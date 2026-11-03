<?php
/**
 * ELLSMS — «منشی هوشمند»: the AI SMS secretary of a customer's lines (docs/ai-secretary.md).
 *
 * A customer switches the assistant on for a line of their own, describes the business (or picks the
 * general-assistant mode), tests it here without sending anything, and reads every conversation in
 * the log. Messages a keyword auto-reply rule answers stay with that rule (/autoreply.php).
 *
 * Access is the auto-reply page's: AUTOREPLY_VIEW to see, AUTOREPLY_MANAGE to change, the AUTOREPLY
 * plan entitlement, the menu-access key 'ai_secretary', and at least one AI model given to the account
 * (Platform Admin -> موتورهای هوش مصنوعی). A customer only ever sees and edits its own lines
 * (allowed_originators()); a platform admin sees every line and sets the global switches.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'منشی هوشمند';
$active = 'ai_secretary';
$isAdmin = is_admin();

if (!ai_secretary_available()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>منشی هوشمند</h2><p class="muted">این سرویس هنوز راه‌اندازی نشده است.</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}
if (!$isAdmin) {
    require_permission(Permissions::AUTOREPLY_VIEW);
    require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::AUTOREPLY);
}

/** Lines this user may manage: null = every line (admin). */
$myLines = $isAdmin ? null : array_values(array_map('strval', allowed_originators($me)));
$myAccess = $isAdmin ? null : ai_access_effective((int)$me['id']);
$selectedLine = normalize_originator((string)($_GET['line'] ?? $_POST['line'] ?? '')) ?? '';
if ($selectedLine !== '' && $myLines !== null && !in_array($selectedLine, $myLines, true)) {
    flash('error', 'این خط متعلق به حساب شما نیست.');
    redirect('/ai-secretary.php');
}

/**
 * Who owns (and pays for) the assistant of $line: the existing assistant's owner, else the line's
 * assigned account; for a customer, always the customer.
 */
$ownerFor = static function (string $line) use ($me, $isAdmin): ?int {
    if (!$isAdmin) return (int)$me['id'];
    $existing = ai_assistant_for_line($line);
    if ($existing !== null) return (int)$existing['user_id'];
    $st = db()->prepare('SELECT assigned_user_id FROM ellsms_numbers WHERE number = ?');
    $st->execute([$line]);
    $owner = $st->fetchColumn();
    return $owner ? (int)$owner : null;
};

