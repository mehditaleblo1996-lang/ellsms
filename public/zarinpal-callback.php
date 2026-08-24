<?php
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'نتیجه‌ی پرداخت';
$active = '';

$paymentId = (int)($_GET['payment_id'] ?? 0);
$authorityParam = (string)($_GET['Authority'] ?? '');
$status = (string)($_GET['Status'] ?? '');

$st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
$st->execute([$paymentId]);
$payment = $st->fetch();

$result = null; // ['ok'=>bool, 'message'=>string]

if (!$payment || (int)$payment['user_id'] !== (int)$me['id']) {
    $result = ['ok' => false, 'message' => 'پرداخت پیدا نشد یا متعلق به شما نیست.'];
} elseif ($payment['authority'] !== $authorityParam) {
    // Integrity check — the authority ZarinPal is echoing back must match
    // the one we actually requested for this payment row.
    $result = ['ok' => false, 'message' => 'اطلاعات پرداخت مطابقت ندارد.'];
} elseif ($payment['status'] === 'paid') {
    $result = ['ok' => true, 'message' => 'این پرداخت قبلاً با موفقیت ثبت شده است.'];
} elseif ($status !== 'OK') {
    // This reflects the USER's own action at the ZarinPal checkout screen
    // (they cancelled/declined) — a real, final outcome, distinct from
    // the verify() call itself failing below (Phase 3, STEP 14).
    db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
    Logger::warning('payment.cancelled_or_failed_at_gateway', ['payment_id' => $paymentId, 'user_id' => $me['id']]);
    $result = ['ok' => false, 'message' => 'پرداخت توسط شما لغو شد یا ناموفق بود.'];
} else {
    // FIN-2: dispatch to whichever gateway actually created this payment (zarinpal by default;
    // fake only when explicitly enabled and used at creation time — see app/Payment/PaymentGateway.php).
    $gateway = (string)($payment['gateway'] ?? 'zarinpal');
    $verify = payment_gateway_verify($gateway, (int)$payment['amount_rial'], $payment['authority']);
    $ok = $verify['ok'];
    $info = $verify['message'];
    $refId = $verify['ref_id'];
    if (!$ok) {
        // NOT the same as 'failed' — this is the verify() call itself not
        // succeeding (network error, ZarinPal API error, or a rejected
        // code), which may be transient. Kept retryable: `make
        // payments-reconcile` (cron/payments-reconcile.php) revisits rows
        // in this state and calls the same gateway's verify again, rather
        // than this being treated as a permanent dead end like a real user
        // cancellation is.
        db()->prepare("UPDATE ellsms_payments SET status='verification_failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
        Logger::error('payment.verify_failed', ['payment_id' => $paymentId, 'user_id' => $me['id'], 'info' => $info]);
        $result = ['ok' => false, 'message' => 'تأیید پرداخت ناموفق بود، اما پرداخت شما گم نشده — تأیید به‌صورت خودکار دوباره بررسی می‌شود: ' . $info];
    } elseif ($verify['verified_amount_rial'] !== null && (int)$verify['verified_amount_rial'] !== (int)$payment['amount_rial']) {
        // FIN-39 — AMOUNT MISMATCH: the provider confirms a DIFFERENT amount than what this payment
        // was created for. FAIL CLOSED — no claim, no fulfillment. This is not the same failure mode
        // as verify() itself failing (the provider DID confirm something, just not the right thing),
        // so it gets its own security event rather than being folded into the generic verify-failed
        // path, and the payment is marked 'failed' (a final outcome) rather than 'verification_failed'
        // (a retryable one) — retrying a mismatched amount would just mismatch again.
        db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
        Logger::critical('payment.amount_mismatch', [
            'payment_id' => $paymentId, 'user_id' => $me['id'], 'gateway' => $gateway,
            'expected_amount_rial' => (int)$payment['amount_rial'], 'verified_amount_rial' => $verify['verified_amount_rial'],
        ]);
        audit((int)$me['id'], 'security.payment.amount_mismatch', "#{$paymentId} expected={$payment['amount_rial']} verified={$verify['verified_amount_rial']}");
        $result = ['ok' => false, 'message' => 'مبلغ تأییدشده توسط درگاه با مبلغ فاکتور مطابقت ندارد — پرداخت لغو شد.'];
    } else {
        // Phase 3 (STEP 13): the payment-row claim and the wallet credit
        // now happen inside ONE database transaction, via the same
        // payment_claim_and_credit() (app/zarinpal.php) that
        // cron/payments-reconcile.php (STEP 15) also uses — previously
        // these were two separate, independently-autocommitted statements
        // (docs/security-review.md finding 6), so a crash between them
        // could leave a payment permanently marked 'paid' with the
        // customer never actually credited, no automatic recovery. Now
        // either both happen or neither does. The claim's atomic
        // `WHERE status IN ('pending','verification_failed')` still means
        // only the request that actually flips the row to paid proceeds
        // to credit anything — a retried/duplicate callback hit (ZarinPal
        // retry, a refreshed browser tab) sees rowCount()===0 and does
        // nothing. The wallet's own idempotency_key
        // (payment_credit:{id}) is a second, independent guard against
        // ever double-crediting the same payment even if this code path
        // were somehow re-entered outside that claim check.
        // Phase 13 (STEP 32): a subscription payment routes to its own claim-and-activate function
        // — same atomic-claim/one-transaction/idempotent model, but it activates a subscription
        // instead of crediting the wallet (STEP 33: the two are explicitly different concepts and
        // must never both fire for one payment).
        $isSubscriptionPayment = ($payment['purpose'] ?? 'credit') === 'subscription';
        try {
            $txResult = $isSubscriptionPayment
                ? payment_claim_and_activate_subscription($payment, $refId)
                : payment_claim_and_credit($payment, $refId);
        } catch (Throwable $t) {
            Logger::error('payment.credit_transaction_failed', ['payment_id' => $paymentId, 'user_id' => $me['id'], 'exception' => $t]);
            $txResult = null;
        }

        if ($txResult !== null && $isSubscriptionPayment) {
            if ($txResult['claimed'] && $txResult['activated']) {
                audit((int)$me['id'], 'billing.subscription.paid', "#{$paymentId} ref={$refId}");
                $result = ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد و اشتراک سازمان شما فعال گردید.'];
            } elseif ($txResult['claimed']) {
                // The payment was genuinely claimed but the subscription could not be activated
                // (missing/mismatched billing record). Deliberately NOT reported as a plain success:
                // money moved and service didn't start, which is precisely the case an operator must
                // see. cron/subscription-integrity-check.php reports these as paid-but-unactivated.
                Logger::critical('billing.payment.activation_failed', ['payment_id' => $paymentId, 'reason' => $txResult['reason']]);
                $result = ['ok' => false, 'message' => 'پرداخت شما ثبت شد اما فعال‌سازی اشتراک ناتمام ماند — لطفاً با پشتیبانی تماس بگیرید. کد پیگیری: ' . e($refId)];
            } else {
                $result = ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.'];
            }
        } elseif ($txResult === null) {
            // The transaction rolled back — the payment row is still
            // 'pending' (not stuck 'paid'-without-credit, the old failure
            // mode), so a reconciliation pass or a retried callback can
            // still recover this payment later. See make payments-reconcile.
            $result = ['ok' => false, 'message' => 'مشکلی در ثبت اعتبار رخ داد — لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'];
        } elseif ($txResult['claimed']) {
            audit((int)$me['id'], 'payment.paid', "#{$paymentId} +{$payment['credits']}cr ref={$refId}");
            Logger::info('payment.credited', [
                'payment_id'  => $paymentId,
                'user_id'     => $me['id'],
                'credits'     => $payment['credits'],
                'amount_rial' => $payment['amount_rial'],
                'replayed'    => $txResult['credit']['replayed'] ?? false,
            ]);
            $result = ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد و اعتبار شما افزایش یافت.'];
        } else {
            Logger::info('payment.replayed_already_paid', ['payment_id' => $paymentId, 'user_id' => $me['id']]);
            $result = ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.'];
        }
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="flash flash-<?= $result['ok'] ? 'success' : 'error' ?>"><?= e($result['message']) ?></div>
  <?php if ($payment): ?>
  <table>
    <tr><th>واحد اعتبار</th><td class="num"><?= to_persian_digits(number_format($payment['credits'])) ?></td></tr>
    <tr><th>مبلغ</th><td class="num"><?= to_persian_digits(number_format($payment['amount_rial'])) ?> ریال</td></tr>
  </table>
  <?php endif; ?>
  <div class="toolbar" style="margin-top:14px">
    <a class="btn btn-primary" href="/buy-credit.php">بازگشت به خرید اعتبار</a>
    <a class="btn" href="/index.php">داشبورد</a>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
