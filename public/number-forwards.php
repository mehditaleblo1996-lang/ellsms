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

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ارسال پیامک‌های دریافتی و گزارش تحویل به آدرس شما</h2>
  <p class="hint">
    برای هر خط می‌توانید یک آدرس <b>http</b> یا <b>https</b> بدهید تا سامانه هر <b>پیامک دریافتی</b> روی آن خط (MO) و
    <b>گزارش تحویل نهایی</b> هر پیامکی که از آن خط فرستاده شده (Delivery Report) را بلافاصله به آن آدرس بفرستد.
    مقادیر را به شکل متغیر (مثل <span class="ltr">${content}</span>) در آدرس بگذارید (فهرست در زبانه‌ی «راهنما»)؛ قالب همان قالب سامانه‌ی وصال است و آدرس‌های قبلی بدون تغییر کار می‌کنند.
    پاسخ <b>2xx</b> یعنی دریافت شد؛ در صورت قطعی، کد 5xx یا 429، ارسال چند بار با فاصله تکرار می‌شود.
  </p>
  <?php if (!number_forward_enabled()): ?>
    <p class="hint" style="color:var(--danger,#b00)"><b>این سرویس اکنون توسط مدیر سامانه خاموش است؛ چیزی ارسال نمی‌شود و پیام‌ها در صف می‌مانند.</b></p>
  <?php endif; ?>
  <?php if (!$isAdmin && !number_forward_users_can_edit()): ?>
    <p class="hint">تنظیم آدرس‌ها فعلاً فقط توسط مدیر سامانه انجام می‌شود؛ برای تغییر با پشتیبانی تماس بگیرید.</p>
  <?php endif; ?>
  <div class="toolbar">
    <?php foreach ($statusLabels as $key => [$label, $badge]): ?>
      <a class="badge <?= $badge ?>" href="/number-forwards.php?status=<?= e($key) ?>#log"><?= e($label) ?>: <?= to_persian_digits((string)$counts[$key]) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="tabs" data-tabs="number-forwards">
<nav class="tabs-nav" role="tablist" aria-label="ارسال به URL">
  <button type="button" role="tab" data-tab="lines">خطوط<span class="tab-count">(<?= to_persian_digits((string)count($lines)) ?>)</span></button>
  <button type="button" role="tab" data-tab="log">گزارش ارسال‌ها<span class="tab-count">(<?= to_persian_digits((string)$total) ?>)</span></button>
  <button type="button" role="tab" data-tab="help">راهنما</button>
  <?php if ($isAdmin): ?><button type="button" role="tab" data-tab="settings">تنظیمات سرویس</button><?php endif; ?>
</nav>

