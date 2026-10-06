<?php
/**
 * #45 — Customer database connector (app/RemoteDb, docs/remote-db.md).
 *
 * Platform admins create and manage connections here: whose account the rows are sent as, how to
 * reach the customer's database, which table/columns hold what, and which status words to write
 * back. A customer who has a connection sees a read-only health card for it on the same URL.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$isAdmin = is_admin();
$pageTitle = 'اتصال به دیتابیس مشتری';
$active = 'remote_db';

/* ---------- small helpers for this page ---------- */
$fmt = static fn(int|string|null $n): string => to_persian_digits(number_format((int)$n));
$stateBadge = static function (array $c): string {
    if (!(int)$c['enabled']) return '<span class="badge badge-closed">متوقف</span>';
    if ((int)$c['consecutive_failures'] > 0) return '<span class="badge badge-failed">خطا</span>';
    if ($c['last_success_at'] === null) return '<span class="badge badge-pending">در انتظار اولین اجرا</span>';
    if (strtotime((string)$c['last_success_at']) < time() - max(120, 6 * (int)$c['poll_interval_s'])) return '<span class="badge badge-pending">کند / متوقف؟</span>';
    return '<span class="badge badge-active">فعال</span>';
};
$targetLabel = static function (array $r): string {
    [$t] = remote_db_target_state((string)$r['state'], $r['item_status'] ?? null, $r['delivery_status'] ?? null);
    return $t === null ? 'در صف' : REMOTE_DB_STATUS_KEYS[$t];
};
$tablesReady = true;
try { db()->query('SELECT 1 FROM ellsms_remote_db_connections LIMIT 0'); } catch (PDOException) { $tablesReady = false; }

/* ======================================================================
   Customer (non-admin): read-only view of their own connections
   ====================================================================== */
