<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'Sent report';
$active = 'reports';

/* ---------- Filters ---------- */
$from   = $_GET['from']   ?? date('Y-m-d', strtotime('-6 day'));
$to     = $_GET['to']     ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$dest   = trim($_GET['dest'] ?? '');
$text   = trim($_GET['q'] ?? '');
$userId = is_admin() ? (int)($_GET['user_id'] ?? 0) : (int)$me['id'];

$where  = ['m.created_at >= ?', 'm.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
if (!is_admin() || $userId) { $where[] = 'm.user_id = ?'; $params[] = $userId ?: (int)$me['id']; }
if ($status !== '' && in_array($status, ['pending','sent','failed','delivered','undelivered'], true)) {
    $where[] = 'm.status = ?'; $params[] = $status;
}
if ($dest !== '') { $where[] = 'm.destination LIKE ?'; $params[] = '%' . preg_replace('/\D/', '', $dest) . '%'; }
if ($text !== '') { $where[] = 'm.content LIKE ?';     $params[] = '%' . $text . '%'; }
$W = implode(' AND ', $where);

/* ---------- Summary ---------- */
$sum = db()->prepare("SELECT COUNT(*) total,
        SUM(status IN ('sent','delivered')) ok,
        SUM(status IN ('failed','undelivered')) bad,
        SUM(status = 'delivered') dlv,
        COALESCE(SUM(parts),0) parts
      FROM messages m WHERE {$W}");
$sum->execute($params);
$S = $sum->fetch();

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    $st = db()->prepare("SELECT m.id, u.username, m.originator, m.destination, m.content, m.parts,
                                m.status, m.api_message_id, m.created_at, m.delivered_at, m.error
                         FROM messages m JOIN users u ON u.id = m.user_id
                         WHERE {$W} ORDER BY m.id DESC LIMIT 100000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-report-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (Persian) correctly
    fputcsv($out, ['id','user','originator','destination','content','parts','status','api_message_id','created_at','delivered_at','error']);
    while ($r = $st->fetch()) fputcsv($out, $r);
    exit;
}

/* ---------- Paged rows ---------- */
$per  = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$cnt  = (int)$S['total'];
$pages = max(1, (int)ceil($cnt / $per));
$off  = ($page - 1) * $per;

$st = db()->prepare("SELECT m.*, u.username FROM messages m JOIN users u ON u.id = m.user_id
                     WHERE {$W} ORDER BY m.id DESC LIMIT {$per} OFFSET {$off}");
$st->execute($params);
$rows = $st->fetchAll();

$users = is_admin() ? db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll() : [];

$qs = fn(array $extra = []) => http_build_query(array_merge($_GET, $extra));
require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Messages</div><div class="stat-value"><?= number_format($cnt) ?></div></div>
  <div class="stat"><div class="stat-label">Sent / delivered</div><div class="stat-value"><?= number_format((int)$S['ok']) ?></div></div>
  <div class="stat"><div class="stat-label">Failed</div><div class="stat-value"><?= number_format((int)$S['bad']) ?></div></div>
  <div class="stat"><div class="stat-label">Parts (credits)</div><div class="stat-value"><?= number_format((int)$S['parts']) ?></div></div>
</div>

<div class="card" style="margin-top:20px">
  <form method="get" class="toolbar">
    <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <label>Status
      <select name="status">
        <option value="">All</option>
        <?php foreach (['sent','delivered','failed','undelivered','pending'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (is_admin()): ?>
    <label>User
      <select name="user_id">
        <option value="0">All users</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Destination <input type="text" name="dest" value="<?= e($dest) ?>" placeholder="9891…"></label>
    <label>Text contains <input type="text" name="q" value="<?= e($text) ?>"></label>
    <button class="btn btn-primary">Filter</button>
    <a class="btn" href="/reports.php?<?= e($qs(['export' => 1])) ?>">Export CSV</a>
  </form>

  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>User</th><?php endif; ?>
      <th>From</th><th>To</th><th>Message</th><th>Parts</th><th>Status</th><th>Gateway ID</th><th>Sent</th><th>Delivered</th>
    </tr>
    <?php foreach ($rows as $m): ?>
      <tr>
        <td class="num"><?= $m['id'] ?></td>
        <?php if (is_admin()): ?><td><?= e($m['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['originator']) ?></td>
        <td class="msisdn"><?= e($m['destination']) ?></td>
        <td class="msg-preview" title="<?= e($m['content'] . ($m['error'] ? "\n\nError: " . $m['error'] : '')) ?>">
          <?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?>
        </td>
        <td class="num"><?= $m['parts'] ?></td>
        <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
        <td class="num"><?= e((string)$m['api_message_id']) ?></td>
        <td class="num"><?= e((string)$m['sent_at']) ?></td>
        <td class="num"><?= e((string)$m['delivered_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">No messages match these filters.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
    <span class="btn btn-sm btn-ghost">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
