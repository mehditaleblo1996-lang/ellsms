<?php
/**
 * ELLSMS — «تعرفه من»: the price the signed-in customer pays for one SMS segment, per destination
 * operator and encoding, from each of its sending lines (customer_tariff_rows(), app/ResellerTariffs.php).
 * Read-only, and computed by the same precedence a send is charged with: the account's own prices,
 * else the list price times its tariff percentage, or its reseller's tariff.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'تعرفه من';
$active = 'my_tariff';

$isAdmin = $me['role'] === 'admin';
$columns = gateway_price_matrix_columns();
$rows = $isAdmin ? [] : customer_tariff_rows($me);
$rial = static function (int $millis): string {
    $formatted = rtrim(rtrim(number_format(sms_pricing_millicredits_to_credits($millis), 3, '.', ','), '0'), '.');
    return to_persian_digits($formatted);
};

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>تعرفه من</h2>
  <?php if ($isAdmin): ?>
    <p class="hint">این صفحه تعرفه‌ی هر مشتری را به خودش نشان می‌دهد. تعرفه‌ها در <a href="/sms-pricing.php?tab=matrix">تعرفه‌ی پیامک</a> و صفحه‌ی هر کاربر تنظیم می‌شوند.</p>
  <?php else: ?>
    <p class="hint">هزینه‌ی ارسال هر پیامک (هر بخش) به ریال، بر اساس اپراتور گیرنده و زبان متن. پیامک فارسی تا ۷۰ نویسه و پیامک لاتین تا ۱۶۰ نویسه یک بخش است و پیامک بلندتر به چند بخش تقسیم می‌شود.</p>
  <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<div class="card">
  <?php if (!$rows || !array_filter(array_column($rows, 'prices'))): ?>
    <p class="empty">هنوز تعرفه‌ای برای حساب شما ثبت نشده است. برای اطلاع از تعرفه با <a href="/tickets.php">پشتیبانی</a> در تماس باشید.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="price-matrix">
      <thead>
        <tr>
          <th rowspan="2">خط ارسال</th>
          <?php foreach ($columns as $column): ?>
            <th colspan="2"><?= operator_label($column['code'] ?? $column['label'], $column['label'], 22) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($columns as $column): ?>
            <?php foreach (GATEWAY_PRICE_ENCODINGS as $encodingLabel): ?><th><?= e($encodingLabel) ?></th><?php endforeach; ?>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <th scope="row"><?= $row['lines'] ? e(to_persian_digits(implode('، ', $row['lines']))) : '<span class="hint">همه‌ی خطوط</span>' ?></th>
            <?php foreach ($columns as $column): ?>
              <?php foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding): $price = $row['prices'][reseller_tariff_cell_key($column['key'], $encoding)] ?? null; ?>
                <td class="num"><?= $price !== null ? $rial($price) : '—' ?></td>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">«سایر» شماره‌ی ایرانی با اپراتور نامشخص و «بین‌الملل» شماره‌ی خارج از ایران است. هزینه‌ی دقیق هر ارسال پیش از تأیید در صفحه‌ی ارسال نمایش داده می‌شود.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
