<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * deploy.sh — a deploy does only what the files changed since the last one need. Runs the real
 * script in a throwaway git checkout with a `docker` stand-in on PATH that records its arguments.
 */
final class DeployScriptTest extends TestCase
{
    private string $dir;
    private string $work;
    private string $server;
    private string $log;

    protected function setUp(): void
    {
        if (trim((string)shell_exec('command -v git')) === '' || trim((string)shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('needs git and bash');
        }
        $this->dir = sys_get_temp_dir() . '/ellsms_deploy_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/bin', 0777, true);
        $this->log = $this->dir . '/docker.log';
        $this->work = $this->dir . '/work';
        $this->server = $this->dir . '/server';

        // docker: log the call; answer `compose ps --status running --services` from a file.
        file_put_contents($this->dir . '/bin/docker', "#!/usr/bin/env bash\n"
            . "echo \"\$*\" >> '{$this->log}'\n"
            . "if [ \"\$*\" = 'compose ps --status running --services' ]; then cat '{$this->dir}/running' 2>/dev/null; fi\n"
            . "cat >/dev/null 2>&1 < /dev/stdin || true\nexit 0\n");
        chmod($this->dir . '/bin/docker', 0755);
        $this->running(['app', 'worker', 'status-worker', 'scheduler', 'mysql', 'mongo', 'smpp-bridge', 'tg-worker', 'mock-sms-gateway']);

        $this->sh("git init -q --bare '{$this->dir}/origin.git'");
        $this->sh("git clone -q '{$this->dir}/origin.git' '{$this->work}' 2>/dev/null");
        foreach (['db/migrations', 'app', 'cron', 'docs', 'docker', 'public', 'smpp-bridge'] as $d) mkdir($this->work . '/' . $d, 0777, true);
        copy(dirname(__DIR__, 2) . '/deploy.sh', $this->work . '/deploy.sh');
        chmod($this->work . '/deploy.sh', 0755);
        file_put_contents($this->work . '/db/ellsms_extra.sql', "SELECT 1;\n");
        file_put_contents($this->work . '/.gitignore', ".env\n");
        $this->commit($this->work, 'first');
        $this->sh("git clone -q '{$this->dir}/origin.git' '{$this->server}'");
        file_put_contents($this->server . '/.env', "AUDIT_MONGO_PASSWORD=x\nBACKEND_DB_HOST=db\nBACKEND_DB_USER=u\nBACKEND_DB_PASS=p\nBACKEND_DB_NAME=n\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) $this->sh("rm -rf '{$this->dir}'");
    }

    private function sh(string $command): string
    {
        exec($command . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, $command . "\n" . implode("\n", $out));
        return implode("\n", $out);
    }

    private function commit(string $repo, string $message): void
    {
        $this->sh("cd '{$repo}' && git add -A && git -c user.name=t -c user.email=t@example.org commit -q -m '{$message}' && git push -q origin HEAD 2>/dev/null");
    }

    /** A change pushed from the developer's checkout. */
    private function push(array $files): void
    {
        foreach ($files as $file) {
            @mkdir(dirname($this->work . '/' . $file), 0777, true);
            file_put_contents($this->work . '/' . $file, bin2hex(random_bytes(8)) . "\n", FILE_APPEND);
        }
        $this->commit($this->work, 'change');
    }

    private function running(array $services): void
    {
        file_put_contents($this->dir . '/running', $services === [] ? '' : implode("\n", $services) . "\n");
    }

    /** Runs deploy.sh on the "server"; returns [its output, the docker calls it made]. */
    private function deploy(string $args = ''): array
    {
        @unlink($this->log);
        $out = $this->sh("cd '{$this->server}' && PATH='{$this->dir}/bin':\$PATH bash ./deploy.sh {$args}");
        $calls = is_file($this->log) ? array_values(array_filter(explode("\n", (string)file_get_contents($this->log)))) : [];
        return [$out, $calls];
    }

    private function firstDeploy(): void
    {
        [$out, $calls] = $this->deploy();
        $this->assertStringContainsString('Full deploy: there is no record of a previous deploy', $out);
        $this->assertContains('compose build', $calls);
        $this->assertContains('compose run --rm --no-deps -T app php cron/db-migrate.php --apply', $calls);
        $this->assertContains('compose up -d', $calls);
        $this->assertNotContains('compose restart worker status-worker scheduler', $calls);
    }

    public function testTheFirstDeployIsAFullOneAndRecordsItsCommit(): void
    {
        $this->firstDeploy();
        $this->assertSame(trim($this->sh("cd '{$this->server}' && git rev-parse HEAD")), trim((string)file_get_contents($this->server . '/.git/ellsms-deployed-rev')));
    }

    public function testDocumentationAndPublicFilesNeedNothingButThePull(): void
    {
        $this->firstDeploy();
        $this->push(['docs/guide.md', 'public/guide.php', 'README.md', 'integrations/Guide.pdf', 'tests/Unit/XTest.php', 'sdk/js/index.js']);
        [$out, $calls] = $this->deploy();
        $this->assertStringContainsString('6 file(s) changed', $out);
        $this->assertStringContainsString('nothing but the new files', $out);
        $this->assertSame(['compose ps --status running --services', 'compose up -d', 'compose ps'], $calls);
        $this->assertFileExists($this->server . '/docs/guide.md', 'the pull brought the files');
    }

    public function testAppCodeRestartsTheWorkersButNeverTheWebContainerOrADatabase(): void
    {
        $this->firstDeploy();
        $this->push(['app/Thing.php', 'docs/thing.md']);
        [, $calls] = $this->deploy();
        $this->assertContains('compose restart worker status-worker scheduler', $calls);
        $this->assertSame([], preg_grep('/build|db-migrate|ellsms_extra|compose run/', $calls), implode("\n", $calls));
    }

    public function testAMigrationIsAppliedInTheRunningAppContainerBeforeTheWorkersRestart(): void
    {
        $this->firstDeploy();
        $this->push(['db/migrations/2026_01_01_x.sql', 'app/Thing.php']);
        [, $calls] = $this->deploy();
        $migrate = array_search('compose exec -T app php cron/db-migrate.php --apply', $calls, true);
        $restart = array_search('compose restart worker status-worker scheduler', $calls, true);
        $this->assertNotFalse($migrate, implode("\n", $calls));
        $this->assertNotFalse($restart);
        $this->assertLessThan($restart, $migrate);
        $this->assertSame([], preg_grep('/build|mysql/', $calls), 'the base schema file did not change');
    }

    public function testTheBaseSchemaFileIsAppliedOnlyWhenItChanged(): void
    {
        $this->firstDeploy();
        $this->push(['db/ellsms_extra.sql']);
        [, $calls] = $this->deploy();
        $this->assertCount(1, preg_grep('/^compose exec -T app mysql /', $calls), implode("\n", $calls));
        $this->assertSame([], preg_grep('/db-migrate|restart|build/', $calls));
    }

    public function testADockerfileChangeRebuildsOnlyThePhpImage(): void
    {
        $this->firstDeploy();
        $this->push(['docker/Dockerfile', 'app/Thing.php', 'db/migrations/2026_01_02_y.sql']);
        [, $calls] = $this->deploy();
        $this->assertContains('compose build app', $calls);
        // The image changed: the migration runs in a container of the NEW image, and `up -d`
        // recreates the PHP containers, so there is no separate restart.
        $this->assertContains('compose run --rm --no-deps -T app php cron/db-migrate.php --apply', $calls);
        $this->assertSame([], preg_grep('/restart|exec/', $calls), implode("\n", $calls));

        $this->push(['smpp-bridge/src/Main.java', 'docker/grafana/dashboards/x.json']);
        [, $calls] = $this->deploy();
        $this->assertContains('compose build smpp-bridge', $calls);
        $this->assertSame([], preg_grep('/restart|build app|db-migrate/', $calls), implode("\n", $calls));
    }

    public function testAChangePulledByHandIsStillDeployed(): void
    {
        $this->firstDeploy();
        $this->push(['app/Thing.php']);
        $this->sh("cd '{$this->server}' && git pull -q --ff-only");
        [$out, $calls] = $this->deploy();
        $this->assertStringContainsString('1 file(s) changed', $out);
        $this->assertContains('compose restart worker status-worker scheduler', $calls);
    }

    public function testAStackThatIsDownOrAForcedDeployIsAFullOne(): void
    {
        $this->firstDeploy();
        $this->push(['docs/x.md']);
        $this->running([]);
        [$out, $calls] = $this->deploy();
        $this->assertStringContainsString('Full deploy: the app container is not running', $out);
        $this->assertContains('compose build', $calls);

        $this->running(['app', 'worker']);
        [$out, $calls] = $this->deploy('--full');
        $this->assertStringContainsString('Full deploy: --full was given', $out);
        $this->assertContains('compose build', $calls);
        $this->assertContains('compose up -d', $calls);
    }

    public function testPlanPullsNothingAndChangesNothing(): void
    {
        $this->firstDeploy();
        $this->push(['app/Thing.php']);
        $before = trim($this->sh("cd '{$this->server}' && git rev-parse HEAD"));
        [$out, $calls] = $this->deploy('--plan');
        $this->assertStringContainsString('0 file(s) changed', $out, 'the change is not pulled yet');
        $this->assertSame($before, trim($this->sh("cd '{$this->server}' && git rev-parse HEAD")));
        $this->assertSame(['compose ps --status running --services'], $calls);

        $this->sh("cd '{$this->server}' && git pull -q --ff-only");
        [$out, $calls] = $this->deploy('--plan');
        $this->assertStringContainsString('Needed: workers', $out);
        $this->assertSame(['compose ps --status running --services'], $calls);
        $this->assertSame($before, trim((string)file_get_contents($this->server . '/.git/ellsms-deployed-rev')), 'a plan records nothing');
    }

    public function testANewDeployScriptTakesOverInTheSameRun(): void
    {
        $this->firstDeploy();
        file_put_contents($this->work . '/deploy.sh', "\n# a later version\n", FILE_APPEND);
        $this->commit($this->work, 'newer deploy.sh');
        [$out] = $this->deploy();
        $this->assertStringContainsString('deploy.sh itself changed; continuing with the new one.', $out);
        $this->assertSame(1, substr_count($out, 'continuing with the new one'), 'exactly one hand-over');
        $this->assertStringContainsString('==> Done.', $out);
    }
}
