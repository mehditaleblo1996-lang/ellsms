<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'صندوق دریافت';
$active = 'inbox';

$from = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to   = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
$sndr = trim($_GET['sender'] ?? '');
$text = trim($_GET['q'] ?? '');

$where  = ['received_at >= ?', 'received_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
if ($sndr !== '') { $where[] = 'originator LIKE ?'; $params[] = '%' . preg_replace('/\D/', '', $sndr) . '%'; }
if ($text !== '') { $where[] = 'content LIKE ?';    $params[] = '%' . $text . '%'; }

/*
 * Ownership scoping — fail closed. A non-admin may only see messages
 * that arrived on a line they're actually allowed to use
 * (allowed_originators(), app/authorization.php). Previously this only
 * checked `$me['originator'] !== ''` and applied NO filter at all when
 * that legacy field was empty (the normal state for any account set up
 * under the current ellsms_numbers model) — meaning such a user saw
 * every user's inbound messages system-wide. A user with zero allowed
 * originators now sees zero rows, never an unfiltered table.
 */
$hasSenderScope = true;
$allowedOriginators = allowed_originators($me);
if (!in_array('*', $allowedOriginators, true)) {
    if (!$allowedOriginators) {
        $hasSenderScope = false;
        $where[] = '1 = 0';
    } else {
        $placeholders = implode(',', array_fill(0, count($allowedOriginators), '?'));
        $where[] = "destination IN ({$placeholders})";
        array_push($params, ...$allowedOriginators);
    }
}
$W = implode(' AND ', $where);

if (isset($_GET['export'])) {
    // Phase 8 (Invariant C): inbound repository, not a direct inbound_message query — $W/$params
    // are still built entirely by this page (tenant scoping, date/sender/text filters); only the
    // actual SELECT execution moved to app/Backend/messages.php.
    $exportRows = backend_inbound_export_rows($W, $params, 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-inbox-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['شناسه','فرستنده','گیرنده','متن پیام','زمان دریافت']);
    foreach ($exportRows as $r) fputcsv($out, $r);
    exit;
}

$per = 50;
$cnt = backend_inbound_count($W, $params);
[$page, $offset] = pager_offset($cnt, $per);
$pages = pager_pages($cnt, $per);
$rows = backend_inbound_rows($W, $params, $per, $offset);

$qs = fn(array $extra = []) => http_build_query(array_merge($_GET, $extra));
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <label>فرستنده <input type="text" name="sender" value="<?= e($sndr) ?>" placeholder="۹۸۹۱…" class="ltr"></label>
    <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
    <button class="btn btn-primary">اعمال فیلتر</button>
    <a class="btn" href="/inbox.php?<?= e($qs(['export' => 1])) ?>">خروجی CSV</a>
  </form>

  <div class="table-wrap">
  <table data-cards>
    <tr><th>#</th><th>فرستنده</th><th>گیرنده (خط شما)</th><th>متن پیام</th><th>زمان دریافت</th></tr>
    <?php foreach ($rows as $m): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$m['id']) ?></td>
        <td class="msisdn"><?= e((string)$m['originator']) ?></td>
        <td class="msisdn"><?= e((string)$m['destination']) ?></td>
        <td><?= e($m['content']) ?></td>
        <td class="num"><?= jdate($m['received_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows && !$hasSenderScope): ?>
      <tr><td colspan="5" class="empty-cell"><?= panel_empty_state('هنوز هیچ خط ارسالی به شما تخصیص داده نشده', 'صندوق دریافت فقط پیامک‌های خطوط تخصیص‌یافته را نشان می‌دهد. از مدیر بخواهید یک شماره از صفحه‌ی «شماره‌ها» به شما تخصیص دهد.', null, 'phone') ?></td></tr>
    <?php elseif (!$rows): ?>
      <tr><td colspan="5" class="empty-cell"><?= panel_empty_state('در این بازه پیامک دریافتی‌ای وجود ندارد', '', null, 'inbox') ?>
        <p class="hint" style="text-align:center;margin:0"> پیامک‌های دریافتیِ خط‌های شما به‌طور خودکار (از طریق آدرس <code class="kbd">/mo</code>) اینجا نمایش داده می‌شوند — نیازی به تنظیم چیزی اینجا نیست.</p>
      </td></tr>
    <?php endif; ?>
  </table>
  </div>

  <?= pager_links($page, $pages, array_filter($_GET, static fn($v) => is_scalar($v) && $v !== ''), 'page', $cnt) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
