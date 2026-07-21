# ELLSMS Ticketing System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an in-panel, threaded support-ticket system — logged-in users create/reply to their own tickets, admins see and reply to all tickets and control status, Telegram notifies on ticket creation and user replies.

**Architecture:** Three layers, matching this codebase's existing split: a schema migration (two new `ellsms_*` tables), a business-logic file (`app/tickets.php`, mirroring how `app/backend.php` holds SMS logic separately from page rendering), and one role-adaptive page (`public/tickets.php`, following the exact pattern `public/reports.php` already uses for "admin sees all / user sees own").

**Tech Stack:** PHP 8.2, PDO/MySQL, existing `app/telegram.php` relay (no new external dependencies).

## Global Constraints

- No Composer, no `vendor/`, no new runtime dependencies — plain PHP only, matching every other file in this project.
- No automated test framework exists in this repo (intentional) — verification is `php -l` for syntax, plus manual commands against a real `docker compose` deployment (with a working `.env` and the backend platform's stack running) for anything needing MySQL or the Telegram API. This mirrors how the rest of this codebase is verified.
- This is a **separate, new system** from the existing public `public/contact.php` / `app/telegram.php` contact-form flow — do not modify `public/contact.php`. The two share only the transport function `telegram_send_message()` in `app/telegram.php`, which must not change its existing signature (`telegram_send_message(string $text): array` returning `[ok, message]`) since `contact.php` already depends on it.
- Ticket statuses are exactly `'open'`, `'answered'`, `'closed'` (ENUM in the DB) — no other values.
- Status transitions are automatic except closing: new ticket → `open`; a user reply → `open` (including reopening a `closed` ticket); an admin reply → `answered`; `closed` is set **only** by an explicit admin action, never as a side effect of a reply.
- Telegram notifies on ticket creation and on user replies; admin replies do **not** trigger a Telegram notification.
- Telegram delivery failure must never block a ticket/reply from being saved — the DB write is authoritative, the notification is best-effort (log via `error_log()` on failure, don't surface an error to the submitter).
- Any panel-access user may create tickets and view/reply to their own; only admins (`is_admin()`) may view/reply to other users' tickets or change status.
- CSRF protection (`csrf_check()`/`csrf_field()`) on every form, matching every other form in this codebase.
- All user-facing strings are Persian/Farsi, matching the existing tone (short, plain, RTL).
- Out of scope: file attachments, read/unread state, categories/priority, admin assignment — do not add any of these.

---

## File Structure

| File | Change |
|---|---|
| `db/ellsms_extra.sql` | New `ellsms_tickets` and `ellsms_ticket_replies` tables |
| `app/tickets.php` | New — business logic: create/reply/status-change/list/find, Telegram notify wiring |
| `public/tickets.php` | New — the page: list + create form (no `?id`), thread + reply + admin status control (`?id=N`) |
| `app/views/header.php` | Add `tickets` entry to the base `$nav` array |
| `public/assets/css/style.css` | Add ticket-status badge colors and thread-message styling |

---

## Task 1: Database schema

**Files:**
- Modify: `db/ellsms_extra.sql`

**Interfaces:**
- Produces: tables `ellsms_tickets` (`id`, `user_id`, `subject`, `status` ENUM, `created_at`, `updated_at`) and `ellsms_ticket_replies` (`id`, `ticket_id`, `user_id`, `is_admin_reply`, `body`, `created_at`). Task 2's queries depend on these exact column names and types.

- [ ] **Step 1: Add the two tables to `db/ellsms_extra.sql`**

Insert this block right after the existing `ellsms_guide_articles` table
definition and before the final `-- Seed default settings` comment /
`INSERT INTO ellsms_settings` block at the end of the file:

```sql
-- In-panel support tickets. Separate from the public "تماس با ما" contact
-- form (public/contact.php / app/telegram.php), which stays a stateless
-- Telegram relay with no persistence — this is a real, authenticated,
-- threaded ticket system. A ticket's opening message is not a column on
-- this table; it's simply the first row in ellsms_ticket_replies, so
-- rendering a thread never needs to special-case the first message.
CREATE TABLE IF NOT EXISTS ellsms_tickets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,               -- ticket owner (= user_.id)
  subject    VARCHAR(160) NOT NULL,
  status     ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (user_id), KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every message in a ticket's thread, oldest first. is_admin_reply is a
-- snapshot of the author's role at post time (not a live join against
-- ellsms_meta.is_admin), so a later role change never rewrites history.
CREATE TABLE IF NOT EXISTS ellsms_ticket_replies (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id      INT UNSIGNED NOT NULL,
  user_id        BIGINT NOT NULL,            -- author (owner or admin — both are user_ rows)
  is_admin_reply TINYINT(1) NOT NULL DEFAULT 0,
  body           TEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Verify against a real deployment**

Apply the migration (requires the backend platform's stack already
running, per this project's own Quick Start):

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Confirm both tables exist with the right shape:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" -e "DESCRIBE ellsms_tickets; DESCRIBE ellsms_ticket_replies;"
```

Expected: `ellsms_tickets` shows `id, user_id, subject, status, created_at,
updated_at`; `ellsms_ticket_replies` shows `id, ticket_id, user_id,
is_admin_reply, body, created_at`.

Re-run the migration a second time to confirm it's a safe no-op:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Expected: no errors (both `CREATE TABLE IF NOT EXISTS` statements are
idempotent by construction).

If Docker/MySQL are not available in your environment, note this clearly
as a concern and don't fake the verification — a human must run these
commands against a real deployment before this is trusted.

- [ ] **Step 3: Commit**

```bash
git add db/ellsms_extra.sql
git commit -m "feat: add ellsms_tickets/ellsms_ticket_replies schema"
```

---

## Task 2: Ticket business logic

**Files:**
- Create: `app/tickets.php`

**Interfaces:**
- Consumes: `db()`, `error_log()` (built-in), and `telegram_send_message(string $text): array` from `app/telegram.php` (existing, unchanged signature).
- Produces (all used by Task 3's `public/tickets.php`):
  - `ticket_create(int $userId, string $username, string $subject, string $body): int` — inserts the ticket + its opening reply in one transaction, fires the "created" Telegram notification, returns the new ticket id.
  - `ticket_add_reply(int $ticketId, int $userId, string $username, string $body, bool $isAdmin): void` — inserts a reply, updates the ticket's `status` per the lifecycle rules, fires the "user reply" Telegram notification only when `$isAdmin` is `false`.
  - `ticket_set_status(int $ticketId, string $status): void` — sets status directly (admin-only enforcement happens in the caller, `public/tickets.php`, not here); silently no-ops if `$status` isn't one of the three valid values.
  - `ticket_find(int $ticketId): ?array` — one ticket row joined with the owner's `username`, or `null` if it doesn't exist.
  - `ticket_replies(int $ticketId): array` — every reply for a ticket, oldest first, each joined with the author's `username`.
  - `ticket_list(int $ownerUserId, string $statusFilter, int $page, int $per): array` — returns `[rows, totalCount]`. `$ownerUserId = 0` means "all users" (the caller must only pass `0` when the viewer is an admin); `$statusFilter` is `''` for "all statuses" or one of `open`/`answered`/`closed`.

- [ ] **Step 1: Create `app/tickets.php`**

```php
<?php
/**
 * ELLSMS — in-panel support tickets.
 *
 * Separate from the public "تماس با ما" contact form (public/contact.php),
 * which stays a stateless Telegram relay with no persistence. This is a
 * real, authenticated, threaded ticket system: users create/reply to
 * their own tickets, admins see and reply to all of them and control
 * status. A ticket's opening message is just the first row in
 * ellsms_ticket_replies — there is no separate body column on
 * ellsms_tickets, so rendering a thread never special-cases the first
 * message.
 *
 * Access control (who may call which function for which ticket) is the
 * caller's job — public/tickets.php enforces it before calling in, the
 * same way app/backend.php's bulk_send_batch() trusts its caller rather
 * than re-checking permissions internally.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/telegram.php';

/** Best-effort Telegram notify — logs and swallows failure, never blocks the caller. */
function ticket_notify_telegram(string $text): void {
    [$ok, $info] = telegram_send_message($text);
    if (!$ok) {
        error_log('[ellsms tickets] telegram notify failed: ' . $info);
    }
}

/**
 * Create a ticket and its opening message in one transaction, then fire
 * the "ticket created" Telegram notification (best-effort, after the
 * transaction commits — a notify failure must never roll back a saved
 * ticket). Returns the new ticket id.
 */
function ticket_create(int $userId, string $username, string $subject, string $body): int {
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO ellsms_tickets (user_id, subject, status) VALUES (?, ?, ?)')
           ->execute([$userId, $subject, 'open']);
        $ticketId = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO ellsms_ticket_replies (ticket_id, user_id, is_admin_reply, body) VALUES (?, ?, 0, ?)')
           ->execute([$ticketId, $userId, $body]);

        $db->commit();
    } catch (Throwable $t) {
        $db->rollBack();
        throw $t;
    }

    ticket_notify_telegram(
        "🎫 تیکت جدید #{$ticketId} از {$username}\nموضوع: {$subject}\n" . mb_strimwidth($body, 0, 500, '…')
    );

    return $ticketId;
}

/**
 * Add a reply to an existing ticket and update its status per the
 * lifecycle rules: a user reply always moves the ticket to 'open'
 * (including reopening a 'closed' ticket); an admin reply moves it to
 * 'answered'. Only a user reply (not an admin one) fires a Telegram
 * notification — admins are already reading the panel when they reply.
 */
function ticket_add_reply(int $ticketId, int $userId, string $username, string $body, bool $isAdmin): void {
    $db = db();
    $db->prepare('INSERT INTO ellsms_ticket_replies (ticket_id, user_id, is_admin_reply, body) VALUES (?, ?, ?, ?)')
       ->execute([$ticketId, $userId, $isAdmin ? 1 : 0, $body]);

    $newStatus = $isAdmin ? 'answered' : 'open';
    $db->prepare('UPDATE ellsms_tickets SET status = ? WHERE id = ?')->execute([$newStatus, $ticketId]);

    if (!$isAdmin) {
        ticket_notify_telegram(
            "💬 پاسخ جدید روی تیکت #{$ticketId} از {$username}:\n" . mb_strimwidth($body, 0, 500, '…')
        );
    }
}

/** Set a ticket's status directly. No-ops silently on an invalid value. Caller enforces admin-only. */
function ticket_set_status(int $ticketId, string $status): void {
    if (!in_array($status, ['open', 'answered', 'closed'], true)) {
        return;
    }
    db()->prepare('UPDATE ellsms_tickets SET status = ? WHERE id = ?')->execute([$status, $ticketId]);
}

/** One ticket, joined with the owner's username. Null if it doesn't exist. */
function ticket_find(int $ticketId): ?array {
    $st = db()->prepare(
        'SELECT t.*, u.username FROM ellsms_tickets t JOIN user_ u ON u.id = t.user_id WHERE t.id = ?'
    );
    $st->execute([$ticketId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Every reply for a ticket, oldest first, joined with each author's username. */
function ticket_replies(int $ticketId): array {
    $st = db()->prepare(
        'SELECT r.*, u.username FROM ellsms_ticket_replies r JOIN user_ u ON u.id = r.user_id
         WHERE r.ticket_id = ? ORDER BY r.created_at ASC, r.id ASC'
    );
    $st->execute([$ticketId]);
    return $st->fetchAll();
}

/**
 * Paged ticket list, newest-activity-first. Returns [rows, totalCount].
 * $ownerUserId = 0 means "every user's tickets" — the caller (page-level
 * code) must only pass 0 when the viewer is an admin. $statusFilter is
 * '' for "all statuses" or one of open/answered/closed.
 */
function ticket_list(int $ownerUserId, string $statusFilter, int $page, int $per): array {
    $where  = [];
    $params = [];
    if ($ownerUserId > 0) {
        $where[] = 't.user_id = ?';
        $params[] = $ownerUserId;
    }
    if (in_array($statusFilter, ['open', 'answered', 'closed'], true)) {
        $where[] = 't.status = ?';
        $params[] = $statusFilter;
    }
    $W = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $db = db();
    $c = $db->prepare("SELECT COUNT(*) c FROM ellsms_tickets t {$W}");
    $c->execute($params);
    $total = (int)$c->fetch()['c'];

    $per = max(1, $per);
    $off = max(0, ($page - 1) * $per);
    $st = $db->prepare(
        "SELECT t.*, u.username FROM ellsms_tickets t JOIN user_ u ON u.id = t.user_id
         {$W} ORDER BY t.updated_at DESC LIMIT {$per} OFFSET {$off}"
    );
    $st->execute($params);

    return [$st->fetchAll(), $total];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l app/tickets.php`
Expected: `No syntax errors detected in app/tickets.php`

- [ ] **Step 3: Verify against a real deployment**

Requires a working DB connection and (optionally, for the notify checks)
a configured Telegram bot — verify against your dev deployment
(`docker compose up -d --build`, `.env` filled in, `Settings → تماس با ما`
has a bot token/chat id saved, matching how `contact.php` is already
tested):

```bash
docker compose exec app php -r "
require '/var/www/html/app/tickets.php';
\$id = ticket_create(1, 'testuser', 'تست موضوع', 'این یک پیام آزمایشی است.');
echo \"created ticket #{\$id}\n\";
\$t = ticket_find(\$id);
echo 'status: ' . \$t['status'] . \" (expected open)\n\";
ticket_add_reply(\$id, 1, 'testuser', 'پاسخ کاربر', false);
\$t = ticket_find(\$id);
echo 'status after user reply: ' . \$t['status'] . \" (expected open)\n\";
ticket_add_reply(\$id, 1, 'adminuser', 'پاسخ مدیر', true);
\$t = ticket_find(\$id);
echo 'status after admin reply: ' . \$t['status'] . \" (expected answered)\n\";
ticket_set_status(\$id, 'closed');
\$t = ticket_find(\$id);
echo 'status after close: ' . \$t['status'] . \" (expected closed)\n\";
ticket_add_reply(\$id, 1, 'testuser', 'دوباره پاسخ کاربر', false);
\$t = ticket_find(\$id);
echo 'status after reopening reply: ' . \$t['status'] . \" (expected open)\n\";
echo 'reply count: ' . count(ticket_replies(\$id)) . \" (expected 4)\n\";
"
```

Expected output matches every `(expected ...)` annotation above. Confirm
in your Telegram chat that two notifications arrived (ticket creation,
then the first user reply) and that the admin reply and the reopening
user reply also produced exactly one more notification each for user
replies only (so 3 total: create, first user reply, reopening user
reply — not 4, since the admin reply must NOT notify).

If Docker/MySQL/Telegram are not available in your environment, note
this clearly as a concern — a human must run this before merge.

- [ ] **Step 4: Commit**

```bash
git add app/tickets.php
git commit -m "feat: add ticket business logic (create/reply/status/list)"
```

---

## Task 3: Ticket page (list, create, thread, reply, status)

**Files:**
- Create: `public/tickets.php`
- Modify: `app/views/header.php` (add nav entry)
- Modify: `public/assets/css/style.css` (add status badges + thread message styling)

**Interfaces:**
- Consumes: everything from Task 2 (`ticket_create`, `ticket_add_reply`, `ticket_set_status`, `ticket_find`, `ticket_replies`, `ticket_list`), plus existing helpers `require_login()`, `is_admin()`, `csrf_check()`/`csrf_field()`, `flash()`/`flashes()`, `audit()`, `redirect()`, `e()`, `jdate()`, `to_persian_digits()`.
- Produces: nothing new for later tasks — this is the last task in the plan.

- [ ] **Step 1: Add the nav entry**

In `app/views/header.php`, the base `$nav` array currently ends with:

```php
    'buy_credit' => ['/buy-credit.php',  'خرید اعتبار',      '💳'],
];
```

Add the tickets entry right before that closing `];`:

```php
    'tickets'    => ['/tickets.php',     'پشتیبانی',        '🎫'],
    'buy_credit' => ['/buy-credit.php',  'خرید اعتبار',      '💳'],
];
```

- [ ] **Step 2: Add CSS for ticket status badges and thread messages**

In `public/assets/css/style.css`, right after the existing badge rules
(the block containing `.badge-admin { background: var(--ink); color: #fff; }`),
add:

```css
.badge-open { background: var(--warn-bg); color: var(--warn); }
.badge-answered { background: var(--tint); color: var(--indigo); }
.badge-closed { background: var(--ok-bg); color: var(--ok); }

.ticket-thread { display: flex; flex-direction: column; gap: 14px; margin: 18px 0; }
.ticket-msg { padding: 12px 16px; border-radius: 10px; background: var(--tint); }
.ticket-msg.is-admin { background: var(--ink); color: #fff; }
.ticket-msg-meta { font-size: 12px; opacity: .7; margin-bottom: 6px; }
```

- [ ] **Step 3: Create `public/tickets.php`**

```php
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
```

- [ ] **Step 4: Syntax check**

Run: `php -l public/tickets.php && php -l app/views/header.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Verify against a real deployment**

With a working dev deployment (two accounts handy: one regular user, one
admin):

1. Log in as the regular user, open "پشتیبانی" from the sidebar, submit a
   new ticket. Expected: redirected to the ticket's thread view, status
   badge shows "باز", the opening message appears once in the thread, and
   the configured Telegram chat receives the creation notification.
2. Still as the regular user, go back to `/tickets.php` (no `id`).
   Expected: the ticket appears in the list with status "باز".
3. Log in as a **different** regular user (or confirm via a second
   account) and visit `/tickets.php`. Expected: the first user's ticket
   does **not** appear in this list.
4. Try opening the first user's ticket directly by its `?id=` as this
   second non-admin user. Expected: HTTP 403, "اجازه‌ی دسترسی به این
   تیکت را ندارید."
5. Log in as admin, visit `/tickets.php`. Expected: the ticket appears in
   the admin's list (with a "کاربر" column showing the owner's username).
6. Open the ticket as admin and submit a reply. Expected: status badge
   flips to "پاسخ‌داده‌شده", the reply appears in the thread styled
   distinctly (dark background, "(مدیر)" label), and **no** Telegram
   notification fires for this reply.
7. Log back in as the owning regular user, reply again. Expected: status
   flips back to "باز", and a Telegram notification fires for this reply.
8. As admin, use the status dropdown to set the ticket to "بسته".
   Expected: status badge shows "بسته" and stays that way on reload.
9. As the regular user, reply once more to the now-closed ticket.
   Expected: status automatically flips back to "باز".

If Docker/MySQL/Telegram are not available in your environment, note
this clearly as a concern — a human must run this full checklist against
a real deployment before merge.

- [ ] **Step 6: Commit**

```bash
git add public/tickets.php app/views/header.php public/assets/css/style.css
git commit -m "feat: add ticket list/create/thread/reply/status page"
```