if (!$isAdmin) {
    $rows = [];
    if ($tablesReady) {
        $st = db()->prepare('SELECT * FROM ellsms_remote_db_connections WHERE user_id = ? ORDER BY id');
        $st->execute([(int)$me['id']]);
        $rows = $st->fetchAll();
    }
    require __DIR__ . '/../app/views/header.php';
    ?>
    <div class="card">
      <h2>اتصال به دیتابیس</h2>
      <p class="hint">پیام‌هایی که در جدول دیتابیس خودتان ثبت می‌کنید، از حساب شما ارسال می‌شوند و وضعیت ارسال و تحویل در همان ردیف نوشته می‌شود. تنظیمات این سرویس را پشتیبانی انجام می‌دهد.</p>
      <?php if (!$rows): ?><p class="empty">برای حساب شما اتصالی تعریف نشده است. برای فعال‌سازی با پشتیبانی تماس بگیرید.</p><?php endif; ?>
      <?php foreach ($rows as $c): $s = remote_db_stats((int)$c['id']); ?>
        <div class="subsection">
          <div class="subsection-title"><?= e((string)$c['name']) ?> <?= $stateBadge($c) ?></div>
          <div class="grid grid-4">
            <div class="stat"><div class="stat-label">برداشته‌شده امروز</div><div class="stat-value"><?= $fmt($s['taken'] ?? 0) ?></div></div>
            <div class="stat"><div class="stat-label">ارسال‌شده</div><div class="stat-value"><?= $fmt($s['sent'] ?? 0) ?></div></div>
            <div class="stat"><div class="stat-label">تحویل‌شده</div><div class="stat-value"><?= $fmt($s['delivered'] ?? 0) ?></div></div>
            <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= $fmt(($s['refused'] ?? 0) + ($s['send_failed'] ?? 0)) ?></div></div>
          </div>
          <p class="hint">
            آخرین اجرای موفق: <?= $c['last_success_at'] ? jdate((string)$c['last_success_at']) : '—' ?>
            <?php if ($c['last_error']): ?> · <span class="error">آخرین خطا: <?= e((string)$c['last_error']) ?></span><?php endif; ?>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$me = require_admin();

if (!$tablesReady) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>اتصال به دیتابیس مشتری</h2><p class="error">جدول‌های این بخش هنوز ساخته نشده‌اند. مایگریشن <code class="ltr">2026_10_06_remote_db_connections.sql</code> را اجرا کنید (make db-migrations-apply).</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* ======================================================================
   Form handling
   ====================================================================== */

/**
 * Builds a connection array from the posted form (validated). Returns [errors, data].
 * $existing is the saved row when editing, so an empty password field keeps the stored one.
 */
function remote_db_form_read(array $post, ?array $existing): array {
    $errors = [];
    $d = [];
    $d['name'] = trim((string)($post['name'] ?? ''));
    if ($d['name'] === '' || mb_strlen($d['name']) > 120) $errors['name'] = 'نام اتصال لازم است (حداکثر ۱۲۰ نویسه).';

    $userRef = trim((string)($post['user'] ?? ''));
    $userId = ctype_digit($userRef) ? (int)$userRef : (backend_find_user_id_by_username($userRef) ?? 0);
    $owner = $userId > 0 ? backend_find_user_by_id($userId) : null;
    if (!$owner || !has_panel_access($owner)) {
        $errors['user'] = 'کاربر پیدا نشد یا دسترسی پنل ندارد.';
    }
    $d['user_id'] = $userId;

    $d['driver'] = (string)($post['driver'] ?? '');
    if (!isset(REMOTE_DB_DRIVERS[$d['driver']])) $errors['driver'] = 'نوع دیتابیس نامعتبر است.';
    $d['host'] = trim((string)($post['host'] ?? ''));
    if (!remote_db_host_valid($d['host'])) $errors['host'] = 'آدرس سرور باید نام دامنه یا IP باشد.';
    $d['port'] = (int)($post['port'] ?? 0);
    if ($d['port'] < 1 || $d['port'] > 65535) $errors['port'] = 'پورت نامعتبر است.';
    $d['database_name'] = trim((string)($post['database_name'] ?? ''));
    if (!remote_db_database_name_valid($d['database_name'])) $errors['database_name'] = 'نام دیتابیس فقط حروف لاتین، عدد، زیرخط، خط تیره.';
    $d['username'] = trim((string)($post['username'] ?? ''));
    if ($d['username'] === '' || mb_strlen($d['username']) > 128) $errors['username'] = 'نام کاربری دیتابیس لازم است.';
    $d['password'] = (string)($post['password'] ?? '');
    if ($existing === null && $d['password'] === '') $errors['password'] = 'رمز دیتابیس لازم است.';
    if (mb_strlen($d['password']) > 256) $errors['password'] = 'رمز بیش از حد طولانی است.';
    $d['tls_mode'] = in_array($post['tls_mode'] ?? '', REMOTE_DB_TLS_MODES, true) ? (string)$post['tls_mode'] : 'prefer';

    $bounded = static function (string $key, int $min, int $max, int $default) use ($post): int {
        $v = isset($post[$key]) && $post[$key] !== '' ? (int)$post[$key] : $default;
        return max($min, min($max, $v));
    };
    $d['connect_timeout_s'] = $bounded('connect_timeout_s', 1, 120, 10);
    $d['query_timeout_s'] = $bounded('query_timeout_s', 5, 600, 60);
    $d['batch_size'] = $bounded('batch_size', 1, 1000, 200);
    $d['poll_interval_s'] = $bounded('poll_interval_s', 2, 3600, 10);
    $d['status_sync_interval_s'] = $bounded('status_sync_interval_s', 5, 3600, 15);
    $d['delivery_wait_hours'] = $bounded('delivery_wait_hours', 1, 720, 72);
    $d['retry_hours'] = $bounded('retry_hours', 1, 168, 24);

    $d['send_enabled'] = !empty($post['send_enabled']) ? 1 : 0;
    $d['status_writeback'] = !empty($post['status_writeback']) ? 1 : 0;
    $d['inbound_enabled'] = !empty($post['inbound_enabled']) ? 1 : 0;
    $d['enabled'] = !empty($post['enabled']) ? 1 : 0;
    $d['message_class'] = ($post['message_class'] ?? '') === MESSAGE_CLASS_ADVERTISING ? MESSAGE_CLASS_ADVERTISING : MESSAGE_CLASS_BULK_CAMPAIGN;

    $d['default_originator'] = normalize_originator((string)($post['default_originator'] ?? '')) ?? '';
    if ($d['default_originator'] !== '' && $owner) {
        $u = ['id' => (int)$owner['id'], 'role' => $owner['is_admin'] ? 'admin' : 'user', 'originator' => $owner['originator'] ?? '', 'organization_id' => user_primary_organization_id_for_display((int)$owner['id'])];
        if (!can_use_originator($u, $d['default_originator'])) $errors['default_originator'] = 'این خط برای این کاربر مجاز نیست.';
    }

    [$mapErrors, $d['outbound'], $d['status_values'], $d['inbound']] = remote_db_validate_mapping(
        (array)($post['out'] ?? []), (array)($post['st'] ?? []), (array)($post['in'] ?? []), (bool)$d['inbound_enabled']
    );
    if (empty($d['outbound']['originator']) && $d['default_originator'] === '') {
        $mapErrors['default_originator'] = 'وقتی ستون خط فرستنده نگاشت نشده، «خط پیش‌فرض» لازم است.';
    }
    return [$errors + $mapErrors, $d];
}

$formErrors = [];
$formData = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $existing = $id > 0 ? remote_db_connection_load($id) : null;

    if ($do === 'save' || $do === 'test_form') {
        [$formErrors, $formData] = remote_db_form_read($_POST, $existing);
        if ($formErrors === [] && $do === 'test_form') {
            // Tests the values on the form, unsaved: the stored password unless a new one was typed.
            $probe = $formData + ['id' => $id, 'outbound' => $formData['outbound'], 'inbound' => $formData['inbound']];
            try {
                $password = $formData['password'] !== '' ? $formData['password'] : ($existing ? remote_db_decrypt_password($existing) : '');
                $testResult = remote_db_test($probe, $password);
            } catch (RemoteDbException $e) {
                $testResult = ['ok' => false, 'message' => $e->getMessage(), 'checks' => []];
            }
        } elseif ($formErrors === []) {
            try {
                $secret = $formData['password'] !== '' ? remote_db_encrypt_password($formData['password']) : null;
                $orgId = user_primary_organization_id_for_display($formData['user_id']);
                $cols = [
                    'name' => $formData['name'], 'user_id' => $formData['user_id'], 'organization_id' => $orgId,
                    'enabled' => $formData['enabled'], 'driver' => $formData['driver'], 'host' => $formData['host'],
                    'port' => $formData['port'], 'database_name' => $formData['database_name'], 'username' => $formData['username'],
                    'tls_mode' => $formData['tls_mode'], 'connect_timeout_s' => $formData['connect_timeout_s'],
                    'query_timeout_s' => $formData['query_timeout_s'], 'send_enabled' => $formData['send_enabled'],
                    'status_writeback' => $formData['status_writeback'], 'inbound_enabled' => $formData['inbound_enabled'],
                    'default_originator' => $formData['default_originator'], 'message_class' => $formData['message_class'],
                    'batch_size' => $formData['batch_size'], 'poll_interval_s' => $formData['poll_interval_s'],
                    'status_sync_interval_s' => $formData['status_sync_interval_s'],
                    'delivery_wait_hours' => $formData['delivery_wait_hours'], 'retry_hours' => $formData['retry_hours'],
                    'outbound_mapping_json' => json_encode($formData['outbound'], JSON_UNESCAPED_UNICODE),
                    'status_values_json' => json_encode($formData['status_values'], JSON_UNESCAPED_UNICODE),
                    'inbound_mapping_json' => $formData['inbound'] ? json_encode($formData['inbound'], JSON_UNESCAPED_UNICODE) : null,
                ];
                if ($secret !== null) {
                    $cols += ['password_ciphertext' => $secret['ciphertext'], 'password_nonce' => $secret['nonce'], 'password_tag' => $secret['tag'], 'key_fingerprint' => $secret['fingerprint']];
                }
                $db = db();
                if ($existing) {
                    $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($cols)));
                    // config_version moves so a running worker drops its cached connection; next_run_at
                    // is cleared so the new settings are used right away instead of after a backoff.
                    $db->prepare("UPDATE ellsms_remote_db_connections SET {$set}, config_version = config_version + 1, next_run_at = NULL, consecutive_failures = 0 WHERE id = ?")
                       ->execute(array_merge(array_values($cols), [$id]));
                    if ($formData['inbound_enabled'] && !(int)$existing['inbound_enabled'] && (int)$existing['inbound_backend_cursor'] === 0 && (int)$existing['inbound_ellsms_cursor'] === 0) {
                        remote_db_inbound_cursors_to_now($id);
                    }
                    remote_db_event($id, 'info', 'config_saved', 'تنظیمات توسط ' . ($me['username'] ?? ('#' . $me['id'])) . ' ذخیره شد.');
                } else {
                    $cols['created_by'] = (int)$me['id'];
                    $db->prepare('INSERT INTO ellsms_remote_db_connections (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                       ->execute(array_values($cols));
                    $id = (int)$db->lastInsertId();
                    remote_db_inbound_cursors_to_now($id);
                    remote_db_event($id, 'info', 'created', 'اتصال ساخته شد.');
                }
                audit((int)$me['id'], 'remote_db.save', '#' . $id . ' ' . $formData['name'] . ' user=' . $formData['user_id'] . ' driver=' . $formData['driver'] . ' enabled=' . $formData['enabled']);
                flash('success', 'تنظیمات اتصال ذخیره شد.');
                redirect('/remote-db.php?id=' . $id);
            } catch (RemoteDbException $e) {
                $formErrors['password'] = $e->getMessage();
            }
        }
    } elseif ($existing && $do === 'test') {
        $testResult = remote_db_test($existing);
        db()->prepare('UPDATE ellsms_remote_db_connections SET last_test_at = NOW(), last_test_result = ? WHERE id = ?')
            ->execute([mb_strimwidth(($testResult['ok'] ? 'OK: ' : 'FAIL: ') . $testResult['message'], 0, 480, '…'), $id]);
        $_GET['id'] = (string)$id;
    } elseif ($existing && $do === 'toggle') {
        db()->prepare('UPDATE ellsms_remote_db_connections SET enabled = 1 - enabled, next_run_at = NULL WHERE id = ?')->execute([$id]);
        $now = (int)$existing['enabled'] ? 'متوقف' : 'فعال';
        remote_db_event($id, 'info', (int)$existing['enabled'] ? 'paused' : 'resumed', "اتصال {$now} شد.");
        audit((int)$me['id'], 'remote_db.toggle', '#' . $id . ' -> ' . ((int)$existing['enabled'] ? 'off' : 'on'));
        flash('info', "اتصال {$now} شد.");
        redirect('/remote-db.php?id=' . $id);
    } elseif ($existing && $do === 'run_now') {
        db()->prepare('UPDATE ellsms_remote_db_connections SET next_run_at = NULL, next_status_sync_at = NULL, consecutive_failures = 0 WHERE id = ?')->execute([$id]);
        flash('info', 'اتصال در اولین دور worker (چند ثانیه) اجرا می‌شود.');
        redirect('/remote-db.php?id=' . $id);
    } elseif ($existing && $do === 'resync') {
        // Rewrites every still-open row's status on the customer's side on the next sync.
        db()->prepare("UPDATE ellsms_remote_db_rows SET written_signature = '' WHERE connection_id = ? AND final = 0")->execute([$id]);
        db()->prepare('UPDATE ellsms_remote_db_connections SET next_status_sync_at = NULL WHERE id = ?')->execute([$id]);
        remote_db_event($id, 'info', 'resync_requested', 'بازنویسی وضعیت ردیف‌های باز درخواست شد.');
        flash('info', 'وضعیت ردیف‌های باز در دور بعد دوباره نوشته می‌شود.');
        redirect('/remote-db.php?id=' . $id);
    } elseif ($existing && $do === 'delete') {
        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_remote_db_rows WHERE connection_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            flash('error', 'این اتصال پیام ارسال کرده و سابقه‌اش باید بماند؛ به‌جای حذف، آن را متوقف کنید.');
        } else {
            db()->prepare('DELETE FROM ellsms_remote_db_events WHERE connection_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_remote_db_connections WHERE id = ?')->execute([$id]);
            audit((int)$me['id'], 'remote_db.delete', '#' . $id);
            flash('info', 'اتصال حذف شد.');
        }
        redirect('/remote-db.php');
    }
}

