<?php
/**
 * «گزارش وضعیت‌ها» — how many messages, and how many SMS parts (بخش), ended in each state, per month
 * of a Jalali year (or per day of a month / short range), with a bar chart per period and a donut of
 * the whole range. Admins see everyone (and can narrow to an organization or a user); a customer sees
 * their own organization. The data and the column rules live in app/Reports/StatusReport.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/SentMessages.php';
require_once __DIR__ . '/../app/Reports/StatusReport.php';
require_once __DIR__ . '/../app/views/panel-icons.php'; // the lazily loaded part's empty state runs before header.php
$me = require_login();
$pageTitle = 'گزارش وضعیت‌ها';
$active = 'status_report';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

$admin = is_admin();
// «رسیده / نرسیده به گوشی» only for admins and customers on delivery-based billing.
$showDelivery = delivery_status_visible($me);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$f = status_report_filters($_GET, $admin, $today);
$scope = status_report_scope($me, $admin, $f['org_id'], $f['user_id'], $f['vip']);
$hideRemoved = hidden_messages_apply_to_viewer();
$filterKeys = ['org_id', 'vip', 'user_id', 'lines', 'encoding', 'operator', 'year', 'month', 'from_y', 'from_m', 'from_d', 'to_y', 'to_m', 'to_d', 'parts', 'channel'];
$filterQuery = array_filter(array_intersect_key($_GET, array_flip($filterKeys)), static fn($v) => is_array($v) ? $v !== [] : (is_scalar($v) && $v !== ''));
$rangeLabel = $f['from'] === $f['to'] ? jdate($f['from'], false) : jdate($f['from'], false) . ' تا ' . jdate($f['to'], false);

/* ---------- Excel (CSV) download: the same table, every cell as plain numbers ---------- */
if (isset($_GET['export'])) {
    $report = status_report_build($f, $scope, $hideRemoved, $showDelivery);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="status-report-' . $f['from'] . '_' . $f['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    $head = [$f['granularity'] === 'month' ? 'ماه' : 'روز'];
    foreach ($report['columns'] as $col) {
        $head[] = STATUS_REPORT_COLUMNS[$col][0] . ' - پیامک';
        $head[] = STATUS_REPORT_COLUMNS[$col][0] . ' - بخش';
    }
    array_push($head, 'مجموع کل - پیامک', 'مجموع کل - بخش');
    fputcsv($out, $head);
    $line = static function (string $label, array $cols, array $total) use ($report): array {
        $cells = [$label];
        foreach ($report['columns'] as $col) array_push($cells, $cols[$col]['n'], $cols[$col]['p']);
        array_push($cells, $total['n'], $total['p']);
        return $cells;
    };
    foreach ($report['table']['rows'] as $row) fputcsv($out, $line(report_latin_digits($row['label']), $row['cols'], $row['total']));
    fputcsv($out, $line('جمع کل', $report['table']['totals'], $report['table']['total']));
    audit((int)$me['id'], 'status_report.export', $f['from'] . '..' . $f['to']);
    exit;
}

