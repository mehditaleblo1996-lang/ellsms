<?php
require_once __DIR__ . '/../app/zarinpal.php';
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
    if ($payment['status'] === 'pending') {
        db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status='pending'")->execute([$paymentId]);
    }
    $result = ['ok' => false, 'message' => 'پرداخت توسط شما لغو شد یا ناموفق بود.'];
} else {
    [$ok, $info, $refId] = zarinpal_verify((int)$payment['amount_rial'], $payment['authority']);
    if (!$ok) {
        db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=? AND status='pending'")->execute([$paymentId]);
        $result = ['ok' => false, 'message' => 'تأیید پرداخت ناموفق بود: ' . $info];
    } else {
        // Atomic claim: only the request that actually flips pending->paid
        // gets to credit the account, so a retried/duplicate callback
        // hit from ZarinPal (or a refreshed browser tab) can never
        // double-credit — rowCount()===0 means someone already processed it.
        $claim = db()->prepare("UPDATE ellsms_payments SET status='paid', ref_id=? WHERE id=? AND status='pending'");
        $claim->execute([$refId, $paymentId]);
        if ($claim->rowCount() > 0) {
            db()->prepare('UPDATE user_ SET currentcredit = currentcredit + ? WHERE id = ?')
               ->execute([$payment['credits'], $me['id']]);
            audit((int)$me['id'], 'payment.paid', "#{$paymentId} +{$payment['credits']}cr ref={$refId}");
            $result = ['ok' => true, 'message' => 'پرداخت با موفقیت انجام شد و اعتبار شما افزایش یافت.'];
        } else {
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
