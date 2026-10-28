<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'شماره‌ها';
$active = 'numbers';
$detailsReady = number_details_ready();

// A stored commitment letter (تعهدنامه), served only to an admin and only from storage/.
if (isset($_GET['commitment'])) {
    $path = null;
    if ($detailsReady) {
        $st = db()->prepare('SELECT commitment_file FROM ellsms_numbers WHERE id = ?');
        $st->execute([(int)$_GET['commitment']]);
        $path = number_commitment_path(($f = $st->fetchColumn()) === false ? null : $f);
    }
    if ($path === null) {
        http_response_code(404);
        exit('فایلی برای این شماره ثبت نشده است.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeByExt = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="commitment-' . (int)$_GET['commitment'] . '.' . $ext . '"');
    header('Cache-Control: private, max-age=0, no-store');
    readfile($path);
    exit;
}

// The list's filters, kept on every link, pager and redirect back to the list.
$filterKeys = ['q', 'user', 'bypass', 'gateway', 'status', 'expiry', 'page'];
$listQuery = static function (array $src) use ($filterKeys): array {
    $out = [];
    foreach ($filterKeys as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $number = normalize_originator($_POST['number'] ?? '');
        $label  = trim($_POST['label'] ?? '');
        if (!$number) {
            flash('error', 'شماره معتبر نیست.');
        } else {
            try {
                db()->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$number, $label]);
                audit((int)$me['id'], 'number.create', $number);
                flash('success', 'شماره افزوده شد.');
            } catch (PDOException $e) {
                flash('error', 'این شماره قبلاً ثبت شده است.');
            }
        }
    }

    // «ویرایش شماره»: label, status, expiry, priority, bandwidth, confidential, inbound cost and the
    // commitment letter. Only status and expiry change sending (app/NumberDetails.php).
    if ($do === 'details') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $num = static fn(string $k, int $default): int => (int)from_persian_digits(trim((string)($_POST[$k] ?? (string)$default)));
        if (!$row) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } elseif (!$detailsReady) {
            db()->prepare('UPDATE ellsms_numbers SET label = ? WHERE id = ?')->execute([trim((string)($_POST['label'] ?? '')), $id]);
            audit((int)$me['id'], 'number.label', (string)$row['number']);
            flash('success', 'برچسب ذخیره شد. برای بقیه‌ی فیلدها ابتدا مایگریشن 2026_10_28_number_details.sql را اجرا کنید.');
        } else {
            $status       = (string)($_POST['status'] ?? 'active');
            $expiresOn    = !empty($_POST['no_expiry']) ? null : jalali_request_to_gregorian('expires');
            $priority     = $num('priority', 5);
            $bandwidth    = $num('bandwidth', 1);
            $bandwidthMax = $num('bandwidth_max', 20);
            $error = null;
            if (!isset(NUMBER_STATUSES[$status]))                 $error = 'وضعیت انتخاب‌شده معتبر نیست.';
            elseif ($priority < 1 || $priority > 10)              $error = 'اولویت پیام باید عددی بین ۱ تا ۱۰ باشد.';
            elseif ($bandwidthMax < 1 || $bandwidthMax > 100000)  $error = 'حداکثر پهنای باند باید عددی بین ۱ و ۱۰۰٬۰۰۰ باشد.';
            elseif ($bandwidth < 1 || $bandwidth > $bandwidthMax) $error = 'پهنای باند باید بین ۱ و حداکثر پهنای باند قابل اختصاص باشد.';
            $file = null;
            if ($error === null) {
                try {
                    $file = number_commitment_store_upload('commitment', $id);
                } catch (AppException $e) {
                    $error = $e->getMessage();
                }
            }
            if ($error !== null) {
                flash('error', $error);
            } else {
                db()->prepare(
                    'UPDATE ellsms_numbers SET label = ?, status = ?, expires_on = ?, priority = ?, bandwidth = ?, bandwidth_max = ?,
                            confidential = ?, pay_inbound_cost = ?, commitment_file = COALESCE(?, commitment_file) WHERE id = ?'
                )->execute([
                    trim((string)($_POST['label'] ?? '')), $status, $expiresOn, $priority, $bandwidth, $bandwidthMax,
                    !empty($_POST['confidential']) ? 1 : 0, !empty($_POST['pay_inbound_cost']) ? 1 : 0, $file, $id,
                ]);
                if ($file !== null && ($old = number_commitment_path($row['commitment_file'] ?? null)) !== null) @unlink($old);
                $changes = [];
                if ($row['status'] !== $status) $changes[] = "status {$row['status']} -> {$status}";
                if (($row['expires_on'] ?? null) !== $expiresOn) $changes[] = 'expires ' . ($row['expires_on'] ?? '—') . ' -> ' . ($expiresOn ?? '—');
                if ($file !== null) $changes[] = 'commitment uploaded';
                audit((int)$me['id'], 'number.details', $row['number'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
                $reason = number_unsendable_reason_for($status, $expiresOn);
                $reason === null
                    ? flash('success', 'مشخصات شماره ذخیره شد.')
                    : flash('info', "مشخصات شماره ذخیره شد. ارسال از این خط ممکن نیست: {$reason}.");
            }
        }
    }

    if ($do === 'commitment_remove' && $detailsReady) {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT number, commitment_file FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            db()->prepare('UPDATE ellsms_numbers SET commitment_file = NULL WHERE id = ?')->execute([$id]);
            if (($old = number_commitment_path($row['commitment_file'])) !== null) @unlink($old);
            audit((int)$me['id'], 'number.commitment_remove', (string)$row['number']);
            flash('info', 'فایل تعهدنامه حذف شد.');
        }
    }

    if ($do === 'assign') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        db()->prepare('UPDATE ellsms_numbers SET assigned_user_id = ? WHERE id = ?')
           ->execute([$userId ?: null, $id]);
        audit((int)$me['id'], 'number.assign', "#{$id} -> {$userId}");
        flash('success', $userId ? 'شماره تخصیص داده شد.' : 'تخصیص شماره لغو شد.');
    }

    if ($do === 'gateway') {
        // Which gateway this number sends through. Empty = as before (its route's gateway, else the
        // default). Only an active gateway with sending switched on can be chosen; the price does not change.
        $id = (int)($_POST['id'] ?? 0);
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $ok = true;
        if ($gatewayId > 0) {
            $st = db()->prepare("SELECT code FROM ellsms_sms_gateways WHERE id = ? AND status = 'active' AND send_enabled = 1");
            $st->execute([$gatewayId]);
            $ok = $st->fetchColumn() !== false;
        }
        if (!$ok) {
            flash('error', 'این درگاه فعال نیست یا ارسال آن خاموش است.');
        } else {
            db()->prepare('UPDATE ellsms_numbers SET gateway_id = ? WHERE id = ?')->execute([$gatewayId ?: null, $id]);
            gateway_cache_reset();
            audit((int)$me['id'], 'number.gateway', "#{$id} -> " . ($gatewayId ?: 'route'));
            flash('success', $gatewayId ? 'درگاه ارسال این شماره تنظیم شد.' : 'درگاه این شماره به حالت پیش‌فرض (مسیر) برگشت.');
        }
    }

    // LBA (docs/lba.md): the LBA gateway this number's location-based sends go through, apart from its
    // ordinary gateway above. Empty = the number cannot send LBA.
    if ($do === 'lba_gateway') {
        $lbaGatewayId = (int)($_POST['lba_gateway_id'] ?? 0);
        $r = lba_number_set_gateway((int)($_POST['id'] ?? 0), $lbaGatewayId > 0 ? $lbaGatewayId : null, (int)$me['id']);
        $r['ok'] ? flash('success', $lbaGatewayId > 0 ? 'درگاه LBA این شماره تنظیم شد.' : 'ارسال LBA این شماره برداشته شد.') : flash('error', $r['error']);
    }

    // Service line (docs/service-lines.md): sends from it skip the do-not-contact list, like Vesal's
    // byPassBlackList. Set explicitly to the posted value rather than flipped, so a double-submitted
    // form cannot undo itself. Line opt-outs ("11") still apply.
    if ($do === 'bypass_blacklist') {
        $id = (int)($_POST['id'] ?? 0);
        $on = ($_POST['value'] ?? '') === '1';
        $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        $number = $st->fetchColumn();
        if ($number === false) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } else {
            db()->prepare('UPDATE ellsms_numbers SET bypass_blacklist = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
            audit((int)$me['id'], $on ? 'number.bypass_blacklist_on' : 'number.bypass_blacklist_off', (string)$number);
            flash('success', $on
                ? "شماره {$number} خدماتی شد: ارسال از آن دیگر با لیست سیاه بررسی نمی‌شود."
                : "شماره {$number} از حالت خدماتی خارج شد: ارسال از آن دوباره با لیست سیاه بررسی می‌شود.");
        }
    }

    // Shared lines (docs/shared-numbers.md). A share is additive and send-only: it never moves the
    // number's own assignment, and never widens the inbox/auto-reply/opt-out scope, which stay with
    // assigned_user_id / organization_id. Granting the same line twice is a no-op (PRIMARY KEY).
    if ($do === 'share_add') {
        $id     = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        // The number is re-checked rather than trusted from the form: INSERT IGNORE downgrades a
        // foreign-key failure to a warning, so a crafted id would otherwise insert nothing while
        // still reporting success. resolve_ellsms_managed_user() is the same gate users.php uses —
        // a real backend account that actually has ELLSMS panel access, never an arbitrary id.
        $numberExists = false;
        if ($id > 0) {
            $st = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE id = ?');
            $st->execute([$id]);
            $numberExists = $st->fetchColumn() !== false;
        }
        if (!$numberExists) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } elseif ($userId <= 0 || !resolve_ellsms_managed_user($userId)) {
            flash('error', 'کاربر معتبری انتخاب نشده است.');
        } else {
            db()->prepare('INSERT IGNORE INTO ellsms_number_shares (number_id, user_id, created_by) VALUES (?,?,?)')
               ->execute([$id, $userId, (int)$me['id']]);
            audit((int)$me['id'], 'number.share_add', "#{$id} -> {$userId}");
            flash('success', 'شماره برای این کاربر هم فعال شد.');
        }
    }

    if ($do === 'share_remove') {
        $id     = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_number_shares WHERE number_id = ? AND user_id = ?')
           ->execute([$id, $userId]);
        audit((int)$me['id'], 'number.share_remove', "#{$id} -> {$userId}");
        flash('info', 'اشتراک این شماره برای آن کاربر برداشته شد.');
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $file = null;
        if ($detailsReady) {
            $st = db()->prepare('SELECT commitment_file FROM ellsms_numbers WHERE id = ?');
            $st->execute([$id]);
            $file = $st->fetchColumn() ?: null;
        }
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$id]);
        if (($old = number_commitment_path($file)) !== null) @unlink($old);
        audit((int)$me['id'], 'number.delete', "#{$id}");
        flash('info', 'شماره حذف شد.');
        unset($_POST['return_id']);
    }

    // Back to the edit page the form was on, else to the list with the filters the admin was using.
    $returnId = (int)($_POST['return_id'] ?? 0);
    if ($returnId > 0) redirect('/numbers.php?id=' . $returnId);
    parse_str((string)($_POST['back'] ?? ''), $back);
    $back = $listQuery(is_array($back) ? $back : []);
    redirect('/numbers.php' . ($back ? '?' . http_build_query($back) : ''));
}