/* ======================================================================
   Views
   ====================================================================== */
$view = isset($_GET['new']) || ($formData !== null && empty($_POST['id'])) ? 'form'
      : (isset($_GET['edit']) || ($formData !== null && !empty($_POST['id'])) ? 'form'
      : (isset($_GET['id']) ? 'detail' : 'list'));
$editId = (int)($_GET['edit'] ?? $_POST['id'] ?? 0);
$detailId = (int)($_GET['id'] ?? 0);

require __DIR__ . '/../app/views/header.php';

if ($view === 'list'):
    $rows = db()->query('SELECT * FROM ellsms_remote_db_connections ORDER BY id DESC')->fetchAll();
    $usernames = backend_usernames_by_ids(array_column($rows, 'user_id'));
    ?>
    <div class="card">
      <div class="toolbar" style="justify-content:space-between">
        <h2 style="margin:0">اتصال به دیتابیس مشتری</h2>
        <a class="btn btn-primary" href="/remote-db.php?new=1">اتصال جدید</a>
      </div>
      <p class="hint">
        مشتری پیام‌ها را در جدول دیتابیس خودش (MySQL/MariaDB، SQL Server یا PostgreSQL) ثبت می‌کند؛ ELLSMS ردیف‌های در انتظار را برمی‌دارد،
        از حساب همان کاربر (با کیف پول، تعرفه، سقف پلن و فیلتر محتوا) ارسال می‌کند و وضعیت ارسال/تحویل و پیام‌های دریافتی را در دیتابیس او می‌نویسد.
        اجرای این سرویس با کانتینر <code class="ltr">remote-db-worker</code> است.
      </p>
      <?php foreach (REMOTE_DB_DRIVERS as $key => $meta): if (!remote_db_driver_available($key)): ?>
        <p class="error">درایور <?= e($meta['label']) ?> (<span class="ltr"><?= e($meta['pdo']) ?></span>) روی این سرور نصب نیست؛ ایمیج را دوباره بسازید (docker/Dockerfile).</p>
      <?php endif; endforeach; ?>
      <div class="table-wrap">
      <table>
        <tr><th>نام</th><th>کاربر</th><th>دیتابیس</th><th>وضعیت</th><th>امروز (برداشت / ارسال / تحویل / ناموفق)</th><th>آخرین موفقیت</th><th>آخرین خطا</th></tr>
        <?php foreach ($rows as $c): $s = remote_db_stats((int)$c['id']); ?>
          <tr>
            <td><a href="/remote-db.php?id=<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a></td>
            <td><?= e((string)($usernames[(int)$c['user_id']] ?? ('#' . $c['user_id']))) ?></td>
            <td class="ltr"><?= e(REMOTE_DB_DRIVERS[$c['driver']]['label'] ?? $c['driver']) ?> · <?= e($c['host'] . ':' . $c['port'] . '/' . $c['database_name']) ?></td>
            <td><?= $stateBadge($c) ?></td>
            <td class="num"><?= $fmt($s['taken'] ?? 0) ?> / <?= $fmt($s['sent'] ?? 0) ?> / <?= $fmt($s['delivered'] ?? 0) ?> / <?= $fmt(($s['refused'] ?? 0) + ($s['send_failed'] ?? 0)) ?></td>
            <td class="num"><?= $c['last_success_at'] ? jdate((string)$c['last_success_at']) : '—' ?></td>
            <td class="muted"><?= e(mb_strimwidth((string)($c['last_error'] ?? ''), 0, 90, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">هنوز اتصالی تعریف نشده است.</td></tr><?php endif; ?>
      </table>
      </div>
    </div>
<?php
elseif ($view === 'detail'):
    $c = remote_db_connection_load($detailId);
    if (!$c) { echo '<div class="card"><p class="error">اتصال پیدا نشد.</p></div>'; require __DIR__ . '/../app/views/footer.php'; exit; }
    $s = remote_db_stats($detailId);
    $s7 = remote_db_stats($detailId, date('Y-m-d 00:00:00', strtotime('-6 days')));
    $username = backend_usernames_by_ids([(int)$c['user_id']])[(int)$c['user_id']] ?? ('#' . $c['user_id']);
    $events = db()->prepare('SELECT * FROM ellsms_remote_db_events WHERE connection_id = ? ORDER BY id DESC LIMIT 40');
    $events->execute([$detailId]);
    $events = $events->fetchAll();
    $recent = db()->prepare(
        'SELECT r.*, bi.status AS item_status, bi.delivery_status, bi.provider_message_id
           FROM ellsms_remote_db_rows r LEFT JOIN ellsms_bulk_items bi ON bi.id = r.bulk_item_id
          WHERE r.connection_id = ? ORDER BY r.id DESC LIMIT 30'
    );
    $recent->execute([$detailId]);
    $recent = $recent->fetchAll();
    $open = db()->prepare("SELECT SUM(final = 0) AS open_rows, SUM(state = 'claimed') AS waiting FROM ellsms_remote_db_rows WHERE connection_id = ?");
    $open->execute([$detailId]);
    $open = $open->fetch();
    ?>
    <div class="card">
      <div class="toolbar" style="justify-content:space-between">
        <h2 style="margin:0"><?= e((string)$c['name']) ?> <?= $stateBadge($c) ?></h2>
        <div class="toolbar">
          <a class="btn" href="/remote-db.php">بازگشت</a>
          <a class="btn btn-primary" href="/remote-db.php?edit=<?= $detailId ?>">ویرایش</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="do" value="test"><button class="btn">تست اتصال</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="do" value="run_now"><button class="btn">اجرای فوری</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="do" value="toggle"><button class="btn <?= (int)$c['enabled'] ? 'btn-danger' : 'btn-primary' ?>"><?= (int)$c['enabled'] ? 'توقف' : 'فعال‌سازی' ?></button></form>
        </div>
      </div>

      <?php if ($testResult !== null): ?>
        <div class="flash <?= $testResult['ok'] ? 'flash-success' : 'flash-error' ?>"><?= e($testResult['message']) ?></div>
        <div class="table-wrap"><table>
          <?php foreach ($testResult['checks'] as [$label, $ok, $detail]): ?>
            <tr><td><?= e($label) ?></td><td><?= $ok ? '✅' : '❌' ?></td><td class="ltr"><?= e($detail) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>

      <div class="summary-grid grid grid-4">
        <div class="summary-item"><div class="summary-label">کاربر</div><div class="summary-value"><?= e((string)$username) ?></div></div>
        <div class="summary-item"><div class="summary-label">دیتابیس</div><div class="summary-value ltr"><?= e(REMOTE_DB_DRIVERS[$c['driver']]['label'] ?? $c['driver']) ?> — <?= e($c['host'] . ':' . $c['port'] . '/' . $c['database_name']) ?></div></div>
        <div class="summary-item"><div class="summary-label">جدول خروجی</div><div class="summary-value ltr"><?= e((string)$c['outbound']['table']) ?></div></div>
        <div class="summary-item"><div class="summary-label">بخش‌ها</div><div class="summary-value"><?= (int)$c['send_enabled'] ? 'ارسال' : '<s>ارسال</s>' ?> · <?= (int)$c['status_writeback'] ? 'نوشتن وضعیت' : '<s>نوشتن وضعیت</s>' ?> · <?= (int)$c['inbound_enabled'] ? 'پیام دریافتی' : '<s>پیام دریافتی</s>' ?></div></div>
        <div class="summary-item"><div class="summary-label">آخرین اجرا / موفقیت</div><div class="summary-value"><?= $c['last_run_at'] ? jdate((string)$c['last_run_at']) : '—' ?> / <?= $c['last_success_at'] ? jdate((string)$c['last_success_at']) : '—' ?></div></div>
        <div class="summary-item"><div class="summary-label">خطاهای پشت‌سرهم</div><div class="summary-value"><?= $fmt($c['consecutive_failures']) ?></div></div>
        <div class="summary-item"><div class="summary-label">ردیف‌های باز (در انتظار نتیجه) / منتظر صف</div><div class="summary-value"><?= $fmt($open['open_rows'] ?? 0) ?> / <?= $fmt($open['waiting'] ?? 0) ?></div></div>
        <div class="summary-item"><div class="summary-label">آخرین تست</div><div class="summary-value"><?= $c['last_test_at'] ? jdate((string)$c['last_test_at']) . ' — ' . e((string)$c['last_test_result']) : '—' ?></div></div>
      </div>
      <?php if ($c['last_error']): ?><p class="error">آخرین خطا (<?= $c['last_error_at'] ? jdate((string)$c['last_error_at']) : '' ?>): <span class="ltr"><?= e((string)$c['last_error']) ?></span></p><?php endif; ?>
    </div>

    <div class="card" style="margin-top:18px">
      <h2>آمار</h2>
      <?php foreach (['امروز' => $s, '۷ روز اخیر' => $s7] as $label => $st): ?>
        <div class="subsection-title"><?= $label ?></div>
        <div class="grid grid-4">
          <div class="stat stat-accent"><div class="stat-label">برداشته‌شده</div><div class="stat-value"><?= $fmt($st['taken'] ?? 0) ?></div></div>
          <div class="stat"><div class="stat-label">در صف ارسال</div><div class="stat-value"><?= $fmt(($st['in_queue'] ?? 0) + ($st['waiting'] ?? 0)) ?></div></div>
          <div class="stat"><div class="stat-label">ارسال‌شده</div><div class="stat-value"><?= $fmt($st['sent'] ?? 0) ?></div></div>
          <div class="stat"><div class="stat-label">تحویل‌شده</div><div class="stat-value"><?= $fmt($st['delivered'] ?? 0) ?></div></div>
          <div class="stat"><div class="stat-label">تحویل‌نشده</div><div class="stat-value"><?= $fmt($st['not_delivered'] ?? 0) ?></div></div>
          <div class="stat"><div class="stat-label">ردشده قبل از صف</div><div class="stat-value"><?= $fmt($st['refused'] ?? 0) ?></div></div>
          <div class="stat"><div class="stat-label">ارسال ناموفق</div><div class="stat-value"><?= $fmt($st['send_failed'] ?? 0) ?></div></div>
        </div>
      <?php endforeach; ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('وضعیت همه‌ی ردیف‌های باز دوباره در دیتابیس مشتری نوشته شود؟')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="do" value="resync">
        <button class="btn btn-sm">بازنویسی وضعیت ردیف‌های باز</button>
        <span class="hint">اگر مشتری ستون وضعیت را دستی تغییر داده، این دکمه وضعیت درست را دوباره می‌نویسد.</span>
      </form>
    </div>

    <div class="card" style="margin-top:18px">
      <h2>آخرین ردیف‌ها</h2>
      <div class="table-wrap"><table>
        <tr><th>شناسه ردیف مشتری</th><th>گیرنده</th><th>خط</th><th>وضعیت</th><th>شناسه ELLSMS</th><th>آخرین نوشتن</th><th>برداشت</th></tr>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="ltr"><?= e((string)$r['remote_row_id']) ?></td>
            <td class="ltr"><?= e((string)$r['destination']) ?></td>
            <td class="ltr"><?= e((string)$r['originator']) ?></td>
            <td><?= e($targetLabel($r)) ?><?= $r['error_code'] ? ' — <span class="muted">' . e(remote_db_error_text((string)$r['error_code'])) . '</span>' : '' ?></td>
            <td class="ltr"><?= $r['bulk_item_id'] ? (int)$r['bulk_item_id'] : '—' ?></td>
            <td class="num"><?= $r['written_at'] ? jdate((string)$r['written_at']) : '—' ?></td>
            <td class="num"><?= jdate((string)$r['claimed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="7" class="empty">هنوز ردیفی برداشته نشده است.</td></tr><?php endif; ?>
      </table></div>
    </div>

    <div class="card" style="margin-top:18px">
      <h2>رویدادها</h2>
      <div class="table-wrap"><table>
        <tr><th>زمان</th><th>سطح</th><th>رویداد</th><th>جزئیات</th></tr>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td class="num"><?= jdate((string)$ev['created_at']) ?></td>
            <td><span class="badge <?= $ev['level'] === 'error' ? 'badge-failed' : ($ev['level'] === 'warning' ? 'badge-pending' : 'badge-active') ?>"><?= e((string)$ev['level']) ?></span></td>
            <td class="ltr"><?= e((string)$ev['event']) ?></td>
            <td><?= e((string)$ev['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="4" class="empty">رویدادی ثبت نشده است.</td></tr><?php endif; ?>
      </table></div>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('اتصال حذف شود؟ فقط اتصالی که هنوز پیامی نفرستاده حذف می‌شود.')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="do" value="delete">
        <button class="btn btn-sm btn-danger">حذف اتصال</button>
      </form>
    </div>
<?php
else: /* form */
    $existing = $editId > 0 ? remote_db_connection_load($editId) : null;
    // Values to show: what was just posted (with errors), else the saved row, else defaults.
    if ($formData !== null) {
        $v = $formData;
        $v['user'] = (string)($_POST['user'] ?? '');
    } elseif ($existing) {
        $v = $existing;
        $v['user'] = (string)(backend_usernames_by_ids([(int)$existing['user_id']])[(int)$existing['user_id']] ?? $existing['user_id']);
    } else {
        $v = ['name' => '', 'user' => (string)($_GET['user'] ?? ''), 'driver' => 'mysql', 'host' => '', 'port' => 3306, 'database_name' => '', 'username' => '',
              'tls_mode' => 'prefer', 'connect_timeout_s' => 10, 'query_timeout_s' => 60, 'batch_size' => 200, 'poll_interval_s' => 10,
              'status_sync_interval_s' => 15, 'delivery_wait_hours' => 72, 'retry_hours' => 24, 'send_enabled' => 1, 'status_writeback' => 1,
              'inbound_enabled' => 0, 'enabled' => 0, 'default_originator' => '', 'message_class' => MESSAGE_CLASS_BULK_CAMPAIGN,
              'outbound' => remote_db_default_outbound_mapping(), 'status_values' => remote_db_default_status_values(), 'inbound' => remote_db_default_inbound_mapping()];
    }
    $v['inbound'] = ($v['inbound'] ?? []) ?: remote_db_default_inbound_mapping();
    $err = static fn(string $k): string => isset($formErrors[$k]) ? '<div class="error hint">' . e($formErrors[$k]) . '</div>' : '';
    $users = backend_panel_access_users();
    $ddl = remote_db_ddl((string)$v['driver'], (array)$v['outbound'], (array)$v['inbound']);
    ?>
    <form method="post" class="card" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$editId ?>">
      <div class="toolbar" style="justify-content:space-between">
        <h2 style="margin:0"><?= $existing ? 'ویرایش اتصال' : 'اتصال جدید' ?></h2>
        <a class="btn" href="<?= $existing ? '/remote-db.php?id=' . (int)$editId : '/remote-db.php' ?>">انصراف</a>
      </div>
      <?php if ($formErrors): ?><div class="flash flash-error">فرم خطا دارد؛ موارد قرمز را اصلاح کنید.</div><?php endif; ?>
      <?php if ($testResult !== null): ?>
        <div class="flash <?= $testResult['ok'] ? 'flash-success' : 'flash-error' ?>"><?= e($testResult['message']) ?></div>
        <div class="table-wrap"><table>
          <?php foreach ($testResult['checks'] as [$label, $ok, $detail]): ?>
            <tr><td><?= e($label) ?></td><td><?= $ok ? '✅' : '❌' ?></td><td class="ltr"><?= e($detail) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>

      <div class="subsection">
        <div class="subsection-title">مشتری</div>
        <div class="form-row">
          <label>نام اتصال <input type="text" name="name" value="<?= e((string)$v['name']) ?>" maxlength="120" required><?= $err('name') ?></label>
          <label>کاربر (نام کاربری یا شناسه)
            <input type="text" name="user" list="remote-db-users" value="<?= e((string)$v['user']) ?>" required class="ltr">
            <datalist id="remote-db-users"><?php foreach ($users as $u): ?><option value="<?= e($u['username']) ?>"><?php endforeach; ?></datalist>
            <?= $err('user') ?>
          </label>
          <label>خط پیش‌فرض فرستنده
            <input type="text" name="default_originator" value="<?= e((string)$v['default_originator']) ?>" class="ltr" inputmode="numeric">
            <span class="hint">وقتی ردیف خط فرستنده ندارد. باید از خطوط مجاز همین کاربر باشد.</span><?= $err('default_originator') ?>
          </label>
          <label>کلاس پیام
            <select name="message_class">
              <option value="<?= MESSAGE_CLASS_BULK_CAMPAIGN ?>" <?= $v['message_class'] !== MESSAGE_CLASS_ADVERTISING ? 'selected' : '' ?>>اطلاع‌رسانی / عمومی</option>
              <option value="<?= MESSAGE_CLASS_ADVERTISING ?>" <?= $v['message_class'] === MESSAGE_CLASS_ADVERTISING ? 'selected' : '' ?>>تبلیغاتی</option>
            </select>
          </label>
        </div>
        <div class="toolbar">
          <label><input type="checkbox" name="enabled" value="1" <?= (int)$v['enabled'] ? 'checked' : '' ?>> اتصال فعال باشد</label>
          <label><input type="checkbox" name="send_enabled" value="1" <?= (int)$v['send_enabled'] ? 'checked' : '' ?>> برداشتن و ارسال پیام‌ها</label>
          <label><input type="checkbox" name="status_writeback" value="1" <?= (int)$v['status_writeback'] ? 'checked' : '' ?>> نوشتن وضعیت ارسال/تحویل</label>
          <label><input type="checkbox" name="inbound_enabled" value="1" <?= (int)$v['inbound_enabled'] ? 'checked' : '' ?>> نوشتن پیام‌های دریافتی</label>
        </div>
      </div>

      <div class="subsection">
        <div class="subsection-title">اتصال به دیتابیس</div>
        <div class="form-row">
          <label>نوع دیتابیس
            <select name="driver" id="rdb-driver">
              <?php foreach (REMOTE_DB_DRIVERS as $key => $meta): ?>
                <option value="<?= e($key) ?>" data-port="<?= (int)$meta['port'] ?>" <?= $v['driver'] === $key ? 'selected' : '' ?>><?= e($meta['label']) ?><?= remote_db_driver_available($key) ? '' : ' (درایور نصب نیست)' ?></option>
              <?php endforeach; ?>
            </select><?= $err('driver') ?>
          </label>
          <label>آدرس سرور (host / IP) <input type="text" name="host" value="<?= e((string)$v['host']) ?>" class="ltr" required><?= $err('host') ?></label>
          <label>پورت <input type="number" name="port" id="rdb-port" value="<?= (int)$v['port'] ?>" min="1" max="65535" class="ltr" required><?= $err('port') ?></label>
          <label>نام دیتابیس <input type="text" name="database_name" value="<?= e((string)$v['database_name']) ?>" class="ltr" required><?= $err('database_name') ?></label>
          <label>نام کاربری <input type="text" name="username" value="<?= e((string)$v['username']) ?>" class="ltr" required autocomplete="off"><?= $err('username') ?></label>
          <label>رمز عبور <input type="password" name="password" value="" class="ltr" autocomplete="new-password" <?= $existing ? 'placeholder="بدون تغییر"' : 'required' ?>>
            <span class="hint">رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.</span><?= $err('password') ?></label>
          <label>رمزنگاری اتصال (TLS)
            <select name="tls_mode">
              <?php foreach (['disable' => 'خاموش', 'prefer' => 'در صورت امکان (پیش‌فرض)', 'require' => 'الزامی', 'verify' => 'الزامی + بررسی گواهی'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $v['tls_mode'] === $k ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>مهلت اتصال (ثانیه) <input type="number" name="connect_timeout_s" value="<?= (int)$v['connect_timeout_s'] ?>" min="1" max="120" class="ltr"></label>
          <label>مهلت هر کوئری (ثانیه) <input type="number" name="query_timeout_s" value="<?= (int)$v['query_timeout_s'] ?>" min="5" max="600" class="ltr"></label>
        </div>
      </div>

      <div class="subsection">
        <div class="subsection-title">زمان‌بندی</div>
        <div class="form-row">
          <label>تعداد ردیف در هر برداشت <input type="number" name="batch_size" value="<?= (int)$v['batch_size'] ?>" min="1" max="1000" class="ltr"></label>
          <label>فاصله‌ی بررسی ردیف جدید (ثانیه) <input type="number" name="poll_interval_s" value="<?= (int)$v['poll_interval_s'] ?>" min="2" max="3600" class="ltr"></label>
          <label>فاصله‌ی نوشتن وضعیت (ثانیه) <input type="number" name="status_sync_interval_s" value="<?= (int)$v['status_sync_interval_s'] ?>" min="5" max="3600" class="ltr"></label>
          <label>پیگیری تحویل تا (ساعت) <input type="number" name="delivery_wait_hours" value="<?= (int)$v['delivery_wait_hours'] ?>" min="1" max="720" class="ltr"></label>
          <label>مهلت تلاش برای صف‌کردن (ساعت)
            <input type="number" name="retry_hours" value="<?= (int)$v['retry_hours'] ?>" min="1" max="168" class="ltr">
            <span class="hint">مثلاً وقتی اعتبار کافی نیست؛ بعد از این مهلت ردیف «ارسال نشد» می‌شود.</span></label>
        </div>
      </div>

      <div class="subsection">
        <div class="subsection-title">جدول پیام‌های خروجی</div>
        <p class="hint">نام جدول و ستون‌ها فقط حروف لاتین، عدد و زیرخط (جدول می‌تواند پیشوند schema داشته باشد، مثل <span class="ltr">dbo.SMS_OUT</span>). ستون‌های اختیاری را خالی بگذارید تا استفاده نشوند.
          ردیف‌ها به ترتیب اولویت (کمتر = زودتر) و بعد شناسه برداشته می‌شوند. اگر ستون زمان ارسال پر باشد، ردیف تا آن زمان صبر می‌کند (به وقت تهران).</p>
        <div class="form-row">
          <?php foreach (REMOTE_DB_OUTBOUND_FIELDS as $field => [$label, $required]): ?>
            <label><?= e($label) ?><?= $required ? ' *' : '' ?>
              <input type="text" name="out[<?= $field ?>]" value="<?= e((string)($v['outbound'][$field] ?? '')) ?>" class="ltr" <?= $required ? 'required' : '' ?>><?= $err('outbound.' . $field) ?>
            </label>
          <?php endforeach; ?>
          <label>شرط «در انتظار ارسال»
            <select name="out[pending_mode]">
              <?php foreach (REMOTE_DB_PENDING_MODES as $k => $l): ?><option value="<?= $k ?>" <?= ($v['outbound']['pending_mode'] ?? 'null') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>مقدار «در انتظار» <input type="text" name="out[pending_value]" value="<?= e((string)($v['outbound']['pending_value'] ?? '')) ?>" class="ltr"><?= $err('outbound.pending_value') ?></label>
        </div>
        <?= $err('outbound.duplicate') ?>
      </div>

      <div class="subsection">
        <div class="subsection-title">مقادیری که در ستون وضعیت نوشته می‌شود</div>
        <p class="hint">می‌تواند متن یا عدد باشد (مثلاً DELIVERED یا 2) — هر چه سیستم مشتری انتظار دارد.</p>
        <div class="form-row">
          <?php foreach (REMOTE_DB_STATUS_KEYS as $key => $label): ?>
            <label><?= e($label) ?> <input type="text" name="st[<?= $key ?>]" value="<?= e((string)($v['status_values'][$key] ?? '')) ?>" class="ltr" required><?= $err('status.' . $key) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="subsection">
        <div class="subsection-title">جدول پیام‌های دریافتی (وقتی «نوشتن پیام‌های دریافتی» روشن است)</div>
        <p class="hint">پیام‌هایی که روی خطوط خود این کاربر دریافت می‌شود (همان‌هایی که در صندوق دریافت او هست)، از لحظه‌ی روشن کردن این گزینه به بعد درج می‌شوند.</p>
        <div class="form-row">
          <?php foreach (REMOTE_DB_INBOUND_FIELDS as $field => [$label, $required]): ?>
            <label><?= e($label) ?><?= $required ? ' *' : '' ?>
              <input type="text" name="in[<?= $field ?>]" value="<?= e((string)($v['inbound'][$field] ?? '')) ?>" class="ltr"><?= $err('inbound.' . $field) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="toolbar">
        <button class="btn btn-primary" name="do" value="save">ذخیره</button>
        <button class="btn" name="do" value="test_form" formnovalidate>تست اتصال با همین مقادیر (بدون ذخیره)</button>
      </div>

      <details style="margin-top:16px">
        <summary>اسکریپت ساخت جدول‌ها برای مشتری (<?= e(REMOTE_DB_DRIVERS[$v['driver']]['label'] ?? '') ?>، بر اساس نگاشت فعلی)</summary>
        <p class="hint">اگر مشتری هنوز جدولی ندارد، این اسکریپت را به او بدهید. بعد از تغییر نوع دیتابیس یا نگاشت، ذخیره کنید تا اسکریپت به‌روز شود.</p>
        <pre class="ltr mono" style="white-space:pre-wrap"><?= e($ddl['outbound']) ?></pre>
        <pre class="ltr mono" style="white-space:pre-wrap"><?= e($ddl['inbound']) ?></pre>
        <p class="hint">برای ثبت پیام، مشتری فقط ستون‌های گیرنده و متن (و در صورت نیاز خط فرستنده و زمان ارسال) را پر می‌کند:</p>
        <pre class="ltr mono" style="white-space:pre-wrap">INSERT INTO <?= e(remote_db_quote_identifier((string)$v['driver'], (string)($v['outbound']['table'] ?? 'sms_outbound'))) ?> (<?= e(implode(', ', array_map(static fn(string $c): string => remote_db_quote_identifier((string)$v['driver'], $c), array_values(array_filter([$v['outbound']['destination'] ?? 'destination', $v['outbound']['content'] ?? 'message']))))) ?>) VALUES ('09120000000', 'متن پیام');</pre>
      </details>
    </form>
    <script>
      (function () {
        var d = document.getElementById('rdb-driver'), p = document.getElementById('rdb-port');
        var defaults = Array.prototype.map.call(d.options, function (o) { return o.getAttribute('data-port'); });
        d.addEventListener('change', function () {
          if (defaults.indexOf(p.value) !== -1) p.value = d.options[d.selectedIndex].getAttribute('data-port');
        });
      })();
    </script>
<?php
endif;
require __DIR__ . '/../app/views/footer.php';