<section class="tab-panel" data-panel="lines" role="tabpanel">
<?php if ($edit !== ''): ?>
<?php $v = static fn(string $k, string $d = '') => (string)($editRow[$k] ?? $d); ?>
<div class="card">
  <h2>تنظیم خط <span class="msisdn"><?= e($edit) ?></span><?= isset($lines[$edit]) && $lines[$edit] !== '' ? ' — ' . e($lines[$edit]) : '' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="number" value="<?= e($edit) ?>">
    <fieldset<?= $canEdit ? '' : ' disabled' ?> style="border:0;padding:0;margin:0">
    <label style="display:block;margin-bottom:6px"><input type="checkbox" name="mo_enabled" value="1"<?= $v('mo_enabled') === '1' ? ' checked' : '' ?>> <b>ارسال پیامک‌های دریافتی (MO)</b></label>
    <input type="text" name="mo_url" class="ltr" style="width:100%" maxlength="<?= NUMBER_FORWARD_MAX_URL_LENGTH ?>" value="<?= e($v('mo_url')) ?>"
           placeholder="https://example.com/sms/receive?from=${originator}&amp;to=${destination}&amp;text=${content}">
    <label style="display:block;margin:14px 0 6px"><input type="checkbox" name="dlr_enabled" value="1"<?= $v('dlr_enabled') === '1' ? ' checked' : '' ?>> <b>ارسال گزارش تحویل (Delivery Report)</b></label>
    <input type="text" name="dlr_url" class="ltr" style="width:100%" maxlength="<?= NUMBER_FORWARD_MAX_URL_LENGTH ?>" value="<?= e($v('dlr_url')) ?>"
           placeholder="https://example.com/sms/delivery?id=${id}&amp;status=${status}&amp;dl_time=${dl_time}">
    <div class="toolbar" style="margin-top:14px">
      <label>روش
        <select name="http_method">
          <option value="GET"<?= $v('http_method', 'GET') === 'GET' ? ' selected' : '' ?>>GET (مقادیر فقط در آدرس)</option>
          <option value="POST"<?= $v('http_method') === 'POST' ? ' selected' : '' ?>>POST (مقادیر در بدنه هم فرستاده می‌شوند)</option>
        </select>
      </label>
      <label>قالب بدنه‌ی POST
        <select name="post_format">
          <option value="form"<?= $v('post_format', 'form') === 'form' ? ' selected' : '' ?>>فرم (x-www-form-urlencoded)</option>
          <option value="json"<?= $v('post_format') === 'json' ? ' selected' : '' ?>>JSON</option>
        </select>
      </label>
      <label title="خاموش (پیش‌فرض): مثل وصال، به آدرس https با گواهی خودامضا، منقضی یا نامعتبر هم ارسال می‌شود.">
        <input type="checkbox" name="tls_verify" value="1"<?= $v('tls_verify') === '1' ? ' checked' : '' ?>> بررسی گواهی SSL مقصد (سخت‌گیرانه)
      </label>
      <button class="btn btn-primary">ذخیره</button>
      <a class="btn" href="/number-forwards.php#lines">بستن</a>
    </div>
    </fieldset>
  </form>
  <?php if ($editRow && $canEdit): ?>
  <div class="toolbar" style="margin-top:10px">
    <?php foreach (['mo' => 'آزمایش آدرس دریافت', 'dlr' => 'آزمایش آدرس گزارش تحویل'] as $kind => $label): if ($v($kind . '_url') === '') continue; ?>
      <form method="post" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="do" value="test_<?= $kind ?>"><input type="hidden" name="number" value="<?= e($edit) ?>">
        <button class="btn btn-sm"><?= e($label) ?></button>
      </form>
    <?php endforeach; ?>
    <form method="post" style="margin:0" onsubmit="return confirm('ارسال به آدرس برای این خط حذف شود؟')">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="number" value="<?= e($edit) ?>">
      <button class="btn btn-sm btn-danger">حذف تنظیمات این خط</button>
    </form>
  </div>
  <p class="muted">«آزمایش» یک پیام نمونه (با شناسه‌ی ۰) را همین حالا به آدرس ذخیره‌شده می‌فرستد، حتی اگر تیک آن روشن نباشد.</p>
  <?php endif; ?>
  <?php if ($editRow && (int)$editRow['consecutive_failures'] > 0): ?>
    <p class="muted">آخرین خطا: <?= e(number_forward_reason_text((string)$editRow['last_error'])) ?> — <?= jdate((string)$editRow['last_failure_at']) ?>
      (<?= to_persian_digits((string)$editRow['consecutive_failures']) ?> خطای پشت سر هم)</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>خطوط</h2>
  <?php if ($isAdmin): ?>
  <form method="get" class="toolbar">
    <label>تنظیم یک خط دیگر <input type="text" name="edit" class="ltr" placeholder="5000123" required></label>
    <button class="btn">باز کردن</button>
  </form>
  <?php endif; ?>
  <div class="table-wrap">
  <table>
    <tr><th>خط</th><th>برچسب</th><th>پیامک دریافتی</th><th>گزارش تحویل</th><th>روش</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($lines as $number => $label): $number = (string)$number; $c = $configs[$number] ?? null; ?>
      <tr>
        <td class="msisdn"><?= e($number) ?></td>
        <td><?= e($label) ?></td>
        <?php foreach (NUMBER_FORWARD_KINDS as $kind): ?>
          <td>
            <?php if ($c && $c[$kind . '_url'] !== ''): ?>
              <span class="badge <?= (int)$c[$kind . '_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= (int)$c[$kind . '_enabled'] ? 'روشن' : 'خاموش' ?></span>
              <div class="ltr muted" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($c[$kind . '_url']) ?>"><?= e($c[$kind . '_url']) ?></div>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td><?= $c ? e($c['http_method'] . ($c['http_method'] === 'POST' ? ' / ' . $c['post_format'] : '')) . ((int)($c['tls_verify'] ?? 0) ? '<div class="muted">بررسی SSL</div>' : '') : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if ($c && (int)$c['consecutive_failures'] >= NUMBER_FORWARD_WARN_FAILURES): ?>
            <span style="color:var(--danger,#b00)">⚠ <?= to_persian_digits((string)$c['consecutive_failures']) ?> خطای پشت سر هم</span>
          <?php elseif ($c && $c['last_success_at']): ?>
            <span class="muted">آخرین موفق: <?= jdate((string)$c['last_success_at']) ?></span>
          <?php elseif ($c && $c['last_failure_at']): ?>
            <span class="muted">آخرین خطا: <?= jdate((string)$c['last_failure_at']) ?></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><a class="btn btn-sm" href="/number-forwards.php?edit=<?= e(rawurlencode($number)) ?>#lines"><?= $canEdit ? 'تنظیم' : 'مشاهده' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lines): ?><tr><td colspan="7" class="empty"><?= $isAdmin ? 'هنوز شماره‌ای در «شماره‌ها» ثبت نشده.' : 'هنوز خطی به حساب شما تخصیص داده نشده است.' ?></td></tr><?php endif; ?>
  </table>
  </div>
