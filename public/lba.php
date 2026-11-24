<?php
/**
 * ارسال پیام منطقه‌ای (LBA) — location-based sending through the LBA gateway of the chosen line
 * (app/Lba.php, docs/lba.md). Everything the provider offers is here:
 *   - audiences of every kind (simple, welcome, cell list, club, city, province, airport, periodic),
 *     with the map polygon editor and every filter of the base model;
 *   - gray lists (campaigns) and clubs;
 *   - one-off sends, periodic sends (percent / count) with editing and on/off;
 *   - statistics (periodic, daily, financial, per send) and the polygon coverage check.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'ارسال پیام منطقه‌ای (LBA)';
$active = 'lba';
$isAdmin = is_admin();

if (!lba_tables_ready()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p class="empty">ارسال پیام منطقه‌ای هنوز راه‌اندازی نشده است.</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$lines = lba_originators_for($me);
$lineNumbers = array_column($lines, 'number');
$line = normalize_originator((string)($_GET['line'] ?? $_POST['line'] ?? '')) ?? '';
if (!in_array($line, $lineNumbers, true)) $line = $lineNumbers[0] ?? '';
$lineGateway = null;
foreach ($lines as $l) if ($l['number'] === $line) $lineGateway = $l['gateway'];
$tab = (string)($_GET['tab'] ?? 'requests');
if (!in_array($tab, ['requests', 'new', 'campaigns', 'clubs'], true)) $tab = 'requests';
$back = static fn(string $tab = 'requests', array $extra = []): string => '/lba.php?' . http_build_query(['line' => $GLOBALS['line'], 'tab' => $tab] + $extra);

/* ---------- JSON helpers for the form ---------- */
if (($_GET['action'] ?? '') === 'cities') {
    header('Content-Type: application/json; charset=utf-8');
    $rows = $lineGateway ? lba_lookup($lineGateway, 'GetCities', ['ProvinceId' => (int)($_GET['province'] ?? 0)]) : 'خط LBA انتخاب نشده است.';
    echo json_encode(is_array($rows) ? ['ok' => true, 'rows' => $rows] : ['ok' => false, 'error' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    // «افزودن لغو۱۱» (app/OptoutSuffix.php, per user, set by the admin) — applied to the text before it is priced/sent.
    if (in_array($do, ['send_simple', 'periodic_start', 'periodic_update'], true) && isset($_POST['body'])) {
        $_POST['body'] = optout_suffix_maybe(trim((string)$_POST['body']), optout_suffix_for_user((int)$me['id']));
    }
    if ($do === 'check_polygon') {
        header('Content-Type: application/json; charset=utf-8');
        $mark = lba_debug_mark();
        $r = lba_check_polygons($me, $line, (array)($_POST['operators'] ?? []), (string)($_POST['polygons'] ?? ''));
        // Admins see what was sent and what PishgamRayan answered, right under the button.
        if (!$r['ok'] && $isAdmin && $lineGateway && ($log = lba_debug_last((int)$lineGateway['id'], 'CheckPolygonCells', $mark)) !== null) {
            $r['debug'] = lba_debug_texts($log, $lineGateway);
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $result = null;
    $to = $back();
    $mark = $isAdmin ? lba_debug_mark() : -1;
    switch ($do) {
        case 'campaign_create':
            $result = lba_campaign_create($me, $line, (string)($_POST['title'] ?? ''));
            $ok = 'لیست خاکستری ساخته شد.'; $to = $back('campaigns');
            break;
        case 'campaign_delete':
            $result = lba_campaign_delete($me, $id); $ok = 'لیست خاکستری حذف شد.'; $to = $back('campaigns');
            break;
        case 'campaign_status':
            $result = lba_campaign_status($me, $id); $to = $back('campaigns');
            if ($result['ok']) {
                $parts = array_map(static fn(array $r): string => (LBA_OPERATORS[$r['operator']] ?? $r['operator']) . ': ' . ($r['created'] ? 'ساخته شده' : 'هنوز ساخته نشده'), $result['rows']);
                $ok = 'وضعیت لیست خاکستری — ' . ($parts ? implode('، ', $parts) : 'پاسخی نیامد');
            }
            break;
        case 'club_create':
            $result = lba_club_create($me, $line, (string)($_POST['title'] ?? '')); $ok = 'باشگاه ساخته شد.'; $to = $back('clubs');
            break;
        case 'club_delete':
            $result = lba_club_delete($me, $id); $ok = 'باشگاه حذف شد.'; $to = $back('clubs');
            break;
        case 'club_add':
            $raw = (string)($_POST['numbers'] ?? '');
            if (!empty($_FILES['numbers_file']['tmp_name']) && is_uploaded_file($_FILES['numbers_file']['tmp_name']) && (int)$_FILES['numbers_file']['size'] <= 5 * 1024 * 1024) {
                $raw .= "\n" . (string)file_get_contents($_FILES['numbers_file']['tmp_name']);
            }
            $result = lba_club_add_numbers($me, $id, $raw); $to = $back('clubs');
            if (isset($result['numbers'])) {
                $ok = to_persian_digits(number_format((int)$result['added'])) . ' شماره از ' . to_persian_digits(number_format((int)$result['numbers'])) . ' به باشگاه فرستاده شد'
                    . ((int)$result['invalid'] > 0 ? ' (' . to_persian_digits((string)$result['invalid']) . ' مورد نامعتبر کنار گذاشته شد)' : '') . '.';
                if (!$result['ok']) $result['error'] = $ok . ' ' . $result['error'];
            }
            break;
        case 'request_create':
            $kind = (string)($_POST['kind'] ?? 'simple');
            $result = lba_request_create($me, $line, $kind, $_POST);
            if ($result['ok']) {
                unset($_SESSION['lba_request_form']);
                $ok = 'درخواست ثبت شد. پیشگام رایان مخاطبان را می‌سازد؛ وضعیت هر دقیقه به‌روز می‌شود.' . (($result['warnings'] ?? []) ? ' هشدار: ' . implode('؛ ', $result['warnings']) : '');
                $to = $back('requests', ['request' => $result['id']]);
            } else {
                $_SESSION['lba_request_form'] = array_diff_key($_POST, ['_csrf' => 1]);
                $to = $back('new');
            }
            break;
        case 'request_refresh':
            $result = lba_find('request', $id, $me) ? lba_request_refresh($id) : ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
            $ok = 'وضعیت درخواست به‌روز شد.'; $to = $back('requests', ['request' => $id]);
            break;
        case 'request_delete':
            $result = lba_request_delete($me, $id); $ok = 'درخواست حذف شد.';
            break;
        case 'send_simple':
            $result = lba_send_simple($me, $id, $_POST); $to = $back('requests', ['request' => $id]);
            if ($result['ok']) $ok = 'ارسال ثبت شد' . ((int)$result['cost'] > 0 ? ' و ' . to_persian_digits(number_format((int)$result['cost'])) . ' ریال کسر شد' : '') . '.';
            break;
        case 'periodic_start':
            $result = lba_send_periodic($me, $id, (string)($_POST['mode'] ?? 'percent'), $_POST); $to = $back('requests', ['request' => $id]);
            if ($result['ok']) $ok = 'ارسال دوره‌ای ثبت شد' . ((int)$result['cost'] > 0 ? '؛ سقف ' . to_persian_digits(number_format((int)$result['cost'])) . ' ریال رزرو شد و در پایان فقط ارسال‌شده‌ها کسر می‌شود' : '') . '.';
            break;
        case 'periodic_update':
            $send = lba_find('send', (int)($_POST['send_id'] ?? 0), $me);
            $result = lba_send_periodic_update($me, (int)($_POST['send_id'] ?? 0), (string)($_POST['mode'] ?? 'percent'), $_POST);
            $ok = 'ارسال دوره‌ای ویرایش شد.'; $to = $back('requests', ['request' => (int)($send['request_id'] ?? $id)]);
            break;
        case 'activation':
            $result = lba_request_set_active($me, $id, ($_POST['active'] ?? '') === '1');
            $ok = ($_POST['active'] ?? '') === '1' ? 'ارسال دوره‌ای فعال شد.' : 'ارسال دوره‌ای غیرفعال شد.'; $to = $back('requests', ['request' => $id]);
            break;
    }
    if (is_array($result)) {
        // A provider call that failed was logged: point the admin to «جزئیات فنی».
        if (!$result['ok'] && $isAdmin && $mark >= 0 && lba_debug_mark() > $mark) {
            $result['error'] = (string)($result['error'] ?? 'انجام نشد.') . ' — درخواست و پاسخ کامل پیشگام در «جزئیات فنی» پایین صفحه است.';
        }
        $result['ok'] ? flash('success', $ok ?? 'انجام شد.') : flash('error', (string)($result['error'] ?? 'انجام نشد.'));
    }
    redirect($to);
}

/* ---------- data for the views ---------- */
$requestId = (int)($_GET['request'] ?? 0);
$request = $requestId > 0 ? lba_find('request', $requestId, $me) : null;
if ($request !== null && $request['originator'] !== $line && in_array($request['originator'], $lineNumbers, true)) {
    $line = (string)$request['originator'];
    foreach ($lines as $l) if ($l['number'] === $line) $lineGateway = $l['gateway'];
}
$campaigns = $lineGateway ? lba_campaigns_list($me, (int)$lineGateway['id']) : [];
$clubs = $lineGateway ? lba_clubs_list($me, (int)$lineGateway['id']) : [];
$form = $_SESSION['lba_request_form'] ?? [];
$fv = static fn(string $k, string $d = ''): string => is_array($form[$k] ?? null) ? '' : (string)($form[$k] ?? $d);
$fa = static fn(string $k, array $d = []): array => array_map('intval', (array)($form[$k] ?? $d));

$lookups = ['provinces' => [], 'brands' => [], 'types' => [], 'airports' => []];
$lookupErrors = [];
if ($lineGateway && ($tab === 'new' || $request !== null)) {
    foreach (['provinces' => 'GetProvinces', 'brands' => 'GetDeviceBrands', 'types' => 'GetDeviceTypes', 'airports' => 'GetAirports'] as $k => $m) {
        $rows = lba_lookup($lineGateway, $m);
        if (is_array($rows)) $lookups[$k] = $rows; else $lookupErrors[$k] = $rows;
    }
}
$names = static function (array $rows): array { $o = []; foreach ($rows as $r) $o[(int)$r['id']] = $r['name']; return $o; };

$stats = null;
if ($request !== null && in_array($_GET['stats'] ?? '', ['periodic', 'daily', 'financial'], true)) {
    $stats = ['type' => $_GET['stats']] + lba_request_statistics($me, $requestId, (string)$_GET['stats'], $_GET);
}

send_security_headers([lba_map_tile_origin()]);
$extraStyles = ['/assets/css/lba.css'];
require __DIR__ . '/../app/views/header.php';

$opLabels = LBA_OPERATORS;
$checks = static function (string $name, array $options, array $selected): string {
    $h = '<span class="lba-checks">';
    foreach ($options as $v => $label) {
        $h .= '<label><input type="checkbox" name="' . e($name) . '[]" value="' . (int)$v . '"' . (in_array((int)$v, $selected, true) ? ' checked' : '') . '> ' . e((string)$label) . '</label>';
    }
    return $h . '</span>';
};
$multi = static function (string $name, array $rows, array $selected, string $id = ''): string {
    $h = '<select name="' . e($name) . '[]" multiple class="lba-multi"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>';
    foreach ($rows as $r) $h .= '<option value="' . (int)$r['id'] . '"' . (in_array((int)$r['id'], $selected, true) ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    return $h . '</select>';
};
$jNow = substr(lba_jalali(time() + 300), 0, 16);
$jLater = substr(lba_jalali(time() + 3 * 3600), 0, 16);
$jMonth = substr(lba_jalali(time() + 30 * 86400), 0, 16);
$fmt = static fn($n): string => to_persian_digits(number_format((int)$n));

/* ---------- view helpers ---------- */
$icon = static function (string $name): string {
    $paths = [
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
        'pin'     => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0114 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'filter'  => '<path d="M3 5h18l-7 8.5V19l-4 2v-7.5z"/>',
        'home'    => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'send'    => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'chart'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'code'    => '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
        'copy'    => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h8"/>',
        'refresh' => '<path d="M20 11a8 8 0 10-2.3 5.7"/><path d="M20 4v7h-7"/>',
        'trash'   => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'doc'     => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'signal'  => '<path d="M5 20v-3M10 20v-7M15 20v-11M20 20V4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
};
$requestBadge = static fn(array $r): string => '<span class="badge badge-' . (['ready' => 'ok', 'pending' => 'pending', 'failed' => 'failed', 'deleted' => 'unknown'][$r['status']] ?? 'done') . '">' . e(lba_request_status_label($r)) . '</span>';
$sendBadge = static fn(string $s): string => '<span class="badge badge-' . (['sending' => 'pending', 'unknown' => 'pending', 'accepted' => 'done', 'done' => 'done', 'active' => 'ok', 'inactive' => 'unknown', 'failed' => 'failed'][$s] ?? 'unknown') . '">' . e(lba_send_status_label($s)) . '</span>';
$rowBadge = static fn(array $r): string => $r['status'] === 'active' ? '<span class="badge badge-ok">فعال</span>' : '<span class="badge badge-failed">ناموفق</span>' . ($r['error'] ? ' <span class="muted">' . e((string)$r['error']) . '</span>' : '');
// «جزئیات فنی» (admins): the gateway's latest failed provider calls, as sent and as answered.
$debugRows = $isAdmin && $lineGateway ? lba_debug_list((int)$lineGateway['id']) : [];
?>
<?php if ($lines === []): ?>
  <div class="card">
    <p class="empty">هیچ‌کدام از خط‌های شما درگاه LBA ندارد.
      <?= $isAdmin ? 'در <a href="/lba-gateways.php">درگاه‌های LBA</a> یک درگاه بسازید و آن را به شماره‌ها بدهید.' : 'برای فعال شدن ارسال منطقه‌ای روی خط‌تان با پشتیبانی تماس بگیرید.' ?></p>
  </div>
  <?php require __DIR__ . '/../app/views/footer.php'; exit; ?>
<?php endif; ?>

<div class="lba-page">
<div class="card lba-head">
  <div class="lba-head-row">
    <form method="get" class="lba-line">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <label>خط ارسال
        <select name="line" onchange="this.form.submit()">
          <?php foreach ($lines as $l): ?><option value="<?= e($l['number']) ?>"<?= $l['number'] === $line ? ' selected' : '' ?>><?= e($l['number']) ?><?= $l['label'] !== '' ? ' — ' . e($l['label']) : '' ?></option><?php endforeach; ?>
        </select>
      </label>
      <noscript><button class="btn btn-sm">انتخاب</button></noscript>
    </form>
    <div class="lba-chips">
      <span class="lba-chip"><?= $icon('signal') ?>درگاه <b><?= e($lineGateway['name'] ?? '—') ?></b></span>
      <span class="lba-chip">هر پارت فارسی <b><?= $fmt($lineGateway['price_fa_part'] ?? 0) ?></b> ریال</span>
      <span class="lba-chip">هر پارت انگلیسی <b><?= $fmt($lineGateway['price_en_part'] ?? 0) ?></b> ریال</span>
      <?php if ($isAdmin): ?><span class="lba-chip lba-chip-accent">مدیر سامانه هزینه نمی‌دهد</span><?php endif; ?>
    </div>
  </div>
  <nav class="lba-tabs" aria-label="بخش‌های ارسال منطقه‌ای">
    <?php foreach (['requests' => ['درخواست‌ها و ارسال‌ها', 'list'], 'new' => ['درخواست جدید', 'plus'], 'campaigns' => ['لیست‌های خاکستری', 'shield'], 'clubs' => ['باشگاه‌ها', 'users']] as $k => [$label, $ic]): ?>
      <a class="lba-tab" href="<?= e($back($k)) ?>"<?= $tab === $k && $request === null ? ' aria-current="page"' : '' ?>><?= $icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($request !== null):
    $criteria = json_decode((string)$request['criteria_json'], true) ?: [];
    $segments = lba_request_segments($request);
    $sends = lba_request_sends($requestId);
    $periodicSend = null;
    foreach ($sends as $s) if (in_array($s['status'], ['active', 'inactive'], true)) { $periodicSend = $s; break; }
    $reqGateway = lba_gateway_get((int)$request['gateway_id']);
    $canSend = $request['status'] === 'ready' && $request['originator'] === $line;
?>
<div class="card">
  <div class="lba-card-head">
    <h2><span class="lba-ico"><?= $icon('doc') ?></span><span>درخواست #<?= to_persian_digits((string)$request['id']) ?> — <?= e($request['title']) ?></span> <?= $requestBadge($request) ?></h2>
    <form method="post" class="lba-actions"><?= csrf_field() ?><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
      <button class="btn btn-sm lba-btn-icon" name="do" value="request_refresh"><?= $icon('refresh') ?>به‌روزرسانی وضعیت</button>
      <?php if ($request['status'] !== 'deleted'): ?><button class="btn btn-sm btn-danger lba-btn-icon" name="do" value="request_delete" onclick="return confirm('درخواست در پیشگام رایان هم حذف شود؟ ارسال دوره‌ای آن متوقف و تسویه می‌شود.')"><?= $icon('trash') ?>حذف درخواست</button><?php endif; ?>
    </form>
  </div>
  <div class="lba-kv">
    <div><span>نوع</span><b><?= e(LBA_KINDS[$request['kind']][0] ?? $request['kind']) ?></b></div>
    <div><span>خط</span><b class="ltr"><?= e($request['originator']) ?></b></div>
    <div><span>مخاطبان</span><b><?= $request['total_count'] !== null ? $fmt($request['total_count']) : '—' ?></b></div>
    <div><span>زمان ثبت</span><b><?= jdate_from_utc((string)$request['created_at']) ?></b></div>
  </div>
  <?php if ($request['error']): ?><p class="alert <?= $request['status'] === 'failed' ? 'alert-error' : 'alert-info' ?>"><?= e((string)$request['error']) ?></p><?php endif; ?>
  <details class="lba-details"><summary>معیارهای درخواست</summary>
    <ul class="lba-criteria">
      <li><span>اپراتورها</span><?= e(implode('، ', array_map(static fn($o) => $opLabels[$o] ?? $o, (array)($criteria['operators'] ?? [])))) ?></li>
      <?php if (isset($criteria['startDatetime'])): ?><li><span>بازه</span><span class="ltr"><?= e(to_persian_digits($criteria['startDatetime'] . ' → ' . $criteria['endDatetime'])) ?></span></li><?php endif; ?>
      <?php foreach (['fromAge' => 'از سن', 'toAge' => 'تا سن', 'numberprefixes' => 'پیش‌شماره‌ها', 'grayListExpireDate' => 'ماندن در لیست خاکستری (ساعت)', 'targetAirportId' => 'فرودگاه'] as $k => $label): if (!isset($criteria[$k])) continue; ?>
        <li><span><?= e($label) ?></span><?= e(to_persian_digits((string)$criteria[$k])) ?></li>
      <?php endforeach; ?>
      <?php if (isset($criteria['gender'])): ?><li><span>جنسیت</span><?= e(implode('، ', array_map(static fn($g) => LBA_GENDERS[$g] ?? $g, $criteria['gender']))) ?></li><?php endif; ?>
      <?php if (isset($criteria['residenceState'])): ?><li><span>وضعیت سکونت</span><?= e(LBA_RESIDENCE_STATES[$criteria['residenceState']] ?? '') ?></li><?php endif; ?>
      <?php if (isset($criteria['operationType'])): ?><li><span>باشگاه</span><?= $criteria['operationType'] === 0 ? 'فقط اعضا' : 'همه به جز اعضا' ?></li><?php endif; ?>
      <?php if (isset($criteria['cellList'])): ?><li><span>سلول‌ها</span><?= $fmt(count($criteria['cellList'])) ?> سلول</li><?php endif; ?>
      <?php if (isset($criteria['cities'])): ?><li><span>شهرها</span><?= e(to_persian_digits(implode('، ', $criteria['cities']))) ?></li><?php endif; ?>
      <?php if (isset($criteria['provinces'])): ?><li><span>استان‌ها</span><?= e(implode('، ', array_map(static fn($p) => $names($lookups['provinces'])[$p] ?? $p, $criteria['provinces']))) ?></li><?php endif; ?>
    </ul>
  </details>
  <?php if (!empty($criteria['polygons'])): ?>
    <input type="hidden" id="lba-view-polygons" value="<?= e(json_encode($criteria['polygons'])) ?>">
    <div data-lba-map data-readonly data-input="lba-view-polygons" data-tiles="<?= e(lba_map_tile_url()) ?>"></div>
  <?php endif; ?>

  <h3 class="lba-subtitle">بخش‌های مخاطبان</h3>
  <div class="table-wrap"><table>
    <tr><th>شناسه‌ی بخش</th><th>اپراتور</th><th>تعداد</th><th>قیمت هر پارت فارسی (پیشگام)</th><th>قیمت هر پارت انگلیسی (پیشگام)</th></tr>
    <?php foreach ($segments as $seg): ?>
      <tr><td class="num"><?= to_persian_digits((string)$seg['id']) ?></td><td><?= e($opLabels[$seg['operator']] ?? (string)$seg['operator']) ?></td>
        <td class="num"><b><?= $fmt($seg['count']) ?></b></td><td class="num"><?= $fmt($seg['persian_price']) ?></td><td class="num"><?= $fmt($seg['english_price']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$segments): ?><tr><td colspan="5" class="empty"><?= $request['status'] === 'pending' ? 'پیشگام رایان هنوز مخاطبان را می‌سازد.' : 'بخشی گزارش نشده است.' ?></td></tr><?php endif; ?>
  </table></div>
  <p class="hint">قیمت‌های بالا قیمت پیشگام رایان است؛ هزینه‌ی شما با تعرفه‌ی درگاه حساب می‌شود.</p>
</div>

<?php if ($canSend && (int)$request['is_periodic'] === 0): ?>
<div class="card">
  <div class="lba-card-head"><h2><span class="lba-ico"><?= $icon('send') ?></span>ارسال یکباره</h2></div>
  <form method="post" id="lba-simple-form" class="lba-form" data-fa="<?= (int)$reqGateway['price_fa_part'] ?>" data-en="<?= (int)$reqGateway['price_en_part'] ?>" data-free="<?= $isAdmin ? 1 : 0 ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="send_simple"><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
    <div class="lba-grid">
      <label>اپراتور
        <select name="operator" id="lba-simple-op">
          <?php foreach (array_unique(array_column($segments, 'operator')) as $op): ?><option value="<?= (int)$op ?>"><?= e($opLabels[$op] ?? (string)$op) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>زمان ارسال <span class="lba-optional">خالی = فوری</span><input type="text" name="send_at" class="ltr" placeholder="<?= e(to_persian_digits($jNow)) ?>"></label>
    </div>
    <div class="table-wrap"><table>
      <tr><th>بخش</th><th>اپراتور</th><th>موجود</th><th>از اندیس</th><th>تعداد</th></tr>
      <?php foreach ($segments as $seg): ?>
        <tr data-op="<?= (int)$seg['operator'] ?>"><td class="num"><?= to_persian_digits((string)$seg['id']) ?></td><td><?= e($opLabels[$seg['operator']] ?? '') ?></td><td class="num"><?= $fmt($seg['count']) ?></td>
          <td><input type="number" min="1" name="from_<?= (int)$seg['id'] ?>" value="1" class="ltr lba-num"></td>
          <td><input type="number" min="0" max="<?= (int)$seg['count'] ?>" name="count_<?= (int)$seg['id'] ?>" value="<?= (int)$seg['count'] ?>" class="ltr lba-count lba-num"></td></tr>
      <?php endforeach; ?>
    </table></div>
    <label>متن پیامک <textarea name="body" rows="4" class="lba-body" required></textarea></label>
    <?= optout_suffix_notice_html(optout_suffix_for_user((int)$me['id'])) ?>
    <div class="lba-submit">
      <div class="lba-summary"><span class="lba-parts"></span><span>هزینه‌ی تقریبی: <b class="lba-cost">۰</b> ریال</span></div>
      <button class="btn btn-primary lba-btn-icon" onclick="return confirm('ارسال انجام شود؟ هزینه از اعتبار کسر می‌شود.')"><?= $icon('send') ?>ارسال</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($canSend && (int)$request['is_periodic'] === 1):
    $ps = $periodicSend ? (json_decode((string)$periodicSend['params_json'], true) ?: []) : [];
    $pv = static fn(string $k, string $d = ''): string => isset($ps[$k]) ? (is_bool($ps[$k]) ? ($ps[$k] ? '1' : '') : (string)$ps[$k]) : $d;
?>
<div class="card">
  <div class="lba-card-head">
    <h2><span class="lba-ico"><?= $icon('send') ?></span><?= $periodicSend ? 'ویرایش ارسال دوره‌ای' : 'شروع ارسال دوره‌ای' ?> <?= $periodicSend ? $sendBadge($periodicSend['status']) : '' ?></h2>
    <?php if ($periodicSend): ?>
      <form method="post" class="lba-actions"><?= csrf_field() ?><input type="hidden" name="do" value="activation"><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
        <?php if ($periodicSend['status'] === 'active'): ?><button class="btn btn-sm" name="active" value="0">غیرفعال کردن</button><?php else: ?><button class="btn btn-sm btn-primary" name="active" value="1">فعال کردن</button><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($periodicSend): ?>
    <div class="lba-kv">
      <div><span>ارسال‌شده</span><b><?= $fmt($periodicSend['sent_parts']) ?> پارت</b></div>
      <div><span>کسرشده</span><b><?= $fmt($periodicSend['charged_credits']) ?> ریال</b></div>
      <div><span>سقف رزرو</span><b><?= $fmt($periodicSend['reserved_credits']) ?> ریال</b></div>
    </div>
  <?php endif; ?>
  <form method="post" id="lba-periodic-form" class="lba-form" data-fa="<?= (int)$reqGateway['price_fa_part'] ?>" data-en="<?= (int)$reqGateway['price_en_part'] ?>" data-free="<?= $isAdmin ? 1 : 0 ?>">
    <?= csrf_field() ?><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
    <input type="hidden" name="do" value="<?= $periodicSend ? 'periodic_update' : 'periodic_start' ?>">
    <?php if ($periodicSend): ?><input type="hidden" name="send_id" value="<?= (int)$periodicSend['id'] ?>"><?php endif; ?>
    <div class="lba-grid">
      <label class="lba-span-2">روش
        <select name="mode">
          <option value="percent"<?= ($periodicSend['kind'] ?? 'percent') === 'percent' ? ' selected' : '' ?>>درصدی (درصدی از مخاطبان تازه‌ی هر دوره)</option>
          <option value="count"<?= ($periodicSend['kind'] ?? '') === 'count' ? ' selected' : '' ?>>تعدادی (تعداد ثابت در هر دوره)</option>
        </select>
      </label>
      <?php if (!$periodicSend): ?><label>شروع کمپین <input type="text" name="period_start" class="ltr" required value="<?= e(to_persian_digits($jNow)) ?>"></label><?php endif; ?>
      <label>پایان کمپین <input type="text" name="period_end" class="ltr" required value="<?= e(to_persian_digits($pv('periodEnd') !== '' ? substr($pv('periodEnd'), 0, 16) : $jMonth)) ?>"></label>
      <label>نوع دوره <select name="period_type"><option value="1"<?= $pv('periodType') === '1' ? ' selected' : '' ?>>دقیقه</option><option value="2"<?= $pv('periodType', '2') === '2' ? ' selected' : '' ?>>ساعت</option></select></label>
      <label>طول دوره <input type="number" min="1" name="period_value" class="ltr" value="<?= e($pv('periodValue', '1')) ?>"></label>
      <label data-mode="percent">درصد ارسال در هر دوره <input type="number" min="1" max="100" name="send_percent" class="ltr" value="<?= e($pv('sendPercent', '100')) ?>"></label>
      <label data-mode="count">تعداد ارسال در هر دوره <input type="number" min="1" name="send_count" class="ltr" value="<?= e($pv('sendCount', '100')) ?>"></label>
      <label>سقف کل ارسال در کمپین <input type="number" min="1" name="max_send_count" class="ltr lba-max" required value="<?= e($pv('maxSendCount')) ?>"></label>
      <label>سقف ارسال روزانه <span class="lba-optional">اختیاری</span><input type="number" min="1" name="max_daily_send_count" class="ltr" value="<?= e($pv('maxDailySendCount')) ?>"></label>
      <label>ساعت شروع ارسال در روز <input type="text" name="start_time_at_day" class="ltr" placeholder="10:00" value="<?= e(substr($pv('startTimeAtDay'), 0, 5)) ?>"></label>
      <label>ساعت پایان ارسال در روز <input type="text" name="end_time_at_day" class="ltr" placeholder="18:00" value="<?= e(substr($pv('endTimeAtDay'), 0, 5)) ?>"></label>
    </div>
    <?php if (!$periodicSend): ?><div class="lba-field"><span class="lba-field-label">اپراتورها</span><?= $checks('operators', array_intersect_key($opLabels, array_flip((array)($criteria['operators'] ?? []))), (array)($criteria['operators'] ?? [])) ?></div><?php endif; ?>
    <label class="check"><input type="checkbox" name="continue_on_error" value="1"<?= $pv('continueSendIfAnyOperatorHaveError', '1') === '1' ? ' checked' : '' ?>> اگر یکی از اپراتورها خطا داشت، ارسال بقیه ادامه یابد</label>
    <label>متن پیامک <textarea name="body" rows="4" class="lba-body" required><?= e((string)($periodicSend['body'] ?? '')) ?></textarea></label>
    <?= optout_suffix_notice_html(optout_suffix_for_user((int)$me['id'])) ?>
    <p class="hint">طول هر دوره حداقل ۱۰ دقیقه و حداکثر ۱۱ ساعت است. در ویرایش، شروع کمپین و اپراتورها تغییر نمی‌کنند و می‌توان روش را از درصدی به تعدادی (یا برعکس) عوض کرد.</p>
    <div class="lba-submit">
      <div class="lba-summary"><span class="lba-parts"></span><span>سقف رزرو: <b class="lba-cost">۰</b> ریال</span><span class="muted">در پایان فقط ارسال‌شده‌ها کسر و باقی آزاد می‌شود.</span></div>
      <button class="btn btn-primary"><?= $periodicSend ? 'ذخیره‌ی تغییرات' : 'شروع ارسال دوره‌ای' ?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="lba-card-head"><h2><span class="lba-ico"><?= $icon('list') ?></span>ارسال‌های این درخواست</h2></div>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>زمان</th><th>نوع</th><th>متن</th><th>گیرنده / سقف</th><th>پارت</th><th>هزینه (ریال)</th><th>وضعیت</th><th>آمار تحویل</th></tr>
    <?php foreach ($sends as $s): $rows = json_decode((string)$s['stats_json'], true) ?: []; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$s['id']) ?></td>
        <td class="num"><?= jdate_from_utc((string)$s['created_at']) ?></td>
        <td><?= ['simple' => 'یکباره', 'percent' => 'دوره‌ای درصدی', 'count' => 'دوره‌ای تعدادی'][$s['kind']] ?></td>
        <td class="msg-preview" title="<?= e((string)$s['body']) ?>"><?= e(mb_strimwidth((string)$s['body'], 0, 50, '…')) ?></td>
        <td class="num"><?= $fmt($s['recipient_count']) ?></td>
        <td class="num"><?= $fmt($s['parts']) ?></td>
        <td class="num"><?= $fmt($s['charged_credits']) ?><?= (int)$s['reserved_credits'] !== (int)$s['charged_credits'] && in_array($s['status'], ['active', 'inactive', 'unknown'], true) ? ' <span class="muted">(رزرو ' . $fmt($s['reserved_credits']) . ')</span>' : '' ?></td>
        <td><?= $sendBadge($s['status']) ?><?php if ($s['error']): ?><div class="muted"><?= e(mb_strimwidth((string)$s['error'], 0, 120, '…')) ?></div><?php endif; ?></td>
        <td><?php foreach ($rows as $r): ?><div class="muted"><?= e(($opLabels[$r['operator']] ?? '') . ' — ' . (LBA_DELIVERY_STATES[$r['status']] ?? $r['status']) . ': ' . to_persian_digits(number_format($r['sms']))) ?></div><?php endforeach; ?><?= $rows ? '' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sends): ?><tr><td colspan="9" class="empty">هنوز ارسالی نشده است.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="card">
  <div class="lba-card-head"><h2><span class="lba-ico"><?= $icon('chart') ?></span>آمار</h2></div>
  <form method="get" class="lba-grid lba-grid-end">
    <input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="request" value="<?= (int)$request['id'] ?>">
    <label>از تاریخ <input type="text" name="from" class="ltr" placeholder="1405/09/01" value="<?= e((string)($_GET['from'] ?? '')) ?>"></label>
    <label>تا تاریخ <input type="text" name="to" class="ltr" placeholder="1405/09/30" value="<?= e((string)($_GET['to'] ?? '')) ?>"></label>
    <label>اپراتور <select name="operator"><option value="">همه</option><?php foreach ($opLabels as $k => $label): ?><option value="<?= $k ?>"<?= (int)($_GET['operator'] ?? 0) === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <div class="lba-actions">
      <button class="btn btn-sm" name="stats" value="daily">آمار روزانه</button>
      <?php if ((int)$request['is_periodic'] === 1): ?><button class="btn btn-sm" name="stats" value="periodic">آمار کلی دوره‌ای</button><?php endif; ?>
      <button class="btn btn-sm" name="stats" value="financial">آمار مالی پیشگام رایان</button>
    </div>
  </form>
  <?php if ($stats !== null): ?>
    <?php if (!$stats['ok']): ?><p class="alert alert-error"><?= e($stats['error']) ?></p>
    <?php elseif ($stats['type'] === 'financial'): ?>
      <div class="lba-kv">
        <div><span>هزینه‌ی کل</span><b><?= $fmt($stats['financial']['total']) ?></b></div>
        <div><span>برگشتی (نرسیده‌ها)</span><b><?= $fmt($stats['financial']['refunded']) ?></b></div>
        <div><span>خالص</span><b><?= $fmt($stats['financial']['pure']) ?></b></div>
      </div>
      <p class="hint">به ریال، از حساب پیشگام رایان.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><?= $stats['type'] === 'daily' ? '<th>تاریخ</th>' : '' ?><th>اپراتور</th><th>وضعیت تحویل</th><th>تعداد پیامک</th><th>تعداد پارت</th></tr>
        <?php foreach ($stats['rows'] as $r): ?>
          <tr><?= $stats['type'] === 'daily' ? '<td class="num">' . e(to_persian_digits((string)$r['date'])) . '</td>' : '' ?><td><?= e($opLabels[$r['operator']] ?? (string)$r['operator']) ?></td>
            <td><?= e(LBA_DELIVERY_STATES[$r['status']] ?? (string)$r['status']) ?></td><td class="num"><?= $fmt($r['sms']) ?></td><td class="num"><?= $fmt($r['parts']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$stats['rows']): ?><tr><td colspan="5" class="empty">آماری نیست.</td></tr><?php endif; ?>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'requests'):
    $requests = lba_requests_list($me);
?>
<div class="card">
  <div class="lba-card-head">
    <h2><span class="lba-ico"><?= $icon('list') ?></span>درخواست‌ها</h2>
    <a class="btn btn-primary btn-sm lba-btn-icon" href="<?= e($back('new')) ?>"><?= $icon('plus') ?>درخواست جدید</a>
  </div>
  <ol class="lba-steps">
    <li><span><b>تعریف مخاطبان</b>محدوده، شهر یا فهرست سلول‌ها را در «درخواست جدید» مشخص کنید.</span></li>
    <li><span><b>ساخت مخاطبان</b>پیشگام رایان مخاطبان را می‌سازد و وضعیت «آماده‌ی ارسال» می‌شود.</span></li>
    <li><span><b>ارسال</b>در جزئیات درخواست، ارسال یکباره یا دوره‌ای را انجام دهید.</span></li>
  </ol>
  <?php if ($requests): ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>زمان</th><th>عنوان</th><th>نوع</th><th>خط</th><th>مخاطبان</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="num"><?= jdate_from_utc((string)$r['created_at']) ?></td>
        <td><b><?= e($r['title']) ?></b></td>
        <td><?= e(LBA_KINDS[$r['kind']][0] ?? $r['kind']) ?></td>
        <td class="ltr"><?= e($r['originator']) ?></td>
        <td class="num"><?= $r['total_count'] !== null ? $fmt($r['total_count']) : '—' ?></td>
        <td><?= $requestBadge($r) ?><?php if ($r['status'] === 'failed' && $r['error']): ?><div class="muted"><?= e(mb_strimwidth((string)$r['error'], 0, 90, '…')) ?></div><?php endif; ?></td>
        <td><a class="btn btn-sm" href="<?= e('/lba.php?' . http_build_query(['line' => $r['originator'], 'request' => $r['id']])) ?>">جزئیات و ارسال</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?>
    <div class="lba-empty"><span class="lba-ico"><?= $icon('pin') ?></span><p>هنوز درخواستی ندارید.</p><a class="btn btn-primary btn-sm" href="<?= e($back('new')) ?>">ساخت اولین درخواست</a></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'new'):
    $kind = $fv('kind', 'simple');
?>
<div class="card">
  <div class="lba-card-head">
    <div><h2><span class="lba-ico"><?= $icon('plus') ?></span>درخواست جدید (تعریف مخاطبان)</h2>
    <p class="hint">مشخص کنید پیام به چه کسانی برسد. بعد از ثبت، پیشگام رایان مخاطبان را می‌سازد و ارسال را از جزئیات درخواست انجام می‌دهید.</p></div>
  </div>
  <?php foreach ($lookupErrors as $k => $err): ?><p class="alert alert-error">فهرست <?= e(['provinces' => 'استان‌ها', 'brands' => 'برندها', 'types' => 'نوع دستگاه‌ها', 'airports' => 'فرودگاه‌ها'][$k]) ?> دریافت نشد: <?= e($err) ?></p><?php endforeach; ?>
  <form method="post" id="lba-request-form" class="lba-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="request_create"><input type="hidden" name="line" value="<?= e($line) ?>">

    <fieldset class="lba-section">
      <legend>مشخصات درخواست</legend>
      <div class="lba-grid">
        <label>نوع درخواست
          <select name="kind" id="lba-kind">
            <?php foreach (LBA_KINDS as $k => [$label]): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="lba-span-2">عنوان <span class="lba-optional">فقط یک نام برای شناختن درخواست؛ متن پیام نیست</span>
          <input type="text" name="title" required maxlength="150" placeholder="مثلاً: اطراف فروشگاه ونک" value="<?= e($fv('title')) ?>"></label>
      </div>
      <div class="lba-field"><span class="lba-field-label">اپراتورها</span><?= $checks('operators', $opLabels, $fa('operators', [1])) ?></div>
      <div data-kinds="simple welcome welcome_cells club welcome_city welcome_province welcome_airport">
        <div class="lba-grid">
          <label>شروع بازه <input type="text" name="start" class="ltr" value="<?= e($fv('start', to_persian_digits($jNow))) ?>"></label>
          <label>پایان بازه <input type="text" name="end" class="ltr" value="<?= e($fv('end', to_persian_digits($jLater))) ?>"></label>
        </div>
        <p class="hint">تاریخ و ساعت شمسی، مثل <span class="ltr"><?= e(to_persian_digits($jNow)) ?></span>. در ارسال ساده فاصله‌ی شروع تا پایان حداکثر ۱۱ ساعت است.</p>
      </div>
      <p class="hint" data-kinds="periodic">در درخواست دوره‌ای بازه‌ی زمانی در مرحله‌ی ارسال تعیین می‌شود.</p>
    </fieldset>

    <fieldset class="lba-section" data-kinds="simple welcome club periodic">
      <legend>محدوده‌ی جغرافیایی</legend>
      <p class="hint">روی نقشه کلیک کنید تا نقاط محدوده اضافه شوند؛ با کشیدن نقشه جابه‌جا شوید و با چرخ ماوس یا دکمه‌های + و − بزرگ‌نمایی کنید. برای چند محدوده «محدوده‌ی بعدی» را بزنید. جهت رسم مهم نیست، ولی خطوط محدوده نباید یکدیگر را قطع کنند.</p>
      <input type="hidden" name="polygons" id="lba-polygons" value="<?= e($fv('polygons')) ?>">
      <div data-lba-map data-input="lba-polygons" data-tiles="<?= e(lba_map_tile_url()) ?>"></div>
      <div class="lba-check">
        <button type="button" class="btn btn-sm lba-btn-icon" id="lba-check-polygon"><?= $icon('signal') ?>بررسی پوشش اپراتورها</button>
        <div id="lba-check-result" class="lba-check-result" aria-live="polite"></div>
      </div>
      <details class="lba-details"><summary>ورود دستی مختصات (JSON یا GeoJSON)</summary>
        <textarea id="lba-polygons-raw" rows="3" class="ltr" placeholder='[[[51.3753,35.7448],[51.3760,35.7440],[51.3745,35.7432],[51.3753,35.7448]]]'></textarea>
        <div class="lba-actions"><button type="button" class="btn btn-sm" id="lba-polygons-apply">اعمال روی نقشه</button>
          <button type="button" class="btn btn-sm btn-ghost" id="lba-polygons-sample" title="همان محدوده‌ی نمونه‌ی راهنمای پیشگام رایان (اطراف برج میلاد، حدود ۹ هزار متر مربع)">درج نمونه‌ی راهنمای پیشگام</button>
          <span class="hint">ترتیب هر نقطه [طول جغرافیایی، عرض جغرافیایی] است.</span></div>
      </details>
    </fieldset>

    <fieldset class="lba-section" data-kinds="welcome_cells">
      <legend>سلول‌های مخابراتی</legend>
      <textarea name="cells" rows="3" class="ltr" placeholder="شناسه‌ی سلول‌ها، با ویرگول یا در خطوط جدا"><?= e($fv('cells')) ?></textarea>
    </fieldset>

    <fieldset class="lba-section" data-kinds="welcome welcome_cells club welcome_city welcome_province welcome_airport">
      <legend>لیست خاکستری <span class="lba-optional">جلوگیری از پیام تکراری</span></legend>
      <div class="lba-grid lba-grid-end">
        <label>لیست خاکستری
          <select name="campaign_id"><option value="">—</option><?php foreach ($campaigns as $c): if ($c['status'] !== 'active') continue; ?><option value="<?= (int)$c['id'] ?>"<?= (int)$fv('campaign_id') === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?></select>
        </label>
        <label>مدت ماندن شماره در لیست (ساعت) <input type="number" min="1" max="8760" name="graylist_hours" class="ltr" value="<?= e($fv('graylist_hours', '24')) ?>"></label>
        <div class="lba-actions"><a class="btn btn-sm" href="<?= e($back('campaigns')) ?>">ساخت لیست خاکستری</a></div>
      </div>
    </fieldset>

    <fieldset class="lba-section" data-kinds="club">
      <legend>باشگاه</legend>
      <div class="lba-grid lba-grid-end">
        <label>باشگاه <select name="club_id"><option value="">—</option><?php foreach ($clubs as $c): if ($c['status'] !== 'active') continue; ?><option value="<?= (int)$c['id'] ?>"<?= (int)$fv('club_id') === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?></select></label>
        <label>ارسال به <select name="club_operation"><option value="0"<?= $fv('club_operation', '0') === '0' ? ' selected' : '' ?>>فقط اعضای باشگاه</option><option value="1"<?= $fv('club_operation') === '1' ? ' selected' : '' ?>>همه به جز اعضای باشگاه</option></select></label>
        <div class="lba-actions"><a class="btn btn-sm" href="<?= e($back('clubs')) ?>">مدیریت باشگاه‌ها</a></div>
      </div>
      <p class="hint">پیشگام رایان به‌طور پیش‌فرض باشگاهی با کمتر از ۵۰۰ عضو را نمی‌پذیرد.</p>
    </fieldset>

    <fieldset class="lba-section" data-kinds="welcome_city">
      <legend>شهرها</legend>
      <div class="lba-grid">
        <label>استان <select id="lba-city-province"><option value="">—</option><?php foreach ($lookups['provinces'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
        <label>شهرها <select name="cities[]" multiple class="lba-multi" id="lba-cities" data-selected="<?= e(implode(',', $fa('cities'))) ?>"></select></label>
      </div>
    </fieldset>
    <fieldset class="lba-section" data-kinds="welcome_province">
      <legend>استان‌ها</legend>
      <?= $multi('provinces', $lookups['provinces'], $fa('provinces')) ?>
    </fieldset>
    <fieldset class="lba-section" data-kinds="welcome_airport">
      <legend>فرودگاه</legend>
      <select name="airport_id"><option value="">—</option><?php foreach ($lookups['airports'] as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$fv('airport_id') === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
    </fieldset>

    <fieldset class="lba-section" data-kinds="simple welcome welcome_cells club periodic">
      <legend>فیلترهای مخاطب <span class="lba-optional">اختیاری</span></legend>
      <div class="lba-grid">
        <label>از سن <input type="number" min="18" max="120" name="from_age" class="ltr" placeholder="۱۸" value="<?= e($fv('from_age')) ?>"></label>
        <label>تا سن <input type="number" min="18" max="120" name="to_age" class="ltr" placeholder="۱۲۰" value="<?= e($fv('to_age')) ?>"></label>
        <label class="lba-span-2">پیش‌شماره‌ها <span class="lba-optional">حداکثر ۳</span><input type="text" name="prefixes" class="ltr" placeholder="98912, 98935" value="<?= e($fv('prefixes')) ?>"></label>
      </div>
      <div class="lba-field"><span class="lba-field-label">جنسیت</span><?= $checks('gender', LBA_GENDERS, $fa('gender')) ?></div>
      <div class="lba-grid">
        <label>نوع دستگاه <?= $multi('device_type', $lookups['types'], $fa('device_type')) ?></label>
        <label>برند دستگاه <?= $multi('device_brand', $lookups['brands'], $fa('device_brand')) ?></label>
        <label>وضعیت اشتغال <select name="employment_state"><option value="">—</option><?php foreach (LBA_EMPLOYMENT_STATES as $k => $label): ?><option value="<?= $k ?>"<?= $fv('employment_state') === (string)$k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>وضعیت مسافر <select name="passenger_state"><option value="">—</option><?php foreach (LBA_PASSENGER_STATES as $k => $label): ?><option value="<?= $k ?>"<?= $fv('passenger_state') === (string)$k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="hint">برای انتخاب چند مورد در فهرست‌ها، Ctrl (در مک ⌘) را نگه دارید.</p>
    </fieldset>
    <fieldset class="lba-section" data-kinds="simple welcome welcome_cells club periodic welcome_city welcome_province welcome_airport">
      <legend>محل سکونت <span class="lba-optional">اختیاری</span></legend>
      <div class="lba-grid">
        <label>استان محل سکونت <?= $multi('residence_provinces', $lookups['provinces'], $fa('residence_provinces')) ?></label>
        <label>وضعیت سکونت <select name="residence_state"><option value="">—</option><?php foreach (LBA_RESIDENCE_STATES as $k => $label): ?><option value="<?= $k ?>"<?= $fv('residence_state') === (string)$k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="hint">«استان محل سکونت» استان مبدأ است و برای هدف‌گیری گردشگران (مهمان) به کار می‌رود.</p>
    </fieldset>

    <div class="lba-submit">
      <div class="lba-summary"><span class="muted">بعد از ثبت، ساخت مخاطبان چند دقیقه طول می‌کشد و وضعیت هر دقیقه به‌روز می‌شود.</span></div>
      <button class="btn btn-primary lba-btn-icon"><?= $icon('send') ?>ثبت درخواست</button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'campaigns'): ?>
<div class="card">
  <div class="lba-card-head">
    <div><h2><span class="lba-ico"><?= $icon('shield') ?></span>لیست‌های خاکستری (کمپین)</h2>
    <p class="hint">لیست محرومیت موقت: کسی که پیام خوشامد گرفت، تا مدت تعیین‌شده در درخواست دوباره پیام نمی‌گیرد. هر لیست به درگاه همین خط تعلق دارد.</p></div>
  </div>
  <form method="post" class="lba-inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="campaign_create"><input type="hidden" name="line" value="<?= e($line) ?>">
    <label>عنوان لیست تازه <input type="text" name="title" required maxlength="150" placeholder="مثلاً: خوشامد فرودگاه"></label><button class="btn btn-primary lba-btn-icon"><?= $icon('plus') ?>ساخت لیست خاکستری</button>
  </form>
  <?php if ($campaigns): ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>عنوان</th><th>شناسه در پیشگام</th><th>وضعیت</th><th>زمان</th><th></th></tr>
    <?php foreach ($campaigns as $c): ?>
      <tr><td class="num"><?= to_persian_digits((string)$c['id']) ?></td><td><b><?= e($c['title']) ?></b></td><td class="num"><?= e(to_persian_digits((string)$c['provider_campaign_id'])) ?></td>
        <td><?= $rowBadge($c) ?></td><td class="num"><?= jdate_from_utc((string)$c['created_at']) ?></td>
        <td><form method="post" class="lba-actions"><?= csrf_field() ?><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <?php if ($c['status'] === 'active'): ?><button class="btn btn-sm" name="do" value="campaign_status">وضعیت در اپراتورها</button><?php endif; ?>
          <button class="btn btn-sm btn-danger" name="do" value="campaign_delete" onclick="return confirm('لیست خاکستری حذف شود؟')">حذف</button></form></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?>
    <div class="lba-empty"><span class="lba-ico"><?= $icon('shield') ?></span><p>لیست خاکستری‌ای ندارید.</p></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'clubs'): ?>
<div class="card">
  <div class="lba-card-head">
    <div><h2><span class="lba-ico"><?= $icon('users') ?></span>باشگاه‌های هوشمند</h2>
    <p class="hint">فهرست ثابت شماره‌ها (مثلاً مشتریان وفادار) برای ارسال فقط به اعضا یا به همه به جز اعضا. هر باشگاه به درگاه همین خط تعلق دارد.</p></div>
  </div>
  <form method="post" class="lba-inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="club_create"><input type="hidden" name="line" value="<?= e($line) ?>">
    <label>عنوان باشگاه تازه <input type="text" name="title" required maxlength="150" placeholder="مثلاً: مشتریان وفادار"></label><button class="btn btn-primary lba-btn-icon"><?= $icon('plus') ?>ساخت باشگاه</button>
  </form>
  <?php if ($clubs): ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>عنوان</th><th>شناسه در پیشگام</th><th>شماره‌های فرستاده‌شده</th><th>وضعیت</th><th>افزودن شماره</th><th></th></tr>
    <?php foreach ($clubs as $c): ?>
      <tr><td class="num"><?= to_persian_digits((string)$c['id']) ?></td><td><b><?= e($c['title']) ?></b></td><td class="num"><?= e(to_persian_digits((string)$c['provider_club_id'])) ?></td>
        <td class="num"><?= $fmt($c['added_count']) ?></td><td><?= $rowBadge($c) ?></td>
        <td><?php if ($c['status'] === 'active'): ?>
          <form method="post" enctype="multipart/form-data" class="lba-club-add"><?= csrf_field() ?><input type="hidden" name="do" value="club_add"><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <textarea name="numbers" rows="2" class="ltr" placeholder="09121111111&#10;09132222222"></textarea>
            <div class="lba-actions"><input type="file" name="numbers_file" accept=".txt,.csv"><button class="btn btn-sm">افزودن</button></div>
          </form><?php endif; ?></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="line" value="<?= e($line) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger" name="do" value="club_delete" onclick="return confirm('باشگاه حذف شود؟')">حذف</button></form></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?>
    <div class="lba-empty"><span class="lba-ico"><?= $icon('users') ?></span><p>باشگاهی ندارید.</p></div>
  <?php endif; ?>
  <p class="hint">شماره‌ها در دسته‌های ۱۰۰تایی به پیشگام رایان فرستاده می‌شوند (محدودیت خود سرویس).</p>
</div>
<?php endif; ?>

<?php if ($isAdmin && $lineGateway && ($tab !== 'campaigns' && $tab !== 'clubs' || $debugRows)): ?>
<div class="card lba-debug" id="lba-debug">
  <div class="lba-card-head">
    <div><h2><span class="lba-ico"><?= $icon('code') ?></span>جزئیات فنی <span class="lba-optional">فقط مدیر</span></h2>
    <p class="hint">آخرین درخواست‌های ناموفق درگاه «<?= e($lineGateway['name']) ?>» به پیشگام رایان: دقیقاً همان چیزی که فرستاده شد (بدون توکن) و پاسخ خام پیشگام. برای پشتیبانی پیشگام، «کپی» را بزنید و متن را برایشان بفرستید.</p></div>
  </div>
  <?php if (!$debugRows): ?>
    <p class="empty">درخواست ناموفقی ثبت نشده است.</p>
  <?php endif; ?>
  <?php foreach ($debugRows as $i => $log): $texts = lba_debug_texts($log, $lineGateway); ?>
    <details class="lba-log"<?= $i === 0 ? ' open' : '' ?>>
      <summary>
        <span class="badge badge-failed"><?= $log['status_code'] !== null ? 'کد ' . to_persian_digits((string)$log['status_code']) : 'HTTP ' . to_persian_digits((string)$log['http_status']) ?></span>
        <b class="ltr"><?= e($log['method']) ?></b>
        <span class="lba-log-error"><?= e((string)$log['error']) ?></span>
        <span class="muted lba-log-time"><?= jdate_from_utc((string)$log['created_at']) ?></span>
      </summary>
      <div class="lba-log-body">
        <div><div class="lba-code-head"><span>درخواست ارسالی</span><button type="button" class="btn btn-sm lba-btn-icon" data-copy><?= $icon('copy') ?>کپی</button></div><pre class="lba-code"><?= e($texts['request']) ?></pre></div>
        <div><div class="lba-code-head"><span>پاسخ پیشگام</span><button type="button" class="btn btn-sm lba-btn-icon" data-copy><?= $icon('copy') ?>کپی</button></div><pre class="lba-code"><?= e($texts['response']) ?></pre></div>
      </div>
    </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<script src="<?= e(asset_url('/assets/js/lba-map.js')) ?>" defer></script>
<script>
(function () {
  // Show only the sections of the chosen request kind.
  var kind = document.getElementById('lba-kind');
  function syncKind() {
    if (!kind) return;
    document.querySelectorAll('#lba-request-form [data-kinds]').forEach(function (el) {
      var on = el.getAttribute('data-kinds').split(' ').indexOf(kind.value) >= 0;
      el.style.display = on ? '' : 'none';
      el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
    });
  }
  if (kind) { kind.addEventListener('change', syncKind); syncKind(); }

  // Cities of a province.
  var prov = document.getElementById('lba-city-province'), cities = document.getElementById('lba-cities');
  if (prov && cities) prov.addEventListener('change', function () {
    var keep = {}; Array.prototype.forEach.call(cities.selectedOptions, function (o) { keep[o.value] = o.textContent; });
    if (!prov.value) return;
    fetch('/messages/lba?action=cities&line=<?= rawurlencode($line) ?>&province=' + encodeURIComponent(prov.value), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { alert(d.error); return; }
        Array.prototype.slice.call(cities.options).forEach(function (o) { if (!o.selected) o.remove(); });
        d.rows.forEach(function (c) { if (keep[c.id]) return; var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; cities.appendChild(o); });
      });
  });

  // Typed / pasted coordinates onto the map.
  var raw = document.getElementById('lba-polygons-raw'), apply = document.getElementById('lba-polygons-apply'), poly = document.getElementById('lba-polygons');
  if (apply && raw && poly) apply.addEventListener('click', function () {
    try { JSON.parse(raw.value); } catch (e) { alert('JSON نامعتبر است.'); return; }
    poly.value = raw.value; poly.dispatchEvent(new Event('lba:reload'));
  });
  if (poly && raw) poly.addEventListener('change', function () { raw.value = poly.value; });
  // The example area of PishgamRayan's guide (CreateSimpleSmartSmsRequest), to tell a size problem from an account one.
  var sample = document.getElementById('lba-polygons-sample');
  if (sample && raw && apply) sample.addEventListener('click', function () {
    raw.value = '[[[51.3753,35.7448],[51.3760,35.7440],[51.3745,35.7432],[51.3753,35.7448]]]';
    apply.click();
  });

  // CheckPolygonCells: one badge per operator; on failure the error (and, for admins, the raw request/answer).
  var check = document.getElementById('lba-check-polygon'), out = document.getElementById('lba-check-result');
  function node(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function codeBlock(title, text) {
    var box = node('div'), head = node('div', 'lba-code-head'), btn = node('button', 'btn btn-sm', 'کپی');
    btn.type = 'button'; btn.setAttribute('data-copy', '');
    head.appendChild(node('span', null, title)); head.appendChild(btn);
    box.appendChild(head); box.appendChild(node('pre', 'lba-code', text));
    return box;
  }
  if (check) check.addEventListener('click', function () {
    var form = document.getElementById('lba-request-form'), fd = new FormData();
    fd.append('_csrf', form.querySelector('[name=_csrf]').value);
    fd.append('do', 'check_polygon'); fd.append('line', form.querySelector('[name=line]').value);
    fd.append('polygons', poly.value);
    form.querySelectorAll('[name="operators[]"]:checked').forEach(function (c) { fd.append('operators[]', c.value); });
    out.replaceChildren(node('span', 'muted', 'در حال بررسی…'));
    check.disabled = true;
    fetch('/messages/lba', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
      out.replaceChildren();
      if (!d.ok) {
        out.appendChild(node('p', 'alert alert-error', d.error));
        if (d.debug) {
          var det = node('details', 'lba-log'), sum = node('summary', null, 'جزئیات فنی (فقط مدیر): درخواست و پاسخ خام پیشگام');
          var body = node('div', 'lba-log-body');
          body.appendChild(codeBlock('درخواست ارسالی', d.debug.request)); body.appendChild(codeBlock('پاسخ پیشگام', d.debug.response));
          det.appendChild(sum); det.appendChild(body); det.open = true; out.appendChild(det);
        }
        return;
      }
      var names = <?= json_encode($opLabels, JSON_UNESCAPED_UNICODE) ?>;
      if (!d.rows.length) { out.appendChild(node('span', 'muted', 'پاسخی نیامد.')); return; }
      d.rows.forEach(function (r) {
        var ok = r.valid && r.valid_area;
        out.appendChild(node('span', 'badge ' + (ok ? 'badge-ok' : 'badge-failed'),
          (names[r.operator] || r.operator) + ': ' + (r.valid ? 'پوشش دارد' : 'پوشش ندارد') + (r.valid_area ? '' : ' (مساحت نامعتبر)')));
      });
    }).catch(function () { out.replaceChildren(node('p', 'alert alert-error', 'خطا در بررسی؛ اتصال را بررسی کنید.')); })
      .finally(function () { check.disabled = false; });
  });

  // «کپی» of the technical details.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    var pre = btn.closest('div').parentNode.querySelector('pre');
    if (!pre || !navigator.clipboard) return;
    navigator.clipboard.writeText(pre.textContent).then(function () {
      var old = btn.lastChild.textContent; btn.lastChild.textContent = 'کپی شد';
      setTimeout(function () { btn.lastChild.textContent = old; }, 1500);
    });
  });

  // Parts and cost of a send form.
  function parts(t) {
    var uni = /[^\x20-\x7E\r\n]/.test(t), n = Array.from(t).length;
    if (!n) return [0, uni];
    return [uni ? (n <= 70 ? 1 : Math.ceil(n / 67)) : (n <= 160 ? 1 : Math.ceil(n / 153)), uni];
  }
  // Mirrors optout_suffix_apply() (app/OptoutSuffix.php) so the part count includes «لغو۱۱».
  function optoutText(f, t) {
    var cb = f.querySelector('[data-optout-suffix]');
    var trimmed = t.replace(/\s+$/, '');
    if (!cb || trimmed === '' || /لغو\s*[:：\-]?\s*(?:11|۱۱|١١)(?![0-9۰-۹٠-٩])/.test(trimmed)) return t;
    return trimmed + '\n' + cb.dataset.optoutSuffix;
  }
  function fa(n) { return String(n.toLocaleString('en-US')).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  ['lba-simple-form', 'lba-periodic-form'].forEach(function (id) {
    var f = document.getElementById(id);
    if (!f) return;
    var op = document.getElementById('lba-simple-op');
    function upd() {
      var p = parts(optoutText(f, f.querySelector('.lba-body').value)), price = +(p[1] ? f.dataset.fa : f.dataset.en), count = 0;
      if (id === 'lba-simple-form') {
        f.querySelectorAll('tr[data-op]').forEach(function (tr) {
          var on = !op || tr.getAttribute('data-op') === op.value;
          tr.style.display = on ? '' : 'none';
          tr.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
          if (on) count += +(tr.querySelector('.lba-count').value || 0);
        });
      } else {
        count = +(f.querySelector('.lba-max').value || 0);
      }
      f.querySelector('.lba-parts').textContent = fa(p[0]) + ' پارت ' + (p[1] ? 'فارسی' : 'انگلیسی') + ' × ' + fa(count) + ' گیرنده';
      f.querySelector('.lba-cost').textContent = f.dataset.free === '1' ? 'رایگان (مدیر)' : fa(p[0] * price * count);
    }
    f.addEventListener('input', upd); f.addEventListener('change', upd); upd();
    var mode = f.querySelector('[name=mode]');
    if (mode) {
      function syncMode() { f.querySelectorAll('[data-mode]').forEach(function (el) { var on = el.getAttribute('data-mode') === mode.value; el.style.display = on ? '' : 'none'; el.querySelector('input').disabled = !on; }); }
      mode.addEventListener('change', syncMode); syncMode();
    }
  });
})();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
