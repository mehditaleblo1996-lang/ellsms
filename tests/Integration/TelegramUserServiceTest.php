<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Telegram user-account service, panel side (app/TelegramUser.php): who may use it, the worst case
 * reserved on create, only successful units charged by tg_settle_pass(), the rest released, webhooks
 * once per state, and the safety rules for stuck / undeliverable messages. The worker's side is
 * simulated by moving rows the way tg-worker does.
 */
final class TelegramUserServiceTest extends IntegrationTestCase
{
    private int $userId;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_MASTER_KEY=test-telegram-master-key');
        foreach (['tg_enabled' => '1', 'tg_price_text' => '10', 'tg_price_media' => '20', 'tg_price_check' => '10',
                  'tg_default_max_accounts' => '1', 'tg_promo_warn_threshold' => '30'] as $key => $value) {
            set_setting($key, $value);
        }
        $this->userId = $this->makeUser();
        wallet_credit($this->userId, 1000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:tg:' . $this->userId);
        db()->prepare('INSERT INTO ellsms_tg_user_settings (user_id, enabled) VALUES (?, 1)')->execute([$this->userId]);
        db()->prepare("INSERT INTO ellsms_tg_accounts (user_id, phone, status, session_enc, created_at) VALUES (?, ?, 'active', 'v1:x', ?)")
            ->execute([$this->userId, '989120000' . random_int(100, 999), tg_now()]);
        $this->accountId = (int)db()->lastInsertId();
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_MASTER_KEY');
        set_setting('tg_enabled', '0');
        parent::tearDown();
    }

    private function user(): array
    {
        return ['id' => $this->userId, 'role' => 'user', 'organization_id' => null];
    }

    private function available(): int
    {
        return wallet_balance($this->userId)['available'];
    }

    /** Moves a campaign's messages the way the worker would: status => list of phones. */
    private function workerMarks(int $campaignId, array $statusByPhone): void
    {
        foreach ($statusByPhone as $phone => $status) {
            $set = match ($status) {
                'sent'   => "status = 'sent', sent_at = NOW(), tg_peer_id = 1, tg_message_id = 5",
                'read'   => "status = 'read', sent_at = NOW(), read_at = NOW(), tg_peer_id = 1, tg_message_id = 5",
                'failed' => "status = 'failed', error_code = 'not_on_telegram'",
                'sending'=> "status = 'sending', claimed_at = '2000-01-01 00:00:00'",
            };
            db()->prepare("UPDATE ellsms_tg_messages SET {$set}, account_id = ? WHERE campaign_id = ? AND phone = ?")
                ->execute([$this->accountId, $campaignId, (string)$phone]);
        }
    }

    public function testOnlyEnabledCustomersMayUseIt(): void
    {
        $this->assertTrue(tg_user_allowed($this->user()));
        db()->prepare('UPDATE ellsms_tg_user_settings SET enabled = 0 WHERE user_id = ?')->execute([$this->userId]);
        $this->assertFalse(tg_user_allowed($this->user()));
        $r = tg_campaign_create($this->user(), ['kind' => 'otp', 'content' => 'code 1234', 'recipients' => ['989121111111']]);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_enabled', $r['code']);

        set_setting('tg_enabled', '0');
        $this->assertFalse(tg_user_allowed(['id' => $this->userId, 'role' => 'admin']), 'the global switch closes it for everyone');
    }

