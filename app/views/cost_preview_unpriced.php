<?php
/**
 * Shown INSTEAD of the confirmation card when some recipients have no configured tariff (STEP 44).
 *
 * Expects:
 *   $costPricingFailure — a failed estimate array whose reason is 'pricing_unavailable'
 *
 * There is deliberately NO confirm button here. The total cost of this send is genuinely unknown,
 * and this product does not silently drop the unpriceable recipients and send the rest: doing so
 * would mean the user confirmed one audience and paid for another. The send path enforces the same
 * refusal server-side (sms_pricing_price_messages() returns ok=false), so this card is an
 * explanation, never the enforcement.
 */
$f = $costPricingFailure['pricing_failure'] ?? ['priced_count' => 0, 'unpriced_count' => 0, 'reasons' => []];
$r = $costPricingFailure['recipients'] ?? [];
?>
<div class="modal-overlay is-open" id="sendUnpricedOverlay" role="dialog" aria-modal="true" aria-labelledby="sendUnpricedTitle">
<div class="modal-dialog">
<div class="card" style="border:2px solid #c0392b">
  <h2 id="sendUnpricedTitle">ارسال ممکن نیست — تعرفه‌ی بخشی از گیرندگان مشخص نیست</h2>

  <div class="table-wrap">
  <table>
    <tr><th>گیرندگان واردشده</th><td class="num"><?= to_persian_digits(number_format((int)($r['input_count'] ?? 0))) ?></td></tr>
    <tr><th>گیرندگان قابل ارسال</th><td class="num"><?= to_persian_digits(number_format((int)($r['eligible_count'] ?? 0))) ?></td></tr>
    <tr><th>دارای تعرفه</th><td class="num"><?= to_persian_digits(number_format((int)$f['priced_count'])) ?></td></tr>
    <tr><th><strong>بدون تعرفه</strong></th><td class="num" style="color:#c0392b"><strong><?= to_persian_digits(number_format((int)$f['unpriced_count'])) ?></strong></td></tr>
  </table>
  </div>

  <?php if ($f['reasons']): ?>
    <h3 style="margin-top:14px">دلیل</h3>
    <div class="table-wrap">
    <table>
      <tr><th>دلیل</th><th>تعداد گیرنده</th><th>کد</th></tr>
      <?php foreach ($f['reasons'] as $reason => $count): ?>
        <tr>
          <td><?= e(cost_pricing_reason_message((string)$reason)) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$count)) ?></td>
          <td class="ltr"><code><?= e((string)$reason) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  <?php endif; ?>

  <p class="hint">
    تا زمانی که هزینه‌ی همه‌ی گیرندگان مشخص نباشد، این ارسال انجام نمی‌شود و هیچ مبلغی از اعتبار شما کسر نمی‌شود.
    برای رفع مشکل، از مدیر سامانه بخواهید برای مسیر ارسال این خط، تعرفه‌ی اپراتور یا تعرفه‌ی پیش‌فرض مسیر را تعریف کند.
  </p>

  <div class="toolbar" style="margin-top:12px">
    <button type="button" class="btn" id="sendUnpricedClose">متوجه شدم — ویرایش</button>
  </div>
</div>
</div>
</div>
<script>
(function () {
  var overlay = document.getElementById('sendUnpricedOverlay');
  var close   = document.getElementById('sendUnpricedClose');
  if (!overlay) return;
  function hide() { overlay.classList.remove('is-open'); }
  if (close) close.addEventListener('click', hide);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) hide();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hide();
  });
})();
</script>
