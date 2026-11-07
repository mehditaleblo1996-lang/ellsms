<?php
/**
 * ELLSMS — the part count («تعداد پارت») stored on every message row (docs/message-parts.md).
 *
 * `parts` (db/migrations/2026_11_10_message_parts.sql) is on ELLSMS's three message tables:
 *
 *   ellsms_bulk_items        written by bulk_queue_job() and import_insert_bulk_items_batch()
 *   ellsms_message_attempts  written by backend_record_gateway_send() / _gateway_rejection()
 *   ellsms_inbound_messages  written by gateway_receive_store()
 *
 * The value is sms_parts() of the message text — the same count the send was priced with
 * (app/Sms/Pricing.php takes its segments from sms_parts()), so the stored number is what the
 * customer was charged for. Rows from before the column existed are filled by the `message-parts`
 * scheduler job (cron/message-parts-backfill.php, message_parts_backfill_table()), a few thousand
 * rows per statement. Until a row is filled its `parts` is NULL, and every reader falls back to the
 * text (message_parts_sql(), message_parts_of_row()), so a report never shows a gap.
 *
 * Every writer adds the column only once the migration has run (message_parts_available()), so the
 * code works on a database that has not applied it yet.
 */

declare(strict_types=1);

/** The tables that carry `parts`. */
const MESSAGE_PARTS_TABLES = ['ellsms_bulk_items', 'ellsms_message_attempts', 'ellsms_inbound_messages'];
/** Rows one backfill UPDATE covers. Small enough that the row locks it takes are brief. */
const MESSAGE_PARTS_BACKFILL_CHUNK = 2000;
/** The ellsms_settings key holding how far the backfill has walked a table (the last id it covered). */
const MESSAGE_PARTS_CURSOR_PREFIX = 'message_parts_backfill:';

/** Whether $table has `parts` — writers and readers use it only once the migration has run. */
function message_parts_available(string $table): bool {
    static $has = [];
    if (!isset($has[$table])) {
        try {
            $st = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'parts'"
            );
            $st->execute([$table]);
            $has[$table] = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $has[$table] = false;
        }
    }
    return $has[$table];
}

/**
 * SQL for a row's part count: the stored `parts`, or — on a row not filled yet, or before the
 * migration — the count of its text ($contentSql, an expression; sms_parts_sql()).
 */
function message_parts_sql(string $table, string $alias, string $contentSql): string {
    $derived = sms_parts_sql($contentSql);
    return message_parts_available($table) ? "COALESCE({$alias}.parts, {$derived})" : $derived;
}

/** Pure: a fetched row's part count — its stored `parts`, else the count of its text. */
function message_parts_of_row(array $row): int {
    if (isset($row['parts']) && $row['parts'] !== '') {
        return (int)$row['parts'];
    }
    return sms_parts((string)($row['content'] ?? ''));
}

/**
 * The value the backfill writes into a $table row (alias t): the count of its text. A message
 * attempt written before its text was stored (2026_09_26_message_attempt_report_columns.sql) takes
 * the per-recipient count from its send's price snapshot instead — segment_count over
 * recipient_count of a group, every recipient of one send sharing one text — and 0 when there is
 * neither a text nor a snapshot.
 */
function message_parts_backfill_expr(string $table): string {
    $fromText = sms_parts_sql('t.content');
    if ($table !== 'ellsms_message_attempts') {
        return $fromText;
    }
    $fromSnapshot = message_parts_table_exists('ellsms_sms_price_snapshots')
        ? "COALESCE((SELECT MAX(ROUND(s.segment_count / s.recipient_count)) FROM ellsms_sms_price_snapshots s
                     WHERE s.reference_type = t.reference_type AND s.reference_id = t.reference_id AND s.recipient_count > 0), 0)"
        : '0';
    if (!backend_message_attempts_have_report_columns()) {
        return $fromSnapshot;
    }
    return "(CASE WHEN COALESCE(t.content, '') <> '' THEN {$fromText} ELSE {$fromSnapshot} END)";
}

function message_parts_table_exists(string $table): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() === 1;
}

/**
 * Fills `parts` on the rows of $table that have none, walking the table by id from where the last
 * pass stopped: each step takes the next $chunk ids (`id > cursor ORDER BY id`, so gaps and a table
 * whose ids start at 10^15 cost nothing) and fills those still NULL in one UPDATE, then moves the
 * cursor. Stops at the newest row or once $deadline (microtime) has passed. Once the cursor has
 * caught up, a pass only looks at the rows written since the previous one — a safety net for a row
 * written without its count (e.g. by an older build still running during a deploy).
 *
 * @return array{table:string, filled:int, steps:int, cursor:int, caught_up:bool}
 */
function message_parts_backfill_table(string $table, float $deadline, int $chunk = MESSAGE_PARTS_BACKFILL_CHUNK, int $pauseMicros = 20000): array {
    $out = ['table' => $table, 'filled' => 0, 'steps' => 0, 'cursor' => 0, 'caught_up' => false];
    if (!in_array($table, MESSAGE_PARTS_TABLES, true) || !message_parts_available($table)) {
        $out['caught_up'] = true;
        return $out;
    }
    $chunk = max(1, $chunk);
    $key = MESSAGE_PARTS_CURSOR_PREFIX . $table;
    $cursor = (int)(setting_fresh($key) ?? '0');
    $next = db()->prepare("SELECT MAX(id) FROM (SELECT id FROM {$table} WHERE id > ? ORDER BY id LIMIT {$chunk}) c");
    $fill = db()->prepare('UPDATE ' . $table . ' t SET t.parts = ' . message_parts_backfill_expr($table) . '
                           WHERE t.id > ? AND t.id <= ? AND t.parts IS NULL');
    while (true) {
        $next->execute([$cursor]);
        $upper = $next->fetchColumn();
        if ($upper === null || $upper === false) {
            $out['caught_up'] = true;
            break;
        }
        $fill->execute([$cursor, (int)$upper]);
        $out['filled'] += $fill->rowCount();
        $out['steps']++;
        $cursor = (int)$upper;
        set_setting($key, (string)$cursor);
        if (microtime(true) >= $deadline) {
            break;
        }
        if ($pauseMicros > 0) {
            usleep($pauseMicros);
        }
    }
    $out['cursor'] = $cursor;
    return $out;
}

/**
 * How far the backfill has come, per table: whether the column exists, the cursor, the newest id,
 * and how many rows lie beyond the cursor (rows still to walk — a PK range count, cheap once the
 * backfill is near the end).
 *
 * @return list<array{table:string, available:bool, cursor:int, max_id:int, remaining:int}>
 */
function message_parts_backfill_status(): array {
    $out = [];
    foreach (MESSAGE_PARTS_TABLES as $table) {
        $row = ['table' => $table, 'available' => message_parts_available($table), 'cursor' => 0, 'max_id' => 0, 'remaining' => 0];
        if ($row['available']) {
            $row['cursor'] = (int)(setting_fresh(MESSAGE_PARTS_CURSOR_PREFIX . $table) ?? '0');
            $row['max_id'] = (int)db()->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
            $st = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE id > ?");
            $st->execute([$row['cursor']]);
            $row['remaining'] = (int)$st->fetchColumn();
        }
        $out[] = $row;
    }
    return $out;
}
