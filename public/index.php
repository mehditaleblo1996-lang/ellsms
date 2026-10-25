<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Onboarding.php';
if (!current_user()) {
    require __DIR__ . '/landing.php';
    exit;
}
$me = require_login();
$pageTitle = 'داشبورد';
$active = 'dashboard';

$onboarding = ($me['role'] !== 'admin' && onboarding_enabled()) ? onboarding_status($me) : null;

require_once __DIR__ . '/../app/Dashboard.php';

// Everything below reads ELLSMS's own send records (bulk items + gateway send attempts); see
// app/Dashboard.php. The legacy backend's outbound_message is not used any more. Each section is a
// lazily loaded part (app/LazyParts.php): the page itself answers at once and the sections fill in.
$scopeUserIds = dashboard_scope_user_ids($me);

if (lazy_part_begin('today')) {
    $today      = dashboard_today_counts($scopeUserIds);
    $yesterday  = dashboard_yesterday_counts_so_far($scopeUserIds);
    $queued     = dashboard_queued_count($scopeUserIds);
    $pendingSch = (int)db()->query("SELECT COUNT(*) FROM ellsms_schedule WHERE status='active'" . ($me['role'] === 'admin' ? '' : ' AND user_id = ' . (int)$me['id']))->fetchColumn();
    $deliveryRate = dashboard_delivery_rate($today['delivered'], $today['sent']);
    // Against yesterday by this same time; for failures a rise is the bad direction.
    $delta = static function (int $now, int $before, bool $riseIsBad = false): string {
        $pct = dashboard_change_percent($now, $before);
        if ($pct === null) return '';
        $title = 'دیروز تا همین ساعت: ' . to_persian_digits(number_format($before));
        if ($pct === 0) return '<span class="stat-delta" title="' . e($title) . '">بدون تغییر نسبت به دیروز</span>';
        $good = ($pct > 0) !== $riseIsBad;
        return '<span class="stat-delta ' . ($good ? 'is-good' : 'is-bad') . '" title="' . e($title) . '">'
            . ($pct > 0 ? '↑' : '↓') . ' ' . to_persian_digits((string)abs($pct)) . '٪ <span class="stat-delta-note">نسبت به دیروز</span></span>';
    };
    ?>
<div class="grid grid-4">
  <div class="stat stat-accent">
    <div class="stat-label">ارسال امروز</div>
    <div class="stat-value"><?= to_persian_digits(number_format($today['sent'])) ?></div>
    <div class="stat-foot"><?= $delta($today['sent'], $yesterday['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">تحویل‌شده امروز</div>
    <div class="stat-value"><?= to_persian_digits(number_format($today['delivered'])) ?></div>
    <div class="stat-foot">
      <?php if ($deliveryRate !== null): ?><span class="stat-rate">نرخ تحویل <strong><?= to_persian_digits((string)$deliveryRate) ?>٪</strong></span><?php endif; ?>
      <?= $delta($today['delivered'], $yesterday['delivered']) ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-label">ناموفق امروز</div>
    <div class="stat-value"><?= to_persian_digits(number_format($today['failed'])) ?></div>
    <div class="stat-foot"><?= $delta($today['failed'], $yesterday['failed'], true) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">در صف ارسال</div>
    <div class="stat-value"><?= to_persian_digits(number_format($queued)) ?></div>
    <div class="stat-foot"><?php if ($pendingSch > 0): ?><span class="stat-rate"><?= to_persian_digits((string)$pendingSch) ?> زمان‌بندی فعال</span><?php endif; ?></div>
  </div>
</div>

<?php
    exit;
}

if (lazy_part_begin('chart')) {
    $days = dashboard_daily_breakdown($scopeUserIds, 7);
    $scale = dashboard_chart_scale(max(array_map(static fn(array $d): int => $d['delivered'] + $d['pending'] + $d['failed'], $days)));
    $weekdayShort = ['شنبه'=>'ش','یک‌شنبه'=>'ی','دوشنبه'=>'د','سه‌شنبه'=>'س','چهارشنبه'=>'چ','پنج‌شنبه'=>'پ','جمعه'=>'ج'];
    $todayKey = array_key_last($days);
    $percent = static fn(int $v): string => rtrim(rtrim(number_format($v / $scale['top'] * 100, 2, '.', ''), '0'), '.') . '%';
    $parts = ['delivered' => 'تحویل‌شده', 'pending' => 'در انتظار گزارش', 'failed' => 'ناموفق'];
    ?>
<div class="card dash-chart-card" style="margin-top:22px">
  <div class="dash-chart-head">
    <h2>پیامک‌های ۷ روز اخیر</h2>
    <ul class="dash-legend">
      <?php foreach ($parts as $key => $label): ?><li><span class="dash-swatch is-<?= $key ?>" aria-hidden="true"></span><?= $label ?></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="dash-chart">
    <div class="dash-grid" aria-hidden="true">
      <?php foreach ($scale['ticks'] as $tick): ?>
        <div class="dash-gridline" style="--y:<?= $percent($tick) ?>"><span><?= to_persian_digits(number_format($tick)) ?></span></div>
      <?php endforeach; ?>
    </div>
    <ol class="dash-bars">
      <?php foreach ($days as $d => $c):
          $wd = (int)date('w', strtotime($d)); // 0=Sun..6=Sat (PHP)
          $faWeekday = JALALI_WEEKDAYS[($wd + 1) % 7];
          $dayLabel = $faWeekday . ' ' . jdate_long($d) . ($d === $todayKey ? ' (امروز)' : '');
          $summary = $dayLabel . '؛ ارسال‌شده ' . to_persian_digits(number_format($c['sent']));
          foreach ($parts as $key => $label) $summary .= '، ' . $label . ' ' . to_persian_digits(number_format($c[$key]));
      ?>
        <li class="dash-bar<?= $d === $todayKey ? ' is-today' : '' ?>" tabindex="0" aria-label="<?= e($summary) ?>">
          <div class="dash-bar-track">
            <div class="dash-bar-stack">
              <?php foreach ($parts as $key => $_): if ($c[$key] > 0): ?><span class="dash-seg is-<?= $key ?>" style="--v:<?= $percent($c[$key]) ?>"></span><?php endif; endforeach; ?>
            </div>
            <div class="dash-tip" aria-hidden="true">
              <strong><?= e($dayLabel) ?></strong>
              <span class="dash-tip-row"><span>ارسال‌شده</span><b><?= to_persian_digits(number_format($c['sent'])) ?></b></span>
              <?php foreach ($parts as $key => $label): ?>
                <span class="dash-tip-row"><span><span class="dash-swatch is-<?= $key ?>"></span><?= $label ?></span><b><?= to_persian_digits(number_format($c[$key])) ?></b></span>
              <?php endforeach; ?>
            </div>
          </div>
          <span class="dash-bar-x" aria-hidden="true"><?= e($weekdayShort[$faWeekday] ?? '') ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</div>

<?php
    exit;
}

if (lazy_part_begin('recent')) {
    $recent = dashboard_recent_messages($scopeUserIds, 10);
    $usernames = is_admin() ? backend_usernames_by_ids(array_column($recent, 'user_id')) : [];
    ?>
<?= lazy_part_tab_count('recent', '(' . to_persian_digits((string)count($recent)) . ')') ?>
<div class="card">
  <h2>آخرین پیامک‌ها <a class="btn btn-sm btn-ghost" style="float:left" href="/reports.php">مشاهده‌ی گزارش کامل ←</a></h2>
  <div class="table-wrap">
  <table class="dash-recent<?= is_admin() ? ' has-user' : '' ?>">
    <caption class="sr-only">آخرین پیامک‌های ارسال‌شده</caption>
    <thead>
      <tr><?php if (is_admin()): ?><th scope="col">کاربر</th><?php endif; ?><th scope="col">گیرنده</th><th scope="col">متن پیام</th><th scope="col">نوع ارسال</th><th scope="col">وضعیت</th><th scope="col">زمان</th></tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $m): ?>
      <tr>
        <?php if (is_admin()): ?><td><?= e($usernames[$m['user_id']] ?? ('#' . $m['user_id'])) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['destination'] !== '' ? $m['destination'] : '—') ?></td>
        <td class="msg-preview" title="<?= e($m['content']) ?>"><?= e($m['content'] !== '' ? mb_strimwidth($m['content'], 0, 60, '…') : '—') ?></td>
        <td><?php if ($m['source'] === 'bulk'): ?><a href="/messages/bulk-jobs?id=<?= (int)$m['job_id'] ?>"><?= e($m['job_title'] ?: ('ارسال #' . $m['job_id'])) ?></a><?php else: ?><?= e(report_reference_type_label($m['reference_type'])) ?><?php endif; ?></td>
        <td><span class="badge badge-<?= e($m['status']['class']) ?>"><?= e($m['status']['label']) ?></span></td>
        <td class="num"><?= jdate_from_utc($m['at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="<?= is_admin() ? 6 : 5 ?>" class="empty">هنوز پیامکی ارسال نشده — از <a href="/send.php">ارسال پیامک</a> شروع کنید.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
    exit;
}

if (lazy_part_begin('jobs')) {
    $jobs = dashboard_recent_jobs($scopeUserIds, 5);
    $usernames = is_admin() ? backend_usernames_by_ids(array_column($jobs, 'user_id')) : [];
    // A bulk job is sending: the page refreshes its numbers every 30 seconds while the tab is visible.
    $hasRunning = (bool)array_filter($jobs, static fn(array $j): bool => $j['status'] === 'processing');
    ?>
<?= lazy_part_tab_count('bulk', '(' . to_persian_digits((string)count($jobs)) . ')') ?>
<?= $hasRunning ? lazy_part_refresh_marker(30) : '' ?>
<div class="card">
  <h2>ارسال‌های حجیم اخیر <a class="btn btn-sm btn-ghost" style="float:left" href="/reports-bulk.php">همه‌ی ارسال‌های حجیم ←</a></h2>
  <?php if (!$jobs): ?><p class="empty">هنوز ارسال حجیمی ثبت نشده است.</p><?php endif; ?>
  <div class="dash-jobs">
    <?php foreach ($jobs as $j):
      $total = max(1, (int)$j['total_rows']);
      $sent = (int)$j['sent_rows']; $failed = (int)$j['failed_rows'];
      $remaining = max(0, (int)$j['total_rows'] - $sent - $failed);
      $jobStatus = match ($j['status']) { 'processing' => ['در حال ارسال', 'processing'], 'pending' => ['در انتظار', 'pending'], 'done' => ['تمام‌شده', 'done'], 'cancelled' => ['لغو شده', 'cancelled'], default => [$j['status'], 'unknown'] };
    ?>
      <a class="dash-job" href="/messages/bulk-jobs?id=<?= (int)$j['id'] ?>">
        <div class="dash-job-head">
          <span class="dash-job-title"><?= e($j['title'] ?: ('ارسال #' . $j['id'])) ?><?php if (is_admin()): ?> <span class="hint">· <?= e($usernames[(int)$j['user_id']] ?? ('#' . $j['user_id'])) ?></span><?php endif; ?></span>
          <span class="badge badge-<?= e($jobStatus[1]) ?>"><?= e($jobStatus[0]) ?></span>
        </div>
        <div class="dash-progress" title="<?= to_persian_digits((string)round(($sent + $failed) / $total * 100)) ?>٪">
          <span class="dash-progress-ok" style="width:<?= round($sent / $total * 100, 2) ?>%"></span><span class="dash-progress-bad" style="width:<?= round($failed / $total * 100, 2) ?>%"></span>
        </div>
        <div class="dash-job-meta hint">
          ارسال‌شده <span class="num"><?= to_persian_digits(number_format($sent)) ?></span>
          · ناموفق <span class="num"><?= to_persian_digits(number_format($failed)) ?></span>
          · باقی‌مانده <span class="num"><?= to_persian_digits(number_format($remaining)) ?></span>
          از <span class="num"><?= to_persian_digits(number_format((int)$j['total_rows'])) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
    exit;
}

// News from the reseller(s) above this account (app/ResellerPanel.php).
$resellerAnnouncements = $me['role'] !== 'admin' ? reseller_announcements_for_customer((int)$me['id']) : [];
require __DIR__ . '/../app/views/header.php';
// Shortcuts to the everyday jobs, each only when its menu is open for this account ($nav: header.php).
$quickActions = array_filter([
    isset($nav['send'])       ? ['/send.php', 'ارسال سریع', 'send', true] : null,
    isset($nav['contacts'])   ? ['/contacts.php#add', 'افزودن مخاطب', 'users', false] : null,
    isset($nav['buy_credit']) ? ['/buy-credit.php', 'خرید اعتبار', 'wallet', false] : null,
    isset($nav['reports'])    ? ['/reports.php', 'گزارش ارسال', 'chart', false] : null,
]);
?>
<?php if ($quickActions): ?>
<nav class="dash-actions" aria-label="میانبرها">
  <?php foreach ($quickActions as [$href, $label, $icon, $primary]): ?>
    <a class="btn <?= $primary ? 'btn-primary' : 'btn-ghost' ?>" href="<?= $href ?>"><?= panel_icon($icon) ?><span><?= $label ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($resellerAnnouncements): ?>
<div class="card reseller-news">
  <h2>اطلاعیه‌های نماینده</h2>
  <?php foreach ($resellerAnnouncements as $news): ?>
    <div class="flash flash-<?= e($news['severity'] === 'error' ? 'error' : ($news['severity'] === 'success' ? 'success' : 'info')) ?>">
      <strong><?= e($news['title']) ?></strong> <span class="hint"><?= e(jdate_from_utc((string)$news['created_at'])) ?></span>
      <div style="margin-top:6px"><?= nl2br(e($news['body'])) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($onboarding && !$onboarding['complete']): ?>
<div class="card" style="border:1px solid #dfe3ff;background:linear-gradient(135deg,#fff,#f7f8ff)">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap">
    <div style="flex:1;min-width:250px">
      <div class="hint">شروع کار با داتیس رایان گستر ایرانیان</div>
      <h2 style="margin:5px 0 8px">تکمیل حساب: <?= to_persian_digits((string)$onboarding['progress']) ?>٪</h2>
      <div style="height:8px;background:#eceefa;border-radius:999px;overflow:hidden;max-width:520px">
        <div style="height:100%;width:<?= (int)$onboarding['progress'] ?>%;background:linear-gradient(90deg,#5b36f2,#315cff)"></div>
      </div>
      <p class="hint" style="margin:10px 0 0">مشخصات، احراز هویت، اعتبار و اولین ارسال را مرحله‌به‌مرحله کامل کنید.</p>
    </div>
    <a class="btn btn-primary" href="/onboarding.php">ادامه راه‌اندازی حساب</a>
  </div>
</div>
<?php endif; ?>

<?= lazy_part_placeholder('today', 110) ?>
<?= lazy_part_placeholder('chart', 200) ?>

<?php
/*
 * The two lists share one place below the chart (same tabs as the reports page, assets/js/forms.js);
 * today's numbers and the chart stay visible. The open tab is remembered for the browser session, so
 * the 30-second refresh while a bulk job is sending keeps it.
 */
?>
<div class="tabs" data-tabs="dashboard" style="margin-top:22px">
<nav class="tabs-nav" role="tablist" aria-label="فعالیت‌های اخیر">
  <button type="button" role="tab" data-tab="recent">آخرین پیامک‌ها<span class="tab-count"></span></button>
  <button type="button" role="tab" data-tab="bulk">ارسال‌های حجیم اخیر<span class="tab-count"></span></button>
</nav>

<section class="tab-panel" data-panel="recent" role="tabpanel">
<?= lazy_part_placeholder('recent', 260) ?>
</section>

<section class="tab-panel" data-panel="bulk" role="tabpanel">
<?= lazy_part_placeholder('jobs', 260) ?>
</section>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
