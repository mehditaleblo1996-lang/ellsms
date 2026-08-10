<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The wallet ledger/reservation functions (app/wallet.php) against real MySQL — idempotency
 * (Invariant C/F), the reservation lifecycle (Invariant D/E), and the bulk-overcommit scenario.
 * See WalletConcurrencyTest for the one genuine cross-process race test; the tests here are
 * same-process but still exercise real transactions/locks/UNIQUE constraints against a real
 * database, which is what actually proves the accounting arithmetic and idempotency logic (as
 * opposed to true concurrent timing, which WalletConcurrencyTest covers separately).
 */
final class WalletIntegrationTest extends IntegrationTestCase
{
    private function seedWallet(int $userId, int $available): void {
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, ?, 0)')
            ->execute([$userId, $available]);
        db()->prepare('UPDATE user_ SET currentcredit = ? WHERE id = ?')->execute([$available, $userId]);
    }

    public function testCreditIncreasesAvailableBalanceAndSyncsCurrentcredit(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 0);

        $result = wallet_credit($userId, 500, 'purchase', 'payment', '1', 'test_credit_1');

        $this->assertTrue($result['ok']);
        $this->assertSame(500, $result['balance']);
        $this->assertSame(500, wallet_balance($userId)['available']);

        $legacy = db()->prepare('SELECT currentcredit FROM user_ WHERE id = ?');
        $legacy->execute([$userId]);
        $this->assertSame(500, (int)$legacy->fetch()['currentcredit']);
    }

    public function testDebitWithSameIdempotencyKeyTwiceOnlyChargesOnce(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 100);

        $first = wallet_debit($userId, 30, 'sms_debit', 'direct_send', 'x', 'dup_key_1');
        $second = wallet_debit($userId, 30, 'sms_debit', 'direct_send', 'x', 'dup_key_1');

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(70, wallet_balance($userId)['available'], 'Balance must reflect only ONE debit, not two.');

        $count = db()->prepare('SELECT COUNT(*) c FROM ellsms_wallet_transactions WHERE idempotency_key = ?');
        $count->execute(['dup_key_1']);
        $this->assertSame(1, (int)$count->fetch()['c'], 'Exactly one ledger row must exist for this idempotency key, never two.');
    }

    public function testCreditWithSameIdempotencyKeyTwiceOnlyCreditsOnce(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 0);

        wallet_credit($userId, 200, 'purchase', 'payment', '1', 'dup_credit_1');
        wallet_credit($userId, 200, 'purchase', 'payment', '1', 'dup_credit_1');

        $this->assertSame(200, wallet_balance($userId)['available']);
    }

    public function testDebitFailsCleanlyWhenBalanceIsInsufficient(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 10);

        $result = wallet_debit($userId, 50, 'sms_debit', 'direct_send', 'y', 'insufficient_1');

        $this->assertFalse($result['ok']);
        $this->assertSame('insufficient_balance', $result['reason']);
        $this->assertSame(10, wallet_balance($userId)['available'], 'Balance must be unchanged after a rejected debit.');
    }

    public function testReserveMovesCreditFromAvailableToReservedWithoutChangingTotal(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);

        $result = wallet_reserve($userId, 400, 'bulk_job', '101', 'reserve_101');

        $this->assertTrue($result['ok']);
        $balance = wallet_balance($userId);
        $this->assertSame(600, $balance['available']);
        $this->assertSame(400, $balance['reserved']);
        $this->assertSame(1000, $balance['total'], 'Reserving must not change the total amount the user owns.');
    }

    public function testReserveWithTheSameReferenceTwiceIsAReplayNotADoubleReservation(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);

        $first = wallet_reserve($userId, 400, 'bulk_job', '202', 'reserve_202');
        $second = wallet_reserve($userId, 400, 'bulk_job', '202', 'reserve_202_retry');

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['reservation_id'], $second['reservation_id']);
        $this->assertSame(600, wallet_balance($userId)['available'], 'A replayed reservation must not reserve a second time.');
    }

    /**
     * The bulk-overcommit scenario from STEP 9/21 — two jobs, each wanting 900 of a 1000 balance,
     * cannot both be accepted. This is a same-process test proving the accounting arithmetic (the
     * FOR UPDATE lock inside wallet_reserve() is what WalletConcurrencyTest verifies under true
     * concurrency; this test verifies the balance bookkeeping itself is correct).
     */
    public function testTwoJobsCannotBothReserveMoreThanTheAvailableBalance(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);

        $jobA = wallet_reserve($userId, 900, 'bulk_job', 'jobA', 'reserve_jobA');
        $jobB = wallet_reserve($userId, 900, 'bulk_job', 'jobB', 'reserve_jobB');

        $this->assertTrue($jobA['ok']);
        $this->assertFalse($jobB['ok'], 'The second 900-credit job must be rejected — only 100 remained available after the first.');
        $this->assertSame('insufficient_balance', $jobB['reason']);
        $this->assertSame(100, wallet_balance($userId)['available']);
    }

    public function testCommitReservationSpendsExactlyTheCommittedAmountAndKeepsAvailableUnchanged(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 500, 'bulk_job', '303', 'reserve_303');

        $commit = wallet_commit_reservation('bulk_job', '303', 120, 'commit_item_1');

        $this->assertTrue($commit['ok']);
        $this->assertSame(380, $commit['remaining']);
        $this->assertSame('active', $commit['status']);
        $balance = wallet_balance($userId);
        $this->assertSame(500, $balance['available'], 'Committing spends FROM reserved, not from available — available must be unchanged.');
        $this->assertSame(380, $balance['reserved']);
    }

    public function testCommitReservationWithSameIdempotencyKeyTwiceOnlyCommitsOnce(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 500, 'bulk_job', '404', 'reserve_404');

        wallet_commit_reservation('bulk_job', '404', 120, 'commit_item_dup');
        wallet_commit_reservation('bulk_job', '404', 120, 'commit_item_dup');

        $this->assertSame(380, wallet_balance($userId)['reserved'], 'A retried commit with the same idempotency key must not deduct twice.');
    }

    public function testCommitCannotExceedRemainingReservedAmount(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 100, 'bulk_job', '505', 'reserve_505');

        $result = wallet_commit_reservation('bulk_job', '505', 150, 'commit_over_505');

        $this->assertFalse($result['ok']);
        $this->assertSame('amount_exceeds_remaining', $result['reason']);
        $this->assertSame(100, wallet_balance($userId)['reserved'], 'A rejected over-commit must not touch the reservation at all.');
    }

    public function testReleaseReturnsRemainingReservationToAvailableBalance(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 500, 'bulk_job', '606', 'reserve_606');
        wallet_commit_reservation('bulk_job', '606', 200, 'commit_606');

        $release = wallet_release_reservation('bulk_job', '606');

        $this->assertTrue($release['ok']);
        $this->assertSame(300, $release['released_amount']);
        $balance = wallet_balance($userId);
        $this->assertSame(800, $balance['available'], '500 reserved, 200 committed (spent), 300 released back = 1000 - 200 = 800 available.');
        $this->assertSame(0, $balance['reserved']);
    }

    /** Invariant E: a reservation ends as exactly one of committed/released, never both — releasing twice is a safe no-op. */
    public function testReleasingAnAlreadyReleasedReservationIsIdempotent(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 500, 'bulk_job', '707', 'reserve_707');

        $first = wallet_release_reservation('bulk_job', '707');
        $second = wallet_release_reservation('bulk_job', '707');

        $this->assertSame(500, $first['released_amount']);
        $this->assertSame(0, $second['released_amount'], 'A second release must give back nothing more — it already happened.');
        $this->assertSame(1000, wallet_balance($userId)['available'], 'Double-release must not credit the account twice.');
    }

    /** Invariant E: once fully committed, a reservation cannot be released as if unused. */
    public function testReleasingAFullyCommittedReservationDoesNotReturnAnyCredit(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 1000);
        wallet_reserve($userId, 300, 'bulk_job', '808', 'reserve_808');
        wallet_commit_reservation('bulk_job', '808', 300, 'commit_808'); // fully spent, remaining=0, status=committed

        $release = wallet_release_reservation('bulk_job', '808');

        $this->assertSame(0, $release['released_amount']);
        $this->assertSame(700, wallet_balance($userId)['available'], 'A fully-committed reservation has nothing left to release.');
    }

    public function testManualAdjustmentRequiresANonEmptyReason(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 100);

        $result = wallet_manual_adjustment($userId, 50, 999, '', 'manual_1');

        $this->assertFalse($result['ok']);
        $this->assertSame('reason_required', $result['reason']);
        $this->assertSame(100, wallet_balance($userId)['available']);
    }

    public function testManualCreditAdjustmentIncreasesBalanceWithReason(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 100);

        $result = wallet_manual_adjustment($userId, 250, 999, 'goodwill credit', 'manual_2');

        $this->assertTrue($result['ok']);
        $this->assertSame(350, $result['balance']);
    }

    /** Matches the pre-Phase-3 GREATEST(0, currentcredit + amount) behavior: a debit larger than the balance clamps at zero instead of failing. */
    public function testManualDebitAdjustmentClampsAtZeroInsteadOfRejecting(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 50);

        $result = wallet_manual_adjustment($userId, -500, 999, 'zero out abusive account', 'manual_3');

        $this->assertTrue($result['ok'], 'A manual debit larger than the balance must clamp, not fail.');
        $this->assertSame(0, $result['balance']);
        $this->assertSame(0, wallet_balance($userId)['available']);
    }

    public function testDriftReportIsEmptyWhenWalletAndLegacyBalanceAgree(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 777);

        $drift = wallet_drift_report();

        $ourRow = array_values(array_filter($drift, static fn($r) => $r['user_id'] === $userId));
        $this->assertSame([], $ourRow, 'A freshly-seeded, untouched wallet must show zero drift.');
    }

    public function testDriftReportDetectsAManualOutOfBandCurrentcreditWrite(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 500);

        // Simulate exactly the bug wallet-audit exists to catch: something
        // outside app/wallet.php writing directly to currentcredit.
        db()->prepare('UPDATE user_ SET currentcredit = 999 WHERE id = ?')->execute([$userId]);

        $drift = wallet_drift_report();
        $ourRow = array_values(array_filter($drift, static fn($r) => $r['user_id'] === $userId));

        $this->assertCount(1, $ourRow);
        $this->assertSame(500, $ourRow[0]['wallet_available']);
        $this->assertSame(999, $ourRow[0]['legacy_currentcredit']);
        $this->assertSame(499, $ourRow[0]['drift']);
    }

    public function testBackfillCreatesAWalletAccountFromCurrentcredit(): void
    {
        $userId = $this->makeUser(); // no wallet account yet, currentcredit defaults to 0 in the fixture schema
        db()->prepare('UPDATE user_ SET currentcredit = 42 WHERE id = ?')->execute([$userId]);

        $result = wallet_backfill_user($userId, 42);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertSame(42, wallet_balance($userId)['available']);
    }

    public function testBackfillIsIdempotentAndNeverReCreditsAnExistingAccount(): void
    {
        $userId = $this->makeUser();
        $this->seedWallet($userId, 42); // already has a wallet account

        $result = wallet_backfill_user($userId, 999); // a re-run would see a different (wrong) currentcredit snapshot

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame(42, wallet_balance($userId)['available'], 'An existing wallet account must never be re-seeded or re-credited.');
    }
}