$simulation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if (!$isAdmin) require_permission(Permissions::AUTOREPLY_MANAGE);
    // Arming an automatic sender is what a support session must never leave behind (as /autoreply.php).
    if (in_array($do, ['save', 'toggle', 'settings_save'], true) && impersonation_guard_post('send.autoreply')) {
        redirect('/ai-secretary.php' . ($selectedLine !== '' ? '?line=' . $selectedLine : ''));
    }
    $actor = (int)$me['id'];
    $stay = false;
    try {
        switch ($do) {
            case 'save': {
                if ($selectedLine === '') throw new AppException('خط را انتخاب کنید.');
                $ownerId = $ownerFor($selectedLine);
                if ($ownerId === null && $isAdmin) {
                    $name = trim((string)($_POST['owner'] ?? ''));
                    $ownerId = $name === '' ? null : (ctype_digit($name) ? (int)$name : backend_find_user_id_by_username($name));
                    if ($ownerId === null || backend_usernames_by_ids([$ownerId]) === []) {
                        throw new AppException('این خط به حسابی تخصیص داده نشده است؛ نام کاربری مالک را وارد کنید.');
                    }
                }
                $orgId = $ownerId === (int)$me['id'] ? (isset($me['organization_id']) ? (int)$me['organization_id'] : null) : user_default_organization_id((int)$ownerId);
                $assistantId = ai_assistant_save($selectedLine, (int)$ownerId, $orgId ?: null, $_POST, $actor);
                if (ai_data_available()) {
                    ai_assistant_set_datasets($assistantId, (int)$ownerId, (array)($_POST['dataset_ids'] ?? []));
                }
                flash('success', !empty($_POST['enabled']) ? 'منشی هوشمند این خط ذخیره و فعال شد.' : 'تنظیمات ذخیره شد (منشی خاموش است).');
                break;
            }
            case 'toggle': {
                $assistant = ai_assistant_for_line($selectedLine) ?? throw new AppException('برای این خط منشی تعریف نشده است.');
                ai_assistant_set_enabled((int)$assistant['id'], !(int)$assistant['enabled'], $actor);
                flash('success', (int)$assistant['enabled'] ? 'منشی هوشمند این خط خاموش شد.' : 'منشی هوشمند این خط روشن شد.');
                break;
            }
            case 'simulate': {
                $stay = true;
                $assistant = ai_assistant_for_line($selectedLine) ?? throw new AppException('ابتدا تنظیمات منشی را ذخیره کنید.');
                $message = trim((string)($_POST['message'] ?? ''));
                if ($message === '' || mb_strlen($message) > AI_SECRETARY_MAX_INPUT_CHARS) throw new AppException('متن پیام آزمایشی را وارد کنید.');
                // A customer's own test is a real model call and is charged; a platform admin's is free.
                $simulation = ai_secretary_simulate($assistant, (string)($_POST['sender'] ?? ''), $message, $actor, !$isAdmin);
                break;
            }
            case 'manual_reply': {
                $assistant = ai_assistant_for_line($selectedLine) ?? throw new AppException('برای این خط منشی تعریف نشده است.');
                $to = normalize_originator((string)($_POST['sender'] ?? '')) ?? throw new AppException('شماره‌ی مشتری نامعتبر است.');
                ai_conversation_manual_reply($assistant, $to, (string)($_POST['text'] ?? ''), $actor);
                flash('success', 'پاسخ شما ارسال شد.');
                redirect('/ai-secretary.php?line=' . $selectedLine . '#handoffs');
            }
            case 'resume': {
                $to = normalize_originator((string)($_POST['sender'] ?? '')) ?? throw new AppException('شماره‌ی مشتری نامعتبر است.');
                ai_conversation_resume($selectedLine, $to, $actor);
                flash('success', 'منشی هوشمند دوباره به این شماره پاسخ می‌دهد.');
                redirect('/ai-secretary.php?line=' . $selectedLine . '#handoffs');
            }
            case 'settings_save':
                if (!$isAdmin) throw new AppException('دسترسی ندارید.');
                ai_secretary_settings_save($_POST, $actor);
                flash('success', 'تنظیمات سامانه ذخیره شد.');
                break;
            default:
                throw new AppException('عملیات ناشناخته.');
        }
    } catch (AppException $e) {
        flash('error', $e->getMessage());
    }
    if (!$stay) {
        redirect('/ai-secretary.php' . ($selectedLine !== '' ? '?line=' . $selectedLine : ''));
    }
}

/* ---------------- Data for the view ---------------- */
$assistants = [];
foreach (ai_assistants_for_lines($myLines) as $a) $assistants[(string)$a['line']] = $a;
$lineRows = $myLines ?? array_keys($assistants);
if ($isAdmin && $selectedLine !== '' && !in_array($selectedLine, $lineRows, true)) $lineRows[] = $selectedLine;
$today = ai_secretary_today_counts($myLines);
$logStatus = (string)($_GET['status'] ?? '');
$logPage = max(1, (int)($_GET['page'] ?? 1));
$log = ai_secretary_log($myLines, $selectedLine !== '' ? $selectedLine : null, $logStatus, 30, ($logPage - 1) * 30);
$editing = $selectedLine !== '' ? ($assistants[$selectedLine] ?? ai_assistant_defaults($selectedLine)) : null;
$editingOwner = $selectedLine !== '' ? $ownerFor($selectedLine) : null;
$ownerModels = $editingOwner !== null ? ai_access_effective($editingOwner) : ['models' => [], 'default' => null];
$hasAccess = $isAdmin || ($myAccess !== null && $myAccess['models'] !== []);
/** What one answer of the line being edited costs its owner (null when billing is off or unpriced). */
$priceInfo = null;
if ($editingOwner !== null && ai_billing_enabled()) {
    $priceModel = isset($assistants[$selectedLine]) ? ai_secretary_resolve_model($assistants[$selectedLine]) : $ownerModels['default'];
    if ($priceModel !== null) {
        $priceInfo = ai_price_resolve($editingOwner, (int)$priceModel['id']) + ['model' => $priceModel];
    }
}
$rialText = static fn(int $millis): string => to_persian_digits(number_format($millis / SMS_PRICE_SCALE, $millis % SMS_PRICE_SCALE === 0 ? 0 : 1));

