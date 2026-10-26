<?php
/**
 * ELLSMS — the tickets of a reseller's customers (app/tickets.php, app/ResellerPanel.php): the
 * reseller answers them, closes them, or hands one to platform support.
 */
require_once __DIR__ . '/../app/tickets.php';
[$me, $ctx] = require_reseller('tickets.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'تیکت‌های مشتریان';
$active = 'reseller_tickets';
$statusFa = ['open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'];

$ticketId = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = '/reseller/tickets' . ($ticketId ? '?id=' . $ticketId : '');
    reseller_post_guard($ctx, 'tickets.manage', $back);
    $ticket = $ticketId ? reseller_ticket_find($resellerId, $ticketId) : null;
    if ($ticket === null) {
        flash('error', 'تیکت پیدا نشد.');
        redirect('/reseller/tickets');
    }
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') {
            flash('error', 'متن پاسخ خالی است.');
            redirect($back);
        }
        ticket_add_reply($ticketId, $actorId, $me['username'], $body, true);
        audit($actorId, 'reseller.ticket_reply', "reseller=#{$resellerId} ticket=#{$ticketId}");
        flash('success', 'پاسخ ثبت شد.');
    } elseif ($do === 'status') {
        ticket_set_status($ticketId, (string)($_POST['status'] ?? ''));
        audit($actorId, 'reseller.ticket_status', "reseller=#{$resellerId} ticket=#{$ticketId} -> " . ($_POST['status'] ?? ''));
        flash('success', 'وضعیت تیکت به‌روز شد.');
    } elseif ($do === 'escalate') {
        flash(reseller_ticket_escalate($resellerId, $ticketId, $actorId) ? 'info' : 'error', 'تیکت به پشتیبانی سامانه ارجاع شد.');
        redirect('/reseller/tickets');
    }
    redirect($back);
}

if ($ticketId) {
    $ticket = reseller_ticket_find($resellerId, $ticketId);
    if ($ticket === null) {
        flash('error', 'تیکت پیدا نشد.');
        redirect('/reseller/tickets');
    }
    $replies = ticket_replies($ticketId);
    $pageTitle = 'تیکت #' . to_persian_digits((string)$ticketId);
    require __DIR__ . '/../app/views/reseller_header.php';
    ?>
    <div class="card">
      <div class="toolbar" style="justify-content:space-between">
        <div>
          <h2 style="margin:0 0 6px"><?= e($ticket['subject']) ?></h2>
          <span class="badge badge-<?= e($ticket['status']) ?>"><?= e($statusFa[$ticket['status']] ?? $ticket['status']) ?></span>
          <span class="hint">— <a class="ltr" href="/reseller/customers/view?id=<?= (int)$ticket['user_id'] ?>"><?= e((string)$ticket['username']) ?></a></span>
        </div>
        <a class="btn btn-sm" href="/reseller/tickets">← بازگشت به فهرست</a>
      </div>
      <div class="ticket-thread">
        <?php foreach ($replies as $r): ?>
          <div class="ticket-msg<?= $r['is_admin_reply'] ? ' is-admin' : '' ?>">
            <div class="ticket-msg-meta"><?= e((string)$r['username']) ?><?= e(ticket_reply_role_label($ticket, $r)) ?> — <?= jdate($r['created_at']) ?></div>
            <div><?= nl2br(e($r['body'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="reply">
        <label>پاسخ شما <textarea name="body" required></textarea></label>
        <button class="btn btn-primary">ثبت پاسخ</button>
      </form>
      <div class="toolbar" style="margin-top:16px">
        <form method="post" class="toolbar">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="status">
          <label>وضعیت
            <select name="status"><?php foreach ($statusFa as $val => $label): ?><option value="<?= e($val) ?>"<?= $ticket['status'] === $val ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          </label>
          <button class="btn">به‌روزرسانی وضعیت</button>
        </form>
        <form method="post" onsubmit="return confirm('این تیکت به پشتیبانی سامانه ارجاع شود؟ پس از آن در فهرست شما نمی‌ماند.')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="escalate">
          <button class="btn btn-ghost">ارجاع به پشتیبانی سامانه</button>
        </form>
      </div>
    </div>
    <?php
} else {
    $statusFilter = (string)($_GET['status'] ?? '');
    $per = 50;
    $page = pager_page();
    [$tickets, $total] = ticket_list_for_assignee($resellerId, $statusFilter, $page, $per);
    $pages = pager_pages($total, $per);
    $settings = reseller_settings($resellerId);
    require __DIR__ . '/../app/views/reseller_header.php';
    ?>
    <div class="card">
      <?php if (!$settings['tickets_to_reseller']): ?>
        <div class="flash flash-info">تیکت‌های جدید مشتریان شما الان مستقیم به پشتیبانی سامانه می‌رود. برای پاسخ‌گویی خودتان، آن را در «برند، دامنه و ثبت‌نام» روشن کنید.</div>
      <?php endif; ?>
      <form method="get" class="toolbar">
        <label>وضعیت
          <select name="status"><option value="">همه</option>
            <?php foreach ($statusFa as $val => $label): ?><option value="<?= e($val) ?>"<?= $statusFilter === $val ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary">اعمال فیلتر</button>
      </form>
      <div class="table-wrap">
        <table>
          <tr><th>#</th><th>مشتری</th><th>موضوع</th><th>وضعیت</th><th>آخرین به‌روزرسانی</th></tr>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td class="num"><?= to_persian_digits((string)$t['id']) ?></td>
              <td class="ltr"><?= e((string)$t['username']) ?></td>
              <td><a href="/reseller/tickets?id=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a></td>
              <td><span class="badge badge-<?= e($t['status']) ?>"><?= e($statusFa[$t['status']] ?? $t['status']) ?></span></td>
              <td class="num"><?= jdate($t['updated_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tickets): ?><tr><td colspan="5" class="empty">تیکتی پیدا نشد.</td></tr><?php endif; ?>
        </table>
      </div>
      <?= pager_links(min($page, $pages), $pages, array_filter($_GET, static fn($v) => is_scalar($v) && $v !== ''), 'page', $total) ?>
    </div>
    <?php
}
require __DIR__ . '/../app/views/footer.php';
