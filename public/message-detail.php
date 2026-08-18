<?php
/**
 * ELLSMS — delivery lifecycle of one message (اطلاعات پیام).
 *
 * Shows what ACTUALLY happened to a message: the route/gateway it travelled, the provider reference
 * it came back with, every delivery-status poll since, and the price it was charged at acceptance.
 *
 * Every value on this page is READ from a stored record. Nothing is re-resolved against today's
 * configuration and nothing is re-priced — see app/Reports/MessageDetail.php for why that matters.
 *
 * TENANT SCOPE. An ordinary user's organization id is passed into every query, so an id typed into
 * the URL that belongs to another organization simply does not resolve. A platform admin passes null
 * and sees everything, which is their existing documented bypass.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'اطلاعات پیام';
$active = 'reports';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

// null for a platform admin (no scope restriction), the org id for everyone else. This single value
// is what every query below is scoped by — there is no second, looser path.
$scopeOrgId = is_admin() ? null : (int)($me['organization_id'] ?? 0);
if (!is_admin() && !$scopeOrgId) {
    // A user with no resolvable organization can only ever see their own attempts; without an org to
    // scope by, fail closed rather than falling through to an unscoped query.
    http_response_code(404);
    $notFound = true;
}

$attemptId = (int)($_GET['attempt'] ?? 0);
$jobId     = (int)($_GET['job'] ?? 0);

$attempt = null;
$bulkJob = null;
$items   = [];

if (empty($notFound) && $attemptId > 0) {
    $attempt = report_attempt_by_id($attemptId, $scopeOrgId);
} elseif (empty($notFound) && $jobId > 0) {
    $sql = 'SELECT * FROM ellsms_bulk_jobs WHERE id = ?';
    $params = [$jobId];
    if ($scopeOrgId !== null) {
        $sql .= ' AND organization_id = ?';
        $params[] = $scopeOrgId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $bulkJob = $st->fetch() ?: null;
    if ($bulkJob !== null) {
        $items = report_bulk_items($jobId, $scopeOrgId);
    }
}

if ($attempt === null && $bulkJob === null) {
    http_response_code(404);
    $pageTitle = 'پیام یافت نشد';
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p class="empty">پیام مورد نظر یافت نشد یا به سازمان شما تعلق ندارد.</p>'
       . '<p><a class="btn" href="/reports.php">بازگشت به گزارش‌ها</a></p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* ---------- Direct send / schedule ---------- */
if ($attempt !== null) {
    $referenceType = (string)$attempt['reference_type'];
    $referenceId   = (string)$attempt['reference_id'];

    $pricing = report_pricing_for($referenceType, $referenceId);
    $snapshot = $pricing['groups'][0] ?? null;

    // The message body is not stored on the attempt row (the attempt records TRANSPORT, not
    // content), so the part count comes from the pricing snapshot that was frozen at acceptance.
    // Where no snapshot exists the count is genuinely unavailable rather than guessed from a text
    // this page does not have.
    $segments = report_segment_count($snapshot, null);

    // ONE bounded lookup for every name on the page, rather than one per row.
    $names = report_resolve_names(
        [$attempt['gateway_id']],
        [$attempt['route_id']],
        [$attempt['operator_id']]
    );

    $timeline = report_build_timeline($attempt);
}

/* ---------- Bulk job ---------- */
if ($bulkJob !== null) {
    $pricing = report_pricing_for('bulk_job', (string)$bulkJob['id']);
    $snapshot = $pricing['groups'][0] ?? null;

    // A bulk job's text lives on the ITEM, not the job: a p2p job legitimately sends a different
    // body to every recipient, so there is no single job-level content. The first item's text is
    // used only to describe the ENCODING of a template-style job; the authoritative part count still
    // comes from the acceptance snapshot, which covers every recipient.
    $sampleContent = (string)($bulkJob['template'] ?? ($items[0]['content'] ?? ''));
    $segments = report_segment_count($snapshot, $sampleContent);
    $encoding = report_message_encoding($sampleContent);

    // Collect every id across all recipient rows first, then resolve them in three queries total.
    $gatewayIds = $routeIds = $operatorIds = [];
    foreach ($items as $item) {
        $gatewayIds[]  = $item['gateway_id'];
        $routeIds[]    = $item['route_id'] ?? null;
        $operatorIds[] = $item['operator_id'] ?? null;
    }
    $names = report_resolve_names($gatewayIds, $routeIds, $operatorIds);
}

