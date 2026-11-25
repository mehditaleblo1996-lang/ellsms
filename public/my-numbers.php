<?php
/**
 * «شماره‌ها» (customer): the customer's own lines with their expiry and yearly renewal, the number
 * shop («خرید شماره») and the customer's orders. app/NumberShop.php, docs/number-shop.md.
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$meId = (int)$me['id'];
$pageTitle = 'شماره‌ها';
$active = 'my_numbers';
$extraStyles = ['/assets/css/number-shop.css'];

if (!number_shop_ready()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>شماره‌ها</h2><p class="empty">فروش شماره هنوز راه‌اندازی نشده است.</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}
number_shop_release_stale();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('billing.payment')) redirect('/my-numbers.php');
    $kind = ($_POST['kind'] ?? '') === 'renewal' ? 'renewal' : 'purchase';
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $back = '/my-numbers.php?' . ($kind === 'renewal' ? 'renew=' : 'buy=') . $listingId;
    if (!rate_limit_hit(rate_limit_bucket('number_shop_checkout', 'user', (string)$meId), 20, 600)) {
        flash('error', 'تعداد تلاش‌ها زیاد بود؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.');
        redirect($back);
    }
    $method = ($_POST['method'] ?? '') === 'wallet' ? 'wallet' : 'online';
    try {
        $r = number_shop_checkout($me, $listingId, $kind, $method);
    } catch (Throwable $e) {
        Logger::error('number_shop.checkout_failed', ['user_id' => $meId, 'listing_id' => $listingId, 'exception' => $e]);
        $r = ['ok' => false, 'reason' => 'error'];
    }
    if (!$r['ok']) {
        flash('error', number_shop_error_message((string)$r['reason']));
        redirect(in_array($r['reason'], ['unavailable', 'listing_not_found'], true) ? '/my-numbers.php?tab=shop' : $back);
    }
    if (!empty($r['paid'])) {
        flash('success', $kind === 'renewal'
            ? 'شماره تمدید شد و تا ' . jdate((string)$r['expires_on'], false) . ' فعال است. فاکتور در «فاکتورها» صادر شد.'
            : 'پرداخت انجام شد و شماره برای شما رزرو شد. پس از تأیید مدیر، خط فعال و در فهرست «ارسال‌کننده» شما قرار می‌گیرد.');
        redirect('/my-numbers.php?tab=' . ($kind === 'renewal' ? 'mine' : 'orders'));
    }
    $paymentId = (int)$r['payment_id'];
    $gateway = payment_gateway_name();
    $create = payment_gateway_create($gateway, (int)$r['total'], $paymentId, $kind === 'renewal' ? 'تمدید شماره' : 'خرید شماره', (string)($me['mobile'] ?? ''));
    if ($create['ok']) {
        db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
        audit($meId, 'payment.request', "#{$paymentId} number_order=#{$r['order_id']} paid={$r['total']}rial gateway={$gateway}");
        redirect(payment_gateway_redirect_url($gateway, $create['authority']));
    }
    db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
    number_shop_fail_for_payment($paymentId);
    flash('error', 'شروع پرداخت آنلاین ممکن نشد: ' . $create['message']);
    redirect($back);
}

$rial = static fn(int $v): string => to_persian_digits(number_format($v));
$lines = number_shop_user_lines($meId);
$tab = (string)($_GET['tab'] ?? '');
$buyId = (int)($_GET['buy'] ?? 0);
$renewId = (int)($_GET['renew'] ?? 0);
if (!in_array($tab, ['mine', 'shop', 'orders'], true)) $tab = $lines ? 'mine' : 'shop';
if ($buyId > 0) $tab = 'shop';
if ($renewId > 0) $tab = 'mine';
$today = number_today();
$orders = number_shop_orders_for_user($meId);
$openOrders = count(array_filter($orders, static fn(array $o): bool => in_array($o['status'], ['pending_payment', 'awaiting_approval'], true)));

/* ---------- the checkout panel (?buy= / ?renew=) ---------- */
$checkout = null;
if ($buyId > 0 || $renewId > 0) {
    $listing = number_shop_listing($buyId ?: $renewId);
    $kind = $buyId > 0 ? 'purchase' : 'renewal';
    $ok = $listing !== null && ($kind === 'purchase'
        ? $listing['state'] === 'on_sale' && $listing['assigned_user_id'] === null
        : $listing['state'] === 'sold' && (int)$listing['sold_to_user_id'] === $meId && (int)$listing['assigned_user_id'] === $meId);
    if (!$ok) {
        flash('error', number_shop_error_message($kind === 'purchase' ? 'unavailable' : 'not_owner'));
        redirect('/my-numbers.php?tab=' . ($kind === 'purchase' ? 'shop' : 'mine'));
    }
    $amounts = number_shop_quote_amounts($kind === 'purchase' ? (int)$listing['price'] : (int)$listing['renewal_price']);
    $balance = wallet_balance($meId)['available'];
    $newExpiry = number_add_jalali_year($kind === 'purchase' || empty($listing['expires_on']) ? $today : max((string)$listing['expires_on'], $today));
    $checkout = compact('listing', 'kind', 'amounts', 'balance', 'newExpiry');
}

