<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Support/AuditMongo.php';
$me = require_admin();
$pageTitle = 'لاگ فعالیت‌ها';
$active = 'logs';

$filters = [
    'event_type' => trim($_GET['event_type'] ?? ''),
    'username'   => trim($_GET['username'] ?? ''),
    'user_id'    => trim($_GET['user_id'] ?? ''),
    'ip'         => trim($_GET['ip'] ?? ''),
    'path'       => trim($_GET['path'] ?? ''),
    'request_id' => trim($_GET['request_id'] ?? ''),
];
$per = 100;
$total = audit_mongo_count($filters);
if ($total !== null) {
    [$page, $offset] = pager_offset($total, $per);
} else {
    $page = pager_page();
    $offset = ($page - 1) * $per;
}
$fetched = audit_mongo_list($filters, $per + 1, null, $offset);
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;
// Without a total (Mongo too slow to count), the bar still offers the next page while one exists.
$pages = $total !== null ? pager_pages($total, $per) : $page + ($hasMore ? 1 : 0);

$eventFa = static function (string $event): string {
    return match ($event) {
        'http.request' => 'درخواست HTTP',
        'auth.login_success' => 'ورود موفق',
        'auth.login_failed' => 'ورود ناموفق',
        'auth.logout' => 'خروج',
        'account.password_change_attempt' => 'تغییر رمز عبور',
        default => $event,
    };
};

$qs = static fn(array $extra = []): string => http_build_query(array_filter(array_merge($_GET, $extra), static fn($v) => $v !== null && $v !== ''));

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>جست‌وجوی لاگ</h2>
  <p class="hint">هر درخواست PHP پنل، ورودهای موفق/ناموفق و عملیات POST ثبت می‌شوند. پسورد، توکن، Cookie، CSRF، API Key و Secret قبل از ذخیره‌سازی حذف می‌شوند.</p>
  <form method="get" class="toolbar">
    <label>نوع رویداد<input type="text" name="event_type" value="<?= e($filters['event_type']) ?>" placeholder="auth.login_failed" class="ltr"></label>
    <label>کاربر<input type="text" name="username" value="<?= e($filters['username']) ?>"></label>
    <label>User ID<input type="number" name="user_id" value="<?= e($filters['user_id']) ?>" class="ltr"></label>
    <label>IP<input type="text" name="ip" value="<?= e($filters['ip']) ?>" class="ltr"></label>
    <label>URL<input type="text" name="path" value="<?= e($filters['path']) ?>" placeholder="/settings.php" class="ltr"></label>
    <label>Request ID<input type="text" name="request_id" value="<?= e($filters['request_id']) ?>" class="ltr"></label>
    <button class="btn btn-primary">فیلتر</button>
    <a class="btn btn-ghost" href="/logs.php">پاک کردن فیلتر</a>
  </form>
</div>

<div class="card">
  <h2>رویدادها</h2>
  <?php if (!audit_mongo_manager()): ?>
    <div class="flash flash-error">اتصال MongoDB برای Audit در دسترس نیست. سرویس <span class="ltr">ellsms-mongo</span> و تنظیمات AUDIT_MONGO را بررسی کنید.</div>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <tr><th>زمان</th><th>رویداد</th><th>کاربر</th><th>Actor</th><th>IP</th><th>URL</th><th>Method</th><th>HTTP</th><th>مدت</th><th>Request ID</th></tr>
      <?php foreach ($rows as $r):
        $request = is_array($r['request'] ?? null) ? $r['request'] : [];
      ?>
      <tr>
        <td class="num"><?= e((string)($r['timestamp'] ?? '')) ?></td>
        <td title="<?= e((string)($r['event_type'] ?? '')) ?>"><?= e($eventFa((string)($r['event_type'] ?? ''))) ?></td>
        <td><?= e((string)($r['username'] ?? '—')) ?><?= isset($r['user_id']) && $r['user_id'] !== null ? ' <span class="num">#' . e((string)$r['user_id']) . '</span>' : '' ?></td>
        <td class="num"><?= isset($r['actor_user_id']) && $r['actor_user_id'] !== null ? e((string)$r['actor_user_id']) : '—' ?></td>
        <td class="ltr"><?= e((string)($r['ip'] ?? '')) ?></td>
        <td class="ltr"><?= e((string)($r['path'] ?? '')) ?></td>
        <td class="ltr"><?= e((string)($r['method'] ?? '')) ?></td>
        <td class="num"><?= e((string)($r['status_code'] ?? '')) ?></td>
        <td class="num"><?= isset($r['duration_ms']) ? e(number_format((float)$r['duration_ms'], 1)) . ' ms' : '—' ?></td>
        <td class="request-id"><?= e((string)($r['request_id'] ?? '')) ?></td>
      </tr>
      <?php if ($request): ?>
      <tr><td colspan="10"><details><summary>جزئیات درخواست</summary><pre><?= e(json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details></td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="empty">لاگی با این فیلترها پیدا نشد.</td></tr><?php endif; ?>
    </table>
  </div>
  <?= pager_links($page, $pages, array_filter($filters, 'strlen'), 'page', $total) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
