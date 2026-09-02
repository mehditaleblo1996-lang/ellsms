<?php
/**
 * ELLSMS — Platform Admin -> alerts/incidents (issue #15).
 *
 * PLATFORM ADMIN ONLY, via require_admin() — same guard sms-gateways.php/queue-cancellation.php
 * use. Shows every open/acknowledged incident from the one shared incident model
 * (app/Alerting/AlertManager.php) any alert source in this codebase raises through, plus recent
 * resolved history for audit. The only mutation here is acknowledgement (stops repeat
 * notifications, leaves the incident open) -- there is no "delete"/"dismiss": an incident only
 * ever closes via AlertManager::recover(), called by whatever originally raised it once the
 * underlying condition actually clears.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'هشدارها';
$active = 'alerts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'acknowledge') {
        $incidentId = (int)($_POST['incident_id'] ?? 0);
        $ok = AlertManager::acknowledge($incidentId, (int)$me['id']);
        flash($ok ? 'success' : 'error', $ok ? "هشدار #{$incidentId} تأیید شد؛ دیگر تکرار نمی‌شود." : 'این هشدار قابل تأیید نیست (یافت نشد یا قبلاً تأیید/برطرف شده).');
    }

    // Admin-configurable repeat intervals + email recipient (issue #15's own acceptance criterion:
    // env-only configuration does not satisfy "admin configurable" -- this form writes the same
    // ellsms_settings keys AlertManager::repeatIntervalSeconds() / the email dispatch path read,
    // exactly the same precedence app/telegram.php's own admin-editable settings already use).
    if ($do === 'save_alert_settings') {
        $fields = ['ALERT_REPEAT_SECONDS_WARNING', 'ALERT_REPEAT_SECONDS_CRITICAL', 'ALERT_REPEAT_SECONDS_EMERGENCY'];
        $invalid = [];
        foreach ($fields as $field) {
            $raw = trim((string)($_POST[$field] ?? ''));
            if ($raw === '') {
                set_setting($field, '');
                continue;
            }
            if (!ctype_digit($raw)) {
                $invalid[] = $field;
                continue;
            }
            set_setting($field, $raw);
        }
        $recipient = trim((string)($_POST['alert_email_recipient'] ?? ''));
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $invalid[] = 'alert_email_recipient';
        } else {
            set_setting('alert_email_recipient', $recipient);
        }
        flash($invalid === [] ? 'success' : 'error', $invalid === []
            ? 'تنظیمات هشدار ذخیره شد.'
            : ('مقدار نامعتبر برای: ' . implode(', ', $invalid)));
    }
    redirect('/admin/alerts');
}

$active_incidents = AlertManager::activeIncidents();
$resolved = AlertManager::recentResolvedIncidents(30);

$severityFa = ['warning' => 'هشدار', 'critical' => 'بحرانی', 'emergency' => 'اضطراری'];
$severityColor = ['warning' => '#b7791f', 'critical' => '#c0392b', 'emergency' => '#8e0000'];
$statusFa = ['open' => 'باز', 'acknowledged' => 'تأییدشده', 'resolved' => 'برطرف‌شده'];
$currentRepeat = [
    'warning' => AlertManager::repeatIntervalSeconds(AlertManager::SEVERITY_WARNING),
    'critical' => AlertManager::repeatIntervalSeconds(AlertManager::SEVERITY_CRITICAL),
    'emergency' => AlertManager::repeatIntervalSeconds(AlertManager::SEVERITY_EMERGENCY),
];
$currentRecipient = (string)(setting('alert_email_recipient', env('ALERT_EMAIL_RECIPIENT', '')) ?? '');

require __DIR__ . '/../app/views/header.php';
?>

<div class="card">
  <h2>هشدارهای فعال</h2>
  <p class="muted">هر هشدار از یک منبع واحد (مثلاً قطعی ارائه‌دهنده) می‌آید و در صورت عدم تأیید، طبق فاصله‌ی زمانی هر سطح تکرار می‌شود. تأیید، تکرار را متوقف می‌کند اما هشدار باز می‌ماند تا شرایط واقعاً برطرف شود.</p>
  <div class="table-wrap"><table>
    <tr><th>سطح</th><th>عنوان</th><th>وضعیت</th><th>اولین بار</th><th>آخرین بار</th><th>تعداد تکرار</th><th></th></tr>
    <?php foreach ($active_incidents as $incident): ?>
    <tr>
      <td><strong style="color:<?= e($severityColor[$incident['severity']] ?? '#333') ?>"><?= e($severityFa[$incident['severity']] ?? $incident['severity']) ?></strong></td>
      <td><?= e($incident['title']) ?><div class="muted" style="font-size:.85em"><?= e($incident['message']) ?></div></td>
      <td><?= e($statusFa[$incident['status']] ?? $incident['status']) ?></td>
      <td class="ltr"><?= jdate($incident['first_fired_at']) ?></td>
      <td class="ltr"><?= jdate($incident['last_fired_at']) ?></td>
      <td class="ltr"><?= (int)$incident['fire_count'] ?></td>
      <td>
        <?php if ($incident['status'] === 'open'): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="acknowledge">
          <input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
          <button class="btn btn-primary" onclick="return confirm('این هشدار تأیید شود؟ دیگر یادآوری نمی‌شود تا زمانی که شرایط برطرف شود.')">تأیید</button>
        </form>
        <?php else: ?>
          <span class="muted">تأییدشده</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($active_incidents === []): ?>
      <tr><td colspan="7" class="muted">در حال حاضر هشدار فعالی وجود ندارد.</td></tr>
    <?php endif; ?>
  </table></div>
</div>

<div class="card">
  <h2>تاریخچه‌ی هشدارهای برطرف‌شده</h2>
  <div class="table-wrap"><table>
    <tr><th>سطح</th><th>عنوان</th><th>اولین بار</th><th>برطرف‌شده در</th><th>تعداد تکرار</th></tr>
    <?php foreach ($resolved as $incident): ?>
    <tr>
      <td><?= e($severityFa[$incident['severity']] ?? $incident['severity']) ?></td>
      <td><?= e($incident['title']) ?></td>
      <td class="ltr"><?= jdate($incident['first_fired_at']) ?></td>
      <td class="ltr"><?= jdate($incident['resolved_at']) ?></td>
      <td class="ltr"><?= (int)$incident['fire_count'] ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($resolved === []): ?>
      <tr><td colspan="5" class="muted">هنوز هشداری برطرف نشده است.</td></tr>
    <?php endif; ?>
  </table></div>
</div>

<div class="card">
  <h2>تنظیمات هشدار</h2>
  <p class="muted">فاصله‌ی تکرار یادآوری برای هر سطح (ثانیه) و آدرس ایمیل دریافت‌کننده‌ی هشدارها — بدون نیاز به تغییر کد یا دیپلوی مجدد.</p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_alert_settings">
    <label>هشدار (پیش‌فرض ۱۸۰۰) <input type="number" name="ALERT_REPEAT_SECONDS_WARNING" min="0" value="<?= (int)$currentRepeat['warning'] ?>"></label>
    <label>بحرانی (پیش‌فرض ۳۰۰) <input type="number" name="ALERT_REPEAT_SECONDS_CRITICAL" min="0" value="<?= (int)$currentRepeat['critical'] ?>"></label>
    <label>اضطراری (پیش‌فرض ۱۲۰) <input type="number" name="ALERT_REPEAT_SECONDS_EMERGENCY" min="0" value="<?= (int)$currentRepeat['emergency'] ?>"></label>
    <label>ایمیل دریافت‌کننده <input type="email" name="alert_email_recipient" value="<?= e($currentRecipient) ?>" size="30"></label>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
