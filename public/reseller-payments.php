<?php
/**
 * ELLSMS — the reseller's manual payments in its panel: its customers' card-to-card, deposit, cash
 * and cheque payments made to it, waiting for its review, and the methods, cards and accounts it
 * accepts (app/ManualPayments.php). Approving moves the credit from the reseller's wallet.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('payments.manage');
$resellerId = $ctx['reseller_id'];
$pageTitle = 'پرداخت‌های مشتریان';
$active = 'reseller_payments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'payments.manage', '/reseller/payments');
    if (!manual_payments_available()) {
        flash('error', manual_payment_error_message('not_migrated'));
        redirect('/reseller/payments');
    }
    manual_payments_handle_post($resellerId, (int)$ctx['actor_id'], '/reseller/payments');
}

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <h2>پرداخت‌های مشتریان</h2>
  <?php if (!manual_payments_available()): ?>
    <p class="empty"><?= e(manual_payment_error_message('not_migrated')) ?></p>
  <?php else: ?>
    <p class="hint">کارت‌ها و حساب‌هایی که اینجا تعریف کنید به مشتریان شما در صفحه‌ی خرید اعتبار نمایش داده می‌شود و پول مستقیم به شما واریز می‌شود. پس از بررسی، با تأیید هر پرداخت، اعتبار از کیف پول شما به مشتری منتقل می‌شود.</p>
    <?php $mpOwner = $resellerId; $mpBase = '/reseller/payments'; $mpAdminView = false; require __DIR__ . '/../app/views/manual_payments_panel.php'; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
