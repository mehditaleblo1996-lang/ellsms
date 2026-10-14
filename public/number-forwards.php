<?php
/**
 * Per-line forwarding of inbound SMS (MO) and delivery reports (DLR) to the customer's own URL.
 *
 * A customer sets the URLs of the lines they own (when the admin allows it) and reads their
 * forwarding log. A platform admin sets them for any line, edits the service settings (on/off,
 * http://, timeout, retries, parallel calls, retention) and sees every line's log.
 * Library: app/NumberForwarding.php. Worker: cron/number-forward-worker.php. Docs: docs/number-forwarding.md.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'ارسال پیامک‌های دریافتی و گزارش تحویل به URL';
$active = 'number_forwards';
$isAdmin = ($me['role'] ?? null) === 'admin';

if (!number_forward_available()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p class="empty">این بخش هنوز راه‌اندازی نشده است (migration ‏2026_10_18_number_forwarding.sql اجرا نشده).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$lines = number_forward_manageable_lines($me);
$lineNumbers = array_map('strval', array_keys($lines));
$canEdit = $isAdmin || number_forward_users_can_edit();
$kindLabels = ['mo' => 'پیامک دریافتی (MO)', 'dlr' => 'گزارش تحویل (DLR)'];
$statusLabels = [
    'pending' => ['در صف', 'badge-pending'], 'processing' => ['در حال ارسال', 'badge-processing'],
    'delivered' => ['تحویل شد', 'badge-delivered'], 'failed' => ['ناموفق', 'badge-failed'],
    'dead' => ['ناموفق پس از تلاش‌ها', 'badge-undelivered'], 'cancelled' => ['لغو شد', 'badge-cancelled'],
];

/** The page itself, keeping the line being edited and the tab. */
$back = static function (string $edit = '', string $tab = 'lines'): never {
    redirect('/number-forwards.php' . ($edit !== '' ? '?edit=' . rawurlencode($edit) : '') . '#' . $tab);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $number = normalize_originator((string)($_POST['number'] ?? '')) ?? '';

    if ($do === 'settings') {
        if (!$isAdmin) { http_response_code(403); exit('فقط مدیر سامانه می‌تواند تنظیمات را تغییر دهد.'); }
        number_forward_settings_save($_POST);
        audit((int)$me['id'], 'number_forward.settings', 'enabled=' . (number_forward_enabled() ? 1 : 0));
        flash('success', 'تنظیمات سرویس ذخیره شد.');
        $back('', 'settings');
    }

    if (in_array($do, ['save', 'delete', 'test_mo', 'test_dlr'], true)) {
        if ($number === '' || !number_forward_user_may_edit($me, $number)) {
            flash('error', $canEdit ? 'شما اجازه‌ی تنظیم این خط را ندارید.' : 'تنظیم آدرس خطوط فعلاً توسط مدیر سامانه بسته شده است.');
            $back();
        }
        if ($do === 'save') {
            $result = number_forward_save($number, $_POST, (int)$me['id']);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تنظیمات خط ' . $number . ' ذخیره شد.' : $result['error']);
            $back($number);
        }
        if ($do === 'delete') {
            number_forward_delete($number, (int)$me['id']);
            flash('info', 'ارسال به آدرس برای خط ' . $number . ' حذف شد.');
            $back();
        }
        $kind = $do === 'test_mo' ? 'mo' : 'dlr';
        $test = number_forward_send_test($number, $kind);
        if (isset($test['error'])) {
            flash('error', $test['error']);
        } else {
            $r = $test['result'];
            $text = $test['method'] . ' ' . $test['url'] . ' ← ' . ($r['http_status'] !== null ? 'HTTP ' . $r['http_status'] : number_forward_reason_text((string)$r['error_code']))
                  . ' (' . to_persian_digits((string)$r['duration_ms']) . ' ms)';
            flash($test['ok'] ? 'success' : 'error', ($test['ok'] ? 'آزمایش موفق: ' : 'آزمایش ناموفق: ') . $text);
        }
        audit((int)$me['id'], 'number_forward.test', $number . ' ' . $kind . ' ' . ($test['ok'] ?? false ? 'ok' : 'failed'));
        $back($number);
    }

    if ($do === 'requeue') {
        $id = (int)($_POST['id'] ?? 0);
        $n = number_forward_requeue($id > 0 ? $id : null, $number !== '' ? $number : null, $isAdmin ? null : $lineNumbers);
        audit((int)$me['id'], 'number_forward.requeue', ($id > 0 ? '#' . $id : $number) . ' rows=' . $n);
        flash($n > 0 ? 'success' : 'info', $n > 0 ? to_persian_digits((string)$n) . ' مورد دوباره در صف ارسال قرار گرفت.' : 'موردی برای ارسال دوباره پیدا نشد.');
        redirect('/number-forwards.php?' . http_build_query(array_filter(['line' => $number])) . '#log');
    }
    redirect('/number-forwards.php');
}