/** One storefront card. */
$card = static function (array $l, bool $withBuy = true) use ($rial): string {
    $features = number_shop_features((string)$l['features']);
    $h = '<article class="ns-card' . ((int)$l['featured'] ? ' is-featured' : '') . '">';
    $h .= '<div class="ns-card-top">' . ($l['category'] !== '' ? '<span class="ns-chip">' . e($l['category']) . '</span>' : '<span></span>')
        . ((int)$l['featured'] ? '<span class="ns-featured">' . panel_icon('star') . ' ویژه</span>' : '') . '</div>';
    $h .= '<div class="ns-number ltr">' . e(number_shop_pretty((string)$l['number'])) . '</div>';
    if ($l['title'] !== '') $h .= '<div class="ns-card-title">' . e($l['title']) . '</div>';
    $h .= '<p class="ns-desc">' . nl2br(e((string)$l['description'])) . '</p>';
    if ($features) {
        $h .= '<ul class="ns-features">';
        foreach ($features as $f) $h .= '<li>' . e($f) . '</li>';
        $h .= '</ul>';
    }
    $h .= '<div class="ns-price-row"><div><span class="ns-price-label">قیمت خرید</span><b class="ns-price">' . $rial((int)$l['price']) . ' <small>ریال</small></b></div>'
        . '<div class="ns-renew"><span class="ns-price-label">تمدید سالانه</span><span>' . $rial((int)$l['renewal_price']) . ' ریال</span></div></div>';
    if ($withBuy) $h .= '<a class="btn btn-primary ns-buy-btn" href="/my-numbers.php?buy=' . (int)$l['id'] . '">' . panel_icon('cart') . ' خرید این شماره</a>';
    return $h . '</article>';
};

require __DIR__ . '/../app/views/header.php';
?>
<div class="ns-page">
<div class="card ns-head">
  <div class="ns-head-row">
    <div>
      <h2 class="ns-title"><span class="ns-ico"><?= panel_icon('phone') ?></span> شماره‌ها</h2>
      <p class="hint ns-lead">شماره‌های اختصاصی شما برای ارسال پیامک، تاریخ اعتبار و تمدید سالانه‌ی آن‌ها، و خرید شماره‌ی جدید.</p>
    </div>
    <div class="ns-stats">
      <div class="ns-stat"><span>شماره‌های من</span><b><?= to_persian_digits((string)count($lines)) ?></b></div>
      <div class="ns-stat"><span>موجودی کیف پول</span><b><?= $rial(wallet_balance($meId)['available']) ?> <small>ریال</small></b></div>
    </div>
  </div>
  <nav class="ns-tabs" aria-label="بخش‌های شماره‌ها">
    <a class="ns-tab" href="/my-numbers.php?tab=mine" <?= $tab === 'mine' ? 'aria-current="page"' : '' ?>><?= panel_icon('phone') ?> شماره‌های من</a>
    <a class="ns-tab" href="/my-numbers.php?tab=shop" <?= $tab === 'shop' ? 'aria-current="page"' : '' ?>><?= panel_icon('cart') ?> خرید شماره</a>
    <a class="ns-tab" href="/my-numbers.php?tab=orders" <?= $tab === 'orders' ? 'aria-current="page"' : '' ?>><?= panel_icon('receipt') ?> سفارش‌های من<?php if ($openOrders > 0): ?> <span class="ns-count"><?= to_persian_digits((string)$openOrders) ?></span><?php endif; ?></a>
  </nav>
