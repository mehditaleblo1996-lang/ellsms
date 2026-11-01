<?php
/**
 * Telegram user-account service — platform admin: switch, prices, default limits, per-customer
 * activation and limits, the worker's health, every connected account, and recent sends.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'سرویس تلگرام';
$active = 'telegram_admin';
if (!tg_schema_ready()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>سرویس تلگرام</h2><p>جدول‌های این سرویس هنوز ساخته نشده‌اند. مایگریشن <span class="ltr">db/migrations/2026_11_01_telegram_user_service.sql</span> را اعمال کنید (<span class="ltr">make db-migrations-apply</span>).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$intField = static function (string $name, ?int $min = 0, ?int $max = null): ?int {
    $raw = trim(from_persian_digits((string)($_POST[$name] ?? '')));
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) return null;
    $v = (int)$raw;
    if ($min !== null) $v = max($min, $v);
    if ($max !== null) $v = min($max, $v);
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'settings') {
        set_setting('tg_enabled', !empty($_POST['tg_enabled']) ? '1' : '0');
        foreach ([
            'tg_price_text' => [0, null], 'tg_price_media' => [0, null], 'tg_price_check' => [0, null],
            'tg_default_max_accounts' => [1, 50], 'tg_default_daily_send' => [1, 100000], 'tg_default_daily_check' => [1, 100000],
            'tg_default_interval' => [3, 3600], 'tg_promo_warn_threshold' => [1, 100000],
        ] as $key => [$min, $max]) {
            $v = $intField($key, $min, $max);
            if ($v !== null) set_setting($key, (string)$v);
        }
        audit((int)$me['id'], 'telegram.settings', 'enabled=' . (!empty($_POST['tg_enabled']) ? 1 : 0));
        flash('success', 'تنظیمات سرویس تلگرام ذخیره شد.');
        redirect('/telegram-admin.php');
    }
    if ($action === 'user') {
        $ref = trim(from_persian_digits((string)($_POST['user_ref'] ?? '')));
        $targetId = ctype_digit($ref) ? (int)$ref : (backend_find_user_id_by_username($ref) ?? 0);
        if ($targetId <= 0 || backend_find_user_by_id($targetId) === null) {
            flash('error', 'کاربری با این نام کاربری یا شناسه پیدا نشد.');
            redirect('/telegram-admin.php');
        }
        db()->prepare('INSERT INTO ellsms_tg_user_settings
                         (user_id, enabled, max_accounts, daily_send_limit, daily_check_limit, min_interval_seconds, price_text, price_media, price_check, note, updated_by, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), max_accounts = VALUES(max_accounts), daily_send_limit = VALUES(daily_send_limit),
                         daily_check_limit = VALUES(daily_check_limit), min_interval_seconds = VALUES(min_interval_seconds), price_text = VALUES(price_text),
                         price_media = VALUES(price_media), price_check = VALUES(price_check), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
            ->execute([
                $targetId, !empty($_POST['enabled']) ? 1 : 0,
                $intField('max_accounts', 1, 50), $intField('daily_send_limit', 1, 100000), $intField('daily_check_limit', 1, 100000),
                $intField('min_interval_seconds', 3, 3600), $intField('price_text'), $intField('price_media'), $intField('price_check'),
                mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, (int)$me['id'], tg_now(),
            ]);
        audit((int)$me['id'], 'telegram.user_settings', 'user=' . $targetId . ' enabled=' . (!empty($_POST['enabled']) ? 1 : 0));
        flash('success', 'تنظیمات تلگرام کاربر ذخیره شد.');
        redirect('/telegram-admin.php?user=' . $targetId);
    }
    if ($action === 'disconnect') {
        $st = db()->prepare('SELECT user_id FROM ellsms_tg_accounts WHERE id = ?');
        $st->execute([(int)($_POST['account_id'] ?? 0)]);
        $owner = $st->fetchColumn();
        if ($owner !== false && tg_account_disconnect((int)$owner, (int)$_POST['account_id'], (int)$me['id'])) {
            flash('success', 'اکانت برای خروج علامت خورد.');
        }
        redirect('/telegram-admin.php#accounts');
    }
    if ($action === 'clear_limit') {
        db()->prepare("UPDATE ellsms_tg_accounts SET status = 'active', limited_until = NULL, updated_at = ? WHERE id = ? AND status = 'limited'")
            ->execute([tg_now(), (int)($_POST['account_id'] ?? 0)]);
        audit((int)$me['id'], 'telegram.account.clear_limit', 'account=' . (int)($_POST['account_id'] ?? 0));
        redirect('/telegram-admin.php#accounts');
    }
    redirect('/telegram-admin.php');
}

$worker = tg_worker_status();
$defaults = tg_defaults();
$editUserId = (int)($_GET['user'] ?? 0);
$editRow = $editUserId > 0 ? tg_user_settings_row($editUserId) : null;
$editUsername = $editUserId > 0 ? (backend_usernames_by_ids([$editUserId])[$editUserId] ?? (string)$editUserId) : '';

$userRows = db()->query('SELECT s.*,
                           (SELECT COUNT(*) FROM ellsms_tg_accounts a WHERE a.user_id = s.user_id AND a.status <> \'disconnected\') AS account_count
                         FROM ellsms_tg_user_settings s ORDER BY s.enabled DESC, s.updated_at DESC LIMIT 500')->fetchAll();
$accountStatus = (string)($_GET['account_status'] ?? '');
$accSql = 'SELECT id, user_id, phone, tg_username, display_name, status, limited_until, last_error, counter_date, sent_today, checked_today, last_action_at, logout_requested, created_at
           FROM ellsms_tg_accounts';
$accParams = [];
if (in_array($accountStatus, ['active', 'limited', 'disconnected', 'error'], true)) {
    $accSql .= ' WHERE status = ?';
    $accParams[] = $accountStatus;
}
$accSt = db()->prepare($accSql . ' ORDER BY id DESC LIMIT 300');
$accSt->execute($accParams);
$accounts = $accSt->fetchAll();
$recent = db()->query('SELECT c.*, (SELECT COUNT(*) FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND m.status IN (\'sent\',\'read\')) AS sent_count
                       FROM ellsms_tg_campaigns c ORDER BY c.id DESC LIMIT 30')->fetchAll();
$usernames = backend_usernames_by_ids(array_merge(array_column($userRows, 'user_id'), array_column($accounts, 'user_id'), array_column($recent, 'user_id')));
$today = date('Y-m-d');
$todayStats = db()->prepare("SELECT
    (SELECT COUNT(*) FROM ellsms_tg_messages WHERE sent_at >= ?) AS sent,
    (SELECT COUNT(*) FROM ellsms_tg_messages WHERE read_at >= ?) AS read_count,
    (SELECT COUNT(*) FROM ellsms_tg_messages WHERE status = 'failed' AND updated_at >= ?) AS failed,
    (SELECT COUNT(*) FROM ellsms_tg_lookup_items WHERE checked_at >= ? AND status IN ('found','not_found')) AS checked,
    (SELECT COUNT(*) FROM ellsms_tg_messages WHERE status IN ('queued','sending')) AS queued");
$todayStats->execute([$today, $today, $today, $today]);
$todayStats = $todayStats->fetch();

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">سرویس پردازش (tg-worker)</div>
    <div class="stat-value"><?= $worker['online'] ? '🟢 آنلاین' : '🔴 خاموش' ?></div>
    <div class="stat-foot"><?= $worker['last_seen_at'] ? 'آخرین گزارش: ' . jdate((string)$worker['last_seen_at']) : 'هنوز گزارشی نداده است' ?></div></div>
  <div class="stat"><div class="stat-label">ارسال / خوانده امروز</div>
    <div class="stat-value"><?= to_persian_digits(number_format((int)$todayStats['sent'])) ?> / <?= to_persian_digits(number_format((int)$todayStats['read_count'])) ?></div>
    <div class="stat-foot">ناموفق امروز: <?= to_persian_digits(number_format((int)$todayStats['failed'])) ?> · در صف: <?= to_persian_digits(number_format((int)$todayStats['queued'])) ?></div></div>
  <div class="stat"><div class="stat-label">بررسی شماره امروز</div><div class="stat-value"><?= to_persian_digits(number_format((int)$todayStats['checked'])) ?></div></div>
  <div class="stat"><div class="stat-label">اکانت‌های متصل در worker</div><div class="stat-value"><?= to_persian_digits((string)$worker['connected_accounts']) ?></div></div>
</div>

<div class="card" style="margin-top:18px">
  <h2>وضعیت راه‌اندازی</h2>
  <ul>
    <li>سوییچ سرویس: <b><?= tg_setting('tg_enabled', '0') === '1' ? 'روشن' : 'خاموش — در «تنظیمات عمومی» پایین همین صفحه تیک «سرویس فعال باشد» را بزنید و ذخیره کنید' ?></b></li>
    <li>کلید رمزنگاری در پنل (<span class="ltr">TELEGRAM_SESSION_KEY</span> یا <span class="ltr">SMS_GATEWAY_MASTER_KEY</span>): <b><?= tg_crypto_key() !== null ? 'تنظیم شده' : 'تنظیم نشده' ?></b></li>
    <li>کلید رمزنگاری در worker: <b><?= $worker['crypto_ready'] ? 'تنظیم شده' : 'نامشخص / تنظیم نشده' ?></b></li>
    <li>مشخصات API تلگرام (<span class="ltr">TELEGRAM_API_ID</span> و <span class="ltr">TELEGRAM_API_HASH</span> از <span class="ltr">my.telegram.org</span>): <b><?= $worker['api_configured'] ? 'تنظیم شده' : 'تنظیم نشده' ?></b></li>
    <?php if ($worker['note']): ?><li>پیام worker: <span class="ltr"><?= e((string)$worker['note']) ?></span></li><?php endif; ?>
  </ul>
  <p class="hint">این مقادیر در فایل <span class="ltr">.env</span> سرور تنظیم می‌شوند، نه در پایگاه‌داده. راهنما: <span class="ltr">docs/telegram-user-service.md</span></p>
</div>

<div class="card" style="margin-top:18px">
  <h2>تنظیمات عمومی</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <p><label><input type="checkbox" name="tg_enabled" value="1"<?= tg_setting('tg_enabled', '0') === '1' ? ' checked' : '' ?>> سرویس فعال باشد</label></p>
    <div class="toolbar">
      <label>هزینه‌ی پیام متنی (ریال) <input type="number" min="0" name="tg_price_text" class="ltr" value="<?= $defaults['price_text'] ?>"></label>
      <label>هزینه‌ی پیام با تصویر/فایل (ریال) <input type="number" min="0" name="tg_price_media" class="ltr" value="<?= $defaults['price_media'] ?>"></label>
      <label>هزینه‌ی بررسی هر شماره (ریال) <input type="number" min="0" name="tg_price_check" class="ltr" value="<?= $defaults['price_check'] ?>"></label>
    </div>
    <div class="toolbar">
      <label>پیش‌فرض تعداد اکانت <input type="number" min="1" max="50" name="tg_default_max_accounts" class="ltr" value="<?= $defaults['max_accounts'] ?>"></label>
      <label>پیش‌فرض ارسال روزانه‌ی هر اکانت <input type="number" min="1" name="tg_default_daily_send" class="ltr" value="<?= $defaults['daily_send_limit'] ?>"></label>
      <label>پیش‌فرض بررسی روزانه‌ی هر اکانت <input type="number" min="1" name="tg_default_daily_check" class="ltr" value="<?= $defaults['daily_check_limit'] ?>"></label>
      <label>پیش‌فرض فاصله‌ی بین پیام‌ها (ثانیه) <input type="number" min="3" name="tg_default_interval" class="ltr" value="<?= $defaults['min_interval_seconds'] ?>"></label>
      <label>هشدار تبلیغ از چند گیرنده به بالا <input type="number" min="1" name="tg_promo_warn_threshold" class="ltr" value="<?= tg_setting_int('tg_promo_warn_threshold', 30) ?>"></label>
    </div>
    <p class="hint">پیشنهاد: برای اکانت‌های تازه ارسال روزانه‌ی ۳۰ تا ۵۰ و بررسی روزانه‌ی ۱۰۰ تا ۲۰۰ شماره. عددهای بزرگ‌تر احتمال محدود شدن اکانت توسط تلگرام را بالا می‌برد.
      برای مدیران سامانه ارسال رایگان است.</p>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>

<div class="card" style="margin-top:18px" id="users">
  <h2><?= $editUserId > 0 ? 'تنظیمات کاربر ' . e($editUsername) : 'فعال‌سازی برای کاربر' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="user">
    <div class="toolbar">
      <label>نام کاربری یا شناسه <input type="text" name="user_ref" class="ltr" required value="<?= e($editUsername) ?>"></label>
      <label><input type="checkbox" name="enabled" value="1"<?= ($editRow === null || (int)$editRow['enabled'] === 1) ? ' checked' : '' ?>> سرویس برای این کاربر فعال باشد</label>
    </div>
    <p class="hint">هر فیلد خالی یعنی «همان پیش‌فرض بالا».</p>
    <div class="toolbar">
      <?php foreach ([
          'max_accounts' => 'تعداد اکانت', 'daily_send_limit' => 'ارسال روزانه‌ی هر اکانت', 'daily_check_limit' => 'بررسی روزانه‌ی هر اکانت',
          'min_interval_seconds' => 'فاصله‌ی بین پیام‌ها (ثانیه)', 'price_text' => 'هزینه‌ی پیام متنی', 'price_media' => 'هزینه‌ی پیام با فایل', 'price_check' => 'هزینه‌ی بررسی',
      ] as $field => $label): ?>
        <label><?= e($label) ?> <input type="number" min="0" name="<?= $field ?>" class="ltr" style="width:110px" value="<?= $editRow !== null && $editRow[$field] !== null ? (int)$editRow[$field] : '' ?>"></label>
      <?php endforeach; ?>
    </div>
    <label style="display:block">یادداشت <input type="text" name="note" maxlength="255" style="width:100%" value="<?= e((string)($editRow['note'] ?? '')) ?>"></label>
    <button class="btn btn-primary">ذخیره</button>
    <?php if ($editUserId > 0): ?><a class="btn" href="/telegram-admin.php#users">کاربر جدید</a><?php endif; ?>
  </form>
  <div class="table-wrap" style="margin-top:12px">
  <table>
    <tr><th>کاربر</th><th>وضعیت</th><th>اکانت‌ها</th><th>ارسال/روز</th><th>بررسی/روز</th><th>فاصله</th><th>قیمت‌ها (متن/فایل/بررسی)</th><th>یادداشت</th><th></th></tr>
    <?php foreach ($userRows as $u): ?>
      <?php $cfg = tg_user_config(['id' => (int)$u['user_id'], 'role' => 'user']); ?>
      <tr>
        <td><?= e($usernames[(int)$u['user_id']] ?? (string)$u['user_id']) ?></td>
        <td><?= (int)$u['enabled'] === 1 ? '<span class="badge badge-active">فعال</span>' : '<span class="badge badge-off">غیرفعال</span>' ?></td>
        <td class="num"><?= to_persian_digits((string)$u['account_count']) ?> / <?= to_persian_digits((string)$cfg['max_accounts']) ?></td>
        <td class="num"><?= to_persian_digits((string)$cfg['daily_send_limit']) ?></td>
        <td class="num"><?= to_persian_digits((string)$cfg['daily_check_limit']) ?></td>
        <td class="num"><?= to_persian_digits((string)$cfg['min_interval_seconds']) ?></td>
        <td class="num"><?= to_persian_digits($cfg['price_text'] . ' / ' . $cfg['price_media'] . ' / ' . $cfg['price_check']) ?></td>
        <td><?= e((string)($u['note'] ?? '')) ?></td>
        <td><a class="btn btn-sm" href="/telegram-admin.php?user=<?= (int)$u['user_id'] ?>#users">ویرایش</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$userRows): ?><tr><td colspan="9" class="empty">هنوز برای هیچ کاربری فعال نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px" id="accounts">
  <h2>همه‌ی اکانت‌های متصل</h2>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="account_status" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach (['active', 'limited', 'disconnected', 'error'] as $s): ?><option value="<?= $s ?>"<?= $accountStatus === $s ? ' selected' : '' ?>><?= e(tg_status_label($s)) ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>کاربر</th><th>شماره</th><th>نام در تلگرام</th><th>وضعیت</th><th>ارسال/بررسی امروز</th><th>آخرین فعالیت</th><th>آخرین خطا</th><th></th></tr>
    <?php foreach ($accounts as $a): ?>
      <?php $isToday = (string)$a['counter_date'] === $today; ?>
      <tr>
        <td><?= e($usernames[(int)$a['user_id']] ?? (string)$a['user_id']) ?></td>
        <td class="msisdn ltr"><?= e('+' . $a['phone']) ?></td>
        <td><?= e((string)($a['display_name'] ?? '')) ?><?= $a['tg_username'] ? ' <span class="muted ltr">@' . e((string)$a['tg_username']) . '</span>' : '' ?></td>
        <td><?= tg_status_badge((string)$a['status']) ?><?php if ($a['status'] === 'limited' && $a['limited_until']): ?><div class="muted">تا <?= jdate((string)$a['limited_until']) ?></div><?php endif; ?></td>
        <td class="num"><?= to_persian_digits(($isToday ? (int)$a['sent_today'] : 0) . ' / ' . ($isToday ? (int)$a['checked_today'] : 0)) ?></td>
        <td class="num"><?= $a['last_action_at'] ? jdate((string)$a['last_action_at']) : '—' ?></td>
        <td class="muted"><?= e(mb_strimwidth((string)($a['last_error'] ?? ''), 0, 80, '…')) ?></td>
        <td>
          <?php if ($a['status'] === 'limited'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_limit"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm">رفع محدودیت</button></form>
          <?php endif; ?>
          <?php if ($a['status'] !== 'disconnected' && (int)$a['logout_requested'] === 0): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('این اکانت از تلگرام خارج شود؟');"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-danger">قطع اتصال</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="8" class="empty">اکانتی نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2>آخرین ارسال‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>کاربر</th><th>نوع</th><th>منبع</th><th>گیرندگان</th><th>ارسال‌شده</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($recent as $c): ?>
      <tr>
        <td class="num"><a href="/telegram-send.php?id=<?= (int)$c['id'] ?>"><?= to_persian_digits((string)$c['id']) ?></a></td>
        <td><?= e($usernames[(int)$c['user_id']] ?? (string)$c['user_id']) ?></td>
        <td><?= e(TG_KINDS[$c['kind']] ?? $c['kind']) ?></td>
        <td><?= $c['source'] === 'api' ? 'API' : 'پنل' ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$c['sent_count'])) ?></td>
        <td><?= tg_status_badge((string)$c['status']) ?></td>
        <td class="num"><?= jdate((string)$c['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="8" class="empty">هنوز ارسالی نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