// The line being edited: one the user may manage, or — for an admin — any line typed in.
$edit = normalize_originator((string)($_GET['edit'] ?? '')) ?? '';
if ($edit !== '' && !$isAdmin && !in_array($edit, $lineNumbers, true)) $edit = '';
$editRow = $edit !== '' ? (number_forward_find($edit) ?? []) : [];

// Saved rows for the lines on the page.
$configs = [];
if ($lineNumbers) {
    $st = db()->prepare('SELECT * FROM ellsms_number_forwards WHERE number IN (' . implode(',', array_fill(0, count($lineNumbers), '?')) . ')');
    $st->execute($lineNumbers);
    foreach ($st->fetchAll() as $row) $configs[(string)$row['number']] = $row;
}

// Log: an admin sees every line, anyone else only their own (fail closed).
$where = ['1=1'];
$params = [];
if (!$isAdmin) {
    if (!$lineNumbers) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'number IN (' . implode(',', array_fill(0, count($lineNumbers), '?')) . ')';
        array_push($params, ...$lineNumbers);
    }
}
$filterLine = normalize_originator((string)($_GET['line'] ?? '')) ?? '';
$filterKind = in_array($_GET['kind'] ?? '', NUMBER_FORWARD_KINDS, true) ? (string)$_GET['kind'] : '';
$filterStatus = array_key_exists((string)($_GET['status'] ?? ''), $statusLabels) ? (string)$_GET['status'] : '';
if ($filterLine !== '') { $where[] = 'number = ?'; $params[] = $filterLine; }
if ($filterKind !== '') { $where[] = 'kind = ?'; $params[] = $filterKind; }
if ($filterStatus !== '') { $where[] = 'status = ?'; $params[] = $filterStatus; }
$per = 50;
$count = db()->prepare('SELECT COUNT(*) FROM ellsms_number_forward_queue WHERE ' . implode(' AND ', $where));
$count->execute($params);
$total = (int)$count->fetchColumn();
[$page, $offset] = pager_offset($total, $per);
$st = db()->prepare('SELECT id, kind, number, source_key, status, attempt_count, next_attempt_at, http_status, error_code, response_excerpt, duration_ms, last_url, created_at, completed_at
                     FROM ellsms_number_forward_queue WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$per} OFFSET {$offset}");
$st->execute($params);
$logRows = $st->fetchAll();
$counts = number_forward_queue_counts($isAdmin ? null : $lineNumbers);

