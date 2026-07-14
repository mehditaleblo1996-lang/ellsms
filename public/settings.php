<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'Settings';
$active = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_setting('api_base_url',       rtrim(trim($_POST['api_base_url'] ?? ''), '/'));
    set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
    audit((int)$me['id'], 'settings.update');
    flash('success', 'Settings saved.');
    redirect('/settings.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>Sending</h2>
  <p class="hint">ELLSMS sends by calling the connected backend's own REST API — the same endpoint used for the very first test send of this project.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>API base URL
        <input type="text" name="api_base_url" value="<?= e(setting('api_base_url', '')) ?>" placeholder="https://rest.example.com">
        <div class="hint">Messages POST to <span class="num">{base}/api/messages/send</span>.</div>
      </label>
      <label>Default sender line (originator)
        <input type="text" name="default_originator" value="<?= e(setting('default_originator', '')) ?>">
      </label>
    </div>
    <button class="btn btn-primary">Save settings</button>
  </form>
</div>

<div class="card">
  <h2>Receiving messages &amp; delivery reports</h2>
  <p>Nothing to configure here — incoming SMS and delivery status updates already flow into the shared database automatically, written by the backend platform's own receiver endpoints. ELLSMS just reads <code class="kbd">inbound_message</code> and <code class="kbd">outbound_message</code>.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
