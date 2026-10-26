<?php
/**
 * ELLSMS — Platform Admin -> live queue monitor (app/QueueMonitor.php).
 *
 * PLATFORM ADMIN ONLY, via require_admin() — the same guard queue-cancellation.php uses. The page is
 * a light shell; its numbers are one live lazy part (?_part=live, app/LazyParts.php) re-fetched in
 * place every QUEUE_MONITOR_POLL_SECONDS while the tab is visible, from the shared cached snapshot
 * (queue_monitor_snapshot()), so an open monitor never reloads the page or queries the database
 * once per tab. Emptying a queue posts here; the page repeats the call until the queue reports done.
 */

require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'مانیتورینگ صف‌ها';
$active = 'queue_monitor';
$definitions = queue_monitor_definitions();

const QUEUE_MONITOR_CONFIRM_WORD = 'حذف';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $queue = (string)($_POST['queue'] ?? '');
    $continue = ($_POST['continue'] ?? '') === '1';
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'queue-monitor';

    if (!$continue && trim((string)($_POST['confirm'] ?? '')) !== QUEUE_MONITOR_CONFIRM_WORD) {
        $result = ['ok' => false, 'queue' => $queue, 'deleted' => 0, 'jobs_stopped' => 0, 'done' => true, 'error' => 'not_confirmed'];
    } else {
        if ($wantsJson && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // a long purge must not hold the session lock the live polls need
        }
        $result = queue_monitor_purge($queue, $me, $continue);
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$result['ok']) http_response_code(($result['error'] ?? '') === 'forbidden' ? 403 : 422);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $label = $definitions[$queue]['label'] ?? $queue;
    if (!$result['ok']) {
        flash('error', ($result['error'] ?? '') === 'not_confirmed' ? 'برای تأیید، کلمه‌ی «' . QUEUE_MONITOR_CONFIRM_WORD . '» را بنویسید.' : 'این صف قابل خالی کردن نیست.');
    } else {
        flash('success', '«' . $label . '»: ' . to_persian_digits(number_format($result['deleted'])) . ' مورد حذف شد'
            . ($result['done'] ? '.' : ' — هنوز تمام نشده؛ برای ادامه دوباره «خالی کردن» را بزنید.'));
    }
    redirect('/admin/queue/monitor');
}