$activeLines = 0;
foreach ($configs as $c) {
    if (((int)$c['mo_enabled'] && $c['mo_url'] !== '') || ((int)$c['dlr_enabled'] && $c['dlr_url'] !== '')) $activeLines++;
}
/** "۳۰ ثانیه" / "۲ دقیقه" / "۱ ساعت" for a number of seconds. */
$humanSeconds = static function (int $s): string {
    if ($s < 60) return to_persian_digits((string)$s) . ' ثانیه';
    if ($s < 3600) return to_persian_digits(rtrim(rtrim(number_format($s / 60, 1, '.', ''), '0'), '.')) . ' دقیقه';
    return to_persian_digits(rtrim(rtrim(number_format($s / 3600, 1, '.', ''), '0'), '.')) . ' ساعت';
};
/** The waits between attempts, for $attempts tries in total. */
$retrySchedule = static function (int $attempts) use ($humanSeconds): array {
    $out = [];
    for ($i = 1; $i < $attempts; $i++) $out[] = $humanSeconds(number_forward_retry_delay_seconds($i));
    return $out;
};
$workers = number_forward_setting_int('number_forward_workers');
$concurrency = number_forward_setting_int('number_forward_concurrency');
$defaultAttempts = number_forward_setting_int('number_forward_max_attempts');

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>📡 ارسال پیامک‌های دریافتی و گزارش تحویل به آدرس شما</h2>
      <p class="hint">
        هر <b>پیامک دریافتی</b> روی خط (MO) و <b>گزارش تحویل نهایی</b> هر پیامک ارسالی از آن (DLR) بلافاصله به آدرس
        <b>http</b> یا <b>https</b> شما فرستاده می‌شود؛ قالب آدرس همان قالب سامانه‌ی وصال است و آدرس‌های قبلی بدون تغییر کار می‌کنند.
      </p>
    </div>
  </div>
  <?php if (number_forward_enabled()): ?>
    <div class="fwd-status is-on"><span class="fwd-dot is-on"></span><b>سرویس فعال است</b>
      <?php if ($isAdmin): ?><span class="fwd-meta"><?= to_persian_digits((string)$workers) ?> پردازش موازی × <?= to_persian_digits((string)$concurrency) ?> ارسال هم‌زمان · حداکثر <?= to_persian_digits((string)$defaultAttempts) ?> تلاش</span><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="fwd-status is-off"><span class="fwd-dot is-err"></span><b>سرویس توسط مدیر سامانه خاموش است</b><span class="fwd-meta">چیزی ارسال نمی‌شود و پیام‌ها در صف می‌مانند.</span></div>
  <?php endif; ?>
  <?php if (!$isAdmin && !number_forward_users_can_edit()): ?>
    <p class="hint">تنظیم آدرس‌ها فعلاً فقط توسط مدیر سامانه انجام می‌شود؛ برای تغییر با پشتیبانی تماس بگیرید.</p>
  <?php endif; ?>
  <div class="grid grid-4" style="margin-top:16px">
    <a class="stat stat-accent" href="/number-forwards.php?status=pending#log" style="text-decoration:none"><div class="stat-label">در صف / در حال ارسال</div><div class="stat-value"><?= to_persian_digits(number_format($counts['pending'] + $counts['processing'])) ?></div></a>
    <a class="stat" href="/number-forwards.php?status=delivered#log" style="text-decoration:none;color:inherit"><div class="stat-label">✅ تحویل شد</div><div class="stat-value"><?= to_persian_digits(number_format($counts['delivered'])) ?></div></a>
    <a class="stat" href="/number-forwards.php?status=dead#log" style="text-decoration:none;color:inherit"><div class="stat-label">⚠️ ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($counts['failed'] + $counts['dead'])) ?></div></a>
    <div class="stat"><div class="stat-label">🔗 خطوط فعال</div><div class="stat-value"><?= to_persian_digits((string)$activeLines) ?> <span class="hint">/ <?= to_persian_digits((string)count($lines)) ?></span></div></div>
  </div>
</div>

<div class="tabs" data-tabs="number-forwards">
<nav class="tabs-nav" role="tablist" aria-label="ارسال به URL">
  <button type="button" role="tab" data-tab="lines">🔗 خطوط<span class="tab-count">(<?= to_persian_digits((string)count($lines)) ?>)</span></button>
  <button type="button" role="tab" data-tab="log">📋 گزارش ارسال‌ها<span class="tab-count">(<?= to_persian_digits((string)$total) ?>)</span></button>
  <button type="button" role="tab" data-tab="help">📘 راهنما</button>
  <?php if ($isAdmin): ?><button type="button" role="tab" data-tab="settings">⚙ تنظیمات سرویس</button><?php endif; ?>
</nav>

