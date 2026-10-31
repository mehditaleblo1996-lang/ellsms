<?php
/**
 * ELLSMS — a reseller's / organization's own sub-accounts (app/CustomerHierarchy.php).
 *
 * The parent creates sub-accounts (within its quota and Negar's type rules), moves credit to them
 * and takes it back, sets their tariff (a reseller only, never below its own) and expiry, suspends
 * and re-activates them, resets their password, shares its own sending lines with them, decides
 * which menus they see (app/MenuAccess.php), reads their sending report, and can enter their panel
 * in a read-mostly support session (app/impersonation.php).
 *
 * Every action re-checks that the target is this user's direct child; nothing about the parent is
 * taken from the request.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Dashboard.php';
$me = require_login();
$meId = (int)$me['id'];
$pageTitle = 'زیرمجموعه‌ها';
$active = 'subaccounts';

$available = customer_hierarchy_available();
$myAccount = customer_account($meId);
$childIds  = $available ? customer_child_ids($meId) : [];
if ($me['role'] === 'admin') {
    redirect('/users.php');
}
// A reseller has its own panel (app/ResellerPanel.php); this page keeps working and points there.
$resellerPanelLink = $myAccount['customer_type'] === 'reseller' && reseller_panel_available();
if (!$available || (!customer_can_have_children($myAccount) && $childIds === [])) {
    http_response_code(403);
    exit('۴۰۳ — حساب شما نماینده یا سازمان نیست و زیرمجموعه ندارد.');
}

/** Resolves a posted/queried child id to one of MY direct children, or null. */
$myChild = static function (int $id) use ($childIds): ?int {
    return in_array($id, $childIds, true) ? $id : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $childId = $myChild((int)($_POST['child_id'] ?? 0));
    $back = '/subaccounts.php' . ($childId ? '?view=' . $childId : '');

    if ($do !== 'impersonate' && impersonation_guard_post('subaccounts.manage')) {
        redirect($back);
    }

    if ($do === 'create') {
        $result = customer_create_sub_account($me, array_merge($_POST, ['expires_on' => jalali_request_to_gregorian('expires_on')]));
        if ($result['ok']) {
            flash('success', 'زیرمجموعه ساخته شد.');
            redirect('/subaccounts.php?view=' . $result['user_id']);
        }
        flash('error', customer_error_message((string)$result['reason']) . (!empty($result['message']) ? ' ' . $result['message'] : ''));
        redirect('/subaccounts.php#create');
    }

    if ($do === 'role_save' || $do === 'role_delete') {
        $roleId = (int)($_POST['role_id'] ?? 0) ?: null;
        if ($do === 'role_delete') {
            flash($roleId && menu_role_delete($roleId, $meId, $meId) ? 'info' : 'error', $roleId ? 'نقش حذف شد.' : 'نقش یافت نشد.');
        } else {
            $result = menu_role_save($roleId, (string)($_POST['role_name'] ?? ''), (array)($_POST['role_keys'] ?? []), $meId, $meId);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'نقش ذخیره شد.' : menu_access_error_message((string)$result['reason']));
        }
        redirect('/subaccounts.php#roles');
    }

    if ($childId === null) {
        flash('error', customer_error_message('not_your_child'));
        redirect('/subaccounts.php');
    }

    if ($do === 'impersonate') {
        $actorBucket = rate_limit_bucket('impersonate_start', 'user', (string)$meId);
        $max    = rate_limit_config('RATE_LIMIT_IMPERSONATE_MAX', 10);
        $window = rate_limit_config('RATE_LIMIT_IMPERSONATE_WINDOW_SECONDS', 300);
        if (!rate_limit_hit($actorBucket, $max, $window)) {
            flash('error', impersonation_refusal_message('rate_limited'));
            redirect($back);
        }
        $result = impersonation_start($me, $childId, 'ورود والد به پنل زیرمجموعه', '/subaccounts.php?view=' . $childId);
        if (!$result['ok']) {
            flash('error', impersonation_refusal_message((string)$result['reason']));
            redirect($back);
        }
        flash('info', 'وارد پنل زیرمجموعه شدید. ارسال پیامک و تغییرات حساس در این حالت غیرفعال است.');
        redirect('/index.php');
    }

    if ($do === 'credit') {
        $amount = (int)from_persian_digits((string)($_POST['amount'] ?? '0'));
        $direction = ($_POST['direction'] ?? '') === 'take' ? 'take' : 'give';
        $operationId = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['op_id'] ?? '')) ? (string)$_POST['op_id'] : bin2hex(random_bytes(16));
        $result = customer_transfer_credit($meId, $childId, $amount, $direction, $meId, (string)($_POST['note'] ?? ''), $operationId);
        if ($result['ok']) {
            flash('success', ($direction === 'give' ? 'اعتبار به زیرمجموعه منتقل شد: ' : 'اعتبار از زیرمجموعه برگشت داده شد: ') . to_persian_digits(number_format($amount)) . ' ریال');
        } else {
            flash('error', customer_error_message((string)($result['reason'] ?? '')) . ($direction === 'take' && ($result['reason'] ?? '') === 'insufficient_balance' ? ' (اعتبار آزاد زیرمجموعه کمتر از این مبلغ است.)' : ''));
        }
    } elseif ($do === 'settings') {
        $changes = [];
        if (isset($_POST['tariff_id']) && $myAccount['customer_type'] === 'reseller') {
            $result = reseller_assign_tariff($meId, $childId, (int)$_POST['tariff_id'] ?: null, $meId);
            if (!$result['ok']) {
                flash('error', reseller_tariff_error_message((string)$result['reason']));
                redirect($back);
            }
        }
        $changes['expires_on'] = jalali_request_to_gregorian('expires_on'); // left blank = no expiry
        if (isset($_POST['max_subset_limit'])) {
            $changes['max_subset_limit'] = max(0, (int)from_persian_digits((string)$_POST['max_subset_limit']));
        }
        $result = customer_parent_update_child($meId, $childId, $changes, $meId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تنظیمات زیرمجموعه ذخیره شد.' : customer_error_message((string)$result['reason']));
    } elseif ($do === 'suspend' || $do === 'activate') {
        $result = customer_parent_update_child($meId, $childId, ['suspended' => $do === 'suspend'], $meId);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? ($do === 'suspend' ? 'حساب زیرمجموعه غیرفعال شد.' : 'حساب زیرمجموعه فعال شد.') : customer_error_message((string)$result['reason']));
    } elseif ($do === 'password') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 6) {
            flash('error', 'رمز عبور باید حداقل ۶ نویسه باشد.');
        } else {
            backend_update_user_password($childId, backend_hash_password($password));
            audit($meId, 'customer.child_password_reset', "#{$childId}");
            flash('success', 'رمز عبور زیرمجموعه تغییر کرد.');
        }
    } elseif ($do === 'lines') {
        $result = customer_set_child_lines($me, $childId, (array)($_POST['line_ids'] ?? []), $meId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'خطوط ارسال زیرمجموعه ذخیره شد.' : customer_error_message((string)$result['reason']));
    } elseif ($do === 'menu') {
        $roleId = (int)($_POST['menu_role_id'] ?? 0) ?: null;
        $result = menu_user_access_save($childId, $roleId, (array)($_POST['menu_override'] ?? []), $meId, $meId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'دسترسی منوهای زیرمجموعه ذخیره شد.' : menu_access_error_message((string)$result['reason']));
    }
    redirect($back);
}

