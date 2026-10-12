<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The one-time credit -> Rial conversion (app/RialConversion.php) against real MySQL: wallets,
 * reservations, prices, payments and settings move by one factor, backfilled opening balances
 * (already Rial) do not, user_.currentcredit follows, and a second run changes nothing.
 */
final class RialConversionTest extends IntegrationTestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flagFile = sys_get_temp_dir() . '/ellsms-rial-conversion-' . bin2hex(random_bytes(4)) . '.flag';
        putenv('MAINTENANCE_MODE_FILE=' . $this->flagFile);
        file_put_contents($this->flagFile, 'converting');
        // Start every test from an unconverted install, whatever an earlier test left in settings.
        db()->exec("DELETE FROM ellsms_settings WHERE skey = 'wallet_currency' OR skey LIKE 'rial\\_conversion.%'");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->flagFile);
        putenv('MAINTENANCE_MODE_FILE');
        \sms_pricing_cache_reset();
    }

    private function currentCredit(int $userId): int
    {
        $st = db()->prepare('SELECT currentcredit FROM user_ WHERE id = ?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    private function wallet(int $userId): array
    {
        $st = db()->prepare('SELECT available_balance, reserved_balance FROM ellsms_wallet_accounts WHERE user_id = ?');
        $st->execute([$userId]);
        return array_map('intval', $st->fetch());
    }

    public function testOpeningBalancesStayRialAndEverythingElseIsMultiplied(): void
    {
        // A wallet seeded from Negar's Rial balance, then used in old credits.
        $backfilled = $this->makeUser();
        \wallet_backfill_user($backfilled, 500000);
        \wallet_credit($backfilled, 100, 'purchase', 'payment', 't1', 'test:purchase:' . $backfilled);
        \wallet_debit($backfilled, 10, 'sms_debit', 'direct_send', 't1', 'test:debit:' . $backfilled);

        // A wallet that only ever held credits, with work in flight.
        $plain = $this->makeUser();
        \wallet_credit($plain, 50, 'purchase', 'payment', 't2', 'test:purchase:' . $plain);
        \wallet_reserve($plain, 5, 'bulk_job', 'rial-test-' . $plain, 'test:reserve:' . $plain);

        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, status) VALUES (?, 100, 100000, 'pending')")->execute([$plain]);
        $paymentId = (int)db()->lastInsertId();
        \set_setting('min_credit_purchase', '100');
        \set_setting('credit_packages', '500,1000');
        \set_setting('rial_per_credit', '1000');

        $result = \rial_conversion_apply(1200, true);
        $this->assertFalse($result['already_done']);

        // 500000 Rial opening + 90 credits * 1200.
        $this->assertSame(500000 + 90 * 1200, $this->wallet($backfilled)['available_balance']);
        $this->assertSame($this->wallet($backfilled)['available_balance'], $this->currentCredit($backfilled));

        $this->assertSame(['available_balance' => 45 * 1200, 'reserved_balance' => 5 * 1200], $this->wallet($plain));
        $this->assertSame(45 * 1200, $this->currentCredit($plain));
        $st = db()->prepare("SELECT amount, remaining_amount FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?");
        $st->execute(['rial-test-' . $plain]);
        $this->assertSame([6000, 6000], array_map('intval', array_values($st->fetch())));

        // The seeded legacy route-default price (1 credit per segment) becomes 1200 Rial.
        $price = db()->query("SELECT price_per_segment_millicredits, currency FROM ellsms_sms_route_prices WHERE note LIKE 'seeded legacy parity%'")->fetch();
        $this->assertSame(1200000, (int)$price['price_per_segment_millicredits']);
        $this->assertSame('IRR', $price['currency']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE currency = 'credit'")->fetchColumn());

        $st = db()->prepare('SELECT credits FROM ellsms_payments WHERE id = ?');
        $st->execute([$paymentId]);
        $this->assertSame(120000, (int)$st->fetchColumn(), 'a pending payment must credit Rial when it is confirmed later');

        $this->assertSame('120000', \setting_fresh('min_credit_purchase'));
        $this->assertSame('600000,1200000', \setting_fresh('credit_packages'));
        $this->assertNull(\setting_fresh('rial_per_credit'));
        $this->assertSame('1000', \setting_fresh('rial_conversion.old_rial_per_credit'));
        $this->assertTrue(\rial_conversion_done());

        // The ledger is not rewritten; one conversion entry per wallet keeps it summing to the balance.
        $st = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM ellsms_wallet_transactions WHERE user_id = ?");
        $st->execute([$backfilled]);
        $this->assertSame($this->wallet($backfilled)['available_balance'], (int)$st->fetchColumn());
    }

    public function testASecondRunChangesNothing(): void
    {
        $user = $this->makeUser();
        \wallet_credit($user, 10, 'purchase', 'payment', 't3', 'test:purchase:' . $user);
        \rial_conversion_apply(1200, true);
        $after = $this->wallet($user);

        $this->assertSame(['already_done' => true], \rial_conversion_apply(1200, true));
        $this->assertSame($after, $this->wallet($user));
        $this->assertSame(12000, $after['available_balance']);
    }

    public function testOpeningBalancesCanBeTreatedAsCreditsInstead(): void
    {
        $user = $this->makeUser();
        \wallet_backfill_user($user, 100);
        \rial_conversion_apply(1200, false);
        $this->assertSame(120000, $this->wallet($user)['available_balance']);
    }

    public function testOpeningRialSpentAsCreditsIsClampedToZero(): void
    {
        // 1000 Rial seeded, then 900 "credits" spent as if they were credits: in Rial that is far more
        // than the user ever had, so the balance ends at zero rather than negative.
        $user = $this->makeUser();
        \wallet_backfill_user($user, 1000);
        \wallet_debit($user, 900, 'sms_debit', 'direct_send', 't4', 'test:debit:' . $user);
        $result = \rial_conversion_apply(1200, true);
        $this->assertSame(0, $this->wallet($user)['available_balance']);
        $this->assertGreaterThanOrEqual(1, $result['wallets_clamped']);
    }

    public function testRefusesOutsideMaintenanceMode(): void
    {
        @unlink($this->flagFile);
        $this->expectException(\RuntimeException::class);
        \rial_conversion_apply(1200, true);
    }

    public function testAResumedRunMustUseTheFactorItStartedWith(): void
    {
        \set_setting('rial_conversion.factor', '1000');
        $this->expectException(\RuntimeException::class);
        \rial_conversion_apply(1200, true);
    }
}
