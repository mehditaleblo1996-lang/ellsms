<?php
/** ELLSMS — platform-admin financial history and invoice controls. */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/InvoiceAdmin.php';
$me = require_admin();
$pageTitle = 'گزارش مالی';
$active = 'financial_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    if ($do === 'refund') {
        $result = billing_refund_invoice($invoiceId, (int)$me['id'], trim((string)($_POST['reason'] ?? '')));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'فاکتور بازگشت داده شد.' : 'بازگشت وجه ممکن نشد.');
        redirect('/financial-admin.php?tab=invoices');
    }

    if ($do === 'invoice_mark_paid') {
        $result = invoice_admin_mark_paid($invoiceId, (int)$me['id'], $note);
        $reasonFa = [
            'note_required' => 'برای تأیید دستی پرداخت، توضیح مدیر الزامی است.',
            'invoice_not_found' => 'فاکتور یافت نشد.',
            'invoice_not_issued' => 'این فاکتور در وضعیت پرداخت‌نشده نیست.',
            'invoice_disabled' => 'ابتدا فاکتور را از حالت غیرفعال خارج کنید.',
            'payment_missing' => 'رکورد پرداخت مرتبط پیدا نشد.',
            'payment_state_invalid' => 'وضعیت پرداخت اجازه تأیید دستی را نمی‌دهد.',
            'payment_race' => 'وضعیت پرداخت همزمان تغییر کرد؛ صفحه را تازه کنید.',
            'manual_review_required' => 'این فاکتور با پرداخت دستی (کارت به کارت، واریز، نقدی یا چک) ثبت شده؛ آن را در «پرداخت‌های دستی» تأیید یا رد کنید.',
        ];
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'پرداخت فاکتور تأیید شد و اثر مالی آن نیز اعمال شد.'
            : ($reasonFa[$result['reason']] ?? ('تأیید دستی پرداخت ممکن نشد: ' . ($result['reason'] ?? 'unknown'))));
        redirect('/financial-admin.php?tab=invoices');
    }

    if (in_array($do, ['invoice_approve', 'invoice_disable'], true)) {
        $state = $do === 'invoice_approve' ? 'approved' : 'disabled';
        $result = invoice_admin_set_state($invoiceId, $state, (int)$me['id'], $note);
        $reasonFa = [
            'note_required' => 'برای غیرفعال کردن فاکتور، ذکر دلیل الزامی است.',
            'invoice_not_found' => 'فاکتور یافت نشد.',
            'invoice_not_issued' => 'فقط فاکتور پرداخت‌نشده را می‌توان فعال یا غیرفعال کرد.',
            'active_payment' => 'برای این فاکتور یک پرداخت فعال در درگاه وجود دارد و فعلاً قابل غیرفعال‌سازی نیست.',
            'manual_review_required' => 'پرداخت دستی این فاکتور در انتظار بررسی است؛ آن را در «پرداخت‌های دستی» تأیید یا رد کنید.',
        ];
        flash($result['ok'] ? ($state === 'approved' ? 'success' : 'info') : 'error', $result['ok']
            ? ($state === 'approved' ? 'فاکتور روی «پرداخت‌نشده / قابل پرداخت» قرار گرفت.' : 'فاکتور برای کاربر غیرفعال شد.')
            : ($reasonFa[$result['reason']] ?? 'تغییر وضعیت فاکتور ممکن نشد.'));
        redirect('/financial-admin.php?tab=invoices');
    }
}

$tab = in_array($_GET['tab'] ?? 'invoices', ['invoices','payments','wallet'], true) ? ($_GET['tab'] ?? 'invoices') : 'invoices';
$perPage = 30;
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterOrg = (int)($_GET['organization_id'] ?? 0);
$filterGateway = trim((string)($_GET['gateway'] ?? ''));
$filterPurpose = trim((string)($_GET['purpose'] ?? ''));
$where = [];
$params = [];
$rows = [];
$hasMore = false;

