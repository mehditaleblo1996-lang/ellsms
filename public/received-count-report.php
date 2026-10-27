<?php
/**
 * «گزارش تعداد پیام‌های دریافتی» (admins): how many messages reached each customer's lines in the
 * picked days, one row per customer; lines nobody owns are summed in a row of their own. Data lives
 * in app/Reports/CustomerMessageCounts.php; sorting, paging and search over the rows run in the
 * browser (assets/js/count-reports.js).
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/CustomerMessageCounts.php';
require_once __DIR__ . '/../app/views/panel-icons.php'; // the lazily loaded part's empty state runs before header.php
$me = require_admin();
$pageTitle = 'گزارش تعداد پیام‌های دریافتی';
$active = 'received_count_report';

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$from = jalali_request_to_gregorian('from') ?? $today;
$to   = jalali_request_to_gregorian('to')   ?? $today;
if ($from > $to) [$from, $to] = [$to, $from];
$dateQuery = customer_counts_date_query($from, $to);

/** @return array{rows:list<array>,unowned:array{received:int,lines:array<string,int>},total:int} */
$load = static function () use ($from, $to): array {
    $report = customer_counts_received($from, $to);
    $who = customer_counts_identities(array_column($report['rows'], 'user_id'));
    $total = $report['unowned']['received'];
    foreach ($report['rows'] as &$r) {
        $r += $who[$r['user_id']];
        $total += $r['received'];
    }
    unset($r);
    return $report + ['total' => $total];
};

if (isset($_GET['export'])) {
    $report = $load();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-received-counts-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ردیف', 'شناسه کاربر', 'نام کاربری', 'نام و نام خانوادگی', 'نام شرکت', 'خط‌های دریافت', 'تعداد پیام‌های دریافتی']);
    foreach ($report['rows'] as $i => $r) {
        fputcsv($out, [$i + 1, $r['user_id'], $r['username'], $r['name'], $r['company'], implode(' ', array_keys($r['lines'])), $r['received']]);
    }
    if ($report['unowned']['received'] > 0) {
        fputcsv($out, ['', '', '', 'خط‌های بدون مشتری', '', implode(' ', array_keys($report['unowned']['lines'])), $report['unowned']['received']]);
    }
    fputcsv($out, ['', '', '', 'جمع کل', '', '', $report['total']]);
    exit;
}

