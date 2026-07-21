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
