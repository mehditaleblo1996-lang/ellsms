<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'آمار تفصیلی';
$active = 'analytics';

$from = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to   = jalali_request_to_gregorian('to')   ?? date('Y-m-d');

const ANALYTICS_ROW_CAP = 300000;

/** Add one message's stats into a breakdown bucket keyed by $key. */
function analytics_bump(array &$buckets, string $key, string $status, int $parts): void {
    if (!isset($buckets[$key])) {
        $buckets[$key] = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'units' => 0];
    }
    $b = &$buckets[$key];
    $b['total']++;
    $b['units'] += $parts;
    if ($status === 'delivered') $b['delivered']++;
    elseif ($status === 'sent') $b['sent']++;
    elseif (in_array($status, ['failed', 'send_failed'], true)) $b['failed']++;
    else $b['pending']++;
}

$st = db()->prepare(
    "SELECT originator, sender_user_id, destination, content, status FROM outbound_message
     WHERE sent_at >= ? AND sent_at < DATE_ADD(?, INTERVAL 1 DAY)
     LIMIT " . (ANALYTICS_ROW_CAP + 1)
);
$st->execute([$from, $to]);
$rows = $st->fetchAll();
$truncated = count($rows) > ANALYTICS_ROW_CAP;
if ($truncated) $rows = array_slice($rows, 0, ANALYTICS_ROW_CAP);

$byNumber = $byUser = $byOperator = $overallBucket = [];

foreach ($rows as $r) {
    $parts = sms_parts($r['content']);
    analytics_bump($byNumber, $r['originator'] ?: '—', $r['status'], $parts);
    analytics_bump($byUser, (string)$r['sender_user_id'], $r['status'], $parts);
    analytics_bump($byOperator, detect_operator((string)$r['destination']), $r['status'], $parts);
    analytics_bump($overallBucket, '_', $r['status'], $parts);
}
$overall = $overallBucket['_'] ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'units' => 0];

// Resolve usernames for the per-user breakdown in one query instead of per-row.
$userIds = array_keys($byUser);
$usernames = [];
if ($userIds) {
    $in = implode(',', array_map('intval', $userIds));
    foreach (db()->query("SELECT id, username FROM user_ WHERE id IN ({$in})")->fetchAll() as $u) {
        $usernames[(string)$u['id']] = $u['username'];
    }
}

// Sort every breakdown by total messages, descending.
$sortByTotal = function (array &$a) { uasort($a, fn($x, $y) => $y['total'] <=> $x['total']); };
$sortByTotal($byNumber);
$sortByTotal($byUser);
$sortByTotal($byOperator);

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <button class="btn btn-primary">اعمال فیلتر</button>
  </form>
  <?php if ($truncated): ?>
    <div class="flash flash-info">حجم داده در این بازه زیاد است — فقط <?= to_persian_digits(number_format(ANALYTICS_ROW_CAP)) ?> پیام اول محاسبه شده‌اند. برای آمار دقیق‌تر، بازه‌ی تاریخ را کوتاه‌تر کنید.</div>
  <?php endif; ?>
</div>

<div class="grid grid-4">
  <div class="stat stat-accent"><div class="stat-label">کل پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($overall['total'])) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($overall['sent'] + $overall['delivered'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($overall['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">مجموع واحد پیامک</div><div class="stat-value"><?= to_persian_digits(number_format($overall['units'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
  <h2>بر اساس خط ارسال</h2>
  <div class="table-wrap">
  <table>
    <tr><th>خط</th><th>کل پیام</th><th>ارسال‌شده</th><th>تحویل‌شده</th><th>ناموفق</th><th>در انتظار</th><th>واحد پیامک</th></tr>
    <?php foreach ($byNumber as $key => $b): ?>
      <tr>
        <td class="msisdn"><?= e($key) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['sent'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['delivered'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['failed'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['pending'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['units'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$byNumber): ?><tr><td colspan="7" class="empty">داده‌ای در این بازه نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card">
  <h2>بر اساس کاربر</h2>
  <div class="table-wrap">
  <table>
    <tr><th>کاربر</th><th>کل پیام</th><th>ارسال‌شده</th><th>تحویل‌شده</th><th>ناموفق</th><th>در انتظار</th><th>واحد پیامک</th></tr>
    <?php foreach ($byUser as $key => $b): ?>
      <tr>
        <td><?= e($usernames[$key] ?? ('کاربر #' . $key)) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['sent'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['delivered'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['failed'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['pending'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['units'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$byUser): ?><tr><td colspan="7" class="empty">داده‌ای در این بازه نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card">
  <h2>بر اساس اپراتور</h2>
  <p class="hint">تشخیص اپراتور بر اساس پیش‌شماره‌ی موبایل و صرفاً حدسی است — بازه‌های احتمالی که رگولاتور بعداً تغییر داده باشد یا اپراتورهای کوچک‌تر، در «سایر / نامشخص» قرار می‌گیرند.</p>
  <div class="table-wrap">
  <table>
    <tr><th>اپراتور</th><th>کل پیام</th><th>ارسال‌شده</th><th>تحویل‌شده</th><th>ناموفق</th><th>در انتظار</th><th>واحد پیامک</th></tr>
    <?php foreach ($byOperator as $key => $b): ?>
      <tr>
        <td><?= e($key) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['sent'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['delivered'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['failed'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['pending'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['units'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$byOperator): ?><tr><td colspan="7" class="empty">داده‌ای در این بازه نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
