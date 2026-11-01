<?php
/**
 * Telegram user-account service — send a message (text and/or one photo/file) to a list of numbers
 * from the customer's own Telegram account(s), and follow every message to sent / read / failed.
 * ?id=N shows one campaign; ?id=N&export=csv downloads its report.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
tg_require_user($me);
$pageTitle = 'ارسال با تلگرام';
$active = 'telegram_send';
$userId = (int)$me['id'];
$config = tg_user_config($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'create');
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($action === 'cancel') {
        if (tg_campaign_cancel($me, $campaignId)) {
            flash('success', 'ارسال‌های باقی‌مانده لغو شد؛ هزینه‌ای بابت آن‌ها کسر نمی‌شود.');
        } else {
            flash('error', 'این ارسال قابل لغو نیست.');
        }
        redirect('/telegram-send.php?id=' . $campaignId);
    }
    if ($action === 'pause' || $action === 'resume') {
        tg_campaign_set_paused($me, $campaignId, $action === 'pause');
        redirect('/telegram-send.php?id=' . $campaignId);
    }

    $recipients = tg_parse_phones((string)($_POST['recipients'] ?? ''));
    $file = $_FILES['recipients_file'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$file['tmp_name'])) {
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            flash('error', 'فایل شماره‌ها حداکثر ۵ مگابایت می‌تواند باشد.');
            redirect('/telegram-send.php');
        }
        $recipients = array_values(array_unique(array_merge($recipients, tg_parse_phones((string)file_get_contents((string)$file['tmp_name'])))));
    }
    $fromLookup = (int)($_POST['from_lookup'] ?? 0);
    if ($fromLookup > 0 && tg_lookup_find($me, $fromLookup)) {
        $st = db()->prepare("SELECT phone FROM ellsms_tg_lookup_items WHERE job_id = ? AND status = 'found'");
        $st->execute([$fromLookup]);
        $recipients = array_values(array_unique(array_merge($recipients, array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)))));
    }
    $media = tg_media_store($userId, $_FILES['media'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if (!$media['ok']) {
        flash('error', $media['error']);
        redirect('/telegram-send.php');
    }
    $r = tg_campaign_create($me, [
        'kind' => (string)($_POST['kind'] ?? 'notify'),
        'title' => (string)($_POST['title'] ?? ''),
        'account_id' => (int)($_POST['account_id'] ?? 0),
        'content' => (string)($_POST['content'] ?? ''),
        'media' => isset($media['path']) ? $media : null,
        'recipients' => $recipients,
        'acknowledged' => !empty($_POST['acknowledged']),
        'source' => 'panel',
    ]);
    if (!$r['ok']) {
        if (isset($media['path'])) @unlink(tg_media_root() . '/' . $media['path']);
        flash('error', $r['error']);
        redirect('/telegram-send.php');
    }
    audit($userId, 'telegram.campaign.create', 'campaign=' . $r['id'] . ' recipients=' . count($recipients));
    flash('success', 'ارسال در صف قرار گرفت (' . to_persian_digits((string)count($recipients)) . ' گیرنده). وضعیت هر پیام را همین‌جا ببینید.');
    redirect('/telegram-send.php?id=' . $r['id']);
}

/* ---------- one campaign ---------- */
if (isset($_GET['id'])) {
    $campaign = tg_campaign_find($me, (int)$_GET['id']);
    if (!$campaign) {
        http_response_code(404);
        exit('ارسال پیدا نشد.');
    }
    $cid = (int)$campaign['id'];
    $statusFilter = (string)($_GET['status'] ?? '');
    $where = 'm.campaign_id = ?';
    $params = [$cid];
    if (in_array($statusFilter, ['queued', 'sending', 'sent', 'read', 'failed', 'cancelled'], true)) {
        $where .= ' AND m.status = ?';
        $params[] = $statusFilter;
    }
    if (($_GET['export'] ?? '') === 'csv') {
        $st = db()->prepare("SELECT m.phone, m.status, m.error_code, m.sent_at, m.read_at, m.cost, a.phone AS account_phone
                             FROM ellsms_tg_messages m LEFT JOIN ellsms_tg_accounts a ON a.id = m.account_id WHERE {$where} ORDER BY m.id");
        $st->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="telegram-send-' . $cid . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['phone', 'status', 'status_fa', 'error', 'sent_at', 'read_at', 'cost', 'sender_account'], ',', '"', '');
        foreach ($st as $row) {
            fputcsv($out, [$row['phone'], $row['status'], tg_status_label((string)$row['status']), $row['error_code'] ? tg_error_label((string)$row['error_code']) : '',
                $row['sent_at'], $row['read_at'], $row['cost'], $row['account_phone']], ',', '"', '');
        }
        exit;
    }
    $counts = tg_campaign_counts($cid);
    $count = db()->prepare("SELECT COUNT(*) FROM ellsms_tg_messages m WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $per = 100;
    [$page, $offset] = pager_offset($total, $per);
    $st = db()->prepare("SELECT m.*, a.phone AS account_phone FROM ellsms_tg_messages m LEFT JOIN ellsms_tg_accounts a ON a.id = m.account_id
                         WHERE {$where} ORDER BY m.id LIMIT {$per} OFFSET {$offset}");
    $st->execute($params);
    $rows = $st->fetchAll();
    $delivered = $counts['sent'] + $counts['read'];
    require __DIR__ . '/../app/views/header.php';
    ?>
    <p><a href="/telegram-send.php">← همه‌ی ارسال‌ها</a></p>
    <div class="card">
      <h2><?= e((string)($campaign['title'] ?: 'ارسال #' . $cid)) ?> <?= tg_status_badge((string)$campaign['status']) ?></h2>
      <p class="hint">نوع: <?= e(TG_KINDS[$campaign['kind']] ?? $campaign['kind']) ?> · منبع: <?= $campaign['source'] === 'api' ? 'API' : 'پنل' ?> ·
        ثبت: <?= jdate((string)$campaign['created_at']) ?><?php if ($campaign['finished_at']): ?> · پایان: <?= jdate((string)$campaign['finished_at']) ?><?php endif; ?> ·
        هزینه‌ی هر پیام: <?= to_persian_digits(number_format((int)$campaign['price_each'])) ?> ریال</p>
      <div class="grid grid-4">
        <div class="stat"><div class="stat-label">کل گیرندگان</div><div class="stat-value"><?= to_persian_digits(number_format((int)$campaign['total'])) ?></div></div>
        <div class="stat"><div class="stat-label">ارسال‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($delivered)) ?></div></div>
        <div class="stat"><div class="stat-label">خوانده‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($counts['read'])) ?></div>
          <div class="stat-foot"><?= $delivered > 0 ? to_persian_digits((string)round($counts['read'] * 100 / $delivered)) . '٪ از ارسال‌شده‌ها' : '' ?></div></div>
        <div class="stat"><div class="stat-label">ناموفق / در صف</div><div class="stat-value"><?= to_persian_digits(number_format($counts['failed'])) ?> / <?= to_persian_digits(number_format($counts['queued'] + $counts['sending'])) ?></div>
          <div class="stat-foot">هزینه تا الان: <?= to_persian_digits(number_format($counts['cost'])) ?> ریال</div></div>
      </div>
      <?php if ($campaign['content']): ?><p style="white-space:pre-wrap;margin-top:12px" class="msg-preview"><?= e((string)$campaign['content']) ?></p><?php endif; ?>
      <?php if ($campaign['media_name']): ?><p class="hint">پیوست: <?= e((string)$campaign['media_name']) ?> (<?= $campaign['media_kind'] === 'photo' ? 'تصویر' : 'فایل' ?>)</p><?php endif; ?>
      <div class="toolbar">
        <?php if (in_array($campaign['status'], ['queued', 'running'], true)): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn-sm" name="action" value="pause">توقف موقت</button></form>
        <?php elseif ($campaign['status'] === 'paused'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn-sm btn-primary" name="action" value="resume">ادامه‌ی ارسال</button></form>
        <?php endif; ?>
        <?php if (in_array($campaign['status'], ['queued', 'running', 'paused'], true)): ?>
          <form method="post" onsubmit="return confirm('ارسال‌های باقی‌مانده لغو شوند؟');"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn-sm btn-danger" name="action" value="cancel">لغو باقی‌مانده</button></form>
        <?php endif; ?>
        <a class="btn btn-sm" href="/telegram-send.php?<?= e(http_build_query(['id' => $cid, 'status' => $statusFilter, 'export' => 'csv'])) ?>">دریافت گزارش (CSV)</a>
      </div>
    </div>
    <div class="card" style="margin-top:18px">
      <form method="get" class="toolbar">
        <input type="hidden" name="id" value="<?= $cid ?>">
        <label>وضعیت
          <select name="status" onchange="this.form.submit()">
            <option value="">همه</option>
            <?php foreach (['queued', 'sending', 'sent', 'read', 'failed', 'cancelled'] as $s): ?>
              <option value="<?= $s ?>"<?= $statusFilter === $s ? ' selected' : '' ?>><?= e(tg_status_label($s)) ?> (<?= to_persian_digits((string)$counts[$s]) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <div class="table-wrap">
      <table>
        <tr><th>گیرنده</th><th>وضعیت</th><th>علت</th><th>زمان ارسال</th><th>زمان خواندن</th><th>اکانت فرستنده</th><th>هزینه</th></tr>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="msisdn ltr"><?= e((string)$r['phone']) ?></td>
            <td><?= tg_status_badge((string)$r['status']) ?></td>
            <td><?= $r['error_code'] ? e(tg_error_label((string)$r['error_code'])) : '' ?></td>
            <td class="num"><?= $r['sent_at'] ? jdate((string)$r['sent_at']) : '—' ?></td>
            <td class="num"><?= $r['read_at'] ? jdate((string)$r['read_at']) : '—' ?></td>
            <td class="ltr"><?= $r['account_phone'] ? e(tg_mask_phone((string)$r['account_phone'])) : '' ?></td>
            <td class="num"><?= to_persian_digits(number_format((int)$r['cost'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">پیامی با این وضعیت نیست.</td></tr><?php endif; ?>
      </table>
      </div>
      <?= pager_links($page, pager_pages($total, $per), ['id' => $cid, 'status' => $statusFilter], 'page', $total) ?>
    </div>
    <?php if (in_array($campaign['status'], ['queued', 'running'], true)): ?><script>setTimeout(function () { location.reload(); }, 15000);</script><?php endif; ?>
    <?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* ---------- new send + list ---------- */
$accounts = tg_accounts_for_user($userId, true);
[$ownerWhere, $ownerParams] = tg_owner_clause(['id' => $userId, 'role' => 'user']);
$count = db()->prepare("SELECT COUNT(*) FROM ellsms_tg_campaigns WHERE {$ownerWhere}");
$count->execute($ownerParams);
$total = (int)$count->fetchColumn();
$per = 30;
[$page, $offset] = pager_offset($total, $per);
$st = db()->prepare("SELECT c.*,
                       (SELECT COUNT(*) FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND m.status IN ('sent','read')) AS sent_count,
                       (SELECT COUNT(*) FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND m.status = 'read') AS read_count,
                       (SELECT COUNT(*) FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND m.status = 'failed') AS failed_count
                     FROM ellsms_tg_campaigns c WHERE {$ownerWhere} ORDER BY c.id DESC LIMIT {$per} OFFSET {$offset}");
$st->execute($ownerParams);
$campaigns = $st->fetchAll();
$lookups = db()->prepare("SELECT j.id, j.title, j.created_at, (SELECT COUNT(*) FROM ellsms_tg_lookup_items i WHERE i.job_id = j.id AND i.status = 'found') AS found
                          FROM ellsms_tg_lookup_jobs j WHERE j.user_id = ? AND j.status = 'completed' ORDER BY j.id DESC LIMIT 20");
$lookups->execute([$userId]);
$lookups = array_filter($lookups->fetchAll(), static fn(array $l): bool => (int)$l['found'] > 0);
$dailyCapacity = $config['daily_send_limit'] * max(1, count($accounts));
$threshold = tg_setting_int('tg_promo_warn_threshold', 30);

require __DIR__ . '/../app/views/header.php';
?>
<?= tg_unavailable_banner($me) ?>
<div class="card">
  <h2>ارسال جدید با تلگرام</h2>
  <?php if (!$accounts): ?>
    <div class="flash flash-error"><span class="flash-text">هیچ اکانت تلگرام فعالی ندارید. ابتدا از <a href="/telegram-accounts.php">اکانت‌های تلگرام</a> یک اکانت وصل کنید.</span></div>
  <?php else: ?>
  <p class="hint">هزینه: هر پیام متنی <b><?= to_persian_digits(number_format($config['price_text'])) ?></b> ریال، پیام همراه تصویر/فایل
    <b><?= to_persian_digits(number_format($config['price_media'])) ?></b> ریال — فقط برای پیام‌هایی که واقعاً ارسال شوند. ظرفیت روزانه‌ی اکانت‌های شما حدود
    <b><?= to_persian_digits(number_format($dailyCapacity)) ?></b> پیام است؛ بقیه روزهای بعد ارسال می‌شود.</p>
  <form method="post" enctype="multipart/form-data" id="tgSendForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="toolbar">
      <label>عنوان (اختیاری) <input type="text" name="title" maxlength="190"></label>
      <label>نوع پیام
        <select name="kind" id="tgKind">
          <?php foreach (TG_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === 'notify' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>ارسال از
        <select name="account_id">
          <option value="0">همه‌ی اکانت‌های فعال (تقسیم خودکار)</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e('+' . $a['phone']) ?><?= $a['display_name'] ? ' — ' . e((string)$a['display_name']) : '' ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label style="display:block">متن پیام
      <textarea name="content" rows="5" style="width:100%" maxlength="<?= TG_MAX_TEXT ?>" placeholder="متن پیام… (با پیوست، حداکثر <?= to_persian_digits((string)TG_MAX_CAPTION) ?> نویسه)"></textarea>
    </label>
    <div class="toolbar">
      <label>تصویر یا فایل پیوست (اختیاری، حداکثر ۲۰ مگابایت) <input type="file" name="media"></label>
    </div>
    <label style="display:block">گیرندگان (هر شماره در یک خط یا جدا با ویرگول)
      <textarea name="recipients" id="tgRecipients" rows="6" class="ltr" style="width:100%" placeholder="09121234567&#10;+989351234567"></textarea>
    </label>
    <div class="toolbar">
      <label>یا فایل شماره‌ها (txt / csv) <input type="file" name="recipients_file" accept=".txt,.csv"></label>
      <?php if ($lookups): ?>
        <label>یا شماره‌های «تلگرام دارد» از بررسی
          <select name="from_lookup">
            <option value="0">—</option>
            <?php foreach ($lookups as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e((string)($l['title'] ?: 'بررسی #' . $l['id'])) ?> (<?= to_persian_digits((string)$l['found']) ?> شماره)</option><?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <div class="flash flash-error" id="tgWarn" style="display:none"><span class="flash-text">
      <b>هشدار مسدود شدن اکانت:</b> ارسال تبلیغاتی یا انبوه (بیش از <?= to_persian_digits((string)$threshold) ?> گیرنده) از اکانت شخصی، مخصوصاً به کسانی که شما را در مخاطبین ندارند،
      ممکن است باعث محدود یا مسدود شدن دائمی اکانت تلگرام شما شود. سامانه با اولین هشدار تلگرام ارسال آن اکانت را متوقف می‌کند، اما مسئولیت با شماست.
    </span></div>
    <p id="tgAckRow"><label><input type="checkbox" name="acknowledged" value="1" id="tgAck"> می‌دانم ارسال تبلیغاتی یا انبوه ممکن است باعث مسدود شدن اکانت تلگرامم شود و با مسئولیت خودم ارسال می‌کنم.</label></p>
    <button class="btn btn-primary">ثبت ارسال</button>
  </form>
  <script>
  (function () {
    var kind = document.getElementById('tgKind'), rec = document.getElementById('tgRecipients'), form = document.getElementById('tgSendForm');
    var warn = document.getElementById('tgWarn'), ack = document.getElementById('tgAck');
    var threshold = <?= (int)$threshold ?>;
    function count() { return rec.value.split(/[\s,;،]+/).filter(function (s) { return s.replace(/\D/g, '').length >= 10; }).length; }
    function update() {
      var need = kind.value === 'promo' || count() > threshold;
      warn.style.display = need ? '' : 'none';
      ack.required = need;
    }
    kind.addEventListener('change', update); rec.addEventListener('input', update);
    update();
  })();
  </script>
  <p class="hint">اگر تعداد گیرندگان از فایل یا بررسی بیشتر از <?= to_persian_digits((string)$threshold) ?> شود هم تأیید هشدار لازم است؛ در غیر این صورت ارسال ثبت نمی‌شود.</p>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:18px">
  <h2>ارسال‌های تلگرام</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>عنوان</th><th>نوع</th><th>گیرندگان</th><th>ارسال‌شده</th><th>خوانده‌شده</th><th>ناموفق</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($campaigns as $c): ?>
      <tr>
        <td class="num"><a href="/telegram-send.php?id=<?= (int)$c['id'] ?>"><?= to_persian_digits((string)$c['id']) ?></a></td>
        <td><a href="/telegram-send.php?id=<?= (int)$c['id'] ?>"><?= e((string)($c['title'] ?: mb_strimwidth((string)$c['content'], 0, 40, '…'))) ?></a><?= $c['source'] === 'api' ? ' <span class="muted">API</span>' : '' ?></td>
        <td><?= e(TG_KINDS[$c['kind']] ?? $c['kind']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['sent_count'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['read_count'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['failed_count'])) ?></td>
        <td><?= tg_status_badge((string)$c['status']) ?></td>
        <td class="num"><?= jdate((string)$c['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$campaigns): ?><tr><td colspan="9" class="empty">هنوز ارسالی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($total, $per), [], 'page', $total) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
