<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 8, Invariant E (STEP 16) regression: when the backend API is unreachable,
 * dispatch_message_raw() (app/backend.php) must NOT write a fabricated row into the
 * backend-owned outbound_message table. It must instead record the attempt in ELLSMS's own
 * ellsms_message_attempts table (backend_record_message_attempt_failure(),
 * app/Backend/messages.php) — see docs/service-boundaries.md.
 *
 * The test schema never configures api_base_url, so every dispatch_message_raw() call here
 * deterministically takes the "API unreachable" branch (the same property BulkJobQueueTest and
 * ScheduleQueueTest already rely on for their own retry-path tests) — this test's concern is
 * specifically what that branch writes, not the retry classification itself.
 */
final class NoBackendWriteFallbackTest extends IntegrationTestCase
{
    private function outboundMessageCount(): int {
        return (int)db()->query('SELECT COUNT(*) c FROM outbound_message')->fetch()['c'];
    }

    private function messageAttemptsCount(): int {
        return (int)db()->query('SELECT COUNT(*) c FROM ellsms_message_attempts')->fetch()['c'];
    }

    private function makeSendableUser(): array {
        $userId = $this->makeUser(['originator' => self::DEFAULT_ORIGINATOR]);
        return ['id' => $userId, 'role' => 'user', 'credit' => 0, 'originator' => self::DEFAULT_ORIGINATOR, 'organization_id' => null];
    }

    public function testApiUnreachableDoesNotWriteToOutboundMessage(): void
    {
        $user = $this->makeSendableUser();
        $before = $this->outboundMessageCount();

        [$ok, , $sentCount, $totalCount, , $retryable] = dispatch_message_raw($user, self::DEFAULT_ORIGINATOR, ['09120000000'], 'hi');

        $this->assertFalse($ok);
        $this->assertSame(0, $sentCount);
        $this->assertSame(1, $totalCount);
        $this->assertTrue($retryable, 'a transport-level failure must be retryable, distinct from a permanent validation/authorization failure');
        $this->assertSame($before, $this->outboundMessageCount(), 'ELLSMS must never fabricate a row in the backend-owned outbound_message table on API failure');
    }

    public function testApiUnreachableRecordsAttemptInEllsmsOwnedTable(): void
    {
        $user = $this->makeSendableUser();
        $before = $this->messageAttemptsCount();

        dispatch_message_raw($user, self::DEFAULT_ORIGINATOR, ['09120000000'], 'hi');

        $this->assertSame($before + 1, $this->messageAttemptsCount(), 'the attempt must be recorded exactly once in ELLSMS-owned ellsms_message_attempts');

        $row = db()->query('SELECT * FROM ellsms_message_attempts ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame($user['id'], (int)$row['user_id']);
        $this->assertSame('direct_send', $row['reference_type'], 'no schedule_id was passed, so this is a direct-send attempt');
        $this->assertSame('failed', $row['status']);
        $this->assertNotSame('', trim((string)$row['error_code']));
    }

    public function testApiUnreachableRecordsScheduleReferenceWhenDispatchedFromASchedule(): void
    {
        $user = $this->makeSendableUser();

        dispatch_message_raw($user, self::DEFAULT_ORIGINATOR, ['09120000000'], 'hi', 4242);

        $row = db()->query('SELECT * FROM ellsms_message_attempts ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('schedule', $row['reference_type']);
        $this->assertSame('4242', $row['reference_id']);
    }
}