    public function testCampaignReservesWorstCaseChargesOnlyDeliveredAndReleasesTheRest(): void
    {
        $r = tg_campaign_create($this->user(), ['kind' => 'notify', 'content' => 'سلام', 'recipients' => ['989121111111', '989122222222', '989123333333']]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(30, $r['cost']);
        $this->assertSame(970, $this->available(), 'price x recipients is reserved up front');

        $this->workerMarks($r['id'], ['989121111111' => 'sent', '989122222222' => 'read', '989123333333' => 'failed']);
        tg_settle_pass();
        $charged = db()->query('SELECT SUM(charged), SUM(cost > 0) FROM ellsms_tg_messages WHERE campaign_id = ' . (int)$r['id'])->fetch(\PDO::FETCH_NUM);
        $this->assertSame([3, 2], array_map('intval', $charged), 'all three handled, two of them charged');
        $this->assertSame(980, $this->available(), 'two delivered messages cost 20; the failed one is released');

        $campaign = tg_campaign_find($this->user(), $r['id']);
        $this->assertSame('completed', $campaign['status']);
        $this->assertNotNull($campaign['settled_at']);
        $counts = tg_campaign_counts($r['id']);
        $this->assertSame(20, $counts['cost']);

        tg_settle_pass();
        $this->assertSame(980, $this->available(), 'settling twice changes nothing');
    }

    public function testMediaMessagesUseTheMediaPrice(): void
    {
        $r = tg_campaign_create($this->user(), ['content' => 'caption', 'media' => ['path' => 'u1/x.jpg', 'name' => 'x.jpg', 'kind' => 'photo'],
                                                'recipients' => ['989121111111']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(20, $r['cost']);
    }

    public function testInsufficientCreditCreatesNothing(): void
    {
        $phones = [];
        for ($i = 0; $i < 25; $i++) $phones[] = '9891200' . str_pad((string)$i, 5, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE ellsms_tg_user_settings SET price_text = 100 WHERE user_id = ?')->execute([$this->userId]);
        $r = tg_campaign_create($this->user(), ['content' => 'x', 'recipients' => $phones]);
        $this->assertFalse($r['ok']);
        $this->assertSame('insufficient_credit', $r['code']);
        // (The campaign rows' rollback itself cannot be observed here: each test already runs inside one
        // outer transaction, which db_transaction() joins. Outside tests it owns and rolls back its own.)
        $this->assertSame(1000, $this->available());
        $this->assertSame(0, wallet_balance($this->userId)['reserved']);
    }

    public function testPromotionalAndLargeSendsNeedTheBlockRiskAcknowledgement(): void
    {
        $r = tg_campaign_create($this->user(), ['kind' => 'promo', 'content' => 'تخفیف', 'recipients' => ['989121111111']]);
        $this->assertSame('ack_required', $r['code']);
        $phones = [];
        for ($i = 0; $i < 31; $i++) $phones[] = '9891300' . str_pad((string)$i, 5, '0', STR_PAD_LEFT);
        $r = tg_campaign_create($this->user(), ['kind' => 'notify', 'content' => 'x', 'recipients' => $phones]);
        $this->assertSame('ack_required', $r['code'], 'over the threshold even a notification needs it');
        $r = tg_campaign_create($this->user(), ['kind' => 'promo', 'content' => 'تخفیف', 'recipients' => ['989121111111'], 'acknowledged' => true]);
        $this->assertTrue($r['ok']);
    }

    public function testNoActiveAccountIsRefused(): void
    {
        db()->prepare("UPDATE ellsms_tg_accounts SET status = 'disconnected' WHERE id = ?")->execute([$this->accountId]);
        $r = tg_campaign_create($this->user(), ['content' => 'x', 'recipients' => ['989121111111']]);
        $this->assertSame('no_account', $r['code']);
    }

    public function testStuckSendingFailsUncharged(): void
    {
        $r = tg_campaign_create($this->user(), ['content' => 'x', 'recipients' => ['989121111111']]);
        $this->workerMarks($r['id'], ['989121111111' => 'sending']);
        tg_settle_pass();
        $row = db()->query('SELECT status, error_code, cost FROM ellsms_tg_messages WHERE campaign_id = ' . (int)$r['id'])->fetch();
        $this->assertSame(['status' => 'failed', 'error_code' => 'interrupted', 'cost' => 0], ['status' => $row['status'], 'error_code' => $row['error_code'], 'cost' => (int)$row['cost']]);
        $this->assertSame(1000, $this->available());
    }

    public function testAnOtpNobodyCouldSendFailsAfterFiveMinutes(): void
    {
        $r = tg_campaign_create($this->user(), ['kind' => 'otp', 'content' => 'code 1234', 'recipients' => ['989121111111']]);
        db()->prepare("UPDATE ellsms_tg_messages SET created_at = '2000-01-01 00:00:00' WHERE campaign_id = ?")->execute([$r['id']]);
        tg_settle_pass();
        $row = db()->query('SELECT status, error_code FROM ellsms_tg_messages WHERE campaign_id = ' . (int)$r['id'])->fetch();
        $this->assertSame('failed', $row['status']);
        $this->assertSame('no_account', $row['error_code']);
        $this->assertSame(1000, $this->available());
    }

    public function testCancelReleasesWhatWasNotSent(): void
    {
        $r = tg_campaign_create($this->user(), ['content' => 'x', 'recipients' => ['989121111111', '989122222222']]);
        $this->workerMarks($r['id'], ['989121111111' => 'sent']);
        $this->assertTrue(tg_campaign_cancel($this->user(), $r['id']));
        tg_settle_pass();
        $this->assertSame(990, $this->available());
        $this->assertSame('cancelled', tg_campaign_find($this->user(), $r['id'])['status']);
    }

    public function testLookupChargesCheckedNumbersOnly(): void
    {
        $r = tg_lookup_create($this->user(), ['989121111111', '989122222222', '989123333333']);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(970, $this->available());
        db()->prepare("UPDATE ellsms_tg_lookup_items SET status = 'found', checked_at = NOW() WHERE job_id = ? AND phone = '989121111111'")->execute([$r['id']]);
        db()->prepare("UPDATE ellsms_tg_lookup_items SET status = 'not_found', checked_at = NOW() WHERE job_id = ? AND phone = '989122222222'")->execute([$r['id']]);
        db()->prepare("UPDATE ellsms_tg_lookup_items SET status = 'failed' WHERE job_id = ? AND phone = '989123333333'")->execute([$r['id']]);
        tg_settle_pass();
        $this->assertSame(980, $this->available());
        $this->assertSame('completed', tg_lookup_find($this->user(), $r['id'])['status']);
    }

    public function testLoginRespectsTheAccountLimitAndEncryptsThePassword(): void
    {
        $r = tg_login_start($this->user(), '09129999999');
        $this->assertFalse($r['ok'], 'one account allowed and one already connected');
        db()->prepare('UPDATE ellsms_tg_user_settings SET max_accounts = 2 WHERE user_id = ?')->execute([$this->userId]);
        $r = tg_login_start($this->user(), '09129999999');
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('requested', tg_login_find($this->userId, $r['id'])['state']);

        $this->assertFalse(tg_login_submit_code($this->user(), $r['id'], '12345')['ok'], 'no code before the worker sent one');
        db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'password_needed' WHERE id = ?")->execute([$r['id']]);
        $this->assertTrue(tg_login_submit_password($this->user(), $r['id'], 'secret-2fa')['ok']);
        $stored = db()->query('SELECT password_enc FROM ellsms_tg_login_requests WHERE id = ' . (int)$r['id'])->fetchColumn();
        $this->assertStringNotContainsString('secret-2fa', (string)$stored);
        $this->assertSame('secret-2fa', tg_decrypt((string)$stored));
    }

    public function testWebhooksAreEmittedOncePerState(): void
    {
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['tg org', 'tg-org-' . bin2hex(random_bytes(4)), $this->userId]);
        $orgId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_webhook_endpoints (organization_id, url, description, secret_ciphertext, secret_nonce, secret_tag, enabled, event_types_json, created_by_user_id)
                       VALUES (?, 'https://example.com/hook', 'tg', 'x', 'x', 'x', 1, ?, ?)")
            ->execute([$orgId, json_encode(['telegram.message.sent', 'telegram.message.read', 'telegram.message.failed']), $this->userId]);
        webhook_subscriptions_cache_reset();
        $user = ['id' => $this->userId, 'role' => 'user', 'organization_id' => $orgId];
        $r = tg_campaign_create($user, ['kind' => 'otp', 'content' => 'code', 'recipients' => ['989121111111', '989122222222']]);
        $this->workerMarks($r['id'], ['989121111111' => 'sent', '989122222222' => 'failed']);
        tg_settle_pass();
        $this->workerMarks($r['id'], ['989121111111' => 'read']);
        tg_settle_pass();
        tg_settle_pass();
        $st = db()->prepare("SELECT event_type, COUNT(*) c FROM ellsms_webhook_events WHERE organization_id = ? GROUP BY event_type");
        $st->execute([$orgId]);
        $events = array_column($st->fetchAll(), 'c', 'event_type');
        ksort($events);
        $this->assertSame(['telegram.message.failed' => 1, 'telegram.message.read' => 1, 'telegram.message.sent' => 1], array_map('intval', $events));
    }
}
