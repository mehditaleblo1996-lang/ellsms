<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'آمار تفصیلی';
$active = 'analytics';

$from = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to   = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
// The picked days are Tehran days; every send timestamp below is UTC.
[$fromUtc, $toUtc] = local_day_range_to_utc($from, $to);

/** Add $n messages ($units SMS parts) with $status into a breakdown bucket keyed by $key. */
function analytics_add(array &$buckets, string $key, string $status, int $n, int $units): void {
    if (!isset($buckets[$key])) {
        $buckets[$key] = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'units' => 0];
    }
    $b = &$buckets[$key];
    $b['total'] += $n;
    $b['units'] += $units;
    if ($status === 'delivered') $b['delivered'] += $n;
    elseif ($status === 'sent') $b['sent'] += $n;
    elseif (in_array($status, ['failed', 'send_failed'], true)) $b['failed'] += $n;
    else $b['pending'] += $n;
}

/** One breakdown table (by line / user / operator); $label renders a row's key cell, or the header for null. */
function analytics_table(array $buckets, callable $label): void { ?>
  <div class="table-wrap">
  <table>
    <tr><th><?= e($label(null)) ?></th><th>کل پیام</th><th>ارسال‌شده</th><th>تحویل‌شده</th><th>ناموفق</th><th>در انتظار</th><th>واحد پیامک</th></tr>
    <?php foreach ($buckets as $key => $b): ?>
      <tr>
        <td><?= $label((string)$key) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['sent'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['delivered'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['failed'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['pending'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($b['units'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$buckets): ?><tr><td colspan="7" class="empty">داده‌ای در این بازه نیست.</td></tr><?php endif; ?>
  </table>
  </div>
<?php }

/*
 * Traffic: message counts and SMS parts by sender line, user and destination operator.
 *
 * Sends live in three stores, all read here (the same sources as app/Dashboard.php and the send
 * report): bulk recipients (ellsms_bulk_items, by claimed_at), gateway direct/scheduled/auto-reply
 * sends (ellsms_message_attempts) and the legacy backend's outbound_message. Each is aggregated in
 * SQL -- one grouped scan, never the messages themselves in PHP -- by sender line, user, destination
 * prefix and status. Parts are counted by sms_parts_sql(), the SQL twin of sms_parts().
 *
 * The operator is resolved per destination PREFIX instead of per number: operator detection is pure
 * prefix matching (sms_pricing_match_prefix()), so the first N characters, N = the longest
 * configured prefix, give exactly the operator the full number would. The legacy fallback map also
 * needs to know the whole number is a 98 9xx xxx xxxx mobile, which full_mobile carries.
 *
 * The status alias is msg_status, not status: MySQL resolves a GROUP BY name against the tables'
 * columns before the select list, so "status" would group by the raw column.
 */
if (lazy_part_begin('traffic')) {
    $prefixLen = 5;
    $hasPrefixRules = false;
    try {
        foreach (sms_pricing_prefix_rules() as $rule) {
            $prefixLen = max($prefixLen, strlen((string)$rule['normalized_prefix']));
            $hasPrefixRules = true;
        }
    } catch (Throwable $t) {
        // Pricing tables not migrated yet: detect_operator() falls back to its built-in map.
    }

    $undelivered = "'failed','rejected','expired'";
    // Rows written before the report columns existed have no sender line or text (0 parts).
    $hasReportColumns = backend_message_attempts_have_report_columns();
    $attemptOriginator = $hasReportColumns ? 'a.originator' : "''";
    $attemptContent = $hasReportColumns ? 'a.content' : "''";
    $sources = [
        ["SELECT j.originator, j.user_id sender_user_id, LEFT(i.mobile, {$prefixLen}) prefix,
                 (i.mobile REGEXP '^989[0-9]{9}$') full_mobile,
                 CASE WHEN i.status = 'failed' THEN 'failed'
                      WHEN i.status <> 'sent' THEN 'pending'
                      WHEN i.delivery_status = 'delivered' THEN 'delivered'
                      WHEN i.delivery_status IN ({$undelivered}) THEN 'failed'
                      ELSE 'sent' END msg_status,
                 COUNT(*) n, SUM(" . sms_parts_sql('i.content') . ") units
          FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
          WHERE i.claimed_at >= ? AND i.claimed_at < ? AND i.status <> 'cancelled'
          GROUP BY j.originator, j.user_id, prefix, full_mobile, msg_status", [$fromUtc, $toUtc]],
        ["SELECT {$attemptOriginator} originator, a.user_id sender_user_id, LEFT(a.destination, {$prefixLen}) prefix,
                 (a.destination REGEXP '^989[0-9]{9}$') full_mobile,
                 CASE WHEN a.status = 'failed' THEN 'failed'
                      WHEN a.delivery_status = 'delivered' THEN 'delivered'
                      WHEN a.delivery_status IN ({$undelivered}) THEN 'failed'
                      ELSE 'sent' END msg_status,
                 COUNT(*) n, SUM(" . sms_parts_sql($attemptContent) . ") units
          FROM ellsms_message_attempts a
          WHERE a.status IN ('accepted', 'failed') AND a.attempted_at >= ? AND a.attempted_at < ?
            AND a.reference_type IN ('direct_send', 'schedule', 'autoreply')
          GROUP BY originator, a.user_id, prefix, full_mobile, msg_status", [$fromUtc, $toUtc]],
    ];

    $groups = [];
    foreach ($sources as [$sql, $params]) {
        $st = db()->prepare($sql);
        $st->execute($params);
        array_push($groups, ...$st->fetchAll());
    }
    // outbound_message is backend-owned and read only through its adapter (cron/backend-boundary-check.php).
    array_push($groups, ...backend_outbound_analytics_groups('sent_at >= ? AND sent_at < ?', [$fromUtc, $toUtc], $prefixLen));

    $byNumber = $byUser = $byOperator = $overallBucket = [];
    $operatorOf = [];
    foreach ($groups as $g) {
        $n = (int)$g['n'];
        $units = (int)$g['units'];
        $status = (string)$g['msg_status'];
        $prefix = (string)$g['prefix'];
        // Without configured rules detect_operator() checks the WHOLE number's shape, so it gets a
        // stand-in of the right length; with rules a prefix is all it ever reads.
        $probe = $hasPrefixRules ? $prefix : ((int)$g['full_mobile'] === 1 ? str_pad($prefix, 12, '0') : '');
        $operatorOf[$probe] ??= detect_operator($probe);
        analytics_add($byNumber, (string)$g['originator'] !== '' ? (string)$g['originator'] : '—', $status, $n, $units);
        analytics_add($byUser, (string)$g['sender_user_id'], $status, $n, $units);
        analytics_add($byOperator, $operatorOf[$probe], $status, $n, $units);
        analytics_add($overallBucket, '_', $status, $n, $units);
    }
    $overall = $overallBucket['_'] ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'units' => 0];

    $usernames = [];
    foreach (backend_usernames_by_ids(array_map('intval', array_keys($byUser))) as $id => $username) {
        $usernames[(string)$id] = $username;
    }

    // Sort every breakdown by total messages, descending.
    $sortByTotal = function (array &$a) { uasort($a, fn($x, $y) => $y['total'] <=> $x['total']); };
    $sortByTotal($byNumber);
    $sortByTotal($byUser);
    $sortByTotal($byOperator);
    ?>
<div class="grid grid-4">
  <div class="stat stat-accent"><div class="stat-label">کل پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($overall['total'])) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($overall['sent'] + $overall['delivered'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($overall['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">مجموع واحد پیامک</div><div class="stat-value"><?= to_persian_digits(number_format($overall['units'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
  <h2>بر اساس خط ارسال</h2>
  <?php analytics_table($byNumber, fn(?string $k) => $k === null ? 'خط' : '<span class="msisdn">' . e($k) . '</span>'); ?>
</div>

<div class="card">
  <h2>بر اساس کاربر</h2>
  <?php analytics_table($byUser, fn(?string $k) => $k === null ? 'کاربر' : e($usernames[$k] ?? ('کاربر #' . $k))); ?>
</div>

<div class="card">
  <h2>بر اساس اپراتور</h2>
  <p class="hint">تشخیص اپراتور بر اساس پیش‌شماره‌ی موبایل و صرفاً حدسی است — بازه‌های احتمالی که رگولاتور بعداً تغییر داده باشد یا اپراتورهای کوچک‌تر، در «سایر / نامشخص» قرار می‌گیرند.</p>
  <?php analytics_table($byOperator, fn(?string $k) => $k === null ? 'اپراتور' : operator_label($k, $k)); ?>
</div>
<?php
    exit;
}

// Historical COST comes from the immutable price snapshots written at send acceptance
// (ellsms_sms_price_snapshots), NEVER recomputed from the current tariff tables — that is the whole
// point of the snapshot: an admin raising a rate today must not retroactively change what last
// month's sends cost (STEP 45 / Invariant G). `committed` is what was actually settled once the
// gateway answered; `accepted` is what was reserved at acceptance, and the two differ exactly by
// what never sent.
if (lazy_part_begin('cost')) {
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
    ?>
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
<?php
    exit;
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <button class="btn btn-primary">اعمال فیلتر</button>
  </form>
</div>

<?= lazy_part_placeholder('traffic', 420) ?>
<?= lazy_part_placeholder('cost', 160) ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
