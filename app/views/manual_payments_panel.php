<?php
/**
 * The manual-payments page body (app/ManualPayments.php), shared by the platform admin's
 * /admin/billing/manual-payments and a reseller's /reseller/payments. Expects:
 *
 *   $mpOwner      int     whose payments and settings these are (0 = platform, else the reseller)
 *   $mpBase       string  this page's URL (for tabs, filters and pagination)
 *   $mpAdminView  bool    a platform admin: may also list (read only) payments made to resellers
 */

$mpTab = in_array($_GET['tab'] ?? 'review', ['review', 'settings'], true) ? (string)($_GET['tab'] ?? 'review') : 'review';
$mpPending = manual_payments_pending_count($mpOwner);
$mpNum = static fn($n): string => to_persian_digits(number_format((int)$n));
?>
<div class="toolbar" style="margin-bottom:14px">
  <a class="btn btn-sm <?= $mpTab === 'review' ? 'btn-primary' : '' ?>" href="<?= e($mpBase) ?>?tab=review">بررسی پرداخت‌ها<?= $mpPending > 0 ? ' (' . to_persian_digits((string)$mpPending) . ')' : '' ?></a>
  <a class="btn btn-sm <?= $mpTab === 'settings' ? 'btn-primary' : '' ?>" href="<?= e($mpBase) ?>?tab=settings">روش‌ها، کارت‌ها و حساب‌ها</a>
</div>

<?php if ($mpTab === 'review'):
    $mpState = (string)($_GET['state'] ?? 'pending');
    if ($mpState !== 'all' && !isset(MANUAL_PAYMENT_REVIEW_STATES[$mpState])) $mpState = 'pending';
    $mpMethod = isset(MANUAL_PAYMENT_METHODS[$_GET['method'] ?? '']) ? (string)$_GET['method'] : '';
    $mpCollector = $mpOwner;
    $mpCollectorParam = '';
    if ($mpAdminView) {
        $mpCollectorParam = in_array($_GET['collector'] ?? '', ['resellers', 'all'], true) ? (string)$_GET['collector'] : '';
        $mpCollector = $mpCollectorParam === 'resellers' ? 'resellers' : ($mpCollectorParam === 'all' ? null : 0);
    }
    $mpFilters = ['collector' => $mpCollector, 'state' => $mpState === 'all' ? '' : $mpState, 'method' => $mpMethod];
    $mpPer = 25;
    $mpTotal = manual_payments_count($mpFilters);
    [$mpPage, $mpOffset] = pager_offset($mpTotal, $mpPer);
    $mpRows = manual_payments_list($mpFilters, $mpPer, $mpOffset);
    $mpNames = backend_usernames_by_ids(array_merge(array_column($mpRows, 'user_id'), array_column($mpRows, 'collector_user_id'), array_column($mpRows, 'reviewed_by')));
