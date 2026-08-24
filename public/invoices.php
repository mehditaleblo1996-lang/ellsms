<?php
/**
 * ELLSMS — customer invoice list + detail/print view (financial-commerce continuation, FIN-5/FIN-11).
 *
 * Read-only except for the "pay" action on an unpaid invoice, which re-derives the amount from the
 * invoice's own stored total (never from request input) and starts a fresh gateway payment against
 * the SAME payment row the invoice is already linked to — no new invoice is created for a retry.
 *
 * Organization-scoped exactly like every other financial page: billing_invoice_by_id()/
 * billing_invoices_for_organization() (app/Financial.php) enforce ownership server-side.
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'فاکتورها';
$active = 'invoices';

if (!is_admin()) {
    require_permission(Permissions::PAYMENTS_VIEW);
}
$orgId = $me['organization_id'] ?? null;

$invoiceId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('billing.payment')) {
        redirect('/invoices.php');
    }
    $do = $_POST['do'] ?? '';
    $postedInvoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($do === 'pay') {
        $invoice = billing_invoice_by_id($postedInvoiceId, $orgId, (int)$me['id']);
        if (!$invoice || $invoice['status'] !== 'issued') {
            flash('error', 'این فاکتور قابل پرداخت نیست.');
            redirect('/invoices.php');
        }
        $paySt = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $paySt->execute([(int)$invoice['payment_id']]);
        $payment = $paySt->fetch();
        if (!$payment || !in_array($payment['status'], ['pending', 'verification_failed', 'failed'], true)) {
            flash('error', 'وضعیت پرداخت این فاکتور اجازه‌ی تلاش مجدد را نمی‌دهد.');
            redirect('/invoices.php');
        }

        $gateway = payment_gateway_name();
        $description = 'پرداخت فاکتور ' . $invoice['invoice_number'];
        $create = payment_gateway_create($gateway, (int)$invoice['total_amount'], (int)$payment['id'], $description, (string)($me['mobile'] ?? ''));
        if ($create['ok']) {
            db()->prepare("UPDATE ellsms_payments SET status='pending', authority=?, gateway=? WHERE id=?")
                ->execute([$create['authority'], $gateway, $payment['id']]);
            audit((int)$me['id'], 'invoice.payment_retry', "invoice={$invoice['invoice_number']} payment=#{$payment['id']}");
            redirect(payment_gateway_redirect_url($gateway, $create['authority']));
        }
        flash('error', 'شروع پرداخت ممکن نشد: ' . $create['message']);
        redirect('/invoices.php');
    }
}

if ($invoiceId > 0) {
    $invoice = billing_invoice_with_payment($invoiceId, $orgId, (int)$me['id']);
    if (!$invoice) {
        flash('error', 'فاکتور یافت نشد یا متعلق به شما نیست.');
        redirect('/invoices.php');
    }
    require __DIR__ . '/../app/views/header.php';
    $purposeFa = ['credit' => 'خرید اعتبار', 'subscription' => 'اشتراک'];
    $statusFa = ['issued' => 'صادرشده', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده', 'expired' => 'منقضی', 'refunded' => 'بازگشت‌داده‌شده'];
    ?>
<div class="card print-invoice">
  <div class="toolbar" style="justify-content:space-between">
    <h2>فاکتور <?= e($invoice['invoice_number']) ?></h2>
    <div class="toolbar">
      <button type="button" class="btn btn-sm" onclick="window.print()">چاپ</button>
      <a class="btn btn-sm" href="/invoices.php">بازگشت</a>
    </div>
  </div>
  <table>
    <tr><th>وضعیت</th><td><span class="badge badge-<?= $invoice['status'] === 'paid' ? 'ok' : ($invoice['status'] === 'issued' ? 'pending' : 'off') ?>"><?= e($statusFa[$invoice['status']] ?? $invoice['status']) ?></span></td></tr>
    <tr><th>نوع</th><td><?= e($purposeFa[$invoice['purpose']] ?? $invoice['purpose']) ?></td></tr>
    <tr><th>تاریخ صدور</th><td><?= jdate($invoice['issued_at']) ?></td></tr>
    <?php if ($invoice['paid_at']): ?><tr><th>تاریخ پرداخت</th><td><?= jdate($invoice['paid_at']) ?></td></tr><?php endif; ?>
  </table>

  <h3 style="margin-top:20px">اقلام</h3>
  <div class="table-wrap">
  <table>
    <tr><th>شرح</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>مالیات</th><th>جمع</th></tr>
    <?php foreach ($invoice['items'] as $item): ?>
      <tr>
        <td><?= e($item['description_snapshot']) ?></td>
        <td class="num"><?= to_persian_digits((string)$item['quantity']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$item['unit_price'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$item['discount_amount'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$item['tax_amount'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$item['line_total'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <table style="margin-top:14px; max-width:340px; margin-inline-start:auto">
    <tr><th>جمع جزء</th><td class="num"><?= to_persian_digits(number_format((int)$invoice['subtotal_amount'])) ?> ریال</td></tr>
    <?php if ((int)$invoice['discount_amount'] > 0): ?>
    <tr><th>تخفیف<?= $invoice['coupon_code'] ? ' (' . e($invoice['coupon_code']) . ')' : '' ?></th><td class="num">-<?= to_persian_digits(number_format((int)$invoice['discount_amount'])) ?> ریال</td></tr>
    <?php endif; ?>
    <?php if ((int)$invoice['tax_amount'] > 0): ?>
    <tr><th>مالیات</th><td class="num"><?= to_persian_digits(number_format((int)$invoice['tax_amount'])) ?> ریال</td></tr>
    <?php endif; ?>
    <tr><th><strong>مبلغ نهایی</strong></th><td class="num"><strong><?= to_persian_digits(number_format((int)$invoice['total_amount'])) ?> ریال</strong></td></tr>
  </table>

  <?php if ($invoice['status'] === 'issued' && $invoice['payment'] && in_array($invoice['payment']['status'], ['pending', 'verification_failed', 'failed'], true)): ?>
  <form method="post" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="pay">
    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
    <button class="btn btn-primary">پرداخت این فاکتور</button>
  </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; return; }

// ------------------------------------------------------------------ list
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$invoices = billing_invoices_for_organization($orgId, (int)$me['id'], $perPage, ($page - 1) * $perPage);
$statusFa = ['issued' => 'صادرشده', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده', 'expired' => 'منقضی', 'refunded' => 'بازگشت‌داده‌شده'];
$purposeFa = ['credit' => 'خرید اعتبار', 'subscription' => 'اشتراک'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>فاکتورها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شماره فاکتور</th><th>نوع</th><th>مبلغ (ریال)</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($invoices as $inv): ?>
      <tr>
        <td class="num ltr"><?= e($inv['invoice_number']) ?></td>
        <td><?= e($purposeFa[$inv['purpose']] ?? $inv['purpose']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$inv['total_amount'])) ?></td>
        <td><span class="badge badge-<?= $inv['status'] === 'paid' ? 'ok' : ($inv['status'] === 'issued' ? 'pending' : 'off') ?>"><?= e($statusFa[$inv['status']] ?? $inv['status']) ?></span></td>
        <td class="num"><?= jdate($inv['created_at']) ?></td>
        <td><a class="btn btn-sm" href="/invoices.php?id=<?= (int)$inv['id'] ?>">مشاهده</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="6" class="empty">فاکتوری ثبت نشده.</td></tr><?php endif; ?>
  </table>
  </div>
  <div class="toolbar" style="margin-top:14px">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?page=<?= $page - 1 ?>">صفحه‌ی قبل</a><?php endif; ?>
    <?php if (count($invoices) === $perPage): ?><a class="btn btn-sm" href="?page=<?= $page + 1 ?>">صفحه‌ی بعد</a><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