<section class="tab-panel" data-panel="lines" role="tabpanel">
<?php if ($edit !== ''): ?>
<?php $v = static fn(string $k, string $d = '') => (string)($editRow[$k] ?? $d); ?>
<div class="card" id="edit">
  <div class="card-head">
    <div>
      <h2>تنظیم خط <span class="msisdn"><?= e($edit) ?></span></h2>
      <?php if (isset($lines[$edit]) && $lines[$edit] !== ''): ?><p class="hint"><?= e($lines[$edit]) ?></p><?php endif; ?>
    </div>
    <a class="btn btn-sm" href="/number-forwards.php#lines">✕ بستن</a>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="number" value="<?= e($edit) ?>">
    <fieldset<?= $canEdit ? '' : ' disabled' ?> style="border:0;padding:0;margin:0">
    <?php foreach (['mo' => ['📥 پیامک‌های دریافتی (MO)', 'https://example.com/sms/receive?from=${originator}&to=${destination}&text=${content}'],
                    'dlr' => ['📬 گزارش تحویل (Delivery Report)', 'https://example.com/sms/delivery?id=${id}&status=${status}&dl_time=${dl_time}']] as $kind => [$title, $placeholder]): ?>
    <div class="form-section">
      <label class="switch"><input type="checkbox" name="<?= $kind ?>_enabled" value="1"<?= $v($kind . '_enabled') === '1' ? ' checked' : '' ?>><span><?= e($title) ?></span></label>
      <input type="text" name="<?= $kind ?>_url" id="url-<?= $kind ?>" class="ltr fwd-url-input" style="width:100%;margin-top:12px" dir="ltr"
             maxlength="<?= NUMBER_FORWARD_MAX_URL_LENGTH ?>" value="<?= e($v($kind . '_url')) ?>" placeholder="<?= e($placeholder) ?>">
      <div class="fwd-chips" aria-label="افزودن متغیر">
        <?php foreach (NUMBER_FORWARD_VARS[$kind] as $var): ?>
          <button type="button" class="fwd-chip" data-insert="url-<?= $kind ?>" data-var="<?= e($var) ?>">${<?= e($var) ?>}</button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="form-section">
      <div class="subsection-title">نحوه‌ی ارسال</div>
      <div class="form-grid">
        <label>روش
          <select name="http_method">
            <option value="GET"<?= $v('http_method', 'GET') === 'GET' ? ' selected' : '' ?>>GET — مقادیر فقط در آدرس</option>
            <option value="POST"<?= $v('http_method') === 'POST' ? ' selected' : '' ?>>POST — مقادیر در بدنه هم فرستاده می‌شوند</option>
          </select>
        </label>
        <label>قالب بدنه‌ی POST
          <select name="post_format">
            <option value="form"<?= $v('post_format', 'form') === 'form' ? ' selected' : '' ?>>فرم (x-www-form-urlencoded)</option>
            <option value="json"<?= $v('post_format') === 'json' ? ' selected' : '' ?>>JSON</option>
          </select>
        </label>
        <label>تعداد تلاش برای این خط
          <input type="number" name="max_attempts" min="0" max="20" value="<?= (int)$v('max_attempts', '0') ?: '' ?>" placeholder="پیش‌فرض سرویس (<?= to_persian_digits((string)$defaultAttempts) ?>)">
        </label>
      </div>
      <label class="switch" style="margin-top:14px"><input type="checkbox" name="tls_verify" value="1"<?= $v('tls_verify') === '1' ? ' checked' : '' ?>>
        <span>بررسی سخت‌گیرانه‌ی گواهی SSL مقصد<span class="switch-sub">خاموش (پیش‌فرض): مثل وصال، به https با گواهی خودامضا یا منقضی هم ارسال می‌شود.</span></span>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">💾 ذخیره</button></div>
    </fieldset>
  </form>
  <?php if ($editRow && $canEdit): ?>
  <div class="toolbar" style="margin-top:12px">
        <?php foreach (['mo' => '🧪 آزمایش آدرس دریافت', 'dlr' => '🧪 آزمایش آدرس تحویل'] as $kind => $label): if ($v($kind . '_url') === '') continue; ?>
          <form method="post" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="do" value="test_<?= $kind ?>"><input type="hidden" name="number" value="<?= e($edit) ?>">
            <button class="btn"><?= e($label) ?></button>
          </form>
        <?php endforeach; ?>
        <form method="post" style="margin:0;margin-inline-start:auto" onsubmit="return confirm('ارسال به آدرس برای این خط حذف شود؟')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="number" value="<?= e($edit) ?>">
          <button class="btn btn-danger">🗑 حذف تنظیمات</button>
        </form>
  </div>
  <?php endif; ?>
  <?php if ($editRow && $canEdit): ?><p class="hint">«آزمایش» یک پیام نمونه (با شناسه‌ی ۰) را همین حالا به آدرس ذخیره‌شده می‌فرستد، حتی اگر کلید آن خاموش باشد.</p><?php endif; ?>
  <?php if ($editRow && (int)$editRow['consecutive_failures'] > 0): ?>
    <div class="fwd-status is-off" style="margin-top:12px"><span class="fwd-dot is-err"></span>
      آخرین خطا: <?= e(number_forward_reason_text((string)$editRow['last_error'])) ?>
      <span class="fwd-meta"><?= jdate((string)$editRow['last_failure_at']) ?> · <?= to_persian_digits((string)$editRow['consecutive_failures']) ?> خطای پشت سر هم</span></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>خطوط</h2>
    <?php if ($isAdmin): ?>
    <form method="get" style="margin:0;display:flex;gap:8px;align-items:center">
      <input type="text" name="edit" class="ltr" style="width:220px" placeholder="خط دیگر، مثلاً 5000123" required aria-label="شماره‌ی خط">
      <button class="btn btn-sm">باز کردن</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$lines): ?>
    <p class="empty"><?= $isAdmin ? 'هنوز شماره‌ای در «شماره‌ها» ثبت نشده.' : 'هنوز خطی به حساب شما تخصیص داده نشده است.' ?></p>
  <?php endif; ?>
  <div class="fwd-lines">
    <?php foreach ($lines as $number => $label): $number = (string)$number; $c = $configs[$number] ?? null;
      $failures = $c ? (int)$c['consecutive_failures'] : 0; ?>
      <div class="fwd-line<?= $number === $edit ? ' is-editing' : '' ?>">
        <div class="fwd-line-head">
          <div>
            <div class="fwd-line-number msisdn"><?= e($number) ?></div>
            <?php if ($label !== ''): ?><div class="fwd-line-label"><?= e($label) ?></div><?php endif; ?>
          </div>
          <?php if ($c): ?><span class="badge badge-unknown"><?= e($c['http_method'] . ($c['http_method'] === 'POST' ? ' · ' . strtoupper($c['post_format']) : '')) ?></span><?php endif; ?>
        </div>
        <?php foreach (['mo' => 'دریافتی', 'dlr' => 'تحویل'] as $kind => $kindLabel):
          $url = $c ? (string)$c[$kind . '_url'] : ''; $on = $c && (int)$c[$kind . '_enabled'] && $url !== ''; ?>
          <div class="fwd-line-row">
            <span class="fwd-dot<?= $on ? ($failures >= NUMBER_FORWARD_WARN_FAILURES ? ' is-warn' : ' is-on') : '' ?>"></span>
            <span class="fwd-kind"><?= $kindLabel ?></span>
            <span class="fwd-url ltr" title="<?= e($url) ?>"><?= $url !== '' ? e($url) : '—' ?></span>
          </div>
        <?php endforeach; ?>
        <div class="fwd-line-foot">
          <span>
            <?php if ($failures >= NUMBER_FORWARD_WARN_FAILURES): ?>
              <span style="color:var(--err)">⚠ <?= to_persian_digits((string)$failures) ?> خطای پشت سر هم</span>
            <?php elseif ($c && $c['last_success_at']): ?>
              آخرین موفق: <?= jdate((string)$c['last_success_at']) ?>
            <?php elseif ($c && $c['last_failure_at']): ?>
              آخرین خطا: <?= jdate((string)$c['last_failure_at']) ?>
            <?php else: ?>
              <?= $c ? 'هنوز ارسالی نداشته' : 'تنظیم نشده' ?>
            <?php endif; ?>
          </span>
          <a class="btn btn-sm<?= $c ? '' : ' btn-primary' ?>" href="/number-forwards.php?edit=<?= e(rawurlencode($number)) ?>#lines"><?= $canEdit ? ($c ? '✎ ویرایش' : '+ تنظیم') : 'مشاهده' ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</section>

