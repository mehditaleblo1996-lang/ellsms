<?php
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
require_once __DIR__ . '/../app/NotificationCenter.php';
$me = require_login();
$pageTitle = 'نتیجه‌ی پرداخت';
$active = '';

$paymentId = (int)($_GET['payment_id'] ?? 0);
$authorityParam = (string)($_GET['Authority'] ?? '');
$status = (string)($_GET['Status'] ?? '');

$st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
$st->execute([$paymentId]);
$payment = $st->fetch();
$result = null;
$notificationEvent = null;
$notificationTitle = '';
$notificationBody = '';
$notificationSeverity = 'info';

if (!$payment || (int)$payment['user_id'] !== (int)$me['id']) {
    $result = ['ok' => false, 'message' => 'پرداخت پیدا نشد یا متعلق به شما نیست.'];
} elseif ($payment['authority'] !== $authorityParam) {
    $result = ['ok' => false, 'message' => 'اطلاعات پرداخت مطابقت ندارد.'];
} elseif ($payment['status'] === 'paid') {
    $result = ['ok' => true, 'message' => 'این پرداخت قبلاً با موفقیت ثبت شده است.'];
} elseif ($status !== 'OK') {
    db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
    Logger::warning('payment.cancelled_or_failed_at_gateway', ['payment_id' => $paymentId, 'user_id' => $me['id']]);
    $result = ['ok' => false, 'message' => 'پرداخت توسط شما لغو شد یا ناموفق بود.'];
    $notificationEvent = 'payment.failed';
    $notificationTitle = 'پرداخت ناموفق';
    $notificationBody = 'پرداخت #' . $paymentId . ' تکمیل نشد.';
    $notificationSeverity = 'error';
} else {
    $gateway = (string)($payment['gateway'] ?? 'zarinpal');
    $verify = payment_gateway_verify($gateway, (int)$payment['amount_rial'], $payment['authority']);
    $ok = $verify['ok'];
    $info = $verify['message'];
    $refId = $verify['ref_id'];
    if (!$ok) {
        db()->prepare("UPDATE ellsms_payments SET status='verification_failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
        Logger::error('payment.verify_failed', ['payment_id' => $paymentId, 'user_id' => $me['id'], 'info' => $info]);
        $result = ['ok' => false, 'message' => 'تأیید پرداخت ناموفق بود، اما پرداخت شما گم نشده — تأیید به‌صورت خودکار دوباره بررسی می‌شود: ' . $info];
        $notificationEvent = 'payment.failed';
        $notificationTitle = 'بررسی پرداخت ناتمام است';
        $notificationBody = 'پرداخت #' . $paymentId . ' هنوز تأیید نشده و دوباره بررسی خواهد شد.';
        $notificationSeverity = 'warning';
    } elseif ($verify['verified_amount_rial'] !== null && (int)$verify['verified_amount_rial'] !== (int)$payment['amount_rial']) {
        db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$paymentId]);
        Logger::critical('payment.amount_mismatch', [
            'payment_id' => $paymentId, 'user_id' => $me['id'], 'gateway' => $gateway,
            'expected_amount_rial' => (int)$payment['amount_rial'], 'verified_amount_rial' => $verify['verified_amount_rial'],
        ]);
        audit((int)$me['id'], 'security.payment.amount_mismatch', "#{$paymentId} expected={$payment['amount_rial']} verified={$verify['verified_amount_rial']}");
        $result = ['ok' => false, 'message' => 'مبلغ تأییدشده توسط درگاه با مبلغ فاکتور مطابقت ندارد — پرداخت لغو شد.'];
        $notificationEvent = 'payment.failed';
        $notificationTitle = 'پرداخت لغو شد';
        $notificationBody = 'مبلغ تأییدشده برای پرداخت #' . $paymentId . ' با مبلغ فاکتور مطابقت نداشت.';
        $notificationSeverity = 'error';
    } else {
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
                $notificationEvent = 'payment.success';
                $notificationTitle = 'پرداخت موفق';
                $notificationBody = 'پرداخت #' . $paymentId . ' انجام شد و اشتراک سازمان فعال گردید.';
                $notificationSeverity = 'success';
            } elseif ($txResult['claimed']) {
                Logger::critical('billing.payment.activation_failed', ['payment_id' => $paymentId, 'reason' => $txResult['reason']]);
                $result = ['ok' => false, 'message' => 'پرداخت شما ثبت شد اما فعال‌سازی اشتراک ناتمام ماند — لطفاً با پشتیبانی تماس بگیرید. کد پیگیری: ' . e($refId)];
                $notificationEvent = 'payment.failed';
                $notificationTitle = 'فعال‌سازی اشتراک ناتمام ماند';
                $notificationBody = 'پرداخت #' . $paymentId . ' ثبت شد اما فعال‌سازی سرویس نیاز به بررسی پشتیبانی دارد.';
                $notificationSeverity = 'error';
            } else {
                $result = ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.'];
            }
        } elseif ($txResult === null) {
            $result = ['ok' => false, 'message' => 'مشکلی در ثبت اعتبار رخ داد — لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'];
            $notificationEvent = 'payment.failed';
            $notificationTitle = 'ثبت پرداخت ناتمام ماند';
            $notificationBody = 'پرداخت #' . $paymentId . ' هنوز نهایی نشده و قابل بازیابی است.';
            $notificationSeverity = 'warning';
        } elseif ($txResult['claimed']) {
            audit((int)$me['id'], 'payment.paid', "#{$paymentId} +{$payment['credits']}cr ref={$refId}");
            Logger::info('payment.credited', [
                'payment_id' => $paymentId, 'user_id' => $me['id'], 'credits' => $payment['credits'],
                'amount_rial' => $payment['amount_rial'], 'replayed' => $txResult['credit']['replayed'] ?? false,
            ]);
            $result = ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد و اعتبار شما افزایش یافت.'];
            $notificationEvent = 'payment.success';
            $notificationTitle = 'پرداخت موفق';
            $notificationBody = 'پرداخت #' . $paymentId . ' با موفقیت انجام شد و اعتبار حساب افزایش یافت.';
            $notificationSeverity = 'success';
        } else {
            Logger::info('payment.replayed_already_paid', ['payment_id' => $paymentId, 'user_id' => $me['id']]);
            $result = ['ok' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.'];
        }
    }
}

if ($notificationEvent !== null && $payment) {
    notification_dispatch_user(
        (int)$me['id'],
        (int)($me['organization_id'] ?? 0) ?: null,
        $notificationEvent,
        $notificationTitle,
        $notificationBody,
        '/invoices.php',
        $notificationSeverity
    );
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