if (lazy_part_begin('live')) {
    $snap = queue_monitor_snapshot();
    $queues = $snap['queues'];
    $num = static fn(?int $n): string => $n === null ? '—' : to_persian_digits(number_format($n));
    $metric = static fn(string $key, ?int $n): string => '<span data-metric="' . e($key) . '" data-value="' . ($n ?? '') . '">' . (($n === null) ? '—' : to_persian_digits(number_format($n))) . '</span>';

    $totals = ['ready' => 0, 'delayed' => 0, 'processing' => 0, 'stale' => 0];
    $oldest = null;
    foreach ($queues as $key => $q) {
        if ($q === null || $key === 'import') continue; // import rows are files, not messages
        foreach ($totals as $field => $_) $totals[$field] += (int)($q[$field] ?? 0);
        if ($q['oldest_age'] !== null) $oldest = max($oldest ?? 0, (int)$q['oldest_age']);
    }
    $bulk = $queues['bulk'] ?? null;
    $typeFa = ['p2p' => 'نظیربه‌نظیر', 'smart' => 'هوشمند', 'gradual' => 'تدریجی'];
    $statusFa = ['pending' => 'در انتظار', 'processing' => 'در حال ارسال'];
    ?>
    <div class="grid grid-4">
      <div class="stat stat-accent"><div class="stat-label">آماده‌ی ارسال در همه‌ی صف‌ها</div><div class="stat-value"><?= $metric('total.ready', $totals['ready']) ?></div></div>
      <div class="stat"><div class="stat-label">منتظر زمان یا تلاش دوباره</div><div class="stat-value"><?= $metric('total.delayed', $totals['delayed']) ?></div></div>
      <div class="stat"><div class="stat-label">در حال ارسال · ورکر فعال</div><div class="stat-value"><?= $metric('total.processing', $totals['processing']) ?> <span class="hint">· <?= $num((int)$snap['workers']) ?></span></div></div>
      <div class="stat"><div class="stat-label">قدیمی‌ترین انتظار</div><div class="stat-value<?= $oldest !== null && $oldest > 900 ? ' qm-warn-text' : '' ?>"><?= e(queue_monitor_age_label($oldest)) ?></div></div>
    </div>

    <?php if ($totals['stale'] > 0): ?>
      <div class="flash flash-error" style="margin:14px 0 0"><?= $num($totals['stale']) ?> مورد در حال ارسال بیش از زمان مجاز (lease) گیر کرده است؛ ورکر دیگری آن را دوباره برمی‌دارد. اگر عدد کم نشد وضعیت ورکرها را بررسی کنید: <bdi class="ltr" style="white-space:nowrap">make jobs-recover</bdi></div>
    <?php endif; ?>
    <?php if ($bulk !== null && $bulk['ready'] > 0 && $bulk['processing'] === 0 && ($bulk['oldest_age'] ?? 0) > 120): ?>
      <div class="flash flash-error" style="margin:14px 0 0">پیام‌های دسته‌ای آماده‌ی ارسال‌اند ولی هیچ پیامی در حال ارسال نیست — وضعیت ورکر ارسال و درگاه‌ها را بررسی کنید (ارسال‌های تدریجی عمداً با فاصله ارسال می‌شوند).</div>
    <?php endif; ?>
    <?php if ((int)($snap['draining'] ?? 0) > 0): ?>
      <div class="flash flash-info" style="margin:14px 0 0">حذف پیام‌های <?= $num((int)$snap['draining']) ?> کمپین متوقف‌شده هنوز تمام نشده — این کمپین‌ها دیگر ارسال نمی‌شوند. <button type="button" class="btn btn-sm" data-qm-continue="bulk">ادامه‌ی حذف</button></div>
    <?php endif; ?>

    <div class="card" style="margin-top:16px">
      <h2>صف‌ها</h2>
      <div class="table-wrap"><table class="qm-table">
        <thead><tr><th>صف</th><th>آماده‌ی ارسال</th><th>منتظر زمان / تلاش دوباره</th><th>در حال ارسال</th><th>گیرکرده</th><th>قدیمی‌ترین انتظار</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($definitions as $key => $def): $q = $queues[$key] ?? null; ?>
          <?php if ($q === null): ?>
            <tr><td><strong><?= e($def['label']) ?></strong></td><td colspan="6" class="muted">خواندن این صف ممکن نشد (جدول آن هنوز ساخته نشده یا خطای پایگاه داده).</td></tr>
            <?php continue; ?>
          <?php endif; ?>
          <?php $waiting = $q['ready'] + (int)$q['delayed']; ?>
          <tr<?= $q['stale'] > 0 ? ' class="qm-row-warn"' : '' ?>>
            <td><strong><?= e($def['label']) ?></strong><div class="hint"><?= e($def['description']) ?><?= $key === 'import' ? ' — تعداد فایل، نه پیام' : '' ?></div></td>
            <td class="ltr"><?= $metric($key . '.ready', $q['ready']) ?></td>
            <td class="ltr"><?= $metric($key . '.delayed', $q['delayed']) ?><?php if ($q['delayed'] === null): ?> <span class="hint" title="برای صف بزرگ‌تر از <?= e(to_persian_digits(number_format(QUEUE_MONITOR_BULK_DETAIL_LIMIT))) ?> پیام، این تفکیک برای سبک ماندن صفحه حساب نمی‌شود و در ستون «آماده» آمده است.">(بزرگ)</span><?php endif; ?></td>
            <td class="ltr"><?= $metric($key . '.processing', $q['processing']) ?></td>
            <td class="ltr"><?= $q['stale'] > 0 ? '<strong class="qm-warn-text">' . $num($q['stale']) . '</strong>' : $num(0) ?></td>
            <td><?= e(queue_monitor_age_label($q['oldest_age'])) ?></td>
            <td>
              <?php if ($def['purge']): ?>
                <button type="button" class="btn btn-sm btn-danger" data-qm-purge="<?= e($key) ?>" data-qm-label="<?= e($def['label']) ?>" data-qm-count="<?= $waiting ?>"<?= $waiting === 0 ? ' disabled' : '' ?>>خالی کردن</button>
              <?php else: ?>
                <span class="hint" title="ردیف این صف نشان‌دهنده‌ی «پاسخ داده شده» است؛ حذف آن باعث پاسخ دوباره می‌شود.">فقط نمایش</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <h2>کمپین‌های فعال <span class="hint">(<?= $num(count($snap['jobs'])) ?> کمپین با بیشترین صف)</span></h2>
      <?php if ($snap['jobs'] === []): ?>
        <p class="muted">در حال حاضر کمپین فعالی در صف نیست.</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>شناسه</th><th>عنوان</th><th>نوع</th><th>کاربر</th><th>وضعیت</th><th>در صف</th><th>در حال ارسال</th><th>پیشرفت</th></tr></thead>
        <tbody>
        <?php foreach ($snap['jobs'] as $job):
            $total = max(0, (int)$job['total_rows']);
            $done = (int)$job['sent_rows'] + (int)$job['failed_rows'];
            $pct = $total > 0 ? min(100, (int)floor($done * 100 / $total)) : 0;
        ?>
          <tr>
            <td class="ltr"><a href="/messages/bulk-jobs?id=<?= (int)$job['id'] ?>">#<?= to_persian_digits((string)$job['id']) ?></a></td>
            <td><?= e((string)$job['title'] !== '' ? (string)$job['title'] : '—') ?></td>
            <td><?= e($typeFa[(string)$job['type']] ?? (string)$job['type']) ?><?= $job['throttle_count'] !== null ? ' <span class="hint">(با فاصله)</span>' : '' ?></td>
            <td class="ltr"><?= to_persian_digits((string)$job['user_id']) ?></td>
            <td><span class="badge badge-<?= e((string)$job['status']) ?>"><?= e($statusFa[(string)$job['status']] ?? (string)$job['status']) ?></span></td>
            <td class="ltr"><?= $metric('job.' . (int)$job['id'] . '.pending', (int)$job['pending_count']) ?></td>
            <td class="ltr"><?= $num((int)$job['processing_count']) ?></td>
            <td><div class="qm-bar" title="<?= to_persian_digits((string)$pct) ?>٪"><span style="width:<?= $pct ?>%"></span></div><span class="hint"><?= $num($done) ?> / <?= $num($total) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php if ($snap['providers'] !== []): ?>
    <div class="card">
      <h2>سلامت درگاه‌ها</h2>
      <div class="qm-providers">
        <?php foreach ($snap['providers'] as $p): $h = provider_health_status_label($p['status']); ?>
          <span class="qm-provider"><span class="ltr"><?= e($p['provider_key']) ?></span> <strong style="color:<?= e($h['color']) ?>"><?= e($h['label']) ?></strong><?= $p['consecutive_failures'] > 0 ? ' <span class="hint">(' . $num($p['consecutive_failures']) . ' خطای پیاپی)</span>' : '' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <p class="hint qm-footnote">
      آخرین داده: <?= e(to_persian_digits(date('H:i:s', (int)$snap['generated_at']))) ?>
      · زمان محاسبه <?= $num((int)$snap['duration_ms']) ?> میلی‌ثانیه
      · داده تا <?= $num(queue_monitor_ttl((int)$snap['duration_ms'])) ?> ثانیه برای همه‌ی مدیران مشترک است
    </p>
    <?php
    exit;
}

