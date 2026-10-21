<?php
/**
 * ELLSMS — news a reseller publishes to its customers (Negar's News, app/ResellerPanel.php). An
 * announcement shows on its customers' dashboards while it is active and in its date range, and a
 * new one is also sent to them as a panel notification.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('announcements.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'اطلاعیه‌ها';
$active = 'reseller_announcements';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'announcements.manage', '/reseller/announcements');
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if ($do === 'save') {
        $result = reseller_announcement_save($resellerId, $id, array_merge($_POST, [
            'starts_on' => jalali_request_to_gregorian('starts_on'),
            'ends_on'   => jalali_request_to_gregorian('ends_on'),
        ]), $actorId);
        if ($result['ok']) {
            flash('success', $id === null ? 'اطلاعیه منتشر شد' . ($result['notified'] > 0 ? ' و برای ' . to_persian_digits((string)$result['notified']) . ' حساب اعلان فرستاده شد.' : '.') : 'اطلاعیه ذخیره شد.');
        } else {
            flash('error', reseller_error_message((string)$result['reason']));
        }
    } elseif ($do === 'delete' && $id !== null) {
        reseller_announcement_delete($resellerId, $id, $actorId);
        flash('info', 'اطلاعیه حذف شد.');
    }
    redirect('/reseller/announcements');
}

$items = reseller_announcements_for_owner($resellerId);
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
foreach ($items as $item) if ((int)$item['id'] === $editId) $edit = $item;
$severities = ['info' => 'اطلاع‌رسانی', 'success' => 'خبر خوب', 'warning' => 'هشدار', 'error' => 'مهم / اختلال'];

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <h2><?= $edit ? 'ویرایش اطلاعیه' : 'اطلاعیه‌ی جدید' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <label>عنوان * <input type="text" name="title" maxlength="160" required value="<?= e((string)($edit['title'] ?? '')) ?>"></label>
      <label>نوع
        <select name="severity"><?php foreach ($severities as $key => $label): ?><option value="<?= e($key) ?>"<?= ($edit['severity'] ?? 'info') === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      </label>
      <label>مخاطب
        <select name="audience">
          <option value="tree"<?= ($edit['audience'] ?? 'tree') === 'tree' ? ' selected' : '' ?>>همه‌ی زیرمجموعه (با زیرمجموعه‌ی زیرنماینده‌ها)</option>
          <option value="direct"<?= ($edit['audience'] ?? '') === 'direct' ? ' selected' : '' ?>>فقط مشتریان مستقیم</option>
        </select>
      </label>
      <label>نمایش از <?= jalali_date_select('starts_on', $edit['starts_on'] ?? null, yearsAhead: 2, yearsBack: 0, allowEmpty: true) ?></label>
      <label>نمایش تا <?= jalali_date_select('ends_on', $edit['ends_on'] ?? null, yearsAhead: 2, yearsBack: 0, allowEmpty: true) ?></label>
      <label class="check"><input type="checkbox" name="active" value="1"<?= !$edit || (int)$edit['active'] === 1 ? ' checked' : '' ?>> فعال</label>
      <label class="span-full">متن * <textarea name="body" rows="5" maxlength="5000" required><?= e((string)($edit['body'] ?? '')) ?></textarea></label>
    </div>
    <p class="hint">اطلاعیه‌ی جدیدِ فعال، همان لحظه به‌صورت اعلان پنل برای مخاطبانش فرستاده می‌شود و تا پایان بازه روی داشبورد آن‌ها می‌ماند.</p>
    <div class="form-actions">
      <button class="btn btn-primary"><?= $edit ? 'ذخیره' : 'انتشار' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="/reseller/announcements">انصراف</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>اطلاعیه‌های منتشرشده</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>عنوان</th><th>نوع</th><th>مخاطب</th><th>بازه</th><th>اعلان‌شده</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="7" class="empty">هنوز اطلاعیه‌ای منتشر نکرده‌اید.</td></tr><?php endif; ?>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e((string)$item['title']) ?><div class="hint"><?= e(jdate_from_utc((string)$item['created_at'])) ?></div></td>
        <td><?= e($severities[$item['severity']] ?? $item['severity']) ?></td>
        <td><?= $item['audience'] === 'direct' ? 'مستقیم' : 'همه‌ی زیرمجموعه' ?></td>
        <td class="hint"><?= e(($item['starts_on'] ? jdate((string)$item['starts_on'], false) : '—') . ' تا ' . ($item['ends_on'] ? jdate((string)$item['ends_on'], false) : '—')) ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$item['notified_count']) ?></td>
        <td><span class="badge badge-<?= (int)$item['active'] === 1 ? 'ok' : 'off' ?>"><?= (int)$item['active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
        <td class="toolbar">
          <a class="btn btn-sm" href="/reseller/announcements?edit=<?= (int)$item['id'] ?>">ویرایش</a>
          <form method="post" onsubmit="return confirm('این اطلاعیه حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
            <button class="btn btn-sm btn-ghost">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
