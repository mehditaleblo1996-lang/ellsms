<?php
/**
 * Shared cost-preview confirmation card (STEP 14/15/37).
 *
 * Expects:
 *   $costPreview  — a successful estimate array from app/Cost/MessageCostEstimator.php
 *   $previewFormFields — HTML string of hidden inputs carrying the original submission forward,
 *                        so confirming resubmits the IDENTICAL inputs the estimate was computed from
 *
 * Renders read-only figures only; the confirm button posts back to the same page, where every value
 * is recomputed server-side. Nothing here is authoritative (Invariant D: UI is not authorization).
 */
$cp = $costPreview;
$rialPerCredit = (int)($cp['pricing']['rial_per_credit'] ?? 0);
$estimatedCost = (int)$cp['pricing']['estimated_cost'];
$rialTotal = $estimatedCost * $rialPerCredit;
$walletOk = (bool)$cp['wallet']['sufficient'];
$quotaOk  = (bool)$cp['quota']['sufficient'];
$canSend  = $walletOk && $quotaOk;
$dist = $cp['segments']['distribution'] ?? [];
$isExempt = ($cp['pricing']['price_source'] ?? '') === 'admin_exempt';
$unitPrice = $cp['pricing']['credits_per_segment'];
$unitMin = ($cp['pricing']['unit_price_min_millicredits'] ?? 0) / 1000;
$unitMax = ($cp['pricing']['unit_price_max_millicredits'] ?? 0) / 1000;
$groups = $cp['pricing']['groups'] ?? [];
$operatorLabels = [];
foreach ($groups as $g) {
    $operatorLabels[$g['operator']] = $g['operator_name'] !== '' ? $g['operator_name'] : $g['operator'];
}
?>
<div class="modal-overlay is-open" id="sendConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="sendConfirmTitle">
<div class="modal-dialog">
<div class="card" style="border:2px solid <?= $canSend ? '#2d7a4f' : '#c0392b' ?>">
  <h2 id="sendConfirmTitle">خلاصه‌ی ارسال — پیش از تأیید</h2>

  <?php
    $previewMode = (string)($_POST['mode'] ?? 'now');
    $previewIsScheduled = in_array($previewMode, ['later', 'recurring'], true);
    $previewScheduleAt = null;
    if ($previewIsScheduled) {
        $pgDate = jalali_request_to_gregorian('send_date');
        $ptime  = time_post('send_time');
        if ($pgDate && $ptime) {
            $previewScheduleAt = jdate("{$pgDate} {$ptime}:00");
        }
    }
  ?>
  <div class="table-wrap">
  <table>
    <tr><th>فرستنده</th><td class="ltr"><?= e((string)($cp['originator'] ?? '')) ?></td></tr>
    <tr><th>زمان ارسال</th><td>
      <?php if (!$previewIsScheduled): ?>
        ارسال فوری
      <?php elseif ($previewScheduleAt !== null): ?>
        زمان‌بندی‌شده — <?= e($previewScheduleAt) ?>
      <?php else: ?>
        زمان‌بندی‌شده
      <?php endif; ?>
    </td></tr>
    <tr><th>گیرندگان واردشده</th><td class="num"><?= to_persian_digits(number_format((int)$cp['recipients']['input_count'])) ?></td></tr>
    <?php if (($cp['recipients']['invalid_count'] ?? 0) > 0): ?>
      <tr><th>شماره‌ی نامعتبر (حذف شد)</th><td class="num"><?= to_persian_digits(number_format((int)$cp['recipients']['invalid_count'])) ?></td></tr>
    <?php endif; ?>
    <?php if (($cp['recipients']['duplicate_count'] ?? 0) > 0): ?>
      <tr><th>تکراری (حذف شد)</th><td class="num"><?= to_persian_digits(number_format((int)$cp['recipients']['duplicate_count'])) ?></td></tr>
    <?php endif; ?>
    <?php if (($cp['recipients']['blacklisted_count'] ?? 0) > 0): ?>
      <tr><th>در لیست سیاه (حذف شد)</th><td class="num"><?= to_persian_digits(number_format((int)$cp['recipients']['blacklisted_count'])) ?></td></tr>
    <?php endif; ?>
    <?php if (($cp['recipients']['empty_content_count'] ?? 0) > 0): ?>
      <tr><th>بدون متن (حذف شد)</th><td class="num"><?= to_persian_digits(number_format((int)$cp['recipients']['empty_content_count'])) ?></td></tr>
    <?php endif; ?>
    <tr><th><strong>گیرندگان قابل ارسال</strong></th><td class="num"><strong><?= to_persian_digits(number_format((int)$cp['recipients']['eligible_count'])) ?></strong></td></tr>

    <tr><th>نوع کدگذاری</th><td>
      <?= $cp['message']['encoding'] === 'unicode' ? 'فارسی / یونیکد' : 'لاتین (GSM-7)' ?>
      <span class="hint" style="display:inline">— هر پیامک تا <?= to_persian_digits((string)$cp['message']['single_segment_limit']) ?> نویسه</span>
    </td></tr>
    <?php if (($cp['message']['represents'] ?? '') === 'longest_item'): ?>
      <tr><th>طولانی‌ترین متن</th><td class="num"><?= to_persian_digits(number_format((int)$cp['message']['characters'])) ?> نویسه</td></tr>
    <?php else: ?>
      <tr><th>طول متن</th><td class="num"><?= to_persian_digits(number_format((int)$cp['message']['characters'])) ?> نویسه</td></tr>
      <tr><th>تعداد پیامک برای هر گیرنده</th><td class="num"><?= to_persian_digits((string)($cp['segments']['per_recipient'] ?? $cp['message']['segments'])) ?></td></tr>
    <?php endif; ?>

    <tr><th><strong>مجموع پیامک‌های محاسبه‌شده</strong></th><td class="num"><strong><?= to_persian_digits(number_format((int)$cp['segments']['total'])) ?></strong>
      <?php if (empty($cp['segments']['exact'])): ?>
        <span class="hint" style="display:inline">— تخمینی (بر پایه‌ی نمونه‌برداری)</span>
      <?php endif; ?>
    </td></tr>
  </table>
  </div>

  <?php if (count($dist) > 1): ?>
    <h3 style="margin-top:14px">توزیع تعداد پیامک</h3>
    <div class="table-wrap">
    <table>
      <tr><th>تعداد پیامک</th><th>گیرندگان</th></tr>
      <?php foreach ($dist as $segments => $count): ?>
        <tr>
          <td class="num"><?= to_persian_digits((string)$segments) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$count)) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <p class="hint">به دلیل متفاوت‌بودن طول پیام برای هر گیرنده، تعداد پیامک یکسان نیست.</p>
  <?php endif; ?>

  <h3 style="margin-top:14px">هزینه و اعتبار</h3>
  <div class="table-wrap">
  <table>
    <?php if ($isExempt): ?>
      <tr><td colspan="2">حساب مدیر — این ارسال از اعتبار کسر نمی‌شود.</td></tr>
    <?php else: ?>
      <tr><th>هزینه‌ی هر پیامک</th><td class="num">
        <?php if ($unitPrice !== null): ?>
          <?= to_persian_digits(rtrim(rtrim(number_format((float)$unitPrice, 3), '0'), '.')) ?> واحد اعتبار
        <?php else: ?>
          <?= to_persian_digits(rtrim(rtrim(number_format($unitMin, 3), '0'), '.')) ?>
          تا
          <?= to_persian_digits(rtrim(rtrim(number_format($unitMax, 3), '0'), '.')) ?>
          واحد اعتبار <span class="hint" style="display:inline">— بسته به اپراتور گیرنده</span>
        <?php endif; ?>
      </td></tr>
      <tr><th><strong>هزینه‌ی تقریبی</strong></th><td class="num"><strong><?= to_persian_digits(number_format($estimatedCost)) ?> واحد اعتبار</strong>
        <?php if ($rialPerCredit > 0): ?>
          <span class="hint" style="display:inline">≈ <?= to_persian_digits(number_format($rialTotal)) ?> ریال</span>
        <?php endif; ?>
      </td></tr>
    <?php endif; ?>
    <tr><th>اعتبار فعلی</th><td class="num"><?= to_persian_digits(number_format((int)$cp['wallet']['balance'])) ?> واحد</td></tr>
    <tr><th>اعتبار پس از ارسال</th><td class="num" style="color:<?= $walletOk ? 'inherit' : '#c0392b' ?>">
      <?= to_persian_digits(number_format((int)$cp['wallet']['estimated_remaining'])) ?> واحد
    </td></tr>
  </table>
  </div>

  <?php if (!$walletOk): ?>
    <div class="flash flash-error">اعتبار کافی نیست — این ارسال به <?= to_persian_digits(number_format($estimatedCost)) ?> واحد اعتبار نیاز دارد.</div>
  <?php endif; ?>
  <?php if (!$quotaOk): ?>
    <div class="flash flash-error">سهمیه‌ی پلن سازمان برای این تعداد پیام کافی نیست.</div>
  <?php endif; ?>

  <p class="hint">
    این مبلغ تخمینی است. هزینه، اعتبار و سهمیه در لحظه‌ی ارسال دوباره و به‌صورت قطعی بررسی می‌شوند؛
    اگر در این فاصله اعتبار یا سهمیه‌ی شما تغییر کند، ارسال با پیام مناسب متوقف می‌شود و کسری انجام نمی‌گیرد.
  </p>

  <form method="post" style="margin-top:12px" id="sendConfirmForm">
    <?= csrf_field() ?>
    <?= $previewFormFields ?>
    <input type="hidden" name="previewed_cost" value="<?= $estimatedCost ?>">
    <input type="hidden" name="previewed_at" value="<?= time() ?>">
    <div class="toolbar">
      <?php if ($canSend): ?>
        <button class="btn btn-primary" name="do" value="confirm" id="sendConfirmSubmit">تأیید و ارسال</button>
      <?php else: ?>
        <button class="btn" disabled>ارسال ممکن نیست</button>
      <?php endif; ?>
      <button type="button" class="btn" id="sendConfirmCancel">انصراف / ویرایش</button>
    </div>
  </form>
</div>
</div>
</div>
<script>
(function () {
  var overlay = document.getElementById('sendConfirmOverlay');
  var cancel  = document.getElementById('sendConfirmCancel');
  var form    = document.getElementById('sendConfirmForm');
  var submit  = document.getElementById('sendConfirmSubmit');
  if (!overlay) return;

  function close() { overlay.classList.remove('is-open'); }

  if (cancel) cancel.addEventListener('click', close);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  if (form && submit) {
    form.addEventListener('submit', function () {
      // Once the user confirms, the confirmation modal has completed its job. Hide it immediately
      // while the normal synchronous provider request finishes in the background of this navigation.
      close();
      submit.disabled = true;
      submit.textContent = 'در حال ارسال…';
    });
  }
})();
</script>