<section class="tab-panel" data-panel="log" role="tabpanel">
<div class="card">
  <div class="card-head"><h2>گزارش ارسال‌ها</h2></div>
  <form method="get" class="toolbar">
    <label>خط <input type="text" name="line" class="ltr" value="<?= e($filterLine) ?>"></label>
    <label>نوع
      <select name="kind"><option value="">همه</option>
        <?php foreach ($kindLabels as $key => $label): ?><option value="<?= $key ?>"<?= $filterKind === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>وضعیت
      <select name="status"><option value="">همه</option>
        <?php foreach ($statusLabels as $key => [$label]): ?><option value="<?= $key ?>"<?= $filterStatus === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn btn-primary">جست‌وجو</button>
    <?php if ($filterLine !== '' || $filterKind !== '' || $filterStatus !== ''): ?><a class="btn" href="/number-forwards.php#log">پاک کردن فیلتر</a><?php endif; ?>
  </form>
  <?php if ($filterLine !== '' && ($isAdmin || in_array($filterLine, $lineNumbers, true))): ?>
    <form method="post" class="toolbar" onsubmit="return confirm('همه‌ی موارد ناموفق این خط دوباره ارسال شوند؟')">
      <?= csrf_field() ?><input type="hidden" name="do" value="requeue"><input type="hidden" name="number" value="<?= e($filterLine) ?>">
      <button class="btn btn-sm">↻ ارسال دوباره‌ی همه‌ی موارد ناموفق این خط</button>
    </form>
  <?php endif; ?>
  <div class="table-wrap">
  <table>
    <tr><th>زمان</th><th>نوع</th><th>خط</th><th>پیام</th><th>وضعیت</th><th>تلاش</th><th>پاسخ</th><th>آدرس فراخوانی‌شده</th><th></th></tr>
    <?php foreach ($logRows as $r): [$statusLabel, $badge] = $statusLabels[$r['status']] ?? [$r['status'], 'badge-unknown']; ?>
      <tr>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td><span class="badge badge-unknown"><?= $r['kind'] === 'mo' ? 'MO' : 'DLR' ?></span></td>
        <td class="msisdn"><?= e((string)$r['number']) ?></td>
        <td class="ltr muted"><?= e((string)$r['source_key']) ?></td>
        <td>
          <span class="badge <?= $badge ?>"><?= e($statusLabel) ?></span>
          <?php if ($r['status'] === 'pending' && $r['next_attempt_at']): ?><div class="muted">تلاش بعدی: <?= jdate((string)$r['next_attempt_at']) ?></div><?php endif; ?>
        </td>
        <td class="num"><?= to_persian_digits((string)$r['attempt_count']) ?></td>
        <td>
          <?php if ($r['http_status'] !== null): ?><span class="ltr">HTTP <?= (int)$r['http_status'] ?></span><?php endif; ?>
          <?php if ($r['error_code'] !== null && $r['status'] !== 'delivered'): ?><div class="muted"><?= e(number_forward_reason_text((string)$r['error_code'])) ?></div><?php endif; ?>
          <?php if ($r['duration_ms'] !== null): ?><div class="muted num"><?= to_persian_digits((string)$r['duration_ms']) ?> ms</div><?php endif; ?>
          <?php if ((string)$r['response_excerpt'] !== ''): ?><div class="ltr muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string)$r['response_excerpt']) ?>"><?= e((string)$r['response_excerpt']) ?></div><?php endif; ?>
        </td>
        <td class="ltr muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string)$r['last_url']) ?>"><?= e((string)$r['last_url']) ?></td>
        <td>
          <?php if (in_array($r['status'], ['failed', 'dead', 'cancelled'], true)): ?>
            <form method="post" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="do" value="requeue"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm">↻ دوباره</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logRows): ?><tr><td colspan="9" class="empty">موردی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($total, $per), array_filter(['line' => $filterLine, 'kind' => $filterKind, 'status' => $filterStatus], 'strlen'), 'page', $total, 'log') ?>
  <p class="hint">موارد تمام‌شده پس از <?= to_persian_digits((string)number_forward_setting_int('number_forward_retention_days')) ?> روز از این گزارش پاک می‌شوند.</p>
