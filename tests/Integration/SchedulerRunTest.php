<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * A scheduled job run end to end (app/Scheduler.php): the job's script runs as its own process
 * against the test database, the run is recorded, a failure raises an alert and the next success
 * resolves it. Uses the wallet audit, which fails exactly when a balance was changed outside the
 * wallet. Commits its rows (the job runs in another process) and removes them afterwards.
 */
final class SchedulerRunTest extends IntegrationTestCase
{
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();
        db()->rollBack();
        require_once dirname(__DIR__, 2) . '/app/Scheduler.php';
        // Leftovers of other tests must not make the audit fail on their own.
        db()->exec('UPDATE user_ u JOIN ellsms_wallet_accounts w ON w.user_id = u.id SET u.currentcredit = w.available_balance');
        db()->exec("DELETE FROM ellsms_scheduler_runs WHERE job = 'wallet-audit'");
        db()->exec("DELETE FROM ellsms_alert_incidents WHERE alert_key = 'scheduler_job_failed:wallet-audit'");
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            db()->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$this->userId]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->userId]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->userId]);
        }
        db()->exec("DELETE FROM ellsms_alert_dispatch_log WHERE incident_id IN (SELECT id FROM ellsms_alert_incidents WHERE alert_key = 'scheduler_job_failed:wallet-audit')");
        db()->exec("DELETE FROM ellsms_alert_incidents WHERE alert_key = 'scheduler_job_failed:wallet-audit'");
        db()->exec("DELETE FROM ellsms_scheduler_runs WHERE job = 'wallet-audit'");
        parent::tearDown();
    }

    private function run_(): array {
        $result = scheduler_run_job('wallet-audit');
        $row = db()->query("SELECT * FROM ellsms_scheduler_runs WHERE job = 'wallet-audit'")->fetch();
        $incident = db()->query("SELECT status FROM ellsms_alert_incidents WHERE alert_key = 'scheduler_job_failed:wallet-audit' ORDER BY id DESC LIMIT 1")->fetchColumn();
        return [$result, $row, $incident];
    }

    public function testAFailedJobIsRecordedAndAlertedAndItsNextSuccessResolvesTheAlert(): void
    {
        $this->userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 500, 0)')->execute([$this->userId]);
        db()->prepare('UPDATE user_ SET currentcredit = 900 WHERE id = ?')->execute([$this->userId]); // written outside the wallet

        [$result, $row, $incident] = $this->run_();
        $this->assertSame(1, $result['exit_code'], $result['output']);
        $this->assertStringContainsString("user_id={$this->userId}", $result['output']);
        $this->assertSame(1, (int)$row['last_exit_code']);
        $this->assertSame(1, (int)$row['consecutive_failures']);
        $this->assertNull($row['last_success_at']);
        $this->assertSame('open', $incident);

        [, $row] = $this->run_();
        $this->assertSame(2, (int)$row['consecutive_failures']);

        db()->prepare('UPDATE user_ SET currentcredit = 500 WHERE id = ?')->execute([$this->userId]);
        [$result, $row, $incident] = $this->run_();
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertSame(0, (int)$row['last_exit_code']);
        $this->assertSame(0, (int)$row['consecutive_failures']);
        $this->assertNotNull($row['last_success_at']);
        $this->assertSame('resolved', $incident);
    }

    public function testADueJobIsNoLongerDueOnceItRan(): void
    {
        $this->assertContains('wallet-audit', scheduler_due_jobs(new \DateTimeImmutable()), 'never run');
        scheduler_run_job('wallet-audit');
        $this->assertNotContains('wallet-audit', scheduler_due_jobs(new \DateTimeImmutable()));
    }
}
