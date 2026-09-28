<?php
/**
 * #42 — the Bale messenger channel: settings (platform admin) and the record of Bale messages.
 * A customer sees only their own organization's (or, without one, their own) Bale messages.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'پیام‌رسان بله';
$active = 'bale';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) {
        http_response_code(403);
        exit('فقط مدیر سامانه می‌تواند تنظیمات بله را تغییر دهد.');
    }
    $baseUrl = trim((string)($_POST['bale_base_url'] ?? ''));
    $botId = preg_replace('/\D/', '', from_persian_digits((string)($_POST['bale_bot_id'] ?? ''))) ?? '';
    if ($baseUrl !== '' && preg_match('#^https://#i', $baseUrl) !== 1 && app_env() === 'production') {
        flash('error', 'آدرس سرویس بله باید با https:// شروع شود.');
    } elseif ($baseUrl !== '' && preg_match('#^https?://#i', $baseUrl) !== 1) {
        flash('error', 'آدرس سرویس بله نامعتبر است.');
    } else {
        set_setting('bale_enabled', !empty($_POST['bale_enabled']) ? '1' : '0');
        set_setting('bale_base_url', $baseUrl);
        set_setting('bale_bot_id', $botId);
        set_setting('bale_tps', (string)max(1, min(1000, (int)($_POST['bale_tps'] ?? 20))));
        set_setting('bale_price_credits', (string)max(0, (int)($_POST['bale_price_credits'] ?? 1)));
        audit((int)$me['id'], 'bale.settings', 'enabled=' . (!empty($_POST['bale_enabled']) ? 1 : 0) . ' host=' . (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        flash('success', 'تنظیمات بله ذخیره شد.');
    }
    redirect('/bale-messages.php');
}

$where = ['1=1'];
$params = [];
if (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    if ($orgId > 0) { $where[] = 'organization_id = ?'; $params[] = $orgId; }
    else { $where[] = 'user_id = ?'; $params[] = (int)$me['id']; }
}
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
if ($beforeId > 0) { $where[] = 'id < ?'; $params[] = $beforeId; }
$st = db()->prepare('SELECT id, destination, content, status, provider_message_id, error, cost_credits, created_at
                     FROM ellsms_channel_messages WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 101');
$st->execute($params);
$rows = $st->fetchAll();
$hasMore = count($rows) > 100;
$rows = array_slice($rows, 0, 100);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (is_admin()): ?>
<div class="card">
  <h2>تنظیمات کانال بله</h2>
  <p class="hint">
    پیام از طریق API کسب‌وکاری بله به حساب بله‌ی شماره‌ی گیرنده می‌رسد. کلید دسترسی در متغیر محیطی
    <span class="ltr">BALE_API_ACCESS_KEY</span> نگه داشته می‌شود و هرگز در پایگاه‌داده ذخیره یا اینجا نمایش داده نمی‌شود —
    وضعیت فعلی: <b><?= bale_access_key() !== '' ? 'تنظیم شده' : 'تنظیم نشده' ?></b>.
    کانال فقط وقتی در صفحه‌ی ارسال و API در دسترس است که فعال و کامل باشد (اکنون: <b><?= bale_configured() ? 'آماده' : 'غیرفعال' ?></b>).
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="toolbar">
      <label><input type="checkbox" name="bale_enabled" value="1"<?= bale_setting('bale_enabled', '0') === '1' ? ' checked' : '' ?>> فعال</label>
      <label style="flex:1 1 320px">آدرس سرویس <input type="url" name="bale_base_url" class="ltr" style="width:100%" value="<?= e(bale_setting('bale_base_url', '')) ?>" placeholder="https://…"></label>
      <label>شناسه‌ی بات <input type="text" name="bale_bot_id" class="ltr" value="<?= e(bale_setting('bale_bot_id', '')) ?>"></label>
      <label>سقف ارسال در ثانیه <input type="number" name="bale_tps" class="ltr" min="1" max="1000" value="<?= (int)bale_setting('bale_tps', '20') ?>"></label>
      <label>هزینه‌ی هر پیام (اعتبار) <input type="number" name="bale_price_credits" class="ltr" min="0" value="<?= bale_price_credits() ?>"></label>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>پیام‌های ارسال‌شده از طریق بله</h2>
  <div class="table-wrap">
  <table>
    <tr><th>زمان</th><th>گیرنده</th><th>متن</th><th>وضعیت</th><th>هزینه</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td class="msisdn"><?= e((string)$r['destination']) ?></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'], 0, 60, '…')) ?></td>
        <td><?= $r['status'] === 'accepted' ? 'ارسال شد' : 'ناموفق' ?><?php if ($r['status'] !== 'accepted' && is_admin()): ?> <span class="muted ltr"><?= e(mb_strimwidth((string)$r['error'], 0, 80, '…')) ?></span><?php endif; ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$r['cost_credits']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">هنوز پیامی از طریق بله ارسال نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($hasMore): ?><a class="btn btn-sm" href="?before_id=<?= (int)end($rows)['id'] ?>">موارد قدیمی‌تر ←</a><?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
