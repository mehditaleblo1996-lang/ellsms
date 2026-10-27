<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'شماره‌ها';
$active = 'numbers';

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
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$id]);
        audit((int)$me['id'], 'number.delete', "#{$id}");
        flash('info', 'شماره حذف شد.');
    }

    // Keep the list filter the admin was looking at.
    $back = in_array($_POST['bypass_filter'] ?? '', ['1', '0'], true) ? '?bypass=' . $_POST['bypass_filter'] : '';
    redirect('/numbers.php' . $back);
}

// Service-line filter: '' = all, '1' = only service lines, '0' = only lines checked against the blacklist.
$bypassFilter = in_array($_GET['bypass'] ?? '', ['1', '0'], true) ? (string)$_GET['bypass'] : '';
$numbers = db()->query(
    "SELECT n.* FROM ellsms_numbers n ORDER BY (n.assigned_user_id IS NULL) DESC, n.number"
)->fetchAll();
$serviceLineCount = count(array_filter($numbers, static fn(array $n): bool => (int)($n['bypass_blacklist'] ?? 0) === 1));
$totalNumberCount = count($numbers);
if ($bypassFilter !== '') {
    $numbers = array_values(array_filter($numbers, static fn(array $n): bool => (string)(int)($n['bypass_blacklist'] ?? 0) === $bypassFilter));
}
$numberUsernames = backend_usernames_by_ids(array_column($numbers, 'assigned_user_id'));
foreach ($numbers as &$n) {
    $n['username'] = $n['assigned_user_id'] !== null ? ($numberUsernames[(int)$n['assigned_user_id']] ?? null) : null;
}
unset($n);

// Shares, grouped per number, in one query — not one per row. Usernames resolve through the same
// identity provider the assignment column already uses (app/Backend/identity.php), never a second
// ad-hoc join to user_.
$shareRows = db()->query('SELECT number_id, user_id FROM ellsms_number_shares')->fetchAll();
$shareUsernames = backend_usernames_by_ids(array_column($shareRows, 'user_id'));
$sharesByNumber = [];
foreach ($shareRows as $row) {
    $sharesByNumber[(int)$row['number_id']][] = [
        'user_id'  => (int)$row['user_id'],
        'username' => $shareUsernames[(int)$row['user_id']] ?? ('#' . (int)$row['user_id']),
    ];
}

$panelUsers = backend_panel_access_users();

// Every active gateway: selectable ones can send; a number still pinned to one that can no longer send is
// shown with a warning, because its messages are held (retried) rather than sent another way.
$gateways = db()->query("SELECT id, code, name, send_enabled, is_mock, default_slot FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY name")->fetchAll();
$gatewaysById = array_column($gateways, null, 'id');
$lbaGateways = lba_gateways_list();
$lbaGatewaysById = array_column($lbaGateways, null, 'id');