</div>
</section>

<section class="tab-panel" data-panel="help" role="tabpanel">
<div class="grid grid-2">
<div class="card">
  <h2>📥 متغیرهای پیامک دریافتی (MO)</h2>
  <div class="table-wrap"><table>
    <tr><th>متغیر</th><th>مقدار</th></tr>
    <tr><td class="ltr">${originator}</td><td>شماره‌ی فرستنده (مشتری شما)</td></tr>
    <tr><td class="ltr">${destination}</td><td>خط شما که پیام را دریافت کرد</td></tr>
    <tr><td class="ltr">${content}</td><td>متن پیام</td></tr>
    <tr><td class="ltr">${udh}</td><td>UDH پیام چندبخشی (معمولاً خالی)</td></tr>
    <tr><td class="ltr">${sourcePort} ${destinationPort}</td><td>درگاه‌های پیام باینری (معمولاً ۰)</td></tr>
    <tr><td class="ltr">${id}</td><td>شناسه‌ی پیام دریافتی در سامانه</td></tr>
    <tr><td class="ltr">${received_at}</td><td>زمان دریافت (<span class="ltr">YYYY-MM-DD HH:MM:SS</span>)</td></tr>
  </table></div>
  <p class="ltr muted">https://example.com/sms/receive?from=${originator}&amp;to=${destination}&amp;text=${content}</p>
