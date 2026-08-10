<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * payment_claim_and_credit() (app/zarinpal.php) against real MySQL — Phase 3 STEP 13/21's
 * "duplicate payment callback" scenario: the same verified payment claimed twice (a genuine
 * ZarinPal retry, or a user refreshing the result page) must credit the wallet exactly once.
 */
final class PaymentIntegrationTest extends IntegrationTestCase
{
    private function makePayment(int $userId, int $credits, int $amountRial, string $status = 'pending'): array {
        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (?,?,?,?,?)")
            ->execute([$userId, $credits, $amountRial, 'A-' . bin2hex(random_bytes(6)), $status]);
        $id = (int)db()->lastInsertId();
        $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    public function testFirstClaimCreditsTheWalletAndMarksThePaymentPaid(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$userId]);
        $payment = $this->makePayment($userId, 500, 500000);

        $result = payment_claim_and_credit($payment, 'REF-1');

        $this->assertTrue($result['claimed']);
        $this->assertTrue($result['credit']['ok']);
        $this->assertSame(500, wallet_balance($userId)['available']);

        $row = db()->prepare('SELECT status, ref_id FROM ellsms_payments WHERE id = ?');
        $row->execute([$payment['id']]);
        $updated = $row->fetch();
        $this->assertSame('paid', $updated['status']);
        $this->assertSame('REF-1', $updated['ref_id']);
    }

    /** The exact scenario STEP 21 names: "same provider payment verified twice" must credit only once. */
    public function testClaimingTheSamePaymentTwiceOnlyCreditsOnce(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$userId]);
        $payment = $this->makePayment($userId, 500, 500000);

        $first = payment_claim_and_credit($payment, 'REF-DUP');
        $second = payment_claim_and_credit($payment, 'REF-DUP'); // simulates a retried callback hit with the same stale $payment array

        $this->assertTrue($first['claimed']);
        $this->assertFalse($second['claimed'], 'The second claim attempt must see the row already paid and do nothing.');
        $this->assertSame(500, wallet_balance($userId)['available'], 'Balance must reflect exactly one credit, not two.');
    }

    public function testClaimingAnAlreadyFailedPaymentDoesNothing(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$userId]);
        $payment = $this->makePayment($userId, 500, 500000, 'failed');

        $result = payment_claim_and_credit($payment, 'REF-2');

        $this->assertFalse($result['claimed'], 'A payment already marked failed (a real user cancellation) must never be claimable again.');
        $this->assertSame(0, wallet_balance($userId)['available']);
    }

    /** verification_failed (STEP 14) must still be claimable — that's the whole point of the retryable state. */
    public function testClaimingAVerificationFailedPaymentSucceeds(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$userId]);
        $payment = $this->makePayment($userId, 300, 300000, 'verification_failed');

        $result = payment_claim_and_credit($payment, 'REF-3');

        $this->assertTrue($result['claimed']);
        $this->assertSame(300, wallet_balance($userId)['available']);
    }
}
