<?php
/**
 * ELLSMS — where a send came from, and the recipients the panel itself dropped from it.
 *
 * Two small things the status report («گزارش وضعیت‌ها», app/Reports/StatusReport.php) needs and the
 * message tables do not otherwise hold:
 *
 *  1. «عامل ارسال پیام» — the send channel. Panel and API sends both reach dispatch_message() and
 *     are both recorded as reference_type 'direct_send', so the request context says which it was:
 *     the public API front controller (public/api/index.php) sets 'api', the auto-reply worker
 *     'autoreply', the AI secretary's replies and outreach 'ai', a schedule is 'schedule' by its own
 *     id, and everything else is 'panel'. The
 *     channel is written onto each ellsms_message_attempts row (send_channel) and onto a bulk job
 *     created through the API (ellsms_bulk_jobs.send_channel).
 *
 *  2. Recipients that never became a message row, because the panel refused them before the send:
 *     on the customer's blacklist («لیست سیاه»), opted out of the line by texting 11, or asked for
 *     from a line the customer may not use («شماره فرستنده نامعتبر»). Each refusal is recorded in
 *     ellsms_send_drops, grouped by (operator, encoding, parts), so the status report can count
 *     them. Recording is reporting only: a failure here is logged and never fails the send.
 *
 * Schema: db/migrations/2026_11_11_status_report.sql. Before it is applied nothing is recorded.
 */

declare(strict_types=1);

/** Send channels («عامل ارسال پیام»): key => label. 'direct' is history from before channels were recorded. */
const SEND_CHANNELS = [
    'panel'     => 'پنل کاربری',
    'api'       => 'وب‌سرویس (API)',
    'schedule'  => 'ارسال زمان‌بندی‌شده',
    'autoreply' => 'منشی پیامک',
    'ai'        => 'منشی هوشمند',
    'p2p'       => 'نظیر به نظیر',
    'smart'     => 'پیامک هوشمند',
    'gradual'   => 'ارسال تدریجی',
    'bulk'      => 'ارسال حجیم',
    'direct'    => 'پنل / وب‌سرویس (سوابق قدیمی)',
];

/** Why the panel dropped a recipient before sending. */
const SEND_DROP_REASONS = ['blacklist', 'optout', 'invalid_sender'];

/**
 * The current request's send context: ['channel' => one of SEND_CHANNELS, 'ref' => ?string].
 * $set merges into it (null values clear a key back to its default); returns the context after.
 *
 * 'ref' identifies the operation ("refType:refId") so a retried send records its drops once.
 *
 * @param array{channel?:?string,ref?:?string}|null $set
 * @return array{channel:string,ref:?string}
 */
function send_context(?array $set = null): array {
    static $ctx = ['channel' => 'panel', 'ref' => null];
    if ($set !== null) {
        if (array_key_exists('channel', $set)) {
            $channel = (string)($set['channel'] ?? '');
            $ctx['channel'] = isset(SEND_CHANNELS[$channel]) ? $channel : 'panel';
        }
        if (array_key_exists('ref', $set)) {
            $ctx['ref'] = $set['ref'] !== null && $set['ref'] !== '' ? mb_substr((string)$set['ref'], 0, 190) : null;
        }
    }
    return $ctx;
}

/** The current send channel ('panel' unless something set another). */
function send_channel(): string {
    return send_context()['channel'];
}

/**
 * Runs $fn with the send context changed by $set, and restores the previous context afterwards —
 * also when $fn throws.
 *
 * @template T
 * @param array{channel?:?string,ref?:?string} $set
 * @param callable():T $fn
 * @return T
 */
function send_context_with(array $set, callable $fn): mixed {
    $previous = send_context();
    send_context($set);
    try {
        return $fn();
    } finally {
        send_context($previous);
    }
}

/** Whether ellsms_send_drops exists (2026_11_11_status_report.sql). */
function send_drops_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_send_drops LIMIT 0');
            $available = true;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/** Whether $table has the send_channel column (2026_11_11_status_report.sql). */
function send_channel_column_available(string $table): bool {
    static $has = [];
    if (!isset($has[$table])) {
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'send_channel'");
            $st->execute([$table]);
            $has[$table] = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $has[$table] = false;
        }
    }
    return $has[$table];
}

