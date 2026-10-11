<?php
/**
 * ELLSMS — admin "delete message": hide a sent message from the customer's panel.
 *
 * A soft delete (db/migrations/2026_10_11_hidden_messages.sql): the send records are left exactly as
 * they are — the message was sent and charged, and outbound_message is backend-owned — and a row in
 * ellsms_hidden_messages tells every customer-facing view to leave it out. Admins keep seeing it,
 * marked as removed, and can restore it.
 *
 * Sources:
 *   outbound  — outbound_message.id         (direct/API sends recorded by the backend)
 *   attempt   — ellsms_message_attempts.id  (direct/API/scheduled sends that went straight to a gateway)
 *   bulk_job  — ellsms_bulk_jobs.id         (a whole bulk / P2P send and every item in it)
 *
 * Until the migration is applied every helper here is a no-op (nothing is hidden, nothing can be),
 * so deploying the code before the schema never breaks a report.
 */

const HIDDEN_MESSAGE_SOURCES = ['outbound', 'attempt', 'bulk_job'];

function hidden_messages_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $st = db()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'ellsms_hidden_messages'"
            );
            $has = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * SQL to append to a WHERE clause that drops hidden rows: " AND NOT EXISTS (...)" or '' when there is
 * nothing to filter (an admin viewer, or the migration not applied yet). $idColumn is a trusted,
 * code-supplied column reference such as 'm.id' — never request input.
 */
function hidden_messages_exclude_sql(string $source, string $idColumn, bool $applies = true): string {
    if (!$applies || !in_array($source, HIDDEN_MESSAGE_SOURCES, true) || !hidden_messages_available()) {
        return '';
    }
    return " AND NOT EXISTS (SELECT 1 FROM ellsms_hidden_messages hm WHERE hm.source = '{$source}' AND hm.message_id = {$idColumn})";
}

/** Whether removed messages are left out for the current viewer — everyone except a platform admin. */
function hidden_messages_apply_to_viewer(): bool {
    return !is_admin();
}

function hidden_messages_is_hidden(string $source, int $id): bool {
    if ($id <= 0 || !in_array($source, HIDDEN_MESSAGE_SOURCES, true) || !hidden_messages_available()) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM ellsms_hidden_messages WHERE source = ? AND message_id = ?');
    $st->execute([$source, $id]);
    return (bool)$st->fetchColumn();
}

/**
 * Which of $ids are hidden, for marking rows on an admin's report.
 * @param list<int> $ids
 * @return array<int, array{hidden_by:int, reason:string, hidden_at:string}>
 */
function hidden_messages_lookup(string $source, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !in_array($source, HIDDEN_MESSAGE_SOURCES, true) || !hidden_messages_available()) {
        return [];
    }
    $st = db()->prepare(
        'SELECT message_id, hidden_by, reason, hidden_at FROM ellsms_hidden_messages
         WHERE source = ? AND message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $st->execute(array_merge([$source], $ids));
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['message_id']] = [
            'hidden_by' => (int)$row['hidden_by'], 'reason' => (string)$row['reason'], 'hidden_at' => (string)$row['hidden_at'],
        ];
    }
    return $out;
}

/** @return array{ok:bool, reason?:string} */
function hidden_messages_hide(string $source, int $id, int $actorUserId, string $reason = ''): array {
    if (!in_array($source, HIDDEN_MESSAGE_SOURCES, true) || $id <= 0) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    if (!hidden_messages_available()) {
        return ['ok' => false, 'reason' => 'not_migrated'];
    }
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($reason)) ?? ''), 0, 190);
    db()->prepare(
        'INSERT INTO ellsms_hidden_messages (source, message_id, hidden_by, reason) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE hidden_by = VALUES(hidden_by), reason = VALUES(reason)'
    )->execute([$source, $id, $actorUserId, $reason]);
    audit($actorUserId, 'message.hide', "{$source}#{$id}");
    Logger::info('message.hidden', ['source' => $source, 'message_id' => $id, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

/** @return array{ok:bool, reason?:string} */
function hidden_messages_restore(string $source, int $id, int $actorUserId): array {
    if (!in_array($source, HIDDEN_MESSAGE_SOURCES, true) || $id <= 0) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    if (!hidden_messages_available()) {
        return ['ok' => false, 'reason' => 'not_migrated'];
    }
    db()->prepare('DELETE FROM ellsms_hidden_messages WHERE source = ? AND message_id = ?')->execute([$source, $id]);
    audit($actorUserId, 'message.restore', "{$source}#{$id}");
    Logger::info('message.restored', ['source' => $source, 'message_id' => $id, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

/**
 * Handles a remove/restore POST from hidden_messages_admin_button() on the page that rendered it,
 * then redirects back to that same page (GET), so a refresh never re-submits. Pages call this before
 * their own POST handling; it does nothing unless the POST is one of these.
 */
function hidden_messages_handle_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['hidden_message_do'] ?? '', ['hide', 'restore'], true)) {
        return;
    }
    csrf_check();
    $me = require_admin();
    $source = (string)($_POST['source'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $result = $_POST['hidden_message_do'] === 'hide'
        ? hidden_messages_hide($source, $id, (int)$me['id'], (string)($_POST['reason'] ?? ''))
        : hidden_messages_restore($source, $id, (int)$me['id']);
    if ($result['ok']) {
        flash('success', $_POST['hidden_message_do'] === 'hide'
            ? 'پیام از پنل کاربر حذف شد. برای مدیر همچنان با برچسب «حذف‌شده» نمایش داده می‌شود.'
            : 'پیام بازگردانی شد و دوباره در پنل کاربر نمایش داده می‌شود.');
    } else {
        flash('error', ($result['reason'] ?? '') === 'not_migrated'
            ? 'این قابلیت هنوز فعال نیست: مایگریشن 2026_10_11_hidden_messages.sql اجرا نشده است.'
            : 'درخواست نامعتبر است.');
    }
    // Back to the same page and query string. A path starting with "//" (or a backslash, which
    // browsers treat alike) would be read as another host, so anything but a plain local path falls
    // back to the reports page.
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (!preg_match('#^/(?![/\\\\])#', $back)) {
        $back = '/reports.php';
    }
    redirect($back);
}

/** The admin's remove/restore control for one row. Posts to the current page (see above). */
function hidden_messages_admin_button(string $source, int $id, bool $isHidden): string {
    if (!hidden_messages_available()) {
        return '';
    }
    $confirm = $isHidden
        ? ''
        : ' onsubmit="return confirm(\'این پیام از پنل کاربر حذف شود؟ برای مدیر باقی می‌ماند و قابل بازگردانی است.\')"';
    return '<form method="post" style="display:inline;margin:0"' . $confirm . '>'
        . csrf_field()
        . '<input type="hidden" name="hidden_message_do" value="' . ($isHidden ? 'restore' : 'hide') . '">'
        . '<input type="hidden" name="source" value="' . e($source) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn-sm' . ($isHidden ? '' : ' btn-danger') . '">' . ($isHidden ? 'بازگردانی' : 'حذف') . '</button>'
        . '</form>';
}

/** The "removed for the customer" badge shown next to a hidden row on an admin's view. */
function hidden_messages_badge(): string {
    return '<span class="badge badge-off" title="این پیام در پنل کاربر نمایش داده نمی‌شود">حذف‌شده برای کاربر</span>';
}
