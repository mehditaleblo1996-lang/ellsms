<?php
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'خرید اعتبار';
$active = 'buy_credit';

if (!is_admin()) {
    require_permission(Permissions::WALLET_VIEW);
    require_permission(Permissions::PAYMENTS_VIEW);
}

// The wallet is Rial-denominated: the amount bought is the Rial added to the wallet, before VAT.
$minPurchase   = (int)setting('min_credit_purchase', '100000');
$packages      = array_filter(array_map('intval', explode(',', (string)setting('credit_packages', ''))));
$taxPercent    = billing_tax_percent();

// Online (the payment gateway) is always offered; the manual methods are the ones the platform — or
// this customer's reseller — accepts (app/ManualPayments.php).
$manualOptions = manual_payment_options_for((int)$me['id']);
$methods = ['online' => 'پرداخت آنلاین'] + array_intersect_key(MANUAL_PAYMENT_METHODS, $manualOptions);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$method = (string)($isPost ? ($_POST['method'] ?? 'online') : ($_GET['method'] ?? 'online'));
if (!isset($methods[$method])) $method = 'online';

if ($isPost) {
    csrf_check();
    if (impersonation_guard_post('billing.payment')) {
        redirect('/buy-credit.php');
    }
    $credits = max(0, (int)str_replace([',', '٬'], '', from_persian_digits((string)($_POST['credits'] ?? '0'))));

    if ($credits < $minPurchase) {
        flash('error', 'حداقل مبلغ خرید ' . to_persian_digits(number_format($minPurchase)) . ' ریال است.');
    } elseif (($_POST['accept_refund_terms'] ?? '') !== '1') {
        flash('error', 'برای پرداخت، پذیرش قوانین و شرایط انصراف و بازگشت وجه لازم است.');
    } elseif ($method !== 'online') {
        $kind = MANUAL_PAYMENT_DESTINATION_KIND[$method] ?? null;
        $input = [
            'destination_id' => $kind !== null ? ($_POST['destination_' . $kind] ?? 0) : 0,
            'paid_on'        => jalali_request_to_gregorian('paid_on'),
            'cheque_due_on'  => jalali_request_to_gregorian('cheque_due'),
        ] + $_POST;
        $result = manual_payment_submit($me, $method, $credits, $input, $_FILES['attachment'] ?? null);
        if ($result['ok']) {
            flash('success', 'پرداخت شما ثبت شد و پس از بررسی و تأیید، ' . to_persian_digits(number_format($credits)) . ' ریال به اعتبار شما اضافه می‌شود. وضعیت آن را در فاکتور ' . $result['invoice_number'] . ' ببینید.');
            redirect('/invoices.php?id=' . (int)$result['invoice_id']);
        }
        flash('error', manual_payment_error_message($result['reason']));
    } else {
        $baseAmountRial = $credits;
        $gateway = payment_gateway_name();
        db()->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,?)')
           ->execute([$me['id'], $me['organization_id'] ?? null, $credits, $baseAmountRial, $gateway]);
        $paymentId = (int)db()->lastInsertId();

        // Issue the invoice before contacting the provider. VAT is computed centrally by
        // billing_invoice_create(); payment_gateway_create() then charges this exact immutable total.
        $invoiceResult = billing_invoice_create($paymentId, $me['organization_id'] ?? null, (int)$me['id'], 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null,
            'description' => 'خرید ' . number_format($credits) . ' ریال اعتبار پیامک', 'quantity' => 1, 'unit_price' => $baseAmountRial,
        ]]);
        if (!$invoiceResult['ok']) {
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            flash('error', 'صدور فاکتور ممکن نشد. پرداخت شروع نشد.');
            redirect('/buy-credit.php');
        }
        $totalAmountRial = (int)$invoiceResult['total_amount'];
        audit((int)$me['id'], 'invoice.issued_for_credit_purchase', "payment=#{$paymentId} credit={$credits}rial total={$totalAmountRial}rial");

        $description = 'خرید ' . number_format($credits) . ' ریال اعتبار داتیس رایان گستر ایرانیان';
        $create = payment_gateway_create($gateway, $totalAmountRial, $paymentId, $description, (string)($me['mobile'] ?? ''));

        if ($create['ok']) {
            db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
            audit((int)$me['id'], 'payment.request', "#{$paymentId} credit={$credits}rial paid={$totalAmountRial}rial gateway={$gateway}");
            redirect(payment_gateway_redirect_url($gateway, $create['authority']));
        } else {
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            audit((int)$me['id'], 'payment.gateway_init_failed', "payment=#{$paymentId} gateway={$gateway}");
            flash('error', 'شروع پرداخت ممکن نشد. فاکتور پرداخت‌نشده ثبت شد و می‌توانید از بخش فاکتورها دوباره پرداخت را انجام دهید.');
        }
    }
}