/* ---------------- Page data ---------------- */
$myTariffs    = $myAccount['customer_type'] === 'reseller' && reseller_panel_available() ? reseller_tariffs_for($meId) : [];
$usedSubset   = customer_used_subset($meId);
$allowedTypes = customer_allowed_child_types($myAccount);
$isOrgan      = $myAccount['customer_type'] === 'organ';
$identities   = backend_users_by_ids($childIds);
$accounts     = customer_accounts_by_ids($childIds);
$myGrantable  = menu_access_effective($meId);
$myRoles      = menu_roles_for($meId);

$viewId = $myChild((int)($_GET['view'] ?? 0));
$view = null;
if ($viewId !== null) {
    $view = [
        'identity'  => $identities[$viewId] ?? ['username' => '#' . $viewId, 'first_name' => '', 'last_name' => '', 'credit' => 0, 'active' => 1, 'deleted' => 0],
        'account'   => $accounts[$viewId],
        'balance'   => wallet_balance($viewId),
        'block'     => customer_account_block_reason($viewId),
        'used'      => customer_used_subset($viewId),
        'lines'     => customer_parent_lines($me),
        'shared'    => customer_child_line_ids($me, $viewId),
        'menu'      => menu_user_access_get($viewId),
        'effective' => menu_access_effective($viewId),
        'today'     => dashboard_today_counts([$viewId]),
        'daily'     => dashboard_daily_sent([$viewId], 14),
        'jobs'      => dashboard_recent_jobs([$viewId], 5),
    ];
    $ledger = db()->prepare("SELECT type, amount, balance_after, reference_type, metadata, created_at FROM ellsms_wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 25");
    $ledger->execute([$viewId]);
    $view['ledger'] = $ledger->fetchAll();
    $pageTitle = 'زیرمجموعه: ' . $view['identity']['username'];
}

