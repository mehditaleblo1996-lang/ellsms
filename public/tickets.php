<?php
require_once __DIR__ . '/../app/tickets.php';
$me = require_login();
$pageTitle = 'پشتیبانی';
$active = 'tickets';

$ticketId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        if ($subject === '' || $body === '') {
            flash('error', 'موضوع و متن پیام را کامل وارد کنید.');
            redirect('/tickets.php');
        }
        $newId = ticket_create((int)$me['id'], $me['username'], $subject, $body);
        audit((int)$me['id'], 'ticket.create', "#{$newId} {$subject}");
        flash('success', 'تیکت شما ثبت شد.');
        redirect('/tickets.php?id=' . $newId);
    }

    if ($do === 'reply' && $ticketId) {
        $ticket = ticket_find($ticketId);
        if (!$ticket || (!is_admin() && (int)$ticket['user_id'] !== (int)$me['id'])) {
            http_response_code(403);
            exit('اجازه‌ی دسترسی به این تیکت را ندارید.');
        }
        $body = trim($_POST['body'] ?? '');
        if ($body === '') {
            flash('error', 'متن پاسخ خالی است.');
            redirect('/tickets.php?id=' . $ticketId);
        }
        ticket_add_reply($ticketId, (int)$me['id'], $me['username'], $body, is_admin());
        audit((int)$me['id'], 'ticket.reply', "#{$ticketId}");
        flash('success', 'پاسخ شما ثبت شد.');
        redirect('/tickets.php?id=' . $ticketId);
    }

    if ($do === 'status' && $ticketId) {
        if (!is_admin()) {
            http_response_code(403);
            exit('این عملیات فقط برای مدیران مجاز است.');
        }
        ticket_set_status($ticketId, $_POST['status'] ?? '');
        audit((int)$me['id'], 'ticket.status', "#{$ticketId} -> " . ($_POST['status'] ?? ''));
        flash('success', 'وضعیت تیکت به‌روزرسانی شد.');
        redirect('/tickets.php?id=' . $ticketId);
    }
}

$statusFa = ['open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'];

if ($ticketId) {
    $ticket = ticket_find($ticketId);
    if (!$ticket || (!is_admin() && (int)$ticket['user_id'] !== (int)$me['id'])) {
        http_response_code(403);
        exit('اجازه‌ی دسترسی به این تیکت را ندارید.');
    }
    $replies = ticket_replies($ticketId);
    require __DIR__ . '/../app/views/header.php';
    ?>
    <div class="card">
      <div class="toolbar" style="justify-content:space-between">
        <div>
          <h2 style="margin:0 0 6px"><?= e($ticket['subject']) ?></h2>
          <span class="badge badge-<?= e($ticket['status']) ?>"><?= e($statusFa[$ticket['status']] ?? $ticket['status']) ?></span>
          <?php if (is_admin()): ?><span class="hint">— <?= e($ticket['username']) ?></span><?php endif; ?>
        </div>
        <a class="btn btn-sm" href="/tickets.php">← بازگشت به فهرست</a>
      </div>

      <div class="ticket-thread">
        <?php foreach ($replies as $r): ?>
          <div class="ticket-msg<?= $r['is_admin_reply'] ? ' is-admin' : '' ?>">
            <div class="ticket-msg-meta">
              <?= e($r['username']) ?><?= $r['is_admin_reply'] ? ' (مدیر)' : '' ?> — <?= jdate($r['created_at']) ?>
            </div>
            <div><?= nl2br(e($r['body'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="reply">
        <label>پاسخ شما
          <textarea name="body" required></textarea>
        </label>
        <button class="btn btn-primary">ثبت پاسخ</button>
      </form>

      <?php if (is_admin()): ?>
      <form method="post" class="toolbar" style="margin-top:16px">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="status">
        <label>وضعیت تیکت
          <select name="status">
            <?php foreach ($statusFa as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $ticket['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn">به‌روزرسانی وضعیت</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
} else {
    $statusFilter = $_GET['status'] ?? '';
    $per  = 50;
    $page = max(1, (int)($_GET['page'] ?? 1));
    [$tickets, $total] = ticket_list(is_admin() ? 0 : (int)$me['id'], $statusFilter, $page, $per);
    $pages = max(1, (int)ceil($total / $per));
    $qs = fn(array $extra = []) => http_build_query(array_merge($_GET, $extra));

    require __DIR__ . '/../app/views/header.php';
    ?>
    <div class="card">
      <h2>ثبت تیکت جدید</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="create">
        <label>موضوع
          <input type="text" name="subject" required>
        </label>
        <label>متن پیام
          <textarea name="body" required></textarea>
        </label>
        <button class="btn btn-primary">ثبت تیکت</button>
      </form>
    </div>

    <div class="card" style="margin-top:22px">
      <form method="get" class="toolbar">
        <label>وضعیت
          <select name="status">
            <option value="">همه</option>
            <?php foreach ($statusFa as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary">اعمال فیلتر</button>
      </form>

      <div class="table-wrap">
      <table>
        <tr>
          <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
          <th>موضوع</th><th>وضعیت</th><th>آخرین به‌روزرسانی</th>
        </tr>
        <?php foreach ($tickets as $t): ?>
          <tr>
            <td class="num"><?= to_persian_digits((string)$t['id']) ?></td>
            <?php if (is_admin()): ?><td><?= e($t['username']) ?></td><?php endif; ?>
            <td><a href="/tickets.php?id=<?= $t['id'] ?>"><?= e($t['subject']) ?></a></td>
            <td><span class="badge badge-<?= e($t['status']) ?>"><?= e($statusFa[$t['status']] ?? $t['status']) ?></span></td>
            <td class="num"><?= jdate($t['updated_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tickets): ?><tr><td colspan="<?= is_admin() ? 5 : 4 ?>" class="empty">هیچ تیکتی یافت نشد.</td></tr><?php endif; ?>
      </table>
      </div>

      <?php if ($pages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page - 1])) ?>">→ قبلی</a><?php endif; ?>
        <span class="btn btn-sm btn-ghost">صفحه <?= to_persian_digits((string)$page) ?> از <?= to_persian_digits((string)$pages) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page + 1])) ?>">بعدی ←</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
}
require __DIR__ . '/../app/views/footer.php';
