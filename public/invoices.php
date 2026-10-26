<?php
/** ELLSMS — customer invoice list + detail/print view. */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
require_once __DIR__ . '/../app/InvoiceAdmin.php';
$me = require_login();
$pageTitle = 'فاکتورها';
$active = 'invoices';

if (!is_admin()) require_permission(Permissions::PAYMENTS_VIEW);
$orgId = $me['organization_id'] ?? null;
$invoiceId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $postedInvoiceId = (int)($_POST['invoice_id'] ?? 0);

    // Platform-admin controls are intentionally available on the invoice detail itself, not only
    // on the financial list. They use the same domain functions as /financial-admin.php.
    if (is_admin() && in_array($do, ['invoice_mark_paid','invoice_unpaid','invoice_disable'], true)) {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($do === 'invoice_mark_paid') {
            $result = invoice_admin_mark_paid($postedInvoiceId, (int)$me['id'], $note);
            flash($result['ok'] ? 'success' : 'error', $result['ok']
                ? 'پرداخت فاکتور تأیید شد و اثر مالی آن نیز اعمال شد.'
                : ('تأیید پرداخت ممکن نشد: ' . ($result['reason'] ?? 'unknown')));
        } else {
            $state = $do === 'invoice_disable' ? 'disabled' : 'approved';
            $result = invoice_admin_set_state($postedInvoiceId, $state, (int)$me['id'], $note);
            flash($result['ok'] ? ($state === 'approved' ? 'success' : 'info') : 'error', $result['ok']
                ? ($state === 'approved' ? 'فاکتور روی «پرداخت‌نشده / قابل پرداخت» قرار گرفت.' : 'فاکتور برای کاربر غیرفعال شد.')
                : ('تغییر وضعیت ممکن نشد: ' . ($result['reason'] ?? 'unknown')));
        }
        redirect('/invoices.php?id=' . $postedInvoiceId);
    }

    if (impersonation_guard_post('billing.payment')) redirect('/invoices.php');

    if ($do === 'pay') {
        $invoice = billing_invoice_by_id($postedInvoiceId, $orgId, (int)$me['id']);
        if (!$invoice || !invoice_admin_payable($invoice)) {
            flash('error', $invoice && invoice_admin_state($invoice) === 'disabled'
                ? 'این فاکتور توسط مدیر غیرفعال شده و در حال حاضر قابل پرداخت نیست.'
                : 'این فاکتور قابل پرداخت نیست.');
            redirect('/invoices.php');
        }
        $paySt = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $paySt->execute([(int)$invoice['payment_id']]);
        $payment = $paySt->fetch();
        if (!$payment || !in_array($payment['status'], ['pending','verification_failed','failed'], true)) {
            flash('error', 'وضعیت پرداخت این فاکتور اجازه‌ی تلاش مجدد را نمی‌دهد.');
            redirect('/invoices.php');
        }
        // A card-to-card / deposit / cash / cheque payment is settled by its review, never re-paid online.
        if (manual_payment_is_manual_gateway($payment['gateway'] ?? null)) {
            flash('error', 'این فاکتور با پرداخت دستی ثبت شده و در انتظار بررسی است.');
            redirect('/invoices.php?id=' . (int)$invoice['id']);
        }

        $gateway = payment_gateway_name();
        $create = payment_gateway_create($gateway, (int)$invoice['total_amount'], (int)$payment['id'], 'پرداخت فاکتور ' . $invoice['invoice_number'], (string)($me['mobile'] ?? ''));
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
    $purposeFa = ['credit'=>'خرید اعتبار','subscription'=>'اشتراک'];
    $adminState = invoice_admin_state($invoice);
    $manual = $invoice['payment'] ? manual_payment_for_payment((int)$invoice['payment']['id']) : null;
    [$statusLabel, $statusBadge] = manual_payment_invoice_status($invoice, $manual);
    ?>
<div class="card print-invoice">
  <div class="toolbar" style="justify-content:space-between">
    <h2>فاکتور <?= e($invoice['invoice_number']) ?></h2>
    <div class="toolbar"><button type="button" class="btn btn-sm" onclick="window.print()">چاپ</button><a class="btn btn-sm" href="/invoices.php">بازگشت</a></div>
  </div>

  <?php if (is_admin()): ?>
  <div class="card no-print" style="background:var(--tint);box-shadow:none;margin-bottom:16px">
    <h3>کنترل مدیر</h3>
    <div class="toolbar">
      <?php if ($manual !== null && $manual['review_state']==='pending'): ?>
        <?php if ((int)$manual['collector_user_id']===0): ?><a class="btn btn-sm btn-primary" href="/admin/billing/manual-payments">بررسی در «پرداخت‌های دستی»</a>
        <?php else: ?><span class="badge badge-review">بررسی این پرداخت با نماینده‌ی مشتری است</span><?php endif; ?>
      <?php elseif ($invoice['status']==='issued' && $adminState==='approved' && $manual === null): ?>
        <form method="post" onsubmit="var n=prompt('توضیح تأیید دستی پرداخت را وارد کنید:');if(n===null||!n.trim())return false;this.note.value=n.trim();return confirm('پرداخت دستی تأیید شود؟ اعتبار/اشتراک کاربر هم اعمال می‌شود.');">
          <?= csrf_field() ?><input type="hidden" name="do" value="invoice_mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><input type="hidden" name="note" value=""><button class="btn btn-sm btn-primary">پرداخت‌شده</button>
        </form>
        <form method="post" onsubmit="var n=prompt('دلیل غیرفعال کردن را وارد کنید:');if(n===null||!n.trim())return false;this.note.value=n.trim();return confirm('فاکتور غیرفعال شود؟');">
          <?= csrf_field() ?><input type="hidden" name="do" value="invoice_disable"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><input type="hidden" name="note" value=""><button class="btn btn-sm btn-danger">غیرفعال</button>
        </form>
      <?php elseif ($invoice['status']==='issued' && $adminState==='disabled'): ?>
        <form method="post" onsubmit="return confirm('فاکتور دوباره پرداخت‌نشده و قابل پرداخت شود؟')">
          <?= csrf_field() ?><input type="hidden" name="do" value="invoice_unpaid"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><button class="btn btn-sm btn-primary">پرداخت‌نشده / فعال</button>
        </form>
      <?php elseif ($invoice['status']==='paid'): ?>
        <span class="badge badge-ok">این فاکتور پرداخت‌شده است</span>
      <?php endif; ?>
    </div>
    <p class="hint">فاکتور پرداخت‌شده را مستقیم به پرداخت‌نشده برنمی‌گردانیم؛ برای حفظ صحت مالی باید از فرآیند بازگشت وجه استفاده شود.</p>
  </div>
  <?php endif; ?>

  <?php if ($adminState === 'disabled'): ?><div class="flash flash-error">این فاکتور توسط مدیر غیرفعال شده و تا زمان فعال‌سازی مجدد امکان پرداخت آن وجود ندارد.<?= !empty($invoice['admin_note']) ? ' دلیل: ' . e($invoice['admin_note']) : '' ?></div><?php endif; ?>

  <table>
    <tr><th>وضعیت</th><td><span class="badge badge-<?= $statusBadge ?>"><?= e($statusLabel) ?></span></td></tr>
    <?php if ($manual === null): ?><tr><th>دسترسی پرداخت</th><td><span class="badge badge-<?= $adminState==='approved'?'ok':'off' ?>"><?= $adminState==='approved'?'فعال':'غیرفعال توسط مدیر' ?></span></td></tr><?php endif; ?>
    <tr><th>نوع</th><td><?= e($purposeFa[$invoice['purpose']]??$invoice['purpose']) ?></td></tr>
    <?php if ($invoice['payment']): ?><tr><th>روش پرداخت</th><td><?= e(manual_payment_method_label((string)($invoice['payment']['gateway'] ?? 'zarinpal'))) ?></td></tr><?php endif; ?>
    <tr><th>تاریخ صدور</th><td><?= jdate($invoice['issued_at']) ?></td></tr>
    <?php if ($invoice['paid_at']): ?><tr><th>تاریخ پرداخت</th><td><?= jdate($invoice['paid_at']) ?></td></tr><?php endif; ?>
  </table>

  <h3 style="margin-top:20px">اقلام</h3>
  <div class="table-wrap"><table>
    <tr><th>شرح</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>ارزش افزوده</th><th>جمع</th></tr>
    <?php foreach($invoice['items'] as $item): ?><tr><td><?= e($item['description_snapshot']) ?></td><td class="num"><?= to_persian_digits((string)$item['quantity']) ?></td><td class="num"><?= to_persian_digits(number_format((int)$item['unit_price'])) ?></td><td class="num"><?= to_persian_digits(number_format((int)$item['discount_amount'])) ?></td><td class="num"><?= to_persian_digits(number_format((int)$item['tax_amount'])) ?></td><td class="num"><?= to_persian_digits(number_format((int)$item['line_total'])) ?></td></tr><?php endforeach; ?>
  </table></div>

  <table style="margin-top:14px;max-width:340px;margin-inline-start:auto">
    <tr><th>جمع جزء</th><td class="num"><?= to_persian_digits(number_format((int)$invoice['subtotal_amount'])) ?> ریال</td></tr>
    <?php if((int)$invoice['discount_amount']>0): ?><tr><th>تخفیف<?= $invoice['coupon_code']?' ('.e($invoice['coupon_code']).')':'' ?></th><td class="num">-<?= to_persian_digits(number_format((int)$invoice['discount_amount'])) ?> ریال</td></tr><?php endif; ?>
    <tr><th>ارزش افزوده (۱۰٪)</th><td class="num"><?= to_persian_digits(number_format((int)$invoice['tax_amount'])) ?> ریال</td></tr>
    <tr><th><strong>مبلغ نهایی</strong></th><td class="num"><strong><?= to_persian_digits(number_format((int)$invoice['total_amount'])) ?> ریال</strong></td></tr>
  </table>

  <?php if ($manual !== null): ?>
  <h3 style="margin-top:20px">مشخصات پرداخت</h3>
  <?php if ($manual['review_state']==='pending'): ?><div class="flash flash-info">این پرداخت ثبت شده و در انتظار بررسی است. پس از تأیید، <?= to_persian_digits(number_format((int)$manual['credits'])) ?> ریال به اعتبار شما اضافه می‌شود.</div>
  <?php elseif ($manual['review_state']==='rejected'): ?><div class="flash flash-error">این پرداخت رد شد.<?= !empty($manual['review_note']) ? ' دلیل: ' . e($manual['review_note']) : '' ?> در صورت نیاز، پرداخت را دوباره از صفحه‌ی خرید اعتبار ثبت کنید.</div><?php endif; ?>
  <table class="pay-details">
    <tr><th>مبلغ پرداختی</th><td class="num"><?= to_persian_digits(number_format((int)$manual['amount_rial'])) ?> ریال</td></tr>
    <?php if (!empty($manual['destination_label'])): ?><tr><th>واریز به</th><td><?= e(to_persian_digits((string)$manual['destination_label'])) ?></td></tr><?php endif; ?>
    <tr><th>شماره سند / پیگیری</th><td class="ltr"><?= e((string)$manual['document_number']) ?></td></tr>
    <tr><th>تاریخ و ساعت پرداخت</th><td><?= jdate($manual['paid_at']) ?></td></tr>
    <?php if ($manual['method']==='cheque'): ?>
      <tr><th>مبلغ چک</th><td class="num"><?= to_persian_digits(number_format((int)$manual['cheque_amount'])) ?> ریال</td></tr>
      <tr><th>بانک / کد شعبه</th><td><?= e(manual_payment_bank_label((string)$manual['cheque_bank'])) ?> / <span class="ltr"><?= e((string)$manual['cheque_branch_code']) ?></span></td></tr>
      <tr><th>صاحب حساب</th><td><?= e((string)$manual['cheque_owner_name']) ?> — <span class="ltr"><?= e((string)$manual['cheque_owner_national_id']) ?></span></td></tr>
      <tr><th>شبا صاحب چک</th><td class="ltr"><?= e((string)$manual['cheque_owner_sheba']) ?></td></tr>
      <tr><th>صیادی / سریال / سری</th><td class="ltr"><?= e((string)$manual['cheque_sayad_number']) ?> / <?= e((string)$manual['cheque_serial']) ?> / <?= e((string)$manual['cheque_series']) ?></td></tr>
      <tr><th>تاریخ سررسید</th><td><?= jdate($manual['cheque_due_on'], false) ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($manual['description'])): ?><tr><th>توضیحات</th><td><?= e((string)$manual['description']) ?></td></tr><?php endif; ?>
    <?php if (!empty($manual['attachment_file'])): ?><tr><th>تصویر</th><td class="no-print"><a class="btn btn-sm" href="/billing/receipt?id=<?= (int)$manual['id'] ?>" target="_blank" rel="noopener">مشاهده</a></td></tr><?php endif; ?>
    <?php if ($manual['review_state']!=='pending'): ?><tr><th>زمان بررسی</th><td><?= jdate($manual['reviewed_at']) ?></td></tr><?php endif; ?>
  </table>
  <?php endif; ?>

  <?php if($manual === null && invoice_admin_payable($invoice) && $invoice['payment'] && in_array($invoice['payment']['status'],['pending','verification_failed','failed'],true)): ?>
  <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="do" value="pay"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><button class="btn btn-primary">پرداخت این فاکتور</button></form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; return; }