require __DIR__ . '/../app/views/header.php';
?>

<div class="card qm-head">
  <div>
    <h2 style="margin-bottom:4px"><span class="qm-live-dot" aria-hidden="true"></span> وضعیت لحظه‌ای صف‌ها</h2>
    <p class="muted" style="margin:0">هر <?= to_persian_digits((string)QUEUE_MONITOR_POLL_SECONDS) ?> ثانیه، فقط وقتی این تب باز است، به‌روز می‌شود. فلش کنار اعداد تغییر نسبت به به‌روزرسانی قبلی است.</p>
  </div>
  <a class="btn btn-sm" href="/admin/queue/cancellation">لغو یک پیام / لغو بر اساس درگاه</a>
</div>

<div id="qmStatus" role="status" aria-live="polite"></div>

<?= lazy_part_live_placeholder('live', QUEUE_MONITOR_POLL_SECONDS, 420, shape: 'table') ?>

<div class="modal-overlay" id="qmPurgeOverlay" role="dialog" aria-modal="true" aria-labelledby="qmPurgeTitle">
  <div class="modal-dialog"><div class="card" style="border:2px solid var(--err)">
    <h2 id="qmPurgeTitle">خالی کردن صف</h2>
    <p id="qmPurgeText"></p>
    <ul class="muted" style="line-height:2">
      <li>پیام‌های در انتظار <strong>برای همیشه حذف</strong> می‌شوند و در گزارش‌ها هم نمی‌مانند.</li>
      <li>پیامی که همین الان در حال ارسال است دست نمی‌خورد و ارسالش تمام می‌شود.</li>
      <li>در صف دسته‌ای، کمپین‌ها متوقف و اعتبار رزروشده‌ی کاربران آزاد می‌شود.</li>
    </ul>
    <form id="qmPurgeForm" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="queue" id="qmPurgeQueue">
      <label>برای تأیید، کلمه‌ی «<?= e(QUEUE_MONITOR_CONFIRM_WORD) ?>» را بنویسید
        <input type="text" name="confirm" id="qmPurgeConfirm" autocomplete="off" required>
      </label>
      <p id="qmPurgeProgress" class="hint" role="status" aria-live="polite"></p>
      <div class="toolbar" style="margin-top:12px">
        <button type="submit" class="btn btn-danger" id="qmPurgeSubmit" disabled>حذف همه</button>
        <button type="button" class="btn" id="qmPurgeCancel">انصراف</button>
      </div>
    </form>
  </div></div>
</div>

