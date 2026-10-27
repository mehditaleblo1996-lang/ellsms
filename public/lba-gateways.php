<?php
/**
 * Platform admin -> LBA gateways (docs/lba.md): the gateways that carry location-based sends, kept apart
 * from the ordinary SMS gateways. Per gateway: service address, token (and the optional panel
 * username/password), the fixed tariff per part, a connection check; which numbers send LBA through
 * which gateway; and one-off sends whose answer was lost, to settle as sent or refund.
 *
 * Secrets are write-only: a blank field keeps the stored value, and nothing secret is rendered back.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'درگاه‌های LBA';
$active = 'lba_gateways';

if (!lba_tables_ready()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p class="alert alert-error">جدول‌های LBA هنوز ساخته نشده‌اند: مایگریشن <span class="ltr">2026_10_27_lba.sql</span> را اجرا کنید (<span class="ltr">make db-migrations-apply</span>).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$editId = (int)($_GET['edit'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if (impersonation_guard_post('gateway.config')) redirect('/lba-gateways.php');
    $gid = (int)($_POST['gateway_id'] ?? 0);
    try {
        switch ($do) {
            case 'save':
                $r = lba_gateway_save($_POST, (int)$me['id'], $gid > 0 ? $gid : null);
                if ($r['ok']) {
                    flash('success', 'درگاه LBA ذخیره شد.');
                    redirect('/lba-gateways.php?edit=' . $r['id']);
                }
                flash('error', $r['error']);
                $_SESSION['lba_gateway_form'] = array_diff_key($_POST, array_flip(['token', 'password', '_csrf']));
                redirect('/lba-gateways.php' . ($gid > 0 ? '?edit=' . $gid : '?new=1'));
            case 'test':
                $g = lba_gateway_get($gid);
                if ($g === null) { flash('error', 'درگاه پیدا نشد.'); break; }
                $t = lba_gateway_test($g, (int)$me['id']);
                flash($t['ok'] ? 'success' : 'error', $t['message']);
                redirect('/lba-gateways.php?edit=' . $gid);
            case 'archive':
            case 'restore':
                lba_gateway_set_archived($gid, $do === 'archive', (int)$me['id']);
                flash('success', $do === 'archive' ? 'درگاه بایگانی شد؛ خط‌های آن دیگر ارسال LBA ندارند.' : 'درگاه از بایگانی خارج شد (برای استفاده دوباره فعالش کنید).');
                break;
            case 'number':
                $r = lba_number_set_gateway((int)($_POST['number_id'] ?? 0), ($v = (int)($_POST['lba_gateway_id'] ?? 0)) > 0 ? $v : null, (int)$me['id']);
                $r['ok'] ? flash('success', 'درگاه LBA شماره ذخیره شد.') : flash('error', $r['error']);
                redirect('/lba-gateways.php#numbers');
            case 'resolve_sent':
            case 'resolve_refund':
                $r = lba_send_resolve($me, (int)($_POST['send_id'] ?? 0), $do === 'resolve_sent');
                $r['ok'] ? flash('success', $do === 'resolve_sent' ? 'ارسال ثبت‌شده حساب شد و هزینه کسر شد.' : 'هزینه به کیف پول مشتری برگشت.') : flash('error', $r['error']);
                redirect('/lba-gateways.php#unknown');
        }
    } catch (LbaSecretException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/lba-gateways.php');
}

$gateways = lba_gateways_list();
$gatewaysById = array_column($gateways, null, 'id');
$editing = $editId > 0 ? ($gatewaysById[$editId] ?? null) : null;
$showForm = $editing !== null || isset($_GET['new']) || $gateways === [];
$form = $_SESSION['lba_gateway_form'] ?? null;
unset($_SESSION['lba_gateway_form']);
$fv = static fn(string $k, string $d = ''): string => (string)($form[$k] ?? ($editing[$k] ?? $d));
$stored = $editing ? lba_secrets_stored((int)$editing['id']) : array_fill_keys(LBA_SECRET_KEYS, false);

$numbers = db()->query('SELECT id, number, label, assigned_user_id, lba_gateway_id FROM ellsms_numbers ORDER BY (lba_gateway_id IS NULL), number')->fetchAll();
$numberUsers = backend_usernames_by_ids(array_column($numbers, 'assigned_user_id'));
$numbersPerGateway = [];
foreach ($numbers as $n) if ($n['lba_gateway_id'] !== null) $numbersPerGateway[(int)$n['lba_gateway_id']] = ($numbersPerGateway[(int)$n['lba_gateway_id']] ?? 0) + 1;
$unknownSends = db()->query("SELECT s.*, r.title FROM ellsms_lba_sends s JOIN ellsms_lba_requests r ON r.id = s.request_id WHERE s.status = 'unknown' ORDER BY s.id")->fetchAll();
$unknownUsers = backend_usernames_by_ids(array_column($unknownSends, 'user_id'));

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>درگاه‌های LBA (ارسال پیام منطقه‌ای)</h2>
  <p class="hint">
    این درگاه‌ها از <a href="/sms-gateways.php">درگاه‌های پیامک</a> جدا هستند: فقط ارسال منطقه‌ای (LBA) از آن‌ها می‌رود و
    ارسال عادی هر شماره همان درگاه قبلی خودش را دارد. برای هر شماره‌ای که باید LBA داشته باشد، در جدول «شماره‌ها»ی پایین
    همین صفحه (یا ستون «درگاه LBA» در <a href="/numbers.php">شماره‌ها</a>) درگاه LBA آن را انتخاب کنید.
    سرویس فعلی: وب‌سرویس ارسال هوشمند پیشگام رایان (<span class="ltr">smsapi.pishgamrayan.com</span>).
  </p>
  <p class="hint">
    پیش‌نیاز پیشگام رایان: آی‌پی عمومی این سرور باید در پنل پیشگام رایان (کاربران ← آی‌پی‌های امن، با تیک «استفاده برای ساخت توکن») ثبت و
    تأیید شود، سپس توکن با دسترسی «ارسال پیام» ساخته و تأیید شود. آی‌پی خارج از ایران و آی‌پی خصوصی پذیرفته نمی‌شود.
    <?php if (lba_secret_key() === null): ?><br><b style="color:var(--danger,#b00)">SMS_GATEWAY_MASTER_KEY تنظیم نشده است؛ تا تنظیم نشود توکن ذخیره نمی‌شود.</b><?php endif; ?>
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه</th><th>نام</th><th>وضعیت</th><th>تعرفه‌ی هر پارت (فارسی / انگلیسی)</th><th>توکن</th><th>شماره‌ها</th><th>آخرین بررسی اتصال</th><th></th></tr>
    <?php foreach ($gateways as $g): $s = lba_secrets_stored((int)$g['id']); $reason = lba_gateway_unusable_reason($g); ?>
      <tr>
        <td class="ltr"><?= e($g['code']) ?></td>
        <td><?= e($g['name']) ?></td>
        <td><?php if ($reason === null): ?><span class="badge badge-ok">آماده</span><?php else: ?><span class="badge badge-off"><?= e($g['status'] === 'archived' ? 'بایگانی' : ((int)$g['enabled'] ? 'ناقص' : 'غیرفعال')) ?></span> <span class="muted"><?= e($reason) ?></span><?php endif; ?></td>
        <td class="num"><?= $g['price_fa_part'] !== null ? to_persian_digits(number_format((int)$g['price_fa_part'])) : '—' ?> / <?= $g['price_en_part'] !== null ? to_persian_digits(number_format((int)$g['price_en_part'])) : '—' ?> ریال</td>
        <td><?= $s['token'] ? 'ذخیره شده' : '<span style="color:var(--danger,#b00)">ندارد</span>' ?></td>
        <td class="num"><?= to_persian_digits((string)($numbersPerGateway[(int)$g['id']] ?? 0)) ?></td>
        <td><?php if ($g['last_test_at']): ?><?= (int)$g['last_test_ok'] ? '✓' : '✗' ?> <span class="muted"><?= jdate_from_utc((string)$g['last_test_at']) ?> — <?= e((string)$g['last_test_message']) ?></span><?php else: ?>—<?php endif; ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="/lba-gateways.php?edit=<?= (int)$g['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-sm" name="do" value="test">بررسی اتصال</button>
            <?php if ($g['status'] === 'active'): ?>
              <button class="btn btn-sm btn-danger" name="do" value="archive" onclick="return confirm('درگاه بایگانی شود؟ خط‌های آن دیگر ارسال LBA نخواهند داشت.')">بایگانی</button>
            <?php else: ?>
              <button class="btn btn-sm" name="do" value="restore">بازگردانی</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$gateways): ?><tr><td colspan="8" class="empty">هنوز درگاه LBA تعریف نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if (!$showForm): ?><p><a class="btn btn-primary" href="/lba-gateways.php?new=1">افزودن درگاه LBA</a></p><?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="card" style="margin-top:18px">
  <h2><?= $editing ? 'ویرایش درگاه «' . e($editing['name']) . '»' : 'درگاه LBA جدید' ?></h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="gateway_id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="toolbar">
      <label>نام <input type="text" name="name" required value="<?= e($fv('name', 'پیشگام رایان')) ?>"></label>
      <label>شناسه (لاتین) <input type="text" name="code" class="ltr" <?= $editing ? 'disabled' : 'required' ?> pattern="[a-z0-9_]{2,40}" value="<?= e($fv('code', 'pishgamrayan')) ?>"></label>
      <label style="flex:1 1 320px">آدرس سرویس <input type="url" name="base_url" class="ltr" style="width:100%" value="<?= e($fv('base_url', LBA_PISHGAM_DEFAULT_BASE_URL)) ?>"></label>
      <label><input type="checkbox" name="enabled" value="1"<?= $fv('enabled', '0') === '1' ? ' checked' : '' ?>> فعال</label>
    </div>
    <div class="toolbar">
      <label style="flex:1 1 360px">توکن (Authorization) <?= $stored['token'] ? '<span class="muted">— ذخیره شده؛ خالی بگذارید تا تغییر نکند</span>' : '' ?>
        <input type="password" name="token" class="ltr" style="width:100%" autocomplete="new-password" placeholder="<?= $stored['token'] ? '••••••••' : '' ?>"></label>
    </div>
    <div class="toolbar">
      <label>نام کاربری پنل (اختیاری) <?= $stored['username'] ? '<span class="muted">— ذخیره شده</span>' : '' ?>
        <input type="text" name="username" class="ltr" autocomplete="off" placeholder="<?= $stored['username'] ? '••••••••' : '' ?>"></label>
      <?php if ($stored['username']): ?><label><input type="checkbox" name="clear_username" value="1"> حذف نام کاربری</label><?php endif; ?>
      <label>رمز عبور پنل (اختیاری) <?= $stored['password'] ? '<span class="muted">— ذخیره شده</span>' : '' ?>
        <input type="password" name="password" class="ltr" autocomplete="new-password" placeholder="<?= $stored['password'] ? '••••••••' : '' ?>"></label>
      <?php if ($stored['password']): ?><label><input type="checkbox" name="clear_password" value="1"> حذف رمز عبور</label><?php endif; ?>
    </div>
    <p class="hint">وب‌سرویس پیشگام رایان فقط با توکن کار می‌کند؛ نام کاربری و رمز عبور پنل فقط رمزنگاری‌شده نگه داشته می‌شوند و در فراخوانی‌ها استفاده نمی‌شوند.</p>
    <div class="toolbar">
      <label>تعرفه‌ی هر پارت پیامک فارسی (ریال) <input type="text" inputmode="numeric" name="price_fa_part" class="ltr" value="<?= e($fv('price_fa_part')) ?>"></label>
      <label>تعرفه‌ی هر پارت پیامک انگلیسی (ریال) <input type="text" inputmode="numeric" name="price_en_part" class="ltr" value="<?= e($fv('price_en_part')) ?>"></label>
    </div>
    <p class="hint">هزینه‌ی مشتری = تعداد گیرنده × تعداد پارت × تعرفه‌ی همین درگاه. همه‌ی پیام‌های ارسال‌شده حساب می‌شوند (رسیده یا نرسیده). مدیران سامانه هزینه‌ای نمی‌دهند.</p>
    <button class="btn btn-primary">ذخیره</button>
    <a class="btn" href="/lba-gateways.php">انصراف</a>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px" id="numbers">
  <h2>شماره‌ها و درگاه LBA آن‌ها</h2>
  <p class="hint">فقط شماره‌ای که درگاه LBA دارد در صفحه‌ی «ارسال پیام منطقه‌ای (LBA)» دیده می‌شود، و LBA آن فقط از همین درگاه می‌رود. ارسال عادی شماره تغییری نمی‌کند.</p>
  <div class="table-wrap">
  <table>
    <tr><th>شماره</th><th>برچسب</th><th>کاربر</th><th>درگاه LBA</th></tr>
    <?php foreach ($numbers as $n): $pinned = (int)($n['lba_gateway_id'] ?? 0); ?>
      <tr>
        <td class="msisdn"><?= e($n['number']) ?></td>
        <td><?= e($n['label']) ?></td>
        <td><?= e($n['assigned_user_id'] !== null ? ($numberUsers[(int)$n['assigned_user_id']] ?? '#' . (int)$n['assigned_user_id']) : '—') ?></td>
        <td>
          <form method="post" class="toolbar" style="margin:0"><?= csrf_field() ?>
            <input type="hidden" name="do" value="number"><input type="hidden" name="number_id" value="<?= (int)$n['id'] ?>">
            <select name="lba_gateway_id" onchange="this.form.submit()">
              <option value="">— بدون LBA —</option>
              <?php foreach ($gateways as $g): if ($g['status'] !== 'active' && (int)$g['id'] !== $pinned) continue; ?>
                <option value="<?= (int)$g['id'] ?>"<?= (int)$g['id'] === $pinned ? ' selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">اعمال</button></noscript>
          </form>
          <?php if ($pinned > 0 && ($reason = lba_gateway_unusable_reason($gatewaysById[$pinned] ?? null)) !== null): ?><div class="muted" style="color:var(--danger,#b00)">⚠ <?= e($reason) ?></div><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$numbers): ?><tr><td colspan="4" class="empty">هنوز شماره‌ای ثبت نشده (<a href="/numbers.php">شماره‌ها</a>).</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px" id="unknown">
  <h2>ارسال‌های نامشخص</h2>
  <p class="hint">ارسال‌هایی که پاسخ پیشگام رایان به آن‌ها نرسید. هزینه‌شان رزرو مانده است. در پنل پیشگام رایان بررسی کنید و یکی از دو گزینه را بزنید.</p>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>زمان</th><th>کاربر</th><th>درخواست</th><th>خط</th><th>گیرنده</th><th>هزینه‌ی رزروشده (ریال)</th><th></th></tr>
    <?php foreach ($unknownSends as $s): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$s['id']) ?></td>
        <td class="num"><?= jdate_from_utc((string)$s['created_at']) ?></td>
        <td><?= e($unknownUsers[(int)$s['user_id']] ?? '#' . (int)$s['user_id']) ?></td>
        <td><?= e($s['title']) ?></td>
        <td class="ltr"><?= e($s['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$s['recipient_count'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$s['reserved_credits'])) ?></td>
        <td style="white-space:nowrap">
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="send_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-primary" name="do" value="resolve_sent" onclick="return confirm('ارسال انجام شده و هزینه کسر شود؟')">ارسال شده (کسر هزینه)</button>
            <button class="btn btn-sm" name="do" value="resolve_refund" onclick="return confirm('ارسال انجام نشده و هزینه برگردد؟')">ارسال نشده (بازگشت هزینه)</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$unknownSends): ?><tr><td colspan="8" class="empty">موردی نیست.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
