<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/FailedResend.php';
$me = require_login();
$pageTitle = 'جزئیات ارسال حجیم';
$active = 'p2p';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

$jobId = max(0, (int)($_GET['id'] ?? 0));
if ($jobId <= 0) {
    http_response_code(400);
    exit('شناسه ارسال نامعتبر است.');
}

$jobSt = db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id=?');
$jobSt->execute([$jobId]);
$job = $jobSt->fetch();
if (!$job) {
    http_response_code(404);
    exit('ارسال پیدا نشد.');
}

if (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    $allowed = $orgId > 0
        ? (int)($job['organization_id'] ?? 0) === $orgId
        : (int)$job['user_id'] === (int)$me['id'];
    if (!$allowed) {
        http_response_code(403);
        exit('دسترسی به این ارسال وجود ندارد.');
    }
    // Removed from the customer's panel by an admin (app/HiddenMessages.php): as if it did not exist.
    if (hidden_messages_is_hidden('bulk_job', $jobId)) {
        http_response_code(404);
        exit('ارسال پیدا نشد.');
    }
}
hidden_messages_handle_post();
$jobHidden = is_admin() && hidden_messages_is_hidden('bulk_job', $jobId);

// Cancel from the job's own page: a job queued behind others had no cancel control anywhere once
// its import was confirmed (the import page only cancels before confirmation, and reports are
// read-only). bulk_cancel_campaign() re-checks ownership and only stops rows still pending.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'cancel') {
    csrf_check();
    if (!is_admin()) {
        require_permission(Permissions::MESSAGES_SEND);
    }
    $result = bulk_cancel_campaign($jobId, $me, 'admin panel: bulk-job page cancel');
    if ($result['ok'] && $result['job_cancelled']) {
        flash('success', 'ارسال لغو شد — ' . to_persian_digits(number_format((int)$result['cancelled_items'])) . ' پیامک ارسال‌نشده متوقف شد.');
    } elseif ($result['ok']) {
        flash('info', 'این ارسال قبلاً تمام یا لغو شده بود.');
    } else {
        flash('error', 'لغو این ارسال ممکن نشد.');
    }
    redirect('/messages/bulk-jobs?id=' . $jobId);
}
$canCancelJob = in_array((string)$job['status'], ['pending', 'processing'], true);

// «ارسال مجدد ناموفق‌ها» (app/FailedResend.php, docs/failed-resend.md): the failed rows become a new p2p
// job — on the job owner's account, priced and reserved like any upload. Only for a finished job.
$showDelivery = delivery_status_visible($me);
$canSendBulk = static function () use ($me): bool {
    if (is_admin()) return true;
    $orgId = (int)($me['organization_id'] ?? 0);
    return has_permission((int)$me['id'], $orgId, Permissions::MESSAGES_SEND)
        && organization_has_entitlement($orgId, Entitlements::BULK_SEND);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'resend_failed') {
    csrf_check();
    if (!$canSendBulk()) {
        flash('error', 'شما اجازه‌ی ارسال انبوه ندارید.');
        redirect('/messages/bulk-jobs?id=' . $jobId);
    }
    $result = failed_resend_queue($jobId, $me, $showDelivery);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/messages/bulk-jobs?id=' . ($result['ok'] ? (int)$result['new_job_id'] : $jobId));
}
$resendCount = failed_resend_job_eligible($job) && $canSendBulk() ? failed_resend_count($jobId, $showDelivery) : 0;
$resendHistory = failed_resend_history($jobId);
$resendSource = failed_resend_source_job($jobId);
// Aimed bulk (app/AimedBulk.php): numbers from the platform's own bank are shown masked to the
// customer, and cannot be searched for either — a search would tell whether a number is in the bank.
$recipientsMasked = !empty($job['recipients_masked']) && !is_admin();

$status = trim((string)($_GET['status'] ?? ''));
// Admins only: filter by the provider's own status code (provider_status), e.g. ?pcode=4.
$pcode = is_admin() ? mb_substr(trim((string)($_GET['pcode'] ?? '')), 0, 60) : '';
$dest = trim((string)($_GET['dest'] ?? ''));
$per = (int)($_GET['per_page'] ?? 100);
if (!in_array($per, [50,100,200], true)) $per = 100;

// Delivery states only for admins and customers on delivery-based billing (delivery_status_visible(),
// set above); for anyone else a sent message stays «ارسال‌شده» and only a failed send is «ناموفق».
$reportStatuses = $showDelivery ? ['pending', 'sent', 'delivered', 'failed'] : ['pending', 'sent', 'failed'];
$canonicalStatusSql = $showDelivery ? "CASE
    WHEN bi.delivery_status = 'delivered' THEN 'delivered'
    WHEN bi.delivery_status IN ('failed','rejected','expired') OR bi.status IN ('failed','cancelled') THEN 'failed'
    WHEN bi.status = 'sent' THEN 'sent'
    ELSE 'pending'
