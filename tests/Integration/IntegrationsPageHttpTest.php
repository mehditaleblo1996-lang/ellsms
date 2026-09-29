<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The /integrations page over real HTTP: the guide renders, every package downloads as a valid zip with
 * the expected files, an unknown package is a 404, and a user who cannot see API keys gets nothing.
 * Plus the guide's Markdown renderer never lets HTML or a javascript: link through.
 */
final class IntegrationsPageHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        require_once dirname(__DIR__, 2) . '/app/Integrations.php';
        $this->sessionDir = sys_get_temp_dir() . '/ellsms_integ_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20900 + random_int(0, 200);
        $env = ['APP_ENV' => 'testing', 'API_ENABLED' => '1'];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $this->fail('throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['integ_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function get(string $path, int $userId): array
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents($this->sessionDir . '/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now));
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid, CURLOPT_HEADER => true]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    public function testAnAdminSeesTheGuideAndDownloadsEveryPackage(): void
    {
        $admin = $this->makeUser(true);
        $page = $this->get('/integrations.php', $admin);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('<h2', $page['body']);
        $this->assertStringContainsString('افزونه‌ی ووکامرس', $page['body']);
        $this->assertStringContainsString('<table>', $page['body'], 'the guide tables render');
        $this->assertStringContainsString('<pre class="ltr"><code>', $page['body'], 'code samples render');
        $this->assertStringNotContainsString('```', $page['body']);

        $expect = [
            'woocommerce' => ['ellsms-woocommerce/ellsms-woocommerce.php', 'ellsms-woocommerce/lib/ellsms-php/Client.php', 'ellsms-woocommerce/GUIDE.fa.md'],
            'sdk-php' => ['ellsms-php/src/Client.php', 'ellsms-php/composer.json'],
            'sdk-js' => ['ellsms-js/index.js', 'ellsms-js/index.mjs', 'ellsms-js/index.d.ts', 'ellsms-js/package.json'],
            'sdk-python' => ['ellsms-python/ellsms/__init__.py', 'ellsms-python/pyproject.toml'],
        ];
        foreach ($expect as $name => $files) {
            $r = $this->get('/integrations.php?download=' . $name, $admin);
            $this->assertSame(200, $r['code'], $name);
            $this->assertMatchesRegularExpression('/Content-Type: application\/zip/i', $r['headers']);
            $tmp = tempnam(sys_get_temp_dir(), 'integ');
            file_put_contents($tmp, $r['body']);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($tmp) === true, "{$name} is a valid zip");
            foreach ($files as $f) {
                $this->assertNotFalse($zip->locateName($f), "{$name} contains {$f}");
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $this->assertStringNotContainsString('/tests/', '/' . $entry, "{$name} ships no tests: {$entry}");
                $this->assertStringNotContainsString('..', $entry);
            }
            $zip->close();
            unlink($tmp);
        }
        $this->assertSame(404, $this->get('/integrations.php?download=../../etc/passwd', $admin)['code']);
    }

    public function testAUserWhoCannotSeeApiKeysGetsNothing(): void
    {
        $plain = $this->makeUser(false); // no organization, not an admin
        $this->assertSame(403, $this->get('/integrations.php', $plain)['code']);
        $this->assertSame(403, $this->get('/integrations.php?download=woocommerce', $plain)['code']);
    }

    public function testTheGuideRendererLetsNoHtmlOrScriptLinkThrough(): void
    {
        $html = integration_markdown("# T <b>x</b>\n\nهی <script>alert(1)</script> **پررنگ** [ok](https://a.b/c?x=1&y=2) [bad](javascript:alert(1)) `<i>code</i>`\n\n| a | <img src=x> |\n|---|---|\n| 1 | 2 |\n\n```\n<?php echo 1; ?>\n```\n- one\n- two\n1. first");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<strong>پررنگ</strong>', $html);
        $this->assertStringContainsString('<a href="https://a.b/c?x=1&amp;y=2">ok</a>', $html);
        $this->assertStringContainsString('<code class="ltr">&lt;i&gt;code&lt;/i&gt;</code>', $html);
        $this->assertStringContainsString('&lt;?php echo 1; ?&gt;', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<th>a</th>', $html);
    }

    /**
     * Regression: without the /u modifier, \s matched the UTF-8 continuation bytes 0x85/0xA0 inside
     * Persian letters (م is D9 85), which cut headings, list items and paragraphs mid-character.
     */
    public function testPersianTextSurvivesEveryBlockIntact(): void
    {
        $html = integration_markdown("# راهنمای کامل مشتریان\n\n1. وارد پنل شوید و از منوی کناری بروید.\n- متن مهم با م و ن\n\n| دسترسی | کاربرد |\n|---|---|\n| `messages:send` | ارسال پیامک |\n\nپاراگراف معمولی با **متن پررنگ** و ممنون.");
        foreach (['راهنمای کامل مشتریان', 'وارد پنل شوید و از منوی کناری بروید.', 'متن مهم با م و ن', '<th>دسترسی</th>', '<td>ارسال پیامک</td>',
                  '<strong>متن پررنگ</strong>', 'و ممنون.'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertMatchesRegularExpression('/<h2 id="g-[0-9a-f]{8}">راهنمای کامل مشتریان<\/h2>/u', $html);
        $this->assertStringNotContainsString('---', $html, 'the table separator row is not rendered');
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'no character was split');
        $this->assertStringContainsString('<ol start="4">', integration_markdown("1. a\n2. b\n   - sub\n4. d"), 'numbering continues after a sub-list');

        // The real guide: every heading of the source file appears in full.
        $guide = (string)file_get_contents(dirname(__DIR__, 2) . '/' . INTEGRATION_GUIDE_FILE);
        $rendered = integration_markdown($guide);
        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        preg_match_all('/^#{1,4}\s+(.+)$/mu', $guide, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $heading) {
            $this->assertStringContainsString(strip_tags(integration_markdown_inline($heading)), strip_tags($rendered), "heading: {$heading}");
        }
    }
}