/**
 * Pure: dropped recipients grouped the way ellsms_send_drops stores them — by operator, encoding and
 * part count. $content is one text for every recipient, recipient => text, or a list of texts in the
 * same order as $destinations (a recipient may then appear more than once).
 *
 * @param list<string> $destinations
 * @param string|array<array-key,string> $content
 * @param callable(string):string $operatorOf normalized number => operator display name
 * @return list<array{operator:string,encoding:string,parts:int,count:int}>
 */
function send_drops_group(array $destinations, string|array $content, callable $operatorOf): array {
    $groups = [];
    $byIndex = is_array($content) && array_is_list($content);
    foreach (array_values($destinations) as $i => $destination) {
        $destination = (string)$destination;
        $text = is_array($content) ? (string)($byIndex ? ($content[$i] ?? '') : ($content[$destination] ?? '')) : $content;
        $encoding = $text === '' ? 'na' : sms_encoding($text);
        $parts = min(65535, sms_parts($text));
        $number = normalize_msisdn($destination) ?? $destination;
        $operator = mb_substr($operatorOf($number), 0, 120);
        $key = $operator . "\x1f" . $encoding . "\x1f" . $parts;
        $groups[$key] ??= ['operator' => $operator, 'encoding' => $encoding, 'parts' => $parts, 'count' => 0];
        $groups[$key]['count']++;
    }
    return array_values($groups);
}

/**
 * Records recipients the panel dropped before sending ($reason one of SEND_DROP_REASONS).
 *
 * $source optionally ties the drop to an import job (+ chunk) or an aimed-bulk request: the status
 * report counts such a drop only once that job or request was actually sent, and recording the same
 * chunk again (a retried chunk) replaces what it recorded before. Without a source, the current send
 * context's 'ref' makes a retried send record its drops once.
 *
 * @param list<string> $destinations
 * @param string|array<string,string> $content
 * @param array{import_job_id?:int,chunk_no?:int,aimed_request_id?:int,channel?:string,ref?:string} $source
 */
function send_drops_record(int $userId, ?int $organizationId, string $sender, string $reason, array $destinations, string|array $content, array $source = [], ?PDO $db = null): void {
    if ($destinations === [] || !in_array($reason, SEND_DROP_REASONS, true) || !send_drops_available()) {
        return;
    }
    try {
        $db ??= db();
        $channel = (string)($source['channel'] ?? send_channel());
        if (!isset(SEND_CHANNELS[$channel])) $channel = 'panel';
        $ref = $source['ref'] ?? send_context()['ref'];
        $importJobId = isset($source['import_job_id']) ? (int)$source['import_job_id'] : null;
        $chunkNo = isset($source['chunk_no']) ? (int)$source['chunk_no'] : null;
        $aimedId = isset($source['aimed_request_id']) ? (int)$source['aimed_request_id'] : null;
        if ($importJobId !== null || $aimedId !== null) $ref = null;
        if ($importJobId !== null && $chunkNo !== null) {
            $db->prepare('DELETE FROM ellsms_send_drops WHERE import_job_id = ? AND chunk_no = ? AND reason = ?')
               ->execute([$importJobId, $chunkNo, $reason]);
        }
        static $operatorCache = [];
        $operatorOf = static function (string $number) use (&$operatorCache): string {
            $prefix = substr($number, 0, 7);
            return $operatorCache[$prefix] ??= detect_operator($number);
        };
        $insert = $db->prepare(
            'INSERT IGNORE INTO ellsms_send_drops
                (created_at, user_id, organization_id, sender, channel, reason, operator, encoding, parts, drop_count,
                 import_job_id, chunk_no, aimed_request_id, ref_key)
             VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (send_drops_group($destinations, $content, $operatorOf) as $g) {
            $insert->execute([
                $userId, (int)($organizationId ?? 0), mb_substr(normalize_originator($sender) ?? $sender, 0, 32), $channel, $reason,
                $g['operator'], $g['encoding'], $g['parts'], $g['count'], $importJobId, $chunkNo, $aimedId, $ref,
            ]);
        }
    } catch (Throwable $t) {
        Logger::warning('send_drops.record_failed', ['user_id' => $userId, 'reason' => $reason, 'exception' => $t->getMessage()]);
    }
}