</div>

<?php if ($checkout !== null):
    $l = $checkout['listing'];
    $a = $checkout['amounts'];
    $isPurchase = $checkout['kind'] === 'purchase';
    $walletEnough = $checkout['balance'] >= $a['total'];
?>
<div class="ns-checkout">
  <div class="card ns-checkout-main">
    <a class="ns-back" href="/my-numbers.php?tab=<?= $isPurchase ? 'shop' : 'mine' ?>">→ بازگشت</a>
    <h2><?= $isPurchase ? 'خرید شماره' : 'تمدید شماره' ?></h2>
    <div class="ns-number ns-number-lg ltr"><?= e(number_shop_pretty((string)$l['number'])) ?></div>
    <ol class="ns-steps">
      <?php if ($isPurchase): ?>
        <li><b>پرداخت</b> از کیف پول یا درگاه بانکی؛ فاکتور رسمی صادر می‌شود.</li>
        <li><b>رزرو برای شما</b> تا بررسی مدیر (معمولاً کمتر از یک روز کاری).</li>
        <li><b>فعال‌سازی</b>: خط به حساب شما اضافه و تا <?= e(jdate($checkout['newExpiry'], false)) ?> فعال می‌شود. اگر تأیید نشود، کل مبلغ به کیف پول شما برمی‌گردد.</li>
      <?php else: ?>
        <li>اعتبار فعلی: <?= $l['expires_on'] ? e(jdate($l['expires_on'], false)) : 'بدون تاریخ' ?></li>
        <li>پس از پرداخت، بلافاصله تا <b><?= e(jdate($checkout['newExpiry'], false)) ?></b> تمدید می‌شود.</li>
      <?php endif; ?>
    </ol>

    <form method="post" class="ns-pay-form">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($checkout['kind']) ?>">
      <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
      <fieldset class="ns-methods">
        <legend>روش پرداخت</legend>
        <label class="ns-method">
          <input type="radio" name="method" value="online" <?= $walletEnough ? '' : 'checked' ?>>
          <span class="ns-method-body"><?= panel_icon('globe') ?><span><b>پرداخت آنلاین</b><small>درگاه بانکی؛ مبلغ پس از پرداخت از طریق کیف پول به سفارش منتقل می‌شود.</small></span></span>
        </label>
        <label class="ns-method<?= $walletEnough ? '' : ' is-disabled' ?>">
          <input type="radio" name="method" value="wallet" <?= $walletEnough ? 'checked' : 'disabled' ?>>
          <span class="ns-method-body"><?= panel_icon('wallet') ?><span><b>کسر از کیف پول</b>
            <small>موجودی: <?= $rial($checkout['balance']) ?> ریال<?= $walletEnough ? '' : ' — کافی نیست؛ <a href="/buy-credit.php">خرید اعتبار</a>' ?></small></span></span>
        </label>
      </fieldset>
      <button class="btn btn-primary ns-pay-btn"><?= panel_icon('check') ?> پرداخت <?= $rial($a['total']) ?> ریال</button>
      <p class="pay-consent">با پرداخت این صورت‌حساب، <a href="/terms" target="_blank" rel="noopener">قوانین و مقررات</a> و <a href="/refund" target="_blank" rel="noopener">شرایط انصراف و بازگشت وجه</a> را پذیرفته‌ام.</p>
    </form>
  </div>

  <aside class="card ns-summary">
    <h3>خلاصه‌ی فاکتور</h3>
    <table class="ns-sum-table">
      <tr><th><?= $isPurchase ? 'خرید شماره (یک سال)' : 'تمدید یک‌ساله' ?></th><td class="num"><?= $rial($a['price']) ?></td></tr>
      <?php if ($a['tax'] > 0): ?><tr><th>مالیات بر ارزش افزوده (<?= to_persian_digits((string)$a['tax_percent']) ?>٪)</th><td class="num"><?= $rial($a['tax']) ?></td></tr><?php endif; ?>
      <tr class="ns-sum-total"><th>مبلغ قابل پرداخت</th><td class="num"><?= $rial($a['total']) ?> <small>ریال</small></td></tr>
    </table>
    <?php if ($isPurchase): ?>
      <p class="hint">پس از سال اول، تمدید سالانه <?= $rial((int)$l['renewal_price']) ?> ریال (بدون مالیات) است.</p>
      <div class="ns-summary-card"><?= $card($l, false) ?></div>
    <?php endif; ?>
  </aside>
