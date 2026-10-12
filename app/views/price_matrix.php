<?php
/**
 * The price matrix form (app/GatewayPrices.php): one row per sending gateway, one column pair
 * (Persian / Latin) per destination operator, plus "other" and "international". The top row of each
 * column pair fills the whole column with one value (the ↓ button, assets/js/forms.js).
 *
 * Expects:
 *   $matrixGateways      gateway_price_matrix_gateways()
 *   $matrixColumns       gateway_price_matrix_columns()
 *   $matrixValues        gateway_prices_current() of the matrix being edited
 *   $matrixDefaults      gateway_price_defaults(): what an empty cell costs. Shown in grey inside the
 *                        empty cell so it can be edited in place; left unchanged, it is not saved
 *   $matrixHidden        HTML of the form's hidden fields (csrf, do, id, ...)
 *   $matrixSubmitLabel   the submit button's label
 */
$matrixTints = ['mci' => '#e8f8fb', 'mtn' => '#fff7d6', 'rightel' => '#f8e9f6'];
$matrixDefaultTints = ['other' => '#f1edf9', 'intl' => '#e9f1fb'];
$matrixTint = static function (array $column) use ($matrixTints, $matrixDefaultTints): string {
    $key = operator_logo_key($column['code'] ?? $column['label']);
    return $key !== null ? $matrixTints[$key] : ($matrixDefaultTints[$column['key']] ?? '#f4f5f7');
};
$matrixFormat = static fn(int $millis): string => (string)sms_pricing_millicredits_to_credits($millis);
?>
<?php if (!$matrixGateways): ?>
  <p class="hint">هنوز هیچ درگاه فعالی تعریف نشده است. درگاه‌ها در صفحه‌ی «درگاه‌های پیامک» ساخته می‌شوند.</p>
<?php else: ?>
<form method="post" class="price-matrix-form">
  <?= $matrixHidden ?>
  <div class="table-wrap">
  <table class="price-matrix">
    <thead>
      <tr>
        <th rowspan="3" class="pm-gateway-head">عامل ارسال پیامک</th>
        <?php foreach ($matrixColumns as $column): ?>
          <th colspan="2" class="pm-col-head" style="background:<?= e($matrixTint($column)) ?>">
            <?= operator_label($column['code'] ?? $column['label'], $column['label'], 22) ?>
          </th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($matrixColumns as $column): ?>
          <?php foreach (GATEWAY_PRICE_ENCODINGS as $encodingLabel): ?>
            <th class="pm-enc-head" style="background:<?= e($matrixTint($column)) ?>"><?= e($encodingLabel) ?></th>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tr>
      <tr class="pm-fill-row">
        <?php foreach ($matrixColumns as $column): ?>
          <?php foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding): $fillKey = $column['key'] . '-' . $encoding; ?>
            <th style="background:<?= e($matrixTint($column)) ?>">
              <span class="pm-fill">
                <input type="text" inputmode="decimal" class="ltr" data-fill-source="<?= e($fillKey) ?>" aria-label="مقدار برای همه‌ی ستون">
                <button type="button" class="btn btn-sm" data-fill-down="<?= e($fillKey) ?>" title="اعمال به همه‌ی درگاه‌های این ستون">↓</button>
              </span>
            </th>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($matrixGateways as $gateway): $gatewayId = (int)$gateway['id']; ?>
        <tr>
          <th scope="row" class="pm-gateway"><?= e((string)$gateway['name']) ?></th>
          <?php foreach ($matrixColumns as $column): ?>
            <?php foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding):
                $cellKey = gateway_price_key($gatewayId, $column['key'], $encoding);
                $value = $matrixValues[$cellKey] ?? null;
                $default = $matrixDefaults[$cellKey] ?? null;
                $shown = $value ?? $default; ?>
              <td>
                <input type="text" inputmode="decimal" class="ltr pm-cell<?= $value === null && $default !== null ? ' is-default' : '' ?>"
                       name="price[<?= $gatewayId ?>][<?= e($column['key']) ?>][<?= $encoding ?>]"
                       data-fill-target="<?= e($column['key'] . '-' . $encoding) ?>"
                       data-default="<?= $default !== null ? e($matrixFormat($default)) : '' ?>"
                       value="<?= $shown !== null ? e($matrixFormat($shown)) : '' ?>"
                       placeholder="<?= $default !== null ? e($matrixFormat($default)) : '—' ?>"
                       title="<?= $default !== null ? 'پیش‌فرض: ' . e($matrixFormat($default)) . ' ریال' : 'بدون قیمت پیش‌فرض' ?>">
              </td>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="form-actions"><button class="btn btn-primary btn-sm"><?= e($matrixSubmitLabel) ?></button></div>
</form>
<?php endif; ?>