// A failed POST re-renders the form with what the customer typed.
$old = static fn(string $key, string $default = ''): string => $isPost ? (string)($_POST[$key] ?? $default) : $default;
$creditsValue = $isPost ? max(0, (int)from_persian_digits((string)($_POST['credits'] ?? '0'))) : $minPurchase;

$pst = db()->prepare('SELECT * FROM ellsms_payments WHERE user_id=? ORDER BY id DESC LIMIT 30');
$pst->execute([$me['id']]);
$payments = $pst->fetchAll();
$manualRows = manual_payments_for_payments(array_column($payments, 'id'));
$statusFa = ['pending' => 'در انتظار', 'verification_failed' => 'در حال بررسی مجدد', 'paid' => 'موفق', 'failed' => 'ناموفق'];

$methodIcons = [
    'online'  => '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="30" cy="24" r="13" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M17 24h26M30 11c-5 6-5 20 0 26M30 11c5 6 5 20 0 26" fill="none" stroke="currentColor" stroke-width="2"/><rect x="4" y="18" width="20" height="13" rx="2.5" fill="currentColor" opacity=".85"/><path d="M4 22h20" stroke="#fff" stroke-width="2"/></svg>',
    'card'    => '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="3" y="9" width="30" height="20" rx="3" fill="currentColor" opacity=".85"/><path d="M3 15h30" stroke="#fff" stroke-width="3"/><rect x="15" y="20" width="30" height="20" rx="3" fill="#fff" stroke="currentColor" stroke-width="2.2"/><path d="M20 30h9M37 34h4" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
    'account' => '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="4" y="5" width="26" height="16" rx="2.5" fill="currentColor" opacity=".85"/><path d="M4 10h26" stroke="#fff" stroke-width="2.5"/><rect x="18" y="27" width="26" height="16" rx="2.5" fill="#fff" stroke="currentColor" stroke-width="2.2"/><path d="M34 13h6v8m-3-3 3 3 3-3M14 35H8v-8m-3 3 3-3 3 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'cash'    => '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="4" y="10" width="40" height="28" rx="2" fill="none" stroke="currentColor" stroke-width="2.4"/><rect x="20" y="6" width="8" height="36" rx="1.5" fill="currentColor" opacity=".85"/><path d="M8 14h6M8 34h6M34 14h6M34 34h6" stroke="currentColor" stroke-width="2"/></svg>',
    'cheque'  => '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="12" y="10" width="33" height="26" rx="2.5" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M18 18h20M18 24h20M18 30h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M6 6v26l2 5 2-5V6z" fill="currentColor" opacity=".85"/></svg>',
];
$bankOptions = static function (string $selected): string {
    $h = '<option value="">انتخاب بانک</option>';
    foreach (MANUAL_PAYMENT_BANKS as $key => $label) {
        $h .= '<option value="' . e($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
};

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'billing.payment';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<form method="post" enctype="multipart/form-data" class="card pay-form" id="payForm">
  <?= csrf_field() ?>
  <h2 class="pay-title">نوع پرداخت</h2>
  <div class="pay-methods" role="radiogroup" aria-label="نوع پرداخت">
    <?php foreach ($methods as $key => $label): ?>
      <label class="pay-method">
        <input type="radio" name="method" value="<?= e($key) ?>" <?= $key === $method ? 'checked' : '' ?>>
        <span class="pay-method-box"><span class="pay-method-icon"><?= $methodIcons[$key] ?></span><span><?= e($label) ?></span></span>
      </label>
    <?php endforeach; ?>
  </div>
  <?php if (is_admin() && ($hiddenMethods = manual_payment_hidden_reasons()) !== []): ?>
  <div class="flash flash-info no-print">
    <?php if (isset($hiddenMethods['*'])): ?><?= e($hiddenMethods['*']) ?>
    <?php else: ?>این روش‌ها به کاربران نمایش داده نمی‌شوند:
      <?php foreach ($hiddenMethods as $hm => $why): ?><strong><?= e(MANUAL_PAYMENT_METHODS[$hm]) ?></strong> (<?= e($why) ?>)<?= $hm !== array_key_last($hiddenMethods) ? '، ' : '' ?><?php endforeach; ?>.
      <a href="/admin/billing/manual-payments?tab=settings">تعریف کارت‌ها و حساب‌ها و فعال‌سازی روش‌ها</a>
    <?php endif; ?>
    <div class="hint">این پیام فقط برای مدیر نمایش داده می‌شود.</div>
  </div>
  <?php endif; ?>
  <hr class="pay-sep">

  <p class="hint">اعتبار فعلی شما: <strong class="ltr"><?= to_persian_digits(number_format((int)$me['credit'])) ?></strong> ریال. به مبلغ خرید <?= to_persian_digits((string)$taxPercent) ?>٪ ارزش افزوده اضافه می‌شود.</p>

  <?php if ($packages): ?>
  <div class="toolbar" style="margin-bottom:16px">
    <?php foreach ($packages as $p): ?>
      <button type="button" class="btn btn-sm" onclick="pickPackage(<?= $p ?>)"><?= to_persian_digits(number_format($p)) ?> ریال</button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pay-grid">
    <label>مبلغ اعتبار (ریال) *
      <input type="number" name="credits" id="creditsInput" min="<?= $minPurchase ?>" value="<?= $creditsValue ?>" oninput="updatePrice()" required>
      <div class="hint">حداقل: <?= to_persian_digits(number_format($minPurchase)) ?> ریال — همین مبلغ به اعتبار شما اضافه می‌شود. <span id="creditsWords"></span></div>
    </label>
    <div class="stat"><div class="stat-label">مبلغ قبل از ارزش افزوده</div><div class="stat-value" id="subtotalOut">۰ ریال</div></div>
    <div class="stat"><div class="stat-label">ارزش افزوده <?= to_persian_digits((string)$taxPercent) ?>٪</div><div class="stat-value" id="taxOut">۰ ریال</div></div>
    <div class="stat stat-accent"><div class="stat-label" id="priceLabel">مبلغ قابل پرداخت</div><div class="stat-value" id="priceOut">۰ ریال</div></div>
  </div>

  <section data-section="online">
    <p class="hint">درگاه پرداخت را انتخاب کنید:</p>
    <div class="pay-methods pay-gateways">
      <label class="pay-method"><input type="radio" name="gateway_choice" value="zarinpal" checked><span class="pay-method-box"><span class="pay-method-icon"><?= $methodIcons['online'] ?></span><span>زرین‌پال</span></span></label>
    </div>
  </section>

  <section data-section="manual" hidden>
    <div class="flash flash-info pay-callout" id="payCallout"></div>
    <div class="pay-grid">
      <?php foreach (['card' => 'شماره کارت', 'account' => 'شماره حساب'] as $kind => $kindLabel): ?>
        <?php $destinations = []; foreach ($manualOptions as $m => $option) { if ((MANUAL_PAYMENT_DESTINATION_KIND[$m] ?? null) === $kind) { foreach ($option['destinations'] as $d) $destinations[(int)$d['id']] = $d; } } ?>
        <?php if ($destinations): ?>
        <label data-section="dest-<?= $kind ?>" hidden><?= $kindLabel ?> *
          <select name="destination_<?= $kind ?>" data-required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($destinations as $d): ?>
              <option value="<?= (int)$d['id'] ?>" data-owner="<?= (int)$d['owner_user_id'] ?>" <?= $old('destination_' . $kind) === (string)$d['id'] ? 'selected' : '' ?>><?= e(to_persian_digits(manual_payment_destination_label($d))) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      <?php endforeach; ?>
      <label>شماره سند / پیگیری *
        <input type="text" name="document_number" maxlength="64" value="<?= e($old('document_number')) ?>" data-required autocomplete="off">
      </label>
      <label>تاریخ پرداخت *
        <?= jalali_date_select('paid_on', $isPost ? jalali_request_to_gregorian('paid_on') : null, 0, 1) ?>
      </label>
      <label>ساعت پرداخت *
        <input type="time" name="paid_time" value="<?= e($old('paid_time', date('H:i'))) ?>" data-required>
      </label>
    </div>

    <div data-section="cheque" hidden>
      <h3>مشخصات چک</h3>
      <div class="pay-grid">
        <label>مبلغ چک (ریال) *
          <input type="number" name="cheque_amount" id="chequeAmount" min="1" value="<?= e($old('cheque_amount')) ?>" data-required>
          <div class="hint">باید برابر مبلغ قابل پرداخت باشد.</div>
        </label>
        <label>بانک *<select name="cheque_bank" data-required><?= $bankOptions($old('cheque_bank')) ?></select></label>
        <label>کد شعبه *<input type="text" name="cheque_branch_code" inputmode="numeric" maxlength="10" value="<?= e($old('cheque_branch_code')) ?>" data-required></label>
        <label>تاریخ سررسید *<?= jalali_date_select('cheque_due', $isPost ? jalali_request_to_gregorian('cheque_due') : null, 3, 1, true) ?></label>
        <label>نام صاحب حساب *<input type="text" name="cheque_owner_name" maxlength="120" value="<?= e($old('cheque_owner_name')) ?>" data-required></label>
        <label>کدملی / شناسه ملی *<input type="text" name="cheque_owner_national_id" inputmode="numeric" maxlength="11" class="ltr" value="<?= e($old('cheque_owner_national_id')) ?>" data-required></label>
        <label>شماره شبا صاحب چک *<input type="text" name="cheque_owner_sheba" maxlength="34" class="ltr" placeholder="IR" value="<?= e($old('cheque_owner_sheba', 'IR')) ?>" data-required></label>
        <label>شماره صیادی *<input type="text" name="cheque_sayad_number" inputmode="numeric" maxlength="16" class="ltr" value="<?= e($old('cheque_sayad_number')) ?>" data-required></label>
        <label>شماره سریال *<input type="text" name="cheque_serial" inputmode="numeric" maxlength="20" class="ltr" value="<?= e($old('cheque_serial')) ?>" data-required></label>
        <label>شماره سری *<input type="text" name="cheque_series" maxlength="20" class="ltr" value="<?= e($old('cheque_series')) ?>" data-required></label>
      </div>
    </div>

    <div class="pay-grid">
      <label data-section="attachment" hidden><span id="attachmentLabel">تصویر پرداخت</span>
        <input type="file" name="attachment" id="attachmentInput" accept="image/jpeg,image/png,image/webp">
        <div class="hint">JPG، PNG یا WebP، حداکثر ۳ مگابایت.</div>
      </label>
      <label class="pay-wide">توضیحات
        <textarea name="description" rows="2" maxlength="500"><?= e($old('description')) ?></textarea>
      </label>
    </div>
  </section>

  <label class="consent-check" style="margin:14px 0">
    <input type="checkbox" name="accept_refund_terms" value="1" required>
    <span><a href="/terms" target="_blank" rel="noopener">قوانین و مقررات</a> و <a href="/refund" target="_blank" rel="noopener">شرایط انصراف و بازگشت وجه</a> را خوانده‌ام و می‌پذیرم.</span>
  </label>
  <button type="submit" class="btn btn-primary pay-submit" id="paySubmit">پرداخت</button>
</form>

<div class="card">
  <h2>تاریخچه‌ی پرداخت‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>روش</th><th>اعتبار (ریال)</th><th>مبلغ پرداختی (ریال)</th><th>وضعیت</th><th>کد پیگیری / سند</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($payments as $p): $manual = $manualRows[(int)$p['id']] ?? null; ?>
      <?php
        if ($manual !== null && $manual['review_state'] === 'pending') { $label = 'در انتظار بررسی'; $badge = 'review'; }
        elseif ($manual !== null && $manual['review_state'] === 'rejected') { $label = 'ردشده'; $badge = 'off'; }
        else { $label = $statusFa[$p['status']] ?? $p['status']; $badge = $p['status'] === 'paid' ? 'ok' : (in_array($p['status'], ['pending', 'verification_failed'], true) ? 'pending' : 'off'); }
      ?>
      <tr>
        <td><?= e(manual_payment_method_label((string)($p['gateway'] ?? 'zarinpal'))) ?></td>
        <td class="num"><?= to_persian_digits(number_format($p['credits'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($p['amount_rial'])) ?></td>
        <td><span class="badge badge-<?= $badge ?>"><?= e($label) ?></span>
          <?php if ($manual !== null && $manual['review_state'] === 'rejected' && $manual['review_note']): ?><div class="hint">دلیل: <?= e($manual['review_note']) ?></div><?php endif; ?></td>
        <td class="num ltr"><?= e((string)($manual['document_number'] ?? ($p['ref_id'] ?: '—'))) ?></td>
        <td class="num"><?= jdate($p['created_at']) ?></td>
        <td><?php if (!empty($p['invoice_id'])): ?><a class="btn btn-sm" href="/invoices.php?id=<?= (int)$p['invoice_id'] ?>">فاکتور</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="7" class="empty">هنوز پرداختی ثبت نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<script>
const taxPercent = <?= $taxPercent ?>;
const manualMethods = <?= json_encode(array_keys($manualOptions)) ?>;
const destinationKind = <?= json_encode(MANUAL_PAYMENT_DESTINATION_KIND) ?>;
function faDigits(s) { return String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
function money(n) { return faDigits(Math.max(0, n).toLocaleString('en-US')) + ' ریال'; }
function faWords(n) {
  n = Math.floor(n);
  if (n <= 0) return 'صفر';
  const ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
  const teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
  const tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
  const hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
  const scales = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];
  const three = x => {
    const parts = [], h = Math.floor(x / 100), r = x % 100;
    if (h) parts.push(hundreds[h]);
    if (r >= 10 && r < 20) parts.push(teens[r - 10]);
    else { if (Math.floor(r / 10)) parts.push(tens[Math.floor(r / 10)]); if (r % 10) parts.push(ones[r % 10]); }
    return parts.join(' و ');
  };
  const out = [];
  for (let i = 0; n > 0 && i < scales.length; i++, n = Math.floor(n / 1000)) {
    if (n % 1000) out.unshift(three(n % 1000) + (scales[i] ? ' ' + scales[i] : ''));
  }
  return out.join(' و ');
}
function payable() {
  const subtotal = Math.max(0, parseInt(document.getElementById('creditsInput').value || '0', 10));
  const tax = Math.floor(subtotal * taxPercent / 100);
  return { subtotal, tax, total: subtotal + tax };
}
function currentMethod() { return (document.querySelector('input[name="method"]:checked') || {}).value || 'online'; }
function updatePrice() {
  const p = payable();
  document.getElementById('subtotalOut').textContent = money(p.subtotal);
  document.getElementById('taxOut').textContent = money(p.tax);
  document.getElementById('priceOut').textContent = money(p.total);
  document.getElementById('creditsWords').textContent = '(' + faWords(p.subtotal / 10) + ' تومان)';
  const method = currentMethod();
  const callout = document.getElementById('payCallout');
  if (method === 'cash') callout.textContent = 'مبلغ ' + money(p.total) + ' را نقدی پرداخت کنید و شماره سند/رسید را وارد کنید. اعتبار پس از تأیید اضافه می‌شود.';
  else if (method === 'cheque') callout.textContent = 'چکی به مبلغ ' + money(p.total) + ' صادر کنید و مشخصات و تصویر آن را وارد کنید. اعتبار پس از تأیید اضافه می‌شود.';
  else callout.textContent = 'مبلغ ' + money(p.total) + ' را به ' + (method === 'card' ? 'کارت' : 'حساب') + ' انتخاب‌شده واریز کنید و مشخصات واریز را وارد کنید. اعتبار پس از تأیید اضافه می‌شود.';
  const cheque = document.getElementById('chequeAmount');
  if (cheque && method === 'cheque' && !cheque.dataset.touched) cheque.value = p.total;
}
function showSection(el, on) {
  el.hidden = !on;
  el.querySelectorAll('input, select, textarea').forEach(f => {
    f.disabled = !on;
    if (f.hasAttribute('data-required')) f.required = on;
  });
}
function applyMethod() {
  const method = currentMethod();
  const manual = manualMethods.includes(method);
  const form = document.getElementById('payForm');
  showSection(form.querySelector('[data-section="online"]'), !manual);
  showSection(form.querySelector('[data-section="manual"]'), manual);
  if (manual) {
    form.querySelectorAll('[data-section^="dest-"]').forEach(el => showSection(el, el.dataset.section === 'dest-' + destinationKind[method]));
    showSection(form.querySelector('[data-section="cheque"]'), method === 'cheque');
    showSection(form.querySelector('[data-section="attachment"]'), method !== 'cash');
    document.getElementById('attachmentLabel').textContent = method === 'cheque' ? 'تصویر چک *' : 'تصویر پرداخت';
    document.getElementById('attachmentInput').required = method === 'cheque';
  }
  document.getElementById('priceLabel').textContent = manual ? 'مبلغ قابل واریز' : 'مبلغ قابل پرداخت';
  document.getElementById('paySubmit').textContent = manual ? 'ثبت پرداخت' : 'پرداخت';
  updatePrice();
}
function pickPackage(n) {
  document.getElementById('creditsInput').value = n;
  updatePrice();
}
document.querySelectorAll('input[name="method"]').forEach(r => r.addEventListener('change', applyMethod));
const chequeAmountInput = document.getElementById('chequeAmount');
if (chequeAmountInput) chequeAmountInput.addEventListener('input', () => { chequeAmountInput.dataset.touched = '1'; });
applyMethod();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