$panelUsers = backend_panel_access_users();
$panelUsersById = array_column($panelUsers, null, 'id');

// Every active gateway: selectable ones can send; a number still pinned to one that can no longer send is
// shown with a warning, because its messages are held (retried) rather than sent another way.
$gateways = db()->query("SELECT id, code, name, send_enabled, is_mock, default_slot FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY name")->fetchAll();
$gatewaysById = array_column($gateways, null, 'id');

$today = number_today();
$soonDays = 30;
$soonLimit = (new DateTimeImmutable($today))->modify("+{$soonDays} days")->format('Y-m-d');

/** The status a row is shown (and coloured) with: an active line past its expiry reads as «منقضی». */
$rowState = static function (array $n) use ($detailsReady): string {
    if (!$detailsReady) return 'active';
    $status = (string)($n['status'] ?? 'active');
    return $status === 'active' && number_is_expired($n['expires_on'] ?? null) ? 'expired' : $status;
};
$stateLabel = static fn(string $state): string => $state === 'expired' ? 'منقضی' : number_status_label($state);

$expiryCell = static function (?string $expiresOn) use ($today, $soonLimit): string {
    if ($expiresOn === null || $expiresOn === '') return '<span class="muted">بدون انقضا</span>';
    $date = e(jdate($expiresOn, false));
    if ($expiresOn < $today) return '<span class="num-expired">' . $date . ' (منقضی)</span>';
    if ($expiresOn <= $soonLimit) {
        $days = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($expiresOn))->days;
        return '<span class="num-soon">' . $date . ' (' . ($days === 0 ? 'امروز' : to_persian_digits((string)$days) . ' روز مانده') . ')</span>';
    }
    return $date;
};

