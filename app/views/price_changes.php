<?php
/**
 * The price-change preview and history of one price matrix (app/PriceChanges.php,
 * docs/price-changes.md), under its form on «تعرفه‌ی درگاه‌ها» and on a user's «تعرفه‌ی اختصاصی».
 *
 * Expects:
 *   $priceChangeSlot    0 = the global matrix, else the account id
 *   $priceChangeHidden  HTML of the hidden fields every form here posts with (csrf, tab / id, ...)
 */
if (!price_changes_available()) {
    echo '<p class="hint">' . e(price_change_error_message('not_migrated')) . '</p>';
    return;
}
$pcDraft = price_change_draft($priceChangeSlot);
$pcLabel = price_change_cell_labeler();
$pcCurrent = gateway_prices_current(price_change_matrix_user($priceChangeSlot));
$pcHistory = price_changes_list($priceChangeSlot);
$pcNum = static fn(int|float $n): string => to_persian_digits(number_format((float)$n, is_float($n) && floor($n) != $n ? 1 : 0));
$pcRial = static fn(int $n): string => to_persian_digits(number_format($n)) . ' ریال';
$pcDeltaClass = static fn(int $d): string => $d > 0 ? 'badge-delivered' : ($d < 0 ? 'badge-failed' : 'badge-unknown');
?>