$statusLabels = ['pending' => 'در صف', 'processing' => 'در حال پاسخ', 'sent' => 'پاسخ داده شد', 'skipped' => 'بدون پاسخ', 'failed' => 'ناموفق'];
$statusColors = ['sent' => '#15803d', 'failed' => '#b91c1c', 'skipped' => '#92400e'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>منشی هوشمند</h2>
  <p class="muted">
    پیامک‌هایی که روی خط شما دریافت می‌شود با هوش مصنوعی و بر اساس اطلاعات کسب‌وکار شما جواب داده می‌شود.
    پیام‌های «۱۱/۱۲» (لغو/عضویت) و پیام‌هایی که یکی از قانون‌های <a href="/autoreply.php">منشی پیامک</a> (کلیدواژه) به آن‌ها جواب می‌دهد، به منشی هوشمند نمی‌رسند.
    هزینه‌ی هر پاسخ دو بخش دارد: پیامکِ پاسخ (مانند پیامک عادی) و هزینه‌ی هوش مصنوعی؛ هر دو از اعتبار حساب کسر می‌شوند.
  </p>
  <?php if (!ai_secretary_enabled()): ?>
    <p><strong style="color:#b91c1c">این سرویس در حال حاضر از طرف مدیریت سامانه متوقف است؛ پیام‌ها پاسخ داده نمی‌شوند.</strong></p>
  <?php endif; ?>
  <?php if (!$hasAccess): ?>
    <p><strong>هیچ مدل هوش مصنوعی برای حساب شما فعال نیست.</strong> برای فعال‌سازی با پشتیبانی تماس بگیرید.</p>
  <?php endif; ?>
  <p class="muted">
    امروز: <?= to_persian_digits((string)$today['sent']) ?> پاسخ ·
    <?= to_persian_digits((string)$today['skipped']) ?> بدون پاسخ ·
    <?= to_persian_digits((string)$today['failed']) ?> ناموفق ·
    <?= to_persian_digits((string)($today['pending'] + $today['processing'])) ?> در صف
  </p>
</div>

<div class="card">
  <h2>خط‌ها</h2>
  <table class="table">
    <thead><tr><th>خط</th><th>وضعیت</th><th>حالت</th><th>کسب‌وکار</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lineRows as $line): $a = $assistants[$line] ?? null; ?>
      <tr<?= $line === $selectedLine ? ' style="background:var(--surface-2, #f5f5ff)"' : '' ?>>
        <td class="mono"><?= e($line) ?></td>
        <td><?= $a === null ? '<span class="muted">تنظیم نشده</span>' : ((int)$a['enabled'] ? '<strong style="color:#15803d">روشن</strong>' : '<span class="muted">خاموش</span>') ?></td>
        <td><?= $a === null ? '—' : ($a['mode'] === 'general' ? 'دستیار عمومی' : 'منشی کسب‌وکار') ?></td>
        <td><?= e((string)($a['business_name'] ?? '')) ?></td>
        <td style="white-space:nowrap">
          <a class="btn<?= $line === $selectedLine ? ' btn-primary' : '' ?>" href="/ai-secretary.php?line=<?= e($line) ?>"><?= $a === null ? 'راه‌اندازی' : 'تنظیمات' ?></a>
          <?php if ($a !== null && $hasAccess): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="line" value="<?= e($line) ?>">
              <button class="btn"><?= (int)$a['enabled'] ? 'خاموش کردن' : 'روشن کردن' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($lineRows === []): ?>
      <tr><td colspan="5" class="muted"><?= $isAdmin ? 'هنوز هیچ خطی منشی هوشمند ندارد. شماره‌ی خط را پایین وارد کنید.' : e(NO_ASSIGNED_LINE_MESSAGE) ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($isAdmin): ?>
    <form method="get" class="toolbar">
      <label>تنظیم منشی برای خط <input type="text" name="line" class="mono" placeholder="5000123" required></label>
      <button class="btn">باز کردن</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($editing !== null && $hasAccess): ?>
