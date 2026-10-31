<?php
/**
 * Shared sending service (app/SharedService.php, docs/shared-service.md).
 *
 * Customer: send a message by giving only the recipient and the text (خدماتی or تبلیغاتی); the line is the
 * platform's. Follow each message: sent, waiting for review, waiting for the promotional window, rejected.
 * Platform admin: switch the service on, keep the line pools (one per kind, with priority, so a provider
 * is swapped by enabling and disabling lines), review held messages, and set each customer's trust level.
 */
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$isAdmin = is_admin();
$pageTitle = 'سرویس ارسال اشتراکی';
$active = $isAdmin ? 'shared_service_admin' : 'shared_service';
$rbacOrg = $isAdmin ? null : require_permission(Permissions::MESSAGES_SEND);
$self = '/shared-service.php';
$user = ['id' => (int)$me['id'], 'role' => $me['role'], 'organization_id' => $me['organization_id'] ?? null];
$available = shared_service_available();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available) {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $adminId = (int)$me['id'];
    if ($isAdmin && $do === 'settings') {
        $start = shared_service_parse_hhmm((string)($_POST['promo_start'] ?? ''));
        $end = shared_service_parse_hhmm((string)($_POST['promo_end'] ?? ''));
        $trust = (string)($_POST['default_trust'] ?? 'supervised');
        if ($start === null || $end === null || !isset(SHARED_SERVICE_TRUST_LEVELS[$trust])) {
            flash('error', 'ساعت‌ها را به شکل ۰۸:۰۰ وارد کنید.');
        } else {
            set_setting('shared_service_enabled', !empty($_POST['enabled']) ? '1' : '0');
            set_setting('shared_service_promo_start', $start);
            set_setting('shared_service_promo_end', $end);
            set_setting('shared_service_hold_hours', (string)max(1, min(720, (int)from_persian_digits((string)($_POST['hold_hours'] ?? '24')))));
            set_setting('shared_service_default_trust', $trust);
            audit($adminId, 'shared_service.settings', 'enabled=' . (!empty($_POST['enabled']) ? '1' : '0') . " promo={$start}-{$end} trust={$trust}");
            flash('success', 'تنظیمات ذخیره شد.');
        }
    } elseif ($isAdmin && $do === 'line_add') {
        $r = shared_service_line_add($adminId, (string)($_POST['number'] ?? ''), (string)($_POST['kind'] ?? ''), (int)from_persian_digits((string)($_POST['priority'] ?? '100')), (string)($_POST['note'] ?? ''));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'خط به استخر اضافه شد.' : (string)$r['error']);
    } elseif ($isAdmin && $do === 'line_update') {
        $changes = isset($_POST['delete']) ? ['delete' => true] : array_filter([
            'enabled'  => isset($_POST['enabled']) ? (string)$_POST['enabled'] === '1' : null,
            'priority' => isset($_POST['priority']) ? (int)from_persian_digits((string)$_POST['priority']) : null,
        ], static fn($v) => $v !== null);
        $r = shared_service_line_update($adminId, (int)($_POST['id'] ?? 0), $changes);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'استخر خطوط به‌روز شد.' : (string)$r['error']);
    } elseif ($isAdmin && in_array($do, ['approve', 'reject'], true)) {
        $textId = (int)($_POST['text_id'] ?? 0);
        $messageId = (int)($_POST['message_id'] ?? 0);
        $r = shared_service_review($adminId, $do, $textId > 0 ? $textId : null, $messageId > 0 ? $messageId : null,
            (string)($_POST['note'] ?? ''), empty($_POST['messages_only']));
        flash($r['ok'] ? 'success' : 'error', $r['ok']
            ? to_persian_digits((string)$r['count']) . ' پیام ' . ($do === 'approve' ? 'تأیید و برای ارسال آزاد شد.' : 'رد شد.')
            : (string)$r['error']);
    } elseif ($isAdmin && $do === 'text_reset') {
        shared_service_text_reset($adminId, (int)($_POST['text_id'] ?? 0));
        flash('info', 'متن به حالت «در انتظار بررسی» برگشت؛ پیام بعدی با این متن بررسی می‌شود.');
    } elseif ($isAdmin && $do === 'trust') {
        $r = shared_service_trust_set($adminId, (int)from_persian_digits((string)($_POST['user_id'] ?? '0')), (string)($_POST['trust'] ?? ''), (string)($_POST['note'] ?? ''));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'سطح اعتماد ذخیره شد.' : (string)$r['error']);
    } elseif (!$isAdmin && $do === 'send') {
        $r = shared_service_submit($user, (string)($_POST['kind'] ?? 'service'), (string)($_POST['destination'] ?? ''), (string)($_POST['text'] ?? ''), 'panel');
        if (!$r['ok']) {
            flash('error', (string)$r['error']);
        } else {
            flash(in_array($r['status'], ['sent', 'held', 'queued'], true) ? 'success' : 'error', match ($r['status']) {
                'sent'   => 'پیام ارسال شد.',
                'held'   => 'پیام ثبت شد و پس از بررسی کارشناس ارسال می‌شود (' . shared_service_hold_reason_label($r['hold_reason']) . ').',
                'queued' => 'پیام ثبت شد و در ساعت مجاز ارسال تبلیغاتی (' . to_persian_digits(substr((string)$r['send_after'], 11, 5)) . ') فرستاده می‌شود.',
                default  => 'ارسال ناموفق بود: ' . (string)($r['error'] ?? ''),
            });
        }
    }
    redirect($self);
}