<script>
(function () {
  'use strict';
  var CONFIRM_WORD = <?= json_encode(QUEUE_MONITOR_CONFIRM_WORD, JSON_UNESCAPED_UNICODE) ?>;
  var box = document.querySelector('[data-lazy-part="live"]');
  var overlay = document.getElementById('qmPurgeOverlay');
  var form = document.getElementById('qmPurgeForm');
  var queueInput = document.getElementById('qmPurgeQueue');
  var confirmInput = document.getElementById('qmPurgeConfirm');
  var submit = document.getElementById('qmPurgeSubmit');
  var progress = document.getElementById('qmPurgeProgress');
  var statusBox = document.getElementById('qmStatus');
  var running = false;
  var previous = {};
  var fa = function (n) { return Number(n).toLocaleString('fa-IR'); };

  // Change since the previous refresh, next to each number (a growing queue is the warning colour).
  box.addEventListener('lazy-part:loaded', function () {
    var next = {};
    box.querySelectorAll('[data-metric]').forEach(function (el) {
      var key = el.getAttribute('data-metric');
      var raw = el.getAttribute('data-value');
      if (raw === '') return;
      var value = parseInt(raw, 10);
      next[key] = value;
      if (!(key in previous) || previous[key] === value) return;
      var delta = value - previous[key];
      var mark = document.createElement('span');
      mark.className = 'qm-delta ' + (delta > 0 ? 'up' : 'down');
      mark.textContent = (delta > 0 ? '▲ ' : '▼ ') + fa(Math.abs(delta));
      el.after(mark);
    });
    previous = next;
  });

  function say(type, text) {
    statusBox.innerHTML = '';
    var div = document.createElement('div');
    div.className = 'flash flash-' + type;
    div.textContent = text;
    statusBox.appendChild(div);
  }

  function openDialog(queue, label, count) {
    queueInput.value = queue;
    confirmInput.value = '';
    submit.disabled = true;
    progress.textContent = '';
    document.getElementById('qmPurgeText').textContent =
      'همه‌ی ' + fa(count) + ' مورد در انتظار صف «' + label + '» حذف می‌شوند.';
    overlay.classList.add('is-open');
    confirmInput.focus();
  }
  function closeDialog() { if (!running) overlay.classList.remove('is-open'); }

  box.addEventListener('click', function (event) {
    var purge = event.target.closest('[data-qm-purge]');
    if (purge && !purge.disabled) {
      openDialog(purge.getAttribute('data-qm-purge'), purge.getAttribute('data-qm-label'), parseInt(purge.getAttribute('data-qm-count'), 10) || 0);
      return;
    }
    var cont = event.target.closest('[data-qm-continue]');
    if (cont && !running) {
      cont.disabled = true;
      run(cont.getAttribute('data-qm-continue'), 'کمپین‌های متوقف‌شده', true);
    }
  });
  document.getElementById('qmPurgeCancel').addEventListener('click', closeDialog);
  overlay.addEventListener('click', function (event) { if (event.target === overlay) closeDialog(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeDialog(); });
  confirmInput.addEventListener('input', function () { submit.disabled = confirmInput.value.trim() !== CONFIRM_WORD; });

  // One purge call deletes for a few seconds and answers; repeat until the server reports done.
  function run(queue, label, continuing) {
    running = true;
    var deleted = 0;
    var csrf = form.querySelector('[name="_csrf"]').value;
    var step = function (isContinue) {
      var body = new URLSearchParams();
      body.set('_csrf', csrf);
      body.set('queue', queue);
      if (isContinue) body.set('continue', '1');
      else body.set('confirm', confirmInput.value.trim());
      return fetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', body: body,
        headers: { 'X-Requested-With': 'queue-monitor', 'Accept': 'application/json' }
      }).then(function (response) {
        return response.json().catch(function () { throw new Error('HTTP ' + response.status); });
      }).then(function (result) {
        if (!result.ok) throw new Error(result.error || 'failed');
        deleted += result.deleted;
        progress.textContent = 'تا الان ' + fa(deleted) + ' مورد حذف شد…';
        box.dispatchEvent(new CustomEvent('lazy-part:refresh'));
        return result.done ? result : step(true);
      });
    };
    submit.disabled = true;
    step(continuing).then(function () {
      running = false;
      overlay.classList.remove('is-open');
      say('success', '«' + label + '» خالی شد: ' + fa(deleted) + ' مورد حذف شد.');
    }).catch(function (error) {
      running = false;
      overlay.classList.remove('is-open');
      say('error', 'خالی کردن «' + label + '» کامل نشد (' + error.message + '). ' + fa(deleted) + ' مورد حذف شد؛ دوباره تلاش کنید.');
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (running || confirmInput.value.trim() !== CONFIRM_WORD) return;
    var button = box.querySelector('[data-qm-purge="' + queueInput.value + '"]');
    run(queueInput.value, button ? button.getAttribute('data-qm-label') : queueInput.value, false);
  });
})();
</script>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
