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
    $st = backend_inbound_export_rows($W, $params, 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-inbox-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['شناسه','فرستنده','گیرنده','متن پیام','زمان دریافت']);
    while ($r = $st->fetch()) fputcsv($out, $r);
    exit;
}

$per = 50; $page = max(1, (int)($_GET['page'] ?? 1));
$cnt = backend_inbound_count($W, $params);
$pages = max(1, (int)ceil($cnt / $per));
$rows = backend_inbound_rows($W, $params, $per, ($page - 1) * $per);

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
  <table>
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
      <tr><td colspan="5" class="empty">
        هنوز هیچ خط ارسالی به شما تخصیص داده نشده — صندوق دریافت فقط پیامک‌های خطوط تخصیص‌یافته را نشان می‌دهد. از مدیر بخواهید یک شماره از صفحه‌ی «شماره‌ها» به شما تخصیص دهد.</td></tr>
    <?php elseif (!$rows): ?>
      <tr><td colspan="5" class="empty">
        در این بازه‌ی زمانی پیامک دریافتی‌ای وجود ندارد. پیامک‌های دریافتی به‌طور خودکار از طریق اندپوینت <code class="kbd">/mo</code> سامانه‌ی مرکزی مستقیماً وارد پایگاه‌داده‌ی مشترک می‌شوند — نیازی به تنظیم چیزی اینجا نیست.
      </td></tr>
    <?php endif; ?>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page - 1])) ?>">→ قبلی</a><?php endif; ?>
    <span class="btn btn-sm btn-ghost">صفحه <?= to_persian_digits((string)$page) ?> از <?= to_persian_digits((string)$pages) ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page + 1])) ?>">بعدی ←</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