END" : "CASE
    WHEN bi.status IN ('failed','cancelled') THEN 'failed'
    WHEN bi.status = 'sent' THEN 'sent'
    ELSE 'pending'
END";

// Each count with its SMS part total (docs/message-parts.md).
$partsSql = message_parts_sql('ellsms_bulk_items', 'bi', 'bi.content');
$sumSt = db()->prepare("SELECT
    COUNT(*) total,
    SUM(({$canonicalStatusSql})='pending') pending_count,
    SUM(({$canonicalStatusSql})='sent') sent_count,
    SUM(({$canonicalStatusSql})='delivered') delivered_count,
    SUM(({$canonicalStatusSql})='failed') failed_count,
    SUM({$partsSql}) total_parts,
    SUM(IF(({$canonicalStatusSql})='pending', {$partsSql}, 0)) pending_parts,
    SUM(IF(({$canonicalStatusSql}) IN ('sent','delivered'), {$partsSql}, 0)) sent_parts,
    SUM(IF(({$canonicalStatusSql})='failed', {$partsSql}, 0)) failed_parts
FROM ellsms_bulk_items bi WHERE bi.job_id=?");
$sumSt->execute([$jobId]);
$summary = $sumSt->fetch() ?: ['total'=>0,'pending_count'=>0,'sent_count'=>0,'delivered_count'=>0,'failed_count'=>0];

$where = ['bi.job_id=?'];
$params = [$jobId];
if ($status !== '' && in_array($status, $reportStatuses, true)) {
    $where[] = "{$canonicalStatusSql}=?";
    $params[] = $status;
}
if ($recipientsMasked) {
    $dest = '';
}
if ($dest !== '') {
    $digits = preg_replace('/\D/', '', $dest);
    if ($digits !== '') {
        $where[] = 'bi.mobile LIKE ?';
        $params[] = '%' . $digits . '%';
    }
}

if ($pcode !== '') {
    $where[] = 'bi.provider_status = ?';
    $params[] = $pcode;
}

// The rows matching the filter: the summary already counts them unless a number is searched.
$statusFilter = in_array($status, $reportStatuses, true) ? $status : '';
if (count($where) === 1) {
    $listTotal = (int)$summary['total'];
} elseif ($pcode === '' && ($dest === '' || preg_replace('/\D/', '', $dest) === '')) {
    $listTotal = (int)$summary[$statusFilter . '_count'];
} else {
    $countSt = db()->prepare('SELECT COUNT(*) FROM ellsms_bulk_items bi WHERE ' . implode(' AND ', $where));
    $countSt->execute($params);
    $listTotal = (int)$countSt->fetchColumn();
}
[$page, $offset] = pager_offset($listTotal, $per);

$sql = "SELECT bi.id,bi.mobile,bi.content,bi.status queue_status,bi.provider_message_id,bi.provider_status,bi.gateway_id,
               bi.delivery_status,bi.delivery_attempts,bi.delivery_checked_at,bi.created_at,
               {$canonicalStatusSql} canonical_status, {$partsSql} parts
        FROM ellsms_bulk_items bi
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bi.id DESC
        LIMIT {$per} OFFSET {$offset}";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Admins: how many of this job's messages got each provider status code (app/Sms/GatewayStatus.php
// gateway_provider_status_label()), e.g. «۱۰ — رسیده به گوشی: ۷۱۱ · ۴ — نرسیده به گوشی: ۲۱۵».
$providerCodes = [];
if (is_admin()) {
    $codeSt = db()->prepare('SELECT bi.gateway_id, bi.provider_status, COUNT(*) n FROM ellsms_bulk_items bi
                             WHERE bi.job_id = ? AND bi.provider_status IS NOT NULL AND bi.provider_status <> \'\'
                             GROUP BY bi.gateway_id, bi.provider_status ORDER BY n DESC LIMIT 30');
    $codeSt->execute([$jobId]);
    $providerCodes = $codeSt->fetchAll();
}

$statusFa = ['pending'=>'در انتظار','sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','failed'=>'ناموفق'];
$statusClass = ['pending'=>'pending','sent'=>'success','delivered'=>'success','failed'=>'error'];
$qs = fn(array $extra = []) => http_build_query(array_filter(array_merge($_GET, $extra), static fn($v) => $v !== null && $v !== ''));

$total = (int)$summary['total'];
$done = (int)$summary['sent_count'] + (int)$summary['delivered_count'] + (int)$summary['failed_count'];
$progress = $total > 0 ? min(100, (int)round(($done / $total) * 100)) : 0;

$nextBatchText = '—';
if (!empty($job['throttle_count']) && !empty($job['throttle_minutes'])) {
    if (!empty($job['last_throttle_at'])) {
        try {
            $next = new DateTimeImmutable((string)$job['last_throttle_at']);
            $next = $next->modify('+' . (int)$job['throttle_minutes'] . ' minutes');
            $nextBatchText = jdate($next->format('Y-m-d H:i:s'));
        } catch (Throwable) {
            $nextBatchText = '—';
        }
    } else {
        $nextBatchText = 'پس از شروع اولین مرحله';
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-ghost" href="/messages/p2p">بازگشت به ارسال‌های نظیر به نظیر</a>
  <a class="btn btn-ghost" href="/messages/reports/bulk?job_id=<?= (int)$jobId ?>">گزارش حجیم</a>
  <?php if (is_admin()): ?><?= hidden_messages_admin_button('bulk_job', $jobId, $jobHidden) ?><?php endif; ?>
</div>
<?php if ($jobHidden): ?>
  <div class="flash flash-info">این ارسال توسط مدیر از پنل کاربر حذف شده است و کاربر آن را نمی‌بیند.</div>
<?php endif; ?>

<div class="card">
  <h2><?= e((string)($job['title'] ?? ('ارسال #' . $jobId))) ?></h2>
  <p class="hint">Job #<?= to_persian_digits((string)$jobId) ?> · خط <?= e((string)$job['originator']) ?></p>
  <?php if ((string)$job['status'] === 'staged' && !empty($job['source_import_job_id'])): ?>
    <p style="margin:0 0 14px"><a class="btn btn-sm btn-primary" href="/contacts/import?id=<?= (int)$job['source_import_job_id'] ?>">این ارسال منتظر تأیید است — تأیید و ارسال</a></p>
  <?php endif; ?>
  <?php if ($canCancelJob): ?>
    <form method="post" style="margin:0 0 14px" onsubmit="return confirm('ارسال #<?= (int)$jobId ?> لغو شود؟ پیامک‌هایی که هنوز ارسال نشده‌اند دیگر ارسال نخواهند شد.');">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="cancel">
      <button class="btn btn-sm btn-danger" type="submit">لغو این ارسال</button>
    </form>
  <?php endif; ?>
  <?php if ($resendSource !== null): ?>
    <p class="hint">این ارسال، ارسال مجدد ناموفق‌های <a href="/messages/bulk-jobs?id=<?= (int)$resendSource ?>">Job #<?= to_persian_digits((string)$resendSource) ?></a> است.</p>
  <?php endif; ?>
  <?php if ($resendCount > 0): ?>
    <form method="post" style="margin:0 0 14px" onsubmit="this.querySelector('button').disabled=true;return true;">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="resend_failed">
      <button class="btn btn-sm btn-primary" type="submit"
        onclick="return confirm('<?= e(to_persian_digits(number_format($resendCount))) ?> پیامک ناموفق این ارسال، با همان خط و همان متن، به‌عنوان یک ارسال نظیر به نظیر جدید دوباره ارسال شود؟ هزینه‌ی آن مانند یک ارسال جدید از اعتبار کسر می‌شود.<?= $resendHistory ? ' توجه: ناموفق‌های این ارسال قبلاً هم دوباره ارسال شده‌اند.' : '' ?>');">ارسال مجدد ناموفق‌ها (<?= to_persian_digits(number_format($resendCount)) ?> پیامک)</button>
    </form>
  <?php endif; ?>
  <?php if ($resendHistory): ?>
    <p class="hint">ارسال‌های مجدد ناموفق‌ها:
      <?php foreach ($resendHistory as $i => $h): ?><?= $i ? ' · ' : '' ?><a href="/messages/bulk-jobs?id=<?= (int)$h['new_job_id'] ?>">Job #<?= to_persian_digits((string)(int)$h['new_job_id']) ?></a> (<?= to_persian_digits(number_format((int)$h['item_count'])) ?> پیامک، <?= jdate((string)$h['created_at']) ?>)<?php endforeach; ?>
    </p>
  <?php endif; ?>
  <div class="grid grid-4">
    <?php $partsValue = static fn(string $k): string => '<div class="stat-value"><span class="stat-unit">پارت</span> ' . to_persian_digits(number_format((int)($summary[$k] ?? 0))) . '</div>';
          $msgMeta = static fn(int $n): string => '<div class="stat-meta">' . to_persian_digits(number_format($n)) . ' پیام</div>'; ?>
    <div class="stat"><div class="stat-label">کل</div><?= $partsValue('total_parts') ?><?= $msgMeta((int)$total) ?></div>
    <div class="stat"><div class="stat-label"><?= $showDelivery ? 'ارسال/تحویل' : 'ارسال‌شده' ?></div><?= $partsValue('sent_parts') ?><?= $msgMeta((int)$summary['sent_count'] + (int)$summary['delivered_count']) ?></div>
    <div class="stat"><div class="stat-label">ناموفق</div><?= $partsValue('failed_parts') ?><?= $msgMeta((int)$summary['failed_count']) ?></div>
    <div class="stat"><div class="stat-label">باقی‌مانده</div><?= $partsValue('pending_parts') ?><?= $msgMeta((int)$summary['pending_count']) ?></div>
  </div>

  <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
    <div><strong>پیشرفت:</strong> <?= to_persian_digits((string)$progress) ?>٪</div>
    <div><strong>وضعیت Job:</strong> <?= e((string)$job['status']) ?></div>
    <div><strong>هر مرحله:</strong> <?= !empty($job['throttle_count']) ? to_persian_digits(number_format((int)$job['throttle_count'])) : 'بدون محدودیت' ?></div>
    <div><strong>فاصله:</strong> <?= !empty($job['throttle_minutes']) ? to_persian_digits((string)(int)$job['throttle_minutes']) . ' دقیقه' : '—' ?></div>
    <div><strong>مرحله بعدی:</strong> <?= e($nextBatchText) ?></div>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <form method="get" class="toolbar">
    <input type="hidden" name="id" value="<?= (int)$jobId ?>">
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach ($reportStatuses as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($statusFa[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (is_admin()): ?><label>کد سرویس‌دهنده <input class="ltr" type="text" name="pcode" value="<?= e($pcode) ?>" size="6"></label><?php endif; ?>
    <?php if (!$recipientsMasked): ?><label>شماره گیرنده <input class="ltr" type="text" name="dest" value="<?= e($dest) ?>"></label><?php endif; ?>
    <label>تعداد در صفحه
      <select name="per_page"><?php foreach ([50,100,200] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn btn-primary">اعمال فیلتر</button>
  </form>

  <?php if ($providerCodes): ?>
    <p class="hint">کد سرویس‌دهنده <span class="badge">فقط مدیر</span>:
      <?php foreach ($providerCodes as $i => $pc): ?><?= $i ? ' · ' : '' ?><a href="?<?= e($qs(['pcode' => (string)$pc['provider_status'], 'page' => null])) ?>"><?= e(gateway_provider_status_label($pc['gateway_id'] !== null ? (int)$pc['gateway_id'] : null, (string)$pc['provider_status'])) ?></a>: <?= to_persian_digits(number_format((int)$pc['n'])) ?><?php endforeach; ?>
    </p>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>گیرنده</th><th>متن</th><th>تعداد پارت</th><th>وضعیت</th><?php if (is_admin()): ?><th>کد سرویس‌دهنده</th><?php endif; ?><th>Provider ID</th><?php if ($showDelivery): ?><th>دفعات استعلام</th><?php endif; ?><th>زمان ثبت</th><?php if ($showDelivery): ?><th>آخرین استعلام</th><?php endif; ?></tr>
    <?php foreach ($rows as $r): $cs=(string)$r['canonical_status']; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="msisdn"><?= e(bulk_recipient_display((string)$r['mobile'], !empty($job['recipients_masked']))) ?></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'],0,80,'…')) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$r['parts']) ?></td>
        <td><span class="badge badge-<?= e($statusClass[$cs] ?? 'pending') ?>"><?= e($statusFa[$cs] ?? $cs) ?></span></td>
        <?php if (is_admin()): ?><td><?= !empty($r['provider_status']) ? e(gateway_provider_status_label($r['gateway_id'] !== null ? (int)$r['gateway_id'] : null, (string)$r['provider_status'])) : '—' ?></td><?php endif; ?>
        <td class="ltr num"><?= !empty($r['provider_message_id']) ? e((string)$r['provider_message_id']) : '—' ?></td>
        <?php if ($showDelivery): ?><td class="num"><?= to_persian_digits((string)(int)$r['delivery_attempts']) ?></td><?php endif; ?>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <?php if ($showDelivery): ?><td class="num"><?= !empty($r['delivery_checked_at']) ? jdate((string)$r['delivery_checked_at']) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= ($showDelivery ? 9 : 7) + (is_admin() ? 1 : 0) ?>" class="empty">ردیفی با این فیلتر پیدا نشد.</td></tr><?php endif; ?>
  </table></div>

  <?= pager_links($page, pager_pages($listTotal, $per), array_filter(array_diff_key($_GET, ['before_id' => 1, 'after_id' => 1]), static fn($v) => is_scalar($v) && $v !== ''), 'page', $listTotal) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>