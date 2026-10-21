<?php
/**
 * ELLSMS — one of the reseller's customers in the reseller panel (app/ResellerPanel.php): credit,
 * tariff (percentage and named tariff), expiry and quota, suspension, password, sending lines, menu
 * access, giving it a package, its sending report and ledger, and (the reseller itself only)
 * entering its panel in a read-mostly support session.
 *
 * Every action re-checks that the target is the reseller's direct child; nothing about the reseller
 * is taken from the request. A staff account acts for the reseller with its own permissions.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Dashboard.php';
[$me, $ctx] = require_reseller('customers.view');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$active = 'reseller_customers';
$owner = reseller_owner_user($resellerId);
$account = $ctx['account'];

$childId = (int)($_REQUEST['id'] ?? 0);
if (!in_array($childId, customer_child_ids($resellerId), true)) {
    flash('error', customer_error_message('not_your_child'));
    redirect('/reseller/customers');
}
$back = '/reseller/customers/view?id=' . $childId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $permission = match ($do) {
        'credit'   => 'credit.manage',
        'package'  => 'packages.manage',
        'tariff'   => 'tariffs.manage',
        'impersonate' => 'owner',
        default    => 'customers.manage',
    };
    if ($do === 'impersonate') {
        csrf_check();
        if (!reseller_can($ctx, 'owner')) redirect($back);
        $bucket = rate_limit_bucket('impersonate_start', 'user', (string)$resellerId);
        if (!rate_limit_hit($bucket, rate_limit_config('RATE_LIMIT_IMPERSONATE_MAX', 10), rate_limit_config('RATE_LIMIT_IMPERSONATE_WINDOW_SECONDS', 300))) {
            flash('error', impersonation_refusal_message('rate_limited'));
            redirect($back);
        }
        $result = impersonation_start($me, $childId, 'ورود نماینده به پنل مشتری', $back);
        if (!$result['ok']) {
            flash('error', impersonation_refusal_message((string)$result['reason']));
            redirect($back);
        }
        flash('info', 'وارد پنل مشتری شدید. ارسال پیامک و تغییرات حساس در این حالت غیرفعال است.');
        redirect('/index.php');
    }
    reseller_post_guard($ctx, $permission, $back);

    if ($do === 'credit') {
        $amount = (int)str_replace([',', '٬'], '', from_persian_digits((string)($_POST['amount'] ?? '0')));
        $direction = ($_POST['direction'] ?? '') === 'take' ? 'take' : 'give';
        $operationId = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['op_id'] ?? '')) ? (string)$_POST['op_id'] : bin2hex(random_bytes(16));
        $result = customer_transfer_credit($resellerId, $childId, $amount, $direction, $actorId, (string)($_POST['note'] ?? ''), $operationId);
        if ($result['ok']) {
            flash('success', ($direction === 'give' ? 'اعتبار به مشتری منتقل شد: ' : 'اعتبار از مشتری برگشت داده شد: ') . to_persian_digits(number_format($amount)) . ' ریال');
        } else {
            flash('error', customer_error_message((string)($result['reason'] ?? '')) . ($direction === 'take' && ($result['reason'] ?? '') === 'insufficient_balance' ? ' (اعتبار آزاد مشتری کمتر از این مبلغ است.)' : ''));
        }
    } elseif ($do === 'settings') {
        $changes = ['expires_on' => jalali_request_to_gregorian('expires_on')];
        if (isset($_POST['tariff_percent'])) {
            $bp = customer_tariff_bp_from_input((string)$_POST['tariff_percent']);
            if ($bp === null) {
                flash('error', customer_error_message('invalid_tariff'));
                redirect($back);
            }
            $changes['tariff_bp'] = $bp;
        }
        if (isset($_POST['max_subset_limit'])) {
            $changes['max_subset_limit'] = max(0, (int)from_persian_digits((string)$_POST['max_subset_limit']));
        }
        $result = customer_parent_update_child($resellerId, $childId, $changes, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تنظیمات مشتری ذخیره شد.' : customer_error_message((string)$result['reason']));
    } elseif ($do === 'tariff') {
        $result = reseller_assign_tariff($resellerId, $childId, (int)($_POST['tariff_id'] ?? 0) ?: null, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تعرفه‌ی مشتری ذخیره شد.' : reseller_tariff_error_message((string)$result['reason']));
    } elseif ($do === 'suspend' || $do === 'activate') {
        $result = customer_parent_update_child($resellerId, $childId, ['suspended' => $do === 'suspend'], $actorId);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? ($do === 'suspend' ? 'حساب مشتری غیرفعال شد.' : 'حساب مشتری فعال شد.') : customer_error_message((string)$result['reason']));
    } elseif ($do === 'password') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 6) {
            flash('error', 'رمز عبور باید حداقل ۶ نویسه باشد.');
        } else {
            backend_update_user_password($childId, backend_hash_password($password));
            audit($actorId, 'customer.child_password_reset', "#{$childId} reseller=#{$resellerId}");
            flash('success', 'رمز عبور مشتری تغییر کرد.');
        }
    } elseif ($do === 'lines') {
        $result = customer_set_child_lines($owner, $childId, (array)($_POST['line_ids'] ?? []), $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'خطوط ارسال مشتری ذخیره شد.' : customer_error_message((string)$result['reason']));
    } elseif ($do === 'menu') {
        $roleId = (int)($_POST['menu_role_id'] ?? 0) ?: null;
        $result = menu_user_access_save($childId, $roleId, (array)($_POST['menu_override'] ?? []), $resellerId, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'دسترسی منوهای مشتری ذخیره شد.' : menu_access_error_message((string)$result['reason']));
    } elseif ($do === 'package') {
        $operationId = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['op_id'] ?? '')) ? (string)$_POST['op_id'] : bin2hex(random_bytes(16));
        $result = reseller_package_assign($resellerId, $childId, (int)($_POST['package_id'] ?? 0), $actorId, 'manual:' . $operationId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? (!empty($result['replayed']) ? 'این بسته قبلاً داده شده بود.' : 'بسته به مشتری داده شد.') : reseller_package_error_message((string)$result['reason']));
    }
    redirect($back);
}

$identity = backend_user_details_by_ids([$childId])[$childId] ?? ['username' => '#' . $childId, 'first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => ''];
$acc = customer_account($childId);
$balance = wallet_balance($childId);
$block = customer_account_block_reason($childId);
$subOrgan = $acc['customer_type'] === 'organ' && $account['customer_type'] === 'organ';
$myTariffBp = customer_effective_tariff_bp($resellerId);
$tariffs = reseller_tariffs_for($resellerId);
$packages = array_values(array_filter(reseller_packages_for($resellerId), static fn(array $p): bool => $p['active'] && reseller_package_allows_type($p, $acc['customer_type'])));
$lines = customer_parent_lines($owner);
$sharedLines = customer_child_line_ids($owner, $childId);
$ledger = db()->prepare('SELECT type, amount, balance_after, reference_type, metadata, created_at FROM ellsms_wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 25');
$ledger->execute([$childId]);
$ledger = $ledger->fetchAll();
$orders = reseller_package_orders($resellerId, 20, $childId);
$today = dashboard_today_counts([$childId]);
$daily = dashboard_daily_sent([$childId], 14);
$jobs = dashboard_recent_jobs([$childId], 5);
$pageTitle = 'مشتری: ' . $identity['username'];

$ledgerLabels = [
    'transfer_in' => 'انتقال اعتبار (دریافت)', 'transfer_out' => 'انتقال اعتبار (پرداخت)', 'sms_debit' => 'هزینه‌ی ارسال',
    'purchase' => 'خرید اعتبار', 'manual_credit' => 'افزایش توسط مدیر', 'manual_debit' => 'کاهش توسط مدیر',
    'reservation_release' => 'آزادسازی رزرو', 'reseller_profit' => 'سود نمایندگی', 'migration_opening_balance' => 'موجودی اولیه',
    'reseller_package_sale' => 'تسویه‌ی فروش بسته',
];
$sourceLabels = ['manual' => 'تخصیص دستی', 'online' => 'خرید آنلاین', 'voucher' => 'کد هدیه'];
$statusLabels = ['pending' => 'در انتظار پرداخت', 'applied' => 'اعمال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو شده'];

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2 class="ltr" style="text-align:right"><?= e((string)$identity['username']) ?></h2>
      <p class="hint"><?= e(trim($identity['first_name'] . ' ' . $identity['last_name'])) ?> · <?= e(customer_type_label($acc, $account)) ?> · <span class="ltr"><?= e((string)$identity['mobile']) ?></span> · <span class="ltr"><?= e((string)$identity['email']) ?></span></p>
    </div>
    <a class="btn btn-sm btn-ghost" href="/reseller/customers">← بازگشت به فهرست</a>
  </div>
  <?php if ($block !== null): ?>
    <div class="flash flash-error"><?= $block === 'expired' ? 'این حساب منقضی شده و امکان ورود ندارد.' : 'این حساب غیرفعال است و امکان ورود ندارد.' ?></div>
  <?php endif; ?>
  <div class="summary-grid">
    <div class="summary-item"><div class="summary-label">اعتبار آزاد (ریال)</div><div class="summary-value num"><?= to_persian_digits(number_format($balance['available'])) ?></div></div>
    <div class="summary-item"><div class="summary-label">رزرو برای ارسال‌های در جریان</div><div class="summary-value num"><?= to_persian_digits(number_format($balance['reserved'])) ?></div></div>
    <div class="summary-item"><div class="summary-label">تعرفه</div><div class="summary-value"><?php
        $named = $acc['tariff_id'] !== null ? reseller_tariff_owned($resellerId, $acc['tariff_id']) : null;
        echo $named !== null ? e($named['title']) . ' <span class="hint">+ ' . to_persian_digits(customer_tariff_percent($acc['tariff_bp'])) . '٪</span>' : to_persian_digits(customer_tariff_percent(customer_effective_tariff_bp($childId))) . '٪';
    ?></div></div>
    <div class="summary-item"><div class="summary-label">انقضا</div><div class="summary-value"><?= e($acc['expires_on'] === null ? 'بدون انقضا' : jdate($acc['expires_on'], false)) ?></div></div>
  </div>
  <?php if (reseller_can($ctx, 'customers.manage')): ?>
  <div class="toolbar" style="margin:var(--sp-4) 0 0">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $childId ?>">
      <input type="hidden" name="do" value="<?= $acc['suspended'] ? 'activate' : 'suspend' ?>">
      <button class="btn btn-sm"><?= $acc['suspended'] ? 'فعال‌سازی حساب' : 'غیرفعال‌سازی حساب' ?></button>
    </form>
    <?php if ($block === null && !is_impersonating() && reseller_can($ctx, 'owner')): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $childId ?>">
      <input type="hidden" name="do" value="impersonate">
      <button class="btn btn-sm btn-primary">ورود به پنل مشتری</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="tabs" data-tabs="reseller-customer">
<nav class="tabs-nav" role="tablist" aria-label="بخش‌های مشتری">
  <?php if (reseller_can($ctx, 'credit.manage')): ?><button type="button" role="tab" data-tab="credit">اعتبار</button><?php endif; ?>
  <?php if (reseller_can($ctx, 'packages.manage')): ?><button type="button" role="tab" data-tab="package">دادن بسته</button><?php endif; ?>
  <?php if (reseller_can($ctx, 'customers.manage') || reseller_can($ctx, 'tariffs.manage')): ?><button type="button" role="tab" data-tab="settings">تعرفه و انقضا</button><?php endif; ?>
  <?php if (reseller_can($ctx, 'customers.manage')): ?>
    <button type="button" role="tab" data-tab="lines">خطوط ارسال</button>
    <button type="button" role="tab" data-tab="menus">دسترسی منوها</button>
    <button type="button" role="tab" data-tab="password">رمز عبور</button>
  <?php endif; ?>
  <button type="button" role="tab" data-tab="report">گزارش و تراکنش‌ها</button>
</nav>

<?php if (reseller_can($ctx, 'credit.manage')): ?>
<section class="tab-panel" data-panel="credit" role="tabpanel">
  <div class="card">
    <h2>انتقال اعتبار</h2>
    <p class="hint">اعتبار شما: <span class="num"><?= to_persian_digits(number_format((int)wallet_balance($resellerId)['available'])) ?></span> ریال. «برگشت» اعتبار آزاد مشتری را به حساب شما برمی‌گرداند.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <input type="hidden" name="op_id" value="<?= bin2hex(random_bytes(16)) ?>">
      <div class="form-grid">
        <label>عملیات
          <select name="direction"><option value="give">انتقال از من به مشتری</option><option value="take">برگشت از مشتری به من</option></select>
        </label>
        <label>مبلغ (ریال) * <input type="text" name="amount" class="ltr" inputmode="numeric" required></label>
        <label>توضیح <input type="text" name="note" maxlength="190"></label>
      </div>
      <div class="form-actions"><button class="btn btn-primary btn-sm">اعمال</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if (reseller_can($ctx, 'packages.manage')): ?>
<section class="tab-panel" data-panel="package" role="tabpanel">
  <div class="card">
    <h2>دادن بسته به مشتری</h2>
    <p class="hint">اعتبار بسته از حساب شما به مشتری منتقل می‌شود و تعرفه، اعتبار زمانی و سقف زیرمجموعه‌ی بسته روی حساب او اعمال می‌شود. وجه بسته را خودتان از مشتری دریافت می‌کنید.</p>
    <?php if (!$packages): ?>
      <p class="muted">بسته‌ی فعالی برای این نوع حساب ندارید. از <a href="/reseller/packages">بسته‌ها</a> بسازید.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="package">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <input type="hidden" name="op_id" value="<?= bin2hex(random_bytes(16)) ?>">
      <label>بسته
        <select name="package_id" required>
          <?php foreach ($packages as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — اعتبار <?= to_persian_digits(number_format($p['credit_rial'])) ?> ریال</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="form-actions"><button class="btn btn-primary btn-sm">دادن بسته</button></div>
    </form>
    <?php endif; ?>
    <?php if ($orders): ?>
      <h3 style="margin-top:16px">بسته‌های این مشتری</h3>
      <div class="table-wrap"><table>
        <thead><tr><th>زمان</th><th>بسته</th><th>روش</th><th>مبلغ</th><th>اعتبار</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= e(jdate_from_utc((string)$o['created_at'])) ?></td>
            <td><?= e((string)($o['package_name'] ?? '#' . $o['package_id'])) ?></td>
            <td><?= e($sourceLabels[$o['source']] ?? $o['source']) ?></td>
            <td class="num"><?= to_persian_digits(number_format((int)$o['price_rial'])) ?></td>
            <td class="num"><?= to_persian_digits(number_format((int)$o['credit_rial'])) ?></td>
            <td><?= e($statusLabels[$o['status']] ?? $o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (reseller_can($ctx, 'customers.manage') || reseller_can($ctx, 'tariffs.manage')): ?>
<section class="tab-panel" data-panel="settings" role="tabpanel">
  <?php if (reseller_can($ctx, 'tariffs.manage') && !$subOrgan): ?>
  <div class="card">
    <h2>تعرفه‌ی نام‌دار</h2>
    <p class="hint">قیمت هر خانه‌ی تعرفه‌ی نام‌دار، به‌جای درصد پایین، برای این مشتری اعمال می‌شود. سود شما اختلاف قیمت مشتری با قیمت خودتان است.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="tariff">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <label>تعرفه
        <select name="tariff_id">
          <option value="">— بدون تعرفه‌ی نام‌دار (فقط درصد) —</option>
          <?php foreach ($tariffs as $t): ?><option value="<?= $t['id'] ?>"<?= $acc['tariff_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره‌ی تعرفه</button> <a class="btn btn-sm btn-ghost" href="/reseller/tariffs">مدیریت تعرفه‌ها</a></div>
    </form>
  </div>
  <?php endif; ?>
  <?php if (reseller_can($ctx, 'customers.manage')): ?>
  <div class="card">
    <h2>درصد تعرفه، انقضا و سقف زیرمجموعه</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="settings">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <div class="form-grid">
        <?php if ($subOrgan): ?>
          <div class="field"><div class="hint">زیرسازمان همیشه با تعرفه‌ی سازمان ارسال می‌کند.</div></div>
        <?php else: ?>
          <label>تعرفه (درصد نرخ پایه)
            <input type="text" name="tariff_percent" class="ltr" inputmode="decimal" value="<?= e(customer_tariff_percent($acc['tariff_bp'])) ?>">
            <span class="hint" style="display:block">حداقل <?= to_persian_digits(customer_tariff_percent($myTariffBp)) ?>٪.</span>
          </label>
        <?php endif; ?>
        <label>تاریخ انقضا <?= jalali_date_select('expires_on', $acc['expires_on'], yearsAhead: 5, yearsBack: 1, allowEmpty: true) ?>
          <span class="hint" style="display:block">برای «بدون انقضا» روز، ماه و سال را خالی بگذارید.</span>
        </label>
        <?php if (customer_can_have_children($acc) && !$subOrgan): ?>
          <label>سقف زیرمجموعه <input type="text" name="max_subset_limit" class="ltr" inputmode="numeric" value="<?= (int)$acc['max_subset_limit'] ?>">
            <span class="hint" style="display:block">استفاده‌شده: <?= to_persian_digits((string)customer_used_subset($childId)) ?></span>
          </label>
        <?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره</button></div>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (reseller_can($ctx, 'customers.manage')): ?>
<section class="tab-panel" data-panel="lines" role="tabpanel">
  <div class="card">
    <h2>خطوط ارسال</h2>
    <p class="hint">خطوط خودتان را برای ارسال در اختیار این مشتری بگذارید. مشتری فقط از این خطوط ارسال می‌کند و پیام‌های دریافتی آن‌ها را نمی‌بیند.</p>
    <?php if (!$lines): ?>
      <p class="muted">خطی به حساب شما تخصیص داده نشده است.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="lines">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <div class="form-grid">
        <?php foreach ($lines as $line): ?>
          <label class="check"><input type="checkbox" name="line_ids[]" value="<?= $line['id'] ?>"<?= in_array($line['id'], $sharedLines, true) ? ' checked' : '' ?>> <span class="ltr"><?= e($line['number']) ?></span> <?= e($line['label']) ?></label>
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
        'user_id'   => $childId,
        'access'    => menu_user_access_get($childId),
        'roles'     => menu_roles_for($resellerId),
        'grantable' => menu_access_effective($resellerId),
        'effective' => menu_access_effective($childId),
        'hidden'    => ['do' => 'menu', 'id' => $childId],
    ];
    require __DIR__ . '/../app/views/menu_access_form.php';
    ?>
  </div>
</section>

<section class="tab-panel" data-panel="password" role="tabpanel">
  <div class="card">
    <h2>تعیین رمز عبور جدید</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="id" value="<?= $childId ?>">
      <label>رمز عبور جدید <input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
      <div class="form-actions"><button class="btn btn-primary btn-sm">تغییر رمز عبور</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="tab-panel" data-panel="report" role="tabpanel">
  <div class="card">
    <h2>گزارش ارسال</h2>
    <div class="summary-grid">
      <div class="summary-item"><div class="summary-label">ارسال امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($today['sent'])) ?></div></div>
      <div class="summary-item"><div class="summary-label">تحویل‌شده امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($today['delivered'])) ?></div></div>
      <div class="summary-item"><div class="summary-label">ناموفق امروز</div><div class="summary-value num"><?= to_persian_digits(number_format($today['failed'])) ?></div></div>
    </div>
    <h3 style="margin-top:var(--sp-4)">ارسال روزانه (۱۴ روز اخیر)</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>روز</th><th>پیام ارسال‌شده</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($daily, true) as $day => $count): ?>
          <tr><td><?= e(jdate($day, false)) ?></td><td class="num"><?= to_persian_digits(number_format($count)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($jobs): ?>
      <h3 style="margin-top:var(--sp-4)">آخرین ارسال‌های گروهی</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>عنوان</th><th>وضعیت</th><th>کل</th><th>ارسال‌شده</th><th>ناموفق</th></tr></thead>
          <tbody>
          <?php foreach ($jobs as $job): ?>
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
  </div>
  <div class="card">
    <h2>تراکنش‌های اعتبار</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>زمان</th><th>نوع</th><th>مبلغ</th><th>مانده</th></tr></thead>
        <tbody>
        <?php if (!$ledger): ?><tr><td colspan="4" class="muted">تراکنشی ثبت نشده است.</td></tr><?php endif; ?>
        <?php foreach ($ledger as $tx): ?>
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
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
