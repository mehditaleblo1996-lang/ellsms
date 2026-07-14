<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'Scheduled messages';
$active = 'schedules';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $own = is_admin() ? '' : ' AND user_id = ' . (int)$me['id'];
    if (($_POST['do'] ?? '') === 'cancel') {
        db()->exec("UPDATE ellsms_schedule SET status='cancelled' WHERE id={$id} AND status IN ('active','processing'){$own}");
        flash('info', "Schedule #{$id} cancelled.");
        audit((int)$me['id'], 'schedule.cancel', "#{$id}");
    }
    redirect('/schedules.php');
}

$where = is_admin() ? '1=1' : 's.user_id = ' . (int)$me['id'];
$rows = db()->query("SELECT s.*, u.username FROM ellsms_schedule s JOIN user_ u ON u.id = s.user_id
                     WHERE {$where} ORDER BY FIELD(s.status,'active','processing','done','cancelled'), s.run_at DESC
                     LIMIT 300")->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>Scheduled sends <a class="btn btn-sm btn-primary" style="float:right" href="/send.php">+ New</a></h2>
  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>User</th><?php endif; ?>
      <th>Runs at</th><th>Repeat</th><th>To</th><th>Message</th><th>Status</th><th>Runs</th><th>Last result</th><th></th>
    </tr>
    <?php foreach ($rows as $s): $d = json_decode($s['destinations'], true) ?: []; ?>
      <tr>
        <td class="num"><?= $s['id'] ?></td>
        <?php if (is_admin()): ?><td><?= e($s['username']) ?></td><?php endif; ?>
        <td class="num"><?= e($s['run_at']) ?></td>
        <td><?= $s['repeat_type'] === 'none' ? 'once' : e($s['repeat_type']) ?></td>
        <td class="num"><?= count($d) ?> number(s)</td>
        <td class="msg-preview" title="<?= e($s['content']) ?>"><?= e(mb_strimwidth($s['content'], 0, 50, '…')) ?></td>
        <td><span class="badge badge-<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
        <td class="num"><?= $s['run_count'] ?></td>
        <td class="msg-preview" title="<?= e((string)$s['last_result']) ?>"><?= e(mb_strimwidth((string)$s['last_result'], 0, 40, '…')) ?></td>
        <td>
          <?php if (in_array($s['status'], ['active','processing'], true)): ?>
            <form method="post" onsubmit="return confirm('Cancel schedule #<?= $s['id'] ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <input type="hidden" name="do" value="cancel">
              <button class="btn btn-sm btn-danger">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">No scheduled messages yet. Create one from <a href="/send.php">Send SMS → Schedule for later</a>.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
