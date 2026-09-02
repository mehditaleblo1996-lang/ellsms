<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Issue #15 — the unified alert/incident subsystem (app/Alerting/AlertManager.php). Covers the
 * full required matrix: first fire, dedup, repeat, acknowledge, no-repeat-after-ack, recovery,
 * per-channel failure (Telegram/email/both), and admin-configurable repeat intervals.
 *
 * Real dispatch attempts run against this test environment's genuinely unconfigured Telegram/email
 * (no TELEGRAM_BOT_TOKEN/alert_email_recipient set here) for the "both fail" case, and against
 * AlertManager's test-only sender overrides (setTelegramSenderForTesting/setEmailSenderForTesting)
 * for the "succeeds" and "one succeeds, one fails" cases -- these overrides exist ONLY for this
 * purpose (see the class's own docblock); production code never touches them.
 */
final class AlertManagerTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        \AlertManager::setTelegramSenderForTesting(null);
        \AlertManager::setEmailSenderForTesting(null);
        parent::tearDown();
    }

    private function key(string $suffix): string {
        return 'test_alert:' . $suffix . ':' . bin2hex(random_bytes(4));
    }

    public function testFirstFireCreatesAnOpenIncidentAndDispatchesOnce(): void
    {
        $sent = [];
        \AlertManager::setTelegramSenderForTesting(function (string $text) use (&$sent): array {
            $sent[] = $text;
            return [true, 'ok'];
        });
        $key = $this->key('first');

        $id = \AlertManager::fire($key, \AlertManager::SEVERITY_CRITICAL, 'Title', 'Message body');

        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Title', $sent[0]);

        $row = \db()->query("SELECT * FROM ellsms_alert_incidents WHERE id = {$id}")->fetch();
        $this->assertSame('open', $row['status']);
        $this->assertSame('critical', $row['severity']);
        $this->assertSame(1, (int)$row['fire_count']);
    }

    public function testASecondFireForTheSameKeyBeforeTheRepeatIntervalIsDedupedNotDuplicated(): void
    {
        $sent = 0;
        \AlertManager::setTelegramSenderForTesting(function () use (&$sent): array {
            $sent++;
            return [true, 'ok'];
        });
        $key = $this->key('dedup');

        $id1 = \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M1');
        $id2 = \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M2');

        $this->assertSame($id1, $id2, 'the same alert_key must reuse the existing open incident, never create a second one');
        $this->assertSame(1, $sent, 'a second fire within the repeat interval must not dispatch again');

        $row = \db()->query("SELECT * FROM ellsms_alert_incidents WHERE id = {$id1}")->fetch();
        $this->assertSame(2, (int)$row['fire_count'], 'fire_count must still increment even when the dispatch itself is deduped');
        $this->assertSame('M2', $row['message'], 'the latest message must be kept even when not re-dispatched');
    }

    public function testFireRepeatsOnceTheConfiguredIntervalHasElapsed(): void
    {
        putenv('ALERT_REPEAT_SECONDS_WARNING=0'); // "elapsed immediately" -- deterministic without a real sleep
        try {
            $sent = 0;
            \AlertManager::setTelegramSenderForTesting(function () use (&$sent): array { $sent++; return [true, 'ok']; });
            $key = $this->key('repeat');

            \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M1');
            usleep(10000);
            \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M2');

            $this->assertSame(2, $sent, 'once the repeat interval has elapsed, a still-open incident must be re-dispatched');
        } finally {
            putenv('ALERT_REPEAT_SECONDS_WARNING');
        }
    }

    public function testAcknowledgingAnIncidentStopsRepeatsButLeavesItOpen(): void
    {
        putenv('ALERT_REPEAT_SECONDS_CRITICAL=0');
        try {
            $sent = 0;
            \AlertManager::setTelegramSenderForTesting(function () use (&$sent): array { $sent++; return [true, 'ok']; });
            $key = $this->key('ack');

            $id = \AlertManager::fire($key, \AlertManager::SEVERITY_CRITICAL, 'T', 'M1');
            $acked = \AlertManager::acknowledge($id, 42);
            $this->assertTrue($acked);

            usleep(10000);
            \AlertManager::fire($key, \AlertManager::SEVERITY_CRITICAL, 'T', 'M2');

            $this->assertSame(1, $sent, 'an acknowledged incident must never repeat, no matter how much time has passed');

            $row = \db()->query("SELECT * FROM ellsms_alert_incidents WHERE id = {$id}")->fetch();
            $this->assertSame('acknowledged', $row['status'], 'acknowledging must not resolve/close the incident');
            $this->assertSame(42, (int)$row['acknowledged_by']);
            $this->assertNotNull($row['acknowledged_at']);
        } finally {
            putenv('ALERT_REPEAT_SECONDS_CRITICAL');
        }
    }

    public function testAcknowledgingAnAlreadyAcknowledgedOrResolvedIncidentIsANoOp(): void
    {
        \AlertManager::setTelegramSenderForTesting(fn() => [true, 'ok']);
        $key = $this->key('ack-twice');
        $id = \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M');

        $this->assertTrue(\AlertManager::acknowledge($id, 1));
        $this->assertFalse(\AlertManager::acknowledge($id, 2), 'acknowledging an already-acknowledged incident must report false, not silently succeed again');
    }

    public function testRecoverResolvesTheIncidentAndSendsARecoveryNotice(): void
    {
        $sent = [];
        \AlertManager::setTelegramSenderForTesting(function (string $text) use (&$sent): array { $sent[] = $text; return [true, 'ok']; });
        $key = $this->key('recover');

        $id = \AlertManager::fire($key, \AlertManager::SEVERITY_CRITICAL, 'Down', 'It is down');
        \AlertManager::recover($key, 'It is back up');

        $this->assertCount(2, $sent, 'fire + recover must each dispatch once');
        $this->assertStringContainsString('✅', $sent[1]);

        $row = \db()->query("SELECT * FROM ellsms_alert_incidents WHERE id = {$id}")->fetch();
        $this->assertSame('resolved', $row['status']);
        $this->assertNotNull($row['resolved_at']);

        $active = \AlertManager::activeIncidents();
        $this->assertNotContains($id, array_column($active, 'id'), 'a resolved incident must not appear in the active list');
    }

    public function testRecoveringAKeyWithNoActiveIncidentIsANoOp(): void
    {
        $sent = 0;
        \AlertManager::setTelegramSenderForTesting(function () use (&$sent): array { $sent++; return [true, 'ok']; });
        \AlertManager::recover($this->key('never-fired'));
        $this->assertSame(0, $sent, 'recovering a condition that never alerted must not send anything');
    }

    public function testANewIncidentCanBeRaisedForTheSameKeyAfterTheOldOneIsResolved(): void
    {
        \AlertManager::setTelegramSenderForTesting(fn() => [true, 'ok']);
        $key = $this->key('reopen');

        $id1 = \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M1');
        \AlertManager::recover($key);
        $id2 = \AlertManager::fire($key, \AlertManager::SEVERITY_WARNING, 'T', 'M2');

        $this->assertNotSame($id1, $id2, 'a resolved incident must not be reused -- the next occurrence is a new incident');
    }

    public function testTelegramFailureIsLoggedAndDoesNotPreventEmailFromBeingAttempted(): void
    {
        $emailCalled = false;
        \AlertManager::setTelegramSenderForTesting(fn() => [false, 'simulated telegram outage']);
        \AlertManager::setEmailSenderForTesting(function () use (&$emailCalled): array { $emailCalled = true; return [true, 'sent']; });

        $id = \AlertManager::fire($this->key('telegram-fail'), \AlertManager::SEVERITY_CRITICAL, 'T', 'M');

        $this->assertTrue($emailCalled, 'a Telegram failure must never suppress the email attempt');
        // dispatch() attempts telegram then email, in that order -- see AlertManager::dispatch().
        $log = \db()->query("SELECT channel, outcome FROM ellsms_alert_dispatch_log WHERE incident_id = {$id} ORDER BY id")->fetchAll();
        $this->assertSame(['channel' => 'telegram', 'outcome' => 'failed'], $log[0]);
        $this->assertSame(['channel' => 'email', 'outcome' => 'sent'], $log[1]);
    }

    public function testEmailFailureIsLoggedAndDoesNotPreventTelegramFromBeingAttempted(): void
    {
        $telegramCalled = false;
        \AlertManager::setTelegramSenderForTesting(function () use (&$telegramCalled): array { $telegramCalled = true; return [true, 'sent']; });
        \AlertManager::setEmailSenderForTesting(fn() => [false, 'simulated mail() failure']);

        $id = \AlertManager::fire($this->key('email-fail'), \AlertManager::SEVERITY_CRITICAL, 'T', 'M');

        $this->assertTrue($telegramCalled, 'an email failure must never suppress the Telegram attempt');
        $log = \db()->query("SELECT channel, outcome FROM ellsms_alert_dispatch_log WHERE incident_id = {$id} ORDER BY id")->fetchAll();
        $this->assertSame(['channel' => 'telegram', 'outcome' => 'sent'], $log[0]);
        $this->assertSame(['channel' => 'email', 'outcome' => 'failed'], $log[1]);
    }

    public function testBothChannelsFailingStillCreatesAndTracksTheIncidentRatherThanThrowing(): void
    {
        // Deliberately uses NO sender override -- this test environment has neither Telegram nor
        // alert_email_recipient configured, so both real code paths fail naturally, proving the
        // "both channels down" case doesn't require special-casing in AlertManager itself.
        $id = \AlertManager::fire($this->key('both-fail'), \AlertManager::SEVERITY_EMERGENCY, 'T', 'M');

        $row = \db()->query("SELECT * FROM ellsms_alert_incidents WHERE id = {$id}")->fetch();
        $this->assertSame('open', $row['status'], 'the incident must still be recorded even when every channel fails to deliver');

        $log = \db()->query("SELECT outcome FROM ellsms_alert_dispatch_log WHERE incident_id = {$id}")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $log);
        $this->assertSame(['failed', 'failed'], $log);
    }

    public function testRepeatIntervalIsAdminConfigurableViaTheEnvironmentDefaultAsAFallback(): void
    {
        // setting() (ellsms_settings, admin-editable from /admin/alerts) wins when set -- see the
        // subprocess test below for that path. The env var remains a real, working per-deployment
        // default/override for whenever no admin setting exists yet, exactly like
        // telegram_bot_token()'s own env fallback.
        putenv('ALERT_REPEAT_SECONDS_CRITICAL=999');
        try {
            $this->assertSame(999, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_CRITICAL));
        } finally {
            putenv('ALERT_REPEAT_SECONDS_CRITICAL');
        }
    }

    /**
     * The real admin-configurability path (issue #15's own acceptance criterion: env-only does not
     * count as "admin configurable"). setting()'s cache is a process-wide static populated once and
     * never refreshed, so proving a DB write actually takes effect needs a genuinely fresh PHP
     * process to read it -- the same subprocess pattern ApiRateLimitHttpTest/ApiClientFailureModelTest
     * already use for this exact class of process-boundary concern.
     */
    public function testDbConfiguredRepeatIntervalTakesEffectInAFreshProcess(): void
    {
        // IntegrationTestCase wraps every test in a transaction rolled back in tearDown -- a write
        // made inside it is invisible to a genuinely separate process (a different DB connection),
        // which is exactly what this test needs to prove. Commit for real, then clean up for real.
        \set_setting('ALERT_REPEAT_SECONDS_EMERGENCY', '777');
        \db()->commit();
        try {
            $script = 'require ' . var_export(dirname(__DIR__, 2) . '/app/backend.php', true) . ';'
                . 'echo AlertManager::repeatIntervalSeconds(AlertManager::SEVERITY_EMERGENCY);';
            $env = [
                'APP_ENV' => 'testing',
                'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
                'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
                'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
                'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
                'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            ];
            $proc = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertNotFalse($proc);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame('777', trim($out), 'a value saved via set_setting() (what the /admin/alerts settings form calls) must be read back by a fresh process');
        } finally {
            \set_setting('ALERT_REPEAT_SECONDS_EMERGENCY', '');
        }
    }

    public function testDefaultRepeatIntervalsMatchTheDocumentedDefaults(): void
    {
        $this->assertSame(1800, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_WARNING));
        $this->assertSame(300, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_CRITICAL));
        $this->assertSame(120, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_EMERGENCY));
    }

    public function testInvalidConfiguredIntervalFallsBackToTheDefaultRatherThanBeingNegative(): void
    {
        putenv('ALERT_REPEAT_SECONDS_WARNING=-5');
        try {
            $this->assertSame(1800, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_WARNING));
        } finally {
            putenv('ALERT_REPEAT_SECONDS_WARNING');
        }
    }

    public function testZeroIsAValidConfiguredIntervalMeaningRepeatOnEveryFire(): void
    {
        putenv('ALERT_REPEAT_SECONDS_EMERGENCY=0');
        try {
            $this->assertSame(0, \AlertManager::repeatIntervalSeconds(\AlertManager::SEVERITY_EMERGENCY));
        } finally {
            putenv('ALERT_REPEAT_SECONDS_EMERGENCY');
        }
    }

    public function testActiveIncidentsOrdersMostSevereFirst(): void
    {
        \AlertManager::setTelegramSenderForTesting(fn() => [true, 'ok']);
        $warnKey = $this->key('order-warn');
        $emergKey = $this->key('order-emerg');
        \AlertManager::fire($warnKey, \AlertManager::SEVERITY_WARNING, 'W', 'w');
        \AlertManager::fire($emergKey, \AlertManager::SEVERITY_EMERGENCY, 'E', 'e');

        $active = \AlertManager::activeIncidents();
        $keys = array_column($active, 'alert_key');
        $this->assertLessThan(array_search($warnKey, $keys, true), array_search($emergKey, $keys, true), 'emergency must sort before warning');
    }

    public function testUnknownSeverityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \AlertManager::fire($this->key('bad-severity'), 'not_a_real_severity', 'T', 'M');
    }
}