// Per-line MO / delivery-report forwarding (/number-forwards.php), shown here as a status + link.
$forwards = [];
if (number_forward_available()) {
    foreach (db()->query('SELECT number, mo_enabled, mo_url, dlr_enabled, dlr_url, consecutive_failures FROM ellsms_number_forwards') as $row) {
        $forwards[(string)$row['number']] = $row;
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن شماره</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <label>شماره <input type="text" name="number" required class="ltr" placeholder="5000435800"></label>
    <label>برچسب (اختیاری) <input type="text" name="label" placeholder="مثلاً خط پشتیبانی"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>

<div class="card">
  <h2>همه‌ی شماره‌ها</h2>
  <form method="get" class="toolbar">
    <label>نوع خط
      <select name="bypass" onchange="this.form.submit()">
        <option value="" <?= $bypassFilter === '' ? 'selected' : '' ?>>همه (<?= to_persian_digits((string)$totalNumberCount) ?>)</option>
        <option value="1" <?= $bypassFilter === '1' ? 'selected' : '' ?>>فقط خدماتی — بدون بررسی لیست سیاه (<?= to_persian_digits((string)$serviceLineCount) ?>)</option>
        <option value="0" <?= $bypassFilter === '0' ? 'selected' : '' ?>>فقط عادی — با بررسی لیست سیاه (<?= to_persian_digits((string)($totalNumberCount - $serviceLineCount)) ?>)</option>
      </select>
    </label>
    <noscript><button class="btn btn-sm">اعمال</button></noscript>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>شماره</th><th>برچسب</th><th>خدماتی (عبور از لیست سیاه)</th><th>تخصیص به کاربر</th><th>کاربران اشتراکی (فقط ارسال)</th><th>درگاه ارسال</th><th>درگاه LBA</th><th>ارسال به URL</th><th></th></tr>
    <?php foreach ($numbers as $n): ?>
      <tr>
        <td class="msisdn"><?= e($n['number']) ?></td>
        <td><?= e($n['label']) ?></td>
        <td>
          <?php $bypass = (int)($n['bypass_blacklist'] ?? 0) === 1; ?>
          <form method="post" class="toolbar" style="margin:0"
                onsubmit="return confirm(<?= e(json_encode($bypass
                    ? 'ارسال از این شماره دوباره با لیست سیاه بررسی شود؟'
                    : 'این شماره خدماتی شود؟ ارسال از آن دیگر با لیست سیاه مشتری بررسی نمی‌شود (لغو عضویت «۱۱» همچنان اعمال می‌شود).', JSON_UNESCAPED_UNICODE)) ?>)">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="bypass_blacklist">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <input type="hidden" name="value" value="<?= $bypass ? '0' : '1' ?>">
            <input type="hidden" name="bypass_filter" value="<?= e($bypassFilter) ?>">
            <span class="badge <?= $bypass ? 'badge-ok' : 'badge-unknown' ?>"><?= $bypass ? 'فعال' : 'غیرفعال' ?></span>
            <button class="btn btn-sm"><?= $bypass ? 'غیرفعال کن' : 'فعال کن' ?></button>
          </form>
        </td>
        <td>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="assign">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <select name="user_id" onchange="this.form.submit()">
              <option value="">— تخصیص‌نیافته —</option>
              <?php foreach ($panelUsers as $u): ?>
                <option value="<?= $u['id'] ?>" <?= (int)$n['assigned_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">اعمال</button></noscript>
          </form>
        </td>
        <td>
          <?php $shares = $sharesByNumber[(int)$n['id']] ?? []; ?>
          <?php foreach ($shares as $s): ?>
            <form method="post" class="toolbar" style="margin:0 0 4px">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="share_remove">
              <input type="hidden" name="id" value="<?= $n['id'] ?>">
              <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
              <span><?= e($s['username']) ?></span>
              <button class="btn btn-sm btn-danger" title="برداشتن اشتراک">×</button>
            </form>
          <?php endforeach; ?>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="share_add">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <select name="user_id" onchange="this.form.submit()">
              <option value="">— افزودن کاربر —</option>
              <?php
              $alreadyShared = array_column($shares, 'user_id');
              foreach ($panelUsers as $u):
                  // The outright owner is already covered by the assignment column; offering them
                  // here would create a share row that changes nothing.
                  if ((int)$u['id'] === (int)$n['assigned_user_id']) continue;
                  if (in_array((int)$u['id'], $alreadyShared, true)) continue;
              ?>
                <option value="<?= $u['id'] ?>"><?= e($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">افزودن</button></noscript>
          </form>
        </td>
        <td>
          <?php $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0; $pinnedRow = $gatewaysById[$pinned] ?? null; ?>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="gateway">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
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
        </td>
        <td>
          <?php $lbaPinned = (int)($n['lba_gateway_id'] ?? 0); ?>
          <?php if (lba_tables_ready()): ?>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="lba_gateway">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
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
        </td>
        <td>
          <?php $fw = $forwards[(string)$n['number']] ?? null; ?>
          <?php if ($fw): ?>
            <?php foreach (['mo' => 'دریافتی', 'dlr' => 'تحویل'] as $kind => $kindLabel): if ($fw[$kind . '_url'] === '') continue; ?>
              <span class="badge <?= (int)$fw[$kind . '_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= $kindLabel ?></span>
            <?php endforeach; ?>
            <?php if ((int)$fw['consecutive_failures'] >= NUMBER_FORWARD_WARN_FAILURES): ?><span style="color:var(--danger,#b00)" title="خطاهای پشت سر هم">⚠</span><?php endif; ?>
          <?php endif; ?>
          <a class="btn btn-sm" href="/number-forwards.php?edit=<?= e(rawurlencode((string)$n['number'])) ?>#lines">تنظیم</a>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('این شماره حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$numbers): ?><tr><td colspan="9" class="empty"><?= $bypassFilter !== '' && $totalNumberCount > 0 ? 'شماره‌ای با این فیلتر نیست.' : 'هنوز شماره‌ای ثبت نشده.' ?></td></tr><?php endif; ?>
  </table>
  </div>
  <p class="hint">وقتی کاربری حداقل یک شماره تخصیص‌یافته داشته باشد، در ارسال پیامک و منشی پیامک به‌جای نوشتن آزاد، از میان شماره‌های خودش انتخاب می‌کند.</p>
  <p class="hint">
    <b>کاربران اشتراکی:</b> یک شماره را می‌توان علاوه بر مالکش، به چند کاربر دیگر هم داد. آن کاربران این خط را در فهرست
    «ارسال‌کننده» صفحات ارسال، نظیر‌به‌نظیر، پیامک هوشمند و ارسال منطقه‌ای می‌بینند و می‌توانند از آن بفرستند — هزینه‌ی هر
    ارسال هم از اعتبار خودِ همان کاربر کم می‌شود.
    اشتراک <b>فقط اجازه‌ی ارسال</b> می‌دهد: صندوق دریافت، منشی پیامک و فهرست لغو عضویتِ این خط همچنان فقط برای مالک
    (یا سازمان مالک) دیده می‌شود. برای اینکه کاربری پیام‌های ورودی یک خط را هم ببیند، باید خط به خودش یا به سازمانش
    تخصیص داده شود، نه اشتراکی.
  </p>
  <p class="hint">
    <b>خدماتی (عبور از لیست سیاه):</b> برای خطوط خدماتی مثل اطلاع‌رسانی تراکنش، قبض یا نوبت‌دهی — همان «bypassBlackList» در وصال.
    وقتی فعال باشد، ارسال از این شماره در هیچ مسیری (ارسال تکی و گروهی، زمان‌بندی، نظیر‌به‌نظیر، پیامک هوشمند، ارسال با کد پستی و پیش‌نمایش هزینه)
    با لیست سیاه مشتری بررسی نمی‌شود، حتی اگر گزینه‌ی «فقط ارسال به لیست سفید» زده شده باشد.
    لغو عضویتِ گیرنده از همین خط («۱۱») همچنان رعایت می‌شود. پیش‌فرض برای هر شماره‌ی جدید غیرفعال است و هر تغییر در گزارش رویدادها ثبت می‌شود.
    کارهای گروهی‌ای که پیش از تغییر، بررسی لیست سیاه‌شان تمام شده، با همان نتیجه ارسال می‌شوند.
  </p>
  <p class="hint">
    <b>درگاه LBA:</b> ارسال پیام منطقه‌ای (LBA) این شماره فقط از درگاه LBA انتخاب‌شده می‌رود و ارسال عادی آن تغییری نمی‌کند.
    شماره‌ی بدون درگاه LBA در صفحه‌ی ارسال منطقه‌ای دیده نمی‌شود. درگاه‌ها، توکن و تعرفه در <a href="/lba-gateways.php">درگاه‌های LBA</a>.
  </p>
  <p class="hint">
    <b>ارسال به URL:</b> پیامک‌های دریافتی روی هر خط و گزارش تحویل پیامک‌های ارسالی از آن را به آدرس http/https مشتری
    می‌فرستد (مثل receiveUrl / deliveryUrl در وصال). تنظیمات، گزارش و خاموش/روشن کردن سرویس در
    <a href="/number-forwards.php">فوروارد پیامک به URL</a>.
  </p>
  <p class="hint">
    <b>درگاه ارسال:</b> هر پیامی که از این شماره ارسال شود فقط از درگاه انتخاب‌شده می‌رود (پیامک ورودی تغییری نمی‌کند).
    قیمت پیام همان قیمت مسیر فعلی است و تغییر نمی‌کند. اگر درگاه انتخاب‌شده در دسترس نباشد، پیام از درگاه دیگری فرستاده نمی‌شود:
    ارسال گروهی بعداً دوباره تلاش می‌کند و ارسال مستقیم خطا می‌دهد. تغییر حداکثر ظرف چند ثانیه روی workerها اعمال می‌شود.
    <?php if (!gateway_transport_enabled()): ?><br><b style="color:var(--danger,#b00)">توجه: ارسال از طریق درگاه‌ها (SMS_GATEWAY_TRANSPORT) خاموش است؛ این انتخاب تا روشن شدن آن اثری ندارد.</b><?php endif; ?>
  </p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
