<?php
/**
 * «گزارش تعداد پیام‌های ارسالی» (admins): per customer, in the picked days, how many interactive
 * messages it sent and how many bulk messages (regional / aimed) it requested and had sent. Data and
 * the definitions of «تعاملی» and «بالک» live in app/Reports/CustomerMessageCounts.php; sorting,
 * paging and search over the rows run in the browser (assets/js/count-reports.js).
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/CustomerMessageCounts.php';
require_once __DIR__ . '/../app/views/panel-icons.php'; // the lazily loaded part's empty state runs before header.php
$me = require_admin();
$pageTitle = 'گزارش تعداد پیام‌های ارسالی';
$active = 'sent_count_report';

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$from = jalali_request_to_gregorian('from') ?? $today;
$to   = jalali_request_to_gregorian('to')   ?? $today;
if ($from > $to) [$from, $to] = [$to, $from];
$dateQuery = customer_counts_date_query($from, $to);

/** @return array{rows:list<array>,totals:array<string,int>,as_of:?string} — every count with its SMS parts (`*_parts`) */
$load = static function () use ($from, $to): array {
    $rows = customer_counts_sent($from, $to, $asOf);
    $who = customer_counts_identities(array_column($rows, 'user_id'));
    foreach ($rows as &$r) $r += $who[$r['user_id']];
    unset($r);
    return ['rows' => $rows, 'totals' => customer_counts_sent_totals($rows), 'as_of' => $asOf];
};

if (isset($_GET['export'])) {
    $report = $load();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-sent-counts-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ردیف', 'شناسه کاربر', 'نام کاربری', 'نام و نام خانوادگی', 'نام شرکت',
        'تعداد پیام‌های تعاملی', 'تعداد پارت تعاملی', 'تعداد پیام‌های بالک درخواستی', 'تعداد پارت بالک درخواستی',
        'تعداد پیام‌های بالک ارسال‌شده', 'تعداد پارت بالک ارسال‌شده']);
    $values = static fn(array $r): array => array_map(static fn(string $k): int => (int)$r[$k], CUSTOMER_COUNTS_SENT_COLUMNS);
    foreach ($report['rows'] as $i => $r) {
        fputcsv($out, [$i + 1, $r['user_id'], $r['username'], $r['name'], $r['company'], ...$values($r)]);
    }
    fputcsv($out, ['', '', '', 'جمع کل', '', ...$values($report['totals'])]);
    exit;
}