<?php if ($pcDraft !== null): $impact = $pcDraft['impact']; ?>
<div class="card price-change-preview" id="price-change-preview" style="margin-top:22px;border:2px solid var(--primary, #3055f7)">
  <h2>پیش‌نمایش اثر تغییر تعرفه <span class="badge">هنوز ذخیره نشده</span></h2>
  <p class="hint">
    بر اساس ارسال‌های واقعی <?= $impact['from'] ? e(jdate($impact['from'], false)) . ' تا ' . e(jdate((new DateTimeImmutable((string)$impact['to']))->modify('-1 day')->format('Y-m-d'), false)) : '۳۰ روز گذشته' ?>
    (یک ماه)، با همان موتور قیمت‌گذاری ارسال‌ها و با درصد تعرفه، قیمت اختصاصی و زنجیره‌ی نمایندگی هر مشتری.
    عددها تخمینی است: گرد کردن هر پیام جدا حساب نمی‌شود و ترافیک ماه آینده ممکن است فرق کند.
  </p>
  <div class="stat-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0">
    <div class="stat"><div class="hint">خانه‌های تغییرکرده</div><strong><?= $pcNum((int)$impact['cells']) ?></strong></div>
    <div class="stat"><div class="hint">مشتری‌های تحت تأثیر</div><strong><?= $pcNum((int)$impact['customers']) ?></strong>
      <span class="hint">از <?= $pcNum((int)$impact['customers_total']) ?> مشتری فعال</span></div>
    <div class="stat"><div class="hint">پیام / پارت ماهانه‌ی تحت تأثیر</div><strong><?= $pcNum((int)$impact['messages']) ?> / <?= $pcNum((int)$impact['parts']) ?></strong></div>
    <div class="stat"><div class="hint">درآمد ماهانه‌ی این پیام‌ها</div><strong><?= $pcRial((int)$impact['before']) ?> ← <?= $pcRial((int)$impact['after']) ?></strong></div>
    <div class="stat"><div class="hint">جابه‌جایی درآمد ماهانه</div>
      <strong><span class="badge <?= $pcDeltaClass((int)$impact['delta']) ?>"><span dir="ltr"><?= e(price_change_format_delta((int)$impact['delta'])) ?></span> ریال</span></strong>
      <?php if ($impact['percent'] !== null): ?><span class="hint">(<span dir="ltr"><?= e(($impact['percent'] > 0 ? '+' : ($impact['percent'] < 0 ? '−' : '')) . to_persian_digits((string)abs((float)$impact['percent']))) ?>٪</span> از کل درآمد ماهانه‌ی <?= $pcRial((int)$impact['revenue_total']) ?>)</span><?php endif; ?></div>
  </div>
  <?php if ((int)$impact['newly_unpriced_messages'] > 0): ?>
    <div class="flash flash-error">با این تغییر <?= $pcNum((int)$impact['newly_unpriced_messages']) ?> پیام در ماه دیگر قیمتی ندارد و ارسالشان رد می‌شود.</div>
  <?php endif; ?>
  <?php if (!empty($impact['partial'])): ?>
    <div class="flash flash-error">زمان محاسبه تمام شد؛ فقط <?= e(to_persian_digits((string)$impact['covered_percent'])) ?>٪ از ترافیک (پرحجم‌ترین‌ها) بررسی شد و عددها کمتر از واقع است.</div>
  <?php endif; ?>
  <?php if ((int)$impact['customers_total'] === 0): ?>
    <p class="hint">در ۳۰ روز گذشته هیچ ارسالی نبوده که این تعرفه بر آن اثر داشته باشد.</p>
  <?php endif; ?>

  <h3>خانه‌های تغییرکرده</h3>
  <div class="table-wrap"><table>
    <tr><th>عامل ارسال</th><th>اپراتور</th><th>متن</th><th>قیمت فعلی (ریال)</th><th>قیمت جدید (ریال)</th></tr>
    <?php foreach ($pcDraft['cells'] as $key => $millis): $l = $pcLabel((string)$key); ?>
      <tr><td><?= e($l['row']) ?></td><td><?= e($l['column']) ?></td><td><?= e($l['encoding']) ?></td>
          <td class="num"><?= e(price_change_format_price($pcCurrent[$key] ?? null)) ?></td>
          <td class="num"><strong><?= e(price_change_format_price($millis)) ?></strong></td></tr>
    <?php endforeach; ?>
  </table></div>

  <?php if ($impact['top'] !== []): ?>
  <h3>بیشترین اثر بر مشتری‌ها (ماهانه)</h3>
  <div class="table-wrap"><table>
    <tr><th>مشتری</th><th>پیام</th><th>پارت</th><th>هزینه با قیمت فعلی</th><th>هزینه با قیمت جدید</th><th>تفاوت</th></tr>
    <?php foreach ($impact['top'] as $t): ?>
      <tr><td><?= e((string)$t['username']) ?></td><td class="num"><?= $pcNum((int)$t['messages']) ?></td><td class="num"><?= $pcNum((int)$t['parts']) ?></td>
          <td class="num"><?= $pcRial((int)$t['before']) ?></td><td class="num"><?= $pcRial((int)$t['after']) ?></td>
          <td class="num"><span class="badge <?= $pcDeltaClass((int)$t['delta']) ?>" dir="ltr"><?= e(price_change_format_delta((int)$t['delta'])) ?></span></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <form method="post" class="price-change-confirm" style="margin-top:14px">
    <?= $priceChangeHidden ?>
    <input type="hidden" name="do" value="price_change_confirm">
    <input type="hidden" name="token" value="<?= e((string)$pcDraft['token']) ?>">
    <div class="toolbar">
      <label><input type="radio" name="when" value="now" checked> اعمال از همین حالا</label>
      <label><input type="radio" name="when" value="later"> اعمال در زمان:</label>
      <?= jalali_date_select('effective', date('Y-m-d', time() + 86400)) ?>
      <?= time_select('effective_time', '00:00') ?>
      <span class="hint">به وقت تهران</span>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" onclick="return confirm('این تغییر تعرفه ذخیره شود؟')">تأیید و ذخیره</button>
    </div>
  </form>
  <form method="post" style="display:inline">
    <?= $priceChangeHidden ?><input type="hidden" name="do" value="price_change_discard">
    <button class="btn btn-ghost">انصراف (چیزی ذخیره نمی‌شود)</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:22px" id="price-change-history">
  <h2>تاریخچه‌ی تغییرات تعرفه</h2>
  <p class="hint">
    هر ذخیره‌ی این جدول یک تغییر است. تغییر زمان‌بندی‌شده تا زمانش برسد لغو می‌شود؛ تغییر اعمال‌شده با «بازگشت» به مقدارهای قبلی برمی‌گردد
    (به‌صورت یک تغییر جدید؛ خانه‌ای که بعداً دوباره عوض شده دست نمی‌خورد). ارسال‌های انجام‌شده همیشه با قیمت زمان خودشان می‌مانند.
  </p>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>ثبت</th><th>توسط</th><th>وضعیت</th><th>زمان اجرا</th><th>تغییرات</th><th>اثر تخمینی ماهانه</th><th></th></tr>
    <?php foreach ($pcHistory as $change): $impact = is_array($change['impact']) ? $change['impact'] : null; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$change['id']) ?></td>
        <td class="num"><?= e(jdate_from_utc((string)$change['created_at'])) ?></td>
        <td><?= e((string)$change['actor']) ?></td>
        <td><?= e(PRICE_CHANGE_STATUSES[$change['status']] ?? (string)$change['status']) ?>
          <?php if ($change['reverts_change_id'] !== null): ?><div class="hint">بازگشتِ #<?= to_persian_digits((string)$change['reverts_change_id']) ?></div><?php endif; ?>
          <?php if ($change['reverted_by_change_id'] !== null): ?><div class="hint">با #<?= to_persian_digits((string)$change['reverted_by_change_id']) ?> برگشت</div><?php endif; ?>
          <?php if (!empty($change['note'])): ?><div class="hint"><?= e((string)$change['note']) ?></div><?php endif; ?></td>
        <td class="num"><?= e(jdate_from_utc((string)($change['applied_at'] ?? $change['effective_at']))) ?></td>
        <td>
          <details><summary><?= to_persian_digits((string)count($change['cells'])) ?> خانه</summary>
            <ul style="margin:6px 0;padding-inline-start:18px">
              <?php foreach ($change['cells'] as $key => $millis): $l = $pcLabel((string)$key); ?>
                <li><?= e($l['row'] . ' / ' . $l['column'] . ' / ' . $l['encoding']) ?>:
                  <?php if ($change['before'] !== null && array_key_exists($key, $change['before'])): ?><?= e(price_change_format_price($change['before'][$key])) ?> ← <?php endif; ?>
                  <strong><?= e(price_change_format_price($millis)) ?></strong></li>
              <?php endforeach; ?>
            </ul>
          </details>
        </td>
        <td><?php if ($impact !== null): ?>
            <span class="badge <?= $pcDeltaClass((int)$impact['delta']) ?>"><span dir="ltr"><?= e(price_change_format_delta((int)$impact['delta'])) ?></span> ریال</span>
            <div class="hint"><?= $pcNum((int)$impact['customers']) ?> مشتری</div>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td>
          <?php if ($change['status'] === 'scheduled'): ?>
            <form method="post" style="display:inline"><?= $priceChangeHidden ?>
              <input type="hidden" name="do" value="price_change_cancel"><input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
              <button class="btn btn-sm" onclick="return confirm('این تغییر زمان‌بندی‌شده لغو شود؟')">لغو</button></form>
          <?php elseif ($change['status'] === 'applied'): ?>
            <form method="post" style="display:inline"><?= $priceChangeHidden ?>
              <input type="hidden" name="do" value="price_change_revert"><input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
              <button class="btn btn-sm" onclick="return confirm('قیمت‌های این تغییر به مقدار قبلی برگردد؟ از همین لحظه برای ارسال‌های جدید اعمال می‌شود.')">بازگشت</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pcHistory === []): ?><tr><td colspan="8" class="muted">هنوز تغییری ثبت نشده است.</td></tr><?php endif; ?>
  </table></div>
</div>
