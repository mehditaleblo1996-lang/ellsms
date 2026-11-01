<?php
/**
 * Telegram user-account service — connect / disconnect the customer's own Telegram accounts.
 * The login itself runs in the tg-worker container (app/TelegramUser.php); this page creates the login
 * request, then shows each step (code, 2FA password) as the worker reaches it.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
tg_require_user($me);
$pageTitle = 'اکانت‌های تلگرام';
$active = 'telegram';
$userId = (int)$me['id'];

// Small JSON endpoint the page polls while the worker is busy with a login step.
if (isset($_GET['login'], $_GET['poll'])) {
    $req = tg_login_find($userId, (int)$_GET['login']);
    header('Content-Type: application/json');
    echo json_encode(['state' => $req['state'] ?? 'missing']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $loginId = (int)($_POST['login_id'] ?? 0);
    if ($action === 'start') {
        if (empty($_POST['consent'])) {
            flash('error', 'برای اتصال اکانت باید شرایط استفاده را تأیید کنید.');
            redirect('/telegram-accounts.php');
        }
        $r = tg_login_start($me, (string)($_POST['phone'] ?? ''));
        if (!$r['ok']) {
            flash('error', $r['error']);
            redirect('/telegram-accounts.php');
        }
        redirect('/telegram-accounts.php?login=' . $r['id']);
    }
    if ($action === 'code') {
        $r = tg_login_submit_code($me, $loginId, (string)($_POST['code'] ?? ''));
        if (!$r['ok']) flash('error', $r['error']);
        redirect('/telegram-accounts.php?login=' . $loginId);
    }
    if ($action === 'password') {
        $r = tg_login_submit_password($me, $loginId, (string)($_POST['password'] ?? ''));
        if (!$r['ok']) flash('error', $r['error']);
        redirect('/telegram-accounts.php?login=' . $loginId);
    }
    if ($action === 'cancel_login') {
        tg_login_cancel($me, $loginId);
        redirect('/telegram-accounts.php');
    }
    if ($action === 'disconnect') {
        if (tg_account_disconnect($userId, (int)($_POST['account_id'] ?? 0), $userId)) {
            flash('success', 'درخواست خروج ثبت شد؛ اکانت تا چند ثانیه‌ی دیگر از تلگرام خارج و نشست آن پاک می‌شود.');
        }
        redirect('/telegram-accounts.php');
    }
    if ($action === 'forget') {
        tg_account_forget($userId, (int)($_POST['account_id'] ?? 0), $userId);
        redirect('/telegram-accounts.php');
    }
    redirect('/telegram-accounts.php');
}

$config = tg_user_config($me);
$worker = tg_worker_status();
$accounts = tg_accounts_for_user($userId);
$login = isset($_GET['login']) ? tg_login_find($userId, (int)$_GET['login']) : null;
$busyStates = ['requested', 'code_submitted', 'password_submitted'];
$connectedCount = tg_connected_account_count($userId);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$worker['online']): ?>
  <div class="flash flash-error"><span class="flash-text">سرویس پردازش تلگرام در حال حاضر در دسترس نیست؛ درخواست‌ها در صف می‌مانند و به محض راه‌اندازی انجام می‌شوند.</span></div>
<?php endif; ?>

<?php if ($login): ?>
<div class="card" id="loginCard" data-login="<?= (int)$login['id'] ?>" data-state="<?= e((string)$login['state']) ?>">
  <h2>اتصال اکانت <span class="ltr"><?= e('+' . $login['phone']) ?></span></h2>
  <?php if (in_array($login['state'], $busyStates, true)): ?>
    <p><span class="badge badge-processing">در حال انجام</span>
      <?= $login['state'] === 'requested' ? 'در حال درخواست کد ورود از تلگرام…' : 'در حال بررسی با تلگرام…' ?></p>
    <p class="hint">این صفحه خودکار به‌روز می‌شود.</p>
  <?php elseif ($login['state'] === 'code_sent'): ?>
    <p>کد ورود به <b>اپلیکیشن تلگرام</b> همین شماره (در گفتگوی «Telegram») ارسال شد. اگر در تلگرام نیامد، ممکن است با پیامک برسد.</p>
    <p class="hint">⚠️ کد را برای هیچ‌کس — حتی در تلگرام — نفرستید؛ تلگرام ورودی را که کدش جایی فرستاده شده باشد مسدود می‌کند. فقط همین‌جا وارد کنید.</p>
    <?php if ($login['error']): ?><div class="flash flash-error"><span class="flash-text"><?= e((string)$login['error']) ?></span></div><?php endif; ?>
    <form method="post" class="toolbar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="code">
      <input type="hidden" name="login_id" value="<?= (int)$login['id'] ?>">
      <label>کد ورود <input type="text" name="code" class="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus></label>
      <button class="btn btn-primary">تأیید کد</button>
    </form>
  <?php elseif ($login['state'] === 'password_needed'): ?>
    <p>این اکانت <b>تأیید دومرحله‌ای</b> دارد. رمز دومرحله‌ای تلگرام را وارد کنید.<?php if ($login['password_hint']): ?> راهنمای رمز: <b><?= e((string)$login['password_hint']) ?></b><?php endif; ?></p>
    <p class="hint">رمز فقط برای همین ورود استفاده و بلافاصله پاک می‌شود؛ هیچ‌جا ذخیره نمی‌شود.</p>
    <?php if ($login['error']): ?><div class="flash flash-error"><span class="flash-text"><?= e((string)$login['error']) ?></span></div><?php endif; ?>
    <form method="post" class="toolbar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="login_id" value="<?= (int)$login['id'] ?>">
      <label>رمز دومرحله‌ای <input type="password" name="password" class="ltr" autocomplete="off" required autofocus></label>
      <button class="btn btn-primary">ورود</button>
    </form>
  <?php elseif ($login['state'] === 'done'): ?>
    <div class="flash flash-success"><span class="flash-text">اکانت با موفقیت متصل شد.</span></div>
    <a class="btn" href="/telegram-accounts.php">بازگشت به فهرست اکانت‌ها</a>
  <?php else: ?>
    <div class="flash flash-error"><span class="flash-text"><?= $login['state'] === 'expired' ? 'مهلت این ورود تمام شد یا لغو شد.' : 'ورود ناموفق بود: ' . e((string)($login['error'] ?: 'خطای نامشخص')) ?></span></div>
    <a class="btn" href="/telegram-accounts.php">شروع دوباره</a>
  <?php endif; ?>
  <?php if (!in_array($login['state'], ['done', 'failed', 'expired'], true)): ?>
    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_login">
      <input type="hidden" name="login_id" value="<?= (int)$login['id'] ?>">
      <button class="btn btn-sm">انصراف</button>
    </form>
  <?php endif; ?>
</div>
<?php if (in_array($login['state'], $busyStates, true)): ?>
<script>
(function () {
  var card = document.getElementById('loginCard');
  var id = card.getAttribute('data-login'), state = card.getAttribute('data-state'), tries = 0;
  function poll() {
    if (++tries > 150) return;
    fetch('/telegram/accounts?poll=1&login=' + encodeURIComponent(id), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.state !== state) { location.reload(); } else { setTimeout(poll, 2000); } })
      .catch(function () { setTimeout(poll, 4000); });
  }
  setTimeout(poll, 1500);
})();
</script>
<?php endif; ?>
<?php endif; ?>

<div class="card"<?= $login ? ' style="margin-top:18px"' : '' ?>>
  <h2>اکانت‌های متصل</h2>
  <p class="hint">
    سقف شما: <b><?= to_persian_digits((string)$config['max_accounts']) ?></b> اکانت ·
    هر اکانت روزانه حداکثر <b><?= to_persian_digits((string)$config['daily_send_limit']) ?></b> پیام و
    <b><?= to_persian_digits((string)$config['daily_check_limit']) ?></b> بررسی شماره ·
    فاصله‌ی بین دو پیام <b><?= to_persian_digits((string)$config['min_interval_seconds']) ?></b> ثانیه.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>شماره</th><th>نام در تلگرام</th><th>وضعیت</th><th>ارسال امروز</th><th>بررسی امروز</th><th>آخرین فعالیت</th><th></th></tr>
    <?php foreach ($accounts as $a): ?>
      <tr>
        <td class="msisdn ltr"><?= e('+' . $a['phone']) ?></td>
        <td><?= e((string)($a['display_name'] ?? '')) ?><?php if ($a['tg_username']): ?> <span class="muted ltr">@<?= e((string)$a['tg_username']) ?></span><?php endif; ?></td>
        <td>
          <?php if ((int)$a['logout_requested'] === 1 && $a['status'] !== 'disconnected'): ?><span class="badge badge-processing">در حال خروج</span>
          <?php else: ?><?= tg_status_badge((string)$a['status']) ?><?php endif; ?>
          <?php if ($a['status'] === 'limited' && $a['limited_until']): ?><div class="muted">تا <?= jdate((string)$a['limited_until']) ?></div><?php endif; ?>
          <?php if ($a['last_error'] && in_array($a['status'], ['limited', 'error', 'disconnected'], true)): ?><div class="muted"><?= e(mb_strimwidth((string)$a['last_error'], 0, 90, '…')) ?></div><?php endif; ?>
        </td>
        <td class="num"><?= to_persian_digits((string)$a['sent_today']) ?> / <?= to_persian_digits((string)$config['daily_send_limit']) ?></td>
        <td class="num"><?= to_persian_digits((string)$a['checked_today']) ?> / <?= to_persian_digits((string)$config['daily_check_limit']) ?></td>
        <td class="num"><?= $a['last_action_at'] ? jdate((string)$a['last_action_at']) : '—' ?></td>
        <td>
          <?php if ($a['status'] !== 'disconnected' && (int)$a['logout_requested'] === 0): ?>
            <form method="post" onsubmit="return confirm('اکانت از تلگرام خارج شود و نشست آن پاک شود؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="disconnect">
              <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-sm btn-danger">قطع اتصال</button>
            </form>
          <?php elseif ($a['status'] === 'disconnected'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="forget">
              <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-sm">حذف از فهرست</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="7" class="empty">هنوز اکانتی متصل نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<?php if (!$login && $connectedCount < $config['max_accounts']): ?>
<div class="card" style="margin-top:18px">
  <h2>اتصال اکانت جدید</h2>
  <p>شماره‌ی اکانت تلگرامی را که می‌خواهید پیام‌ها از طرف آن ارسال شود وارد کنید. یک کد ورود در اپلیکیشن تلگرام همان شماره دریافت می‌کنید.</p>
  <ul class="hint">
    <li>با اتصال، این سامانه مثل یک دستگاه وارد اکانت شما می‌شود و فقط برای ارسال پیام، بررسی شماره‌ها و دیدن وضعیت «خوانده شد» از آن استفاده می‌کند؛ گفتگوهای شما خوانده یا ذخیره نمی‌شود.</li>
    <li>نشست اکانت رمزنگاری‌شده نگه داشته می‌شود. هر وقت بخواهید با «قطع اتصال» در همین صفحه، یا از تلگرام (Settings › Devices) آن را پایان دهید.</li>
    <li><b>هشدار:</b> ارسال انبوه، تبلیغاتی یا به افرادی که شما را نمی‌شناسند ممکن است باعث محدود یا مسدود شدن اکانت شما از طرف تلگرام شود. مسئولیت محتوا و گیرندگان با شماست.</li>
  </ul>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start">
    <div class="toolbar">
      <label>شماره موبایل <input type="tel" name="phone" class="ltr" placeholder="09121234567 یا +44…" required></label>
    </div>
    <p><label><input type="checkbox" name="consent" value="1" required> شرایط بالا را خواندم و می‌پذیرم.</label></p>
    <button class="btn btn-primary">دریافت کد ورود</button>
  </form>
</div>
<?php elseif (!$login): ?>
<p class="hint" style="margin-top:12px">به سقف تعداد اکانت مجاز رسیده‌اید؛ برای اکانت بیشتر با پشتیبانی تماس بگیرید.</p>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
