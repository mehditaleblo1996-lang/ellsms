<?php
/**
 * Browser return from Saman Electronic Payment (SEP). SEP POSTs by default; the redirect form
 * asks for GET (GetMethod=true) so the session cookie survives SameSite=Lax. Either method is
 * accepted. The receipt is verified server-to-server before any credit — see app/Payment/SepGateway.php.
 *
 * Login is not required. SEP's own guide says the buyer's session may already have expired, and
 * a cross-site POST would drop a Lax cookie anyway. The payment row is found by ResNum + token.
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
require_once __DIR__ . '/../app/NotificationCenter.php';

header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method not allowed';
    exit;
}

$params = sep_callback_params($_POST, $_GET);
$result = sep_handle_callback($params);
$payment = $result['payment'];

if (!empty($result['notify']) && $payment) {
    notification_dispatch_user(
        (int)$payment['user_id'],
        (int)($payment['organization_id'] ?? 0) ?: null,
        (string)$result['notify']['event'],
        (string)$result['notify']['title'],
        (string)$result['notify']['body'],
        '/invoices.php',
        (string)$result['notify']['severity']
    );
}

$viewer = current_user();
$owns = $viewer && $payment && (int)$viewer['id'] === (int)$payment['user_id'];
$pageTitle = 'نتیجه‌ی پرداخت';
$active = '';

if ($owns) {
    $me = $viewer;
    require __DIR__ . '/../app/views/header.php';
    ?>
<div class="card">
  <div class="flash flash-<?= $result['ok'] ? 'success' : 'error' ?>"><?= e($result['message']) ?></div>
  <table>
    <tr><th>اعتبار افزوده‌شده</th><td><span class="num"><?= to_persian_digits(number_format((int)$payment['credits'])) ?></span> ریال</td></tr>
    <tr><th>مبلغ</th><td><span class="num"><?= to_persian_digits(number_format((int)$payment['amount_rial'])) ?></span> ریال</td></tr>
    <?php if ($params['trace_no'] !== ''): ?>
    <tr><th>شماره پیگیری</th><td class="ltr"><?= e($params['trace_no']) ?></td></tr>
    <?php endif; ?>
  </table>
  <div class="toolbar" style="margin-top:14px">
    <?php if (!empty($payment['reseller_order_id'])): ?>
      <a class="btn btn-primary" href="/billing/packages">بازگشت به بسته‌ها</a>
    <?php else: ?>
      <a class="btn btn-primary" href="/buy-credit.php">بازگشت به خرید اعتبار</a>
    <?php endif; ?>
    <a class="btn" href="/index.php">داشبورد</a>
  </div>
</div>
    <?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body>
  <div class="card" style="max-width:520px;margin:48px auto">
    <h2>نتیجه‌ی پرداخت</h2>
    <div class="flash flash-<?= $result['ok'] ? 'success' : 'error' ?>"><?= e($result['message']) ?></div>
    <p class="hint">اگر نشست شما در بانک تمام شده باشد، نتیجه همین‌جا ثبت شده است. برای دیدن اعتبار وارد شوید.</p>
    <div class="toolbar">
      <a class="btn btn-primary" href="/login.php">ورود</a>
      <a class="btn" href="/buy-credit.php">خرید اعتبار</a>
    </div>
  </div>
</body>
</html>