$profitRows = [];
if ($myAccount['customer_type'] === 'reseller') {
    $pst = db()->prepare('SELECT profit_day, SUM(spent) spent, SUM(profit) profit FROM ellsms_reseller_profits WHERE reseller_user_id = ? GROUP BY profit_day ORDER BY profit_day DESC LIMIT 30');
    $pst->execute([$meId]);
    $profitRows = $pst->fetchAll();
}

$ledgerLabels = [
    'transfer_in' => 'انتقال اعتبار (دریافت)', 'transfer_out' => 'انتقال اعتبار (پرداخت)', 'sms_debit' => 'هزینه‌ی ارسال', 'delivery_refund' => 'بازگشت هزینه‌ی پیام تحویل‌نشده',
    'purchase' => 'خرید اعتبار', 'manual_credit' => 'افزایش توسط مدیر', 'manual_debit' => 'کاهش توسط مدیر',
    'reservation_release' => 'آزادسازی رزرو', 'reseller_profit' => 'سود نمایندگی', 'migration_opening_balance' => 'موجودی اولیه',
];
$typeOptions = ['normal' => 'مشتری عادی', 'organ' => $isOrgan ? 'زیرسازمان' : 'مشتری سازمانی', 'reseller' => 'زیرنماینده'];
$expiryText = static fn(?string $d): string => $d === null ? 'بدون انقضا' : jdate($d, false);

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($resellerPanelLink): ?>
<div class="flash flash-info">همه‌ی امکانات نمایندگی (تعرفه‌های نام‌دار، بسته‌ها، کد تخفیف، برند اختصاصی، کارمندان و …) در <a href="<?= $view !== null ? '/reseller/customers/view?id=' . (int)$viewId : '/reseller' ?>">پنل نمایندگی</a> است.</div>
<?php endif; ?>
<?php if ($view === null): ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2><?= e(customer_type_label($myAccount)) ?> — <?= e($me['full_name'] ?: $me['username']) ?></h2>
      <p class="hint"><?= $isOrgan ? 'سازمان فقط زیرسازمان می‌سازد. زیرسازمان با تعرفه‌ی شما ارسال می‌کند و خودش زیرمجموعه ندارد.' : 'شما برای زیرمجموعه‌هایتان تعرفه تعیین می‌کنید و اختلاف آن با تعرفه‌ی خودتان هر شب به‌عنوان سود به اعتبارتان اضافه می‌شود.' ?></p>
    </div>
  </div>
  <div class="summary-grid">
    <div class="summary-item"><div class="summary-label">اعتبار شما (ریال)</div><div class="summary-value num"><?= to_persian_digits(number_format((int)wallet_balance($meId)['available'])) ?></div></div>
    <div class="summary-item"><div class="summary-label">تعرفه‌ی شما</div><div class="summary-value"><?= e(customer_tariff_label($meId)) ?></div></div>
    <div class="summary-item"><div class="summary-label">سقف زیرمجموعه</div><div class="summary-value num"><?= to_persian_digits((string)$usedSubset) ?> از <?= to_persian_digits((string)$myAccount['max_subset_limit']) ?></div></div>
    <div class="summary-item"><div class="summary-label">انقضای حساب شما</div><div class="summary-value"><?= e($expiryText($myAccount['expires_on'])) ?></div></div>
  </div>