if ($tab === 'invoices') {
    if ($filterStatus !== '') { $where[]='status=?'; $params[]=$filterStatus; }
    if ($filterOrg > 0) { $where[]='organization_id=?'; $params[]=$filterOrg; }
    if ($filterPurpose !== '') { $where[]='purpose=?'; $params[]=$filterPurpose; }
    $table = 'ellsms_invoices';
} elseif ($tab === 'payments') {
    if ($filterStatus !== '') { $where[]='status=?'; $params[]=$filterStatus; }
    if ($filterOrg > 0) { $where[]='organization_id=?'; $params[]=$filterOrg; }
    if ($filterGateway !== '') { $where[]='gateway=?'; $params[]=$filterGateway; }
    if ($filterPurpose !== '') { $where[]='purpose=?'; $params[]=$filterPurpose; }
    $table = 'ellsms_payments';
} else {
    if ($filterOrg > 0) { $where[]='user_id IN (SELECT user_id FROM ellsms_organization_memberships WHERE organization_id=?)'; $params[]=$filterOrg; }
    $table = 'ellsms_wallet_transactions';
}
$whereSql = $where ? ' WHERE '.implode(' AND ',$where) : '';
$countSt = db()->prepare('SELECT COUNT(*) FROM '.$table.$whereSql); $countSt->execute($params);
$total = (int)$countSt->fetchColumn();
[$page, $offset] = pager_offset($total, $perPage);
$st = db()->prepare('SELECT * FROM '.$table.$whereSql.' ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.$offset); $st->execute($params); $rows = $st->fetchAll();

$invoiceStatusFa=['issued'=>'پرداخت‌نشده','paid'=>'پرداخت‌شده','cancelled'=>'لغوشده','expired'=>'منقضی','refunded'=>'بازگشت‌داده‌شده'];
$rowManuals = $tab !== 'wallet' ? manual_payments_for_payments(array_column($rows, $tab === 'invoices' ? 'payment_id' : 'id')) : [];
$rowGateways = [];
if ($tab === 'invoices' && $rows) {
    $gwSt = db()->prepare('SELECT id, gateway FROM ellsms_payments WHERE id IN (' . implode(',', array_fill(0, count($rows), '?')) . ')');
    $gwSt->execute(array_map('intval', array_column($rows, 'payment_id')));
    foreach ($gwSt->fetchAll() as $gw) $rowGateways[(int)$gw['id']] = (string)$gw['gateway'];
}
$manualPending = manual_payments_pending_count(0);
$paymentStatusFa=['pending'=>'در انتظار','verification_failed'=>'در حال بررسی مجدد','paid'=>'موفق','failed'=>'ناموفق'];
$purposeFa=['credit'=>'خرید اعتبار','subscription'=>'اشتراک'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>گزارش مالی</h2>
  <?php if (manual_payments_available()): ?>
  <p><a class="btn btn-sm <?= $manualPending > 0 ? 'btn-primary' : '' ?>" href="/admin/billing/manual-payments">پرداخت‌های دستی در انتظار بررسی: <?= to_persian_digits((string)$manualPending) ?></a></p>
  <?php endif; ?>
  <div class="toolbar" style="margin-bottom:14px">
    <a class="btn btn-sm <?= $tab==='invoices'?'btn-primary':'' ?>" href="?tab=invoices">فاکتورها</a>
    <a class="btn btn-sm <?= $tab==='payments'?'btn-primary':'' ?>" href="?tab=payments">پرداخت‌ها</a>
    <a class="btn btn-sm <?= $tab==='wallet'?'btn-primary':'' ?>" href="?tab=wallet">تراکنش‌های کیف پول</a>
  </div>

  <form method="get" class="toolbar" style="margin-bottom:14px">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label>سازمان (ID)<input type="number" name="organization_id" value="<?= $filterOrg ?: '' ?>" style="width:100px"></label>
    <?php if ($tab!=='wallet'): ?>
      <label>وضعیت<select name="status"><option value="">همه</option><?php foreach(($tab==='invoices'?$invoiceStatusFa:$paymentStatusFa) as $k=>$v): ?><option value="<?= e($k) ?>" <?= $filterStatus===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label>نوع<select name="purpose"><option value="">همه</option><option value="credit" <?= $filterPurpose==='credit'?'selected':'' ?>>خرید اعتبار</option><option value="subscription" <?= $filterPurpose==='subscription'?'selected':'' ?>>اشتراک</option></select></label>
    <?php endif; ?>
    <?php if ($tab==='payments'): ?><label>درگاه / روش<select name="gateway"><option value="">همه</option><option value="zarinpal" <?= $filterGateway==='zarinpal'?'selected':'' ?>>زرین‌پال</option><option value="fake" <?= $filterGateway==='fake'?'selected':'' ?>>آزمایشی</option><?php foreach (MANUAL_PAYMENT_METHODS as $mk => $mv): ?><option value="<?= e($mk) ?>" <?= $filterGateway===$mk?'selected':'' ?>><?= e($mv) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <button class="btn btn-sm">فیلتر</button>
  </form>

  <div class="table-wrap">
  <?php if ($tab==='invoices'): ?>
    <table>
      <tr><th>شماره</th><th>سازمان</th><th>نوع</th><th>روش پرداخت</th><th>مبلغ پایه</th><th>ارزش افزوده</th><th>مبلغ نهایی</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
      <?php foreach($rows as $r): $adminState=invoice_admin_state($r); $rowManual=$rowManuals[(int)$r['payment_id']]??null; ?>
      <tr>
        <td class="num ltr"><?= e($r['invoice_number']) ?></td>
        <td class="num"><?= (int)($r['organization_id']??0) ?: '—' ?></td>
        <td><?= e($purposeFa[$r['purpose']]??$r['purpose']) ?></td>
        <td><?= e(manual_payment_method_label($rowGateways[(int)$r['payment_id']] ?? 'zarinpal')) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['subtotal_amount'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['tax_amount'])) ?> <span class="hint">(۱۰٪)</span></td>
        <td class="num"><strong><?= to_persian_digits(number_format((int)$r['total_amount'])) ?></strong></td>
        <td>
          <?php if ($rowManual !== null && in_array($rowManual['review_state'], ['pending','rejected'], true)): [$ml,$mb]=manual_payment_invoice_status($r,$rowManual); ?><span class="badge badge-<?= $mb ?>"><?= e($ml) ?></span>
          <?php elseif ($r['status']==='paid'): ?><span class="badge badge-ok">پرداخت‌شده</span>
          <?php elseif ($r['status']==='issued' && $adminState==='disabled'): ?><span class="badge badge-off">غیرفعال</span>
          <?php elseif ($r['status']==='issued'): ?><span class="badge badge-pending">پرداخت‌نشده</span>
          <?php else: ?><span class="badge badge-off"><?= e($invoiceStatusFa[$r['status']]??$r['status']) ?></span><?php endif; ?>
          <?php if (!empty($r['admin_note'])): ?><div class="hint"><?= e($r['admin_note']) ?></div><?php endif; ?>
        </td>
        <td class="num"><?= jdate($r['created_at']) ?></td>
        <td><div class="toolbar">
          <?php if ($rowManual !== null && $rowManual['review_state']==='pending'): ?>
            <?php if ((int)$rowManual['collector_user_id']===0): ?><a class="btn btn-sm btn-primary" href="/admin/billing/manual-payments">بررسی</a><?php else: ?><span class="hint">بررسی با نماینده</span><?php endif; ?>
          <?php elseif ($r['status']==='issued'): ?>
            <?php if ($adminState==='disabled'): ?>
              <form method="post"><input type="hidden" name="do" value="invoice_approve"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary">پرداخت‌نشده / فعال</button></form>
            <?php else: ?>
              <form method="post" onsubmit="var n=prompt('توضیح تأیید دستی پرداخت را وارد کنید:');if(n===null||!n.trim())return false;this.note.value=n.trim();return confirm('پرداخت این فاکتور دستی تأیید شود؟ اعتبار/اشتراک کاربر نیز اعمال می‌شود.');"><input type="hidden" name="do" value="invoice_mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="note" value=""><?= csrf_field() ?><button class="btn btn-sm btn-primary">تأیید پرداخت</button></form>
              <form method="post" onsubmit="var n=prompt('دلیل غیرفعال کردن را وارد کنید:');if(n===null||!n.trim())return false;this.note.value=n.trim();return confirm('فاکتور غیرفعال شود؟');"><input type="hidden" name="do" value="invoice_disable"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="note" value=""><?= csrf_field() ?><button class="btn btn-sm btn-danger">غیرفعال</button></form>
            <?php endif; ?>
          <?php elseif ($r['status']==='paid'): ?>
            <form method="post" onsubmit="var n=prompt('دلیل بازگشت وجه را وارد کنید:');if(n===null||!n.trim())return false;this.reason.value=n.trim();return confirm('بازگشت وجه انجام شود؟');"><input type="hidden" name="do" value="refund"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="reason" value=""><?= csrf_field() ?><button class="btn btn-sm btn-danger">بازگشت وجه</button></form>
          <?php endif; ?>
        </div></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php elseif ($tab==='payments'): ?>
    <table><tr><th>#</th><th>سازمان</th><th>نوع</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>مرجع</th><th>تاریخ</th></tr>
      <?php foreach($rows as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= (int)($r['organization_id']??0) ?: '—' ?></td><td><?= e($purposeFa[$r['purpose']??'credit']??($r['purpose']??'credit')) ?></td><td><?= e(manual_payment_method_label((string)($r['gateway']??'zarinpal'))) ?></td><td class="num"><?= to_persian_digits(number_format((int)$r['amount_rial'])) ?></td><td><?php $pm=$rowManuals[(int)$r['id']]??null; ?><?= e($pm !== null && $pm['review_state'] !== 'approved' ? MANUAL_PAYMENT_REVIEW_STATES[$pm['review_state']] : ($paymentStatusFa[$r['status']]??$r['status'])) ?></td><td class="ltr"><?= e((string)($r['ref_id']?:'—')) ?></td><td><?= jdate($r['created_at']) ?></td></tr><?php endforeach; ?>
    </table>
  <?php else: ?>
    <table><tr><th>کاربر</th><th>نوع</th><th>مبلغ</th><th>مانده</th><th>مرجع</th><th>تاریخ</th></tr>
      <?php foreach($rows as $r): ?><tr><td><?= (int)$r['user_id'] ?></td><td><?= e($r['type']) ?></td><td class="num"><?= to_persian_digits(number_format((int)$r['amount'])) ?></td><td class="num"><?= to_persian_digits(number_format((int)$r['balance_after'])) ?></td><td class="ltr"><?= e($r['reference_type']) ?>:<?= e($r['reference_id']) ?></td><td><?= jdate($r['created_at']) ?></td></tr><?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if(!$rows): ?><p class="empty">رکوردی یافت نشد.</p><?php endif; ?>
  </div>

  <?= pager_links($page, pager_pages($total, $perPage), array_filter(['tab'=>$tab,'status'=>$filterStatus,'organization_id'=>$filterOrg?:'','gateway'=>$filterGateway,'purpose'=>$filterPurpose], 'strlen'), 'page', $total) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
