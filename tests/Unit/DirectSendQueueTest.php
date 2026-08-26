<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DirectSendQueueTest extends TestCase
{
    public function testQueueMigrationAndWorkerWiringExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/db/migrations/2026_08_26_direct_send_queue.sql');
        self::assertFileExists($root . '/app/DirectSendQueue.php');
        self::assertFileExists($root . '/public/send-queue.php');

        $worker = (string)file_get_contents($root . '/cron/worker.php');
        self::assertStringContainsString("require_once __DIR__ . '/../app/DirectSendQueue.php';", $worker);
        self::assertStringContainsString('run_direct_send_queue_pass()', $worker);
    }

    public function testQueueUsesLeaseRetryAndStableWalletReference(): void
    {
        $root = dirname(__DIR__, 2);
        $queue = (string)file_get_contents($root . '/app/DirectSendQueue.php');

        self::assertStringContainsString("FOR UPDATE SKIP LOCKED", $queue);
        self::assertStringContainsString('job_lease_seconds()', $queue);
        self::assertStringContainsString('job_max_attempts()', $queue);
        self::assertStringContainsString('job_retry_backoff_seconds($attempts)', $queue);
        self::assertStringContainsString("'direct_send_queue',", $queue);
        self::assertStringContainsString('(string)$id', $queue);
    }

    public function testQueueEndpointRechecksSecurityAndPricingBeforeEnqueue(): void
    {
        $root = dirname(__DIR__, 2);
        $endpoint = (string)file_get_contents($root . '/public/send-queue.php');

        self::assertStringContainsString('csrf_check();', $endpoint);
        self::assertStringContainsString('require_permission(Permissions::MESSAGES_SEND)', $endpoint);
        self::assertStringContainsString("kyc_feature_allowed($organizationId, 'sms_send')", $endpoint);
        self::assertStringContainsString('direct_send_queue_policy_allowed($me)', $endpoint);
        self::assertStringContainsString('can_use_originator($me, $originator)', $endpoint);
        self::assertStringContainsString('estimate_message_cost(', $endpoint);
        self::assertStringContainsString('direct_send_queue_enqueue(', $endpoint);
    }

    public function testBrowserConfirmationRoutesImmediateSendsToQueue(): void
    {
        $root = dirname(__DIR__, 2);
        $footer = (string)file_get_contents($root . '/app/views/footer.php');

        self::assertStringContainsString("form.setAttribute('action', '/send-queue.php')", $footer);
        self::assertStringContainsString("mode === 'direct'", $footer);
        self::assertStringContainsString("mode === 'now'", $footer);
        self::assertStringContainsString("button[name=\"do\"][value=\"confirm\"]", $footer);
    }
}
