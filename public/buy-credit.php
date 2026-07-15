<?php
require_once __DIR__ . '/../app/zarinpal.php';
$me = require_login();
$pageTitle = 'خرید اعتبار';
$active = 'buy_credit';

$rialPerCredit = (int)setting('rial_per_credit', '1000');
$minPurchase   = (int)setting('min_credit_purchase', '100');
$packages      = array_filter(array_map('intval', explode(',', (string)setting('credit_packages', ''))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $credits = max(0, (int)($_POST['credits'] ?? 0));

    if ($credits < $minPurchase) {
        flash('error', 'حداقل میزان خرید ' . to_persian_digits(number_format($minPurchase)) . ' واحد اعتبار است.');
    } else {
        $amountRial = $credits * $rialPerCredit;
        db()->prepare('INSERT INTO ellsms_payments (user_id, credits, amount_rial) VALUES (?,?,?)')
           ->execute([$me['id'], $credits, $amountRial]);
        $paymentId = (int)db()->lastInsertId();

        $description = "خرید {$credits} واحد اعتبار ELLSMS";
        [$ok, $info, $authority] = zarinpal_request($amountRial, $paymentId, $description, (string)($me['mobile'] ?? ''));

        if ($ok) {
            db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$authority, $paymentId]);
            audit((int)$me['id'], 'payment.request', "#{$paymentId} {$credits}cr {$amountRial}rial");
            redirect(zarinpal_start_pay_url($authority));
        } else {
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            flash('error', 'شروع پرداخت ممکن نشد: ' . $info);
        }
    }
}

$pst = db()->prepare('SELECT * FROM ellsms_payments WHERE user_id=? ORDER BY id DESC LIMIT 30');
$pst->execute([$me['id']]);
$payments = $pst->fetchAll();

$statusFa = ['pending' => 'در انتظار', 'paid' => 'موفق', 'failed' => 'ناموفق'];

require __DIR__ . '/../app/views/header.php';
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
          <td><span class="badge badge-<?= $p['status'] === 'paid' ? 'ok' : ($p['status'] === 'pending' ? 'pending' : 'off') ?>"><?= e($statusFa[$p['status']]) ?></span></td>
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
