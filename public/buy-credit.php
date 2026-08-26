<?php
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'خرید اعتبار';
$active = 'buy_credit';

if (!is_admin()) {
    require_permission(Permissions::WALLET_VIEW);
    require_permission(Permissions::PAYMENTS_VIEW);
}

$rialPerCredit = (int)setting('rial_per_credit', '1000');
$minPurchase   = (int)setting('min_credit_purchase', '100');
$packages      = array_filter(array_map('intval', explode(',', (string)setting('credit_packages', ''))));
$taxPercent    = billing_tax_percent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('billing.payment')) {
        redirect('/buy-credit.php');
    }
    $credits = max(0, (int)($_POST['credits'] ?? 0));

    if ($credits < $minPurchase) {
        flash('error', 'حداقل میزان خرید ' . to_persian_digits(number_format($minPurchase)) . ' واحد اعتبار است.');
    } else {
        $baseAmountRial = $credits * $rialPerCredit;
        $gateway = payment_gateway_name();
        db()->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,?)')
           ->execute([$me['id'], $me['organization_id'] ?? null, $credits, $baseAmountRial, $gateway]);
        $paymentId = (int)db()->lastInsertId();

        // Issue the invoice before contacting the provider. VAT is computed centrally by
        // billing_invoice_create(); payment_gateway_create() then charges this exact immutable total.
        $invoiceResult = billing_invoice_create($paymentId, $me['organization_id'] ?? null, (int)$me['id'], 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null,
            'description' => "خرید {$credits} واحد اعتبار پیامک", 'quantity' => 1, 'unit_price' => $baseAmountRial,
        ]]);
        if (!$invoiceResult['ok']) {
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            flash('error', 'صدور فاکتور ممکن نشد. پرداخت شروع نشد.');
            redirect('/buy-credit.php');
        }
        $totalAmountRial = (int)$invoiceResult['total_amount'];
        audit((int)$me['id'], 'invoice.issued_for_credit_purchase', "payment=#{$paymentId} {$credits}cr total={$totalAmountRial}rial");

        $description = "خرید {$credits} واحد اعتبار ELLSMS";
        $create = payment_gateway_create($gateway, $totalAmountRial, $paymentId, $description, (string)($me['mobile'] ?? ''));

        if ($create['ok']) {
            db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
            audit((int)$me['id'], 'payment.request', "#{$paymentId} {$credits}cr {$totalAmountRial}rial gateway={$gateway}");
            redirect(payment_gateway_redirect_url($gateway, $create['authority']));
        } else {
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            audit((int)$me['id'], 'payment.gateway_init_failed', "payment=#{$paymentId} gateway={$gateway}");
            flash('error', 'شروع پرداخت ممکن نشد. فاکتور پرداخت‌نشده ثبت شد و می‌توانید از بخش فاکتورها دوباره پرداخت را انجام دهید.');
        }
    }
}

$pst = db()->prepare('SELECT * FROM ellsms_payments WHERE user_id=? ORDER BY id DESC LIMIT 30');
$pst->execute([$me['id']]);
$payments = $pst->fetchAll();
$statusFa = ['pending' => 'در انتظار', 'verification_failed' => 'در حال بررسی مجدد', 'paid' => 'موفق', 'failed' => 'ناموفق'];

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'billing.payment';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>خرید اعتبار</h2>
    <p class="hint">اعتبار فعلی شما: <strong class="ltr"><?= to_persian_digits(number_format((int)$me['credit'])) ?></strong> — هر واحد اعتبار <?= to_persian_digits(number_format($rialPerCredit)) ?> ریال.</p>
    <p class="hint">به مبلغ خرید <?= to_persian_digits((string)$taxPercent) ?>٪ ارزش افزوده اضافه می‌شود.</p>

    <?php if ($packages): ?>
    <div class="toolbar" style="margin-bottom:16px">
      <?php foreach ($packages as $p): ?>
        <button type="button" class="btn btn-sm" onclick="pickPackage(<?= $p ?>)"><?= to_persian_digits(number_format($p)) ?> واحد</button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <label>تعداد واحد اعتبار
        <input type="number" name="credits" id="creditsInput" min="<?= $minPurchase ?>" value="<?= $minPurchase ?>" oninput="updatePrice()" required>
        <div class="hint">حداقل: <?= to_persian_digits(number_format($minPurchase)) ?> واحد</div>
      </label>
      <div class="stat" style="margin-bottom:10px">
        <div class="stat-label">مبلغ قبل از ارزش افزوده</div>
        <div class="stat-value" id="subtotalOut">۰ ریال</div>
      </div>
      <div class="stat" style="margin-bottom:10px">
        <div class="stat-label">ارزش افزوده <?= to_persian_digits((string)$taxPercent) ?>٪</div>
        <div class="stat-value" id="taxOut">۰ ریال</div>
      </div>
      <div class="stat stat-accent" style="margin-bottom:16px">
        <div class="stat-label">مبلغ قابل پرداخت</div>
        <div class="stat-value" id="priceOut">۰ ریال</div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">پرداخت با زرین‌پال</button>
    </form>
  </div>

  <div class="card">
    <h2>تاریخچه‌ی پرداخت‌ها</h2>
    <div class="table-wrap">
    <table>
      <tr><th>واحد اعتبار</th><th>مبلغ (ریال)</th><th>وضعیت</th><th>کد پیگیری</th><th>تاریخ</th></tr>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="num"><?= to_persian_digits(number_format($p['credits'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format($p['amount_rial'])) ?></td>
          <td><span class="badge badge-<?= $p['status'] === 'paid' ? 'ok' : (in_array($p['status'], ['pending', 'verification_failed'], true) ? 'pending' : 'off') ?>"><?= e($statusFa[$p['status']]) ?></span></td>
          <td class="num"><?= e((string)($p['ref_id'] ?: '—')) ?></td>
          <td class="num"><?= jdate($p['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="5" class="empty">هنوز پرداختی ثبت نشده.</td></tr><?php endif; ?>
    </table>
    </div>
  </div>
</div>

<script>
const rialPerCredit = <?= $rialPerCredit ?>;
const taxPercent = <?= $taxPercent ?>;
function faDigits(s) { return String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
function money(n) { return faDigits(Math.max(0, n).toLocaleString('en-US')) + ' ریال'; }
function updatePrice() {
  const credits = parseInt(document.getElementById('creditsInput').value || '0', 10);
  const subtotal = Math.max(0, credits) * rialPerCredit;
  const tax = Math.floor(subtotal * taxPercent / 100);
  document.getElementById('subtotalOut').textContent = money(subtotal);
  document.getElementById('taxOut').textContent = money(tax);
  document.getElementById('priceOut').textContent = money(subtotal + tax);
}
function pickPackage(n) {
  document.getElementById('creditsInput').value = n;
  updatePrice();
}
updatePrice();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
