<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'بسته‌های قیمتی';
$active = 'pricing';

$editing = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM ellsms_pricing_packages WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $credit   = max(0, (int)($_POST['credit_amount'] ?? 0));
        $price    = max(0, (int)($_POST['price_rial'] ?? 0));
        $features = trim($_POST['features'] ?? '');
        $featured = !empty($_POST['is_featured']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $activeF  = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('error', 'نام بسته نمی‌تواند خالی باشد.');
        } elseif ($id) {
            db()->prepare('UPDATE ellsms_pricing_packages SET name=?, credit_amount=?, price_rial=?, features=?, is_featured=?, sort_order=?, active=? WHERE id=?')
                ->execute([$name, $credit, $price, $features, $featured, $sort, $activeF, $id]);
            audit((int)$me['id'], 'pricing.update', "#{$id}");
            flash('success', 'بسته به‌روزرسانی شد.');
        } else {
            db()->prepare('INSERT INTO ellsms_pricing_packages (name, credit_amount, price_rial, features, is_featured, sort_order, active) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $credit, $price, $features, $featured, $sort, $activeF]);
            audit((int)$me['id'], 'pricing.create', $name);
            flash('success', 'بسته افزوده شد.');
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_pricing_packages WHERE id = ?')->execute([$id]);
        audit((int)$me['id'], 'pricing.delete', "#{$id}");
        flash('info', 'بسته حذف شد.');
    }

    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE ellsms_pricing_packages SET active = 1 - active WHERE id = ?')->execute([$id]);
    }

    redirect('/pricing.php');
}

$packages = db()->query('SELECT * FROM ellsms_pricing_packages ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2><?= $editing ? 'ویرایش بسته' : 'افزودن بسته‌ی جدید' ?></h2>
  <p class="hint">این بسته‌ها به‌صورت کارت قیمتی در صفحه‌ی فرود (<a href="/landing.php#pricing" target="_blank">/landing.php#pricing</a>) نمایش داده می‌شوند — صرفاً برای نمایش تبلیغاتی است و مستقل از نرخ واقعی خرید اعتبار در «تنظیمات → پرداخت».</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="form-row">
      <label>نام بسته
        <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="مثلاً بسته‌ی حرفه‌ای">
      </label>
      <label>مقدار اعتبار
        <input type="number" name="credit_amount" min="0" value="<?= (int)($editing['credit_amount'] ?? 0) ?>">
      </label>
      <label>قیمت (ریال)
        <input type="number" name="price_rial" min="0" value="<?= (int)($editing['price_rial'] ?? 0) ?>">
      </label>
    </div>
    <label>ویژگی‌ها (هر خط یک مورد)
      <textarea name="features" rows="4" placeholder="پشتیبانی ۲۴ ساعته&#10;ارسال نامحدود کاراکتر&#10;گزارش لحظه‌ای"><?= e($editing['features'] ?? '') ?></textarea>
    </label>
    <label>ترتیب نمایش
      <input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
    </label>
    <label style="display:flex;align-items:center;gap:8px;margin-top:6px">
      <input type="checkbox" name="is_featured" value="1" <?= !empty($editing['is_featured']) ? 'checked' : '' ?> style="width:auto;margin:0">
      برچسب «پیشنهاد ویژه» نمایش داده شود
    </label>
    <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
      <input type="checkbox" name="is_active" value="1" <?= ($editing === null || !empty($editing['active'])) ? 'checked' : '' ?> style="width:auto;margin:0">
      نمایش داده شود
    </label>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary"><?= $editing ? 'ذخیره‌ی تغییرات' : 'افزودن بسته' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="/pricing.php">انصراف</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>بسته‌های موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>اعتبار</th><th>قیمت (ریال)</th><th>ویژه</th><th>ترتیب</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($packages as $p): ?>
      <tr>
        <td><?= e($p['name']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$p['credit_amount'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$p['price_rial'])) ?></td>
        <td><?= $p['is_featured'] ? '⭐' : '—' ?></td>
        <td class="num"><?= to_persian_digits((string)$p['sort_order']) ?></td>
        <td><span class="badge badge-<?= $p['active'] ? 'active' : 'off' ?>"><?= $p['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td>
          <a class="btn btn-sm btn-ghost" href="/pricing.php?edit=<?= $p['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-sm btn-ghost"><?= $p['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('بسته‌ی «<?= e($p['name']) ?>» حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$packages): ?><tr><td colspan="7" class="empty">هنوز بسته‌ای افزوده نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
