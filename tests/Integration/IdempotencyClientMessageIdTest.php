<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Issue #7 — the $maxAgeHours parameter idempotency_begin() gained for the OPTIONAL
 * client_message_id path (app/Api/Handlers/Messages.php passes
 * IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS = 24). Concurrent-duplicate-request coverage already
 * exists in IdempotencyConcurrencyTest.php (real subprocesses) and is not duplicated here — this
 * file is specifically about the TTL-expiry behavior that test doesn't exercise: a key must stop
 * deduplicating once it's past its window, even if the periodic prune cron hasn't physically
 * deleted the row yet.
 */
final class IdempotencyClientMessageIdTest extends IntegrationTestCase
{
    private const ENDPOINT = 'POST /api/v1/messages';

    private int $organizationId;
    private int $apiKeyId;

    protected function setUp(): void
    {
        parent::setUp();
        \db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['idem_cmid_' . bin2hex(random_bytes(4))]);
        $userId = (int)\db()->lastInsertId();
        $org = \create_organization($userId, 'Idempotency client_message_id test org');
        $this->organizationId = (int)$org['organization_id'];
        $key = \api_key_create($this->organizationId, $userId, 'test', [\ApiScopes::MESSAGES_SEND]);
        $this->apiKeyId = (int)$key['id'];
    }

    public function testFreshKeyClaimsSuccessfully(): void
    {
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-fresh', 'hash-a', 24);
        $this->assertSame('claimed', $lock['action']);
    }

    public function testSameKeySameBodyWithinWindowReplays(): void
    {
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-replay', 'hash-a', 24);
        \idempotency_complete($lock['id'], 201, '{"data":{"id":"1"}}');

        $second = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-replay', 'hash-a', 24);
        $this->assertSame('replay', $second['action']);
        $this->assertSame(201, $second['status']);
    }

    public function testSameKeyDifferentBodyWithinWindowConflicts(): void
    {
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-conflict', 'hash-a', 24);
        \idempotency_complete($lock['id'], 201, '{}');

        $second = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-conflict', 'hash-b', 24);
        $this->assertSame('conflict', $second['action']);
    }

    public function testKeyOlderThanTheWindowIsTreatedAsFreshNotReplayed(): void
    {
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-expired', 'hash-a', 24);
        \idempotency_complete($lock['id'], 201, '{"data":{"id":"1"}}');

        // Simulate 25 hours having passed -- exactly what cron/idempotency-prune.php's much longer
        // default TTL (48h) would NOT yet have physically deleted, proving the live $maxAgeHours
        // check (not prune timing) is what actually enforces the agreed 24h window.
        \db()->prepare("UPDATE ellsms_idempotency_keys SET created_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?")
            ->execute([$lock['id']]);

        $second = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-expired', 'hash-a', 24);
        $this->assertSame('claimed', $second['action'], 'a key past its 24h window must claim fresh, not replay the stale response');
        $this->assertNotSame($lock['id'], $second['id'], 'the expired row must actually be gone, not reused in place');
    }

    public function testKeyJustUnderTheWindowStillReplays(): void
    {
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-not-yet-expired', 'hash-a', 24);
        \idempotency_complete($lock['id'], 201, '{}');

        \db()->prepare("UPDATE ellsms_idempotency_keys SET created_at = DATE_SUB(NOW(), INTERVAL 23 HOUR) WHERE id = ?")
            ->execute([$lock['id']]);

        $second = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-not-yet-expired', 'hash-a', 24);
        $this->assertSame('replay', $second['action'], 'a key still inside its 24h window must keep deduplicating');
    }

    public function testNullMaxAgeHoursPreservesExistingUnboundedBehaviorForOtherCallers(): void
    {
        // The REQUIRED Idempotency-Key feature (POST /bulk-jobs, and /messages via the header
        // convention) calls idempotency_begin() with no $maxAgeHours at all -- this proves that
        // default is untouched by issue #7's change: a very old row still replays until the
        // separate, much longer prune cron actually removes it.
        $lock = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-unbounded', 'hash-a');
        \idempotency_complete($lock['id'], 201, '{}');
        \db()->prepare("UPDATE ellsms_idempotency_keys SET created_at = DATE_SUB(NOW(), INTERVAL 100 HOUR) WHERE id = ?")
            ->execute([$lock['id']]);

        $second = \idempotency_begin($this->organizationId, $this->apiKeyId, self::ENDPOINT, 'cmid-unbounded', 'hash-a');
        $this->assertSame('replay', $second['action'], 'without an explicit maxAgeHours, existing callers must be completely unaffected by this change');
    }
}