?>
<form method="get" class="toolbar" style="margin-bottom:14px">
  <input type="hidden" name="tab" value="review">
  <label>وضعیت<select name="state">
    <?php foreach (MANUAL_PAYMENT_REVIEW_STATES + ['all' => 'همه'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $mpState === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <label>روش<select name="method"><option value="">همه</option>
    <?php foreach (MANUAL_PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $mpMethod === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <?php if ($mpAdminView): ?>
  <label>دریافت‌کننده<select name="collector">
    <option value="">پلتفرم (بررسی با مدیر)</option>
    <option value="resellers" <?= $mpCollectorParam === 'resellers' ? 'selected' : '' ?>>نمایندگان (فقط مشاهده)</option>
    <option value="all" <?= $mpCollectorParam === 'all' ? 'selected' : '' ?>>همه</option>
  </select></label>
  <?php endif; ?>
  <button class="btn btn-sm">فیلتر</button>
</form>

<div class="table-wrap">
<table>
  <tr><th>#</th><th>مشتری</th><th>روش</th><th>مبلغ واریزی (ریال)</th><th>اعتبار (ریال)</th><th>مقصد / سند</th><th>زمان پرداخت</th><th>وضعیت</th><th>عملیات</th></tr>
  <?php foreach ($mpRows as $r): $mpMine = (int)$r['collector_user_id'] === $mpOwner; ?>
  <tr>
    <td class="num">#<?= (int)$r['id'] ?><?php if (!empty($r['invoice_number'])): ?><div class="hint ltr"><?= e($r['invoice_number']) ?></div><?php endif; ?></td>
    <td><?= e($mpNames[(int)$r['user_id']] ?? ('#' . (int)$r['user_id'])) ?>
      <?php if ($mpAdminView && (int)$r['collector_user_id'] > 0): ?><div class="hint">نماینده: <?= e($mpNames[(int)$r['collector_user_id']] ?? ('#' . (int)$r['collector_user_id'])) ?></div><?php endif; ?></td>
    <td><?= e(MANUAL_PAYMENT_METHODS[$r['method']] ?? $r['method']) ?></td>
    <td class="num"><strong><?= $mpNum($r['amount_rial']) ?></strong></td>
    <td class="num"><?= $mpNum($r['credits']) ?></td>
    <td>
      <?php if (!empty($r['destination_label'])): ?><div><?= e(to_persian_digits((string)$r['destination_label'])) ?></div><?php endif; ?>
      <div class="hint">سند: <span class="ltr"><?= e((string)$r['document_number']) ?></span></div>
      <?php if (!empty($r['description'])): ?><div class="hint"><?= e((string)$r['description']) ?></div><?php endif; ?>
      <?php if ($r['method'] === 'cheque'): ?>
        <details><summary class="hint">مشخصات چک</summary>
          <table class="pay-details">
            <tr><th>مبلغ چک</th><td class="num"><?= $mpNum($r['cheque_amount']) ?></td></tr>
            <tr><th>بانک / شعبه</th><td><?= e(manual_payment_bank_label((string)$r['cheque_bank'])) ?> / <span class="ltr"><?= e((string)$r['cheque_branch_code']) ?></span></td></tr>
            <tr><th>صاحب حساب</th><td><?= e((string)$r['cheque_owner_name']) ?> — <span class="ltr"><?= e((string)$r['cheque_owner_national_id']) ?></span></td></tr>
            <tr><th>شبا</th><td class="ltr"><?= e((string)$r['cheque_owner_sheba']) ?></td></tr>
            <tr><th>صیادی / سریال / سری</th><td class="ltr"><?= e((string)$r['cheque_sayad_number']) ?> / <?= e((string)$r['cheque_serial']) ?> / <?= e((string)$r['cheque_series']) ?></td></tr>
            <tr><th>سررسید</th><td><?= jdate($r['cheque_due_on'], false) ?></td></tr>
          </table>
        </details>
      <?php endif; ?>
      <?php if (!empty($r['attachment_file'])): ?><a class="btn btn-sm" href="/billing/receipt?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">مشاهده تصویر</a><?php endif; ?>
    </td>
    <td class="num"><?= jdate($r['paid_at']) ?><div class="hint">ثبت: <?= jdate($r['created_at']) ?></div></td>
    <td>
      <span class="badge badge-<?= $r['review_state'] === 'approved' ? 'ok' : ($r['review_state'] === 'pending' ? 'review' : 'off') ?>"><?= e(MANUAL_PAYMENT_REVIEW_STATES[$r['review_state']] ?? $r['review_state']) ?></span>
      <?php if ($r['review_state'] !== 'pending'): ?><div class="hint"><?= e($mpNames[(int)$r['reviewed_by']] ?? '') ?> <?= jdate($r['reviewed_at']) ?></div><?php endif; ?>
      <?php if (!empty($r['review_note'])): ?><div class="hint"><?= e((string)$r['review_note']) ?></div><?php endif; ?>
    </td>
    <td>
      <?php if ($r['review_state'] === 'pending' && $mpMine): ?>
      <div class="toolbar">
        <form method="post" onsubmit="return confirm('واریز این مبلغ را بررسی کرده‌اید؟ با تأیید، <?= e($mpNum($r['credits'])) ?> ریال به اعتبار مشتری اضافه می‌شود<?= $mpOwner > 0 ? ' (از کیف پول شما)' : '' ?>.');">
          <?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="decision" value="approve"><input type="hidden" name="manual_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="note" value="">
          <button class="btn btn-sm btn-primary">تأیید</button>
        </form>
        <form method="post" onsubmit="var n=prompt('دلیل رد پرداخت (به مشتری نمایش داده می‌شود):');if(n===null||!n.trim())return false;this.note.value=n.trim();return true;">
          <?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="decision" value="reject"><input type="hidden" name="manual_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="note" value="">
          <button class="btn btn-sm btn-danger">رد</button>
        </form>
      </div>
      <?php elseif ($r['review_state'] === 'pending'): ?><span class="hint">بررسی با نماینده</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$mpRows): ?><tr><td colspan="9" class="empty">پرداختی یافت نشد.</td></tr><?php endif; ?>
</table>
</div>
<?= pager_links($mpPage, pager_pages($mpTotal, $mpPer), array_filter(['tab' => 'review', 'state' => $mpState, 'method' => $mpMethod, 'collector' => $mpCollectorParam], 'strlen'), 'page', $mpTotal) ?>
<?php if ($mpOwner > 0): ?><p class="hint">با تأیید هر پرداخت، مبلغ اعتبار از کیف پول شما به کیف پول مشتری منتقل می‌شود؛ پول را خودتان دریافت کرده‌اید.</p><?php endif; ?>

<?php else:
    $mpEnabled = manual_payment_methods_enabled($mpOwner);
    $mpDestinations = manual_payment_destinations($mpOwner);
    $mpBankOptions = static function (string $selected): string {
        $h = '<option value="">انتخاب بانک</option>';
        foreach (MANUAL_PAYMENT_BANKS as $key => $label) $h .= '<option value="' . e($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        return $h;
    };
?>
<div class="grid grid-2">
  <div class="card" style="box-shadow:none;border:1px solid var(--line)">
    <h3>روش‌های پرداخت دستی</h3>
    <p class="hint">پرداخت آنلاین همیشه فعال است. روش‌های زیر پس از بررسی و تأیید <?= $mpOwner > 0 ? 'شما' : 'مدیر' ?> اعتبار را شارژ می‌کنند. کارت به کارت به یک کارت فعال، و واریز به حساب و چک به یک حساب فعال نیاز دارند.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="methods">
      <?php foreach (MANUAL_PAYMENT_METHODS as $k => $v): ?>
        <label class="check"><input type="checkbox" name="methods[]" value="<?= e($k) ?>" <?= $mpEnabled[$k] ? 'checked' : '' ?>> <?= e($v) ?>
          <?php if ($mpEnabled[$k] && !manual_payment_owner_accepts($mpOwner, $k, $mpEnabled)): ?><span class="badge badge-pending">بدون <?= MANUAL_PAYMENT_DESTINATION_KIND[$k] === 'card' ? 'کارت' : 'حساب' ?> فعال — نمایش داده نمی‌شود</span><?php endif; ?>
        </label>
      <?php endforeach; ?>
      <?php if ($mpOwner > 0): ?><p class="hint">روشی که شما فعال نکنید، برای مشتریانتان با تنظیمات پلتفرم (پرداخت به مدیر) نمایش داده می‌شود.</p><?php endif; ?>
      <button class="btn btn-primary btn-sm">ذخیره</button>
    </form>
  </div>

  <div class="card" style="box-shadow:none;border:1px solid var(--line)">
    <h3>افزودن کارت یا حساب</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="destination_save">
      <label>نوع<select name="kind" required onchange="this.form.querySelectorAll('[data-kind]').forEach(el=>el.hidden=el.dataset.kind!==this.value)"><option value="card">کارت (کارت به کارت)</option><option value="account">حساب بانکی (واریز به حساب / چک)</option></select></label>
      <label>بانک<select name="bank" required><?= $mpBankOptions('') ?></select></label>
      <label>نام صاحب کارت/حساب<input type="text" name="holder_name" maxlength="120" required></label>
      <label data-kind="card">شماره کارت<input type="text" name="card_number" class="ltr" inputmode="numeric" maxlength="19" placeholder="6037-xxxx-xxxx-xxxx"></label>
      <label data-kind="account" hidden>شماره حساب<input type="text" name="account_number" class="ltr" maxlength="30"></label>
      <label data-kind="account" hidden>شماره شبا (اختیاری)<input type="text" name="sheba" class="ltr" maxlength="34" placeholder="IR"></label>
      <label>ترتیب نمایش<input type="number" name="sort_order" value="0" style="width:100px"></label>
      <label class="check"><input type="checkbox" name="active" value="1" checked> فعال</label>
      <button class="btn btn-primary btn-sm">افزودن</button>
    </form>
  </div>
</div>

<h3 style="margin-top:18px">کارت‌ها و حساب‌ها</h3>
<div class="table-wrap">
<table>
  <tr><th>نوع</th><th>مشخصات</th><th>وضعیت</th><th>ویرایش</th></tr>
  <?php foreach ($mpDestinations as $d): ?>
  <tr>
    <td><?= $d['kind'] === 'card' ? 'کارت' : 'حساب' ?></td>
    <td><?= e(to_persian_digits(manual_payment_destination_label($d))) ?></td>
    <td><span class="badge badge-<?= (int)$d['active'] === 1 ? 'ok' : 'off' ?>"><?= (int)$d['active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
    <td>
      <details><summary class="btn btn-sm">ویرایش</summary>
        <form method="post" style="margin-top:8px">
          <?= csrf_field() ?><input type="hidden" name="do" value="destination_save"><input type="hidden" name="destination_id" value="<?= (int)$d['id'] ?>">
          <label>بانک<select name="bank" required><?= $mpBankOptions((string)$d['bank']) ?></select></label>
          <label>نام صاحب<input type="text" name="holder_name" maxlength="120" value="<?= e((string)$d['holder_name']) ?>" required></label>
          <?php if ($d['kind'] === 'card'): ?>
            <label>شماره کارت<input type="text" name="card_number" class="ltr" maxlength="19" value="<?= e((string)$d['card_number']) ?>" required></label>
          <?php else: ?>
            <label>شماره حساب<input type="text" name="account_number" class="ltr" maxlength="30" value="<?= e((string)$d['account_number']) ?>" required></label>
            <label>شماره شبا<input type="text" name="sheba" class="ltr" maxlength="34" value="<?= e((string)$d['sheba']) ?>"></label>
          <?php endif; ?>
          <label>ترتیب نمایش<input type="number" name="sort_order" value="<?= (int)$d['sort_order'] ?>" style="width:100px"></label>
          <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$d['active'] === 1 ? 'checked' : '' ?>> فعال</label>
          <button class="btn btn-primary btn-sm">ذخیره</button>
        </form>
        <form method="post" onsubmit="return confirm('این کارت/حساب حذف شود؟ پرداخت‌های قبلی آن حفظ می‌شوند.')" style="margin-top:6px">
          <?= csrf_field() ?><input type="hidden" name="do" value="destination_delete"><input type="hidden" name="destination_id" value="<?= (int)$d['id'] ?>">
          <button class="btn btn-sm btn-danger">حذف</button>
        </form>
      </details>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$mpDestinations): ?><tr><td colspan="4" class="empty">هنوز کارت یا حسابی تعریف نشده.</td></tr><?php endif; ?>
</table>
</div>
<?php endif; ?>