<div class="card">
  <h2>منشی خط <span class="mono"><?= e($selectedLine) ?></span></h2>
  <?php if ($isAdmin && $editingOwner === null): ?>
    <p class="muted">این خط به حسابی تخصیص داده نشده است؛ مالک (حسابی که هزینه‌ی پاسخ‌ها را می‌پردازد) را مشخص کنید.</p>
  <?php elseif ($isAdmin): ?>
    <p class="muted">مالک: <span class="mono"><?= e((string)(backend_usernames_by_ids([$editingOwner])[$editingOwner] ?? '#' . $editingOwner)) ?></span></p>
  <?php endif; ?>
  <?php if ($editingOwner !== null && $ownerModels['models'] === []): ?>
    <p><strong style="color:#b91c1c">هیچ مدل هوش مصنوعی برای حساب مالک این خط فعال نیست؛ منشی پاسخی نمی‌دهد.</strong></p>
  <?php endif; ?>
  <?php if ($priceInfo !== null): ?>
    <p class="muted">
      هزینه‌ی هوش مصنوعی هر پاسخ (<?= e($priceInfo['model']['display_name']) ?>):
      <strong><?= $priceInfo['answer'] > 0 ? $rialText($priceInfo['answer']) . ' ریال' : 'رایگان' ?></strong>
      <?php if ($priceInfo['overage'] > 0): ?>
        تا <?= to_persian_digits(number_format($priceInfo['included'])) ?> توکن، و برای هر ۱۰۰۰ توکن بیشتر <?= $rialText($priceInfo['overage']) ?> ریال
      <?php endif; ?>
      — به‌علاوه‌ی هزینه‌ی پیامکِ پاسخ. اطلاعات کسب‌وکار طولانی‌تر، توکن بیشتری مصرف می‌کند.
    </p>
  <?php endif; ?>
  <form method="post" id="assistant-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="line" value="<?= e($selectedLine) ?>">
    <?php if ($isAdmin && $editingOwner === null): ?>
      <div class="toolbar"><label>نام کاربری مالک <input type="text" name="owner" class="mono" required></label></div>
    <?php endif; ?>
    <div class="toolbar">
      <label><input type="checkbox" name="enabled" value="1"<?= (int)$editing['enabled'] ? ' checked' : '' ?>> <strong>منشی هوشمند روشن باشد</strong></label>
      <label>الگو
        <select name="template" id="as-template">
          <option value="custom"<?= $editing['template'] === 'custom' ? ' selected' : '' ?>>دلخواه</option>
          <?php foreach (AI_SECRETARY_TEMPLATES as $key => [$label]): ?>
            <option value="<?= e($key) ?>"<?= $editing['template'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>حالت
        <select name="mode" id="as-mode">
          <option value="business"<?= $editing['mode'] === 'business' ? ' selected' : '' ?>>منشی کسب‌وکار (فقط از اطلاعات شما)</option>
          <option value="general"<?= $editing['mode'] === 'general' ? ' selected' : '' ?>>دستیار عمومی (پاسخ به هر سؤال)</option>
        </select>
      </label>
      <label>نام کسب‌وکار <input type="text" name="business_name" value="<?= e((string)$editing['business_name']) ?>" maxlength="120" placeholder="کلینیک دندانپزشکی لبخند"></label>
    </div>
    <label style="display:block">دستورالعمل (منشی چطور رفتار کند)
      <textarea name="instructions" id="as-instructions" rows="4" style="width:100%" maxlength="<?= AI_SECRETARY_MAX_INSTRUCTIONS_CHARS ?>"><?= e((string)$editing['instructions']) ?></textarea>
    </label>
    <label style="display:block">اطلاعات کسب‌وکار (ساعت کاری، خدمات، قیمت‌ها، آدرس، سؤال‌های پرتکرار ...)
      <textarea name="knowledge" id="as-knowledge" rows="8" style="width:100%" maxlength="<?= AI_SECRETARY_MAX_KNOWLEDGE_CHARS ?>"><?= e((string)$editing['knowledge']) ?></textarea>
    </label>
    <p class="muted">در حالت «منشی کسب‌وکار» فقط از همین اطلاعات جواب داده می‌شود؛ اگر جواب در آن نباشد، منشی حدس نمی‌زند و می‌گوید همکاران پیگیری می‌کنند.</p>
    <div class="toolbar">
      <label>مدل
        <select name="model_id">
          <option value="0">پیش‌فرض حساب<?= $ownerModels['default'] ? ' (' . e($ownerModels['default']['display_name']) . ')' : '' ?></option>
          <?php foreach ($ownerModels['models'] as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= (int)($editing['model_id'] ?? 0) === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>حداکثر طول پاسخ
        <select name="max_parts">
          <?php for ($p = 1; $p <= 5; $p++): ?>
            <option value="<?= $p ?>"<?= (int)$editing['max_parts'] === $p ? ' selected' : '' ?>><?= to_persian_digits((string)$p) ?> پارت (حدود <?= to_persian_digits((string)ai_secretary_part_chars($p)) ?> نویسه)</option>
          <?php endfor; ?>
        </select>
      </label>
      <label>به‌خاطرسپردن <input type="number" name="memory_turns" min="0" max="20" value="<?= (int)$editing['memory_turns'] ?>" style="width:5em"> پیام قبلی</label>
      <label>در <input type="number" name="memory_hours" min="1" max="720" value="<?= (int)$editing['memory_hours'] ?>" style="width:5em"> ساعت گذشته</label>
    </div>
    <div class="toolbar">
      <label>سقف پاسخ به هر شماره در ساعت <input type="number" name="sender_hourly_limit" min="0" max="1000" value="<?= (int)$editing['sender_hourly_limit'] ?>" style="width:6em"></label>
      <label>سقف پاسخ روزانه‌ی خط <input type="number" name="line_daily_limit" min="0" value="<?= (int)$editing['line_daily_limit'] ?>" style="width:7em"></label>
      <span class="muted">(۰ = بدون سقف)</span>
    </div>
    <?php if (ai_data_available()):
        $ownerTables = $editingOwner !== null ? ai_datasets_for($editingOwner) : [];
        $linkedTables = isset($assistants[$selectedLine]) ? array_column(ai_assistant_datasets((int)$assistants[$selectedLine]['id']), 'id') : []; ?>
      <fieldset style="border:1px solid var(--border, #ddd);border-radius:8px;padding:8px 12px;margin:8px 0">
        <legend>جدول‌های داده (جست‌وجوی سفارش، محصول، نوبت ...)</legend>
        <?php foreach ($ownerTables as $t): ?>
          <label style="display:inline-block;margin-inline-end:16px"><input type="checkbox" name="dataset_ids[]" value="<?= $t['id'] ?>"<?= in_array($t['id'], $linkedTables, true) ? ' checked' : '' ?>>
            <?= e($t['name']) ?> <span class="muted">(<?= to_persian_digits(number_format((int)$t['row_count'])) ?> ردیف<?= $t['phone_column'] !== null ? '، فقط ردیف‌های همان شماره' : '' ?>)</span></label>
        <?php endforeach; ?>
        <?php if ($ownerTables === []): ?><span class="muted">جدولی ندارید.</span><?php endif; ?>
        <a class="btn" href="/ai-data.php">مدیریت جدول‌ها</a>
        <div class="toolbar" style="margin-top:6px">
          <label>روش جست‌وجو
            <select name="lookup_mode">
              <?php foreach (['auto' => 'خودکار (ابزار اگر مدل پشتیبانی کند)', 'search' => 'جست‌وجوی خودکار در پیام', 'tools' => 'ابزار (فقط مدل‌های دارای ابزار)'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= ($editing['lookup_mode'] ?? 'auto') === $k ? ' selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </fieldset>
      <div class="toolbar">
        <label><input type="checkbox" name="handoff_enabled" value="1"<?= (int)($editing['handoff_enabled'] ?? 1) ? ' checked' : '' ?>> ارجاع به اپراتور: وقتی مشتری اپراتور بخواهد، شکایت کند یا منشی نتواند کمک کند</label>
        <label>پس از ارجاع، منشی <input type="number" name="handoff_pause_hours" min="0" max="720" value="<?= (int)($editing['handoff_pause_hours'] ?? 24) ?>" style="width:5em"> ساعت به آن شماره پاسخ ندهد (۰ = ادامه دهد)</label>
      </div>
    <?php endif; ?>
    <label style="display:block">متن جایگزین وقتی هوش مصنوعی در دسترس نیست (خالی = پاسخی ارسال نشود)
      <input type="text" name="fallback_text" value="<?= e((string)$editing['fallback_text']) ?>" maxlength="500" style="width:100%" placeholder="پیام شما دریافت شد؛ همکاران ما به‌زودی پاسخ می‌دهند.">
    </label>
    <button class="btn btn-primary" style="margin-top:8px">ذخیره</button>
  </form>
</div>
<script>
// Convenience only: fills the instructions/knowledge from the chosen template when they are empty,
// or after a confirmation when they are not. The server stores whatever is submitted.
(function () {
  var templates = <?= json_encode(array_map(static fn(array $t): array => ['mode' => $t[1], 'instructions' => $t[2], 'knowledge' => $t[3]], AI_SECRETARY_TEMPLATES), JSON_UNESCAPED_UNICODE) ?>;
  var select = document.getElementById('as-template');
  var ins = document.getElementById('as-instructions'), kn = document.getElementById('as-knowledge'), mode = document.getElementById('as-mode');
  if (!select || !ins || !kn || !mode) { return; }
  select.addEventListener('change', function () {
    var t = templates[select.value];
    if (!t) { return; }
    if ((ins.value.trim() !== '' || kn.value.trim() !== '') && !confirm('متن فعلی دستورالعمل و اطلاعات با نمونه‌ی این الگو جایگزین شود؟')) { return; }
    ins.value = t.instructions; kn.value = t.knowledge; mode.value = t.mode;
  });
})();
</script>

<?php if (isset($assistants[$selectedLine])): ?>
<div class="card">
  <h2>آزمایش منشی</h2>
  <p class="muted">پیامی بنویسید تا ببینید منشی با تنظیمات <strong>ذخیره‌شده</strong> چه جوابی می‌دهد. هیچ پیامکی ارسال نمی‌شود.
    اگر شماره‌ی فرستنده را وارد کنید، گفتگوی قبلی همان شماره هم در نظر گرفته می‌شود.
    <?= $isAdmin ? '' : 'هزینه‌ی هوش مصنوعیِ هر آزمایش مانند یک پاسخ کسر می‌شود (بدون هزینه‌ی پیامک).' ?></p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="simulate"><input type="hidden" name="line" value="<?= e($selectedLine) ?>">
    <div class="toolbar">
      <label>شماره‌ی فرستنده (اختیاری) <input type="text" name="sender" class="mono" value="<?= e((string)($_POST['sender'] ?? '')) ?>" placeholder="989121234567"></label>
    </div>
    <label style="display:block">پیام مشتری
      <textarea name="message" rows="2" style="width:100%" required><?= e((string)($_POST['message'] ?? '')) ?></textarea>
    </label>
    <button class="btn btn-primary" style="margin-top:8px">آزمایش</button>
  </form>
  <?php if ($simulation !== null): ?>
    <?php if ($simulation['ok']): ?>
      <div style="white-space:pre-wrap;border:1px solid var(--border, #ddd);border-radius:8px;padding:12px;margin-top:12px"><?= e($simulation['text']) ?></div>
      <p class="muted">
        مدل: <?= e($simulation['model']['display_name']) ?> ·
        <?= to_persian_digits((string)sms_parts($simulation['text'])) ?> پارت (<?= to_persian_digits((string)mb_strlen($simulation['text'])) ?> نویسه) ·
        زمان پاسخ: <span class="mono"><?= (int)$simulation['latency_ms'] ?> ms</span> ·
        توکن: <span class="mono"><?= $simulation['input_tokens'] ?? '—' ?> / <?= $simulation['output_tokens'] ?? '—' ?></span>
        <?php if ($simulation['history'] > 0): ?> · با <?= to_persian_digits((string)$simulation['history']) ?> پیام قبلی این شماره<?php endif; ?>
        <?php if (($simulation['charge'] ?? null) !== null): ?> · هزینه: <?= $simulation['charge']['amount'] > 0 ? to_persian_digits(number_format($simulation['charge']['amount'])) . ' ریال' : 'رایگان' ?><?php endif; ?>
      </p>
    <?php else: ?>
      <p style="margin-top:12px"><strong style="color:#b91c1c">ناموفق:</strong> <?= e($simulation['message']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
  $handoffLines = $selectedLine !== '' ? [$selectedLine] : $myLines;
  $handoffs = ai_conversations_handed_over($handoffLines);
  if ($handoffs !== []): ?>
<div class="card" id="handoffs">
  <h2>گفتگوهای ارجاع‌شده به اپراتور</h2>
  <p class="muted">در این گفتگوها مشتری پاسخ انسانی خواسته یا منشی نتوانسته کمک کند. تا وقتی گفتگو «متوقف» است منشی به آن شماره جواب نمی‌دهد؛
    از همین‌جا پاسخ بدهید (مثل پیامک عادی هزینه دارد) و بعد «ادامه با منشی هوشمند» را بزنید.</p>
  <?php foreach ($handoffs as $h):
      $recent = db()->prepare('SELECT kind, content, reply_text, created_at FROM ellsms_ai_jobs WHERE line = ? AND sender = ? ORDER BY id DESC LIMIT 6');
      $recent->execute([$h['line'], $h['sender']]); ?>
    <div style="border:1px solid var(--border, #ddd);border-radius:8px;padding:10px;margin-bottom:10px">
      <p><span class="mono"><?= e($h['sender']) ?></span> روی خط <span class="mono"><?= e($h['line']) ?></span> —
        <?= (int)$h['is_paused'] ? '<strong style="color:#b91c1c">منشی متوقف است</strong>' : '<span class="muted">' . ($h['resolved_at'] ? 'به منشی برگشت' : 'منشی فعال') . '</span>' ?>
        · <?= jdate((string)$h['handoff_at']) ?></p>
      <ul style="margin:0 0 8px">
        <?php foreach (array_reverse($recent->fetchAll()) as $m): ?>
          <?php if (trim((string)$m['content']) !== ''): ?><li>مشتری: <?= e(mb_strimwidth((string)$m['content'], 0, 200, '…')) ?></li><?php endif; ?>
          <?php if ($m['reply_text'] !== null): ?><li class="muted"><?= $m['kind'] === 'manual' ? 'اپراتور' : 'منشی' ?>: <?= e(mb_strimwidth((string)$m['reply_text'], 0, 200, '…')) ?></li><?php endif; ?>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="toolbar">
        <?= csrf_field() ?><input type="hidden" name="do" value="manual_reply"><input type="hidden" name="line" value="<?= e($h['line']) ?>"><input type="hidden" name="sender" value="<?= e($h['sender']) ?>">
        <label style="flex:1 1 24em">پاسخ شما <input type="text" name="text" maxlength="1000" style="width:100%" required></label>
        <button class="btn btn-primary">ارسال پاسخ</button>
      </form>
      <?php if ((int)$h['is_paused']): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="resume"><input type="hidden" name="line" value="<?= e($h['line']) ?>"><input type="hidden" name="sender" value="<?= e($h['sender']) ?>">
          <button class="btn">ادامه با منشی هوشمند</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>گفتگوها<?= $selectedLine !== '' ? ' — خط <span class="mono">' . e($selectedLine) . '</span>' : '' ?></h2>
  <form method="get" class="toolbar">
    <?php if ($selectedLine !== ''): ?><input type="hidden" name="line" value="<?= e($selectedLine) ?>"><?php endif; ?>
    <label>وضعیت
      <select name="status" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach ($statusLabels as $key => $label): ?><option value="<?= $key ?>"<?= $logStatus === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><th>زمان</th><?= $selectedLine === '' ? '<th>خط</th>' : '' ?><th>فرستنده</th><th>پیام</th><th>پاسخ</th><th>وضعیت</th></tr></thead>
    <tbody>
    <?php foreach ($log as $row): ?>
      <tr>
        <td style="white-space:nowrap"><?= jdate((string)$row['created_at']) ?></td>
        <?php if ($selectedLine === ''): ?><td class="mono"><?= e($row['line']) ?></td><?php endif; ?>
        <td class="mono"><?= e($row['sender']) ?></td>
        <td style="max-width:22em;white-space:pre-wrap"><?= e(mb_strimwidth((string)$row['content'], 0, 300, '…')) ?></td>
        <td style="max-width:26em;white-space:pre-wrap"><?= $row['reply_text'] !== null ? e((string)$row['reply_text']) : '<span class="muted">—</span>' ?>
          <?php if ($row['reply_text'] !== null): ?>
            <br><span class="muted"><?= (int)$row['is_fallback'] ? 'متن جایگزین · ' : '' ?><?= e((string)($row['model_name'] ?? '')) ?><?= $row['reply_parts'] ? ' · ' . to_persian_digits((string)$row['reply_parts']) . ' پارت' : '' ?><?= isset($row['ai_charge']) && $row['ai_charge'] !== null ? ' · هوش مصنوعی: ' . ((int)$row['ai_charge'] > 0 ? to_persian_digits(number_format((int)$row['ai_charge'])) . ' ریال' : 'رایگان') : '' ?></span>
          <?php endif; ?>
        </td>
        <td>
          <strong style="color:<?= $statusColors[$row['status']] ?? 'inherit' ?>"><?= $statusLabels[$row['status']] ?? e($row['status']) ?></strong>
          <?php if ($row['skip_reason']): ?><br><span class="muted"><?= e(ai_secretary_reason_label((string)$row['skip_reason'])) ?></span><?php endif; ?>
          <?php if (!empty($row['handoff'])): ?><br><span class="badge">ارجاع به اپراتور</span><?php endif; ?>
          <?php if (($row['kind'] ?? '') === 'manual'): ?><br><span class="muted">پاسخ اپراتور</span><?php endif; ?>
          <?php if (isset($row['records_used']) && $row['records_used'] !== null): ?><br><span class="muted"><?= to_persian_digits((string)$row['records_used']) ?> ردیف داده</span><?php endif; ?>
          <?php if ($row['status'] === 'failed' || ($row['status'] === 'pending' && $row['error_code'])): ?>
            <br><span class="muted"><?= e($row['error_code'] === 'send_failed' ? (string)$row['info'] : ai_secretary_reason_label((string)$row['error_code'])) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($log === []): ?><tr><td colspan="6" class="muted">هنوز گفتگویی ثبت نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="toolbar">
    <?php if ($logPage > 1): ?><a class="btn" href="?<?= e(http_build_query(array_filter(['line' => $selectedLine, 'status' => $logStatus, 'page' => $logPage - 1]))) ?>">قبلی</a><?php endif; ?>
    <?php if (count($log) === 30): ?><a class="btn" href="?<?= e(http_build_query(array_filter(['line' => $selectedLine, 'status' => $logStatus, 'page' => $logPage + 1]))) ?>">بعدی</a><?php endif; ?>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="card">
  <h2>تنظیمات سامانه (مدیر)</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings_save">
    <label><input type="checkbox" name="ai_secretary_enabled" value="1"<?= ai_secretary_enabled() ? ' checked' : '' ?>> سرویس منشی هوشمند فعال باشد</label>
    <label>پیام‌های قدیمی‌تر از <input type="number" name="ai_secretary_max_age_minutes" min="1" max="1440" value="<?= ai_secretary_setting('ai_secretary_max_age_minutes') ?>" style="width:5em"> دقیقه پاسخ داده نشوند</label>
    <label>تلاش برای هر پیام <input type="number" name="ai_secretary_max_attempts" min="1" max="10" value="<?= ai_secretary_setting('ai_secretary_max_attempts') ?>" style="width:4em"></label>
    <label>نگهداری گفتگوها <input type="number" name="ai_secretary_retention_days" min="7" max="3650" value="<?= ai_secretary_setting('ai_secretary_retention_days') ?>" style="width:5em"> روز</label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
  <p class="muted">
    هزینه‌ی هوش مصنوعی هر پاسخ با «تعرفه‌ی هوش مصنوعی» (مدیریت ← موتورهای هوش مصنوعی) محاسبه و پس از ارسال پاسخ کسر می‌شود؛ هزینه‌ی پیامکِ پاسخ مثل پاسخ خودکار کسر می‌شود.
    پاسخ‌ها را سرویس <span class="mono">ai-worker</span> ارسال می‌کند.
  </p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