</div>
<div class="card">
  <h2>📬 متغیرهای گزارش تحویل (DLR)</h2>
  <div class="table-wrap"><table>
    <tr><th>متغیر</th><th>مقدار</th></tr>
    <tr><td class="ltr">${id}</td><td>شناسه‌ی عددی پیام ارسالی</td></tr>
    <tr><td class="ltr">${message_id}</td><td>شناسه‌ی کامل پیام، مثل <span class="ltr">attempt:123</span> یا <span class="ltr">bulk_item:456</span> (همان شناسه‌ی وب‌هوک‌ها)</td></tr>
    <tr><td class="ltr">${reference_id}</td><td>مرجع ارسال: در API همان <span class="ltr">client_message_id</span> شما، در ارسال گروهی شناسه‌ی کار گروهی</td></tr>
    <tr><td class="ltr">${status}</td><td>کد وضعیت به سبک وصال: ۲ تحویل شد، ۳ تحویل نشد، ۷ منقضی، ۸ رد شد، ۴ نامشخص</td></tr>
    <tr><td class="ltr">${state}</td><td>وضعیت متنی: <span class="ltr">delivered / failed / expired / rejected</span></td></tr>
    <tr><td class="ltr">${dl_time}</td><td>زمان تحویل (میلی‌ثانیه از ۱۹۷۰، مثل وصال)</td></tr>
    <tr><td class="ltr">${delivered_at}</td><td>زمان تحویل (<span class="ltr">YYYY-MM-DD HH:MM:SS</span>)</td></tr>
    <tr><td class="ltr">${part}</td><td>شماره‌ی بخش؛ همیشه ۱ (یک گزارش نهایی برای هر پیام)</td></tr>
    <tr><td class="ltr">${originator} ${destination}</td><td>خط ارسال‌کننده و شماره‌ی گیرنده</td></tr>
    <tr><td class="ltr">${provider_message_id}</td><td>شناسه‌ی پیام نزد اپراتور/درگاه</td></tr>
  </table></div>
  <p class="ltr muted">https://example.com/sms/delivery?id=${id}&amp;status=${status}&amp;dl_time=${dl_time}</p>
</div>
</div>
<div class="card">
  <h2>نکته‌ها</h2>
  <ul class="hint">
    <li>مقادیر در آدرس URL-encode می‌شوند. اگر آدرس هیچ متغیری نداشته باشد (روش GET)، همه‌ی مقادیر به‌صورت پارامتر به انتهای آدرس اضافه می‌شوند.</li>
    <li>با روش POST، آدرس به همان شکل ساخته می‌شود و همه‌ی مقادیر در بدنه (فرم یا JSON) هم فرستاده می‌شوند.</li>
    <li>هر درخواست سربرگ <span class="ltr">X-ELLSMS-Forward-Kind</span> (mo/dlr) و <span class="ltr">X-ELLSMS-Forward-Id</span> دارد؛ این شناسه در تلاش‌های دوباره ثابت است و برای حذف تکراری‌ها مناسب است.</li>
    <li>برای اطمینان از اینکه درخواست از طرف سامانه آمده، یک رمز (token) دلخواه در خود آدرس بگذارید و در سمت خود بررسی کنید.</li>
    <li>گواهی SSL مقصد به‌طور پیش‌فرض بررسی نمی‌شود (مثل وصال): آدرس https با گواهی خودامضا، منقضی یا نامعتبر هم دریافت می‌کند و ارتباط همچنان رمزنگاری‌شده است. برای بررسی سخت‌گیرانه، کلید آن را برای خط روشن کنید.</li>
    <li>آدرس‌های شبکه‌ی داخلی/خصوصی (localhost، 10.x، 192.168.x و…) پذیرفته نمی‌شوند و تغییر مسیر (redirect) دنبال نمی‌شود.</li>
    <li>کد 2xx یعنی موفق؛ قطعی اتصال، پایان زمان، 5xx و 429 دوباره امتحان می‌شوند (فاصله‌ها: <?= e(implode('، ', $retrySchedule($defaultAttempts)) ?: '—') ?>)؛ بقیه‌ی کدهای 4xx ناموفق قطعی‌اند. اگر آدرس را اصلاح کنید، تلاش‌های بعدی با آدرس جدید انجام می‌شود.</li>
  </ul>
</div>
</section>

