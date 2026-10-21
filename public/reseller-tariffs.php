<?php
/**
 * ELLSMS — the reseller's named tariffs (app/ResellerTariffs.php): a price per segment for each
 * destination operator and encoding, checked against what the reseller itself pays for the same
 * message, one of them the default for new customers.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('tariffs.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'تعرفه‌ها';
$active = 'reseller_tariffs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'tariffs.manage', '/reseller/tariffs');
    $do = (string)($_POST['do'] ?? '');
    $tariffId = (int)($_POST['tariff_id'] ?? 0) ?: null;
    if ($do === 'save') {
        [$cells, $error] = reseller_tariff_cells_from_form((array)($_POST['price'] ?? []));
        $result = $error !== null
            ? ['ok' => false, 'reason' => $error]
            : reseller_tariff_save($resellerId, $tariffId, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), $cells, !empty($_POST['is_default']), $actorId);
        if ($result['ok']) {
            flash('success', 'تعرفه ذخیره شد.');
            redirect('/reseller/tariffs?edit=' . $result['id']);
        }
        $message = reseller_tariff_error_message((string)$result['reason']);
        if (($result['cell'] ?? null) !== null) {
            [$column, $encoding] = explode('|', (string)$result['cell']);
            $label = array_column(reseller_tariff_columns(), 'label', 'key')[$column] ?? $column;
            $message .= ' (خانه‌ی ' . $label . ' / ' . (GATEWAY_PRICE_ENCODINGS[$encoding] ?? $encoding) . ')';
        }
        flash('error', $message);
        redirect('/reseller/tariffs' . ($tariffId ? '?edit=' . $tariffId : '?new=1'));
    }
    if ($do === 'delete' && $tariffId) {
        $result = reseller_tariff_delete($resellerId, $tariffId, $actorId);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'تعرفه حذف شد.' : reseller_tariff_error_message((string)$result['reason']));
    }
    redirect('/reseller/tariffs');
}

$tariffs = reseller_tariffs_for($resellerId);
$usage = reseller_tariff_usage($resellerId);
$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? reseller_tariff_owned($resellerId, $editId) : null;
$showForm = $edit !== null || isset($_GET['new']) || $tariffs === [];
$cells = $edit !== null ? reseller_tariff_cells($edit['id']) : [];
$floor = $showForm ? reseller_own_prices($resellerId) : [];
$columns = reseller_tariff_columns();
$rial = static fn(?int $millis): string => $millis === null ? '' : rtrim(rtrim(number_format($millis / SMS_PRICE_SCALE, 3, '.', ''), '0'), '.');

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>تعرفه‌های فروش شما</h2>
      <p class="hint">هر تعرفه قیمت هر پیامک (هر بخش) را برای هر اپراتور و برای متن فارسی و لاتین تعیین می‌کند. خانه‌ای که خالی بماند از ردیف «همه‌ی مقصدها»، و اگر آن هم خالی باشد از درصد تعرفه‌ی مشتری محاسبه می‌شود. هیچ مشتری‌ای کمتر از قیمت خود شما برای همان پیام نمی‌پردازد.</p>
    </div>
    <a class="btn btn-sm btn-primary" href="/reseller/tariffs?new=1">تعرفه‌ی جدید</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>عنوان</th><th>توضیح</th><th>مشتریان</th><th>آخرین تغییر</th><th></th></tr></thead>
      <tbody>
      <?php if (!$tariffs): ?><tr><td colspan="5" class="empty">هنوز تعرفه‌ای نساخته‌اید.</td></tr><?php endif; ?>
      <?php foreach ($tariffs as $t): ?>
        <tr>
          <td><?= e($t['title']) ?><?= $t['is_default'] ? ' <span class="badge badge-ok">پیش‌فرض</span>' : '' ?></td>
          <td class="hint"><?= e($t['description']) ?></td>
          <td class="num"><?= to_persian_digits((string)($usage[$t['id']] ?? 0)) ?></td>
          <td><?= e(jdate_from_utc($t['updated_at'])) ?></td>
          <td class="toolbar">
            <a class="btn btn-sm" href="/reseller/tariffs?edit=<?= $t['id'] ?>">ویرایش</a>
            <form method="post" onsubmit="return confirm('این تعرفه حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="tariff_id" value="<?= $t['id'] ?>">
              <button class="btn btn-sm btn-ghost"<?= ($usage[$t['id']] ?? 0) > 0 ? ' disabled title="به مشتریانی داده شده است"' : '' ?>>حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="card" id="form">
  <h2><?= $edit !== null ? 'ویرایش تعرفه: ' . e($edit['title']) : 'تعرفه‌ی جدید' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <?php if ($edit !== null): ?><input type="hidden" name="tariff_id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <label>عنوان * <input type="text" name="title" maxlength="80" required value="<?= e($edit['title'] ?? '') ?>"></label>
      <label>توضیح <input type="text" name="description" maxlength="255" value="<?= e($edit['description'] ?? '') ?>"></label>
      <label class="check"><input type="checkbox" name="is_default" value="1"<?= ($edit['is_default'] ?? $tariffs === []) ? ' checked' : '' ?>> تعرفه‌ی پیش‌فرض مشتریان جدید</label>
    </div>
    <div class="table-wrap" style="margin-top:14px">
      <table class="tariff-matrix">
        <thead><tr><th>مقصد</th><?php foreach (GATEWAY_PRICE_ENCODINGS as $encoding => $label): ?><th>متن <?= e($label) ?> (ریال برای هر بخش)</th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($columns as $column): ?>
          <tr>
            <th scope="row"><?= e($column['label']) ?></th>
            <?php foreach (array_keys(GATEWAY_PRICE_ENCODINGS) as $encoding): $key = reseller_tariff_cell_key($column['key'], $encoding); ?>
              <td>
                <input type="text" inputmode="decimal" name="price[<?= e($column['key']) ?>][<?= e($encoding) ?>]" value="<?= e($rial($cells[$key] ?? null)) ?>" aria-label="<?= e($column['label'] . ' — ' . GATEWAY_PRICE_ENCODINGS[$encoding]) ?>">
                <?php if (isset($floor[$key])): ?><span class="floor">قیمت شما: <?= to_persian_digits($rial($floor[$key])) ?></span><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">«قیمت شما» گران‌ترین قیمتی است که خودتان روی یکی از درگاه‌ها برای آن خانه می‌پردازید؛ قیمت تعرفه نمی‌تواند کمتر از آن باشد.</p>
    <div class="form-actions">
      <button class="btn btn-primary">ذخیره‌ی تعرفه</button>
      <a class="btn btn-ghost" href="/reseller/tariffs">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
