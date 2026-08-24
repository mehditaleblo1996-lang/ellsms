<?php
/**
 * ELLSMS — PLATFORM-ADMIN financial history (financial-commerce continuation, FIN-12).
 *
 * Deliberately separate from public/billing-admin.php (subscription lifecycle only, per that
 * page's own docblock) — this page is READ-ONLY financial inspection: orders/invoices, payments,
 * wallet transactions. gated on require_admin() like every other platform-admin screen, never on an
 * organization permission (Invariant O).
 *
 * The one write action here is FIN-13's explicit, reason-required refund
 * (billing_refund_invoice(), app/Financial.php) — a full-invoice refund only, never a partial one,
 * never an automatic subscription rollback. Every other financial mutation on this page's data
 * (manual wallet adjustment) reuses the existing wallet_manual_adjustment() mechanism from
 * public/users.php, unchanged.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'گزارش مالی';
$active = 'financial_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'refund') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = billing_refund_invoice($invoiceId, (int)$me['id'], $reason);
        if ($result['ok'] && ($result['reason'] ?? '') === 'refunded') {
            flash('success', 'فاکتور بازگشت داده شد.' . (($result['wallet_reversed'] ?? false)
                ? ' اعتبار کیف پول کاربر بازگردانده شد.'
                : ' اعتبار کیف پول بازگردانده نشد (موجودی کافی برای برگشت وجود نداشت یا این فاکتور اشتراکی بود).'));
        } elseif ($result['ok']) {
            flash('info', 'این فاکتور قبلاً بازگشت داده شده بود.');
        } else {
            $reasonFa = ['reason_required' => 'ذکر دلیل الزامی است.', 'invoice_not_found' => 'فاکتور یافت نشد.', 'invoice_not_paid' => 'فقط فاکتور پرداخت‌شده قابل بازگشت است.', 'payment_missing' => 'رکورد پرداخت یافت نشد.'];
            flash('error', $reasonFa[$result['reason']] ?? ('بازگشت ناموفق: ' . $result['reason']));
        }
        redirect('/financial-admin.php?tab=invoices');
    }
}

$tab = in_array($_GET['tab'] ?? 'invoices', ['invoices', 'payments', 'wallet'], true) ? $_GET['tab'] : 'invoices';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterOrg = (int)($_GET['organization_id'] ?? 0);
$filterGateway = trim((string)($_GET['gateway'] ?? ''));
$filterPurpose = trim((string)($_GET['purpose'] ?? ''));

$rows = [];
$hasMore = false;

if ($tab === 'invoices') {
    $where = [];
    $params = [];
    if ($filterStatus !== '') { $where[] = 'status = ?'; $params[] = $filterStatus; }
    if ($filterOrg > 0) { $where[] = 'organization_id = ?'; $params[] = $filterOrg; }
    if ($filterPurpose !== '') { $where[] = 'purpose = ?'; $params[] = $filterPurpose; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = db()->prepare("SELECT * FROM ellsms_invoices {$whereSql} ORDER BY id DESC LIMIT " . ($perPage + 1) . ' OFFSET ' . $offset);
    $st->execute($params);
    $rows = $st->fetchAll();
} elseif ($tab === 'payments') {
    $where = [];
    $params = [];
    if ($filterStatus !== '') { $where[] = 'status = ?'; $params[] = $filterStatus; }
    if ($filterOrg > 0) { $where[] = 'organization_id = ?'; $params[] = $filterOrg; }
    if ($filterGateway !== '') { $where[] = 'gateway = ?'; $params[] = $filterGateway; }
    if ($filterPurpose !== '') { $where[] = 'purpose = ?'; $params[] = $filterPurpose; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = db()->prepare("SELECT * FROM ellsms_payments {$whereSql} ORDER BY id DESC LIMIT " . ($perPage + 1) . ' OFFSET ' . $offset);
    $st->execute($params);
    $rows = $st->fetchAll();
} else { // wallet
    $where = [];
    $params = [];
    if ($filterOrg > 0) {
        // ellsms_wallet_transactions is keyed by user_id, not organization_id — resolve via the
        // membership table rather than trusting a user-supplied organization_id blindly against an
        // unrelated column.
        $where[] = 'user_id IN (SELECT user_id FROM ellsms_organization_memberships WHERE organization_id = ?)';
        $params[] = $filterOrg;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = db()->prepare("SELECT * FROM ellsms_wallet_transactions {$whereSql} ORDER BY id DESC LIMIT " . ($perPage + 1) . ' OFFSET ' . $offset);
    $st->execute($params);
    $rows = $st->fetchAll();
}

if (count($rows) > $perPage) {
    $hasMore = true;
    $rows = array_slice($rows, 0, $perPage);
}

$invoiceStatusFa = ['issued' => 'صادرشده', 'paid' => 'پرداخت‌شده', 'cancelled' => 'لغوشده', 'expired' => 'منقضی', 'refunded' => 'بازگشت‌داده‌شده'];
$paymentStatusFa = ['pending' => 'در انتظار', 'verification_failed' => 'در حال بررسی مجدد', 'paid' => 'موفق', 'failed' => 'ناموفق'];
$purposeFa = ['credit' => 'خرید اعتبار', 'subscription' => 'اشتراک'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>گزارش مالی</h2>
  <div class="toolbar" style="margin-bottom:14px">
    <a class="btn btn-sm <?= $tab === 'invoices' ? 'btn-primary' : '' ?>" href="?tab=invoices">فاکتورها</a>
    <a class="btn btn-sm <?= $tab === 'payments' ? 'btn-primary' : '' ?>" href="?tab=payments">پرداخت‌ها</a>
    <a class="btn btn-sm <?= $tab === 'wallet' ? 'btn-primary' : '' ?>" href="?tab=wallet">تراکنش‌های کیف پول</a>
  </div>

  <form method="get" class="toolbar" style="margin-bottom:14px">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label>سازمان (ID)<input type="number" name="organization_id" value="<?= $filterOrg ?: '' ?>" style="width:100px"></label>
    <?php if ($tab !== 'wallet'): ?>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (($tab === 'invoices' ? $invoiceStatusFa : $paymentStatusFa) as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>نوع
      <select name="purpose">
        <option value="">همه</option>
        <option value="credit" <?= $filterPurpose === 'credit' ? 'selected' : '' ?>>خرید اعتبار</option>
        <option value="subscription" <?= $filterPurpose === 'subscription' ? 'selected' : '' ?>>اشتراک</option>
      </select>
    </label>
    <?php endif; ?>
    <?php if ($tab === 'payments'): ?>
    <label>درگاه
      <select name="gateway">
        <option value="">همه</option>
        <option value="zarinpal" <?= $filterGateway === 'zarinpal' ? 'selected' : '' ?>>زرین‌پال</option>
        <option value="fake" <?= $filterGateway === 'fake' ? 'selected' : '' ?>>آزمایشی</option>
      </select>
    </label>
    <?php endif; ?>
    <button class="btn btn-sm">فیلتر</button>
  </form>

  <div class="table-wrap">
  <?php if ($tab === 'invoices'): ?>
  <table>
    <tr><th>شماره</th><th>سازمان</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num ltr"><?= e($r['invoice_number']) ?></td>
        <td class="num"><?= (int)($r['organization_id'] ?? 0) ?: '—' ?></td>
        <td><?= e($purposeFa[$r['purpose']] ?? $r['purpose']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['total_amount'])) ?></td>
        <td><span class="badge badge-<?= $r['status'] === 'paid' ? 'ok' : ($r['status'] === 'issued' ? 'pending' : 'off') ?>"><?= e($invoiceStatusFa[$r['status']] ?? $r['status']) ?></span></td>
        <td class="num"><?= jdate($r['created_at']) ?></td>
        <td>
          <?php if ($r['status'] === 'paid'): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="refund">
            <input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="reason" class="refund-reason-input">
            <button type="button" class="btn btn-sm btn-danger" onclick="promptRefund(this)">بازگشت وجه</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <script>
  function promptRefund(btn) {
    var reason = prompt('دلیل بازگشت وجه را وارد کنید (الزامی):');
    if (reason === null) return;
    reason = reason.trim();
    if (!reason) { alert('ذکر دلیل الزامی است.'); return; }
    var form = btn.closest('form');
    form.querySelector('.refund-reason-input').value = reason;
    if (confirm('این فاکتور به‌طور کامل بازگشت داده شود؟ این عمل غیرقابل بازگشت است.')) form.submit();
  }
  </script>
  <?php elseif ($tab === 'payments'): ?>
  <table>
    <tr><th>شماره</th><th>سازمان</th><th>نوع</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>مرجع</th><th>تاریخ</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num">#<?= (int)$r['id'] ?></td>
        <td class="num"><?= (int)($r['organization_id'] ?? 0) ?: '—' ?></td>
        <td><?= e($purposeFa[$r['purpose'] ?? 'credit'] ?? $r['purpose']) ?></td>
        <td><?= e($r['gateway'] ?? 'zarinpal') ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['amount_rial'])) ?></td>
        <td><span class="badge badge-<?= $r['status'] === 'paid' ? 'ok' : (in_array($r['status'], ['pending', 'verification_failed'], true) ? 'pending' : 'off') ?>"><?= e($paymentStatusFa[$r['status']] ?? $r['status']) ?></span></td>
        <td class="num ltr"><?= e((string)($r['ref_id'] ?: '—')) ?></td>
        <td class="num"><?= jdate($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <table>
    <tr><th>کاربر</th><th>نوع</th><th>مبلغ</th><th>مانده پس از تراکنش</th><th>مرجع</th><th>تاریخ</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= (int)$r['user_id'] ?></td>
        <td><?= e($r['type']) ?></td>
        <td class="num" style="color: var(--<?= (int)$r['amount'] < 0 ? 'err' : 'ok' ?>)"><?= to_persian_digits(number_format((int)$r['amount'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['balance_after'])) ?></td>
        <td class="num ltr"><?= e($r['reference_type']) ?>:<?= e($r['reference_id']) ?></td>
        <td class="num"><?= jdate($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if (!$rows): ?><p class="empty">رکوردی یافت نشد.</p><?php endif; ?>
  </div>

  <div class="toolbar" style="margin-top:14px">
    <?php $qs = ['tab' => $tab, 'status' => $filterStatus, 'organization_id' => $filterOrg ?: '', 'gateway' => $filterGateway, 'purpose' => $filterPurpose]; ?>
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= http_build_query($qs + ['page' => $page - 1]) ?>">صفحه‌ی قبل</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn btn-sm" href="?<?= http_build_query($qs + ['page' => $page + 1]) ?>">صفحه‌ی بعد</a><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
