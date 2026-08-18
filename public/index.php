<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!current_user()) {
    require __DIR__ . '/landing.php';
    exit;
}
$me = require_login();
$pageTitle = 'داشبورد';
$active = 'dashboard';

$scope  = $me['role'] === 'admin' ? '' : ' AND sender_user_id = ' . (int)$me['id'];
$scopeW = $me['role'] === 'admin' ? '1=1' : 'sender_user_id = ' . (int)$me['id'];

$q = fn(string $sql) => (int)db()->query($sql)->fetch()['c'];

// Same canonical status resolution reports.php uses (report_canonical_status_totals()) — a message
// the poller has since confirmed delivered must count the same way here as it does on the report list.
$dashOrgId  = !is_admin() ? (int)($me['organization_id'] ?? 0) ?: null : null;
$dashUserId = !is_admin() && !$dashOrgId ? (int)$me['id'] : null;

$todayTotals = report_canonical_status_totals("DATE(sent_at)=CURDATE() AND {$scopeW}", [], $dashOrgId, $dashUserId);
$todaySent   = $todayTotals['ok'];
$todayFailed = $todayTotals['failed'];
$totalTotals = report_canonical_status_totals($scopeW, [], $dashOrgId, $dashUserId);
$totalSent   = $totalTotals['ok'];
$pendingSch  = $q("SELECT COUNT(*) c FROM ellsms_schedule WHERE status='active'" . ($me['role'] === 'admin' ? '' : ' AND user_id = ' . (int)$me['id']));
$inboxToday  = $me['role'] === 'admin' ? backend_inbound_today_count() : null;

/* Last 7 days volume */
$days = [];
for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-{$i} day"))] = 0;
foreach (backend_outbound_daily_counts("sent_at >= CURDATE() - INTERVAL 6 DAY AND {$scopeW}") as $d => $c) {
    $days[$d] = $c;
}
$max = max(1, max($days));

$weekdayShort = ['شنبه'=>'ش','یک‌شنبه'=>'ی','دوشنبه'=>'د','سه‌شنبه'=>'س','چهارشنبه'=>'چ','پنج‌شنبه'=>'پ','جمعه'=>'ج'];

/* Recent messages */
$recent = backend_outbound_rows($scopeW, [], 8);
$recentDeliveryByDest = report_delivery_lookup_by_destination($recent, $dashOrgId, $dashUserId);

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat stat-accent"><div class="stat-label">ارسال امروز</div><div class="stat-value"><?= to_persian_digits(number_format($todaySent)) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق امروز</div><div class="stat-value"><?= to_persian_digits(number_format($todayFailed)) ?></div></div>
  <div class="stat"><div class="stat-label">در صف زمان‌بندی</div><div class="stat-value"><?= to_persian_digits(number_format($pendingSch)) ?></div></div>
  <?php if ($inboxToday !== null): ?>
    <div class="stat"><div class="stat-label">دریافتی امروز</div><div class="stat-value"><?= to_persian_digits(number_format($inboxToday)) ?></div></div>
  <?php else: ?>
    <div class="stat"><div class="stat-label">مجموع ارسال‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($totalSent)) ?></div></div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:22px">
  <h2>پیامک‌های ۷ روز اخیر</h2>
  <div class="bars">
    <?php foreach ($days as $d => $c):
        $ts = strtotime($d);
        $wd = (int)date('w', $ts); // 0=Sun..6=Sat (PHP)
        $faWeekday = JALALI_WEEKDAYS[($wd + 1) % 7];
    ?>
      <div class="bar">
        <div class="bar-v"><?= to_persian_digits((string)$c) ?></div>
        <div class="bar-fill" style="height:<?= (int)round($c / $max * 110) ?>px"></div>
        <div class="bar-x"><?= e($weekdayShort[$faWeekday] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>آخرین پیامک‌ها <a class="btn btn-sm btn-ghost" style="float:left" href="/reports.php">مشاهده‌ی گزارش کامل ←</a></h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?><th>گیرنده</th><th>متن پیام</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($recent as $m):
      $d = $recentDeliveryByDest[(string)$m['destination']] ?? null;
      $canonical = report_canonical_status($d['delivery_status'] ?? null, (string)$m['status']);
    ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$m['id']) ?></td>
        <?php if (is_admin()): ?><td><?= e($m['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['destination']) ?></td>
        <td class="msg-preview" title="<?= e($m['content']) ?>"><?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?></td>
        <td><span class="badge badge-<?= e($canonical['class']) ?>"><?= e($canonical['label']) ?></span></td>
        <td class="num"><?= jdate($m['sent_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="empty">هنوز پیامکی ارسال نشده — از <a href="/send.php">ارسال پیامک</a> شروع کنید.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
