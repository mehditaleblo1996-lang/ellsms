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

// Route pricing: `credits_per_segment` is null when operators genuinely differ in rate, so the
// "is this free?" test is price_source, not the number (which may legitimately be null or 0.5).
$isExempt = ($cp['pricing']['price_source'] ?? '') === 'admin_exempt';
$unitPrice = $cp['pricing']['credits_per_segment'];
$unitMin = ($cp['pricing']['unit_price_min_millicredits'] ?? 0) / 1000;
$unitMax = ($cp['pricing']['unit_price_max_millicredits'] ?? 0) / 1000;
$groups = $cp['pricing']['groups'] ?? [];
// Operator names come from the configured catalog, never a hard-coded UI label list (STEP 20).
$operatorLabels = [];
foreach ($groups as $g) {
    $operatorLabels[$g['operator']] = $g['operator_name'] !== '' ? $g['operator_name'] : $g['operator'];
}
?>
<div class="card" style="border:2px solid <?= $canSend ? '#2d7a4f' : '#c0392b' ?>">
  <h2>خلاصه‌ی ارسال — پیش از تأیید</h2>

  <div class="table-wrap">
  <table>
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
          <?php // Mixed operator rates: one averaged number would be a price no recipient actually
                // pays, so the range is shown and the per-operator breakdown below carries the detail. ?>
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

  <?php if (!$isExempt && count($groups) > 0): ?>
    <h3 style="margin-top:14px">تفکیک بر اساس اپراتور</h3>
    <div class="table-wrap">
    <table>
      <tr><th>اپراتور</th><th>گیرندگان</th><th>پیامک</th><th>هزینه</th></tr>
      <?php
        // Grouped by operator for the default view — a normal user cares which carrier costs what,
        // not which internal route carried it. The provider/route detail is one click away below.
        $byOperator = [];
        foreach ($groups as $g) {
          $key = $g['operator'];
          $byOperator[$key] ??= ['label' => $operatorLabels[$key], 'recipients' => 0, 'segments' => 0, 'cost' => 0];
          $byOperator[$key]['recipients'] += (int)$g['recipients'];
          $byOperator[$key]['segments']   += (int)$g['segments'];
          $byOperator[$key]['cost']       += (int)$g['cost'];
        }
      ?>
      <?php foreach ($byOperator as $row): ?>
        <tr>
          <td><?= e($row['label']) ?></td>
          <td class="num"><?= to_persian_digits(number_format($row['recipients'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format($row['segments'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format($row['cost'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <details style="margin-top:8px">
      <summary>جزئیات مسیر ارسال</summary>
      <div class="table-wrap">
      <table>
        <tr><th>اپراتور</th><th>ارائه‌دهنده</th><th>مسیر</th><th>نوع پیام</th><th>هر بخش</th><th>هزینه</th></tr>
        <?php foreach ($groups as $g): ?>
          <tr>
            <td><?= e($operatorLabels[$g['operator']]) ?></td>
            <td class="ltr"><?= e((string)$g['provider']) ?></td>
            <td class="ltr"><?= e((string)$g['route']) ?></td>
            <td class="ltr"><?= e((string)$g['message_type']) ?></td>
            <td class="num"><?= to_persian_digits(rtrim(rtrim(number_format((float)$g['unit_price'], 3), '0'), '.')) ?></td>
            <td class="num"><?= to_persian_digits(number_format((int)$g['cost'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <p class="hint">
        تشخیص اپراتور بر پایه‌ی پیش‌شماره‌ی پیکربندی‌شده است؛ پس از جابه‌جایی شماره میان اپراتورها ممکن است
        با اپراتور فعلیِ شماره یکسان نباشد.
      </p>
    </details>
  <?php endif; ?>

  <?php if (!empty($cp['quota']['enforced'])): ?>
    <h3 style="margin-top:14px">سهمیه‌ی پلن</h3>
    <div class="table-wrap">
    <table>
      <tr><th>مصرف این ارسال</th><td class="num"><?= to_persian_digits(number_format((int)$cp['quota']['estimated_usage'])) ?> پیام</td></tr>
      <?php foreach ([['monthly','ماهانه'], ['daily','روزانه']] as [$k, $label]): ?>
        <?php if (isset($cp['quota'][$k]) && $cp['quota'][$k]['limit'] !== null): ?>
          <tr><th>باقی‌مانده‌ی <?= $label ?></th><td class="num"><?= to_persian_digits(number_format((int)$cp['quota'][$k]['remaining'])) ?> از <?= to_persian_digits(number_format((int)$cp['quota'][$k]['limit'])) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </table>
    </div>
  <?php endif; ?>

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

  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?>
    <?= $previewFormFields ?>
    <input type="hidden" name="previewed_cost" value="<?= $estimatedCost ?>">
    <input type="hidden" name="previewed_at" value="<?= time() ?>">
    <div class="toolbar">
      <?php if ($canSend): ?>
        <button class="btn btn-primary" name="do" value="confirm">تأیید و ارسال</button>
      <?php else: ?>
        <button class="btn" disabled>ارسال ممکن نیست</button>
      <?php endif; ?>
      <a class="btn" href="">ویرایش</a>
    </div>
  </form>
</div>