</div>
</section>

<section class="tab-panel" data-panel="log" role="tabpanel">
<div class="card">
  <h2>گزارش ارسال‌ها</h2>
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
  </form>
  <?php if ($filterLine !== '' && ($isAdmin || in_array($filterLine, $lineNumbers, true))): ?>
    <form method="post" class="toolbar" onsubmit="return confirm('همه‌ی موارد ناموفق این خط دوباره ارسال شوند؟')">
      <?= csrf_field() ?><input type="hidden" name="do" value="requeue"><input type="hidden" name="number" value="<?= e($filterLine) ?>">
      <button class="btn btn-sm">ارسال دوباره‌ی همه‌ی موارد ناموفق این خط</button>
    </form>
  <?php endif; ?>
  <div class="table-wrap">
  <table>
    <tr><th>زمان</th><th>نوع</th><th>خط</th><th>پیام</th><th>وضعیت</th><th>تلاش</th><th>پاسخ</th><th>آدرس فراخوانی‌شده</th><th></th></tr>
    <?php foreach ($logRows as $r): [$statusLabel, $badge] = $statusLabels[$r['status']] ?? [$r['status'], 'badge-unknown']; ?>
      <tr>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td><?= $r['kind'] === 'mo' ? 'MO' : 'DLR' ?></td>
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
              <button class="btn btn-sm">ارسال دوباره</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logRows): ?><tr><td colspan="9" class="empty">موردی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($total, $per), array_filter(['line' => $filterLine, 'kind' => $filterKind, 'status' => $filterStatus], 'strlen'), 'page', $total, 'log') ?>
  <p class="muted">موارد تمام‌شده پس از <?= to_persian_digits((string)number_forward_setting_int('number_forward_retention_days')) ?> روز از این گزارش پاک می‌شوند.</p>
</div>
</section>

