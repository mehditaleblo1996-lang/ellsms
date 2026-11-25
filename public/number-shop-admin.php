<?php
/**
 * «فروش شماره» (admin): put unassigned lines up for sale with a price, a yearly renewal price, a
 * category, a description and features; approve or reject paid purchases. app/NumberShop.php,
 * docs/number-shop.md.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'فروش شماره';
$active = 'number_shop_admin';
$extraStyles = ['/assets/css/number-shop.css'];

if (!number_shop_ready()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>فروش شماره</h2><p class="flash flash-info">برای راه‌اندازی، مایگریشن <code>db/migrations/2026_11_28_number_shop.sql</code> را اجرا کنید (<code>make db-migrations-apply</code>).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}
number_shop_release_stale();

$tab = (string)($_GET['tab'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) $tab = 'add';
$pendingCount = number_shop_pending_approval_count();
if (!in_array($tab, ['orders', 'listings', 'add'], true)) $tab = $pendingCount > 0 ? 'orders' : 'listings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $actorId = (int)$me['id'];

    if ($do === 'list' || $do === 'update') {
        $v = number_shop_validate_listing($_POST);
        $listingId = (int)($_POST['listing_id'] ?? 0);
        if (!$v['ok']) {
            $_SESSION['number_shop_form'] = $_POST;
            flash('error', $v['error']);
            redirect('/number-shop-admin.php?tab=add' . ($listingId > 0 ? '&edit=' . $listingId : ''));
        }
        if ($do === 'update') {
            $r = number_shop_listing_update($listingId, $v['data'], $actorId);
            $r['ok'] ? flash('success', 'مشخصات شماره‌ی ' . $r['number'] . ' ذخیره شد. قیمت جدید فقط برای سفارش‌های بعدی اعمال می‌شود.') : flash('error', $r['error']);
            redirect('/number-shop-admin.php?tab=listings');
        }
        $mode = (string)($_POST['mode'] ?? 'single');
        $raw = [];
        if ($mode === 'range') {
            $range = number_range((string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''));
            if (!$range['ok']) {
                $_SESSION['number_shop_form'] = $_POST;
                flash('error', $range['error']);
                redirect('/number-shop-admin.php?tab=add');
            }
            $raw = $range['numbers'];
        } elseif ($mode === 'list') {
            $raw = preg_split('/[\s,،;]+/u', (string)($_POST['numbers'] ?? '')) ?: [];
        } else {
            $raw = [(string)($_POST['number'] ?? '')];
        }
        $raw = array_values(array_filter(array_map('trim', $raw), 'strlen'));
        if ($raw === []) {
            $_SESSION['number_shop_form'] = $_POST;
            flash('error', 'شماره‌ای برای فروش وارد نشده است.');
            redirect('/number-shop-admin.php?tab=add');
        }
        $r = number_shop_list_numbers($raw, $v['data'], $actorId);
        if (!$r['ok']) {
            $_SESSION['number_shop_form'] = $_POST;
            flash('error', $r['error']);
            redirect('/number-shop-admin.php?tab=add');
        }
        if ($r['listed']) {
            flash('success', to_persian_digits((string)count($r['listed'])) . ' شماره برای فروش عرضه شد و در بخش «خرید شماره» مشتریان دیده می‌شود.');
        }
        if ($r['skipped']) {
            $parts = array_map(static fn(array $s): string => $s[0] . ' (' . $s[1] . ')', array_slice($r['skipped'], 0, 10));
            flash($r['listed'] ? 'info' : 'error', 'عرضه نشد: ' . implode('، ', $parts) . (count($r['skipped']) > 10 ? ' و ' . to_persian_digits((string)(count($r['skipped']) - 10)) . ' مورد دیگر' : ''));
        }
        redirect('/number-shop-admin.php?tab=' . ($r['listed'] ? 'listings' : 'add'));
    }

    if ($do === 'withdraw' || $do === 'relist') {
        $r = number_shop_listing_set_on_sale((int)($_POST['listing_id'] ?? 0), $do === 'relist', $actorId);
        $r['ok'] ? flash('success', 'شماره‌ی ' . $r['number'] . ($do === 'relist' ? ' دوباره به فروش گذاشته شد.' : ' از فروش خارج شد.')) : flash('error', $r['error']);
        redirect('/number-shop-admin.php?tab=listings');
    }

    if ($do === 'approve') {
        $r = number_shop_approve((int)($_POST['order_id'] ?? 0), $actorId, (string)($_POST['note'] ?? ''));
        $r['ok'] ? flash('success', 'شماره‌ی ' . $r['number'] . ' به مشتری تخصیص داده شد و تا ' . jdate($r['expires_on'], false) . ' فعال است.') : flash('error', $r['error']);
        redirect('/number-shop-admin.php?tab=orders');
    }

    if ($do === 'reject') {
        $r = number_shop_reject((int)($_POST['order_id'] ?? 0), $actorId, (string)($_POST['reason'] ?? ''));
        $r['ok'] ? flash('info', 'سفارش شماره‌ی ' . $r['number'] . ' رد شد؛ ' . to_persian_digits(number_format($r['amount'])) . ' ریال به کیف پول مشتری برگشت و شماره دوباره در فروش است.') : flash('error', $r['error']);
        redirect('/number-shop-admin.php?tab=orders');
    }
    redirect('/number-shop-admin.php');
}

$rial = static fn(int $v): string => to_persian_digits(number_format($v));
$counts = number_shop_state_counts();
$tabUrl = static fn(string $t): string => '/number-shop-admin.php?tab=' . $t;

require __DIR__ . '/../app/views/header.php';
?>
<div class="ns-page">
<div class="card ns-head">
  <div class="ns-head-row">
    <div>
      <h2 class="ns-title"><span class="ns-ico"><?= panel_icon('cart') ?></span> فروش شماره</h2>
      <p class="hint ns-lead">شماره‌های اختصاصی را با قیمت، هزینه‌ی تمدید سالانه، توضیحات و امکانات برای فروش بگذارید. مشتری از منوی «شماره‌ها ← خرید شماره» انتخاب و پرداخت می‌کند و پس از تأیید شما، خط به حسابش تخصیص داده می‌شود.</p>
    </div>
    <div class="ns-stats">
      <div class="ns-stat"><span>در حال فروش</span><b><?= to_persian_digits((string)($counts['on_sale'] ?? 0)) ?></b></div>
      <div class="ns-stat"><span>رزرو</span><b><?= to_persian_digits((string)($counts['reserved'] ?? 0)) ?></b></div>
      <div class="ns-stat"><span>فروخته‌شده</span><b><?= to_persian_digits((string)($counts['sold'] ?? 0)) ?></b></div>
      <div class="ns-stat<?= $pendingCount > 0 ? ' is-alert' : '' ?>"><span>منتظر تأیید</span><b><?= to_persian_digits((string)$pendingCount) ?></b></div>
    </div>
  </div>
  <nav class="ns-tabs" aria-label="بخش‌های فروش شماره">
    <a class="ns-tab" href="<?= $tabUrl('orders') ?>" <?= $tab === 'orders' ? 'aria-current="page"' : '' ?>><?= panel_icon('receipt') ?> سفارش‌ها<?php if ($pendingCount > 0): ?> <span class="ns-count"><?= to_persian_digits((string)$pendingCount) ?></span><?php endif; ?></a>
    <a class="ns-tab" href="<?= $tabUrl('listings') ?>" <?= $tab === 'listings' ? 'aria-current="page"' : '' ?>><?= panel_icon('tag') ?> شماره‌های فروشی</a>
    <a class="ns-tab" href="<?= $tabUrl('add') ?>" <?= $tab === 'add' ? 'aria-current="page"' : '' ?>><?= panel_icon('plus') ?> <?= $editId > 0 ? 'ویرایش شماره‌ی فروشی' : 'افزودن شماره برای فروش' ?></a>
  </nav>
</div>

<?php if ($tab === 'orders'):
    $statusFilter = (string)($_GET['status'] ?? '');
    $perPage = 25;
    $probe = number_shop_orders_admin($statusFilter, 1, 0);
    [$page, $offset] = pager_offset($probe['total'], $perPage);
    $orders = number_shop_orders_admin($statusFilter, $perPage, $offset);
    $names = backend_usernames_by_ids(array_column($orders['rows'], 'user_id'));
?>
<div class="card">
  <div class="ns-card-head">
    <h2>سفارش‌های خرید و تمدید</h2>
    <form method="get" class="toolbar" style="margin:0">
      <input type="hidden" name="tab" value="orders">
      <select name="status" onchange="this.form.submit()" aria-label="وضعیت سفارش">
        <option value="">همه‌ی وضعیت‌ها</option>
        <?php foreach (NUMBER_SHOP_ORDER_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm">اعمال</button></noscript>
    </form>
  </div>
  <div class="table-wrap">
  <table class="ns-table">
    <tr><th>سفارش</th><th>شماره</th><th>نوع</th><th>مشتری</th><th>مبلغ (ریال)</th><th>پرداخت</th><th>فاکتور</th><th>تاریخ</th><th>وضعیت</th></tr>
    <?php foreach ($orders['rows'] as $o): ?>
      <tr class="<?= $o['status'] === 'awaiting_approval' ? 'ns-row-pending' : '' ?>">
        <td class="num">#<?= to_persian_digits((string)$o['id']) ?></td>
        <td class="ltr"><b><?= e(number_shop_pretty((string)$o['number'])) ?></b></td>
        <td><?= $o['kind'] === 'purchase' ? 'خرید' : 'تمدید' ?></td>
        <td><?= e($names[(int)$o['user_id']] ?? ('#' . (int)$o['user_id'])) ?></td>
        <td class="num"><?= $rial((int)$o['total_amount']) ?></td>
        <td class="ns-nowrap"><?= $o['pay_method'] === 'wallet' ? 'کیف پول' : 'آنلاین' ?></td>
        <td class="ns-nowrap"><?php if ($o['invoice_id']): ?><a class="ltr" href="/invoices.php?id=<?= (int)$o['invoice_id'] ?>"><?= e((string)$o['invoice_number']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= e(jdate_from_utc($o['created_at'])) ?></td>
        <td><span class="ns-badge ns-o-<?= e($o['status']) ?>"><?= e(NUMBER_SHOP_ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span>
          <?php if ($o['decided_at'] && $o['kind'] === 'purchase'): ?><div class="muted ns-note">تصمیم: <?= e(jdate_from_utc($o['decided_at'])) ?></div><?php endif; ?>
          <?php if ($o['admin_note'] !== ''): ?><div class="muted ns-note"><?= e($o['admin_note']) ?></div><?php endif; ?></td>
      </tr>
      <?php if ($o['status'] === 'awaiting_approval'): ?>
      <tr class="ns-row-pending ns-decide-row">
        <td colspan="9">
          <div class="ns-decide">
            <form method="post" class="ns-decide-form" onsubmit="return confirm('شماره به این مشتری تخصیص داده و برای یک سال فعال شود؟')">
              <?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="text" name="note" maxlength="500" placeholder="یادداشت تأیید (اختیاری)" aria-label="یادداشت تأیید">
              <button class="btn btn-sm btn-primary"><?= panel_icon('check') ?> تأیید و تخصیص</button>
            </form>
            <form method="post" class="ns-decide-form" onsubmit="return confirm('سفارش رد شود؟ مبلغ به کیف پول مشتری برمی‌گردد.')">
              <?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="text" name="reason" maxlength="500" required placeholder="دلیل رد (به مشتری نمایش داده می‌شود)" aria-label="دلیل رد">
              <button class="btn btn-sm btn-danger"><?= panel_icon('close') ?> رد و بازگشت وجه</button>
            </form>
          </div>
        </td>
      <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders['rows']): ?><tr><td colspan="9" class="empty-cell"><?= panel_empty_state('سفارشی نیست', 'وقتی مشتری شماره‌ای بخرد یا تمدید کند، اینجا دیده می‌شود.', null, 'receipt') ?></td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($orders['total'], $perPage), ['tab' => 'orders'] + ($statusFilter !== '' ? ['status' => $statusFilter] : []), 'page', $orders['total']) ?>
  <p class="hint">
    خرید پس از پرداخت «منتظر تأیید» می‌ماند و شماره برای مشتری رزرو است. <b>تأیید</b>: خط به مشتری تخصیص داده می‌شود، وضعیتش «فعال» و تاریخ انقضایش یک سال بعد می‌شود.
    <b>رد</b>: کل مبلغ (با مالیات) به کیف پول مشتری برمی‌گردد، فاکتور «بازگشت وجه» می‌شود و شماره دوباره در فروش قرار می‌گیرد.
    تمدید نیاز به تأیید ندارد و بلافاصله پس از پرداخت اعمال می‌شود.
  </p>
</div>

<?php elseif ($tab === 'listings'):
    $stateFilter = (string)($_GET['state'] ?? '');
    $q = trim((string)($_GET['q'] ?? ''));
    $perPage = 25;
    $probe = number_shop_listings_admin($stateFilter, $q, 1, 0);
    [$page, $offset] = pager_offset($probe['total'], $perPage);
    $listings = number_shop_listings_admin($stateFilter, $q, $perPage, $offset);
    $buyers = backend_usernames_by_ids(array_filter(array_column($listings['rows'], 'sold_to_user_id')));
?>
<div class="card">
  <div class="ns-card-head">
    <h2>شماره‌های فروشی</h2>
    <a class="btn btn-primary btn-sm" href="<?= $tabUrl('add') ?>"><?= panel_icon('plus') ?> افزودن شماره برای فروش</a>
  </div>
  <form method="get" class="toolbar ns-filter">
    <input type="hidden" name="tab" value="listings">
    <label>شماره <input type="text" name="q" value="<?= e($q) ?>" class="ltr" placeholder="مثلاً 9000"></label>
    <label>وضعیت
      <select name="state">
        <option value="">همه</option>
        <?php foreach (NUMBER_SHOP_LISTING_STATES as $k => $label): ?><option value="<?= $k ?>" <?= $stateFilter === $k ? 'selected' : '' ?>><?= e($label) ?> (<?= to_persian_digits((string)($counts[$k] ?? 0)) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <button class="btn"><?= panel_icon('search') ?> جستجو</button>
  </form>
  <div class="table-wrap">
  <table class="ns-table">
    <tr><th>شماره</th><th>دسته</th><th>قیمت خرید</th><th>تمدید سالانه</th><th>امکانات</th><th>وضعیت</th><th>خریدار / انقضا</th><th></th></tr>
    <?php foreach ($listings['rows'] as $l): $feat = number_shop_features((string)$l['features']); ?>
      <tr>
        <td class="ltr"><b><?= e(number_shop_pretty((string)$l['number'])) ?></b><?php if ((int)$l['featured']): ?> <span class="ns-badge ns-badge-star" title="ویژه"><?= panel_icon('star') ?></span><?php endif; ?>
          <?php if ($l['title'] !== ''): ?><div class="muted"><?= e($l['title']) ?></div><?php endif; ?></td>
        <td><?= $l['category'] !== '' ? '<span class="ns-chip">' . e($l['category']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= $rial((int)$l['price']) ?></td>
        <td class="num"><?= $rial((int)$l['renewal_price']) ?></td>
        <td><?= $feat ? to_persian_digits((string)count($feat)) . ' مورد' : '<span class="muted">—</span>' ?></td>
        <td><span class="ns-badge ns-l-<?= e($l['state']) ?>"><?= e(NUMBER_SHOP_LISTING_STATES[$l['state']] ?? $l['state']) ?></span>
          <?php if ($l['state'] === 'reserved' && $l['reserved_until'] !== null): ?><div class="muted ns-note">در انتظار پرداخت آنلاین تا <?= e(jdate_from_utc($l['reserved_until'])) ?></div><?php endif; ?></td>
        <td><?php if ($l['sold_to_user_id'] !== null): ?><?= e($buyers[(int)$l['sold_to_user_id']] ?? ('#' . (int)$l['sold_to_user_id'])) ?><?php if ($l['expires_on']): ?><div class="muted ns-note">تا <?= e(jdate($l['expires_on'], false)) ?></div><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="ns-row-actions">
          <a class="btn btn-sm" href="/number-shop-admin.php?edit=<?= (int)$l['id'] ?>"><?= panel_icon('edit') ?> ویرایش</a>
          <a class="btn btn-sm" href="/numbers.php?id=<?= (int)$l['number_id'] ?>" title="تنظیمات خط"><?= panel_icon('phone') ?></a>
          <?php if ($l['state'] === 'on_sale'): ?>
            <form method="post" onsubmit="return confirm('این شماره از فروش خارج شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="withdraw"><input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm">خارج از فروش</button></form>
          <?php elseif ($l['state'] === 'withdrawn'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="relist"><input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-primary">فروش دوباره</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$listings['rows']): ?><tr><td colspan="8" class="empty-cell"><?= panel_empty_state('شماره‌ای برای فروش نیست', 'اولین شماره را با قیمت و توضیحات برای فروش بگذارید.', ['/number-shop-admin.php?tab=add', 'افزودن شماره برای فروش'], 'tag') ?></td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($listings['total'], $perPage), array_filter(['tab' => 'listings', 'state' => $stateFilter, 'q' => $q], 'strlen'), 'page', $listings['total']) ?>
  <p class="hint">
    شماره‌ی «در حال فروش» به کسی تخصیص ندارد و فقط در فروشگاه مشتریان دیده می‌شود. شماره‌ای که مشتری آنلاین پرداخت را شروع کرده تا
    <?= to_persian_digits((string)NUMBER_SHOP_RESERVE_MINUTES) ?> دقیقه رزرو می‌ماند و اگر پرداخت نشود خودکار به فروش برمی‌گردد.
    برای پس گرفتن شماره‌ی فروخته‌شده، تخصیص آن را در صفحه‌ی «ویرایش شماره» بردارید؛ سپس «خارج از فروش ← فروش دوباره» ممکن است.
  </p>
</div>

<?php else:
    $edit = $editId > 0 ? number_shop_listing($editId) : null;
    if ($editId > 0 && $edit === null) { echo '<div class="card"><p class="empty">این شماره‌ی فروشی پیدا نشد.</p></div>'; }
    $old = $_SESSION['number_shop_form'] ?? [];
    unset($_SESSION['number_shop_form']);
    $val = static function (string $k, string $default = '') use ($old, $edit): string {
        if (array_key_exists($k, $old)) return (string)$old[$k];
        if ($edit !== null && array_key_exists($k, $edit)) return (string)$edit[$k];
        return $default;
    };
    $mode = (string)($old['mode'] ?? 'single');
    $categories = array_keys(number_shop_categories(false));
    $taxPercent = billing_tax_percent();
?>
<div class="ns-editor">
<form method="post" class="card ns-form" id="nsForm" data-mode="<?= e($mode) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="<?= $edit ? 'update' : 'list' ?>">
  <?php if ($edit): ?><input type="hidden" name="listing_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

  <section class="ns-section">
    <h3><span class="ns-step">۱</span> شماره</h3>
    <?php if ($edit): ?>
      <p class="ns-fixed-number ltr"><?= e(number_shop_pretty((string)$edit['number'])) ?></p>
      <p class="hint">وضعیت: <span class="ns-badge ns-l-<?= e($edit['state']) ?>"><?= e(NUMBER_SHOP_LISTING_STATES[$edit['state']]) ?></span> — تغییر قیمت روی سفارش‌های قبلی اثری ندارد.</p>
    <?php else: ?>
      <div class="ns-seg" role="radiogroup" aria-label="روش وارد کردن شماره">
        <label><input type="radio" name="mode" value="single" <?= $mode === 'single' ? 'checked' : '' ?>> یک شماره</label>
        <label><input type="radio" name="mode" value="range" <?= $mode === 'range' ? 'checked' : '' ?>> بازه</label>
        <label><input type="radio" name="mode" value="list" <?= $mode === 'list' ? 'checked' : '' ?>> چند شماره</label>
      </div>
      <div class="form-grid cols-2">
        <label data-mode="single">شماره <input type="text" name="number" class="ltr ns-input-number" value="<?= e((string)($old['number'] ?? '')) ?>" placeholder="9000123456" inputmode="numeric"></label>
        <label data-mode="range">از شماره <input type="text" name="from" class="ltr" value="<?= e((string)($old['from'] ?? '')) ?>" placeholder="9000123400" inputmode="numeric"></label>
        <label data-mode="range">تا شماره <input type="text" name="to" class="ltr" value="<?= e((string)($old['to'] ?? '')) ?>" placeholder="9000123499" inputmode="numeric"></label>
        <label data-mode="list" class="span-2">شماره‌ها (هر سطر یک شماره) <textarea name="numbers" rows="4" class="ltr" placeholder="9000123456&#10;9000777777"><?= e((string)($old['numbers'] ?? '')) ?></textarea></label>
      </div>
      <p class="hint">شماره‌ی جدید در فهرست «شماره‌ها» ثبت می‌شود؛ شماره‌ی موجود فقط وقتی عرضه می‌شود که به کسی تخصیص نداشته باشد. همه‌ی شماره‌های این فرم با همین قیمت و توضیحات عرضه می‌شوند.</p>
    <?php endif; ?>
  </section>

  <section class="ns-section">
    <h3><span class="ns-step">۲</span> قیمت <span class="ns-req">الزامی</span></h3>
    <div class="form-grid cols-2">
      <label>قیمت خرید (ریال)
        <input type="text" name="price" class="ltr ns-money" required inputmode="numeric" value="<?= e($val('price')) ?>" placeholder="50,000,000" data-preview="price">
        <span class="hint ns-words" data-words="price"></span>
      </label>
      <label>هزینه‌ی تمدید سالانه (ریال)
        <input type="text" name="renewal_price" class="ltr ns-money" required inputmode="numeric" value="<?= e($val('renewal_price')) ?>" placeholder="5,000,000" data-preview="renewal">
        <span class="hint ns-words" data-words="renewal"></span>
      </label>
    </div>
    <p class="hint">مبالغ بدون مالیات است<?= $taxPercent > 0 ? '؛ ' . to_persian_digits((string)$taxPercent) . '٪ مالیات بر ارزش افزوده روی فاکتور اضافه می‌شود' : '' ?>. خرید، شماره را یک سال فعال می‌کند و مشتری هر سال با «هزینه‌ی تمدید» تمدید می‌کند.</p>
  </section>

  <section class="ns-section">
    <h3><span class="ns-step">۳</span> معرفی شماره</h3>
    <div class="form-grid cols-2">
      <label>دسته / نوع
        <input type="text" name="category" list="nsCategories" maxlength="60" value="<?= e($val('category')) ?>" placeholder="مثلاً رند، خدماتی، تبلیغاتی" data-preview="category">
        <datalist id="nsCategories"><?php foreach (array_unique(array_merge($categories, ['رند', 'خدماتی', 'تبلیغاتی', 'اختصاصی'])) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
      </label>
      <label>عنوان کوتاه (اختیاری)
        <input type="text" name="title" maxlength="120" value="<?= e($val('title')) ?>" placeholder="مثلاً خط ۱۰ رقمی رند" data-preview="title">
      </label>
      <label class="span-2">توضیحات <span class="ns-req">الزامی</span>
        <textarea name="description" rows="4" maxlength="2000" required placeholder="این شماره برای چه کاری مناسب است، روی کدام اپراتورها ارسال دارد، شرایط استفاده و …" data-preview="description"><?= e($val('description')) ?></textarea>
      </label>
      <label class="span-2">امکانات (هر سطر یک مورد، حداکثر <?= to_persian_digits((string)NUMBER_SHOP_MAX_FEATURES) ?>)
        <textarea name="features" rows="5" placeholder="ارسال به همه‌ی اپراتورها&#10;دریافت پیامک و منشی پیامک&#10;قابل استفاده در API و ووکامرس&#10;خدماتی (بدون بررسی لیست سیاه)" data-preview="features"><?= e($val('features')) ?></textarea>
      </label>
      <label class="ns-check span-2"><input type="checkbox" name="featured" value="1" <?= $val('featured') === '1' || $val('featured') === 'on' ? 'checked' : '' ?> data-preview="featured"> نمایش به‌عنوان «ویژه» (بالای فروشگاه با نشان ستاره)</label>
    </div>
  </section>

  <div class="ns-submit">
    <button class="btn btn-primary"><?= panel_icon($edit ? 'check' : 'tag') ?> <?= $edit ? 'ذخیره‌ی تغییرات' : 'عرضه برای فروش' ?></button>
    <a class="btn" href="<?= $tabUrl('listings') ?>">انصراف</a>
  </div>
</form>

<aside class="ns-preview-wrap" aria-label="پیش‌نمایش برای مشتری">
  <div class="ns-preview-label"><?= panel_icon('eye') ?> مشتری این‌طور می‌بیند</div>
  <article class="ns-card ns-card-preview" id="nsPreview">
    <div class="ns-card-top">
      <span class="ns-chip" data-p="category"><?= e($val('category') ?: 'دسته') ?></span>
      <span class="ns-featured" data-p="featured" <?= $val('featured') === '1' ? '' : 'hidden' ?>><?= panel_icon('star') ?> ویژه</span>
    </div>
    <div class="ns-number ltr" data-p="number"><?= e($edit ? number_shop_pretty((string)$edit['number']) : '9000 1234 56') ?></div>
    <div class="ns-card-title" data-p="title"><?= e($val('title')) ?></div>
    <p class="ns-desc" data-p="description"><?= e($val('description') ?: 'توضیحات شماره اینجا نمایش داده می‌شود.') ?></p>
    <ul class="ns-features" data-p="features"></ul>
    <div class="ns-price-row">
      <div><span class="ns-price-label">قیمت خرید</span><b class="ns-price"><span data-p="price">—</span> <small>ریال</small></b></div>
      <div class="ns-renew"><span class="ns-price-label">تمدید سالانه</span><span><span data-p="renewal">—</span> ریال</span></div>
    </div>
    <span class="btn btn-primary ns-buy-btn" aria-hidden="true"><?= panel_icon('cart') ?> خرید این شماره</span>
  </article>
</aside>
</div>
<script>
(function () {
  var form = document.getElementById('nsForm'), card = document.getElementById('nsPreview');
  if (!form || !card) return;
  var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var en = function (s) { return String(s).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); };
  var digits = function (s) { return en(s).replace(/\D/g, ''); };
  var group = function (n) { return n ? fa(Number(n).toLocaleString('en-US').replace(/,/g, '٬')) : '—'; };
  var pretty = function (n) {
    n = digits(n);
    return n.length <= 5 ? n : n.match(/.{1,4}/g).join(' ');
  };
  var words = function (n) {
    n = Number(n); if (!n) return '';
    var toman = Math.floor(n / 10);
    if (toman >= 1e9) return '≈ ' + fa((toman / 1e9).toFixed(toman % 1e9 ? 2 : 0)) + ' میلیارد تومان';
    if (toman >= 1e6) return '≈ ' + fa((toman / 1e6).toFixed(toman % 1e6 ? 1 : 0)) + ' میلیون تومان';
    if (toman >= 1e3) return '≈ ' + fa((toman / 1e3).toFixed(0)) + ' هزار تومان';
    return fa(toman) + ' تومان';
  };
  var set = function (k, v) { card.querySelectorAll('[data-p="' + k + '"]').forEach(function (el) { el.textContent = v; }); };
  function render() {
    var f = form.elements;
    var num = f.number ? (form.dataset.mode === 'range' ? f.from.value : form.dataset.mode === 'list' ? (f.numbers.value.split(/\s+/)[0] || '') : f.number.value) : null;
    if (num !== null) set('number', pretty(num) || '9000 1234 56');
    set('category', f.category.value.trim() || 'دسته');
    card.querySelector('[data-p="category"]').hidden = f.category.value.trim() === '';
    set('title', f.title.value.trim());
    set('description', f.description.value.trim() || 'توضیحات شماره اینجا نمایش داده می‌شود.');
    card.querySelector('[data-p="featured"]').hidden = !f.featured.checked;
    var price = digits(f.price.value), renewal = digits(f.renewal_price.value);
    set('price', group(price)); set('renewal', group(renewal));
    form.querySelector('[data-words="price"]').textContent = words(price);
    form.querySelector('[data-words="renewal"]').textContent = words(renewal);
    var ul = card.querySelector('[data-p="features"]'); ul.innerHTML = '';
    f.features.value.split(/\n/).map(function (s) { return s.replace(/^[\s\-–•*✓✔]+/, '').trim(); }).filter(Boolean).slice(0, <?= NUMBER_SHOP_MAX_FEATURES ?>)
      .forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ul.appendChild(li); });
  }
  form.addEventListener('input', render);
  form.addEventListener('change', function (e) { if (e.target.name === 'mode') form.dataset.mode = e.target.value; render(); });
  // Thousands separators while typing a price; the server accepts them.
  form.querySelectorAll('.ns-money').forEach(function (el) {
    el.addEventListener('blur', function () { var d = digits(el.value); el.value = d ? Number(d).toLocaleString('en-US') : ''; });
  });
  render();
})();
</script>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
