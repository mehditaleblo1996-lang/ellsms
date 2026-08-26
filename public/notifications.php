<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/NotificationCenter.php';
$me = require_login();
$pageTitle = 'اعلان‌ها';
$active = 'notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'read') notification_mark_read((int)$me['id'], (int)($_POST['id'] ?? 0));
    if ($do === 'read_all') notification_mark_all_read((int)$me['id']);
    redirect('/notifications.php');
}

$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
$rows = notification_list((int)$me['id'], $beforeId, 51);
$hasMore = count($rows) > 50;
if ($hasMore) array_pop($rows);
$nextBefore = $hasMore && $rows ? (int)end($rows)['id'] : 0;
$unread = notification_unread_count((int)$me['id']);

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between;align-items:center">
    <div>
      <h2 style="margin:0">مرکز اعلان‌ها</h2>
      <p class="hint" style="margin-bottom:0">اعلان‌های سیستمی، ثبت‌نام، احراز هویت و سرویس‌ها در این بخش نمایش داده می‌شوند.</p>
    </div>
    <?php if ($unread > 0): ?>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="read_all">
        <button class="btn btn-ghost" type="submit">خواندن همه (<?= to_persian_digits((string)$unread) ?>)</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <p class="empty">هنوز اعلانی ندارید.</p>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($rows as $row): ?>
        <div style="border:1px solid var(--border,#e5e7eb);border-radius:12px;padding:14px;<?= $row['read_at'] ? '' : 'box-shadow:inset 3px 0 0 currentColor;' ?>">
          <div class="toolbar" style="justify-content:space-between;align-items:flex-start;margin:0">
            <div style="min-width:0">
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <strong><?= e($row['title']) ?></strong>
                <?php if (!$row['read_at']): ?><span class="badge badge-pending">جدید</span><?php endif; ?>
                <span class="badge"><?= e(notification_event_label((string)$row['event_key'])) ?></span>
              </div>
              <?php if ((string)$row['body'] !== ''): ?><div style="margin-top:8px;white-space:pre-wrap"><?= e($row['body']) ?></div><?php endif; ?>
              <div class="hint ltr" style="margin-top:8px"><?= e((string)$row['created_at']) ?></div>
            </div>
            <div class="toolbar" style="margin:0">
              <?php if ((string)$row['action_url'] !== ''): ?><a class="btn btn-sm btn-primary" href="<?= e($row['action_url']) ?>">مشاهده</a><?php endif; ?>
              <?php if (!$row['read_at']): ?>
                <form method="post" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="read">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-sm btn-ghost" type="submit">خواندم</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($nextBefore > 0): ?><div style="margin-top:16px"><a class="btn" href="/notifications.php?before_id=<?= $nextBefore ?>">صفحه بعد</a></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
