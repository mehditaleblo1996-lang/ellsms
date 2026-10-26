<?php
/**
 * «لیست پیام‌های دریافتی» — inbound SMS on the viewer's lines (both stores), the customer who owns
 * each line, whether it was forwarded on, and a detail drawer per message. Data and scoping live in
 * app/Reports/ReceivedMessages.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/ReceivedMessages.php';
require_once __DIR__ . '/../app/views/panel-icons.php'; // the lazily loaded parts' empty states run before header.php
$me = require_login();
$pageTitle = 'لیست پیام‌های دریافتی';
$active = 'inbox';

$isAdmin = is_admin();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$f = received_report_filters($_GET, $isAdmin, $today);

/*
 * Ownership scoping — fail closed. A non-admin may only see messages that arrived on a line they
 * are actually allowed to use (allowed_originators(), app/authorization.php); a user with zero
 * allowed lines sees zero rows, never an unfiltered table.
 */
$allowedLines = allowed_originators($me);
$hasSenderScope = in_array('*', $allowedLines, true) || $allowedLines !== [];
[$W, $params] = received_report_where($f, $allowedLines);
$showOwner = $isAdmin;
$filterKeys = ['user_id', 'sender', 'dest', 'q', 'from_y', 'from_m', 'from_d', 'to_y', 'to_m', 'to_d', 'from_time', 'to_time', 'per_page'];
$filterQuery = array_filter(array_intersect_key($_GET, array_flip($filterKeys)), static fn($v) => is_scalar($v) && $v !== '');

if (isset($_GET['export'])) {
    // Phase 8 (Invariant C): inbound repository, not a direct inbound_message query — $W/$params
    // are still built entirely by this page (tenant scoping and every filter on screen); only the
    // actual SELECT execution lives in app/Backend/messages.php.
    $exportRows = backend_inbound_export_rows($W, $params, 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-inbox-' . $f['from'] . '_' . $f['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['شناسه','فرستنده','گیرنده','متن پیام','زمان دریافت']);
    foreach ($exportRows as $r) fputcsv($out, $r);
    exit;
}

if (lazy_part_begin('list')) {
    $cnt = backend_inbound_count($W, $params);
    [$page, $offset] = pager_offset($cnt, $f['per_page']);
    $rows = received_report_decorate(backend_inbound_rows($W, $params, $f['per_page'], $offset));
    $cols = $showOwner ? 9 : 8;
    ?>
<div class="card report-list">
  <p class="hint report-count"><?= to_persian_digits(number_format($cnt)) ?> پیام دریافتی در این بازه</p>
  <div class="table-wrap"><table data-cards class="report-table">
    <thead><tr>
      <th>ردیف</th><th>فرستنده</th><th>اپراتور فرستنده</th><th>گیرنده (خط)</th><?php if ($showOwner): ?><th>نام مشتری</th><?php endif; ?>
      <th>وضعیت فوروارد</th><th>تاریخ دریافت</th><th>محتوا</th><th><span class="sr-only">جزئیات</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $n => $m): ?>
      <tr data-detail="<?= (int)$m['id'] ?>" tabindex="0" class="is-clickable">
        <td class="num"><?= to_persian_digits((string)($offset + $n + 1)) ?></td>
        <td class="msisdn"><?= e((string)$m['originator']) ?></td>
        <td><?= operator_label($m['operator_name'], $m['operator_name'], 18) ?></td>
        <td class="msisdn"><?= e((string)$m['destination']) ?></td>
        <?php if ($showOwner): ?><td><?= $m['owner_name'] !== '' ? e($m['owner_name']) : '<span class="muted">—</span>' ?></td><?php endif; ?>
        <td><span class="forward-status is-<?= e($m['forward_state']) ?>"><?= e($m['forward_label']) ?></span></td>
        <td class="num"><?= received_report_datetime((string)$m['received_at']) ?></td>
        <td class="msg-preview" dir="auto"><?= (string)$m['content'] !== '' ? e(mb_strimwidth((string)$m['content'], 0, 60, '…')) : '—' ?></td>
        <td><button type="button" class="icon-button report-eye" data-detail="<?= (int)$m['id'] ?>" aria-label="جزئیات پیام <?= to_persian_digits((string)$m['id']) ?>" title="جزئیات"><?= panel_icon('eye') ?></button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows && !$hasSenderScope): ?>
      <tr><td colspan="<?= $cols ?>" class="empty-cell"><?= panel_empty_state('هنوز هیچ خط ارسالی به شما تخصیص داده نشده', 'صندوق دریافت فقط پیامک‌های خطوط تخصیص‌یافته را نشان می‌دهد. از مدیر بخواهید یک شماره از صفحه‌ی «شماره‌ها» به شما تخصیص دهد.', null, 'phone') ?></td></tr>
    <?php elseif (!$rows): ?>
      <tr><td colspan="<?= $cols ?>" class="empty-cell"><?= panel_empty_state('در این بازه پیامک دریافتی‌ای وجود ندارد', 'پیامک‌های دریافتیِ خط‌های شما به‌طور خودکار اینجا نمایش داده می‌شوند. بازه‌ی تاریخ را بزرگ‌تر کنید یا فیلترها را تغییر دهید.', null, 'inbox') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
  <?= pager_links($page, pager_pages($cnt, $f['per_page']), $filterQuery, 'page', $cnt) ?>
