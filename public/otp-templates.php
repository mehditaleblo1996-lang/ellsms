<?php
/**
 * Shared OTP (docs/shared-otp.md).
 *
 * Customer: register OTP templates — fixed text with {name} placeholders — follow their review, send a
 * test code, and see recent sends. An approved template is sent through POST /api/v1/otp with only the
 * values; the sender line is the platform's.
 * Platform admin: switch the service on, choose the shared lines, and approve or reject templates.
 */
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$isAdmin = is_admin();
$pageTitle = 'کد تایید اشتراکی';
$active = $isAdmin ? 'shared_otp' : 'otp_templates';
$rbacOrg = $isAdmin ? null : require_permission(Permissions::MESSAGES_SEND);
$self = '/otp-templates.php';

$user = ['id' => (int)$me['id'], 'role' => $me['role'], 'organization_id' => $me['organization_id'] ?? null];
$statusFilter = (string)($_GET['status'] ?? ($isAdmin ? 'pending' : ''));
$statusFilter = in_array($statusFilter, SHARED_OTP_STATUSES, true) ? $statusFilter : '';
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($do === 'settings' && $isAdmin) {
        $lines = shared_otp_parse_lines((string)($_POST['shared_otp_lines'] ?? ''));
        $interval = max(0, min(3600, (int)from_persian_digits((string)($_POST['shared_otp_min_interval_seconds'] ?? '60'))));
        if (!empty($_POST['shared_otp_enabled']) && $lines === []) {
            flash('error', 'برای فعال‌سازی، دست‌کم یک خط اشتراکی وارد کنید.');
        } else {
            set_setting('shared_otp_enabled', !empty($_POST['shared_otp_enabled']) ? '1' : '0');
            set_setting('shared_otp_lines', implode(',', $lines));
            set_setting('shared_otp_min_interval_seconds', (string)$interval);
            audit((int)$me['id'], 'shared_otp.settings', 'enabled=' . (!empty($_POST['shared_otp_enabled']) ? '1' : '0') . ' lines=' . implode(',', $lines));
            flash('success', 'تنظیمات ذخیره شد.');
        }
        redirect($self);
    } elseif (in_array($do, ['approve', 'reject', 'disable'], true) && $isAdmin) {
        $r = shared_otp_template_review((int)$me['id'], $id, $do, (string)($_POST['note'] ?? ''));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'وضعیت قالب ذخیره شد.' : (string)$r['error']);
        redirect($self . ($statusFilter !== '' ? '?status=' . $statusFilter : ''));
    } elseif ($do === 'create') {
        $r = shared_otp_template_create($user, (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'قالب ثبت شد و پس از تأیید مدیر قابل ارسال است. شناسه‌ی قالب: ' . to_persian_digits((string)$r['id']) : (string)$r['error']);
        redirect($self);
    } elseif ($do === 'update') {
        $r = shared_otp_template_update($user, $id, (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''));
        if ($r['ok']) {
            flash('success', $r['status'] === 'pending' ? 'قالب ویرایش شد و دوباره در انتظار بررسی است.' : 'قالب ویرایش شد.');
            redirect($self);
        }
        flash('error', (string)$r['error']);
        redirect($self . '?edit=' . $id);
    } elseif ($do === 'delete') {
        $r = shared_otp_template_delete($user, $id);
        flash($r['ok'] ? 'info' : 'error', $r['ok'] ? 'قالب حذف شد.' : (string)$r['error']);
        redirect($self);
    } elseif ($do === 'test') {
        $values = preg_split('/\s*[,،]\s*/u', trim((string)($_POST['values'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $r = shared_otp_send($user, $id, (string)($_POST['destination'] ?? ''), $values, 'panel');
        if ($r['ok']) {
            flash('success', $isAdmin ? 'کد از خط ' . to_persian_digits((string)$r['originator']) . ' ارسال شد.' : 'کد با خط اشتراکی ارسال شد.');
        } else {
            $detail = isset($r['fields']) ? ' (' . implode('، ', array_map(static fn($k, $v) => $k . ': ' . implode('/', $v), array_keys($r['fields']), $r['fields'])) . ')' : '';
            flash('error', (string)$r['error'] . $detail);
        }
        redirect($self);
    }
    redirect($self);
}

$configured = shared_otp_configured();
if ($isAdmin) {
    $where = "t.status <> 'deleted'" . ($statusFilter !== '' ? ' AND t.status = ?' : '');
    $st = db()->prepare("SELECT t.*, u.username owner_name FROM ellsms_otp_templates t LEFT JOIN user_ u ON u.id = t.user_id WHERE {$where} ORDER BY t.id DESC LIMIT 500");
    $st->execute($statusFilter !== '' ? [$statusFilter] : []);
    $templates = $st->fetchAll();
    $pendingCount = (int)db()->query("SELECT COUNT(*) FROM ellsms_otp_templates WHERE status = 'pending'")->fetchColumn();
    $lines = shared_otp_lines();
    $knownLines = [];
    if ($lines !== []) {
        $in = implode(',', array_fill(0, count($lines), '?'));
        $kl = db()->prepare("SELECT number FROM ellsms_numbers WHERE number IN ({$in})");
        $kl->execute($lines);
        $knownLines = array_map('strval', array_column($kl->fetchAll(), 'number'));
    }
} else {
    $templates = shared_otp_templates_list($user, $statusFilter !== '' ? $statusFilter : null);
}
$approved = $isAdmin ? [] : shared_otp_templates_list($user, 'approved', 200);
$editing = $editId > 0 ? shared_otp_template_find($user, $editId) : null;
$recent = shared_otp_messages_list($user, 30);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$configured): ?>
  <div class="flash flash-info">سرویس کد تایید اشتراکی هنوز فعال نشده است.<?= $isAdmin ? ' از کادر تنظیمات پایین آن را فعال کنید.' : ' قالب‌ها را می‌توانید ثبت کنید؛ ارسال پس از فعال‌سازی ممکن است.' ?></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card">
  <h2>تنظیمات سرویس</h2>
  <p class="hint">
    مشتری بدون داشتن خط اختصاصی و بدون تعیین شماره‌ی فرستنده، با قالب تأییدشده کد تایید می‌فرستد. پیامک از یکی از خطوط زیر
    (بر اساس شماره‌ی گیرنده؛ اگر درگاه آن خط در دسترس نبود، خط بعدی) و با تعرفه‌ی «کد تایید» همان خط ارسال و از کیف پول مشتری کسر می‌شود.
    خطوط باید در «شماره‌ها» ثبت و به مسیر/درگاه وصل باشند.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <p><label><input type="checkbox" name="shared_otp_enabled" value="1" <?= shared_otp_enabled() ? 'checked' : '' ?>> سرویس فعال باشد</label></p>
    <div class="toolbar">
      <label>خطوط اشتراکی (با کاما یا خط جدید)
        <textarea name="shared_otp_lines" rows="2" class="ltr" style="min-width:280px"><?= e(implode("\n", $lines)) ?></textarea>
      </label>
      <label>حداقل فاصله‌ی دو کد به یک شماره (ثانیه)
        <input type="number" name="shared_otp_min_interval_seconds" min="0" max="3600" value="<?= shared_otp_min_interval_seconds() ?>">
      </label>
      <button class="btn btn-primary">ذخیره</button>
    </div>
    <?php foreach (array_diff($lines, $knownLines) as $unknown): ?>
      <p class="flash flash-error">خط <?= e($unknown) ?> در «شماره‌ها» ثبت نشده است؛ ارسال از آن به درگاه تنظیم‌شده نمی‌رسد.</p>
    <?php endforeach; ?>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h2><?= $editing ? 'ویرایش قالب #' . to_persian_digits((string)$editing['id']) : 'قالب جدید' ?></h2>
  <p class="hint">
    متن ثابت پیامک را بنویسید و جای مقادیر متغیر را با نام لاتین داخل آکولاد مشخص کنید، مثلاً
    <span class="ltr">«کد ورود شما به فروشگاه نمونه: {code}»</span>. حداکثر <?= to_persian_digits((string)SHARED_OTP_MAX_VARIABLES) ?> متغیر؛
    هر مقدار حداکثر <?= to_persian_digits((string)SHARED_OTP_MAX_VALUE_CHARS) ?> نویسه، یک‌خطی و بدون لینک. قالب پس از تأیید مدیر قابل ارسال است و هر تغییر متن، آن را دوباره به بررسی می‌فرستد.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <p><label>عنوان <input type="text" name="title" required maxlength="190" value="<?= e((string)($editing['title'] ?? '')) ?>"></label></p>
    <p><textarea name="content" rows="3" required maxlength="<?= SHARED_OTP_MAX_CONTENT_CHARS ?>" style="width:100%" placeholder="کد تایید شما: {code}"><?= e((string)($editing['content'] ?? '')) ?></textarea></p>
    <button class="btn btn-primary"><?= $editing ? 'ذخیره و ارسال برای بررسی' : 'ثبت برای بررسی' ?></button>
    <?php if ($editing): ?><a class="btn" href="<?= $self ?>">انصراف</a><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>قالب‌ها<?php if ($isAdmin && $pendingCount > 0): ?> — <?= to_persian_digits((string)$pendingCount) ?> در انتظار بررسی<?php endif; ?></h2>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="status" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach (SHARED_OTP_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(shared_otp_status_label($s)[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه</th><?php if ($isAdmin): ?><th>مشتری</th><?php endif; ?><th>عنوان</th><th>متن</th><th>متغیرها</th><th>وضعیت</th><th>ثبت</th><th></th></tr>
    <?php foreach ($templates as $t): ?>
      <?php [$label, $class] = shared_otp_status_label((string)$t['status']); ?>
      <tr>
        <td class="num"><?= (int)$t['id'] ?></td>
        <?php if ($isAdmin): ?><td><?= e((string)($t['owner_name'] ?? '#' . $t['user_id'])) ?></td><?php endif; ?>
        <td><?= e((string)$t['title']) ?></td>
        <td style="white-space:pre-wrap;max-width:360px"><?= e((string)$t['content']) ?></td>
        <td class="ltr"><?= e(implode(', ', shared_otp_variables($t))) ?></td>
        <td>
          <span class="badge <?= $class ?>"><?= e($label) ?></span>
          <?php if ($t['review_note']): ?><div class="hint"><?= e((string)$t['review_note']) ?></div><?php endif; ?>
        </td>
        <td class="num"><?= jdate((string)$t['created_at']) ?></td>
        <td>
          <?php if ($isAdmin): ?>
            <?php if ($t['status'] !== 'approved'): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-primary">تأیید</button></form>
            <?php else: ?>
              <form method="post" style="display:inline" onsubmit="return confirm('ارسال با این قالب متوقف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="disable"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm">غیرفعال</button></form>
            <?php endif; ?>
            <?php if ($t['status'] !== 'rejected'): ?>
              <form method="post" style="display:inline" onsubmit="var n=prompt('دلیل رد (به مشتری نمایش داده می‌شود):');if(!n)return false;this.note.value=n;return true;"><?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="note" value="">
                <button class="btn btn-sm btn-danger">رد</button></form>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn btn-sm" href="<?= $self ?>?edit=<?= (int)$t['id'] ?>">ویرایش</a>
            <form method="post" style="display:inline" onsubmit="return confirm('قالب حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-danger">حذف</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$templates): ?><tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" class="empty">قالبی پیدا نشد.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<?php if ($configured && $approved): ?>
<div class="card" style="margin-top:18px">
  <h2>ارسال آزمایشی</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="test">
    <label>قالب
      <select name="id" required>
        <?php foreach ($approved as $t): ?>
          <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> — <?= e((string)$t['title']) ?> (<?= e(implode(', ', shared_otp_variables($t))) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>موبایل گیرنده <input type="text" name="destination" required class="ltr" placeholder="09121234567"></label>
    <label>مقادیر به ترتیب متغیرها (با کاما) <input type="text" name="values" required class="ltr" placeholder="4821"></label>
    <button class="btn btn-primary">ارسال</button>
  </form>
  <p class="hint">هزینه با تعرفه‌ی کد تایید خط اشتراکی از اعتبار شما کسر می‌شود.</p>
</div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
<div class="card" style="margin-top:18px">
  <h2>ارسال از طریق API</h2>
  <p class="hint">با کلید API دارای دسترسی <span class="ltr">otp:send</span> (<a href="/api-keys.php">کلیدهای API</a>). شماره‌ی فرستنده ارسال نکنید؛ خط را سامانه انتخاب می‌کند.</p>
  <pre class="ltr" style="white-space:pre-wrap">POST /api/v1/otp
Authorization: Bearer &lt;API key&gt;
Content-Type: application/json

{"template_id": <?= (int)($approved[0]['id'] ?? 12) ?>, "destination": "09121234567", "parameters": {"<?= e(shared_otp_variables($approved[0] ?? ['variables' => '["code"]'])[0] ?? 'code') ?>": "4821"}}</pre>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>آخرین ارسال‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه</th><th>قالب</th><th>گیرنده</th><th>خط</th><th>وضعیت</th><th>بخش</th><th>منبع</th><th>زمان</th></tr>
    <?php foreach ($recent as $m): ?>
      <?php [$label, $class] = shared_otp_status_label((string)$m['status']); ?>
      <tr>
        <td class="num"><?= (int)$m['id'] ?></td>
        <td><?= e((string)($m['template_title'] ?? '#' . $m['template_id'])) ?></td>
        <td class="ltr msisdn"><?= e((string)$m['destination']) ?></td>
        <td class="ltr"><?= e($m['originator'] !== null ? shared_line_mask((string)$m['originator'], $isAdmin) : '—') ?></td>
        <td><span class="badge <?= $class ?>"><?= e($label) ?></span><?php if ($m['error']): ?><div class="hint"><?= e(mb_strimwidth((string)$m['error'], 0, 120, '…')) ?></div><?php endif; ?></td>
        <td class="num"><?= to_persian_digits((string)$m['parts']) ?></td>
        <td><?= $m['source'] === 'api' ? 'API' : 'پنل' ?></td>
        <td class="num"><?= jdate((string)$m['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="8" class="empty">هنوز ارسالی نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
