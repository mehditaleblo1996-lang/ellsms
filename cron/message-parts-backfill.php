<?php
/**
 * ELLSMS — fills the SMS part count («تعداد پارت») into message rows written before it was stored
 * (app/MessageParts.php, docs/message-parts.md).
 *
 * Run by the scheduler (`message-parts`, every minute): each run walks ellsms_bulk_items,
 * ellsms_message_attempts and ellsms_inbound_messages by id from where the last run stopped, a few
 * thousand rows per UPDATE, for at most --seconds. A large history is filled over several runs; once
 * every table has caught up, a run only checks the rows written since the previous one. Safe to run
 * by hand alongside the scheduler — a second copy waits for nothing and exits.
 *
 *   php cron/message-parts-backfill.php                 fill for up to 50 seconds
 *   php cron/message-parts-backfill.php --seconds=600   a longer manual run
 *   php cron/message-parts-backfill.php --chunk=5000    rows per UPDATE (default 2000)
 *   php cron/message-parts-backfill.php --status        how far each table has come; changes nothing
 */
require_once __DIR__ . '/../app/backend.php';

$args = $argv ?? [];
$opt = static function (string $name, int $default) use ($args): int {
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) return max(1, (int)substr($arg, strlen($name) + 3));
    }
    return $default;
};

if (in_array('--status', $args, true)) {
    foreach (message_parts_backfill_status() as $row) {
        if (!$row['available']) {
            echo "{$row['table']}: no parts column yet — run db/migrations/2026_11_10_message_parts.sql\n";
            continue;
        }
        echo "{$row['table']}: cursor={$row['cursor']} newest_id={$row['max_id']} rows_left={$row['remaining']}"
            . ($row['remaining'] === 0 ? ' (caught up)' : '') . "\n";
    }
    exit(0);
}

$db = db();
if (!(bool)$db->query("SELECT GET_LOCK('ellsms_message_parts_backfill', 0)")->fetchColumn()) {
    echo "another message-parts backfill is running — nothing to do\n";
    exit(0);
}

$deadline = microtime(true) + $opt('seconds', 50);
$chunk = $opt('chunk', MESSAGE_PARTS_BACKFILL_CHUNK);
$failed = false;
foreach (array_values(MESSAGE_PARTS_TABLES) as $i => $table) {
    // Each table gets an even share of the time left, so a huge bulk history does not hold back the
    // other two; time a table leaves unused passes on to the next.
    $tableDeadline = microtime(true) + max(0.0, $deadline - microtime(true)) / (count(MESSAGE_PARTS_TABLES) - $i);
    try {
        $r = message_parts_backfill_table($table, $tableDeadline, $chunk);
        echo "{$table}: filled={$r['filled']} steps={$r['steps']} cursor={$r['cursor']}" . ($r['caught_up'] ? ' (caught up)' : '') . "\n";
        if ($r['filled'] > 0) {
            Logger::info('message_parts.backfill', ['table' => $table, 'filled' => $r['filled'], 'cursor' => $r['cursor'], 'caught_up' => $r['caught_up']]);
        }
    } catch (Throwable $t) {
        $failed = true;
        Logger::error('message_parts.backfill.failed', ['table' => $table, 'exception' => $t]);
        fwrite(STDERR, "{$table}: " . $t->getMessage() . "\n");
    }
}
$db->query("SELECT RELEASE_LOCK('ellsms_message_parts_backfill')");
exit($failed ? 1 : 0);