if (lazy_part_begin('report')) {
    $report = $load();
    $rows = $report['rows'];
    $unowned = $report['unowned'];
    $lineCount = count($unowned['lines']) + array_sum(array_map(static fn(array $r): int => count($r['lines']), $rows));
    $asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('H:i');
    // A customer's lines as chips, busiest first; past the first few they fold into «+n».
    $lineChips = static function (array $lines): string {
        $html = '';
        $shown = 0;
        foreach ($lines as $line => $n) {
            if ($shown++ === 3) break;
            $html .= '<span class="cc-line"><span class="ltr">' . e((string)$line) . '</span> <small>' . operator_report_num((int)$n) . '</small></span>';
        }
        if (count($lines) > 3) {
            $rest = array_slice($lines, 3, null, true);
            $title = implode('، ', array_map(static fn($l, $n): string => $l . ': ' . number_format((int)$n), array_keys($rest), $rest));
            $html .= '<span class="cc-line cc-line-more" title="' . e($title) . '">+' . to_persian_digits((string)count($rest)) . '</span>';
        }
        return $html;
    };
    ?>
<div class="report-stats cc-stats">
  <div class="stat stat-accent">
    <div class="stat-label">گزارش سریع</div>
    <div class="stat-value cc-range"><?= $from === $to ? jdate($from, false) : jdate($from, false) . ' تا ' . jdate($to, false) ?></div>
    <div class="stat-meta">تا ساعت <span class="num"><?= to_persian_digits($asOf) ?></span></div>
  </div>
  <div class="stat"><div class="stat-label">مجموع پیام‌های دریافتی</div><div class="stat-value"><?= operator_report_num($report['total']) ?></div></div>
  <div class="stat"><div class="stat-label">مشتریان دارای پیام دریافتی</div><div class="stat-value"><?= operator_report_num(count($rows)) ?></div></div>
  <div class="stat"><div class="stat-label">خط‌های دریافت‌کننده</div><div class="stat-value"><?= operator_report_num($lineCount) ?></div>
    <?php if ($unowned['received'] > 0): ?><div class="stat-meta"><?= operator_report_num(count($unowned['lines'])) ?> خط بدون مشتری</div><?php endif; ?></div>
</div>

<div class="card cc-card">
  <div class="table-wrap"><table class="report-table cc-table" data-count-table data-cards>
    <thead><tr>
      <th data-sort="num" aria-sort="none">ردیف</th>
      <th data-sort="text" aria-sort="none">نام و نام خانوادگی</th>
      <th data-sort="text" aria-sort="none">نام شرکت</th>
      <th>خط‌های دریافت</th>
      <th data-sort="num" aria-sort="descending">تعداد پیام‌های دریافتی</th>
      <th><span class="sr-only">پیام‌ها</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr data-user="<?= (int)$r['user_id'] ?>" data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['username'] . ' ' . $r['company'] . ' ' . implode(' ', array_keys($r['lines'])))) ?>">
        <td class="num" data-v="<?= $i + 1 ?>"><?= to_persian_digits((string)($i + 1)) ?></td>
        <?= customer_counts_name_cell($r) ?>
        <td data-v="<?= e($r['company']) ?>"><?= $r['company'] !== '' ? e($r['company']) : '<span class="muted">—</span>' ?></td>
        <td class="cc-lines"><?= $lineChips($r['lines']) ?></td>
        <td class="num cc-count" data-v="<?= (int)$r['received'] ?>"><?= operator_report_num($r['received']) ?></td>
        <td><a class="icon-button report-eye" href="/inbox.php?<?= e(http_build_query(['user_id' => $r['user_id']] + $dateQuery)) ?>" title="پیام‌های دریافتی این مشتری" aria-label="پیام‌های دریافتی <?= e($r['name']) ?>"><?= panel_icon('eye') ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows && $unowned['received'] === 0): ?>
      <tr><td colspan="6" class="empty-cell"><?= panel_empty_state('در این بازه پیامی دریافت نشده', 'بازه‌ی تاریخ را بزرگ‌تر کنید تا پیام‌های دریافتی مشتریان را ببینید.', null, 'inbox') ?></td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($rows || $unowned['received'] > 0): ?>
    <tfoot>
      <?php if ($unowned['received'] > 0): ?>
      <tr class="cc-unowned">
        <td></td>
        <td>خط‌های بدون مشتری</td>
        <td><span class="muted">—</span></td>
        <td class="cc-lines"><?= $lineChips($unowned['lines']) ?></td>
        <td class="num cc-count"><?= operator_report_num($unowned['received']) ?></td>
        <td><a class="icon-button report-eye" href="/inbox.php?<?= e(http_build_query($dateQuery)) ?>" title="همه‌ی پیام‌های دریافتی" aria-label="همه‌ی پیام‌های دریافتی"><?= panel_icon('eye') ?></a></td>
      </tr>
      <?php endif; ?>
      <tr class="cc-total"><td></td><td>جمع کل</td><td></td><td></td><td class="num cc-count"><?= operator_report_num($report['total']) ?></td><td></td></tr>
    </tfoot>
    <?php endif; ?>
  </table></div>
  <p class="hint cc-note">هر خط به مشتری‌ای نسبت داده می‌شود که شماره به او تخصیص داده شده (همان صندوق دریافت)؛ خط‌هایی که به کسی تخصیص ندارند در ردیف «خط‌های بدون مشتری» جمع شده‌اند.</p>
</div>
<?php
    exit;
}

$extraStyles = ['/assets/css/reports.css', '/assets/css/count-reports.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="report-head">
  <p class="hint">تعداد پیامک‌هایی که در بازه‌ی انتخابی به خط‌های هر مشتری رسیده است؛ برای دیدن خود پیام‌ها روی آیکن چشم هر ردیف بزنید.</p>
</div>

<form method="get" class="card report-filters" aria-label="بازه‌ی گزارش">
  <div class="report-filter-grid">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
  </div>
  <div class="report-actions">
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
    <a class="btn" href="/received-count-report.php?<?= e(http_build_query($dateQuery + ['export' => 1])) ?>"><?= panel_icon('download') ?> دانلود</a>
  </div>
</form>

<?= lazy_part_placeholder('report', 420, 'در حال آماده‌سازی گزارش…', 'table') ?>
<script src="<?= e(asset_url('/assets/js/count-reports.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