</div>

<?php elseif ($tab === 'mine'): ?>
<div class="card">
  <div class="ns-card-head">
    <h2>شماره‌های من</h2>
    <a class="btn btn-sm btn-primary" href="/my-numbers.php?tab=shop"><?= panel_icon('cart') ?> خرید شماره‌ی جدید</a>
  </div>
  <?php if (!$lines): ?>
    <?= panel_empty_state('هنوز شماره‌ی اختصاصی ندارید', 'با خرید یک شماره‌ی اختصاصی، پیامک‌ها با شماره‌ی خودتان ارسال می‌شود و پیامک‌های دریافتی و منشی پیامک هم روی آن فعال است.', ['/my-numbers.php?tab=shop', 'مشاهده‌ی شماره‌های فروشی'], 'phone') ?>
  <?php else: ?>
  <div class="ns-lines">
    <?php foreach ($lines as $n):
        $expired = number_is_expired($n['expires_on']);
        $days = $n['expires_on'] ? (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable((string)$n['expires_on']))->format('%r%a') : null;
        $soon = $days !== null && $days >= 0 && $days <= 30;
        $sendable = number_unsendable_reason_for((string)$n['status'], $n['expires_on']) === null;
    ?>
      <div class="ns-line<?= $expired ? ' is-expired' : ($soon ? ' is-soon' : '') ?>">
        <div class="ns-line-main">
          <div class="ns-number ltr"><?= e(number_shop_pretty((string)$n['number'])) ?></div>
          <div class="ns-line-meta">
            <?php if (($n['category'] ?? '') !== ''): ?><span class="ns-chip"><?= e($n['category']) ?></span><?php endif; ?>
            <span class="ns-badge <?= $sendable ? 'ns-o-completed' : 'ns-o-failed' ?>"><?= $sendable ? 'فعال' : ($expired ? 'منقضی' : e(number_status_label((string)$n['status']))) ?></span>
            <?php if ($n['label'] !== '' && $n['label'] !== 'فروشی'): ?><span class="muted"><?= e($n['label']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="ns-line-expiry">
          <span class="ns-price-label">اعتبار تا</span>
          <?php if ($n['expires_on']): ?>
            <b><?= e(jdate((string)$n['expires_on'], false)) ?></b>
            <small><?= $expired ? 'منقضی شده — ارسال متوقف است' : ($days === 0 ? 'امروز' : to_persian_digits((string)$days) . ' روز مانده') ?></small>
          <?php else: ?><b>بدون تاریخ انقضا</b><?php endif; ?>
        </div>
        <div class="ns-line-action">
          <?php if ($n['listing_id'] !== null): ?>
            <span class="ns-price-label">تمدید سالانه: <?= $rial((int)$n['renewal_price']) ?> ریال</span>
            <a class="btn btn-sm <?= $expired || $soon ? 'btn-primary' : '' ?>" href="/my-numbers.php?renew=<?= (int)$n['listing_id'] ?>"><?= panel_icon('renew') ?> تمدید یک‌ساله</a>
          <?php elseif ($n['expires_on']): ?>
            <a class="btn btn-sm" href="/tickets.php">تمدید از طریق پشتیبانی</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hint">از شماره‌ی منقضی‌شده نمی‌توان پیامک فرستاد، اما پیامک‌های دریافتی آن همچنان می‌رسد. تمدید، یک سال به بعد از تاریخ انقضای فعلی (یا امروز، اگر گذشته باشد) اضافه می‌کند.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'shop'):
    $f = [
        'q'        => trim((string)($_GET['q'] ?? '')),
        'category' => trim((string)($_GET['category'] ?? '')),
        'min'      => number_shop_parse_amount((string)($_GET['min'] ?? '')),
        'max'      => number_shop_parse_amount((string)($_GET['max'] ?? '')),
        'len'      => (string)($_GET['len'] ?? ''),
        'sort'     => (string)($_GET['sort'] ?? ''),
    ];
    $perPage = 12;
    $probe = number_shop_storefront($f, 1, 0);
    [$page, $offset] = pager_offset($probe['total'], $perPage);
    $shop = number_shop_storefront($f, $perPage, $offset);
    $categories = number_shop_categories();
    $query = array_filter(['tab' => 'shop', 'q' => $f['q'], 'category' => $f['category'], 'min' => $f['min'] !== null ? (string)$f['min'] : '',
        'max' => $f['max'] !== null ? (string)$f['max'] : '', 'len' => $f['len'], 'sort' => $f['sort']], 'strlen');
    $catUrl = static function (string $c) use ($query): string { $q = $query; unset($q['category']); if ($c !== '') $q['category'] = $c; return '/my-numbers.php?' . http_build_query($q); };
?>
<div class="card ns-shop-filters">
  <form method="get" class="ns-filter-grid">
    <input type="hidden" name="tab" value="shop">
    <?php if ($f['category'] !== ''): ?><input type="hidden" name="category" value="<?= e($f['category']) ?>"><?php endif; ?>
    <label class="ns-search"><?= panel_icon('search') ?><input type="search" name="q" value="<?= e($f['q']) ?>" class="ltr" placeholder="جستجوی رقم‌ها، مثلاً 1000 یا 777" aria-label="جستجوی شماره" inputmode="numeric"></label>
    <label>از قیمت (ریال)<input type="text" name="min" class="ltr" inputmode="numeric" value="<?= $f['min'] !== null ? e((string)$f['min']) : '' ?>" placeholder="0"></label>
    <label>تا قیمت (ریال)<input type="text" name="max" class="ltr" inputmode="numeric" value="<?= $f['max'] !== null ? e((string)$f['max']) : '' ?>" placeholder="بدون سقف"></label>
    <label>طول شماره
      <select name="len">
        <option value="">همه</option>
        <option value="short" <?= $f['len'] === 'short' ? 'selected' : '' ?>>کوتاه (تا ۸ رقم)</option>
        <option value="mid" <?= $f['len'] === 'mid' ? 'selected' : '' ?>>۹ تا ۱۱ رقم</option>
        <option value="long" <?= $f['len'] === 'long' ? 'selected' : '' ?>>۱۲ رقم و بیشتر</option>
      </select>
    </label>
    <label>مرتب‌سازی
      <select name="sort">
        <option value="">ویژه‌ها اول</option>
        <option value="cheap" <?= $f['sort'] === 'cheap' ? 'selected' : '' ?>>ارزان‌ترین</option>
        <option value="costly" <?= $f['sort'] === 'costly' ? 'selected' : '' ?>>گران‌ترین</option>
        <option value="new" <?= $f['sort'] === 'new' ? 'selected' : '' ?>>جدیدترین</option>
      </select>
    </label>
    <div class="ns-filter-actions">
      <button class="btn btn-primary"><?= panel_icon('filter') ?> اعمال</button>
      <?php if (count($query) > 1): ?><a class="btn" href="/my-numbers.php?tab=shop">پاک کردن</a><?php endif; ?>
    </div>
  </form>
  <?php if ($categories): ?>
  <div class="ns-cats" role="list" aria-label="دسته‌ها">
    <a role="listitem" class="ns-cat<?= $f['category'] === '' ? ' is-current' : '' ?>" href="<?= e($catUrl('')) ?>">همه</a>
    <?php foreach ($categories as $c => $count): ?>
      <a role="listitem" class="ns-cat<?= $f['category'] === $c ? ' is-current' : '' ?>" href="<?= e($catUrl((string)$c)) ?>"><?= e((string)$c) ?> <span><?= to_persian_digits((string)$count) ?></span></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($shop['rows']): ?>
  <p class="ns-result-count"><?= to_persian_digits(number_format($shop['total'])) ?> شماره برای فروش</p>
  <div class="ns-grid"><?php foreach ($shop['rows'] as $l) echo $card($l); ?></div>
  <?= pager_links($page, pager_pages($shop['total'], $perPage), $query, 'page', $shop['total']) ?>
<?php else: ?>
  <div class="card"><?= panel_empty_state(count($query) > 1 ? 'شماره‌ای با این فیلترها پیدا نشد' : 'فعلاً شماره‌ای برای فروش نیست',
      count($query) > 1 ? 'فیلترها را تغییر دهید یا پاک کنید.' : 'به‌زودی شماره‌های جدید اضافه می‌شود؛ برای درخواست شماره‌ی خاص تیکت ثبت کنید.',
      count($query) > 1 ? ['/my-numbers.php?tab=shop', 'پاک کردن فیلترها'] : ['/tickets.php', 'ثبت تیکت'], 'phone') ?></div>
<?php endif; ?>
<div class="card ns-how">
  <h3>خرید شماره چطور انجام می‌شود؟</h3>
  <ol class="ns-steps">
    <li><b>انتخاب</b> شماره و بررسی توضیحات، امکانات و هزینه‌ی تمدید سالانه.</li>
    <li><b>پرداخت</b> از کیف پول یا درگاه بانکی؛ فاکتور رسمی در «فاکتورها» صادر می‌شود.</li>
    <li><b>تأیید مدیر</b> و فعال شدن خط برای یک سال؛ در صورت عدم تأیید، مبلغ کامل به کیف پول برمی‌گردد.</li>
  </ol>
</div>

<?php else: ?>
<div class="card">
  <h2>سفارش‌های من</h2>
  <div class="table-wrap">
  <table class="ns-table">
    <tr><th>سفارش</th><th>شماره</th><th>نوع</th><th>مبلغ (ریال)</th><th>پرداخت</th><th>فاکتور</th><th>تاریخ</th><th>وضعیت</th></tr>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td class="num">#<?= to_persian_digits((string)$o['id']) ?></td>
        <td class="ltr"><b><?= e(number_shop_pretty((string)$o['number'])) ?></b></td>
        <td><?= $o['kind'] === 'purchase' ? 'خرید' : 'تمدید' ?></td>
        <td class="num"><?= $rial((int)$o['total_amount']) ?></td>
        <td><?= $o['pay_method'] === 'wallet' ? 'کیف پول' : 'آنلاین' ?></td>
        <td><?php if ($o['invoice_id']): ?><a class="ltr" href="/invoices.php?id=<?= (int)$o['invoice_id'] ?>"><?= e((string)$o['invoice_number']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= e(jdate_from_utc($o['created_at'])) ?></td>
        <td><span class="ns-badge ns-o-<?= e($o['status']) ?>"><?= e(NUMBER_SHOP_ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span>
          <?php if ($o['admin_note'] !== '' && in_array($o['status'], ['rejected', 'refunded', 'completed'], true)): ?><div class="muted ns-note"><?= e($o['admin_note']) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="8" class="empty-cell"><?= panel_empty_state('سفارشی ثبت نشده', 'خریدها و تمدیدهای شماره اینجا دیده می‌شود.', ['/my-numbers.php?tab=shop', 'خرید شماره'], 'receipt') ?></td></tr><?php endif; ?>
  </table>
  </div>
  <p class="hint">«در انتظار پرداخت» تا <?= to_persian_digits((string)NUMBER_SHOP_RESERVE_MINUTES) ?> دقیقه شماره را برای شما نگه می‌دارد؛ پس از آن، اگر پرداخت نشده باشد، شماره دوباره قابل خرید است.</p>
</div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