/* =============================== «ویرایش شماره» =============================== */
$editId = (int)($_GET['id'] ?? 0);
if ($editId > 0) {
    $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
    $st->execute([$editId]);
    $n = $st->fetch();
    if (!$n) {
        flash('error', 'این شماره پیدا نشد.');
        redirect('/numbers.php');
    }
    $pageTitle = 'ویرایش شماره ' . $n['number'];
    $ownerName = $n['assigned_user_id'] !== null
        ? (backend_usernames_by_ids([(int)$n['assigned_user_id']])[(int)$n['assigned_user_id']] ?? ('#' . (int)$n['assigned_user_id']))
        : null;
    $st = db()->prepare('SELECT user_id FROM ellsms_number_shares WHERE number_id = ?');
    $st->execute([$editId]);
    $shareIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $shareNames = backend_usernames_by_ids($shareIds);
    $shares = array_map(static fn(int $uid): array => ['user_id' => $uid, 'username' => $shareNames[$uid] ?? ('#' . $uid)], $shareIds);
    $lbaGateways = lba_gateways_list();
    $lbaGatewaysById = array_column($lbaGateways, null, 'id');
    $fw = null;
    if (number_forward_available()) {
        $st = db()->prepare('SELECT mo_enabled, mo_url, dlr_enabled, dlr_url, consecutive_failures FROM ellsms_number_forwards WHERE number = ?');
        $st->execute([(string)$n['number']]);
        $fw = $st->fetch() ?: null;
    }
    $state = $rowState($n);
    $reason = $detailsReady ? number_unsendable_reason_for((string)$n['status'], $n['expires_on']) : null;
    $isSystemLine = system_default_originator() === (string)$n['number'];
    $hidden = static fn() => csrf_field() . '<input type="hidden" name="id" value="' . (int)$n['id'] . '"><input type="hidden" name="return_id" value="' . (int)$n['id'] . '">';
    $bypass = (int)($n['bypass_blacklist'] ?? 0) === 1;
    $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0;
    $pinnedRow = $gatewaysById[$pinned] ?? null;
    $lbaPinned = (int)($n['lba_gateway_id'] ?? 0);

    require __DIR__ . '/../app/views/header.php';
    ?>
<div class="card">
  <div class="num-head">
    <h2>ویرایش شماره <span class="ltr"><?= e($n['number']) ?></span></h2>
    <a class="btn btn-sm" href="/numbers.php">→ بازگشت به فهرست شماره‌ها</a>
  </div>
  <?php if (!$detailsReady): ?>
    <p class="flash flash-info">برای وضعیت، تاریخ انقضا، اولویت، پهنای باند و تعهدنامه ابتدا مایگریشن <code>db/migrations/2026_10_28_number_details.sql</code> را اجرا کنید (<code>make db-migrations-apply</code>). تا آن زمان فقط برچسب ذخیره می‌شود.</p>
  <?php elseif ($reason !== null): ?>
    <p class="flash flash-error">ارسال از این خط اکنون ممکن نیست: <?= e($reason) ?>. پیامک‌های دریافتی و گزارش تحویل این خط همچنان دریافت می‌شوند.<?= $isSystemLine ? ' (این خط، خط سیستمی پنل است و پیام‌های خودِ سامانه مثل کد ورود همچنان از آن ارسال می‌شوند.)' : '' ?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= $hidden() ?>
    <input type="hidden" name="do" value="details">
    <table class="num-kv">
      <tr>
        <th>شماره</th><td class="ltr"><b><?= e($n['number']) ?></b></td>
        <th>تاریخ ثبت</th><td><?= e(jdate_from_utc($n['created_at'] ?? null)) ?></td>
      </tr>
      <tr>
        <th>برچسب</th><td><input type="text" name="label" value="<?= e($n['label']) ?>" placeholder="مثلاً خط پشتیبانی"></td>
        <th>مالک (تخصیص به کاربر)</th><td><?= $ownerName !== null ? e($ownerName) : '<span class="muted">تخصیص‌نیافته</span>' ?></td>
      </tr>
      <?php if ($detailsReady): ?>
      <tr>
        <th>وضعیت</th>
        <td>
          <select name="status">
            <?php foreach (NUMBER_STATUSES as $k => $label): ?>
              <option value="<?= $k ?>" <?= $n['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">فقط خط «فعال» می‌تواند پیامک ارسال کند.</div>
        </td>
        <th>تاریخ انقضا</th>
        <td>
          <?php // Picking a date unticks «بدون تاریخ انقضا», so the date is not silently discarded on save. ?>
          <div onchange="if (event.target.tagName === 'SELECT') this.querySelector('[name=no_expiry]').checked = false">
            <?= jalali_date_select('expires', $n['expires_on'] ?? null, 10, 1, true) ?>
            <label style="margin-top:6px"><input type="checkbox" name="no_expiry" value="1" <?= empty($n['expires_on']) ? 'checked' : '' ?>> بدون تاریخ انقضا</label>
          </div>
          <div class="hint">وضعیت فعلی: <?= $expiryCell($n['expires_on'] ?? null) ?> — خط تا پایان همان روز ارسال می‌کند.</div>
        </td>
      </tr>
      <tr>
        <th>اولویت پیام</th><td><input type="number" name="priority" min="1" max="10" value="<?= (int)$n['priority'] ?>" class="ltr" style="max-width:120px"></td>
        <th>ارسال محرمانه</th>
        <td><select name="confidential"><option value="0">خیر</option><option value="1" <?= (int)$n['confidential'] ? 'selected' : '' ?>>بلی</option></select></td>
      </tr>
      <tr>
        <th>پهنای باند</th><td><input type="number" name="bandwidth" min="1" value="<?= (int)$n['bandwidth'] ?>" class="ltr" style="max-width:160px"></td>
        <th>حداکثر پهنای باند قابل اختصاص</th><td><input type="number" name="bandwidth_max" min="1" value="<?= (int)$n['bandwidth_max'] ?>" class="ltr" style="max-width:160px"></td>
      </tr>
      <tr>
        <th>پرداخت هزینه پیامک دریافتی</th>
        <td><select name="pay_inbound_cost"><option value="0">خیر</option><option value="1" <?= (int)$n['pay_inbound_cost'] ? 'selected' : '' ?>>بلی</option></select></td>
        <th>بارگذاری تعهدنامه شماره</th>
        <td>
          <input type="file" name="commitment" accept=".jpg,.jpeg,.png,.webp,.pdf">
          <div class="hint">JPG، PNG، WEBP یا PDF، حداکثر ۸ مگابایت. فایل جدید جایگزین فایل قبلی می‌شود.</div>
          <?php if (number_commitment_path($n['commitment_file'] ?? null) !== null): ?>
            <a href="/numbers.php?commitment=<?= (int)$n['id'] ?>">دریافت فایل تعهدنامه</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
    </table>
    <div style="margin-top:16px"><button class="btn btn-primary">ذخیره</button></div>
  </form>
  <?php if ($detailsReady && number_commitment_path($n['commitment_file'] ?? null) !== null): ?>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('فایل تعهدنامه حذف شود؟')">
      <?= $hidden() ?><input type="hidden" name="do" value="commitment_remove">
      <button class="btn btn-sm btn-danger">حذف فایل تعهدنامه</button>
    </form>
  <?php endif; ?>
  <?php if ($detailsReady): ?>
  <p class="hint">
    <b>وضعیت و تاریخ انقضا:</b> از خطی که «فعال» نیست یا تاریخ انقضایش گذشته، در هیچ مسیری (ارسال تکی و گروهی، زمان‌بندی، API، منشی پیامک و …)
    نمی‌توان ارسال کرد و این خط از فهرست «ارسال‌کننده»‌ی کاربران هم برداشته می‌شود؛ ارسال‌های در صف نیز رد می‌شوند. پیامک ورودی، صندوق دریافت،
    گزارش تحویل و ارسال به URL تغییری نمی‌کنند. تغییر حداکثر ظرف ۳۰ ثانیه روی workerها اعمال می‌شود.
    اولویت، پهنای باند، ارسال محرمانه و هزینه‌ی پیامک دریافتی فعلاً فقط ثبت و نمایش داده می‌شوند.
  </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>تخصیص، ارسال و درگاه‌ها</h2>
  <table class="num-kv">
    <tr>
      <th>تخصیص به کاربر</th>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="assign">
          <select name="user_id" onchange="this.form.submit()">
            <option value="">— تخصیص‌نیافته —</option>
            <?php foreach ($panelUsers as $u): ?>
              <option value="<?= $u['id'] ?>" <?= (int)$n['assigned_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <div class="hint">وقتی کاربری حداقل یک شماره تخصیص‌یافته داشته باشد، در ارسال پیامک و منشی پیامک به‌جای نوشتن آزاد، از میان شماره‌های خودش انتخاب می‌کند.</div>
      </td>
    </tr>
    <tr>
      <th>کاربران اشتراکی (فقط ارسال)</th>
      <td>
        <?php foreach ($shares as $s): ?>
          <form method="post" class="toolbar" style="margin:0 0 4px">
            <?= $hidden() ?><input type="hidden" name="do" value="share_remove">
            <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
            <span><?= e($s['username']) ?></span>
            <button class="btn btn-sm btn-danger" title="برداشتن اشتراک">×</button>
          </form>
        <?php endforeach; ?>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="share_add">
          <select name="user_id" onchange="this.form.submit()">
            <option value="">— افزودن کاربر —</option>
            <?php
            $alreadyShared = array_column($shares, 'user_id');
            foreach ($panelUsers as $u):
                // The outright owner is already covered by the assignment row; offering them
                // here would create a share row that changes nothing.
                if ((int)$u['id'] === (int)$n['assigned_user_id']) continue;
                if (in_array((int)$u['id'], $alreadyShared, true)) continue;
            ?>
              <option value="<?= $u['id'] ?>"><?= e($u['username']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">افزودن</button></noscript>
        </form>
        <p class="hint">
          یک شماره را می‌توان علاوه بر مالکش، به چند کاربر دیگر هم داد. آن کاربران این خط را در فهرست
          «ارسال‌کننده» صفحات ارسال، نظیر‌به‌نظیر، پیامک هوشمند و ارسال منطقه‌ای می‌بینند و می‌توانند از آن بفرستند — هزینه‌ی هر
          ارسال هم از اعتبار خودِ همان کاربر کم می‌شود.
          اشتراک <b>فقط اجازه‌ی ارسال</b> می‌دهد: صندوق دریافت، منشی پیامک و فهرست لغو عضویتِ این خط همچنان فقط برای مالک
          (یا سازمان مالک) دیده می‌شود. برای اینکه کاربری پیام‌های ورودی یک خط را هم ببیند، باید خط به خودش یا به سازمانش
          تخصیص داده شود، نه اشتراکی.
        </p>
      </td>
    </tr>
    <tr>
      <th>خدماتی (عبور از لیست سیاه)</th>
      <td>
        <form method="post" class="toolbar" style="margin:0"
              onsubmit="return confirm(<?= e(json_encode($bypass
                  ? 'ارسال از این شماره دوباره با لیست سیاه بررسی شود؟'
                  : 'این شماره خدماتی شود؟ ارسال از آن دیگر با لیست سیاه مشتری بررسی نمی‌شود (لغو عضویت «۱۱» همچنان اعمال می‌شود).', JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $hidden() ?><input type="hidden" name="do" value="bypass_blacklist">
          <input type="hidden" name="value" value="<?= $bypass ? '0' : '1' ?>">
          <span class="badge <?= $bypass ? 'badge-ok' : 'badge-unknown' ?>"><?= $bypass ? 'فعال' : 'غیرفعال' ?></span>
          <button class="btn btn-sm"><?= $bypass ? 'غیرفعال کن' : 'فعال کن' ?></button>
        </form>
        <p class="hint">
          برای خطوط خدماتی مثل اطلاع‌رسانی تراکنش، قبض یا نوبت‌دهی — همان «bypassBlackList» در وصال.
          وقتی فعال باشد، ارسال از این شماره در هیچ مسیری (ارسال تکی و گروهی، زمان‌بندی، نظیر‌به‌نظیر، پیامک هوشمند، ارسال با کد پستی و پیش‌نمایش هزینه)
          با لیست سیاه مشتری بررسی نمی‌شود، حتی اگر گزینه‌ی «فقط ارسال به لیست سفید» زده شده باشد.
          لغو عضویتِ گیرنده از همین خط («۱۱») همچنان رعایت می‌شود. پیش‌فرض برای هر شماره‌ی جدید غیرفعال است و هر تغییر در گزارش رویدادها ثبت می‌شود.
          کارهای گروهی‌ای که پیش از تغییر، بررسی لیست سیاه‌شان تمام شده، با همان نتیجه ارسال می‌شوند.
        </p>
      </td>
    </tr>
    <tr>
      <th>درگاه ارسال</th>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="gateway">
          <select name="gateway_id" onchange="this.form.submit()">
            <option value="">— پیش‌فرض (طبق مسیر) —</option>
            <?php foreach ($gateways as $g): if (!(int)$g['send_enabled'] && (int)$g['id'] !== $pinned) continue; ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $pinned ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)<?= (int)$g['is_mock'] ? ' — آزمایشی' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <?php if ($pinned > 0 && ($pinnedRow === null || !(int)$pinnedRow['send_enabled'])): ?>
          <div class="muted" style="color:var(--danger,#b00)">⚠ این درگاه اکنون قادر به ارسال نیست؛ پیام‌های این شماره تا رفع مشکل ارسال نمی‌شوند.</div>
        <?php endif; ?>
        <p class="hint">
          هر پیامی که از این شماره ارسال شود فقط از درگاه انتخاب‌شده می‌رود (پیامک ورودی تغییری نمی‌کند).
          قیمت پیام همان قیمت مسیر فعلی است و تغییر نمی‌کند. اگر درگاه انتخاب‌شده در دسترس نباشد، پیام از درگاه دیگری فرستاده نمی‌شود:
          ارسال گروهی بعداً دوباره تلاش می‌کند و ارسال مستقیم خطا می‌دهد. تغییر حداکثر ظرف چند ثانیه روی workerها اعمال می‌شود.
          <?php if (!gateway_transport_enabled()): ?><br><b style="color:var(--danger,#b00)">توجه: ارسال از طریق درگاه‌ها (SMS_GATEWAY_TRANSPORT) خاموش است؛ این انتخاب تا روشن شدن آن اثری ندارد.</b><?php endif; ?>
        </p>
      </td>
    </tr>
    <tr>
      <th>درگاه LBA</th>
      <td>
        <?php if (lba_tables_ready()): ?>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="lba_gateway">
          <select name="lba_gateway_id" onchange="this.form.submit()">
            <option value="">— بدون LBA —</option>
            <?php foreach ($lbaGateways as $lg): if ($lg['status'] !== 'active' && (int)$lg['id'] !== $lbaPinned) continue; ?>
              <option value="<?= (int)$lg['id'] ?>" <?= (int)$lg['id'] === $lbaPinned ? 'selected' : '' ?>><?= e($lg['name']) ?> (<?= e($lg['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <?php if ($lbaPinned > 0 && ($lbaReason = lba_gateway_unusable_reason($lbaGatewaysById[$lbaPinned] ?? null)) !== null): ?>
          <div class="muted" style="color:var(--danger,#b00)">⚠ <?= e($lbaReason) ?></div>
        <?php endif; ?>
        <?php else: ?>—<?php endif; ?>
        <p class="hint">
          ارسال پیام منطقه‌ای (LBA) این شماره فقط از درگاه LBA انتخاب‌شده می‌رود و ارسال عادی آن تغییری نمی‌کند.
          شماره‌ی بدون درگاه LBA در صفحه‌ی ارسال منطقه‌ای دیده نمی‌شود. درگاه‌ها، توکن و تعرفه در <a href="/lba-gateways.php">درگاه‌های LBA</a>.
        </p>
      </td>
    </tr>
    <tr>
      <th>آدرس تحویل پیام‌های دریافتی مشتری</th>
      <td>
        <?php if ($fw && $fw['mo_url'] !== ''): ?>
          <span class="badge <?= (int)$fw['mo_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= (int)$fw['mo_enabled'] ? 'روشن' : 'خاموش' ?></span>
          <code class="ltr"><?= e($fw['mo_url']) ?></code>
        <?php else: ?><span class="muted">تنظیم نشده</span><?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>آدرس تحویل وضعیت پیام‌های ارسالی</th>
      <td>
        <?php if ($fw && $fw['dlr_url'] !== ''): ?>
          <span class="badge <?= (int)$fw['dlr_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= (int)$fw['dlr_enabled'] ? 'روشن' : 'خاموش' ?></span>
          <code class="ltr"><?= e($fw['dlr_url']) ?></code>
        <?php else: ?><span class="muted">تنظیم نشده</span><?php endif; ?>
        <?php if ($fw && (int)$fw['consecutive_failures'] >= NUMBER_FORWARD_WARN_FAILURES): ?><div style="color:var(--danger,#b00)">⚠ ارسال به این آدرس‌ها پشت سر هم خطا داده است.</div><?php endif; ?>
        <div style="margin-top:6px"><a class="btn btn-sm" href="/number-forwards.php?edit=<?= e(rawurlencode((string)$n['number'])) ?>#lines">تنظیم ارسال به URL</a></div>
        <p class="hint">
          پیامک‌های دریافتی روی این خط و گزارش تحویل پیامک‌های ارسالی از آن را به آدرس http/https مشتری
          می‌فرستد (مثل receiveUrl / deliveryUrl در وصال). تنظیمات، گزارش و خاموش/روشن کردن سرویس در
          <a href="/number-forwards.php">فوروارد پیامک به URL</a>.
        </p>
      </td>
    </tr>
    <tr>
      <th>حذف کامل شماره</th>
      <td>
        <form method="post" onsubmit="return confirm('این شماره برای همیشه حذف شود؟ برای توقف موقت، وضعیت را «غیرفعال» یا «مسدود» کنید.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <button class="btn btn-sm btn-danger">حذف شماره</button>
        </form>
        <div class="hint">شماره و تخصیص‌ها و اشتراک‌هایش پاک می‌شوند. برای نگه داشتن سابقه، به‌جای آن وضعیت را «حذف» یا «غیرفعال» کنید.</div>
      </td>
    </tr>
  </table>
</div>
<?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* =============================== فهرست شماره‌ها =============================== */
$f = $listQuery($_GET);
unset($f['page']);
$where = [];
$params = [];
if (($f['q'] ?? '') !== '') {
    $q = from_persian_digits($f['q']);
    $where[] = '(n.number LIKE ? OR n.label LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like);
}
if (($f['user'] ?? '') === 'none') {
    $where[] = 'n.assigned_user_id IS NULL';
} elseif ((int)($f['user'] ?? 0) > 0) {
    $where[] = 'n.assigned_user_id = ?';
    $params[] = (int)$f['user'];
}
if (in_array($f['bypass'] ?? '', ['1', '0'], true)) {
    $where[] = 'n.bypass_blacklist = ?';
    $params[] = (int)$f['bypass'];
}
if (($f['gateway'] ?? '') === 'route') {
    $where[] = 'n.gateway_id IS NULL';
} elseif ((int)($f['gateway'] ?? 0) > 0) {
    $where[] = 'n.gateway_id = ?';
    $params[] = (int)$f['gateway'];
}
if ($detailsReady) {
    $statusFilter = (string)($f['status'] ?? '');
    if ($statusFilter === 'expired') {
        $where[] = "n.status = 'active' AND n.expires_on < ?";
        $params[] = $today;
    } elseif (isset(NUMBER_STATUSES[$statusFilter])) {
        $where[] = 'n.status = ?';
        $params[] = $statusFilter;
        if ($statusFilter === 'active') { $where[] = '(n.expires_on IS NULL OR n.expires_on >= ?)'; $params[] = $today; }
    }
    switch ($f['expiry'] ?? '') {
        case 'expired': $where[] = 'n.expires_on < ?'; $params[] = $today; break;
        case 'soon':    $where[] = 'n.expires_on BETWEEN ? AND ?'; array_push($params, $today, $soonLimit); break;
        case 'valid':   $where[] = 'n.expires_on >= ?'; $params[] = $today; break;
        case 'none':    $where[] = 'n.expires_on IS NULL'; break;
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*) FROM ellsms_numbers n {$whereSql}");
$st->execute($params);
$total = (int)$st->fetchColumn();
$perPage = 25;
[$page, $offset] = pager_offset($total, $perPage);
$st = db()->prepare("SELECT n.* FROM ellsms_numbers n {$whereSql} ORDER BY (n.assigned_user_id IS NULL) DESC, n.number LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$numbers = $st->fetchAll();
$allCount = (int)db()->query('SELECT COUNT(*) FROM ellsms_numbers')->fetchColumn();

$usernames = backend_usernames_by_ids(array_column($numbers, 'assigned_user_id'));
$shareCounts = [];
if ($numbers) {
    $ids = array_map('intval', array_column($numbers, 'id'));
    $st = db()->prepare('SELECT number_id, COUNT(*) FROM ellsms_number_shares WHERE number_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY number_id');
    $st->execute($ids);
    $shareCounts = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}
$statusCounts = [];
if ($detailsReady) {
    $st = db()->prepare("SELECT IF(status = 'active' AND expires_on < ?, 'expired', status) AS s, COUNT(*) FROM ellsms_numbers GROUP BY s");
    $st->execute([$today]);
    $statusCounts = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}
$backQuery = http_build_query($f + ($page > 1 ? ['page' => $page] : []));

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن شماره</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <input type="hidden" name="back" value="<?= e($backQuery) ?>">
    <label>شماره <input type="text" name="number" required class="ltr" placeholder="5000435800"></label>
    <label>برچسب (اختیاری) <input type="text" name="label" placeholder="مثلاً خط پشتیبانی"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
  <p class="hint">بعد از افزودن، روی شماره کلیک کنید تا وضعیت، تاریخ انقضا، تخصیص، درگاه و بقیه‌ی تنظیمات آن را در صفحه‌ی «ویرایش شماره» تعیین کنید.</p>
</div>

<div class="card">
  <h2>فهرست شماره‌ها</h2>
  <?php if (!$detailsReady): ?>
    <p class="flash flash-info">برای وضعیت و تاریخ انقضای شماره‌ها، مایگریشن <code>db/migrations/2026_10_28_number_details.sql</code> را اجرا کنید (<code>make db-migrations-apply</code>).</p>
  <?php endif; ?>
  <form method="get" class="form-grid cols-4" style="margin-bottom:16px">
    <label>شماره یا برچسب <input type="text" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="جستجو…"></label>
    <label>کاربر
      <select name="user">
        <option value="">همه</option>
        <option value="none" <?= ($f['user'] ?? '') === 'none' ? 'selected' : '' ?>>— تخصیص‌نیافته —</option>
        <?php foreach ($panelUsers as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (string)($f['user'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>نوع خط
      <select name="bypass">
        <option value="">همه</option>
        <option value="1" <?= ($f['bypass'] ?? '') === '1' ? 'selected' : '' ?>>خدماتی — بدون بررسی لیست سیاه</option>
        <option value="0" <?= ($f['bypass'] ?? '') === '0' ? 'selected' : '' ?>>عادی — با بررسی لیست سیاه</option>
      </select>
    </label>
    <label>درگاه ارسال
      <select name="gateway">
        <option value="">همه</option>
        <option value="route" <?= ($f['gateway'] ?? '') === 'route' ? 'selected' : '' ?>>— پیش‌فرض (طبق مسیر) —</option>
        <?php foreach ($gateways as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= (string)($f['gateway'] ?? '') === (string)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($detailsReady): ?>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (NUMBER_STATUSES + ['expired' => 'منقضی (فعال ولی تاریخ گذشته)'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>تاریخ انقضا
      <select name="expiry">
        <option value="">همه</option>
        <option value="expired" <?= ($f['expiry'] ?? '') === 'expired' ? 'selected' : '' ?>>گذشته</option>
        <option value="soon" <?= ($f['expiry'] ?? '') === 'soon' ? 'selected' : '' ?>>تا <?= to_persian_digits((string)$soonDays) ?> روز آینده</option>
        <option value="valid" <?= ($f['expiry'] ?? '') === 'valid' ? 'selected' : '' ?>>دارای انقضا و معتبر</option>
        <option value="none" <?= ($f['expiry'] ?? '') === 'none' ? 'selected' : '' ?>>بدون انقضا</option>
      </select>
    </label>
    <?php endif; ?>
    <div class="field" style="display:flex;gap:8px;align-items:end">
      <button class="btn btn-primary">جستجو</button>
      <?php if ($f): ?><a class="btn" href="/numbers.php">پاک کردن فیلترها</a><?php endif; ?>
    </div>
  </form>

  <?php if ($detailsReady): ?>
  <div class="num-legend">
    <?php foreach (NUMBER_STATUSES + ['expired' => 'منقضی'] as $k => $label):
        $chipQuery = $f; unset($chipQuery['status']);
        if (($f['status'] ?? '') !== $k) $chipQuery['status'] = $k; ?>
      <a class="num-chip num-st-<?= $k ?><?= ($f['status'] ?? '') === $k ? ' is-current' : '' ?>" href="/numbers.php<?= $chipQuery ? '?' . e(http_build_query($chipQuery)) : '' ?>"><?= e($label) ?> (<?= to_persian_digits((string)($statusCounts[$k] ?? 0)) ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
  <table class="num-table">
    <tr>
      <th>ردیف</th><th>شماره</th><th>برچسب</th><th>کاربر</th><th>اشتراکی</th><th>درگاه ارسال</th><th>خدماتی</th>
      <th>تاریخ ثبت</th><?php if ($detailsReady): ?><th>تاریخ انقضا</th><th>وضعیت</th><?php endif; ?><th></th>
    </tr>
    <?php foreach ($numbers as $i => $n):
        $state = $rowState($n);
        $editUrl = '/numbers.php?id=' . (int)$n['id'];
        $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0;
        $pinnedRow = $gatewaysById[$pinned] ?? null; ?>
      <tr class="num-row<?= $detailsReady ? ' num-st-' . $state : '' ?>">
        <td><?= to_persian_digits((string)($offset + $i + 1)) ?></td>
        <td><a class="num-link msisdn" href="<?= $editUrl ?>"><?= e($n['number']) ?></a></td>
        <td><?= e($n['label']) ?></td>
        <td><?= $n['assigned_user_id'] !== null ? e($usernames[(int)$n['assigned_user_id']] ?? ('#' . (int)$n['assigned_user_id'])) : '<span class="muted">—</span>' ?></td>
        <td><?= ($c = $shareCounts[(int)$n['id']] ?? 0) ? to_persian_digits((string)$c) . ' کاربر' : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if ($pinned === 0): ?><span class="muted">طبق مسیر</span>
          <?php else: ?><?= $pinnedRow ? e($pinnedRow['name']) : '#' . $pinned ?><?php if ($pinnedRow === null || !(int)$pinnedRow['send_enabled']): ?> <span style="color:var(--danger,#b00)" title="این درگاه اکنون قادر به ارسال نیست">⚠</span><?php endif; ?><?php endif; ?>
        </td>
        <td><?= (int)($n['bypass_blacklist'] ?? 0) === 1 ? '<span class="badge badge-ok">بله</span>' : '<span class="muted">خیر</span>' ?></td>
        <td><?= e(jdate_from_utc($n['created_at'] ?? null)) ?></td>
        <?php if ($detailsReady): ?>
          <td><?= $expiryCell($n['expires_on'] ?? null) ?></td>
          <td><b><?= e($stateLabel($state)) ?></b></td>
        <?php endif; ?>
        <td><a class="btn btn-sm" href="<?= $editUrl ?>">ویرایش</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$numbers): ?><tr><td colspan="<?= $detailsReady ? 11 : 9 ?>" class="empty"><?= $allCount > 0 ? 'شماره‌ای با این فیلتر نیست.' : 'هنوز شماره‌ای ثبت نشده.' ?></td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($total > 0): ?>
    <p class="hint">نمایش <?= to_persian_digits((string)($offset + 1)) ?> تا <?= to_persian_digits((string)min($offset + $perPage, $total)) ?> از <?= to_persian_digits(number_format($total)) ?> شماره<?= $total !== $allCount ? ' (از ' . to_persian_digits(number_format($allCount)) . ' شماره‌ی کل)' : '' ?>.</p>
  <?php endif; ?>
  <?= pager_links($page, pager_pages($total, $perPage), $f, 'page', $total) ?>
  <p class="hint">
    تخصیص به کاربر، کاربران اشتراکی، خدماتی بودن، درگاه ارسال، درگاه LBA، ارسال به URL، وضعیت، تاریخ انقضا و تعهدنامه‌ی هر شماره
    در صفحه‌ی «ویرایش شماره» تنظیم می‌شود (روی شماره یا دکمه‌ی «ویرایش» کلیک کنید).
    <?php if ($detailsReady): ?>از خطی که «فعال» نیست یا تاریخ انقضایش گذشته نمی‌توان ارسال کرد؛ دریافت پیامک روی آن ادامه دارد.<?php endif; ?>
  </p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
