<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'ارسال پیامک';
$active = 'send';

// Phase 7: same MESSAGES_SEND gate as public/new-send.php — granted to every built-in role by
// default today (app/rbac.php), platform admins keep their existing unrestricted bypass.
$rbacOrg = is_admin() ? null : require_permission(Permissions::MESSAGES_SEND);

// Phase 6 closure: same organization-or-legacy-fallback ownership shape as public/contacts.php —
// an organization-scoped row is visible to any active member; a legacy row not yet backfilled
// (NULL) falls back to the exact pre-Phase-6 user-only behavior.
$myOrgId = $me['organization_id'] ?? null;
$contactOwnership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';

$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE {$contactOwnership} AND group_name<>'' ORDER BY group_name");
$groups->execute([$myOrgId, $me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

$myNumbers = user_sendable_numbers($me); // own + organization + shared (app/authorization.php)

$categories = db()->prepare(
    "SELECT c.id, c.name, (SELECT COUNT(*) FROM ellsms_number_category_items i WHERE i.category_id = c.id) c
     FROM ellsms_number_categories c WHERE (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL)) ORDER BY c.name"
);
$categories->execute([$myOrgId, $myOrgId]);
$categories = $categories->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $originator = trim($_POST['originator'] ?? '') ?: user_default_send_originator($me);
    $content    = trim($_POST['content'] ?? '');
    $dests      = parse_destinations($_POST['destinations'] ?? '');

    // The RAW, pre-normalization tokens, collected alongside $dests purely so the cost preview can
    // report what was actually filtered out. $dests is already normalized AND deduplicated by
    // parse_destinations(), so handing that to the estimator would make it truthfully report zero
    // invalid and zero duplicates — accurate about what it received, but useless to a user who
    // pasted 10 numbers and wants to know why only 7 will be sent. The send itself still uses
    // $dests exactly as before; this list is only ever read by the preview.
    $rawDests = preg_split('/[\s,;،]+/u', (string)($_POST['destinations'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (!empty($_POST['group'])) {
        $st = db()->prepare("SELECT mobile FROM ellsms_contacts WHERE {$contactOwnership} AND group_name=?");
        $st->execute([$myOrgId, $me['id'], $_POST['group']]);
        foreach ($st->fetchAll() as $c) {
            $rawDests[] = $c['mobile'];
            $n = normalize_msisdn($c['mobile']);
            if ($n) $dests[] = $n;
        }
    }

    if (!empty($_POST['category'])) {
        // Phase 6 closure: this previously had NO ownership check at all — any authenticated user
        // could submit any category_id and get its numbers expanded, a real IDOR once categories
        // became organization-scoped (harmless before, when every category was globally shared by
        // design). Verifies the category belongs to the caller's own organization (or is a
        // not-yet-backfilled legacy row) before ever reading its items.
        $st = db()->prepare(
            "SELECT i.mobile FROM ellsms_number_category_items i
             JOIN ellsms_number_categories c ON c.id = i.category_id
             WHERE i.category_id = ? AND (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL))"
        );
        $st->execute([(int)$_POST['category'], $myOrgId, $myOrgId]);
        foreach ($st->fetchAll() as $c) { $dests[] = $c['mobile']; $rawDests[] = $c['mobile']; }
    }
    $dests = array_values(array_unique($dests));

    // Preview is checked BEFORE the immediate/scheduled split so BOTH modes get a cost preview
    // (STEP 17) — otherwise picking "schedule" and clicking preview would have created the schedule
    // outright, with no preview at all.
    if (($_POST['do'] ?? '') === 'preview') {
        // Read-only. Prices the EXACT $originator/$dests/$content this same request already
        // assembled above, so what is shown is what the confirm branch will send — there is no
        // second parsing path that could drift.
        $costPreview = estimate_message_cost($me, $originator, $rawDests, $content);
        cost_preview_record($costPreview, (int)($me['organization_id'] ?? 0), (int)$me['id'], 'web_send');
        if (!$costPreview['ok']) {
            // A pricing gap gets its own card rather than a one-line flash: STEP 44 requires the
            // user to see input / priced / unpriced counts and the reason, not just "failed".
            if (($costPreview['reason'] ?? '') === 'pricing_unavailable') {
                $costPricingFailure = $costPreview;
            } else {
                flash('error', cost_preview_reason_message((string)$costPreview['reason']));
            }
            $costPreview = null;
        }
    } elseif (($_POST['mode'] ?? 'now') === 'later') {
        $gDate = jalali_request_to_gregorian('send_date');
        $time  = time_post('send_time');
        $runAt = ($gDate && $time) ? "{$gDate} {$time}:00" : null;

        if ($rbacOrg !== null && !membership_has_permission($rbacOrg, Permissions::SCHEDULES_MANAGE)) {
            flash('error', 'شما اجازه‌ی زمان‌بندی ارسال را ندارید.');
        } elseif (!organization_has_entitlement((int)($me['organization_id'] ?? 0), Entitlements::SCHEDULES)) {
            // Phase 13 (STEP 14): plan entitlement checked alongside the RBAC gate above, same as
            // public/new-send.php's own scheduling branch.
            flash('error', 'ارسال زمان‌بندی‌شده در پلن فعلی سازمان شما موجود نیست. برای استفاده، پلن خود را ارتقا دهید.');
        } elseif (!$dests) {
            flash('error', 'شماره مقصد معتبری وارد نشده است.');
        } elseif ($content === '') {
            flash('error', 'متن پیام خالی است.');
        } elseif (!$runAt || $runAt <= date('Y-m-d H:i:s')) {
            flash('error', 'زمان زمان‌بندی‌شده باید در آینده باشد.');
        } elseif (impersonation_guard_post('send.schedule')) {
            // A schedule is a send that happens later, so it is blocked in support mode exactly like
            // an immediate one (docs/admin-impersonation.md).
        } else {
            $repeat = in_array($_POST['repeat'] ?? 'none', ['none','daily','weekly','monthly'], true) ? $_POST['repeat'] : 'none';
            $slot = entitlement_with_resource_slot((int)($me['organization_id'] ?? 0), Limits::ACTIVE_SCHEDULES, static function (PDO $db) use ($me, $originator, $dests, $content, $runAt, $repeat) {
                $db->prepare('INSERT INTO ellsms_schedule (user_id, organization_id, originator, destinations, content, run_at, repeat_type)
                              VALUES (?,?,?,?,?,?,?)')
                   ->execute([$me['id'], $me['organization_id'] ?? null, $originator, json_encode($dests), $content, $runAt, $repeat]);
                return true;
            });
            if (!$slot['ok']) {
                flash('error', 'به سقف تعداد زمان‌بندی‌های فعال پلن فعلی رسیده‌اید (' . to_persian_digits((string)$slot['limit']) . ' مورد). زمان‌بندی‌های موجود دست‌نخورده باقی می‌مانند.');
            } else {
                audit((int)$me['id'], 'schedule.create', count($dests) . ' dest @ ' . $runAt);
                $repeatFa = ['none' => '', 'daily' => ' (تکرار روزانه)', 'weekly' => ' (تکرار هفتگی)', 'monthly' => ' (تکرار ماهانه)'][$repeat];
                flash('success', 'برای ' . jdate($runAt) . $repeatFa . ' زمان‌بندی شد — ' . to_persian_digits((string)count($dests)) . ' شماره.');
                redirect('/schedules.php');
            }
        }
    } else {
        // Confirm branch. Everything is recomputed server-side from the resubmitted inputs — the
        // hidden previewed-cost field is used ONLY to detect that the authoritative price moved
        // since the user looked at it, never as the price itself (Invariant I/H).
        $previewedCost = isset($_POST['previewed_cost']) && ctype_digit((string)$_POST['previewed_cost']) ? (int)$_POST['previewed_cost'] : null;
        $previewedAt   = isset($_POST['previewed_at']) && ctype_digit((string)$_POST['previewed_at']) ? (int)$_POST['previewed_at'] : null;

        $blocked = false;
        if ($previewedCost !== null) {
            // Same raw input the preview priced, so the two estimates are directly comparable.
            $recheck = estimate_message_cost($me, $originator, $rawDests, $content);
            if ($recheck['ok']) {
                $check = cost_preview_confirmation_check($previewedCost, (int)$recheck['pricing']['estimated_cost'], $previewedAt);
                if ($check['require_reconfirm']) {
                    // Re-show the preview with the CURRENT numbers rather than silently charging a
                    // different amount than the user agreed to (STEP 21/22).
                    $costPreview = $recheck;
                    $blocked = true;
                    flash('error', $check['expired']
                        ? 'پیش‌نمایش هزینه منقضی شده است — لطفاً هزینه‌ی به‌روز را بررسی و دوباره تأیید کنید.'
                        : 'هزینه‌ی ارسال از زمان پیش‌نمایش تغییر کرده است — لطفاً مقدار جدید را بررسی و دوباره تأیید کنید.');
                }
            }
        }

        if (!$blocked) {
            // The authoritative charge. dispatch_message() re-derives cost, re-reserves the wallet
            // under a row lock and re-reserves quota atomically — the preview above never reserved
            // anything, so a balance/quota race between preview and here fails safely right here.
            [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
            flash($ok ? 'success' : 'error', $info);
            audit((int)$me['id'], 'sms.send', count($dests) . ' dest, ok=' . (int)$ok);
            if ($ok) redirect('/reports.php');
        }
    }
}

$extraStyles = ['/assets/css/sms-composer.css'];
require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'send.direct';
require __DIR__ . '/../app/views/impersonation_notice.php';

// Carries the original submission forward so confirming resubmits IDENTICAL inputs — the confirm
// branch re-parses and re-prices them server-side, so these are inputs to be re-validated, never
// trusted values (Invariant I).
if (!empty($costPricingFailure)) {
    require __DIR__ . '/../app/views/cost_preview_unpriced.php';
}
if (isset($costPreview) && $costPreview) {
    $previewFormFields = '';
    foreach (['originator', 'destinations', 'content', 'group', 'category', 'mode', 'repeat'] as $field) {
        $previewFormFields .= '<input type="hidden" name="' . $field . '" value="' . e((string)($_POST[$field] ?? '')) . '">';
    }
    // The schedule date/time fields (mode=later) must survive the round trip too, or confirming a
    // scheduled send after previewing it silently loses the date the user picked.
    foreach (['send_date_y', 'send_date_m', 'send_date_d', 'send_time_h', 'send_time_i'] as $field) {
        if (isset($_POST[$field])) {
            $previewFormFields .= '<input type="hidden" name="' . $field . '" value="' . e((string)$_POST[$field]) . '">';
        }
    }
    require __DIR__ . '/../app/views/cost_preview.php';
}
?>
<?php require_once __DIR__ . '/../app/views/sms_composer.php'; ?>
<form method="post" data-composer data-composer-estimate="/send-estimate.php" data-composer-live-max="<?= live_cost_max_recipients() ?>">
<?= csrf_field() ?>
<div class="composer-layout">
<div class="composer-main">

  <section class="card" aria-labelledby="stepMessage">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۱</span>
      <div><h2 id="stepMessage">متن پیام</h2><p class="hint">پیش‌نمایش و تعداد بخش‌ها همزمان با نوشتن به‌روز می‌شوند.</p></div>
    </div>
    <label>خط ارسال‌کننده (originator)
      <?php if ($myNumbers): ?>
        <select name="originator">
          <?php $pickedOriginator = (string)($_POST['originator'] ?? $me['originator']); ?>
          <?php foreach ($myNumbers as $n): ?>
            <option value="<?= e($n['number']) ?>" <?= (string)$n['number'] === $pickedOriginator ? 'selected' : '' ?>>
              <?= e($n['number']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php elseif (($me['role'] ?? null) === 'admin'): ?>
        <input type="text" name="originator" class="msisdn" value="<?= e(user_default_send_originator($me)) ?>">
        <div class="hint">خطی که گیرنده می‌بیند، مثلاً ۵۰۰۰۴۳۵۸۰۰.</div>
      <?php else: ?>
        <div class="flash flash-error"><?= e(NO_ASSIGNED_LINE_MESSAGE) ?></div>
      <?php endif; ?>
    </label>

    <label>متن پیام
      <textarea name="content" id="content" required rows="6" dir="auto" placeholder="مثلاً: مشتری گرامی، سفارش شما ارسال شد."><?= e($_POST['content'] ?? '') ?></textarea>
    </label>
    <?= composer_meter_html() ?>
  </section>

  <section class="card" aria-labelledby="stepRecipients">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۲</span>
      <div><h2 id="stepRecipients">گیرندگان</h2><p class="hint">شماره‌های ۰۹… به‌طور خودکار به ۹۸… تبدیل می‌شوند. موارد تکراری حذف می‌شوند.</p></div>
    </div>
    <?php if ($groups || $categories): ?>
    <div class="form-row">
      <?php if ($groups): ?>
      <label>افزودن یک گروه مخاطب من
        <select name="group">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"<?= (string)($_POST['group'] ?? '') === (string)$g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">همه‌ی شماره‌های گروه به فهرست زیر افزوده می‌شوند.</div>
      </label>
      <?php endif; ?>
      <?php if ($categories): ?>
      <label>افزودن یک دسته‌ی عمومی شماره
        <select name="category">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"<?= (string)($_POST['category'] ?? '') === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= to_persian_digits((string)$c['c']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="hint">دسته‌های شماره را مدیر از «دسته‌های شماره» بارگذاری می‌کند.</div>
      </label>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <label>شماره‌های مقصد
      <textarea name="destinations" class="ltr" rows="6" placeholder="989121234567&#10;989351234567 — هر شماره در یک خط، یا جدا‌شده با ویرگول / فاصله"><?= e($_POST['destinations'] ?? '') ?></textarea>
    </label>
    <?= composer_dest_stats_html() ?>
  </section>

  <section class="card" aria-labelledby="stepWhen">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۳</span>
      <div><h2 id="stepWhen">زمان ارسال</h2></div>
    </div>
    <?php $postedSendMode = ($_POST['mode'] ?? 'now') === 'later' ? 'later' : 'now'; ?>
    <div class="composer-modes composer-modes-2" role="radiogroup" aria-label="زمان ارسال">
      <label class="composer-mode"><input type="radio" name="mode" value="now"<?= $postedSendMode === 'now' ? ' checked' : '' ?>><strong>ارسال فوری</strong><span>بلافاصله پس از تأیید</span></label>
      <label class="composer-mode"><input type="radio" name="mode" value="later"<?= $postedSendMode === 'later' ? ' checked' : '' ?>><strong>زمان‌بندی برای بعداً</strong><span>در تاریخ و ساعت دلخواه، با تکرار</span></label>
    </div>
    <div class="composer-mode-panel" id="when"<?= $postedSendMode === 'later' ? '' : ' hidden' ?>>
      <div class="form-row">
        <label>تاریخ ارسال
          <?= jalali_date_select('send_date') ?>
        </label>
        <label>ساعت ارسال
          <?= time_select('send_time') ?>
        </label>
        <label>تکرار
          <select name="repeat">
            <option value="none">فقط یک‌بار</option>
            <option value="daily">هر روز</option>
            <option value="weekly">هر هفته</option>
            <option value="monthly">هر ماه</option>
          </select>
        </label>
      </div>
    </div>
  </section>
</div>

<?= composer_side_html(
    '<button class="btn btn-primary btn-block" name="do" value="preview">محاسبه‌ی هزینه و ادامه</button>'
  . '<button class="btn btn-block" name="do" value="confirm">ارسال بدون پیش‌نمایش</button>'
  . '<p class="hint">«محاسبه‌ی هزینه» چیزی ارسال نمی‌کند و از اعتبار شما کسر نمی‌شود — فقط تعداد پیامک و هزینه‌ی تقریبی را نشان می‌دهد.</p>'
) ?>
</div>
</form>

<script src="<?= e(asset_url('/assets/js/sms-composer.js')) ?>"></script>
<script>
document.querySelectorAll('input[name=mode]').forEach(function (r) {
  r.addEventListener('change', function () { document.getElementById('when').hidden = r.value !== 'later' || !r.checked; });
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
