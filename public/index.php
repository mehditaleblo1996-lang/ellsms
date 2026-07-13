<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'Dashboard';
$active = 'dashboard';

$scope   = $me['role'] === 'admin' ? '' : ' AND user_id = ' . (int)$me['id'];
$scopeW  = $me['role'] === 'admin' ? '1=1' : 'user_id = ' . (int)$me['id'];

$q = fn(string $sql) => (int)db()->query($sql)->fetch()['c'];

$todaySent   = $q("SELECT COUNT(*) c FROM messages WHERE status IN ('sent','delivered') AND DATE(created_at)=CURDATE(){$scope}");
$todayFailed = $q("SELECT COUNT(*) c FROM messages WHERE status IN ('failed','undelivered') AND DATE(created_at)=CURDATE(){$scope}");
$totalSent   = $q("SELECT COUNT(*) c FROM messages WHERE status IN ('sent','delivered'){$scope}");
$pendingSch  = $q("SELECT COUNT(*) c FROM schedules WHERE status='active'{$scope}");
$inboxToday  = $me['role'] === 'admin'
    ? $q("SELECT COUNT(*) c FROM incoming_messages WHERE DATE(received_at)=CURDATE()")
    : null;

/* Last 7 days volume */
$days = [];
for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-{$i} day"))] = 0;
$rows = db()->query("SELECT DATE(created_at) d, COUNT(*) c FROM messages
                     WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND {$scopeW}
                     GROUP BY DATE(created_at)")->fetchAll();
foreach ($rows as $r) $days[$r['d']] = (int)$r['c'];
$max = max(1, max($days));

/* Recent messages */
$recent = db()->query("SELECT m.*, u.username FROM messages m JOIN users u ON u.id=m.user_id
                       WHERE {$scopeW} ORDER BY m.id DESC LIMIT 8")->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat stat-accent"><div class="stat-label">Sent today</div><div class="stat-value"><?= number_format($todaySent) ?></div></div>
  <div class="stat"><div class="stat-label">Failed today</div><div class="stat-value"><?= number_format($todayFailed) ?></div></div>
  <div class="stat"><div class="stat-label">Scheduled &amp; waiting</div><div class="stat-value"><?= number_format($pendingSch) ?></div></div>
  <?php if ($inboxToday !== null): ?>
    <div class="stat"><div class="stat-label">Received today</div><div class="stat-value"><?= number_format($inboxToday) ?></div></div>
  <?php else: ?>
    <div class="stat"><div class="stat-label">Sent — all time</div><div class="stat-value"><?= number_format($totalSent) ?></div></div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:20px">
  <h2>Messages — last 7 days</h2>
  <div class="bars">
    <?php foreach ($days as $d => $c): ?>
      <div class="bar">
        <div class="bar-v"><?= $c ?></div>
        <div class="bar-fill" style="height:<?= (int)round($c / $max * 110) ?>px"></div>
        <div class="bar-x"><?= date('D', strtotime($d)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Latest messages <a class="btn btn-sm btn-ghost" style="float:right" href="/reports.php">Full report →</a></h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><?php if (is_admin()): ?><th>User</th><?php endif; ?><th>Destination</th><th>Message</th><th>Status</th><th>Time</th></tr>
    <?php foreach ($recent as $m): ?>
      <tr>
        <td class="num"><?= $m['id'] ?></td>
        <?php if (is_admin()): ?><td><?= e($m['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['destination']) ?></td>
        <td class="msg-preview" title="<?= e($m['content']) ?>"><?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?></td>
        <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
        <td class="num"><?= e($m['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="empty">Nothing sent yet — start with <a href="/send.php">Send SMS</a>.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
