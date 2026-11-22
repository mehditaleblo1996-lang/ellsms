<?php
/**
 * Hands the buyer to Saman's payment page. The form is submitted by the browser so SEP receives
 * this site's address as Referer — a Location redirect to SEP is rejected (technical guide 3.6).
 * GetMethod=true makes SEP return via GET, which keeps the SameSite=Lax session cookie.
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';

$me = require_login();
header('Cache-Control: no-store');

$paymentId = (int)($_GET['payment_id'] ?? 0);
$st = db()->prepare('SELECT * FROM ellsms_payments WHERE id=?');
$st->execute([$paymentId]);
$payment = $st->fetch();

if (!$payment || (int)$payment['user_id'] !== (int)$me['id'] || (string)($payment['gateway'] ?? '') !== 'sep') {
    flash('error', 'پرداخت پیدا نشد یا متعلق به شما نیست.');
    redirect('/buy-credit.php');
}
if ($payment['status'] === 'paid') {
    flash('success', 'این پرداخت قبلاً با موفقیت ثبت شده است.');
    redirect('/buy-credit.php');
}
$token = trim((string)($payment['authority'] ?? ''));
if (!in_array($payment['status'], ['pending', 'verification_failed'], true) || $token === '') {
    flash('error', 'این پرداخت قابل ادامه نیست. از فاکتورها دوباره پرداخت را شروع کنید.');
    redirect('/invoices.php');
}

$pageTitle = 'انتقال به درگاه سامان';
$active = '';
require __DIR__ . '/../app/views/header.php';
?>
<div class="card" style="max-width:520px;margin:24px auto;text-align:center">
  <h2>در حال انتقال به پرداخت الکترونیک سامان</h2>
  <p>لطفاً این صفحه را نبندید و دکمه بازگشت مرورگر را نزنید.</p>
  <form id="sep-go" method="post" action="<?= e(SEP_TOKEN_URL) ?>">
    <input type="hidden" name="Token" value="<?= e($token) ?>">
    <input type="hidden" name="GetMethod" value="true">
    <noscript><button class="btn btn-primary" type="submit">ادامه به درگاه سامان</button></noscript>
  </form>
</div>
<script>document.getElementById('sep-go').submit();</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