$dash = '—';
/** Renders a stored timestamp in Jalali, or an em dash — never a substitute value. */
$time = fn(?string $t) => ($t === null || $t === '' || str_starts_with((string)$t, '0000')) ? $dash : jdate($t);

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($attempt !== null): ?>
<div class="card">
  <h2>اطلاعات پیام</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه داخلی</th><td class="num"><?= to_persian_digits((string)$attempt['id']) ?></td>
        <th>نوع درخواست</th><td><?= e(report_reference_type_label($attempt['reference_type'])) ?></td></tr>
    <tr><th>گیرنده</th><td class="msisdn"><?= e((string)($attempt['destination'] ?? $dash)) ?></td>
        <th>وضعیت</th>
        <?php $attemptStatus = report_canonical_status($attempt['delivery_status']); ?>
        <td><span class="badge badge-<?= e($attemptStatus['class']) ?>">
              <?= e($attemptStatus['label']) ?></span></td></tr>
    <tr><th>تعداد پارت</th>
        <td class="num"><?= $segments['source'] === 'unavailable' ? $dash : to_persian_digits((string)$segments['parts']) ?>
          <?php if ($segments['source'] === 'snapshot'): ?>
            <small style="opacity:.7">(ثبت‌شده هنگام ارسال)</small>
          <?php endif; ?></td>
        <th>تعداد تلاش استعلام وضعیت</th>
        <td class="num"><?= to_persian_digits((string)(int)$attempt['delivery_attempts']) ?></td></tr>
    <tr><th>اپراتور</th><td><?= e($names['operators'][(int)($attempt['operator_id'] ?? 0)] ?? $dash) ?></td>
        <th>مسیر</th><td><?= e($names['routes'][(int)($attempt['route_id'] ?? 0)] ?? $dash) ?></td></tr>
    <tr><th>درگاه</th>
        <td><?= e($names['gateways'][(int)($attempt['gateway_id'] ?? 0)] ?? $dash) ?>
          <?php if (!empty($attempt['gateway_config_version'])): ?>
            <small style="opacity:.7">v<?= to_persian_digits((string)(int)$attempt['gateway_config_version']) ?></small>
          <?php endif; ?></td>
        <th>شناسه اپراتور (مرجع درگاه)</th>
        <?php /* B9/B32: rendered as a STRING, never a number. A 19-digit reference exceeds exact
                 integer range in both PHP and JavaScript; any numeric handling would round it. */ ?>
        <td class="provider-ref"><?= e((string)($attempt['provider_message_id'] ?? $dash)) ?></td></tr>
    <tr><th>وضعیت خام درگاه</th>
        <td class="ltr"><?= $attempt['provider_status'] !== null && $attempt['provider_status'] !== ''
              ? e((string)$attempt['provider_status'])
              : '<small style="opacity:.7">ثبت نشده</small>' ?></td>
        <th>زمان تلاش ارسال</th><td class="num"><?= $time($attempt['attempted_at'] ?? null) ?></td></tr>
    <tr><th>آخرین استعلام وضعیت</th><td class="num"><?= $time($attempt['delivery_checked_at'] ?? null) ?></td>
        <?php /* B25/B26: delivery_checked_at is when we last ASKED. It is never shown as, or
                 substituted for, the delivery time. */ ?>
        <th>زمان تحویل</th>
        <td class="num"><?= !empty($attempt['delivered_at']) ? $time($attempt['delivered_at']) : 'هنوز تحویل نشده' ?></td></tr>
    <?php if (!empty($attempt['error_code'])): ?>
    <tr><th>کد خطا</th><td class="ltr"><?= e((string)$attempt['error_code']) ?></td>
        <th>پیام خطا</th><td><?= e((string)($attempt['error_message'] ?? '')) ?></td></tr>
    <?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:22px">
  <h2>روند تحویل</h2>
  <div class="table-wrap">
  <table>
    <tr><th>مرحله</th><th>زمان</th></tr>
    <?php foreach ($timeline as $step): ?>
      <tr>
        <td><?= e($step['label']) ?></td>
        <td class="num"><?= $step['at'] === null ? $dash : $time($step['at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<?php if ($pricing['available']): ?>
<div class="card" style="margin-top:22px">
  <h2>هزینه</h2>
  <?php /* B17: read from the immutable acceptance snapshot. Never re-priced against today's tariff. */ ?>
  <div class="table-wrap">
  <table>
    <tr><th>تعداد گیرنده</th><th>تعداد پارت</th><th>قیمت واحد</th><th>هزینه پذیرش</th><th>هزینه نهایی</th><th>مسیر قیمت‌گذاری</th></tr>
    <?php foreach ($pricing['groups'] as $g): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)(int)$g['recipient_count']) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$g['segment_count']) ?></td>
        <td class="num"><?= to_persian_digits(report_format_millicredits((int)$g['unit_price_millicredits'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$g['total_cost_credits'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$g['committed_cost_credits'])) ?></td>
        <td><?= e((string)$g['price_source']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p style="opacity:.7; font-size:.9em">واحد: <?= e($pricing['currency'] === 'credit' ? 'اعتبار' : $pricing['currency']) ?> — این مقادیر در زمان ارسال ثبت شده‌اند و با تغییر تعرفه تغییر نمی‌کنند.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($bulkJob !== null): ?>
<div class="card">
  <h2>اطلاعات ارسال انبوه</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه</th><td class="num"><?= to_persian_digits((string)$bulkJob['id']) ?></td>
        <th>فرستنده</th><td class="msisdn"><?= e((string)($bulkJob['originator'] ?? $dash)) ?></td></tr>
    <tr><th>فرمت</th><td><?= e($encoding['label']) ?></td>
        <th>تعداد پارت</th>
        <td class="num"><?= to_persian_digits((string)$segments['parts']) ?>
          <?php if ($segments['source'] === 'snapshot'): ?><small style="opacity:.7">(ثبت‌شده هنگام ارسال)</small><?php endif; ?></td></tr>
    <tr><th>زمان ثبت</th><td class="num"><?= $time($bulkJob['created_at'] ?? null) ?></td>
        <th>تعداد گیرندگان</th><td class="num"><?= to_persian_digits((string)(int)($bulkJob['total_rows'] ?? count($items))) ?></td></tr>
  </table>
  </div>
  <?php if ($sampleContent !== ''): ?>
    <h3>محتوا<?= empty($bulkJob['template']) ? ' <small style="opacity:.7">(نمونه — متن هر گیرنده جداگانه است)</small>' : '' ?></h3>
    <div class="msg-body"><?= nl2br(e($sampleContent)) ?></div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:22px">
  <h2>گیرندگان</h2>
  <?php /* B13/B15: every row shows ITS OWN provider reference and delivery state — never the first
           recipient's values repeated. All names were resolved in three bounded queries above. */ ?>
  <div class="table-wrap">
  <table>
    <tr>
      <th>ردیف</th><th>شماره</th><th>اپراتور</th><th>شناسه اپراتور</th>
      <th>وضعیت</th><th>تعداد تلاش استعلام</th><th>آخرین استعلام</th><th>زمان تحویل</th>
    </tr>
    <?php foreach ($items as $i => $item): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)($i + 1)) ?></td>
        <td class="msisdn"><?= e((string)$item['mobile']) ?></td>
        <td><?= e($names['operators'][(int)($item['operator_id'] ?? 0)] ?? $dash) ?></td>
        <td class="provider-ref"><?= e((string)($item['provider_message_id'] ?? $dash)) ?></td>
        <?php $itemStatus = report_canonical_status($item['delivery_status']); ?>
        <td><span class="badge badge-<?= e($itemStatus['class']) ?>">
              <?= e($itemStatus['label']) ?></span></td>
        <td class="num"><?= to_persian_digits((string)(int)($item['delivery_attempts'] ?? 0)) ?></td>
        <td class="num"><?= $time($item['delivery_checked_at'] ?? null) ?></td>
        <td class="num"><?= !empty($item['delivered_at']) ? $time($item['delivered_at']) : $dash ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="8" class="empty">گیرنده‌ای ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<?php if ($pricing['available']): ?>
<div class="card" style="margin-top:22px">
  <h2>هزینه</h2>
  <div class="table-wrap">
  <table>
    <tr><th>اپراتور</th><th>تعداد گیرنده</th><th>تعداد پارت</th><th>قیمت واحد</th><th>هزینه پذیرش</th><th>هزینه نهایی</th></tr>
    <?php foreach ($pricing['groups'] as $g): ?>
      <tr>
        <td><?= e((string)$g['operator_code']) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$g['recipient_count']) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$g['segment_count']) ?></td>
        <td class="num"><?= to_persian_digits(report_format_millicredits((int)$g['unit_price_millicredits'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$g['total_cost_credits'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$g['committed_cost_credits'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p style="opacity:.7; font-size:.9em">واحد: <?= e($pricing['currency'] === 'credit' ? 'اعتبار' : $pricing['currency']) ?> — ثبت‌شده در زمان ارسال.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<p style="margin-top:22px"><a class="btn" href="/reports.php">→ بازگشت به گزارش‌ها</a></p>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