/* ---------- The table and the charts, loaded after the page ---------- */
if (lazy_part_begin('report')) {
    $report = status_report_build($f, $scope, $hideRemoved, $showDelivery);
    $columns = $report['columns'];
    $t = $report['table'];
    $hasData = $t['total']['n'] > 0 || $t['total']['p'] > 0;
    ?>
<section class="card sr-card sr-table-card" aria-labelledby="srTableTitle">
  <h2 class="sr-legend" id="srTableTitle">جدول گزارشات</h2>
  <p class="hint sr-range"><?= e($rangeLabel) ?><?php if ($report['as_of'] !== null): ?> · آمار هر چند دقیقه به‌روز می‌شود؛ آخرین به‌روزرسانی: <span class="num"><?= jdate_from_utc($report['as_of']) ?></span><?php endif; ?></p>
  <div class="table-wrap sr-wrap"><table class="sr-table">
    <thead>
      <tr>
        <th rowspan="2" scope="col" class="sr-period-head"><?= $f['granularity'] === 'month' ? 'ماه' : 'روز' ?></th>
        <?php foreach ($columns as $col): ?><th colspan="2" scope="colgroup" class="sr-group sr-c-<?= e($col) ?>"><?= e(STATUS_REPORT_COLUMNS[$col][0]) ?></th><?php endforeach; ?>
        <th colspan="2" scope="colgroup" class="sr-group sr-c-total">مجموع کل</th>
      </tr>
      <tr>
        <?php foreach (array_merge($columns, ['total']) as $col): ?><th scope="col" class="sr-sub sr-c-<?= e($col) ?>">پیامک</th><th scope="col" class="sr-sub sr-c-<?= e($col) ?>">بخش</th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($t['rows'] as $row): ?>
      <tr<?= $row['total']['n'] === 0 && $row['total']['p'] === 0 ? ' class="sr-empty-row"' : '' ?>>
        <th scope="row" class="sr-period"><?= e($row['label']) ?></th>
        <?php foreach ($columns as $col): ?>
          <td class="num sr-c-<?= e($col) ?>"><?= status_report_num($row['cols'][$col]['n']) ?></td>
          <td class="num sr-c-<?= e($col) ?> sr-parts"><?= status_report_num($row['cols'][$col]['p']) ?></td>
        <?php endforeach; ?>
        <td class="num sr-c-total"><?= status_report_num($row['total']['n']) ?></td>
        <td class="num sr-c-total sr-parts"><?= status_report_num($row['total']['p']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="sr-total">
        <th scope="row" class="sr-period">جمع کل</th>
        <?php foreach ($columns as $col): ?>
          <td class="num sr-c-<?= e($col) ?>"><?= status_report_num($t['totals'][$col]['n']) ?></td>
          <td class="num sr-c-<?= e($col) ?> sr-parts"><?= status_report_num($t['totals'][$col]['p']) ?></td>
        <?php endforeach; ?>
        <td class="num sr-c-total"><?= status_report_num($t['total']['n']) ?></td>
        <td class="num sr-c-total sr-parts"><?= status_report_num($t['total']['p']) ?></td>
      </tr>
    </tfoot>
  </table></div>
  <?php if (!$hasData): ?>
    <?= panel_empty_state('در این بازه پیامی با این فیلترها نیست', 'سال، ماه یا بازه‌ی تاریخ را تغییر دهید یا فیلترها را کم کنید.', null, 'search') ?>
  <?php endif; ?>
  <p class="hint sr-note">«پیامک» تعداد پیام‌ها (هر گیرنده یک پیام) و «بخش» تعداد بخش‌های پیامک است — هر پیام فارسی تا ۷۰ نویسه و انگلیسی تا ۱۶۰ نویسه یک بخش است و پیام بلندتر چند بخش. <?php if ($showDelivery): ?>«رسیده به مخابرات» پیام‌هایی است که اپراتور پذیرفته و گزارش تحویل نهایی‌شان هنوز نرسیده؛ «نرسیده به مخابرات» پیام‌هایی که سرویس‌دهنده هنگام ارسال رد کرده است.<?php else: ?>«رسیده به مخابرات» همه‌ی پیام‌هایی است که به اپراتور تحویل داده شده؛ «نرسیده به مخابرات» پیام‌هایی که هنگام ارسال رد شده است.<?php endif; ?> «لیست سیاه» شامل لیست سیاه مخابرات، لیست سیاه خود شما و گیرندگانی است که با ارسال ۱۱ عضویت خود را لغو کرده‌اند. پیام‌های لغوشده در این گزارش نیستند.</p>
</section>

<div class="sr-charts">
  <section class="card sr-card" data-sr-chart="bars" aria-labelledby="srBarsTitle">
    <h2 class="sr-chart-title" id="srBarsTitle">نمودار پیام‌های ارسال شده</h2>
    <ul class="sr-chart-legend">
      <?php foreach ($columns as $col): ?><li><span class="sr-swatch" style="background:<?= e(STATUS_REPORT_COLUMNS[$col][1]) ?>"></span><?= e(STATUS_REPORT_COLUMNS[$col][0]) ?></li><?php endforeach; ?>
    </ul>
    <div class="sr-bars-wrap"><?= status_report_bar_chart($t['rows'], $columns) ?></div>
  </section>
  <section class="card sr-card" data-sr-chart="donut" aria-labelledby="srDonutTitle">
    <h2 class="sr-chart-title" id="srDonutTitle">نمودار تعداد پیام‌های ارسال شده</h2>
    <?= operator_report_donut(array_map(static fn(string $col): array => [
        'label' => STATUS_REPORT_COLUMNS[$col][0], 'value' => $t['totals'][$col]['n'], 'color' => STATUS_REPORT_COLUMNS[$col][1],
    ], $columns), 'سهم هر وضعیت از پیام‌ها') ?>
    <p class="hint sr-parts-total">مجموع بخش‌ها: <strong class="num"><?= status_report_num($t['total']['p']) ?></strong></p>
  </section>
</div>
<?php
    exit;
}

/* ---------- Filter choices ---------- */
$organizations = [];
if ($admin) {
    try {
        $organizations = db()->query('SELECT id, name FROM ellsms_organizations ORDER BY name')->fetchAll();
    } catch (Throwable) {
        // organizations not migrated: no organization filter
    }
}
// «کاربر»: an admin picks anyone (or a member of the picked organization); a customer one of their members.
if ($admin) {
    if ($f['org_id'] > 0) {
        $users = [];
        foreach (backend_usernames_by_ids(array_map('intval', organization_member_user_ids($f['org_id']))) as $id => $username) {
            $users[] = ['id' => (int)$id, 'username' => (string)$username];
        }
        usort($users, static fn(array $a, array $b): int => strcmp($a['username'], $b['username']));
    } else {
        $users = backend_list_users_summary();
    }
} else {
    $memberIds = operator_report_scope($me, false, 0)['members'] ?? [(int)$me['id']];
    $users = [];
    foreach (backend_usernames_by_ids(array_map('intval', $memberIds)) as $id => $username) {
        $users[] = ['id' => (int)$id, 'username' => (string)$username];
    }
}
// «شماره‌های اختصاصی»: the customer's own lines; for an admin, the picked user's / organization's, else all.
$lines = [];
try {
    if (!$admin) {
        $lines = array_merge(allowed_originators($me), array_column(user_shared_numbers($me), 'number'));
    } elseif ($f['user_id'] > 0) {
        $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
        $st->execute([$f['user_id']]);
        $lines = $st->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($f['org_id'] > 0) {
        $members = array_map('intval', organization_member_user_ids($f['org_id']));
        $sql = 'SELECT number FROM ellsms_numbers WHERE organization_id = ?' . ($members ? ' OR assigned_user_id IN (' . implode(',', array_fill(0, count($members), '?')) . ')' : '') . ' ORDER BY number';
        $st = db()->prepare($sql);
        $st->execute(array_merge([$f['org_id']], $members));
        $lines = $st->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $lines = db()->query('SELECT number FROM ellsms_numbers ORDER BY number LIMIT 3000')->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable) {
    // numbers not migrated: the picked lines (if any) are still listed below
}
$lines = array_values(array_unique(array_filter(array_map(static fn($l): string => (string)normalize_originator((string)$l), array_merge($lines, $f['lines'])),
    static fn(string $l): bool => $l !== '' && $l !== '*')));
sort($lines);
$operators = operator_report_operator_order([]);
$operators[] = OPERATOR_REPORT_UNKNOWN;
[$thisYear] = status_report_jalali($today);
$years = range($thisYear, max(1400, $thisYear - 6));
$exportQuery = $filterQuery + ['export' => 1];

$extraStyles = ['/assets/css/operator-reports.css', '/assets/css/status-report.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="sr-head">
  <p class="hint">تعداد پیام‌ها و تعداد بخش‌های هر وضعیت، ماه‌به‌ماه یا روزبه‌روز.</p>
  <div class="sr-tools" role="toolbar" aria-label="ابزار گزارش">
    <button type="button" class="icon-button sr-tool" data-sr-print title="چاپ" aria-label="چاپ گزارش"><?= panel_icon('print') ?></button>
    <a class="icon-button sr-tool sr-tool-ok" href="?<?= e(http_build_query($exportQuery)) ?>" title="دانلود فایل اکسل (CSV)" aria-label="دانلود فایل اکسل"><?= panel_icon('download') ?></a>
    <button type="button" class="icon-button sr-tool sr-tool-err" data-sr-toggle="bars" aria-pressed="true" title="نمایش / پنهان کردن نمودار ستونی" aria-label="نمودار ستونی"><?= panel_icon('chart') ?></button>
    <button type="button" class="icon-button sr-tool sr-tool-indigo" data-sr-toggle="donut" aria-pressed="true" title="نمایش / پنهان کردن نمودار دایره‌ای" aria-label="نمودار دایره‌ای"><?= panel_icon('pie') ?></button>
  </div>
</div>

<form method="get" class="card sr-card sr-filters" aria-labelledby="srFilterTitle">
  <h2 class="sr-legend" id="srFilterTitle">فیلتر گزارشات</h2>
  <div class="sr-filter-grid">
    <?php if ($admin): ?>
    <label>سازمان
      <select name="org_id" data-sr-resubmit>
        <option value="">همه‌ی سازمان‌ها</option>
        <?php foreach ($organizations as $o): ?><option value="<?= (int)$o['id'] ?>"<?= $f['org_id'] === (int)$o['id'] ? ' selected' : '' ?>><?= e((string)$o['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?= vip_filter_select($f['vip']) ?>
    <?php endif; ?>

    <div class="sr-field sr-lines-field">
      <span class="sr-label" id="srLinesLabel">شماره‌های اختصاصی</span>
      <details class="sr-multi" data-sr-multi>
        <summary aria-labelledby="srLinesLabel"><span data-sr-multi-text><?= $f['lines'] ? to_persian_digits((string)count($f['lines'])) . ' شماره انتخاب شده' : 'همه‌ی شماره‌ها' ?></span></summary>
        <div class="sr-multi-pop">
          <input type="search" class="sr-multi-search" placeholder="جست‌وجوی شماره" aria-label="جست‌وجوی شماره" data-sr-multi-search>
          <?php if ($lines): ?>
          <div class="sr-multi-actions"><button type="button" class="btn btn-sm btn-ghost" data-sr-multi-clear>پاک کردن انتخاب</button></div>
          <ul class="sr-multi-list">
            <?php foreach ($lines as $line): ?>
            <li><label class="sr-check"><input type="checkbox" name="lines[]" value="<?= e($line) ?>"<?= in_array($line, $f['lines'], true) ? ' checked' : '' ?>> <span class="ltr"><?= e($line) ?></span></label></li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <p class="hint">شماره‌ای برای انتخاب نیست.</p>
          <?php endif; ?>
        </div>
      </details>
    </div>

    <?php if ($admin || count($users) > 1): ?>
    <label>کاربر
      <select name="user_id"<?= $admin ? ' data-sr-resubmit' : '' ?>>
        <option value="">همه‌ی کاربران</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['user_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= e(($admin && vip_is((int)$u['id']) ? '★ ' : '') . (string)$u['username']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label>نوع پیام
      <select name="encoding">
        <option value="">همه</option>
        <?php foreach (STATUS_REPORT_ENCODINGS as $key => $label): ?><option value="<?= e($key) ?>"<?= $f['encoding'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>

    <label>اپراتور
      <select name="operator">
        <option value="">همه‌ی اپراتورها</option>
        <?php foreach ($operators as $op): ?><option value="<?= e($op) ?>"<?= $f['operator'] === $op ? ' selected' : '' ?>><?= e($op === OPERATOR_REPORT_UNKNOWN ? 'نامشخص' : $op) ?></option><?php endforeach; ?>
      </select>
    </label>

    <label>سال
      <select name="year">
        <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $f['year'] === $y ? ' selected' : '' ?>><?= to_persian_digits((string)$y) ?></option><?php endforeach; ?>
      </select>
    </label>

    <label>ماه
      <span class="sr-inline">
        <select name="month">
          <option value="">همه‌ی ماه‌ها</option>
          <?php foreach (JALALI_MONTHS as $num => $label): ?><option value="<?= $num ?>"<?= $f['month'] === $num ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="icon-button" data-sr-clear="month" title="پاک کردن ماه" aria-label="پاک کردن ماه"><?= panel_icon('trash') ?></button>
      </span>
    </label>

    <div class="sr-field sr-date-field">
      <span class="sr-label">تاریخ شروع</span>
      <span class="sr-inline"><?= jalali_date_select('from', $f['from_picked'], 0, 6, true) ?>
        <button type="button" class="icon-button" data-sr-clear="from" title="پاک کردن تاریخ شروع" aria-label="پاک کردن تاریخ شروع"><?= panel_icon('trash') ?></button></span>
    </div>
    <div class="sr-field sr-date-field">
      <span class="sr-label">تاریخ پایان</span>
      <span class="sr-inline"><?= jalali_date_select('to', $f['to_picked'], 0, 6, true) ?>
        <button type="button" class="icon-button" data-sr-clear="to" title="پاک کردن تاریخ پایان" aria-label="پاک کردن تاریخ پایان"><?= panel_icon('trash') ?></button></span>
    </div>

    <label>تعداد بخش
      <input type="number" name="parts" min="1" max="255" step="1" inputmode="numeric" class="ltr" value="<?= $f['parts'] > 0 ? (int)$f['parts'] : '' ?>" placeholder="همه">
    </label>

    <label class="sr-wide">عامل ارسال پیام
      <select name="channel">
        <option value="">همه</option>
        <?php foreach (SEND_CHANNELS as $key => $label): ?><option value="<?= e($key) ?>"<?= $f['channel'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <?php if ($f['notice'] !== ''): ?><p class="flash flash-info"><?= e($f['notice']) ?></p><?php endif; ?>
  <div class="sr-actions">
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
    <a class="btn btn-ghost" href="/status-report.php">پاک کردن فیلترها</a>
    <span class="hint sr-range-hint">بازه: <?= e($rangeLabel) ?> — <?= $f['granularity'] === 'month' ? 'به تفکیک ماه' : 'به تفکیک روز' ?><?= $f['vip'] !== '' ? ' — ' . e(VIP_FILTERS[$f['vip']]) : '' ?></span>
  </div>
</form>

<?= lazy_part_placeholder('report', 460, 'در حال آماده‌سازی گزارش…', 'table') ?>
<script src="<?= e(asset_url('/assets/js/status-report.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