</div>

<div class="card">
  <h2>زیرمجموعه‌های شما</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نام کاربری</th><th>نام</th><th>نوع</th><th>اعتبار (ریال)</th><th>تعرفه</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php if (!$childIds): ?>
        <tr><td colspan="8" class="muted">هنوز زیرمجموعه‌ای نساخته‌اید.</td></tr>
      <?php endif; ?>
      <?php foreach ($childIds as $cid): ?>
        <?php $ident = $identities[$cid] ?? null; $acc = $accounts[$cid]; $block = customer_account_block_reason($cid); ?>
        <tr>
          <td class="ltr"><?= e((string)($ident['username'] ?? '#' . $cid)) ?></td>
          <td><?= e(trim(($ident['first_name'] ?? '') . ' ' . ($ident['last_name'] ?? ''))) ?></td>
          <td><?= e(customer_type_label($acc, $myAccount)) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)($ident['credit'] ?? 0))) ?></td>
          <td><?= e(customer_tariff_label($cid)) ?></td>
          <td><?= e($expiryText($acc['expires_on'])) ?></td>
          <td><span class="badge badge-<?= $block === null ? 'ok' : 'off' ?>"><?= $block === null ? 'فعال' : ($block === 'expired' ? 'منقضی' : 'غیرفعال') ?></span></td>
          <td><a class="btn btn-sm" href="/subaccounts.php?view=<?= $cid ?>">مدیریت</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($allowedTypes !== []): ?>