<section class="tab-panel" data-panel="help" role="tabpanel">
<div class="card">
  <h2>متغیرهای پیامک دریافتی (MO)</h2>
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
  <h2>متغیرهای گزارش تحویل (DLR)</h2>
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
<div class="card">
  <h2>نکته‌ها</h2>
  <ul class="hint">
    <li>مقادیر در آدرس URL-encode می‌شوند. اگر آدرس هیچ متغیری نداشته باشد (روش GET)، همه‌ی مقادیر به‌صورت پارامتر به انتهای آدرس اضافه می‌شوند.</li>
    <li>با روش POST، آدرس به همان شکل ساخته می‌شود و همه‌ی مقادیر در بدنه (فرم یا JSON) هم فرستاده می‌شوند.</li>
    <li>هر درخواست سربرگ <span class="ltr">X-ELLSMS-Forward-Kind</span> (mo/dlr) و <span class="ltr">X-ELLSMS-Forward-Id</span> دارد؛ این شناسه در تلاش‌های دوباره ثابت است و برای حذف تکراری‌ها مناسب است.</li>
    <li>برای اطمینان از اینکه درخواست از طرف سامانه آمده، یک رمز (token) دلخواه در خود آدرس بگذارید و در سمت خود بررسی کنید.</li>
    <li>گواهی SSL مقصد به‌طور پیش‌فرض بررسی نمی‌شود (مثل وصال): آدرس https با گواهی خودامضا، منقضی یا نامعتبر هم دریافت می‌کند و ارتباط همچنان رمزنگاری‌شده است. برای بررسی سخت‌گیرانه، «بررسی گواهی SSL» را برای خط روشن کنید.</li>
    <li>آدرس‌های شبکه‌ی داخلی/خصوصی (localhost، 10.x، 192.168.x و…) پذیرفته نمی‌شوند و تغییر مسیر (redirect) دنبال نمی‌شود.</li>
    <li>کد 2xx یعنی موفق؛ قطعی اتصال، پایان زمان، 5xx و 429 تا <?= to_persian_digits((string)number_forward_setting_int('number_forward_max_attempts')) ?> بار با فاصله‌ی فزاینده دوباره امتحان می‌شوند؛ بقیه‌ی کدهای 4xx ناموفق قطعی‌اند. اگر آدرس را اصلاح کنید، تلاش‌های بعدی با آدرس جدید انجام می‌شود.</li>
  </ul>
</div>
</section>

<?php if ($isAdmin): ?>
<section class="tab-panel" data-panel="settings" role="tabpanel">
<div class="card">
  <h2>تنظیمات سرویس</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <div class="toolbar">
      <label><input type="checkbox" name="number_forward_enabled" value="1"<?= number_forward_enabled() ? ' checked' : '' ?>> سرویس فعال است</label>
      <label><input type="checkbox" name="number_forward_user_edit" value="1"<?= number_forward_users_can_edit() ? ' checked' : '' ?>> کاربران آدرس خطوط خود را تنظیم کنند</label>
      <label><input type="checkbox" name="number_forward_allow_http" value="1"<?= number_forward_http_allowed() ? ' checked' : '' ?>> آدرس http (بدون SSL) مجاز است</label>
    </div>
    <div class="toolbar">
      <label>مهلت پاسخ (ثانیه) <input type="number" name="number_forward_timeout_seconds" min="1" max="60" value="<?= number_forward_setting_int('number_forward_timeout_seconds') ?>"></label>
      <label>حداکثر تلاش <input type="number" name="number_forward_max_attempts" min="1" max="20" value="<?= number_forward_setting_int('number_forward_max_attempts') ?>"></label>
      <label>درخواست هم‌زمان <input type="number" name="number_forward_concurrency" min="1" max="50" value="<?= number_forward_setting_int('number_forward_concurrency') ?>"></label>
      <label>نگه‌داری گزارش (روز) <input type="number" name="number_forward_retention_days" min="1" max="365" value="<?= number_forward_setting_int('number_forward_retention_days') ?>"></label>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <p class="hint">
    خاموش کردن سرویس چیزی را پاک نمی‌کند: پیام‌های جدید دیگر در صف قرار نمی‌گیرند و صف فعلی تا روشن شدن دوباره منتظر می‌ماند.
    ارسال‌ها را سرویس جداگانه‌ی <span class="ltr">number-forward-worker</span> انجام می‌دهد (<span class="ltr">php cron/number-forward-worker.php</span>)،
    پس آدرس کند یا قطع یک مشتری هرگز ارسال پیامک، منشی یا گزارش‌گیری را کند نمی‌کند. تغییر تنظیمات ظرف چند ثانیه روی آن اعمال می‌شود.
    <br>اگر آدرس http بسته شود، آدرس‌های http ذخیره‌شده دیگر فراخوانی نمی‌شوند (ناموفق ثبت می‌شوند) تا به https تغییر کنند.
  </p>
</div>
</section>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
