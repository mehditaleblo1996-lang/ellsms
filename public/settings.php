<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'Settings';
$active = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_setting('vesal_rest_url',     rtrim(trim($_POST['vesal_rest_url'] ?? ''), '/'));
    set_setting('vesal_username',     trim($_POST['vesal_username'] ?? ''));
    if (trim($_POST['vesal_password'] ?? '') !== '') {
        set_setting('vesal_password', $_POST['vesal_password']);
    }
    set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
    audit((int)$me['id'], 'settings.update');
    flash('success', 'Settings saved.');
    redirect('/settings.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>SMS gateway (Vesal / Armaghan)</h2>
  <p class="hint">ELLSMS calls this gateway directly for every send — the same one negar-python's own backend uses (<code class="kbd">common/smsgateway/vesal</code>). These values only live in the ELLSMS database; negar-python keeps its own copy in its <code class="kbd">.env</code> and is unaffected by changes here.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Vesal REST base URL
        <input type="text" name="vesal_rest_url" value="<?= e(setting('vesal_rest_url', '')) ?>" placeholder="http://192.168.2.27:8081/backend">
        <div class="hint">Messages POST to <span class="num">{base}/OneToMany</span>.</div>
      </label>
      <label>Vesal username
        <input type="text" name="vesal_username" value="<?= e(setting('vesal_username', 'negar')) ?>">
      </label>
      <label>Vesal password
        <input type="password" name="vesal_password" placeholder="<?= setting('vesal_password') ? '•••••••• (unchanged)' : 'not set' ?>">
        <div class="hint">Leave blank to keep the current password.</div>
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
  <p>Nothing to configure here — incoming SMS and delivery status updates already flow into the shared database automatically, written by negar-python's own <code class="kbd">/mo</code> and <code class="kbd">/delivery</code> endpoints (already registered with your Vesal/Armaghan account). ELLSMS just reads <code class="kbd">inbound_message</code> and <code class="kbd">outbound_message</code>.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