<div class="card" id="create">
  <h2>ساخت زیرمجموعه‌ی جدید</h2>
  <p class="hint">باقی‌مانده‌ی سقف زیرمجموعه: <?= to_persian_digits((string)max(0, $myAccount['max_subset_limit'] - $usedSubset)) ?>. هر زیرمجموعه یک واحد، به‌علاوه‌ی سقف زیرمجموعه‌ای که به خودش می‌دهید، از سقف شما کم می‌کند.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-grid">
      <label>نوع حساب *
        <select name="customer_type">
          <?php foreach ($allowedTypes as $t): ?><option value="<?= e($t) ?>"><?= e($typeOptions[$t]) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if (!$isOrgan): ?>
        <label>شخصیت
          <select name="account_type"><option value="individual">حقیقی</option><option value="legal">حقوقی</option></select>
        </label>
        <?php if ($myAccount['customer_type'] === 'reseller'): ?>
        <label>تعرفه *
          <select name="tariff_id" required><option value="">— انتخاب کنید —</option>
            <?php foreach ($myTariffs as $t): ?><option value="<?= $t['id'] ?>"<?= $t['is_default'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
          </select>
          <span class="hint" style="display:block"><?= $myTariffs ? 'نمی‌تواند کمتر از قیمت خودتان باشد؛ اختلافش سود شماست.' : 'هنوز تعرفه‌ای ندارید؛ ابتدا در <a href="/reseller/tariffs">پنل نمایندگی ← تعرفه‌ها</a> یکی بسازید.' ?></span>
        </label>
        <?php endif; ?>
        <label>سقف زیرمجموعه‌ی این حساب
          <input type="text" name="max_subset_limit" class="ltr" inputmode="numeric" value="0">
          <span class="hint" style="display:block">فقط برای زیرنماینده و مشتری سازمانی.</span>
        </label>
      <?php endif; ?>
      <label>نام کاربری * <input type="text" name="username" class="ltr" required minlength="5"></label>
      <label>رمز عبور * <input type="password" name="password" minlength="6" required autocomplete="new-password"></label>
      <label>نام * <input type="text" name="first_name" required></label>
      <label>نام خانوادگی * <input type="text" name="last_name" required></label>
      <label>ایمیل * <input type="email" name="email" class="ltr" required></label>
      <label>موبایل * <input type="text" name="mobile" class="ltr" inputmode="tel" required placeholder="09xxxxxxxxx"></label>
      <label>کد ملی * <input type="text" name="national_id" class="ltr" inputmode="numeric" required maxlength="10"></label>
      <label>جنسیت <select name="gender"><option value="MALE">مرد</option><option value="FEMALE">زن</option></select></label>
      <label>تاریخ انقضا <?= jalali_date_select('expires_on', null, yearsAhead: 5, yearsBack: 0, allowEmpty: true) ?>
        <span class="hint" style="display:block"><?= $isOrgan ? 'خالی = هم‌زمان با انقضای سازمان شما.' : 'خالی = بدون انقضا.' ?><?= $myAccount['expires_on'] !== null ? ' نمی‌تواند بعد از ' . e($expiryText($myAccount['expires_on'])) . ' باشد.' : '' ?></span>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">ساخت حساب</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card" id="roles">
  <h2>نقش‌های دسترسی منو</h2>
  <p class="hint">نقش، مجموعه‌ای از منوهاست که می‌توانید به زیرمجموعه‌ها بدهید. فقط منوهایی که خودتان دارید در نقش‌هایتان قابل انتخاب است.</p>
  <?php
  $editRoleId = (int)($_GET['role'] ?? 0);
  $rolesForm = [
      'roles'     => $myRoles,
      'editable'  => static fn(array $role): bool => $role['owner_user_id'] === $meId,
      'grantable' => $myGrantable,
      'edit'      => isset($myRoles[$editRoleId]) && $myRoles[$editRoleId]['owner_user_id'] === $meId ? $myRoles[$editRoleId] : null,
      'hidden'    => [],
  ];
  require __DIR__ . '/../app/views/menu_roles_form.php';
  ?>
</div>

<?php if ($profitRows): ?>
<div class="card">
  <h2>سود نمایندگی (۳۰ روز اخیر)</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>روز</th><th>هزینه‌ی ارسال زیرمجموعه‌ها</th><th>سود واریزشده</th></tr></thead>
      <tbody>
      <?php foreach ($profitRows as $p): ?>
        <tr><td><?= e(jdate($p['profit_day'], false)) ?></td><td class="num"><?= to_persian_digits(number_format((int)$p['spent'])) ?></td><td class="num"><?= to_persian_digits(number_format((int)$p['profit'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: /* ---------------- One sub-account ---------------- */ ?>
<?php $acc = $view['account']; $ident = $view['identity']; $subOrgan = $acc['customer_type'] === 'organ' && $isOrgan; ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2 class="ltr" style="text-align:right"><?= e((string)$ident['username']) ?></h2>
      <p class="hint"><?= e(trim($ident['first_name'] . ' ' . $ident['last_name'])) ?> · <?= e(customer_type_label($acc, $myAccount)) ?></p>
    </div>
    <a class="btn btn-sm btn-ghost" href="/subaccounts.php">← بازگشت به فهرست</a>
  </div>
  <?php if ($view['block'] !== null): ?>
    <div class="flash flash-error"><?= $view['block'] === 'expired' ? 'این حساب منقضی شده و امکان ورود ندارد.' : 'این حساب غیرفعال است و امکان ورود ندارد.' ?></div>
  <?php endif; ?>
  <div class="summary-grid">
    <div class="summary-item"><div class="summary-label">اعتبار آزاد (ریال)</div><div class="summary-value num"><?= to_persian_digits(number_format($view['balance']['available'])) ?></div></div>
    <div class="summary-item"><div class="summary-label">رزرو‌شده برای ارسال‌های در جریان</div><div class="summary-value num"><?= to_persian_digits(number_format($view['balance']['reserved'])) ?></div></div>
    <div class="summary-item"><div class="summary-label">تعرفه</div><div class="summary-value"><?= e(customer_tariff_label($viewId)) ?></div></div>
    <div class="summary-item"><div class="summary-label">انقضا</div><div class="summary-value"><?= e($expiryText($acc['expires_on'])) ?></div></div>
  </div>
  <div class="toolbar" style="margin:var(--sp-4) 0 0">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <input type="hidden" name="do" value="<?= $acc['suspended'] ? 'activate' : 'suspend' ?>">
      <button class="btn btn-sm"><?= $acc['suspended'] ? 'فعال‌سازی حساب' : 'غیرفعال‌سازی حساب' ?></button>
    </form>
    <?php if ($view['block'] === null && !is_impersonating()): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <input type="hidden" name="do" value="impersonate">
      <button class="btn btn-sm btn-primary">ورود به پنل زیرمجموعه</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="tabs" data-tabs="subaccount">
<nav class="tabs-nav" role="tablist" aria-label="بخش‌های زیرمجموعه">
  <button type="button" role="tab" data-tab="credit">اعتبار</button>
  <button type="button" role="tab" data-tab="settings">تعرفه و انقضا</button>
  <button type="button" role="tab" data-tab="lines">خطوط ارسال</button>
  <button type="button" role="tab" data-tab="menus">دسترسی منوها</button>
  <button type="button" role="tab" data-tab="report">گزارش ارسال</button>
  <button type="button" role="tab" data-tab="password">رمز عبور</button>
</nav>

<section class="tab-panel" data-panel="credit" role="tabpanel">
  <div class="card">
    <h2>انتقال اعتبار</h2>
    <p class="hint">اعتبار شما: <span class="num"><?= to_persian_digits(number_format((int)wallet_balance($meId)['available'])) ?></span> ریال. «برگشت» اعتبار آزاد زیرمجموعه را به حساب شما برمی‌گرداند.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <input type="hidden" name="op_id" value="<?= bin2hex(random_bytes(16)) ?>">
      <div class="form-grid">
        <label>عملیات
          <select name="direction"><option value="give">انتقال از من به زیرمجموعه</option><option value="take">برگشت از زیرمجموعه به من</option></select>
        </label>
        <label>مبلغ (ریال) * <input type="text" name="amount" class="ltr" inputmode="numeric" required></label>
        <label>توضیح <input type="text" name="note" maxlength="190"></label>
      </div>
      <div class="form-actions"><button class="btn btn-primary btn-sm">اعمال</button></div>
    </form>
  </div>
  <div class="card">
    <h2>تراکنش‌های اعتبار</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>زمان</th><th>نوع</th><th>مبلغ</th><th>مانده</th></tr></thead>
        <tbody>
        <?php if (!$view['ledger']): ?><tr><td colspan="4" class="muted">تراکنشی ثبت نشده است.</td></tr><?php endif; ?>
        <?php foreach ($view['ledger'] as $tx): ?>
          <tr>
            <td><?= e(jdate_from_utc((string)$tx['created_at'])) ?></td>
            <td><?= e($ledgerLabels[$tx['type']] ?? $tx['type']) ?></td>
            <td class="num ltr"><?= to_persian_digits(number_format((int)$tx['amount'])) ?></td>
            <td class="num"><?= to_persian_digits(number_format((int)$tx['balance_after'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" data-panel="settings" role="tabpanel">
  <div class="card">
    <h2>تعرفه، انقضا و سقف زیرمجموعه</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="settings">
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <div class="form-grid">
        <?php if ($subOrgan): ?>
          <div class="field"><div class="hint">زیرسازمان همیشه با تعرفه‌ی سازمان ارسال می‌کند.</div></div>
        <?php elseif ($myTariffs): ?>
          <label>تعرفه
            <select name="tariff_id" required>
              <?php if ($acc['tariff_id'] === null): ?><option value="">— انتخاب کنید —</option><?php endif; ?>
              <?php foreach ($myTariffs as $t): ?><option value="<?= $t['id'] ?>"<?= $acc['tariff_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
            </select>
            <span class="hint" style="display:block">سود شما = قیمت زیرمجموعه − قیمت خودتان.</span>
          </label>
        <?php endif; ?>
        <label>تاریخ انقضا <?= jalali_date_select('expires_on', $acc['expires_on'], yearsAhead: 5, yearsBack: 1, allowEmpty: true) ?>
          <span class="hint" style="display:block">برای «بدون انقضا» روز، ماه و سال را خالی بگذارید.</span>
        </label>
        <?php if (customer_can_have_children($acc) && !$subOrgan): ?>
          <label>سقف زیرمجموعه <input type="text" name="max_subset_limit" class="ltr" inputmode="numeric" value="<?= (int)$acc['max_subset_limit'] ?>">
            <span class="hint" style="display:block">استفاده‌شده: <?= to_persian_digits((string)$view['used']) ?></span>
          </label>
        <?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره</button></div>
    </form>
  </div>
</section>

<section class="tab-panel" data-panel="lines" role="tabpanel">
  <div class="card">
    <h2>خطوط ارسال</h2>
    <p class="hint">خطوط خودتان را می‌توانید برای ارسال در اختیار این زیرمجموعه بگذارید. زیرمجموعه فقط از این خطوط ارسال می‌کند و پیام‌های دریافتی آن‌ها را نمی‌بیند.</p>
    <?php if (!$view['lines']): ?>
      <p class="muted">خطی به حساب شما تخصیص داده نشده است.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="lines">
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <div class="form-grid">
        <?php foreach ($view['lines'] as $line): ?>
          <label class="check"><input type="checkbox" name="line_ids[]" value="<?= $line['id'] ?>"<?= in_array($line['id'], $view['shared'], true) ? ' checked' : '' ?>> <span class="ltr"><?= e($line['number']) ?></span> <?= e($line['label']) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره‌ی خطوط</button></div>
    </form>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" data-panel="menus" role="tabpanel">
  <div class="card">
    <h2>دسترسی منوها</h2>
    <?php
    $menuForm = [
        'user_id'   => $viewId,
        'access'    => $view['menu'],
        'roles'     => $myRoles,
        'grantable' => $myGrantable,
        'effective' => $view['effective'],
        'hidden'    => ['do' => 'menu', 'child_id' => $viewId],
    ];
    require __DIR__ . '/../app/views/menu_access_form.php';
    ?>
  </div>
</section>

<section class="tab-panel" data-panel="report" role="tabpanel">
  <div class="card">
    <h2>گزارش ارسال</h2>
    <div class="summary-grid">
      <div class="summary-item"><div class="summary-label">ارسال امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($view['today']['sent'])) ?></div></div>
      <div class="summary-item"><div class="summary-label">تحویل‌شده امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($view['today']['delivered'])) ?></div></div>
      <div class="summary-item"><div class="summary-label">ناموفق امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($view['today']['failed'])) ?></div></div>
    </div>
    <h3 style="margin-top:var(--sp-4)">ارسال روزانه (۱۴ روز اخیر)</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>روز</th><th>پیام ارسال‌شده</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($view['daily'], true) as $day => $count): ?>
          <tr><td><?= e(jdate($day, false)) ?></td><td class="num"><?= to_persian_digits(number_format($count)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($view['jobs']): ?>
      <h3 style="margin-top:var(--sp-4)">آخرین ارسال‌های گروهی</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>عنوان</th><th>وضعیت</th><th>کل</th><th>ارسال‌شده</th><th>ناموفق</th></tr></thead>
          <tbody>
          <?php foreach ($view['jobs'] as $job): ?>
            <tr>
              <td><?= e((string)($job['title'] ?: ('#' . $job['id']))) ?></td>
              <td><span class="badge badge-<?= e((string)$job['status']) ?>"><?= e((string)$job['status']) ?></span></td>
              <td class="num"><?= to_persian_digits(number_format((int)$job['total_rows'])) ?></td>
              <td class="num"><?= to_persian_digits(number_format((int)$job['sent_rows'])) ?></td>
              <td class="num"><?= to_persian_digits(number_format((int)$job['failed_rows'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <p class="hint" style="margin-top:var(--sp-3)">برای دیدن جزئیات کامل هر پیام، از «ورود به پنل زیرمجموعه» استفاده کنید.</p>
  </div>
</section>

<section class="tab-panel" data-panel="password" role="tabpanel">
  <div class="card">
    <h2>تعیین رمز عبور جدید</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="child_id" value="<?= $viewId ?>">
      <label>رمز عبور جدید <input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
      <div class="form-actions"><button class="btn btn-primary btn-sm">تغییر رمز عبور</button></div>
    </form>
  </div>
</section>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