if (lazy_part_begin('report')) {
    $report = $load();
    $rows = $report['rows'];
    $t = $report['totals'];
    // «تا ساعت»: when the summary the counts come from was last refreshed, else now (counted live).
    $asOf = $report['as_of'] !== null
        ? (new DateTimeImmutable($report['as_of'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'))
        : new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    ?>
<div class="report-stats cc-stats">
  <div class="stat stat-accent">
    <div class="stat-label">گزارش سریع</div>
    <div class="stat-value cc-range"><?= $from === $to ? jdate($from, false) : jdate($from, false) . ' تا ' . jdate($to, false) ?></div>
    <div class="stat-meta">تا ساعت <span class="num"><?= to_persian_digits($asOf->format('H:i')) ?></span><?= $asOf->format('Y-m-d') !== $to && $report['as_of'] !== null ? ' — ' . jdate($asOf->format('Y-m-d'), false) : '' ?></div>
  </div>
  <div class="stat"><div class="stat-label">مجموع پیام‌های تعاملی</div><div class="stat-value"><?= operator_report_num($t['interactive']) ?></div>
    <div class="stat-meta"><?= operator_report_num($t['interactive_parts']) ?> پارت</div></div>
  <div class="stat"><div class="stat-label">مجموع پیام‌های بالک درخواستی</div><div class="stat-value"><?= operator_report_num($t['bulk_requested']) ?></div>
    <div class="stat-meta"><?= operator_report_num($t['bulk_requested_parts']) ?> پارت</div></div>
  <div class="stat"><div class="stat-label">مجموع پیام‌های بالک ارسال‌شده</div><div class="stat-value"><?= operator_report_num($t['bulk_sent']) ?></div>
    <div class="stat-meta"><?= operator_report_num($t['bulk_sent_parts']) ?> پارت<?php if ($t['bulk_requested'] > 0): ?> · <?= to_persian_digits((string)operator_report_percent($t['bulk_sent'], $t['bulk_requested'])) ?>٪ از درخواستی<?php endif; ?></div></div>
</div>

<div class="card cc-card">
  <div class="table-wrap"><table class="report-table cc-table" data-count-table data-cards>
    <thead><tr>
      <th data-sort="num" aria-sort="none">ردیف</th>
      <th data-sort="text" aria-sort="none">نام و نام خانوادگی</th>
      <th data-sort="text" aria-sort="none">نام شرکت</th>
      <th data-sort="num" aria-sort="descending">تعداد پیام‌های تعاملی</th>
      <th data-sort="num" aria-sort="none">تعداد پارت تعاملی</th>
      <th data-sort="num" aria-sort="none">تعداد پیام‌های بالک درخواستی</th>
      <th data-sort="num" aria-sort="none">تعداد پارت بالک درخواستی</th>
      <th data-sort="num" aria-sort="none">تعداد پیام‌های بالک ارسال‌شده</th>
      <th data-sort="num" aria-sort="none">تعداد پارت بالک ارسال‌شده</th>
      <th><span class="sr-only">پیام‌ها</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr data-user="<?= (int)$r['user_id'] ?>" data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['username'] . ' ' . $r['company'] . ' ' . $r['user_id'])) ?>">
        <td class="num" data-v="<?= $i + 1 ?>"><?= to_persian_digits((string)($i + 1)) ?></td>
        <?= customer_counts_name_cell($r) ?>
        <td data-v="<?= e($r['company']) ?>"><?= $r['company'] !== '' ? e($r['company']) : '<span class="muted">—</span>' ?></td>
        <?php foreach (CUSTOMER_COUNTS_SENT_COLUMNS as $k): ?>
        <td class="num<?= $k === 'interactive' ? ' cc-count' : '' ?><?= str_ends_with($k, '_parts') ? ' cc-parts' : '' ?>" data-v="<?= (int)$r[$k] ?>"><?= operator_report_num($r[$k]) ?></td>
        <?php endforeach; ?>
        <td><a class="icon-button report-eye" href="/reports.php?<?= e(http_build_query(['user_id' => $r['user_id']] + $dateQuery)) ?>" title="پیام‌های ارسالی این مشتری" aria-label="پیام‌های ارسالی <?= e($r['name']) ?>"><?= panel_icon('eye') ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="empty-cell"><?= panel_empty_state('در این بازه پیامی ارسال نشده', 'بازه‌ی تاریخ را بزرگ‌تر کنید تا ارسال‌های مشتریان را ببینید.', null, 'send') ?></td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot>
      <tr class="cc-total"><td></td><td>جمع کل</td><td></td>
        <?php foreach (CUSTOMER_COUNTS_SENT_COLUMNS as $k): ?>
        <td class="num<?= $k === 'interactive' ? ' cc-count' : '' ?><?= str_ends_with($k, '_parts') ? ' cc-parts' : '' ?>"><?= operator_report_num($t[$k]) ?></td>
        <?php endforeach; ?><td></td></tr>
    </tfoot>
    <?php endif; ?>
  </table></div>
  <p class="hint cc-note">«تعاملی»: همه‌ی پیام‌هایی که مشتری از پنل، وب‌سرویس، ارسال زمان‌بندی‌شده و ارسال‌های فایلی (نظیربه‌نظیر، هوشمند، تدریجی) به اپراتور فرستاده؛ پیام‌های در صف و لغوشده حساب نشده‌اند (همان «مجموع ارسال‌شده»ی گزارش اپراتورها). «بالک»: ارسال منطقه‌ای و ارسال با کد پستی — «درخواستی» تعداد شماره‌های درخواست تأییدشده و «ارسال‌شده» تعداد پیام‌هایی که از آن به اپراتور رفته؛ هر درخواست در روز تأییدش حساب می‌شود. ستون «تعداد پارت» کنار هر ستون، تعداد پارت پیامک‌های همان ستون است (پیام فارسی تا ۷۰ نویسه و لاتین تا ۱۶۰ نویسه یک پارت).</p>
</div>
<?php
    exit;
}

$extraStyles = ['/assets/css/reports.css', '/assets/css/count-reports.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="report-head">
  <p class="hint">تعداد پیام‌های تعاملی و بالکی که هر مشتری در بازه‌ی انتخابی فرستاده است؛ آمار ارسال‌های تعاملی هر چند دقیقه به‌روز می‌شود.</p>
</div>

<form method="get" class="card report-filters" aria-label="بازه‌ی گزارش">
  <div class="report-filter-grid">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
  </div>
  <div class="report-actions">
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
    <a class="btn" href="/sent-count-report.php?<?= e(http_build_query($dateQuery + ['export' => 1])) ?>"><?= panel_icon('download') ?> دانلود</a>
  </div>
</form>

<?= lazy_part_placeholder('report', 420, 'در حال آماده‌سازی گزارش…', 'table') ?>
<script src="<?= e(asset_url('/assets/js/count-reports.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