$perPage=20;
$invoiceTotal=billing_invoices_count_for_organization($orgId,(int)$me['id']);
[$page,$offset]=pager_offset($invoiceTotal,$perPage);
$invoices=billing_invoices_for_organization($orgId,(int)$me['id'],$perPage,$offset);
$invoiceManuals=manual_payments_for_payments(array_column($invoices,'payment_id'));
$invoiceGateways=[];
if ($invoices) {
    $gwSt=db()->prepare('SELECT id, gateway FROM ellsms_payments WHERE id IN ('.implode(',',array_fill(0,count($invoices),'?')).')');
    $gwSt->execute(array_map('intval',array_column($invoices,'payment_id')));
    foreach ($gwSt->fetchAll() as $gw) $invoiceGateways[(int)$gw['id']]=(string)$gw['gateway'];
}
$purposeFa=['credit'=>'خرید اعتبار','subscription'=>'اشتراک'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="card"><h2>فاکتورها</h2><div class="table-wrap"><table>
<tr><th>شماره فاکتور</th><th>نوع</th><th>روش پرداخت</th><th>مبلغ (ریال)</th><th>وضعیت</th><th>دسترسی</th><th>تاریخ</th><th></th></tr>
<?php foreach($invoices as $inv): $as=invoice_admin_state($inv); $im=$invoiceManuals[(int)$inv['payment_id']]??null; [$sl,$sb]=manual_payment_invoice_status($inv,$im); ?><tr><td class="num ltr"><?= e($inv['invoice_number']) ?></td><td><?= e($purposeFa[$inv['purpose']]??$inv['purpose']) ?></td><td><?= e(manual_payment_method_label($invoiceGateways[(int)$inv['payment_id']]??'zarinpal')) ?></td><td class="num"><?= to_persian_digits(number_format((int)$inv['total_amount'])) ?></td><td><span class="badge badge-<?= $sb ?>"><?= e($sl) ?></span></td><td><span class="badge badge-<?= $as==='approved'?'ok':'off' ?>"><?= $as==='approved'?'فعال':'غیرفعال' ?></span></td><td class="num"><?= jdate($inv['created_at']) ?></td><td><a class="btn btn-sm" href="/invoices.php?id=<?= (int)$inv['id'] ?>">مشاهده</a></td></tr><?php endforeach; ?>
<?php if(!$invoices): ?><tr><td colspan="8" class="empty">فاکتوری ثبت نشده.</td></tr><?php endif; ?>
</table></div><?= pager_links($page,pager_pages($invoiceTotal,$perPage),[],'page',$invoiceTotal) ?></div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
