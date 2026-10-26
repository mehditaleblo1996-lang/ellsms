<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reports/OperatorReport.php';
$me = require_login();

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

/*
 * Three views of the same report (app/Reports/OperatorReport.php), each its own sidebar entry:
 *   all    — every message in the range: operator share, encoding, status
 *   number — the status table for one sender line
 *   route  — the status table for one route (admins only: routes are platform configuration)
 * A customer sees only the two summary columns of the status table (مجموع ارسال‌شده / تحویل گرفته‌شده).
 */
$admin = is_admin();
$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'number', 'route'], true) || ($view === 'route' && !$admin)) $view = 'all';
[$pageTitle, $active] = match ($view) {
    'number' => ['گزارش اپراتورها بر اساس شماره', 'operator_reports_number'],
    'route'  => ['گزارش اپراتورها بر اساس مسیر', 'operator_reports_route'],
    default  => ['گزارش اپراتورها', 'operator_reports'],
};

$from = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to   = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
[$fromUtc, $toUtcExclusive] = local_day_range_to_utc($from, $to);
$userId = $admin ? (int)($_GET['user_id'] ?? 0) : 0;
$senderRaw = $view === 'number' ? trim((string)($_GET['sender'] ?? '')) : '';
$sender = $senderRaw !== '' ? normalize_originator($senderRaw) : null;
$routeId = $view === 'route' ? (int)($_GET['route_id'] ?? 0) : 0;

