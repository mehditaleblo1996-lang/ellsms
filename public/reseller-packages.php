<?php
/**
 * ELLSMS — the reseller's packages and gift / discount codes (app/ResellerPackages.php): define
 * packages its customers buy online or are given, create codes, and follow package orders.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('packages.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'بسته‌ها و کدهای تخفیف';
$active = 'reseller_package_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $back = '/reseller/packages' . ($do === 'voucher_create' || $do === 'voucher_toggle' ? '#vouchers' : '');
    reseller_post_guard($ctx, 'packages.manage', $back);
    if ($do === 'save') {
        $packageId = (int)($_POST['package_id'] ?? 0) ?: null;
        $result = reseller_package_save($resellerId, $packageId, $_POST, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'بسته ذخیره شد.' : reseller_package_error_message((string)$result['reason']));
        redirect('/reseller/packages' . ($result['ok'] ? '' : ($packageId ? '?edit=' . $packageId : '?new=1')));
    }
    if ($do === 'delete') {
        $result = reseller_package_delete($resellerId, (int)($_POST['package_id'] ?? 0), $actorId);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'بسته حذف شد.' : reseller_package_error_message((string)$result['reason']));
    }
    if ($do === 'voucher_create') {
        $result = reseller_vouchers_create($resellerId, array_merge($_POST, ['expires_on' => jalali_request_to_gregorian('expires_on')]), $actorId);
        if ($result['ok']) {
            $_SESSION['reseller_new_vouchers'] = $result['codes'];
            flash('success', to_persian_digits((string)count($result['codes'])) . ' کد ساخته شد.');
        } else {
            flash('error', reseller_package_error_message((string)$result['reason']));
        }
    }
    if ($do === 'voucher_toggle') {
        reseller_voucher_set_active($resellerId, (int)($_POST['voucher_id'] ?? 0), !empty($_POST['active']), $actorId);
        flash('info', 'وضعیت کد تغییر کرد.');
    }
    redirect($back);
}

$packages = reseller_packages_for($resellerId);
$tariffs = reseller_tariffs_for($resellerId);
$tariffTitles = array_column($tariffs, 'title', 'id');
$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? reseller_package_owned($resellerId, $editId) : null;
$showForm = $edit !== null || isset($_GET['new']) || $packages === [];
$vouchers = reseller_vouchers_for($resellerId);
$packageNames = array_column($packages, 'name', 'id');
$orders = reseller_package_orders($resellerId, 50);
$buyers = backend_usernames_by_ids(array_column($orders, 'buyer_user_id'));
$newCodes = $_SESSION['reseller_new_vouchers'] ?? [];
unset($_SESSION['reseller_new_vouchers']);
$num = static fn(int $n): string => to_persian_digits(number_format($n));
$typeOptions = ['normal' => 'مشتری عادی', 'organ' => 'مشتری سازمانی', 'reseller' => 'زیرنماینده'];
$sourceLabels = ['manual' => 'تخصیص دستی', 'online' => 'خرید آنلاین', 'voucher' => 'کد هدیه'];
$statusLabels = ['pending' => 'در انتظار پرداخت', 'applied' => 'اعمال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو شده'];

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>بسته‌های شما</h2>
      <p class="hint">مشتریان مستقیم شما بسته‌های «فروش آنلاین» را از منوی «بسته‌های نماینده» خریداری می‌کنند. با خرید آنلاین، اعتبار بسته به مشتری داده می‌شود و تفاوت مبلغ پرداختی و اعتبار بسته به حساب شما واریز (یا از آن کسر) می‌شود. بسته‌ای که خودتان به مشتری می‌دهید، اعتبارش از حساب شما منتقل می‌شود.</p>
    </div>
    <a class="btn btn-sm btn-primary" href="/reseller/packages?new=1#form">بسته‌ی جدید</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نام</th><th>قیمت (ریال)</th><th>تخفیف</th><th>اعتبار (ریال)</th><th>تعرفه</th><th>اعتبار زمانی</th><th>فروش آنلاین</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php if (!$packages): ?><tr><td colspan="9" class="empty">هنوز بسته‌ای نساخته‌اید.</td></tr><?php endif; ?>
      <?php foreach ($packages as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td class="num"><?= $num($p['price_rial']) ?></td>
          <td class="num"><?= $p['discount_percent'] > 0 ? to_persian_digits((string)$p['discount_percent']) . '٪' : '—' ?></td>
          <td class="num"><?= $num($p['credit_rial']) ?></td>
          <td><?= e($p['tariff_id'] !== null ? ($tariffTitles[$p['tariff_id']] ?? '—') : '—') ?></td>
          <td><?= $p['validity_days'] !== null ? to_persian_digits((string)$p['validity_days']) . ' روز' : '—' ?></td>
          <td><?= $p['online_sale'] ? 'بله' : 'خیر' ?></td>
          <td><span class="badge badge-<?= $p['active'] ? 'ok' : 'off' ?>"><?= $p['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
          <td class="toolbar">
            <a class="btn btn-sm" href="/reseller/packages?edit=<?= $p['id'] ?>#form">ویرایش</a>
            <form method="post" onsubmit="return confirm('این بسته حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm btn-ghost">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="card" id="form">
  <h2><?= $edit !== null ? 'ویرایش بسته: ' . e($edit['name']) : 'بسته‌ی جدید' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <?php if ($edit !== null): ?><input type="hidden" name="package_id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <label>نام بسته * <input type="text" name="name" maxlength="120" required value="<?= e($edit['name'] ?? '') ?>"></label>
      <label>قیمت فروش (ریال، بدون ارزش افزوده) * <input type="text" name="price_rial" class="ltr" inputmode="numeric" required value="<?= e((string)($edit['price_rial'] ?? '')) ?>"></label>
      <label>تخفیف بسته (درصد) <input type="text" name="discount_percent" class="ltr" inputmode="numeric" value="<?= e((string)($edit['discount_percent'] ?? '0')) ?>"></label>
      <label>اعتبار پیامک (ریال) <input type="text" name="credit_rial" class="ltr" inputmode="numeric" value="<?= e((string)($edit['credit_rial'] ?? '0')) ?>">
        <span class="hint" style="display:block">مبلغی که به کیف پول مشتری اضافه می‌شود.</span>
      </label>
      <label>تعرفه‌ای که به مشتری داده می‌شود
        <select name="tariff_id"><option value="">— بدون تغییر —</option>
          <?php foreach ($tariffs as $t): ?><option value="<?= $t['id'] ?>"<?= ($edit['tariff_id'] ?? null) === $t['id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>اعتبار زمانی (روز) <input type="text" name="validity_days" class="ltr" inputmode="numeric" value="<?= e((string)($edit['validity_days'] ?? '')) ?>">
        <span class="hint" style="display:block">خالی = بدون تغییر انقضا؛ تمدید از امروز یا از انقضای فعلی، هرکدام دیرتر.</span>
      </label>
      <label>سقف زیرمجموعه <input type="text" name="max_subset_limit" class="ltr" inputmode="numeric" value="<?= e((string)($edit['max_subset_limit'] ?? '')) ?>">
        <span class="hint" style="display:block">فقط برای زیرنماینده و مشتری سازمانی.</span>
      </label>
      <label>ترتیب نمایش <input type="text" name="sort_order" class="ltr" inputmode="numeric" value="<?= e((string)($edit['sort_order'] ?? '0')) ?>"></label>
      <label class="span-full">توضیح <input type="text" name="description" maxlength="500" value="<?= e($edit['description'] ?? '') ?>"></label>
      <fieldset class="span-full" style="border:0;padding:0;margin:0">
        <legend class="hint">برای کدام مشتریان</legend>
        <?php foreach ($typeOptions as $key => $label): ?>
          <label class="check" style="display:inline-flex;margin-inline-end:14px"><input type="checkbox" name="customer_types[]" value="<?= e($key) ?>"<?= in_array($key, $edit['customer_types'] ?? ['normal', 'organ'], true) ? ' checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label class="check"><input type="checkbox" name="online_sale" value="1"<?= ($edit['online_sale'] ?? true) ? ' checked' : '' ?>> قابل خرید آنلاین توسط مشتری</label>
      <label class="check"><input type="checkbox" name="active" value="1"<?= ($edit['active'] ?? true) ? ' checked' : '' ?>> فعال</label>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">ذخیره‌ی بسته</button>
      <a class="btn btn-ghost" href="/reseller/packages">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" id="vouchers">
  <h2>کدهای هدیه و تخفیف</h2>
  <p class="hint">«کد هدیه» یک بسته را رایگان به مشتری می‌دهد و اعتبار آن از حساب شما کم می‌شود. «کد تخفیف» درصدی از مبلغ خرید آنلاین بسته را کم می‌کند. هر مشتری از هر کد یک بار استفاده می‌کند.</p>
  <?php if ($newCodes): ?>
    <div class="reseller-link-box" role="status">
      <strong>کدهای ساخته‌شده:</strong>
      <?php foreach ($newCodes as $code): ?><span class="voucher-code"><?= e($code) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($packages): ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="voucher_create">
    <div class="form-grid">
      <label>نوع
        <select name="kind"><?php foreach (RESELLER_VOUCHER_KINDS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      </label>
      <label>بسته
        <select name="package_id"><option value="">— هر بسته (فقط کد تخفیف) —</option>
          <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>درصد تخفیف <input type="text" name="percent" class="ltr" inputmode="numeric" value="10"></label>
      <label>تعداد کد <input type="text" name="count" class="ltr" inputmode="numeric" value="1"></label>
      <label>دفعات استفاده‌ی هر کد <input type="text" name="max_uses" class="ltr" inputmode="numeric" value="1"></label>
      <label>کد دلخواه (اختیاری) <input type="text" name="code" class="ltr" maxlength="32" placeholder="NOWRUZ1405"></label>
      <label>آخرین روز استفاده <?= jalali_date_select('expires_on', null, yearsAhead: 3, yearsBack: 0, allowEmpty: true) ?></label>
      <label>یادداشت <input type="text" name="note" maxlength="190"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary btn-sm">ساخت کد</button></div>
  </form>
  <?php else: ?>
    <p class="muted">برای ساخت کد، ابتدا یک بسته بسازید.</p>
  <?php endif; ?>
  <?php if ($vouchers): ?>
  <div class="table-wrap" style="margin-top:14px">
    <table>
      <thead><tr><th>کد</th><th>نوع</th><th>بسته</th><th>تخفیف</th><th>استفاده</th><th>آخرین روز</th><th>یادداشت</th><th>وضعیت</th></tr></thead>
      <tbody>
      <?php foreach ($vouchers as $v): ?>
        <tr>
          <td class="voucher-code"><?= e($v['code']) ?></td>
          <td><?= e($v['kind'] === 'gift' ? 'هدیه' : 'تخفیف') ?></td>
          <td><?= e($v['package_id'] !== null ? ($packageNames[$v['package_id']] ?? '#' . $v['package_id']) : 'همه') ?></td>
          <td class="num"><?= $v['kind'] === 'discount' ? to_persian_digits((string)$v['percent']) . '٪' : '—' ?></td>
          <td class="num"><?= to_persian_digits($v['used_count'] . ' / ' . $v['max_uses']) ?></td>
          <td><?= e($v['expires_on'] !== null ? jdate($v['expires_on'], false) : '—') ?></td>
          <td class="hint"><?= e($v['note']) ?></td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="voucher_toggle">
              <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>">
              <input type="hidden" name="active" value="<?= $v['active'] ? '0' : '1' ?>">
              <button class="btn btn-sm<?= $v['active'] ? '' : ' btn-ghost' ?>"><?= $v['active'] ? 'فعال — غیرفعال کن' : 'غیرفعال — فعال کن' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>آخرین سفارش‌های بسته</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>مشتری</th><th>بسته</th><th>روش</th><th>مبلغ پرداختی</th><th>اعتبار</th><th>اثر بر حساب شما</th><th>وضعیت</th></tr></thead>
      <tbody>
      <?php if (!$orders): ?><tr><td colspan="8" class="empty">هنوز سفارشی ثبت نشده است.</td></tr><?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e(jdate_from_utc((string)$o['created_at'])) ?></td>
          <td class="ltr"><a href="/reseller/customers/view?id=<?= (int)$o['buyer_user_id'] ?>"><?= e((string)($buyers[(int)$o['buyer_user_id']] ?? '#' . $o['buyer_user_id'])) ?></a></td>
          <td><?= e((string)($o['package_name'] ?? '#' . $o['package_id'])) ?></td>
          <td><?= e($sourceLabels[$o['source']] ?? $o['source']) ?></td>
          <td class="num"><?= $num((int)$o['price_rial']) ?></td>
          <td class="num"><?= $num((int)$o['credit_rial']) ?></td>
          <td class="num ltr"><?= $num((int)$o['reseller_amount']) ?></td>
          <td><?= e($statusLabels[$o['status']] ?? $o['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
