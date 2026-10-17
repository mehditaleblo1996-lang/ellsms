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

// The picked days are Tehran days; every send timestamp below is UTC.
[$fromUtc, $toUtc] = local_day_range_to_utc($from, $to);

/*
 * Sends live in three stores, and this page must read all of them (the same sources as app/Dashboard.php
 * and the send report): bulk recipients (ellsms_bulk_items, by claimed_at), gateway direct/scheduled/
 * auto-reply sends (ellsms_message_attempts) and the legacy backend's outbound_message. With
 * SMS_GATEWAY_TRANSPORT=1 nothing reaches outbound_message any more, so reading it alone left every
 * breakdown empty while the cost table (price snapshots) still showed the sends.
 * Every query yields originator, sender_user_id, destination, content and a status analytics_bump()
 * understands: sent / delivered / failed / anything else = pending.
 */
$undelivered = "'failed','rejected','expired'";
$attemptColumns = backend_message_attempts_have_report_columns() ? 'a.originator, a.content' : "'' originator, '' content";
$sources = [
    ["SELECT j.originator, j.user_id sender_user_id, i.mobile destination, i.content,
             CASE WHEN i.status = 'failed' THEN 'failed'
                  WHEN i.status <> 'sent' THEN 'pending'
                  WHEN i.delivery_status = 'delivered' THEN 'delivered'
                  WHEN i.delivery_status IN ({$undelivered}) THEN 'failed'
                  ELSE 'sent' END status
      FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
      WHERE i.claimed_at >= ? AND i.claimed_at < ? AND i.status <> 'cancelled'", [$fromUtc, $toUtc]],
    ["SELECT {$attemptColumns}, a.user_id sender_user_id, a.destination,
             CASE WHEN a.status = 'failed' THEN 'failed'
                  WHEN a.delivery_status = 'delivered' THEN 'delivered'
                  WHEN a.delivery_status IN ({$undelivered}) THEN 'failed'
                  ELSE 'sent' END status
      FROM ellsms_message_attempts a
      WHERE a.status IN ('accepted', 'failed') AND a.attempted_at >= ? AND a.attempted_at < ?
        AND a.reference_type IN ('direct_send', 'schedule', 'autoreply')", [$fromUtc, $toUtc]],
];

$byNumber = $byUser = $byOperator = $overallBucket = [];
$scanned = 0;
$truncated = false;

/** Fold one send into every breakdown; false once ANALYTICS_ROW_CAP rows have been counted. */
$take = function (array $r) use (&$byNumber, &$byUser, &$byOperator, &$overallBucket, &$scanned, &$truncated): bool {
    if ($scanned >= ANALYTICS_ROW_CAP) { $truncated = true; return false; }
    $scanned++;
    $parts = sms_parts((string)$r['content']);
    analytics_bump($byNumber, $r['originator'] ?: '—', $r['status'], $parts);
    analytics_bump($byUser, (string)$r['sender_user_id'], $r['status'], $parts);
    analytics_bump($byOperator, detect_operator((string)$r['destination']), $r['status'], $parts);
    analytics_bump($overallBucket, '_', $r['status'], $parts);
    return true;
};

foreach ($sources as [$sql, $params]) {
    if ($truncated) break;
    $st = db()->prepare($sql . ' LIMIT ' . (ANALYTICS_ROW_CAP - $scanned + 1));
    $st->execute($params);
    while (($r = $st->fetch()) && $take($r));
}
// outbound_message is backend-owned and read only through its adapter (cron/backend-boundary-check.php).
if (!$truncated) {
    foreach (backend_outbound_scan('sent_at >= ? AND sent_at < ?', [$fromUtc, $toUtc], ANALYTICS_ROW_CAP - $scanned) as $r) {
        if (!$take($r)) break;
    }
}
$overall = $overallBucket['_'] ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'units' => 0];

// Resolve usernames for the per-user breakdown in one query instead of per-row.
$userIds = array_keys($byUser);
$usernames = [];
foreach (backend_usernames_by_ids(array_map('intval', $userIds)) as $id => $username) {
    $usernames[(string)$id] = $username;
}

// Historical COST comes from the immutable price snapshots written at send acceptance
// (ellsms_sms_price_snapshots), NEVER recomputed from the current tariff tables — that is the whole
// point of the snapshot: an admin raising a rate today must not retroactively change what last
// month's sends cost (STEP 45 / Invariant G). `committed` is what was actually settled once the
// gateway answered; `accepted` is what was reserved at acceptance, and the two differ exactly by
// what never sent.
$costByProvider = [];
$costTotals = ['accepted' => 0, 'committed' => 0];
try {
    $costSt = db()->prepare(
        "SELECT provider_code, route_code, operator_code, message_type,
                SUM(recipient_count) AS recipients, SUM(segment_count) AS segments,
                SUM(total_cost_credits) AS accepted, SUM(committed_cost_credits) AS committed,
                MIN(unit_price_millicredits) AS unit_min, MAX(unit_price_millicredits) AS unit_max
         FROM ellsms_sms_price_snapshots
         WHERE priced_at >= ? AND priced_at < ?
         GROUP BY provider_code, route_code, operator_code, message_type
         ORDER BY committed DESC"
    );
    $costSt->execute([$fromUtc, $toUtc]);
    $costByProvider = $costSt->fetchAll();
    foreach ($costByProvider as $row) {
        $costTotals['accepted']  += (int)$row['accepted'];
        $costTotals['committed'] += (int)$row['committed'];
    }
} catch (Throwable $t) {
    // Pricing tables not migrated yet — the rest of this page is unaffected.
    Logger::info('analytics.price_snapshots_unavailable', ['exception' => $t]);
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
        <td><?= operator_label($key, $key) ?></td>
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
<div class="card">
  <h2>هزینه‌ی واقعی بر اساس ارائه‌دهنده و مسیر</h2>
  <p class="hint">
    این ارقام از «عکس لحظه‌ای تعرفه» که هنگام پذیرش هر ارسال ثبت شده خوانده می‌شوند، نه از تعرفه‌ی امروز —
    بنابراین تغییر تعرفه توسط مدیر، هزینه‌ی ارسال‌های گذشته را تغییر نمی‌دهد.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>ارائه‌دهنده</th><th>مسیر</th><th>اپراتور</th><th>نوع پیام</th><th>گیرندگان</th><th>بخش</th><th>هر بخش (ریال)</th><th>پذیرفته‌شده (ریال)</th><th>کسرشده (ریال)</th></tr>
    <?php foreach ($costByProvider as $c): ?>
      <tr>
        <td class="ltr"><?= e((string)$c['provider_code']) ?></td>
        <td class="ltr"><?= e((string)$c['route_code']) ?></td>
        <td><?= operator_label((string)$c['operator_code']) ?></td>
        <td class="ltr"><?= e((string)$c['message_type']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['recipients'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['segments'])) ?></td>
        <td class="num"><?php
          $min = sms_pricing_millicredits_to_credits((int)$c['unit_min']);
          $max = sms_pricing_millicredits_to_credits((int)$c['unit_max']);
          echo to_persian_digits($min === $max ? (string)$min : $min . ' – ' . $max);
        ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['accepted'])) ?></td>
        <td class="num"><strong><?= to_persian_digits(number_format((int)$c['committed'])) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$costByProvider): ?><tr><td colspan="9" class="empty">در این بازه هزینه‌ای ثبت نشده است.</td></tr><?php endif; ?>
    <?php if ($costByProvider): ?>
      <tr><th colspan="7">مجموع</th>
        <td class="num"><?= to_persian_digits(number_format($costTotals['accepted'])) ?></td>
        <td class="num"><strong><?= to_persian_digits(number_format($costTotals['committed'])) ?></strong></td>
      </tr>
    <?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