if (lazy_part_begin('report')) {
    $counts = operator_report_counts(operator_report_scope($me, $admin, $userId), $fromUtc, $toUtcExclusive, $sender, $routeId, hidden_messages_apply_to_viewer());
    $operators = operator_report_operator_order($counts);
    $statusByOp = [];
    $derivedByOp = [];
    foreach ($operators as $op) {
        $statusByOp[$op] = operator_report_statuses($counts[$op] ?? []);
        $derivedByOp[$op] = operator_report_derive($statusByOp[$op]);
    }
    $statusTotal = operator_report_statuses($statusByOp);
    $derivedTotal = operator_report_derive($statusTotal);
    $grandTotal = $derivedTotal['total_sent'];
    $opColor = [];
    foreach (array_values($operators) as $i => $op) $opColor[$op] = OPERATOR_REPORT_PALETTE[$i % count(OPERATOR_REPORT_PALETTE)];
    $withData = array_values(array_filter($operators, static fn(string $op): bool => array_sum($statusByOp[$op]) > 0));
    $opCell = static fn(string $op): string => $op === OPERATOR_REPORT_UNKNOWN ? e('نامشخص') : operator_label($op, $op);

    if ($view === 'all'):
        // Messages that went out, per encoding, per operator (in queue / cancelled never reached it).
        $encByOp = [];
        $encTotal = array_fill_keys(array_keys(OPERATOR_REPORT_ENCODINGS), 0);
        foreach ($operators as $op) {
            foreach (array_keys(OPERATOR_REPORT_ENCODINGS) as $enc) {
                $encByOp[$op][$enc] = operator_report_derive($counts[$op][$enc] ?? [])['total_sent'];
                $encTotal[$enc] += $encByOp[$op][$enc];
            }
        }
        $encodings = array_keys(OPERATOR_REPORT_ENCODINGS);
        if ($encTotal['na'] === 0) $encodings = ['fa', 'en'];
        ?>
<div class="card op-report-card">
  <h2>سهم هر اپراتور از پیام‌های ارسالی</h2>
  <p class="hint">درصد پیام‌های ارسال‌شده به هر اپراتور در این بازه (پیام‌های در صف و لغوشده حساب نشده‌اند).</p>
  <div class="op-split">
    <?= operator_report_donut(array_map(static fn(string $op): array => [
        'label' => $op === OPERATOR_REPORT_UNKNOWN ? 'نامشخص' : $op, 'value' => $derivedByOp[$op]['total_sent'], 'color' => $opColor[$op],
    ], $operators), 'میزان ارسال هر اپراتور') ?>
    <div class="table-wrap"><table class="op-table">
      <tr><th>اپراتور</th><th>تعداد پیام‌های ارسالی</th><th>سهم</th></tr>
      <?php foreach ($operators as $op): ?>
      <tr><td><?= $opCell($op) ?></td><td class="num"><?= operator_report_num($derivedByOp[$op]['total_sent']) ?></td><td class="num"><?= to_persian_digits((string)operator_report_percent($derivedByOp[$op]['total_sent'], $grandTotal)) ?>٪</td></tr>
      <?php endforeach; ?>
      <tr class="op-total"><td>جمع کل</td><td class="num"><?= operator_report_num($grandTotal) ?></td><td class="num"><?= $grandTotal > 0 ? '۱۰۰٪' : '۰٪' ?></td></tr>
    </table></div>
  </div>
</div>

<div class="card op-report-card">
  <h2>پیام‌های ارسالی به تفکیک اپراتور و زبان پیام</h2>
  <p class="hint">فارسی: پیام‌هایی که نویسه‌ی غیرلاتین دارند (کدگذاری یونیکد)؛ انگلیسی: پیام‌های تمام‌لاتین.</p>
  <?php if ($withData): ?>
  <div class="op-donut-grid">
    <?php foreach ($withData as $op): ?>
      <?= operator_report_donut(array_map(static fn(string $enc): array => [
          'label' => OPERATOR_REPORT_ENCODINGS[$enc], 'value' => $encByOp[$op][$enc], 'color' => OPERATOR_REPORT_ENCODING_COLORS[$enc],
      ], $encodings), $op === OPERATOR_REPORT_UNKNOWN ? 'نامشخص' : $op, 'sm') ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="table-wrap"><table class="op-table">
    <tr><th>اپراتور</th><?php foreach ($encodings as $enc): ?><th><?= e(OPERATOR_REPORT_ENCODINGS[$enc]) ?></th><?php endforeach; ?><th>همه</th></tr>
    <?php foreach ($operators as $op): ?>
    <tr><td><?= $opCell($op) ?></td><?php foreach ($encodings as $enc): ?><td class="num"><?= operator_report_num($encByOp[$op][$enc]) ?></td><?php endforeach; ?><td class="num"><?= operator_report_num($derivedByOp[$op]['total_sent']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="op-total"><td>جمع کل</td><?php foreach ($encodings as $enc): ?><td class="num"><?= operator_report_num($encTotal[$enc]) ?></td><?php endforeach; ?><td class="num"><?= operator_report_num($grandTotal) ?></td></tr>
  </table></div>
</div>
<?php
    endif;

    $statusTitle = match ($view) {
        'number' => 'وضعیت پیام‌های ارسالی' . ($sender !== null ? ' از شماره‌ی ' . $sender : '') . ' به تفکیک اپراتور',
        'route'  => 'وضعیت پیام‌های ارسالی' . ($routeId > 0 ? ' از این مسیر' : '') . ' به تفکیک اپراتور',
        default  => 'وضعیت پیام‌های ارسالی به تفکیک اپراتور',
    };
    ?>
<div class="card op-report-card">
  <h2><?= e($statusTitle) ?></h2>
  <?php if ($admin): ?>
  <?php if ($withData): ?>
  <div class="op-donut-grid">
    <?php foreach ($withData as $op): ?>
      <?= operator_report_donut(array_values(array_filter(array_map(static fn(string $st): array => [
          'label' => OPERATOR_REPORT_STATUSES[$st], 'value' => $statusByOp[$op][$st], 'color' => OPERATOR_REPORT_STATUS_COLORS[$st],
      ], array_keys(OPERATOR_REPORT_STATUSES)), static fn(array $s): bool => $s['value'] > 0)), $op === OPERATOR_REPORT_UNKNOWN ? 'نامشخص' : $op, 'sm') ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="table-wrap"><table class="op-table op-status-table">
    <tr>
      <th>اپراتور</th>
      <th><?= e(OPERATOR_REPORT_STATUSES['queued']) ?></th>
      <th title="ارسال‌شده‌هایی که هنوز گزارش تحویل ندارند"><?= e(OPERATOR_REPORT_STATUSES['sent']) ?></th>
      <th class="op-key" title="همه‌ی پیام‌هایی که به اپراتور ارسال شده‌اند">مجموع ارسال‌شده</th>
      <th title="فقط پیام‌هایی که تحویلشان تأیید شده"><?= e(OPERATOR_REPORT_STATUSES['delivered']) ?></th>
      <th class="op-key" title="تحویل‌شده + ارسال‌شده‌ی بدون گزارش">مجموع تحویل گرفته‌شده</th>
      <?php foreach (['undelivered', 'expired', 'rejected', 'cancelled', 'unknown'] as $st): ?><th><?= e(OPERATOR_REPORT_STATUSES[$st]) ?></th><?php endforeach; ?>
    </tr>
    <?php
    $statusRow = static function (string $label, array $s, array $d, bool $total = false): string {
        $cells = '<td class="num">' . operator_report_num($s['queued']) . '</td>'
            . '<td class="num">' . operator_report_num($s['sent']) . '</td>'
            . '<td class="num op-key">' . operator_report_num($d['total_sent']) . '</td>'
            . '<td class="num">' . operator_report_num($s['delivered']) . '</td>'
            . '<td class="num op-key">' . operator_report_num($d['received']) . '</td>';
        foreach (['undelivered', 'expired', 'rejected', 'cancelled', 'unknown'] as $st) $cells .= '<td class="num">' . operator_report_num($s[$st]) . '</td>';
        return '<tr' . ($total ? ' class="op-total"' : '') . '><td>' . $label . '</td>' . $cells . '</tr>';
    };
    foreach ($operators as $op) echo $statusRow($opCell($op), $statusByOp[$op], $derivedByOp[$op]);
    echo $statusRow('جمع کل', $statusTotal, $derivedTotal, true);
    ?>
  </table></div>
  <p class="hint op-note"><span class="op-key-chip"></span> ستون‌های رنگی همان دو ستونی‌اند که مشتری در پنل خود می‌بیند: «مجموع ارسال‌شده» همه‌ی پیام‌های رفته به اپراتور است و «مجموع تحویل گرفته‌شده» تحویل‌شده‌ها به‌علاوه‌ی ارسال‌شده‌هایی که هنوز گزارش تحویل ندارند. ستون «ارسال‌شده» فقط پیام‌هایی را نشان می‌دهد که در وضعیت ارسال مانده‌اند و «تحویل گرفته‌شده» فقط تحویل‌های تأییدشده را.</p>

  <?php if ($view === 'all' && $withData): ?>
  <h3 class="op-sub">جزئیات هر اپراتور به تفکیک زبان پیام</h3>
  <?php foreach ($withData as $op): ?>
  <details class="op-detail">
    <summary><?= $opCell($op) ?> <span class="hint">— <?= operator_report_num($derivedByOp[$op]['total_sent']) ?> پیام ارسالی</span></summary>
    <div class="table-wrap"><table class="op-table op-status-table">
      <tr>
        <th>زبان پیام</th><th><?= e(OPERATOR_REPORT_STATUSES['queued']) ?></th><th><?= e(OPERATOR_REPORT_STATUSES['sent']) ?></th><th class="op-key">مجموع ارسال‌شده</th>
        <th><?= e(OPERATOR_REPORT_STATUSES['delivered']) ?></th><th class="op-key">مجموع تحویل گرفته‌شده</th>
        <?php foreach (['undelivered', 'expired', 'rejected', 'cancelled', 'unknown'] as $st): ?><th><?= e(OPERATOR_REPORT_STATUSES[$st]) ?></th><?php endforeach; ?>
      </tr>
      <?php foreach (array_keys(OPERATOR_REPORT_ENCODINGS) as $enc):
          if (!isset($counts[$op][$enc])) continue;
          $s = array_merge(array_fill_keys(array_keys(OPERATOR_REPORT_STATUSES), 0), $counts[$op][$enc]);
          echo $statusRow(e(OPERATOR_REPORT_ENCODINGS[$enc]), $s, operator_report_derive($s));
      endforeach; ?>
    </table></div>
  </details>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php else: ?>
  <div class="table-wrap"><table class="op-table op-status-table">
    <tr><th>اپراتور</th><th class="op-key">مجموع ارسال‌شده</th><th class="op-key">تحویل گرفته‌شده</th></tr>
    <?php foreach ($operators as $op): ?>
    <tr><td><?= $opCell($op) ?></td><td class="num op-key"><?= operator_report_num($derivedByOp[$op]['total_sent']) ?></td><td class="num op-key"><?= operator_report_num($derivedByOp[$op]['received']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="op-total"><td>جمع کل</td><td class="num op-key"><?= operator_report_num($derivedTotal['total_sent']) ?></td><td class="num op-key"><?= operator_report_num($derivedTotal['received']) ?></td></tr>
  </table></div>
  <?php endif; ?>
</div>
<?php
    exit;
}

$users = $admin ? backend_list_users_summary() : [];
$routes = [];
if ($view === 'route') {
    try {
        $routes = db()->query("SELECT r.id, r.name, p.name provider FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id
                               ORDER BY r.status = 'active' DESC, p.name, r.name")->fetchAll();
    } catch (Throwable) {
        // pricing catalog not migrated: no routes to pick
    }
}
$myLines = (!$admin && $view === 'number') ? array_values(array_filter(allowed_originators($me), static fn($n) => $n !== '*')) : [];
$extraStyles = ['/assets/css/operator-reports.css'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="op-views">
  <?php foreach (array_filter([
      'all'    => ['گزارش اپراتورها', true],
      'number' => ['بر اساس شماره', true],
      'route'  => ['بر اساس مسیر', $admin],
  ], static fn(array $v): bool => $v[1]) as $key => [$label]): ?>
    <?php if ($key === $view): ?><span class="btn btn-primary" aria-current="page"><?= e($label) ?></span>
    <?php else: ?><a class="btn btn-ghost" href="/operator-reports.php?<?= e(http_build_query(array_filter(['view' => $key === 'all' ? null : $key, 'user_id' => $userId ?: null]))) ?>"><?= e($label) ?></a><?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <?php if ($view !== 'all'): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
    <?php if ($admin): ?>
      <label>نام مشتری
        <select name="user_id"><option value="0">همه</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <?php if ($view === 'number'): ?>
      <label>شماره
        <input type="text" name="sender" value="<?= e($senderRaw) ?>" class="ltr" inputmode="numeric" placeholder="مثلاً 500045"<?= $myLines ? ' list="opLines"' : '' ?>>
        <?php if ($myLines): ?><datalist id="opLines"><?php foreach ($myLines as $line): ?><option value="<?= e((string)$line) ?>"><?php endforeach; ?></datalist><?php endif; ?>
      </label>
    <?php endif; ?>
    <?php if ($view === 'route'): ?>
      <label>مسیر
        <select name="route_id"><option value="0">همه‌ی مسیرها</option>
          <?php foreach ($routes as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $routeId === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['provider'] . ' — ' . $r['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <button class="btn btn-primary">جست‌وجو</button>
  </form>
  <?php if ($view === 'number' && $sender === null): ?><p class="hint">شماره‌ای انتخاب نشده؛ همه‌ی شماره‌ها نمایش داده می‌شوند.</p><?php endif; ?>
  <?php if ($view === 'route'): ?><p class="hint">ارسال‌های قدیمی از طریق API بک‌اند مسیر ندارند؛ با انتخاب یک مسیر در این گزارش حساب نمی‌شوند.</p><?php endif; ?>
</div>

<?= lazy_part_placeholder('report', 420, 'در حال آماده‌سازی گزارش…') ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
