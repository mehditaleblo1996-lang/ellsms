<?php
/**
 * ELLSMS — platform admin: manual payments (card-to-card, bank deposit, cash, cheque) waiting for
 * review, and the platform's accepted methods, cards and accounts (app/ManualPayments.php).
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'پرداخت‌های دستی';
$active = 'manual_payments';

if (!manual_payments_available()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p class="empty">' . e(manual_payment_error_message('not_migrated')) . '</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    manual_payments_handle_post(0, (int)$me['id'], '/admin/billing/manual-payments');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>پرداخت‌های دستی</h2>
  <p class="hint">پرداخت‌های کارت به کارت، واریز به حساب، نقدی و چک کاربران پس از تأیید شما به اعتبارشان اضافه می‌شود. پرداخت مشتریان نمایندگانی که این روش‌ها را خودشان فعال کرده‌اند، توسط همان نماینده بررسی می‌شود.</p>
  <?php $mpOwner = 0; $mpBase = '/admin/billing/manual-payments'; $mpAdminView = true; require __DIR__ . '/../app/views/manual_payments_panel.php'; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
