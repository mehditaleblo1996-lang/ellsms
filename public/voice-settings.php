<?php
/**
 * Voice messages (ZarinCall) — platform admin settings: the switch, the API key (stored encrypted,
 * never shown again), prices and limits, plus a connection check that reads the ZarinCall balance.
 * docs/voice-messages.md.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'سرویس پیام صوتی';
$active = 'voice_settings';

$priceFields = [
    'voice_price_per_pulse' => 'هزینه‌ی هر پالس برای هر گیرنده (ریال)',
    'voice_otp_price'       => 'هزینه‌ی هر کد تایید صوتی (ریال)',
    'voice_ivc_extra_price' => 'هزینه‌ی اضافه‌ی هر گیرنده در ارسال تعاملی (ریال)',
    'voice_tts_price'       => 'هزینه‌ی هر تبدیل متن به صوت (ریال)',
];
$numberFields = [
    'voice_pulse_seconds'            => ['طول هر پالس (ثانیه)', 30, 1, 600],
    'voice_batch_size'               => ['تعداد شماره در هر درخواست به زرین‌کال', 1000, 1, 10000],
    'voice_max_recipients'           => ['حداکثر گیرنده در هر ارسال', 10000, 1, 1000000],
    'voice_poll_interval_seconds'    => ['فاصله‌ی استعلام وضعیت (ثانیه)', 120, 30, 3600],
    'voice_settle_after_hours'       => ['تسویه‌ی اجباری پس از (ساعت)', 48, 1, 720],
    'voice_tts_save_days'            => ['مدت نگهداری فایل‌های متن‌به‌صوت در زرین‌کال (روز)', 365, 0, 3650],
    'voice_otp_min_interval_seconds' => ['حداقل فاصله‌ی دو کد صوتی به یک شماره (ثانیه)', 60, 0, 3600],
];

$connection = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? 'save');
    if ($do === 'test') {
        $connection = zarincall_user_pulse();
        audit((int)$me['id'], 'voice.settings.test', $connection['ok'] ? 'ok' : 'failed');
    } else {
        $baseUrl = trim((string)($_POST['voice_base_url'] ?? ''));
        $baseUrl = $baseUrl === '' ? ZARINCALL_DEFAULT_BASE_URL : rtrim($baseUrl, '/');
        $error = null;
        if (preg_match('#^https?://#i', $baseUrl) !== 1 || (app_env() === 'production' && preg_match('#^https://#i', $baseUrl) !== 1)) {
            $error = 'آدرس سرویس باید با https:// شروع شود.';
        }
        $prices = [];
        foreach ($priceFields as $key => $label) {
            $v = preg_replace('/\D/', '', from_persian_digits((string)($_POST[$key] ?? ''))) ?? '';
            $prices[$key] = $v === '' ? '' : (string)(int)$v;
        }
        if ($error === null && !empty($_POST['voice_enabled']) && $prices['voice_price_per_pulse'] === '') {
            $error = 'برای فعال‌سازی، هزینه‌ی هر پالس را مشخص کنید.';
        }
        $newKey = trim((string)($_POST['voice_api_key'] ?? ''));
        if ($error === null && !empty($_POST['voice_enabled']) && $newKey === '' && !zarincall_api_key_stored()) {
            $error = 'برای فعال‌سازی، کلید API زرین‌کال را وارد کنید.';
        }
        $readyStatuses = preg_replace('/[^\d,\-]/', '', str_replace(['،', ' '], [',', ''], from_persian_digits((string)($_POST['voice_ready_statuses'] ?? '')))) ?? '';
        $speakersRaw = trim((string)($_POST['voice_tts_speakers'] ?? ''));
        if ($error === null && voice_tts_speakers_parse($speakersRaw) === []) {
            $error = 'گوینده‌های متن به صوت: دست‌کم یک خط به شکل «مقدار=نام» (مثلاً «1=مرد») لازم است.';
        }
        if ($error === null && $newKey !== '') {
            try {
                zarincall_api_key_save($newKey);
            } catch (ZarinCallKeyException $e) {
                $error = $e->getMessage();
            }
        }
        if ($error === null && !empty($_POST['voice_api_key_clear'])) {
            zarincall_api_key_save('');
        }
        if ($error !== null) {
            flash('error', $error);
        } else {
            set_setting('voice_enabled', !empty($_POST['voice_enabled']) ? '1' : '0');
            set_setting('voice_base_url', $baseUrl);
            foreach ($prices as $key => $v) {
                set_setting($key, $v);
            }
            set_setting('voice_ready_statuses', trim($readyStatuses, ','));
            $speakers = voice_tts_speakers_parse($speakersRaw);
            set_setting('voice_tts_speakers', implode("\n", array_map(static fn($v, $l) => "{$v}={$l}", array_keys($speakers), $speakers)));
            foreach ($numberFields as $key => [, $default, $min, $max]) {
                $v = (int)from_persian_digits((string)($_POST[$key] ?? $default));
                set_setting($key, (string)max($min, min($max, $v)));
            }
            audit((int)$me['id'], 'voice.settings', 'enabled=' . (!empty($_POST['voice_enabled']) ? 1 : 0)
                . ' host=' . (parse_url($baseUrl, PHP_URL_HOST) ?: '') . ($newKey !== '' ? ' key=changed' : ''));
            flash('success', 'تنظیمات سرویس پیام صوتی ذخیره شد.');
        }
        redirect('/voice-settings.php');
    }
}

$masterKeyOk = zarincall_secret_key() !== null;
$keyStored = zarincall_api_key_stored();
$keyReadable = $keyStored && zarincall_api_key() !== '';
$stats = db()->query("SELECT
        SUM(status IN ('scheduled')) scheduled, SUM(status IN ('sending','sent')) running,
        SUM(status = 'completed') completed, SUM(status = 'failed') failed, COALESCE(SUM(charged_cost),0) charged
    FROM ellsms_voice_messages WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch() ?: [];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>وضعیت</h2>
  <p>
    سرویس: <span class="badge <?= voice_configured() ? 'badge-ok' : 'badge-off' ?>"><?= voice_configured() ? 'آماده' : 'غیرفعال' ?></span>
    &nbsp; کد تایید صوتی: <span class="badge <?= voice_otp_configured() ? 'badge-ok' : 'badge-off' ?>"><?= voice_otp_configured() ? 'آماده' : 'غیرفعال' ?></span>
    &nbsp; کلید API: <b><?= !$keyStored ? 'ثبت نشده' : ($keyReadable ? 'ثبت شده' : 'ثبت شده اما قابل خواندن نیست (کلید اصلی تغییر کرده؛ دوباره وارد کنید)') ?></b>
  </p>
  <?php if (!$masterKeyOk): ?>
    <p class="flash flash-error">برای ذخیره‌ی رمزنگاری‌شده‌ی کلید API، متغیر محیطی <span class="ltr">SMS_GATEWAY_MASTER_KEY</span> (حداقل ۳۲ نویسه) باید تنظیم شده باشد.</p>
  <?php endif; ?>
  <p class="hint">
    ۳۰ روز اخیر: <?= to_persian_digits((string)(int)($stats['scheduled'] ?? 0)) ?> زمان‌بندی‌شده،
    <?= to_persian_digits((string)(int)($stats['running'] ?? 0)) ?> در حال انجام،
    <?= to_persian_digits((string)(int)($stats['completed'] ?? 0)) ?> پایان‌یافته،
    <?= to_persian_digits((string)(int)($stats['failed'] ?? 0)) ?> ناموفق —
    درآمد: <?= to_persian_digits(number_format((int)($stats['charged'] ?? 0))) ?> ریال.
  </p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="test">
    <button class="btn" <?= $keyReadable ? '' : 'disabled' ?>>بررسی اتصال و مانده‌ی حساب زرین‌کال</button>
  </form>
  <?php if ($connection !== null): ?>
    <?php if ($connection['ok']): ?>
      <p class="flash flash-success">اتصال برقرار است. مانده‌ی حساب زرین‌کال: <b class="ltr"><?= e(to_persian_digits(number_format((int)$connection['pulse']))) ?></b></p>
    <?php else: ?>
      <p class="flash flash-error">اتصال ناموفق: <span class="ltr"><?= e((string)$connection['error']) ?></span></p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:18px">
  <h2>تنظیمات زرین‌کال</h2>
  <p class="hint">
    هزینه‌ها ریالی و از کیف پول مشتری کسر می‌شوند: هنگام ارسال، سقف هزینه (هزینه‌ی هر گیرنده × تعداد گیرندگان) رزرو می‌شود و
    پس از گزارش زرین‌کال فقط تماس‌های پاسخ‌داده‌شده کسر و بقیه به کیف پول برمی‌گردد. هزینه‌ی هر گیرنده = تعداد پالس فایل × هزینه‌ی هر پالس
    (تعداد پالس = مدت فایل تقسیم بر طول پالس، به بالا گرد می‌شود). تا وقتی هزینه‌ی کد تایید خالی باشد، کد تایید صوتی غیرفعال است.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div class="toolbar">
      <label><input type="checkbox" name="voice_enabled" value="1"<?= voice_enabled() ? ' checked' : '' ?>> فعال</label>
      <label style="flex:1 1 320px">آدرس سرویس <input type="url" name="voice_base_url" class="ltr" style="width:100%" value="<?= e(zarincall_base_url()) ?>"></label>
    </div>
    <div class="toolbar">
      <label style="flex:1 1 320px">کلید API زرین‌کال
        <input type="password" name="voice_api_key" class="ltr" style="width:100%" autocomplete="off" placeholder="<?= $keyStored ? '•••••••• (برای تغییر، کلید جدید را وارد کنید)' : '' ?>">
      </label>
      <?php if ($keyStored): ?><label><input type="checkbox" name="voice_api_key_clear" value="1"> حذف کلید ذخیره‌شده</label><?php endif; ?>
    </div>
    <div class="toolbar">
      <?php foreach ($priceFields as $key => $label): ?>
        <label><?= e($label) ?> <input type="text" inputmode="numeric" name="<?= e($key) ?>" class="ltr" value="<?= e(voice_setting($key, '')) ?>"></label>
      <?php endforeach; ?>
    </div>
    <div class="toolbar">
      <?php foreach ($numberFields as $key => [$label, $default, $min, $max]): ?>
        <label><?= e($label) ?> <input type="number" name="<?= e($key) ?>" class="ltr" min="<?= $min ?>" max="<?= $max ?>" value="<?= (int)voice_setting($key, (string)$default) ?>"></label>
      <?php endforeach; ?>
    </div>
    <label style="display:block">کدهای وضعیت «آماده‌ی ارسال» فایل در زرین‌کال (با کاما جدا؛ خالی = بدون بررسی، فقط زرین‌کال تصمیم می‌گیرد)
      <input type="text" name="voice_ready_statuses" class="ltr" value="<?= e(implode(',', voice_ready_statuses())) ?>" placeholder="مثلاً 1">
      <span class="hint">زرین‌کال هر فایل جدید را پیش از ارسال بررسی می‌کند و کد وضعیتش را مستند نکرده است. کد وضعیت فایلی که ارسالش موفق بود را از صفحه‌ی فایل‌های صوتی ببینید و اینجا وارد کنید؛ از آن پس فایل‌های تاییدنشده قبل از ارسال رد می‌شوند و هر پنج دقیقه دوباره بررسی می‌شوند.</span>
    </label>
    <label style="display:block">گوینده‌های متن به صوت — هر خط «مقدار=نام»؛ مقدار همان gender در زرین‌کال است
      <textarea name="voice_tts_speakers" rows="3" style="width:100%"><?= e(implode("\n", array_map(static fn($v, $l) => "{$v}={$l}", array_keys(voice_tts_speakers()), voice_tts_speakers()))) ?></textarea>
    </label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
