<?php
/**
 * «لیست پیام‌های ارسالی» — every sent message in one list, whatever sent it (direct, API, scheduled,
 * autoreply, p2p, smart, gradual), one row per recipient, with a detail drawer per message.
 * The data and its merging live in app/Reports/SentMessages.php; this page only renders.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/SentMessages.php';
require_once __DIR__ . '/../app/views/panel-icons.php'; // the lazily loaded parts' empty states run before header.php
$me = require_login();
$pageTitle = 'لیست پیام‌های ارسالی';
$active = 'reports';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}
// Admin "delete message" (app/HiddenMessages.php): a removed message is left out of every query below
// for the customer; an admin still sees it, badged, with a restore button.
hidden_messages_handle_post();
$hideRemoved = hidden_messages_apply_to_viewer();

// "CSV export" (app/Reports/ExportJobs.php): queues the report as it is filtered right now — the
// filters ride in the form's action URL, so they are the same $_GET the page itself reads — and
// export-worker prepares the file in the background. At most a few open exports per account.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'export') {
    csrf_check();
    $open = db()->prepare("SELECT COUNT(*) FROM ellsms_report_exports WHERE user_id = ? AND status IN ('queued','processing')");
    $open->execute([(int)$me['id']]);
    if ((int)$open->fetchColumn() >= REPORT_EXPORT_MAX_OPEN_PER_USER) {
        flash('error', 'چند خروجی شما هنوز در حال آماده‌سازی است؛ پس از آماده شدن آن‌ها دوباره درخواست کنید.');
    } else {
        // The export knows the statuses pending/sent/delivered/failed; «در صف» is its «pending».
        $exportQuery = $_GET;
        $exportQuery['status'] = ['queued' => 'pending'][$_GET['status'] ?? ''] ?? ($_GET['status'] ?? '');
        $exportFilters = report_export_filters_from_request($me, $exportQuery, is_admin());
        $exportId = report_export_queue((int)($me['organization_id'] ?? 0), (int)$me['id'], $exportFilters,
            'ellsms-report-' . $exportFilters['from'] . '-to-' . $exportFilters['to'] . '.csv');
        audit((int)$me['id'], 'report_export_request', 'export_id=' . $exportId);
        flash('success', 'خروجی در صف آماده‌سازی قرار گرفت؛ پس از آماده شدن از همین صفحه دانلود کنید.');
    }
    redirect('/messages/reports/exports');
}

$isAdmin = is_admin();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$f = sent_report_filters($_GET, $isAdmin, $today);
$scope = dashboard_scope_user_ids($me);
$showUser = $isAdmin || ($scope !== null && count($scope) > 1);
$statuses = sent_report_statuses();
$canHide = $isAdmin && hidden_messages_available();
// The filters every link keeps — only these, so paging parameters (this page's cursor, or an old
// bookmark's before_id / gw_page) never ride along into a filter link or the export.
$filterKeys = ['user_id', 'ref', 'sender', 'dest', 'q', 'type', 'status', 'from_y', 'from_m', 'from_d', 'to_y', 'to_m', 'to_d', 'from_time', 'to_time', 'per_page'];
$filterQuery = array_filter(array_intersect_key($_GET, array_flip($filterKeys)), static fn($v) => is_scalar($v) && $v !== '');

if (lazy_part_begin('summary')) {
    $s = sent_report_summary($f, $scope, $hideRemoved);
    $cards = [
        ''          => ['کل پیام‌ها', 'stat-accent'],
        'delivered' => ['تحویل‌شده', ''],
        'sent'      => ['منتظر گزارش تحویل', ''],
        'failed'    => ['ناموفق', ''],
        'queued'    => ['در صف ارسال', ''],
    ];
    if ($s['cancelled'] > 0) $cards['cancelled'] = ['لغوشده', ''];
    ?>
<div class="report-stats">
  <?php foreach ($cards as $key => [$label, $class]): $value = $key === '' ? $s['total'] : $s[$key]; $isOn = $f['status'] === $key; ?>
    <a class="stat <?= $class ?><?= $isOn ? ' is-on' : '' ?>" href="?<?= e(http_build_query(array_merge($filterQuery, ['status' => $key]))) ?>"<?= $isOn ? ' aria-current="true"' : '' ?>>
      <div class="stat-label"><?= e($label) ?></div>
      <div class="stat-value"><?= to_persian_digits(number_format($value)) ?></div>
      <?php if ($key !== '' && $s['total'] > 0): ?><div class="stat-meta"><?= to_persian_digits((string)round($value / $s['total'] * 100)) ?>٪</div><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php
    exit;
}

if (lazy_part_begin('list')) {
    $cursor = (string)($_GET['cursor'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $result = sent_report_page($f, $scope, $hideRemoved, $cursor !== '' ? $cursor : null);
    $rows = $result['rows'];
    $offset = ($page - 1) * $f['per_page'];
    $hidden = [];
    if ($canHide) {
        foreach (['bulk' => 'bulk_job', 'attempt' => 'attempt', 'legacy' => 'outbound'] as $source => $hiddenSource) {
            $ids = array_map(static fn(array $r): int => $source === 'bulk' ? (int)$r['job_id'] : (int)$r['id'], array_filter($rows, static fn(array $r): bool => $r['source'] === $source));
            $hidden[$source] = hidden_messages_lookup($hiddenSource, $ids);
        }
    }
    ?>
<div class="card report-list">
  <div class="table-wrap"><table data-cards class="report-table">
    <thead><tr>
      <th>ردیف</th><th>شناسه</th><th>فرستنده</th><?php if ($showUser): ?><th>نام کاربری</th><?php endif; ?><th>اپراتور</th>
      <th>گیرنده</th><th>محتوا</th><th>فرمت</th><th>تعداد پیام</th><th>نوع درخواست</th><th>زمان درخواست</th><th>زمان تحویل</th><th>وضعیت</th><th><span class="sr-only">جزئیات</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $n => $r):
        $isHidden = $canHide && isset($hidden[$r['source']][$r['source'] === 'bulk' ? (int)$r['job_id'] : (int)$r['id']]); ?>
      <tr data-detail="<?= e($r['ref']) ?>" tabindex="0" class="is-clickable<?= $isHidden ? ' is-hidden-row' : '' ?>">
        <td class="num"><?= to_persian_digits((string)($offset + $n + 1)) ?></td>
        <td class="ltr report-ref"><?= e($r['ref']) ?></td>
        <td class="msisdn"><?= e(shared_line_mask((string)$r['originator'], $isAdmin)) ?: '—' ?></td>
        <?php if ($showUser): ?><td><?= e($r['username']) ?></td><?php endif; ?>
        <td><?= operator_label($r['operator_code'], $r['operator_name'], 18) ?></td>
        <td class="msisdn"><?= e($r['destination_display']) ?></td>
        <td class="msg-preview" dir="auto"><?= (string)$r['content'] !== '' ? e(mb_strimwidth((string)$r['content'], 0, 48, '…')) : '—' ?><?= $isHidden ? ' ' . hidden_messages_badge() : '' ?></td>
        <td class="ltr"><?= e($r['format']) ?></td>
        <td class="num"><?= to_persian_digits((string)$r['parts']) ?></td>
        <td><?= e($r['type_label']) ?></td>
        <td class="num"><?= sent_report_datetime($r['requested_at']) ?></td>
        <td class="num"><?= !empty($r['delivered_at']) ? sent_report_datetime($r['delivered_at']) : '—' ?></td>
        <td><span class="badge badge-<?= e(sent_report_status_class($r['report_status'])) ?>"><?= e($r['status_label']) ?></span></td>
        <td><button type="button" class="icon-button report-eye" data-detail="<?= e($r['ref']) ?>" aria-label="جزئیات پیام <?= e($r['ref']) ?>" title="جزئیات"><?= panel_icon('eye') ?></button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= $showUser ? 14 : 13 ?>" class="empty-cell"><?= panel_empty_state('پیامی با این فیلترها پیدا نشد', 'بازه‌ی تاریخ را بزرگ‌تر کنید یا فیلترها را تغییر دهید.', null, 'search') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($page > 1 || $result['next'] !== null): ?>
  <nav class="report-pager" aria-label="صفحه‌های فهرست">
    <span class="hint">صفحه‌ی <?= to_persian_digits((string)$page) ?> · ردیف <?= to_persian_digits((string)($offset + 1)) ?> تا <?= to_persian_digits((string)($offset + count($rows))) ?></span>
    <span class="report-pager-links">
      <?php if ($page > 1): ?><a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query($filterQuery)) ?>">→ جدیدترین‌ها</a><?php endif; ?>
      <?php if ($result['next'] !== null): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($filterQuery, ['cursor' => $result['next'], 'page' => $page + 1]))) ?>">قدیمی‌ترها ←</a><?php endif; ?>
    </span>
  </nav>
  <?php endif; ?>
</div>
<?php
    exit;
}

if (lazy_part_begin('detail')) {
    $m = sent_report_find((string)($_GET['m'] ?? ''), $scope, $hideRemoved, $isAdmin);
    require __DIR__ . '/../app/views/sent_message_detail.php';
    exit;
}

$users = $isAdmin ? backend_list_users_summary() : [];
// The export reads its window from the URL like the list does; the dates on screen (today by
// default, or a content search's narrowed window) go into it explicitly so the file matches.
$exportQuery = $filterQuery;
foreach (['from' => $f['from'], 'to' => $f['to']] as $name => $ymd) {
    [$jy, $jm, $jd] = gregorian_to_jalali(...array_map('intval', explode('-', $ymd)));
    [$exportQuery["{$name}_y"], $exportQuery["{$name}_m"], $exportQuery["{$name}_d"]] = [$jy, $jm, $jd];
}
$extraStyles = ['/assets/css/reports.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="report-head">
  <p class="hint">همه‌ی پیام‌های ارسالی — عادی، API، زمان‌بندی‌شده، منشی و ارسال‌های حجیم — در یک فهرست؛ برای دیدن گزارش کامل هر پیام روی ردیف آن بزنید.</p>
  <div class="report-head-links">
    <a class="btn btn-sm btn-ghost" href="/reports-bulk.php">خلاصه‌ی ارسال‌های حجیم</a>
    <a class="btn btn-sm btn-ghost" href="/schedules.php">زمان‌بندی‌ها</a>
    <a class="btn btn-sm btn-ghost" href="/messages/reports/exports">خروجی‌های قبلی</a>
  </div>
</div>

<form method="get" class="card report-filters" aria-label="فیلتر پیام‌ها">
  <div class="report-filter-grid">
    <?php if ($isAdmin): ?>
    <label>نام مشتری
      <select name="user_id">
        <option value="0">همه</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['user_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>شناسه پیام
      <input type="text" name="ref" value="<?= e($f['ref']) ?>" class="ltr" placeholder="B12 یا شناسه‌ی سرویس‌دهنده" autocomplete="off">
    </label>
    <label>فرستنده
      <input type="text" name="sender" value="<?= e($f['sender']) ?>" class="ltr" inputmode="numeric" placeholder="500045…" autocomplete="off">
    </label>
    <label>گیرنده
      <input type="text" name="dest" value="<?= e($f['dest']) ?>" class="ltr" inputmode="numeric" placeholder="98912…" autocomplete="off">
    </label>
    <label class="report-filter-wide">متن پیام
      <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="بخشی از متن پیام">
      <span class="hint">جست‌وجوی متن حداکثر در بازه‌ی <?= to_persian_digits((string)SENT_REPORT_TEXT_SEARCH_MAX_DAYS) ?> روزه انجام می‌شود.</span>
    </label>
    <label>نوع درخواست
      <select name="type">
        <option value="">همه</option>
        <?php foreach (sent_report_types() as $key => $label): ?><option value="<?= e($key) ?>"<?= $f['type'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>"<?= $f['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>از تاریخ <?= jalali_date_select('from', $f['from']) ?></label>
    <label>از ساعت <input type="time" name="from_time" value="<?= e($f['from_time']) ?>" class="ltr"></label>
    <label>تا تاریخ <?= jalali_date_select('to', $f['to']) ?></label>
    <label>تا ساعت <input type="time" name="to_time" value="<?= e($f['to_time']) ?>" class="ltr"></label>
    <label>تعداد در صفحه
      <select name="per_page"><?php foreach (SENT_REPORT_PER_PAGE as $n): ?><option value="<?= $n ?>"<?= $f['per_page'] === $n ? ' selected' : '' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <?php if ($f['notice'] !== ''): ?><p class="flash flash-info report-notice"><?= e($f['notice']) ?></p><?php endif; ?>
  <div class="report-actions">
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
    <a class="btn btn-ghost" href="/reports.php">پاک کردن فیلترها</a>
    <button class="btn" form="reportExportForm"><?= panel_icon('download') ?> دانلود</button>
  </div>
</form>
<form method="post" action="?<?= e(http_build_query($exportQuery)) ?>" id="reportExportForm" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="export">
</form>

<?= lazy_part_placeholder('summary', 110, shape: 'stats') ?>
<?= lazy_part_placeholder('list', 420, shape: 'table') ?>

<dialog class="report-drawer" id="reportDrawer" aria-label="جزئیات پیام">
  <div class="report-drawer-bar"><button type="button" class="icon-button" data-drawer-close aria-label="بستن"><?= panel_icon('close') ?></button></div>
  <div class="report-drawer-body"></div>
</dialog>
<script src="<?= e(asset_url('/assets/js/report-detail.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
