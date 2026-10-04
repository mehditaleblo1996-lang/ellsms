<?php
/**
 * #43 — regional bulk through Vesal: pick an area (province / city / postal code / number prefix), see how
 * many subscribers it has, create a priced request, confirm it (which reserves the price), and follow it.
 * Settings (platform admin) live on the same page. See app/RegionalBulk.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'ارسال منطقه‌ای';
$active = 'regional_bulk';

// Cities of a province, for the city picker (JSON).
if (($_GET['action'] ?? '') === 'cities') {
    header('Content-Type: application/json; charset=utf-8');
    $cities = regional_bulk_configured() ? regional_bulk_cities((int)($_GET['province'] ?? 0)) : 'ارسال منطقه‌ای فعال نیست.';
    echo json_encode(is_array($cities) ? ['ok' => true, 'cities' => $cities] : ['ok' => false, 'error' => $cities], JSON_UNESCAPED_UNICODE);
    exit;
}

$form = $_SESSION['regional_bulk_form'] ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'settings') {
        if (!is_admin()) { http_response_code(403); exit('فقط مدیر سامانه می‌تواند تنظیمات را تغییر دهد.'); }
        $baseUrl = trim((string)($_POST['vesal_bulk_base_url'] ?? ''));
        if ($baseUrl !== '' && preg_match(app_env() === 'production' ? '#^https://#i' : '#^https?://#i', $baseUrl) !== 1) {
            flash('error', 'آدرس سرویس Vesal نامعتبر است' . (app_env() === 'production' ? ' (باید با https:// شروع شود).' : '.'));
        } else {
            set_setting('regional_bulk_enabled', !empty($_POST['regional_bulk_enabled']) ? '1' : '0');
            set_setting('vesal_bulk_base_url', $baseUrl);
            set_setting('vesal_bulk_username', trim((string)($_POST['vesal_bulk_username'] ?? '')));
            set_setting('regional_bulk_credits_per_price_unit', (string)max(0, (float)($_POST['regional_bulk_credits_per_price_unit'] ?? 1)));
            set_setting('regional_bulk_margin_percent', (string)max(0, min(1000, (float)($_POST['regional_bulk_margin_percent'] ?? 0))));
            audit((int)$me['id'], 'regional_bulk.settings', 'enabled=' . (!empty($_POST['regional_bulk_enabled']) ? 1 : 0) . ' host=' . (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
            flash('success', 'تنظیمات ارسال منطقه‌ای ذخیره شد.');
        }
    } elseif ($action === 'count' || $action === 'create') {
        $form = array_intersect_key($_POST, array_flip(['kind', 'sim', 'operator', 'prefix', 'province_code', 'city_code', 'postal_code', 'originator', 'content', 'scheduled_at', 'count']));
        $_SESSION['regional_bulk_form'] = $form;
        $c = regional_bulk_criteria($form);
        if (!$c['ok']) {
            flash('error', $c['error']);
        } elseif ($action === 'count') {
            $n = regional_bulk_configured() ? regional_bulk_count($c['criteria']) : ['ok' => false, 'error' => 'ارسال منطقه‌ای فعال نیست.'];
            $n['ok'] ? flash('success', 'تعداد مشترکان این منطقه: ' . to_persian_digits(number_format($n['count']))) : flash('error', $n['error']);
        } else {
            $scheduled = trim((string)($form['scheduled_at'] ?? ''));
            $scheduledAt = null;
            if ($scheduled !== '') {
                $ts = strtotime(str_replace('T', ' ', from_persian_digits($scheduled)));
                $scheduledAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
            }
            $count = (int)from_persian_digits((string)($form['count'] ?? '0'));
            $r = regional_bulk_create($me, $c['criteria'], (string)($form['originator'] ?? ''), (string)($form['content'] ?? ''), $scheduledAt, $count > 0 ? $count : null);
            if ($r['ok']) {
                unset($_SESSION['regional_bulk_form']);
                flash('success', 'درخواست ساخته و قیمت‌گذاری شد. برای ارسال، آن را در فهرست زیر تأیید کنید.');
            } else {
                flash('error', $r['error']);
            }
        }
    } elseif ($action === 'confirm') {
        $r = regional_bulk_confirm($me, (int)($_POST['id'] ?? 0));
        $r['ok'] ? flash('success', 'درخواست تأیید شد و ارسال آن در Vesal آغاز می‌شود.') : flash('error', $r['error']);
    } elseif ($action === 'cancel') {
        regional_bulk_cancel($me, (int)($_POST['id'] ?? 0)) ? flash('success', 'درخواست لغو شد.') : flash('error', 'این درخواست قابل لغو نیست.');
    }
    redirect('/regional-bulk.php');
}

$configured = regional_bulk_configured();
$provinces = $configured ? regional_bulk_provinces() : [];
// Send page, so the send-side list: own + organization + shared lines (app/authorization.php).
$originators = sendable_originators($me);

$where = ['1=1'];
$params = [];
if (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    if ($orgId > 0) { $where[] = 'organization_id = ?'; $params[] = $orgId; }
    else { $where[] = 'user_id = ?'; $params[] = (int)$me['id']; }
}
$st = db()->prepare('SELECT * FROM ellsms_regional_bulk_requests WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100');
$st->execute($params);
$rows = $st->fetchAll();

$statusLabels = ['draft' => 'پیش‌نویس', 'priced' => 'منتظر تأیید', 'confirmed' => 'تأیید شده', 'sending' => 'در حال ارسال', 'done' => 'پایان یافته', 'failed' => 'ناموفق', 'cancelled' => 'لغو شده'];
$kindLabels = ['province' => 'استان', 'city' => 'شهر', 'postal' => 'کد پستی', 'prefix' => 'پیش‌شماره'];
$f = static fn(string $k, string $d = ''): string => (string)($form[$k] ?? $d);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (is_admin()): ?>
<div class="card">
  <h2>تنظیمات ارسال منطقه‌ای (Vesal)</h2>
  <p class="hint">
    هدف‌گیری بر اساس بانک مشترکان اپراتور در سرویس Vesal انجام می‌شود و ELLSMS هیچ شماره‌ای از مشترکان نمی‌بیند یا ذخیره نمی‌کند.
    گذرواژه در متغیر محیطی <span class="ltr">VESAL_BULK_PASSWORD</span> نگه داشته می‌شود
    (وضعیت فعلی: <b><?= trim((string)env('VESAL_BULK_PASSWORD', '')) !== '' ? 'تنظیم شده' : 'تنظیم نشده' ?></b>).
    هزینه‌ی مشتری = قیمت Vesal × ضریب تبدیل × (۱ + درصد سود)؛ در پایان فقط سهم ارسال‌شده کسر و باقی آزاد می‌شود.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <div class="toolbar">
      <label><input type="checkbox" name="regional_bulk_enabled" value="1"<?= regional_bulk_setting('regional_bulk_enabled', '0') === '1' ? ' checked' : '' ?>> فعال</label>
      <label style="flex:1 1 320px">آدرس سرویس <input type="url" name="vesal_bulk_base_url" class="ltr" style="width:100%" value="<?= e(regional_bulk_setting('vesal_bulk_base_url')) ?>" placeholder="https://…"></label>
      <label>نام کاربری <input type="text" name="vesal_bulk_username" class="ltr" value="<?= e(regional_bulk_setting('vesal_bulk_username')) ?>"></label>
      <label>اعتبار به ازای هر واحد قیمت <input type="number" step="any" min="0" name="regional_bulk_credits_per_price_unit" class="ltr" value="<?= e(regional_bulk_setting('regional_bulk_credits_per_price_unit', '1')) ?>"></label>
      <label>درصد سود <input type="number" step="any" min="0" max="1000" name="regional_bulk_margin_percent" class="ltr" value="<?= e(regional_bulk_setting('regional_bulk_margin_percent', '0')) ?>"></label>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>
<?php endif; ?>

<?php if ($configured): ?>
<div class="card" style="margin-top:18px">
  <h2>درخواست جدید</h2>
  <?php if (is_string($provinces)): ?><p class="alert alert-error">فهرست استان‌ها دریافت نشد: <?= e($provinces) ?></p><?php $provinces = []; endif; ?>
  <form method="post" id="rb-form">
    <?= csrf_field() ?>
    <div class="toolbar">
      <label>هدف
        <select name="kind" id="rb-kind">
          <?php foreach ($kindLabels as $k => $label): ?><option value="<?= $k ?>"<?= $f('kind', 'province') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </label>
      <label data-kind="province city">استان
        <select name="province_code" id="rb-province">
          <option value="">—</option>
          <?php foreach ($provinces as $p): ?><option value="<?= (int)$p['code'] ?>"<?= (int)$f('province_code') === (int)$p['code'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label data-kind="city">شهر <select name="city_code" id="rb-city" data-selected="<?= (int)$f('city_code') ?>"><option value="">—</option></select></label>
      <label data-kind="postal">کد پستی (یا ابتدای آن) <input type="text" name="postal_code" class="ltr" value="<?= e($f('postal_code')) ?>"></label>
      <label>پیش‌شماره <input type="text" name="prefix" class="ltr" placeholder="0912" value="<?= e($f('prefix')) ?>"></label>
      <label>نوع سیم‌کارت
        <select name="sim">
          <?php foreach (['all' => 'همه', 'prepaid' => 'اعتباری', 'postpaid' => 'دائمی'] as $k => $label): ?><option value="<?= $k ?>"<?= $f('sim', 'all') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>اپراتور
        <select name="operator">
          <?php foreach (['MCI' => 'همراه اول', 'MTN' => 'ایرانسل'] as $k => $label): ?><option value="<?= $k ?>"<?= $f('operator', 'MCI') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="toolbar">
      <label>خط ارسال
        <?php if ($originators === ['*']): ?>
          <input type="text" name="originator" class="ltr" value="<?= e($f('originator')) ?>">
        <?php else: ?>
          <select name="originator"><?php foreach ($originators as $o): ?><option value="<?= e($o) ?>"<?= $f('originator') === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
      </label>
      <label>تعداد (خالی = همه) <input type="number" min="0" name="count" class="ltr" value="<?= e($f('count')) ?>"></label>
      <label>زمان ارسال (خالی = فوری) <input type="datetime-local" name="scheduled_at" class="ltr" value="<?= e($f('scheduled_at')) ?>"></label>
    </div>
    <label style="display:block">متن پیام <textarea name="content" rows="4" style="width:100%"><?= e($f('content')) ?></textarea></label>
    <button class="btn" name="action" value="count">شمارش مشترکان</button>
    <button class="btn btn-primary" name="action" value="create">ساخت درخواست و قیمت‌گذاری</button>
  </form>
</div>
<script>
(function () {
  var kind = document.getElementById('rb-kind'), province = document.getElementById('rb-province'), city = document.getElementById('rb-city');
  function sync() {
    document.querySelectorAll('#rb-form [data-kind]').forEach(function (el) {
      el.style.display = el.getAttribute('data-kind').split(' ').indexOf(kind.value) >= 0 ? '' : 'none';
    });
  }
  function loadCities() {
    city.innerHTML = '<option value="">—</option>';
    if (!province.value || kind.value !== 'city') return;
    fetch('/regional-bulk.php?action=cities&province=' + encodeURIComponent(province.value), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        (d.cities || []).forEach(function (c) {
          var o = document.createElement('option');
          o.value = c.code; o.textContent = c.name;
          if (String(c.code) === city.getAttribute('data-selected')) o.selected = true;
          city.appendChild(o);
        });
      });
  }
  kind.addEventListener('change', function () { sync(); loadCities(); });
  province.addEventListener('change', loadCities);
  sync(); loadCities();
})();
</script>
<?php elseif (!is_admin()): ?>
<div class="card"><p class="empty">ارسال منطقه‌ای در حال حاضر فعال نیست.</p></div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>درخواست‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>زمان</th><th>هدف</th><th>متن</th><th>هزینه</th><th>پیشرفت</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r): $criteria = json_decode((string)$r['criteria_json'], true) ?: []; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td><?= e($kindLabels[$criteria['kind'] ?? ''] ?? '—') ?>
          <span class="muted ltr"><?= e((string)($criteria['province_code'] ?? $criteria['city_code'] ?? $criteria['postal_code'] ?? $criteria['prefix'] ?? '')) ?></span></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'], 0, 50, '…')) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)($r['settled_credits'] ?? $r['charged_credits'])) ?></td>
        <td class="num"><?= $r['total_request'] !== null ? to_persian_digits((int)$r['total_sent'] . ' / ' . (int)$r['total_request']) : '—' ?></td>
        <td><?= $statusLabels[$r['status']] ?? e((string)$r['status']) ?><?php if ($r['status'] === 'failed' && $r['error']): ?> <span class="muted"><?= e(mb_strimwidth((string)$r['error'], 0, 80, '…')) ?></span><?php endif; ?></td>
        <td>
          <?php if ($r['status'] === 'priced'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-primary" name="action" value="confirm">تأیید و ارسال</button>
              <button class="btn btn-sm" name="action" value="cancel">لغو</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">هنوز درخواستی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
