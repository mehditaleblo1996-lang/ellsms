<?php
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'خرید اعتبار';
$active = 'buy_credit';

// Phase 7: platform admins keep their existing unrestricted bypass; an ordinary org member needs
// WALLET_VIEW (this page shows their own balance) and PAYMENTS_VIEW (their own payment history) —
// both granted to every built-in role by default today (app/rbac.php). Purchasing a new payment
// (the POST branch below) is deliberately NOT gated behind WALLET_ADJUST — that permission is
// reserved for MANUAL credit adjustment (app/wallet.php's wallet_manual_adjustment(), platform-admin
// only via public/users.php), a completely different action from a user spending their own money to
// buy their own credit; STEP 18's own instruction is "payments.create... depending on current
// product behavior" — this codebase's existing behavior already lets any logged-in user purchase
// credit, so PAYMENTS_VIEW (read) is what this phase adds explicit enforcement for, matching every
// other page's read/write split, without introducing a new restriction on the purchase action itself.
if (!is_admin()) {
    require_permission(Permissions::WALLET_VIEW);
    require_permission(Permissions::PAYMENTS_VIEW);
}

$rialPerCredit = (int)setting('rial_per_credit', '1000');
$minPurchase   = (int)setting('min_credit_purchase', '100');
$packages      = array_filter(array_map('intval', explode(',', (string)setting('credit_packages', ''))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Never start a payment on a customer's behalf (STEP 27).
    if (impersonation_guard_post('billing.payment')) {
        redirect('/buy-credit.php');
    }
    $credits = max(0, (int)($_POST['credits'] ?? 0));

    if ($credits < $minPurchase) {
        flash('error', 'حداقل میزان خرید ' . to_persian_digits(number_format($minPurchase)) . ' واحد اعتبار است.');
    } else {
        $amountRial = $credits * $rialPerCredit;
        $gateway = payment_gateway_name();
        // Phase 6 closure: organization_id is persisted at creation time from the PURCHASING
        // user's server-resolved organization (require_login()/current_organization() — never from
        // request input) — payment_claim_and_credit() and the reconciliation job both read this
        // persisted value later; neither re-derives organization from whatever the browser session
        // happens to be pointed at by the time the gateway calls back, which could be long after this
        // request and after the user has switched their active organization.
        db()->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,?)')
           ->execute([$me['id'], $me['organization_id'] ?? null, $credits, $amountRial, $gateway]);
        $paymentId = (int)db()->lastInsertId();

        // FIN-4: issue the invoice at PURCHASE INTENT, before contacting the payment gateway.
        // This deliberately leaves an `issued` (unpaid) invoice even when gateway initialization
        // fails, so the customer has a durable accounting document and can retry payment later from
        // /invoices.php against this SAME payment row. The amount is entirely server-derived from
        // the validated credit quantity and configured unit price; no client-supplied price exists.
        billing_invoice_create($paymentId, $me['organization_id'] ?? null, (int)$me['id'], 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null,
            'description' => "خرید {$credits} واحد اعتبار پیامک", 'quantity' => 1, 'unit_price' => $amountRial,
        ]]);
        audit((int)$me['id'], 'invoice.issued_for_credit_purchase', "payment=#{$paymentId} {$credits}cr {$amountRial}rial");

        $description = "خرید {$credits} واحد اعتبار ELLSMS";
        $create = payment_gateway_create($gateway, $amountRial, $paymentId, $description, (string)($me['mobile'] ?? ''));

        if ($create['ok']) {
            db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
            audit((int)$me['id'], 'payment.request', "#{$paymentId} {$credits}cr {$amountRial}rial gateway={$gateway}");
            redirect(payment_gateway_redirect_url($gateway, $create['authority']));
        } else {
            // Keep the invoice issued/unpaid. invoices.php already allows retrying an issued invoice
            // whose payment row is failed/pending/verification_failed, and reuses this same payment
            // rather than generating duplicate invoices.
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
function faDigits(s) { return String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
function updatePrice() {
  const credits = parseInt(document.getElementById('creditsInput').value || '0', 10);
  const price = Math.max(0, credits) * rialPerCredit;
  document.getElementById('priceOut').textContent = faDigits(price.toLocaleString('en-US')) + ' ریال';
}
function pickPackage(n) {
  document.getElementById('creditsInput').value = n;
  updatePrice();
}
updatePrice();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>