$configured = $available && shared_service_configured();
[$promoStart, $promoEnd] = $available ? shared_service_promo_window() : ['08:00', '21:00'];
$statusFilter = (string)($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, SHARED_SERVICE_MESSAGE_STATUSES, true) ? $statusFilter : null;
if ($available) {
    $recent = shared_service_messages_list($user, $statusFilter, $isAdmin ? 100 : 50);
    if ($isAdmin) {
        $queue = shared_service_review_queue();
        $poolLines = shared_service_lines_list();
        $trustRows = shared_service_trust_list();
        $texts = shared_service_texts_list((string)($_GET['texts'] ?? '') ?: null);
        $heldIndividually = shared_service_messages_list($user, 'held', 100);
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$available): ?>
  <div class="flash flash-error">جدول‌های سرویس ساخته نشده‌اند (مهاجرت 2026_10_31_shared_service.sql).</div>
<?php require __DIR__ . '/../app/views/footer.php'; return; endif; ?>
<?php if (!$configured): ?>
  <div class="flash flash-info">سرویس ارسال اشتراکی هنوز فعال نشده است.<?= $isAdmin ? ' آن را فعال کنید و دست‌کم یک خط به استخر اضافه کنید.' : '' ?></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card">
  <h2>صف بررسی<?php if ($queue): ?> — <?= to_persian_digits((string)array_sum(array_column($queue, 'held'))) ?> پیام<?php endif; ?></h2>
  <p class="hint">
    پیام‌ها بر اساس متن گروه شده‌اند (اعداد مثل کد و مبلغ نادیده گرفته می‌شوند). «تأیید» پیام‌های گروه را ارسال می‌کند و متن را برای مشتری
    تأییدشده ثبت می‌کند تا پیام‌های بعدی همین متن — برای مشتری مورد اعتماد — بی‌بررسی بروند. «فقط همین پیام‌ها» متن را تأیید نمی‌کند.
    «رد» پیام‌ها و متن را رد می‌کند و دلیل به مشتری نمایش داده می‌شود. پیامی که تا <?= to_persian_digits((string)shared_service_hold_hours()) ?> ساعت بررسی نشود منقضی می‌شود.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>مشتری</th><th>نوع</th><th>دلیل نگه‌داشتن</th><th>متن</th><th>تعداد</th><th>قدیمی‌ترین</th><th></th></tr>
    <?php foreach ($queue as $g): ?>
      <tr>
        <td><?= e((string)($g['username'] ?? '#' . $g['user_id'])) ?> <span class="hint">#<?= (int)$g['user_id'] ?></span></td>
        <td><?= e(SHARED_SERVICE_KINDS[$g['kind']] ?? $g['kind']) ?></td>
        <td><?= e(shared_service_hold_reason_label($g['hold_reason'])) ?></td>
        <td style="white-space:pre-wrap;max-width:420px"><?= e((string)($g['sample'] ?? '')) ?></td>
        <td class="num"><?= to_persian_digits((string)$g['held']) ?></td>
        <td class="num"><?= jdate((string)$g['oldest']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($g['text_id'] !== null): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="text_id" value="<?= (int)$g['text_id'] ?>">
            <button class="btn btn-sm btn-primary">تأیید و ارسال</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="messages_only" value="1"><input type="hidden" name="text_id" value="<?= (int)$g['text_id'] ?>">
            <button class="btn btn-sm">فقط همین پیام‌ها</button></form>
          <form method="post" style="display:inline" onsubmit="var n=prompt('دلیل رد (به مشتری نمایش داده می‌شود):');if(!n)return false;this.note.value=n;return true;"><?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="text_id" value="<?= (int)$g['text_id'] ?>"><input type="hidden" name="note" value="">
            <button class="btn btn-sm btn-danger">رد</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$queue): ?><tr><td colspan="7" class="empty">پیامی در انتظار بررسی نیست.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($heldIndividually): ?>
  <details style="margin-top:12px">
    <summary>بررسی تک‌به‌تک پیام‌ها (<?= to_persian_digits((string)count($heldIndividually)) ?>)</summary>
    <div class="table-wrap">
    <table>
      <tr><th>شناسه</th><th>مشتری</th><th>گیرنده</th><th>متن</th><th>زمان</th><th></th></tr>
      <?php foreach ($heldIndividually as $m): ?>
        <tr>
          <td class="num"><?= (int)$m['id'] ?></td>
          <td><?= e((string)($m['username'] ?? '#' . $m['user_id'])) ?></td>
          <td class="ltr msisdn"><?= e((string)$m['destination']) ?></td>
          <td style="white-space:pre-wrap;max-width:420px"><?= e((string)$m['content']) ?></td>
          <td class="num"><?= jdate((string)$m['created_at']) ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-sm btn-primary">تأیید</button></form>
            <form method="post" style="display:inline" onsubmit="var n=prompt('دلیل رد:');if(!n)return false;this.note.value=n;return true;"><?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="note" value="">
              <button class="btn btn-sm btn-danger">رد</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </details>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:18px">
  <h2>استخر خطوط</h2>
  <p class="hint">
    مشتری هیچ‌وقت شماره‌ی فرستنده را نمی‌بیند و انتخاب نمی‌کند. خطوط فعالِ هر نوع به ترتیب اولویت (عدد کمتر اول) امتحان می‌شوند؛
    خطوط هم‌اولویت بار را بر اساس شماره‌ی گیرنده تقسیم می‌کنند و اگر درگاه یک خط پیام را نپذیرد، خط بعدی امتحان می‌شود.
    برای عوض کردن تأمین‌کننده، خط تأمین‌کننده‌ی جدید را اضافه و فعال کنید و خط قبلی را غیرفعال کنید. تأمین‌کننده‌ی هر خط را مسیر/درگاه
    همان شماره در «شماره‌ها» تعیین می‌کند.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>خط</th><th>نوع</th><th>اولویت</th><th>وضعیت</th><th>یادداشت</th><th></th></tr>
    <?php foreach ($poolLines as $l): ?>
      <tr>
        <td class="ltr"><?= e((string)$l['number']) ?><?php if ($l['number_id'] === null): ?> <span class="badge badge-failed">در «شماره‌ها» نیست</span><?php endif; ?></td>
        <td><?= e(SHARED_SERVICE_KINDS[$l['kind']] ?? $l['kind']) ?></td>
        <td>
          <form method="post" class="toolbar" style="margin:0"><?= csrf_field() ?><input type="hidden" name="do" value="line_update"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <input type="number" name="priority" min="0" max="65535" value="<?= (int)$l['priority'] ?>" style="width:90px"><button class="btn btn-sm">ذخیره</button></form>
        </td>
        <td><span class="badge <?= $l['enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= $l['enabled'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td><?= e((string)($l['note'] ?? '')) ?></td>
        <td style="white-space:nowrap">
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="line_update"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="enabled" value="<?= $l['enabled'] ? '0' : '1' ?>">
            <button class="btn btn-sm"><?= $l['enabled'] ? 'غیرفعال کن' : 'فعال کن' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('خط از استخر حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="line_update"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="delete" value="1">
            <button class="btn btn-sm btn-danger">حذف</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$poolLines): ?><tr><td colspan="6" class="empty">هنوز خطی در استخر نیست.</td></tr><?php endif; ?>
  </table>
  </div>
  <form method="post" class="toolbar" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="do" value="line_add">
    <label>شماره‌ی خط <input type="text" name="number" required class="ltr" placeholder="5000…"></label>
    <label>نوع
      <select name="kind"><?php foreach (SHARED_SERVICE_KINDS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label>اولویت <input type="number" name="priority" min="0" max="65535" value="100" style="width:90px"></label>
    <label>یادداشت (مثلاً نام تأمین‌کننده) <input type="text" name="note" maxlength="190"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>

<div class="card" style="margin-top:18px">
  <h2>تنظیمات</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <p><label><input type="checkbox" name="enabled" value="1" <?= shared_service_enabled() ? 'checked' : '' ?>> سرویس فعال باشد</label></p>
    <div class="toolbar">
      <label>ارسال تبلیغاتی از ساعت <input type="text" name="promo_start" class="ltr" value="<?= e($promoStart) ?>" style="width:80px"></label>
      <label>تا ساعت <input type="text" name="promo_end" class="ltr" value="<?= e($promoEnd) ?>" style="width:80px"></label>
      <label>مهلت بررسی (ساعت) <input type="number" name="hold_hours" min="1" max="720" value="<?= shared_service_hold_hours() ?>" style="width:80px"></label>
      <label>سطح اعتماد پیش‌فرض مشتری
        <select name="default_trust"><?php foreach (SHARED_SERVICE_TRUST_LEVELS as $k => $label): ?><option value="<?= $k ?>" <?= shared_service_default_trust() === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      </label>
      <button class="btn btn-primary">ذخیره</button>
    </div>
    <p class="hint">پیام تبلیغاتی که بیرون از این بازه ثبت شود در صف می‌ماند و اول بازه‌ی بعدی ارسال می‌شود. تعرفه‌ی هر دو نوع در «تعرفه‌ی پیامک → تعرفه‌ی درگاه‌ها» (و تعرفه‌ی اختصاصی هر کاربر) دو ردیف جدا دارد.</p>
  </form>
</div>

<div class="card" style="margin-top:18px">
  <h2>سطح اعتماد مشتریان</h2>
  <p class="hint">«تحت نظر»: همه‌ی پیام‌ها پیش از ارسال بررسی می‌شوند. «مورد اعتماد»: فقط متنی که قبلاً تأیید نشده بررسی می‌شود. مشتری بدون سطح، سطح پیش‌فرض را دارد.</p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="trust">
    <label>شناسه‌ی کاربر <input type="text" name="user_id" required class="ltr" style="width:100px"></label>
    <label>سطح
      <select name="trust"><option value="">پیش‌فرض</option><?php foreach (SHARED_SERVICE_TRUST_LEVELS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label>یادداشت <input type="text" name="note" maxlength="190"></label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>کاربر</th><th>سطح</th><th>یادداشت</th><th>تغییر</th></tr>
    <?php foreach ($trustRows as $t): ?>
      <tr>
        <td><?= e((string)($t['username'] ?? '')) ?> <span class="hint">#<?= (int)$t['user_id'] ?></span></td>
        <td><?= e(SHARED_SERVICE_TRUST_LEVELS[$t['trust']] ?? $t['trust']) ?></td>
        <td><?= e((string)($t['note'] ?? '')) ?></td>
        <td class="num"><?= jdate((string)$t['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$trustRows): ?><tr><td colspan="4" class="empty">همه‌ی مشتریان سطح پیش‌فرض دارند.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2>متن‌های بررسی‌شده</h2>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="texts" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>" <?= ($_GET['texts'] ?? '') === $s ? 'selected' : '' ?>><?= e(shared_service_status_label($s)[0]) ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>مشتری</th><th>نوع</th><th>متن نمونه</th><th>وضعیت</th><th>بررسی</th><th></th></tr>
    <?php foreach ($texts as $t): [$label, $class] = shared_service_status_label((string)$t['status']); ?>
      <tr>
        <td><?= e((string)($t['username'] ?? '#' . $t['user_id'])) ?></td>
        <td><?= e(SHARED_SERVICE_KINDS[$t['kind']] ?? $t['kind']) ?></td>
        <td style="white-space:pre-wrap;max-width:420px"><?= e((string)$t['sample']) ?></td>
        <td><span class="badge <?= $class ?>"><?= e($label) ?></span><?php if ($t['review_note']): ?><div class="hint"><?= e((string)$t['review_note']) ?></div><?php endif; ?></td>
        <td class="num"><?= $t['reviewed_at'] ? jdate((string)$t['reviewed_at']) : '—' ?></td>
        <td><?php if ($t['status'] !== 'pending'): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="text_reset"><input type="hidden" name="text_id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-sm">بازگشت به بررسی</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$texts): ?><tr><td colspan="6" class="empty">متنی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php else: ?>
<?php if ($configured): ?>
<div class="card">
  <h2>ارسال پیام</h2>
  <p class="hint">
    فقط گیرنده و متن را وارد کنید؛ پیام از خطوط سامانه ارسال می‌شود و به خط اختصاصی نیازی ندارید. متنی که اولین بار ارسال می‌کنید
    پیش از ارسال توسط کارشناس بررسی می‌شود. ارسال تبلیغاتی فقط از ساعت <?= to_persian_digits($promoStart) ?> تا <?= to_persian_digits($promoEnd) ?> انجام
    می‌شود و پیامی که بیرون از این بازه ثبت شود در اولین فرصت ارسال می‌شود. هزینه هنگام ارسال از اعتبار شما کسر می‌شود.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="send">
    <div class="toolbar">
      <label>نوع پیام
        <select name="kind">
          <?php foreach (SHARED_SERVICE_KINDS as $k => $label): if (shared_service_pool($k) === []) continue; ?>
            <option value="<?= $k ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>موبایل گیرنده <input type="text" name="destination" required class="ltr" placeholder="09121234567"></label>
    </div>
    <p><textarea name="text" rows="4" required maxlength="<?= SHARED_SERVICE_MAX_CONTENT_CHARS ?>" style="width:100%" placeholder="متن پیام"></textarea></p>
    <button class="btn btn-primary">ارسال</button>
  </form>
</div>

<div class="card" style="margin-top:18px">
  <h2>ارسال از طریق API</h2>
  <p class="hint">با کلید API دارای دسترسی <span class="ltr">shared:send</span> (<a href="/api-keys.php">کلیدهای API</a>). شماره‌ی فرستنده نفرستید؛ خط را سامانه انتخاب می‌کند.
    پاسخ <span class="ltr">201</span> یعنی ارسال شد و <span class="ltr">202</span> یعنی پذیرفته شد و منتظر بررسی (<span class="ltr">held</span>) یا ساعت مجاز (<span class="ltr">queued</span>) است؛
    وضعیت را با <span class="ltr">GET /api/v1/shared/messages/{id}</span> بگیرید.</p>
  <pre class="ltr" style="white-space:pre-wrap">POST /api/v1/shared/messages
Authorization: Bearer &lt;API key&gt;
Content-Type: application/json

{"destination": "09121234567", "text": "سفارش شما ارسال شد.", "line_type": "service"}</pre>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>آخرین پیام‌ها</h2>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="status" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach (SHARED_SERVICE_MESSAGE_STATUSES as $s): ?><option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(shared_service_status_label($s)[0]) ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>شناسه</th><?php if ($isAdmin): ?><th>مشتری</th><?php endif; ?><th>نوع</th><th>گیرنده</th><th>متن</th><th>وضعیت</th><?php if ($isAdmin): ?><th>خط</th><?php endif; ?><th>بخش</th><th>منبع</th><th>زمان</th></tr>
    <?php foreach ($recent as $m): [$label, $class] = shared_service_status_label((string)$m['status']); ?>
      <tr>
        <td class="num"><?= (int)$m['id'] ?></td>
        <?php if ($isAdmin): ?><td><?= e((string)($m['username'] ?? '#' . $m['user_id'])) ?></td><?php endif; ?>
        <td><?= e(SHARED_SERVICE_KINDS[$m['kind']] ?? $m['kind']) ?></td>
        <td class="ltr msisdn"><?= e((string)$m['destination']) ?></td>
        <td style="white-space:pre-wrap;max-width:320px"><?= e(mb_strimwidth((string)$m['content'], 0, 160, '…')) ?></td>
        <td>
          <span class="badge <?= $class ?>"><?= e($label) ?></span>
          <?php if ($m['status'] === 'held' && $m['hold_reason']): ?><div class="hint"><?= e(shared_service_hold_reason_label($m['hold_reason'])) ?></div><?php endif; ?>
          <?php if ($m['status'] === 'queued' && $m['send_after']): ?><div class="hint">ارسال از <?= jdate((string)$m['send_after']) ?></div><?php endif; ?>
          <?php if ($m['error']): ?><div class="hint"><?= e(mb_strimwidth((string)$m['error'], 0, 160, '…')) ?></div><?php endif; ?>
        </td>
        <?php if ($isAdmin): ?><td class="ltr"><?= e((string)($m['originator'] ?? '—')) ?></td><?php endif; ?>
        <td class="num"><?= to_persian_digits((string)$m['parts']) ?></td>
        <td><?= $m['source'] === 'api' ? 'API' : 'پنل' ?></td>
        <td class="num"><?= jdate((string)$m['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="<?= $isAdmin ? 10 : 8 ?>" class="empty">هنوز پیامی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
