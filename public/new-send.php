<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'پنل جدید ارسال';
$active = 'new_send';

// Phase 7: this page's whole purpose is composing and dispatching a message (direct/recurring/
// gradual), so MESSAGES_SEND gates the page itself (Invariant C: server-side, not just a hidden
// nav link) — granted to every built-in role by default today, so no current user loses access.
// SCHEDULES_MANAGE additionally gates the recurring-send branch and CAMPAIGNS_MANAGE the
// save-as-campaign checkbox specifically (STEP 14/15 — read/manage/send/schedule kept distinct).
$rbacOrg = is_admin() ? null : require_permission(Permissions::MESSAGES_SEND);

$myNumbers = user_sendable_numbers($me); // own + organization + shared (app/authorization.php)
// Service lines skip the blacklist (docs/service-lines.md) — shown next to the line and the blacklist toggle.
$serviceLines = blacklist_bypass_lines(array_column($myNumbers, 'number'));

// Phase 6 closure: same organization-or-legacy-fallback ownership shape as public/contacts.php.
$myOrgId = $me['organization_id'] ?? null;
$contactOwnership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';

$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE {$contactOwnership} AND group_name<>'' ORDER BY group_name");
$groups->execute([$myOrgId, $me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

$categories = db()->prepare(
    "SELECT c.id, c.name, (SELECT COUNT(*) FROM ellsms_number_category_items i WHERE i.category_id = c.id) c
     FROM ellsms_number_categories c WHERE (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL)) ORDER BY c.name"
);
$categories->execute([$myOrgId, $myOrgId]);
$categories = $categories->fetchAll();

// Phase 6 closure: organization-scoped so any org member sees the organization's saved campaigns,
// not just their own (STEP 1) — falls back to the legacy user-only view for a not-yet-backfilled
// row, same convention as every other resource in this migration.
$cst = db()->prepare("SELECT id, name, originator, content FROM ellsms_campaigns WHERE (organization_id = ? OR (organization_id IS NULL AND user_id = ?)) ORDER BY name");
$cst->execute([$myOrgId, $me['id']]);
$campaigns = $cst->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode       = in_array($_POST['mode'] ?? 'direct', ['direct', 'recurring', 'gradual'], true) ? $_POST['mode'] : 'direct';
    $originator = trim($_POST['originator'] ?? '') ?: user_default_send_originator($me);
    $content    = trim($_POST['content'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $dests      = parse_destinations($_POST['destinations'] ?? '');

    // RAW, pre-normalization tokens, kept alongside $dests solely so the cost preview can report
    // what was filtered out and why. $dests is already normalized and deduplicated, so previewing
    // it would truthfully report zero invalid and zero duplicates — accurate, but useless to a user
    // asking why 10 pasted numbers became 7. The send path still uses $dests, untouched.
    $rawDests = preg_split('/[\s,;،]+/u', (string)($_POST['destinations'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (!empty($_POST['group'])) {
        $st = db()->prepare("SELECT mobile FROM ellsms_contacts WHERE {$contactOwnership} AND group_name=?");
        $st->execute([$myOrgId, $me['id'], $_POST['group']]);
        foreach ($st->fetchAll() as $c) { $rawDests[] = $c['mobile']; $n = normalize_msisdn($c['mobile']); if ($n) $dests[] = $n; }
    }
    if (!empty($_POST['category'])) {
        // Phase 6 closure: previously had NO ownership check — see public/send.php's identical fix
        // for the full explanation of the IDOR this closes.
        $st = db()->prepare(
            "SELECT i.mobile FROM ellsms_number_category_items i
             JOIN ellsms_number_categories c ON c.id = i.category_id
             WHERE i.category_id = ? AND (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL))"
        );
        $st->execute([(int)$_POST['category'], $myOrgId, $myOrgId]);
        foreach ($st->fetchAll() as $c) { $dests[] = $c['mobile']; $rawDests[] = $c['mobile']; }
    }
    $dests = array_values(array_unique($dests));

    $blockedCount = 0;
    if (!empty($_POST['use_blacklist'])) {
        [$dests, $blockedCount] = filter_blacklist((int)$me['id'], $dests, $originator);
    }

    // Cost preview (read-only) — evaluated before ANY of the branches below, so no campaign is
    // saved, no schedule is created, no bulk job is queued and no message is dispatched when the
    // user is only asking what it would cost. Blacklist filtering has already been applied above
    // when requested, so the estimator is handed the same recipient set the send would use; it is
    // therefore told not to re-apply it.
    if (($_POST['do'] ?? '') === 'preview') {
        // The estimator applies the blacklist itself exactly when the user asked for it, so its
        // reported blacklisted_count is genuine rather than reconstructed after the fact.
        $costPreview = estimate_message_cost($me, $originator, $rawDests, $content, !empty($_POST['use_blacklist']));
        cost_preview_record($costPreview, (int)($me['organization_id'] ?? 0), (int)$me['id'], 'web_new_send');
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
    } elseif (!empty($_POST['save_campaign']) && trim($_POST['campaign_name'] ?? '') !== '' && $content !== '') {
        // CAMPAIGNS_MANAGE, distinct from MESSAGES_SEND above (STEP 14) — a role that may dispatch
        // is not automatically allowed to persist a reusable template; skip the save (not the send)
        // rather than denying the whole request, since the user's primary intent here is sending.
        $campaignOrgId = (int)($me['organization_id'] ?? 0);
        if ($rbacOrg !== null && !membership_has_permission($rbacOrg, Permissions::CAMPAIGNS_MANAGE)) {
            flash('error', 'شما اجازه‌ی ذخیره‌ی کمپین را ندارید.');
        } elseif ($campaignOrgId > 0 && !organization_has_entitlement($campaignOrgId, Entitlements::CAMPAIGNS)) {
            // Phase 13 (STEP 14): same "skip the save, not the send" philosophy the RBAC branch
            // above already uses — the user's primary intent here is sending, so a plan that lacks
            // saved templates must not fail the whole request.
            flash('error', 'ذخیره‌ی قالب کمپین در پلن فعلی سازمان شما موجود نیست — پیام ارسال شد ولی قالب ذخیره نشد.');
        } else {
            $slot = entitlement_with_resource_slot($campaignOrgId, Limits::CAMPAIGNS, static function (PDO $db) use ($me, $originator, $content) {
                $db->prepare('INSERT INTO ellsms_campaigns (user_id, organization_id, name, originator, content) VALUES (?,?,?,?,?)')
                   ->execute([$me['id'], $me['organization_id'] ?? null, trim($_POST['campaign_name']), $originator, $content]);
                return true;
            });
            if (!$slot['ok']) {
                flash('error', 'به سقف تعداد قالب‌های کمپین پلن فعلی رسیده‌اید — پیام ارسال شد ولی قالب ذخیره نشد.');
            }
        }
    }

    $blockedNote = $blockedCount ? ' (' . to_persian_digits((string)$blockedCount) . ' شماره از لیست سیاه حذف شد)' : '';

    if (($_POST['do'] ?? '') === 'preview') {
        // Already handled above — a preview request must fall through the entire send chain below
        // without dispatching, queueing, or scheduling anything (Invariant B). Without this branch
        // execution would reach dispatch_message() and actually send.
    } elseif (!$dests) {
        flash('error', 'شماره مقصد معتبری وجود ندارد.' . $blockedNote);
    } elseif ($content === '') {
        flash('error', 'متن پیام خالی است.');
    } elseif (in_array($mode, ['direct', 'gradual'], true) && count($dests) > import_sync_max_recipients()) {
        // Large recipient list: write to a temporary CSV and use the async import pipeline
        // instead of building a giant PHP array / synchronous bulk job.
        $storageKey = 'imports/' . date('Ymd') . '/' . bin2hex(random_bytes(16)) . '.csv';
        $path = import_storage_path($storageKey);
        $destDir = dirname($path);
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            flash('error', 'امکان ساخت مسیر ذخیره‌سازی وجود ندارد.');
        } else {
            $fh = fopen($path, 'w');
            foreach ($dests as $d) {
                fputcsv($fh, [$d, $content]);
            }
            fclose($fh);

            // Unnamed: "ارسال دسته‌ای <date> - <n>" so same-day sends can be told apart.
            $title = $notes ?: bulk_default_send_title((int)$me['id'], $mode === 'gradual' ? 'ارسال تدریجی' : 'ارسال دسته‌ای');
            $throttleCount = $mode === 'gradual' ? max(1, (int)($_POST['throttle_count'] ?? 10)) : null;
            $throttleMinutes = $mode === 'gradual' ? max(1, (int)($_POST['throttle_minutes'] ?? 5)) : null;
            $created = import_create_job($me, $mode === 'gradual' ? 'gradual' : 'p2p', $originator, $title, $storageKey, $throttleCount, $throttleMinutes);
            if ($created['ok']) {
                audit((int)$me['id'], 'new_send.' . $mode . '.large', count($dests) . ' dest');
                redirect('/import.php?id=' . $created['job_id']);
            } else {
                import_delete_storage($storageKey);
                flash('error', $created['error']);
            }
        }
    } elseif ($mode === 'direct') {
        // #42 — the Bale channel is offered only while it is configured; 'sms' is the unchanged path.
        $channel = in_array($_POST['channel'] ?? 'sms', MESSAGE_CHANNELS, true) ? (string)$_POST['channel'] : 'sms';
        [$ok, $info] = dispatch_with_channel($me, $originator, $dests, $content, $channel);
        flash($ok ? 'success' : 'error', $info . $blockedNote);
        audit((int)$me['id'], 'new_send.direct', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    } elseif ($mode === 'recurring' && $rbacOrg !== null && !membership_has_permission($rbacOrg, Permissions::SCHEDULES_MANAGE)) {
        flash('error', 'شما اجازه‌ی زمان‌بندی ارسال را ندارید.');
    } elseif ($mode === 'recurring' && !organization_has_entitlement((int)($me['organization_id'] ?? 0), Entitlements::SCHEDULES)) {
        // Phase 13 (STEP 14): unlike the campaign-save branch above (a secondary side effect of a
        // send), scheduling IS the request's entire intent here — so a plan without it fails the
        // request outright rather than silently degrading to something the user didn't ask for.
        flash('error', 'ارسال زمان‌بندی‌شده در پلن فعلی سازمان شما موجود نیست. برای استفاده، پلن خود را ارتقا دهید.');
    } elseif ($mode === 'recurring') {
        $gDate = jalali_request_to_gregorian('send_date');
        $time  = time_post('send_time');
        $runAt = ($gDate && $time) ? "{$gDate} {$time}:00" : null;
        $repeat = in_array($_POST['repeat'] ?? 'none', ['none', 'daily', 'weekly', 'monthly'], true) ? $_POST['repeat'] : 'none';

        if (!$runAt || $runAt <= date('Y-m-d H:i:s')) {
            flash('error', 'زمان ارسال باید در آینده باشد.');
        } elseif (impersonation_guard_post('send.schedule')) {
            // A schedule is a send that happens later — blocked in support mode exactly like an
            // immediate one (docs/admin-impersonation.md).
        } else {
            $slot = entitlement_with_resource_slot((int)($me['organization_id'] ?? 0), Limits::ACTIVE_SCHEDULES, static function (PDO $db) use ($me, $notes, $originator, $dests, $content, $runAt, $repeat) {
                $db->prepare('INSERT INTO ellsms_schedule (user_id, organization_id, title, originator, destinations, content, run_at, repeat_type)
                              VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$me['id'], $me['organization_id'] ?? null, $notes, $originator, json_encode($dests), $content, $runAt, $repeat]);
                return true;
            });
            if (!$slot['ok']) {
                flash('error', 'به سقف تعداد زمان‌بندی‌های فعال پلن فعلی رسیده‌اید (' . to_persian_digits((string)$slot['limit']) . ' مورد). زمان‌بندی‌های موجود دست‌نخورده باقی می‌مانند.');
            } else {
                audit((int)$me['id'], 'new_send.recurring', count($dests) . ' dest @ ' . $runAt);
                flash('success', 'زمان‌بندی شد برای ' . jdate($runAt) . $blockedNote);
                redirect('/schedules.php');
            }
        }
    } elseif ($mode === 'gradual') {
        $throttleCount   = max(1, (int)($_POST['throttle_count'] ?? 10));
        $throttleMinutes = max(1, (int)($_POST['throttle_minutes'] ?? 5));
        $items = array_map(fn($d) => ['mobile' => $d, 'content' => $content], $dests);

        [$ok, $info, $jobId] = bulk_queue_job($me, 'gradual', $notes ?: 'ارسال تدریجی', $originator, null, $items, $throttleCount, $throttleMinutes);
        if ($ok) {
            audit((int)$me['id'], 'new_send.gradual', count($dests) . " dest, {$throttleCount}/{$throttleMinutes}min");
            flash('success', $info . " — {$throttleCount} پیام هر {$throttleMinutes} دقیقه." . $blockedNote);
            redirect('/messages/p2p');
        } else {
            flash('error', $info);
        }
    }
}

$extraStyles = ['/assets/css/sms-composer.css'];
require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'send.direct';
require __DIR__ . '/../app/views/impersonation_notice.php';

// Carries the original submission forward so confirming resubmits IDENTICAL inputs, which the
// confirm path re-parses and re-prices server-side (Invariant I — these are inputs to re-validate,
// never trusted values).
if (!empty($costPricingFailure)) {
    require __DIR__ . '/../app/views/cost_preview_unpriced.php';
}
if (isset($costPreview) && $costPreview) {
    $previewFormFields = '';
    foreach (['originator', 'destinations', 'content', 'notes', 'group', 'category', 'mode',
              'repeat', 'throttle_count', 'throttle_minutes', 'use_blacklist'] as $field) {
        if (isset($_POST[$field])) {
            $previewFormFields .= '<input type="hidden" name="' . $field . '" value="' . e((string)$_POST[$field]) . '">';
        }
    }
    foreach (['send_date_y','send_date_m','send_date_d','send_time_h','send_time_i'] as $field) {
        if (isset($_POST[$field])) {
            $previewFormFields .= '<input type="hidden" name="' . $field . '" value="' . e((string)$_POST[$field]) . '">';
        }
    }
    require __DIR__ . '/../app/views/cost_preview.php';
}
?>
<?php
require_once __DIR__ . '/../app/views/sms_composer.php';
$postedMode = in_array($_POST['mode'] ?? 'direct', ['direct', 'recurring', 'gradual'], true) ? ($_POST['mode'] ?? 'direct') : 'direct';
?>
<form method="post" id="newSendForm" data-composer data-composer-estimate="/send-estimate.php" data-composer-live-max="<?= live_cost_max_recipients() ?>">
<?= csrf_field() ?>
<input type="hidden" name="mode" id="modeField" value="direct">
<div class="composer-layout">
<div class="composer-main">

  <section class="card" aria-labelledby="stepMessage">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۱</span>
      <div><h2 id="stepMessage">متن پیام</h2><p class="hint">خط ارسال را انتخاب کنید و پیام را بنویسید؛ پیش‌نمایش و تعداد بخش‌ها همزمان به‌روز می‌شوند.</p></div>
    </div>

    <div class="form-row">
      <?php if ($campaigns): ?>
      <label>بارگذاری کمپین ذخیره‌شده
        <select id="campaignSelect">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= $c['id'] ?>" data-originator="<?= e($c['originator']) ?>" data-content="<?= e($c['content']) ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <label>ارسال‌کننده *
        <?php if ($myNumbers): ?>
          <select name="originator" id="originatorSelect">
            <?php foreach ($myNumbers as $n): $isService = isset($serviceLines[(string)$n['number']]); ?>
              <option value="<?= e($n['number']) ?>" data-service="<?= $isService ? '1' : '0' ?>"<?= (string)($_POST['originator'] ?? '') === (string)$n['number'] ? ' selected' : '' ?>><?= e($n['number']) ?><?= $isService ? ' — خدماتی' : '' ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif (($me['role'] ?? null) === 'admin'): ?>
          <input type="text" name="originator" id="originatorField" class="ltr" value="<?= e(user_default_send_originator($me)) ?>">
        <?php else: ?>
          <div class="flash flash-error"><?= e(NO_ASSIGNED_LINE_MESSAGE) ?></div>
        <?php endif; ?>
      </label>
    </div>
    <?php if ($serviceLines): ?>
      <p class="hint composer-line-hint" id="serviceLineHint" hidden>
        این خط خدماتی است: ارسال از آن با لیست سیاه بررسی نمی‌شود (لغو عضویت «۱۱» همچنان رعایت می‌شود).
      </p>
    <?php endif; ?>

    <label>متن پیام *
      <textarea name="content" id="contentField" required rows="6" dir="auto" placeholder="مثلاً: مشتری گرامی، سفارش شما ارسال شد."><?= e($_POST['content'] ?? '') ?></textarea>
    </label>
    <?= composer_meter_html() ?>

    <div class="composer-tools">
      <button type="button" class="btn btn-sm btn-ghost" id="emojiToggle" aria-expanded="false" aria-controls="emojiBox">🙂 شکلک</button>
      <label class="check"><input type="checkbox" id="digitConvert"> تبدیل ارقام فارسی به لاتین</label>
    </div>
    <div id="emojiBox" class="composer-emoji" hidden>
      <?php foreach (['😀','😁','😊','👍','🙏','🎉','❤️','✅','⏰','📌','🔥','⭐'] as $em): ?>
        <button type="button" class="btn btn-sm btn-ghost" data-emoji="<?= $em ?>" aria-label="درج <?= $em ?>"><?= $em ?></button>
      <?php endforeach; ?>
    </div>

    <label class="check composer-check">
      <input type="checkbox" name="save_campaign" value="1" id="saveCampaignChk">
      ذخیره‌ی این پیام به عنوان کمپین
    </label>
    <div id="campaignNameField" hidden>
      <label>نام کمپین <input type="text" name="campaign_name" placeholder="مثلاً یادآوری ماهانه"></label>
    </div>
  </section>

  <section class="card" aria-labelledby="stepRecipients">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۲</span>
      <div><h2 id="stepRecipients">گیرندگان</h2><p class="hint">شماره‌ها را وارد کنید؛ شماره‌های ۰۹… خودکار به ۹۸… تبدیل و تکراری‌ها یک‌بار شمرده می‌شوند.</p></div>
    </div>

    <div class="composer-tabs" role="group" aria-label="شیوه‌ی وارد کردن شماره‌ها">
      <button type="button" class="btn btn-sm" id="tabManual" data-tab="manual" aria-pressed="true">⌨ دستی</button>
      <button type="button" class="btn btn-sm" id="tabUpload" data-tab="upload" aria-pressed="false">⬆ آپلود فایل</button>
      <button type="button" class="btn btn-sm" id="tabClip" data-tab="clip" aria-pressed="false">📋 کلیپ‌بورد</button>
    </div>

    <div id="uploadAux" class="composer-aux" hidden>
      <input type="file" id="fileInput" accept=".txt,.csv">
      <div class="hint">فقط txt و csv — برای xlsx از «نظیر به نظیر» یا «پیامک هوشمند» استفاده کنید.</div>
    </div>
    <div id="clipAux" class="composer-aux" hidden>
      <button type="button" class="btn btn-sm" id="clipPaste">چسباندن از کلیپ‌بورد</button>
    </div>

    <?php if ($groups || $categories): ?>
    <div class="form-row">
      <?php if ($groups): ?>
      <label>گروه مخاطبین من
        <select name="group">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"<?= (string)($_POST['group'] ?? '') === (string)$g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <?php if ($categories): ?>
      <label>دسته‌ی عمومی شماره
        <select name="category">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"<?= (string)($_POST['category'] ?? '') === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= to_persian_digits((string)$c['c']) ?>)</option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <label>شماره‌ها (هر شماره در یک خط، یا جداشده با ویرگول / فاصله)
      <textarea name="destinations" id="destField" class="ltr" rows="6" placeholder="09121234567&#10;09351234567"><?= e($_POST['destinations'] ?? '') ?></textarea>
    </label>
    <?= composer_dest_stats_html() ?>

    <label class="check composer-check">
      <input type="checkbox" name="use_blacklist" value="1"<?= !empty($_POST['use_blacklist']) ? ' checked' : '' ?>>
      شماره‌های لیست سیاه حذف شوند
      <a href="/blacklist.php" class="composer-check-link">مدیریت لیست سیاه</a>
    </label>

    <div class="composer-quick">
      <label>ارسال سریع به یک شماره (اختیاری)
        <input type="text" id="quickRecipients" class="ltr" placeholder="0912…">
      </label>
      <button type="button" class="btn" id="quickSendButton">ارسال سریع</button>
    </div>
    <p class="hint composer-line-hint">«ارسال سریع» شماره را به فهرست اضافه می‌کند و بی‌درنگ، بدون پیش‌نمایش هزینه، می‌فرستد.</p>
  </section>

  <section class="card" aria-labelledby="stepMode">
    <div class="composer-step-head">
      <span class="composer-step-num" aria-hidden="true">۳</span>
      <div><h2 id="stepMode">زمان و شیوه‌ی ارسال</h2><p class="hint">هزینه در هر سه حالت یکسان است.</p></div>
    </div>

    <div class="composer-modes" role="radiogroup" aria-label="شیوه‌ی ارسال">
      <label class="composer-mode"><input type="radio" name="mode_ui" value="direct"<?= $postedMode === 'direct' ? ' checked' : '' ?>><strong>ارسال مستقیم</strong><span>بلافاصله پس از تأیید</span></label>
      <label class="composer-mode"><input type="radio" name="mode_ui" value="recurring"<?= $postedMode === 'recurring' ? ' checked' : '' ?>><strong>ارسال دوره‌ای</strong><span>در تاریخ و ساعت دلخواه، با تکرار</span></label>
      <label class="composer-mode"><input type="radio" name="mode_ui" value="gradual"<?= $postedMode === 'gradual' ? ' checked' : '' ?>><strong>ارسال تدریجی</strong><span>چند پیام در هر چند دقیقه</span></label>
    </div>

    <div id="directPanel" class="composer-mode-panel">
      <p class="hint">پیام بلافاصله پس از ثبت فرم ارسال می‌شود.</p>
      <?php if (bale_configured()): ?>
        <label class="composer-channel">کانال ارسال
          <select name="channel">
            <option value="sms">پیامک</option>
            <option value="bale">پیام‌رسان بله</option>
            <option value="bale_sms">بله، و پیامک برای کسانی که بله ندارند</option>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <div id="recurringPanel" class="composer-mode-panel" hidden>
      <div class="form-row">
        <label>تاریخ ارسال <?= jalali_date_select('send_date') ?></label>
        <label>ساعت ارسال <?= time_select('send_time') ?></label>
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
    <div id="gradualPanel" class="composer-mode-panel" hidden>
      <div class="form-row">
        <label>تعداد پیام <input type="number" name="throttle_count" value="10" min="1"></label>
        <label>در هر (دقیقه) <input type="number" name="throttle_minutes" value="5" min="1"></label>
      </div>
      <p class="hint">مثال بالا: هر ۵ دقیقه، ۱۰ پیام ارسال می‌شود تا تمام شود.</p>
    </div>

    <label>توضیحات <input type="text" name="notes" placeholder="یادداشت داخلی برای این ارسال"></label>
  </section>
</div>

<?= composer_side_html(
    '<button type="submit" name="do" value="preview" class="btn btn-primary btn-block">محاسبه‌ی هزینه و ادامه</button>'
  . '<button type="submit" name="do" value="confirm" class="btn btn-block">ارسال بدون پیش‌نمایش</button>'
  . '<p class="hint">«محاسبه‌ی هزینه» چیزی ارسال نمی‌کند و از اعتبار کسر نمی‌شود.</p>'
) ?>
</div>
</form>

<script src="<?= e(asset_url('/assets/js/sms-composer.js')) ?>"></script>
<script>
(function () {
  const refresh = () => window.smsComposerRefresh && window.smsComposerRefresh();
  const content = document.getElementById('contentField');
  const dest = document.getElementById('destField');
  const show = (id, on) => { document.getElementById(id).hidden = !on; };

  function insertAtCursor(text) {
    const start = content.selectionStart, end = content.selectionEnd;
    content.value = content.value.slice(0, start) + text + content.value.slice(end);
    content.selectionStart = content.selectionEnd = start + text.length;
    content.focus();
    refresh();
  }
  const emojiToggle = document.getElementById('emojiToggle');
  emojiToggle.addEventListener('click', () => {
    const box = document.getElementById('emojiBox');
    box.hidden = !box.hidden;
    emojiToggle.setAttribute('aria-expanded', String(!box.hidden));
  });
  document.querySelectorAll('[data-emoji]').forEach(b => b.addEventListener('click', () => insertAtCursor(b.dataset.emoji)));

  document.getElementById('saveCampaignChk').addEventListener('change', e => show('campaignNameField', e.target.checked));

  function appendDest(text) {
    dest.value = (dest.value ? dest.value + '\n' : '') + text;
    refresh();
  }
  document.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => {
    const tab = b.dataset.tab;
    show('uploadAux', tab === 'upload');
    show('clipAux', tab === 'clip');
    document.querySelectorAll('[data-tab]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
  }));
  document.getElementById('fileInput').addEventListener('change', ev => {
    const file = ev.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => appendDest(e.target.result);
    reader.readAsText(file, 'UTF-8');
  });
  document.getElementById('clipPaste').addEventListener('click', () => {
    if (!navigator.clipboard || !navigator.clipboard.readText) {
      alert('مرورگر شما از این قابلیت پشتیبانی نمی‌کند.');
      return;
    }
    navigator.clipboard.readText().then(appendDest).catch(() => alert('اجازه‌ی دسترسی به کلیپ‌بورد داده نشد.'));
  });

  function setMode(mode) {
    document.getElementById('modeField').value = mode;
    show('directPanel', mode === 'direct');
    show('recurringPanel', mode === 'recurring');
    show('gradualPanel', mode === 'gradual');
  }
  document.querySelectorAll('input[name=mode_ui]').forEach(r => r.addEventListener('change', () => setMode(r.value)));

  document.getElementById('quickSendButton').addEventListener('click', () => {
    const q = document.getElementById('quickRecipients').value.trim();
    if (!q) { alert('شماره گیرنده را وارد کنید.'); return; }
    dest.value = (dest.value ? dest.value + '\n' : '') + q;
    setMode('direct');
    document.querySelector('input[name=mode_ui][value=direct]').checked = true;
    document.getElementById('newSendForm').submit();
  });

<?php if ($serviceLines): ?>
  const sel = document.getElementById('originatorSelect');
  const hint = document.getElementById('serviceLineHint');
  if (sel && hint) {
    const sync = () => { hint.hidden = sel.selectedOptions[0]?.dataset.service !== '1'; };
    sel.addEventListener('change', sync);
    sync();
  }
<?php endif; ?>

<?php if ($campaigns): ?>
  document.getElementById('campaignSelect').addEventListener('change', function () {
    const opt = this.selectedOptions[0];
    if (!opt || !opt.value) return;
    const originatorField = document.querySelector('[name=originator]');
    if (originatorField) { originatorField.value = opt.dataset.originator; originatorField.dispatchEvent(new Event('change')); }
    content.value = opt.dataset.content;
    refresh();
  });
<?php endif; ?>

  setMode(<?= json_encode($postedMode) ?>);
})();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