<?php if ($isAdmin): ?>
<section class="tab-panel" data-panel="settings" role="tabpanel">
<div class="card">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <div class="form-section">
      <div class="subsection-title">وضعیت سرویس</div>
      <div class="form-grid">
        <label class="switch"><input type="checkbox" name="number_forward_enabled" value="1"<?= number_forward_enabled() ? ' checked' : '' ?>>
          <span>سرویس فعال است<span class="switch-sub">خاموش: پیام جدیدی در صف نمی‌رود و صف فعلی منتظر می‌ماند.</span></span></label>
        <label class="switch"><input type="checkbox" name="number_forward_user_edit" value="1"<?= number_forward_users_can_edit() ? ' checked' : '' ?>>
          <span>کاربران آدرس خطوط خود را تنظیم کنند<span class="switch-sub">خاموش: فقط مدیر سامانه آدرس‌ها را تنظیم می‌کند.</span></span></label>
        <label class="switch"><input type="checkbox" name="number_forward_allow_http" value="1"<?= number_forward_http_allowed() ? ' checked' : '' ?>>
          <span>آدرس http (بدون SSL) مجاز است<span class="switch-sub">خاموش: آدرس‌های http دیگر فراخوانی نمی‌شوند.</span></span></label>
      </div>
    </div>
    <div class="form-section">
      <div class="subsection-title">سرعت و ارسال موازی</div>
      <div class="form-grid">
        <label>تعداد پردازش (ترد) موازی
          <input type="number" name="number_forward_workers" min="1" max="32" value="<?= $workers ?>">
          <span class="hint">هر پردازش جدا از بقیه از صف برمی‌دارد و می‌فرستد (۱ تا ۳۲).</span></label>
        <label>ارسال هم‌زمان در هر پردازش
          <input type="number" name="number_forward_concurrency" min="1" max="100" value="<?= $concurrency ?>">
          <span class="hint">تا این تعداد درخواست هم‌زمان باز است؛ هر درخواستی تمام شود، بعدی فوراً شروع می‌شود.</span></label>
        <label>مهلت پاسخ (ثانیه)
          <input type="number" name="number_forward_timeout_seconds" min="1" max="60" value="<?= number_forward_setting_int('number_forward_timeout_seconds') ?>">
          <span class="hint">آدرس کند حداکثر همین مدت یک جای ارسال را می‌گیرد.</span></label>
      </div>
      <p class="hint">ظرفیت کل: حداکثر <b><?= to_persian_digits((string)($workers * $concurrency)) ?></b> درخواست هم‌زمان. تغییر این اعداد ظرف چند ثانیه بدون راه‌اندازی دوباره اعمال می‌شود.</p>
    </div>
    <div class="form-section">
      <div class="subsection-title">تلاش دوباره</div>
      <div class="form-grid">
        <label>حداکثر تعداد تلاش (پیش‌فرض خطوط)
          <input type="number" name="number_forward_max_attempts" min="1" max="20" value="<?= $defaultAttempts ?>">
          <span class="hint">هر خط می‌تواند عدد خودش را داشته باشد.</span></label>
        <label>فاصله‌ی اولین تلاش دوباره (ثانیه)
          <input type="number" name="number_forward_retry_base_seconds" min="5" max="3600" value="<?= number_forward_setting_int('number_forward_retry_base_seconds') ?>">
          <span class="hint">هر بار دو برابر می‌شود.</span></label>
        <label>بیشترین فاصله بین تلاش‌ها (ثانیه)
          <input type="number" name="number_forward_retry_max_seconds" min="10" max="86400" value="<?= number_forward_setting_int('number_forward_retry_max_seconds') ?>"></label>
      </div>
      <div class="fwd-schedule"><span class="hint" style="border:0;background:none;padding:0">فاصله‌ها با تنظیم فعلی:</span>
        <?php foreach ($retrySchedule($defaultAttempts) as $i => $wait): ?><span>تلاش <?= to_persian_digits((string)($i + 2)) ?>: پس از <?= e($wait) ?></span><?php endforeach; ?>
        <?php if ($defaultAttempts <= 1): ?><span>بدون تلاش دوباره</span><?php endif; ?>
      </div>
    </div>
    <div class="form-section">
      <div class="subsection-title">نگه‌داری گزارش</div>
      <div class="form-grid">
        <label>نگه‌داری گزارش (روز)
          <input type="number" name="number_forward_retention_days" min="1" max="365" value="<?= number_forward_setting_int('number_forward_retention_days') ?>"></label>
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">💾 ذخیره‌ی تنظیمات</button></div>
  </form>
</div>
</section>
<?php endif; ?>
</div>
<script>
// Placeholder chips: insert ${name} at the cursor of the URL field they belong to.
var lastFocused = null;
document.addEventListener('focusin', function (event) {
  if (event.target.classList && event.target.classList.contains('fwd-url-input')) lastFocused = event.target;
});
document.addEventListener('click', function (event) {
  var chip = event.target.closest('[data-insert]');
  if (!chip) return;
  var input = document.getElementById(chip.getAttribute('data-insert'));
  if (!input || input.disabled) return;
  var text = '${' + chip.getAttribute('data-var') + '}';
  // At the cursor when the field was being edited (the chip took focus from it), else at the end.
  var wasEditing = lastFocused === input && input.selectionStart != null;
  var start = wasEditing ? input.selectionStart : input.value.length;
  var end = wasEditing ? input.selectionEnd : input.value.length;
  input.value = input.value.slice(0, start) + text + input.value.slice(end);
  input.focus();
  input.setSelectionRange(start + text.length, start + text.length);
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