</div>
<?php
    exit;
}

if (lazy_part_begin('detail')) {
    $m = received_report_find((int)report_latin_digits((string)($_GET['m'] ?? '')), $allowedLines, $isAdmin);
    require __DIR__ . '/../app/views/received_message_detail.php';
    exit;
}

$users = $isAdmin ? backend_list_users_summary() : [];
$extraStyles = ['/assets/css/reports.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="report-head">
  <p class="hint">پیامک‌هایی که به خط‌های شما رسیده‌اند، همراه با وضعیت فوروارد آن‌ها به آدرس اینترنتی یا وب‌هوک؛ برای دیدن گزارش کامل هر پیام روی ردیف آن بزنید.</p>
</div>

<form method="get" class="card report-filters" aria-label="فیلتر پیام‌های دریافتی">
  <div class="report-filter-grid">
    <?php if ($isAdmin): ?>
    <label>نام مشتری
      <select name="user_id">
        <option value="0">همه</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['user_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>گیرنده (خط)
      <input type="text" name="dest" value="<?= e($f['dest']) ?>" class="ltr" inputmode="numeric" placeholder="500045…" autocomplete="off">
    </label>
    <label>فرستنده
      <input type="text" name="sender" value="<?= e($f['sender']) ?>" class="ltr" inputmode="numeric" placeholder="98912…" autocomplete="off">
    </label>
    <label>محتوا
      <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="بخشی از متن پیام">
    </label>
    <label>از تاریخ <?= jalali_date_select('from', $f['from']) ?></label>
    <label>از ساعت <input type="time" name="from_time" value="<?= e($f['from_time']) ?>" class="ltr"></label>
    <label>تا تاریخ <?= jalali_date_select('to', $f['to']) ?></label>
    <label>تا ساعت <input type="time" name="to_time" value="<?= e($f['to_time']) ?>" class="ltr"></label>
    <label>تعداد در صفحه
      <select name="per_page"><?php foreach (RECEIVED_REPORT_PER_PAGE as $n): ?><option value="<?= $n ?>"<?= $f['per_page'] === $n ? ' selected' : '' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <div class="report-actions">
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
    <a class="btn btn-ghost" href="/inbox.php">پاک کردن فیلترها</a>
    <a class="btn" href="/inbox.php?<?= e(http_build_query($filterQuery + ['export' => 1])) ?>"><?= panel_icon('download') ?> دانلود</a>
  </div>
</form>

<?= lazy_part_placeholder('list', 420, shape: 'table') ?>

<dialog class="report-drawer" id="reportDrawer" aria-label="جزئیات پیام دریافتی">
  <div class="report-drawer-bar"><button type="button" class="icon-button" data-drawer-close aria-label="بستن"><?= panel_icon('close') ?></button></div>
  <div class="report-drawer-body"></div>
</dialog>
<script src="<?= e(asset_url('/assets/js/report-detail.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
