<?php
/**
 * ELLSMS — a reseller's customer buys its reseller's packages online, with an optional discount
 * code, or redeems a gift code (app/ResellerPackages.php).
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$meId = (int)$me['id'];
$pageTitle = 'بسته‌های نماینده';
$active = 'reseller_packages';
$extraStyles = ['/assets/css/reseller.css'];

$account = customer_account($meId);
$parentId = $account['parent_user_id'];
if ($me['role'] === 'admin' || !reseller_panel_available() || $parentId === null || !reseller_is_reseller($parentId)) {
    http_response_code(403);
    exit('۴۰۳ — حساب شما زیرمجموعه‌ی نماینده نیست.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('billing.payment')) {
        redirect('/billing/packages');
    }
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'redeem') {
        $bucket = rate_limit_bucket('voucher_redeem', 'user', (string)$meId);
        if (!rate_limit_hit($bucket, 10, 600)) {
            flash('error', 'تعداد تلاش‌ها زیاد بود؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.');
            redirect('/billing/packages');
        }
        $result = reseller_voucher_redeem_gift($meId, (string)($_POST['code'] ?? ''));
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'کد هدیه اعمال شد: بسته‌ی «' . $result['package']['name'] . '» به حساب شما اضافه شد.' : reseller_package_error_message((string)$result['reason']));
        redirect('/billing/packages');
    }
    if ($do === 'buy') {
        if (($_POST['accept_refund_terms'] ?? '') !== '1') {
            flash('error', 'برای پرداخت، پذیرش قوانین و شرایط انصراف و بازگشت وجه لازم است.');
            redirect('/billing/packages');
        }
        $code = (string)($_POST['code'] ?? '');
        if ($code !== '' && !rate_limit_hit(rate_limit_bucket('voucher_redeem', 'user', (string)$meId), 10, 600)) {
            flash('error', 'تعداد تلاش‌ها زیاد بود؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.');
            redirect('/billing/packages');
        }
        try {
            $checkout = reseller_package_checkout($me, (int)($_POST['package_id'] ?? 0), $code);
        } catch (Throwable $e) {
            Logger::error('reseller.package_checkout_failed', ['user_id' => $meId, 'exception' => $e]);
            $checkout = ['ok' => false, 'reason' => 'package_unavailable'];
        }
        if (!$checkout['ok']) {
            flash('error', reseller_package_error_message((string)$checkout['reason']));
            redirect('/billing/packages');
        }
        if (!empty($checkout['applied'])) {
            flash('success', 'بسته به حساب شما اضافه شد.');
            redirect('/billing/packages');
        }
        $paymentId = (int)$checkout['payment_id'];
        $gateway = payment_gateway_name();
        $create = payment_gateway_create($gateway, (int)$checkout['total'], $paymentId, 'خرید بسته‌ی پیامک', (string)($me['mobile'] ?? ''));
        if ($create['ok']) {
            db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
            audit($meId, 'payment.request', "#{$paymentId} reseller_order=#{$checkout['order_id']} paid={$checkout['total']}rial gateway={$gateway}");
            redirect(payment_gateway_redirect_url($gateway, $create['authority']));
        }
        db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
        reseller_package_order_fail_for_payment($paymentId);
        flash('error', 'شروع پرداخت ممکن نشد. کمی بعد دوباره تلاش کنید.');
        redirect('/billing/packages');
    }
    redirect('/billing/packages');
}

$packages = array_values(array_filter(reseller_packages_for($parentId, true), static fn(array $p): bool => reseller_package_allows_type($p, $account['customer_type'])));
$tariffTitles = array_column(reseller_tariffs_for($parentId), 'title', 'id');
$orders = db()->prepare('SELECT o.*, p.name package_name FROM ellsms_reseller_package_orders o LEFT JOIN ellsms_reseller_packages p ON p.id = o.package_id
                         WHERE o.buyer_user_id = ? ORDER BY o.id DESC LIMIT 20');
$orders->execute([$meId]);
$orders = $orders->fetchAll();
$taxPercent = billing_tax_percent();
$num = static fn(int $n): string => to_persian_digits(number_format($n));
$statusLabels = ['pending' => 'در انتظار پرداخت', 'applied' => 'اعمال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغو شده'];
$sourceLabels = ['manual' => 'از طرف نماینده', 'online' => 'خرید آنلاین', 'voucher' => 'کد هدیه'];

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'billing.payment';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<div class="card">
  <h2>بسته‌های پیامک</h2>
  <p class="hint">این بسته‌ها را نماینده‌ی شما ارائه می‌کند. به مبلغ هر بسته <?= to_persian_digits((string)$taxPercent) ?>٪ ارزش افزوده اضافه می‌شود. اگر کد تخفیف دارید، هنگام خرید وارد کنید.</p>
  <?php if (!$packages): ?>
    <p class="muted">در حال حاضر بسته‌ای برای خرید آنلاین وجود ندارد.</p>
  <?php else: ?>
  <div class="package-cards">
    <?php foreach ($packages as $p): $final = reseller_package_final_price($p); ?>
      <div class="package-card">
        <h3><?= e($p['name']) ?></h3>
        <?php if ($p['description'] !== ''): ?><p class="hint" style="margin:0"><?= e($p['description']) ?></p><?php endif; ?>
        <div>
          <?php if ($final !== $p['price_rial']): ?><span class="package-old-price"><?= $num($p['price_rial']) ?></span> <?php endif; ?>
          <span class="package-price"><?= $num($final) ?></span> <span class="hint">ریال</span>
        </div>
        <ul>
          <?php if ($p['credit_rial'] > 0): ?><li>اعتبار پیامک: <?= $num($p['credit_rial']) ?> ریال</li><?php endif; ?>
          <?php if ($p['tariff_id'] !== null && isset($tariffTitles[$p['tariff_id']])): ?><li>تعرفه: <?= e($tariffTitles[$p['tariff_id']]) ?></li><?php endif; ?>
          <?php if ($p['validity_days'] !== null): ?><li>تمدید حساب: <?= to_persian_digits((string)$p['validity_days']) ?> روز</li><?php endif; ?>
          <?php if ($p['max_subset_limit'] !== null): ?><li>سقف زیرمجموعه: <?= to_persian_digits((string)$p['max_subset_limit']) ?></li><?php endif; ?>
        </ul>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="buy">
          <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
          <label>کد تخفیف <input type="text" name="code" class="ltr" maxlength="32" autocomplete="off"></label>
          <label class="consent-check" style="margin:8px 0">
            <input type="checkbox" name="accept_refund_terms" value="1" required>
            <span><a href="/terms" target="_blank" rel="noopener">قوانین</a> و <a href="/refund" target="_blank" rel="noopener">شرایط بازگشت وجه</a> را می‌پذیرم.</span>
          </label>
          <button class="btn btn-primary btn-block">خرید</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>کد هدیه</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="redeem">
    <label>کد <input type="text" name="code" class="ltr" maxlength="32" required autocomplete="off" placeholder="ABCD-1234"></label>
    <button class="btn btn-primary">اعمال کد</button>
  </form>
</div>

<?php if ($orders): ?>
<div class="card">
  <h2>بسته‌های شما</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>زمان</th><th>بسته</th><th>روش</th><th>مبلغ (ریال)</th><th>اعتبار (ریال)</th><th>وضعیت</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= e(jdate_from_utc((string)$o['created_at'])) ?></td>
        <td><?= e((string)($o['package_name'] ?? '')) ?></td>
        <td><?= e($sourceLabels[$o['source']] ?? $o['source']) ?></td>
        <td class="num"><?= $num((int)$o['price_rial']) ?></td>
        <td class="num"><?= $num((int)$o['credit_rial']) ?></td>
        <td><?= e($statusLabels[$o['status']] ?? $o['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
