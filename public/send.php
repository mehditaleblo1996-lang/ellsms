<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'Send SMS';
$active = 'send';

$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE user_id=? AND group_name<>'' ORDER BY group_name");
$groups->execute([$me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $originator = trim($_POST['originator'] ?? '') ?: ($me['originator'] ?: setting('default_originator', ''));
    $content    = trim($_POST['content'] ?? '');
    $dests      = parse_destinations($_POST['destinations'] ?? '');

    if (!empty($_POST['group'])) {
        $st = db()->prepare('SELECT mobile FROM ellsms_contacts WHERE user_id=? AND group_name=?');
        $st->execute([$me['id'], $_POST['group']]);
        foreach ($st->fetchAll() as $c) {
            $n = normalize_msisdn($c['mobile']);
            if ($n) $dests[] = $n;
        }
        $dests = array_values(array_unique($dests));
    }

    $when = trim($_POST['send_at'] ?? '');
    if (($_POST['mode'] ?? 'now') === 'later' && $when !== '') {
        $runAt = date('Y-m-d H:i:s', strtotime($when));
        if (!$dests) {
            flash('error', 'No valid destination numbers.');
        } elseif ($content === '') {
            flash('error', 'Message content is empty.');
        } elseif ($runAt <= date('Y-m-d H:i:s')) {
            flash('error', 'The scheduled time must be in the future.');
        } else {
            $repeat = in_array($_POST['repeat'] ?? 'none', ['none','daily','weekly','monthly'], true) ? $_POST['repeat'] : 'none';
            db()->prepare('INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, repeat_type)
                           VALUES (?,?,?,?,?,?)')
               ->execute([$me['id'], $originator, json_encode($dests), $content, $runAt, $repeat]);
            audit((int)$me['id'], 'schedule.create', count($dests) . ' dest @ ' . $runAt);
            flash('success', 'Scheduled for ' . $runAt . ($repeat !== 'none' ? " (repeats {$repeat})" : '') . ' — ' . count($dests) . ' number(s).');
            redirect('/schedules.php');
        }
    } else {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info);
        audit((int)$me['id'], 'sms.send', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Sender line (originator)
        <input type="text" name="originator" class="msisdn" value="<?= e($me['originator'] ?: setting('default_originator','')) ?>">
        <div class="hint">The line your recipients see, e.g. 5000435800.</div>
      </label>
      <?php if ($groups): ?>
      <label>Add a contact group
        <select name="group">
          <option value="">— none —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">All numbers from the group are added to the list below.</div>
      </label>
      <?php endif; ?>
    </div>

    <label>Destination numbers
      <textarea name="destinations" placeholder="989121234567&#10;989351234567 — one per line, or separated by comma / space"><?= e($_POST['destinations'] ?? '') ?></textarea>
      <div class="hint">09… numbers are converted to 98… automatically. Duplicates are removed.</div>
    </label>

    <label>Message
      <textarea name="content" id="content" required oninput="counter()"><?= e($_POST['content'] ?? '') ?></textarea>
      <div class="hint" id="cnt">0 characters · 0 part(s)</div>
    </label>

    <div class="form-row">
      <label>When to send
        <select name="mode" id="mode" onchange="document.getElementById('when').style.display=this.value==='later'?'grid':'none'">
          <option value="now">Send now</option>
          <option value="later">Schedule for later</option>
        </select>
      </label>
    </div>

    <div class="form-row" id="when" style="display:none">
      <label>Date &amp; time
        <input type="datetime-local" name="send_at" min="<?= date('Y-m-d\TH:i') ?>">
      </label>
      <label>Repeat
        <select name="repeat">
          <option value="none">Only once</option>
          <option value="daily">Every day</option>
          <option value="weekly">Every week</option>
          <option value="monthly">Every month</option>
        </select>
      </label>
    </div>

    <button class="btn btn-primary" type="submit">Send message</button>
  </form>
</div>

<script>
function counter() {
  const v = document.getElementById('content').value;
  const uni = /[^\x20-\x7E\r\n]/.test(v);
  const len = [...v].length;
  let parts = 0;
  if (len > 0) parts = uni ? (len <= 70 ? 1 : Math.ceil(len / 67)) : (len <= 160 ? 1 : Math.ceil(len / 153));
  document.getElementById('cnt').textContent =
    len + ' characters · ' + parts + ' part(s)' + (uni ? ' · Unicode (Persian)' : '');
}
counter();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
