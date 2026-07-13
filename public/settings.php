<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'Settings';
$active = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'regen_token') {
        set_setting('webhook_token', bin2hex(random_bytes(12)));
        audit((int)$me['id'], 'settings.webhook_token');
        flash('success', 'New webhook token generated. Update the URLs at your SMS provider.');
    } else {
        set_setting('api_base_url',       rtrim(trim($_POST['api_base_url'] ?? ''), '/'));
        set_setting('default_sender_id',  (string)max(0, (int)($_POST['default_sender_id'] ?? 1)));
        set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
        audit((int)$me['id'], 'settings.update');
        flash('success', 'Settings saved.');
    }
    redirect('/settings.php');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain');
$token  = setting('webhook_token', '');

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>SMS gateway</h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>API base URL
        <input type="text" name="api_base_url" value="<?= e(setting('api_base_url', 'https://rest.ravixops.com')) ?>">
        <div class="hint">Messages are sent to <span class="num">{base}/api/messages/send</span>.</div>
      </label>
      <label>Default sender_user_id
        <input type="number" name="default_sender_id" value="<?= e(setting('default_sender_id', '1')) ?>">
        <div class="hint">Used when a user has no gateway sender id of their own.</div>
      </label>
      <label>Default sender line (originator)
        <input type="text" name="default_originator" value="<?= e(setting('default_originator', '')) ?>">
      </label>
    </div>
    <button class="btn btn-primary">Save settings</button>
  </form>
</div>

<div class="card">
  <h2>Webhooks (give these URLs to your SMS provider)</h2>
  <p>Incoming (received) messages:</p>
  <p><code class="kbd"><?= e($base) ?>/api/incoming.php?token=<?= e($token) ?></code></p>
  <p class="hint">POST JSON like <span class="num">{"sender":"98912…","recipient":"5000435800","content":"…"}</span> — common field aliases (from/to/text/message/originator/destination) are accepted too.</p>
  <p>Delivery reports (status updates):</p>
  <p><code class="kbd"><?= e($base) ?>/api/dlr.php?token=<?= e($token) ?></code></p>
  <p class="hint">POST JSON like <span class="num">{"message_id":"…","status":"delivered"}</span>. Statuses mapping to delivered/undelivered/failed are recognized.</p>
  <form method="post" onsubmit="return confirm('Generate a new token? Old webhook URLs will stop working.')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="regen_token">
    <button class="btn btn-danger">Generate a new webhook token</button>
  </form>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
