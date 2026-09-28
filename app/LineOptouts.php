<?php
/**
 * #38 — per-line opt-out with "11" / opt-in with "12".
 *
 * A recipient who texts the stop keyword (default "11") to a line no longer receives messages from
 * THAT line; the start keyword (default "12") re-subscribes. Other lines are unaffected. This is the
 * operator convention for promotional SMS in Iran and mirrors Vesal's MOPreparationJob
 * (check11And12MOMessage + the internal blacklist check at send time).
 *
 *  - line_optout_process_inbound(): called for every NEW inbound message (both inbound stores, from
 *    run_autoreply_pass()); idempotent per inbound message id; confirms by SMS from the same line.
 *  - line_optout_filter(): the send-time check, applied in dispatch_message_raw() (direct, scheduled,
 *    auto-reply, legacy URL API) and bulk_send_group() (every bulk/import job) — the numbers are simply
 *    not sent to and never charged.
 *
 * Settings (ellsms_settings, read fresh so a running worker sees an admin change):
 *   line_optout_enabled            '1' (default) / '0'
 *   line_optout_stop_keywords      comma list, default "11"
 *   line_optout_start_keywords     comma list, default "12"
 *   line_optout_confirm            '1' (default) send a confirmation SMS
 *   line_optout_stop_text / line_optout_start_text   confirmation texts ({line})
 *   line_optout_exempt_types       message types never filtered, default "otp"
 */

declare(strict_types=1);

const LINE_OPTOUT_STOP_TEXT_DEFAULT = 'مشترک گرامی، عضویت شما در خط {line} لغو شد. برای عضویت مجدد عدد ۱۲ را ارسال کنید.';
const LINE_OPTOUT_START_TEXT_DEFAULT = 'عضویت شما در خط {line} فعال شد. برای لغو، عدد ۱۱ را ارسال کنید.';
const LINE_OPTOUT_ERROR = 'گیرنده عضویت خود در این خط را لغو کرده است (۱۱) — ارسال نشد و هزینه‌ای کسر نشد.';

/** Settings are re-read at most every few seconds: dispatch is a hot path, admin changes are rare. */
function line_optout_setting(string $key, string $default): string {
    $state = &$GLOBALS['__line_optout_settings'];
    if (!is_array($state) || microtime(true) - ($state['at'] ?? 0) > 5) {
        $state = ['at' => microtime(true), 'values' => []];
    }
    if (!array_key_exists($key, $state['values'])) {
        try {
            $state['values'][$key] = (string)setting_fresh($key, $default);
        } catch (Throwable) {
            $state['values'][$key] = $default;
        }
    }
    return $state['values'][$key];
}

/** Drops the short settings cache (tests, or right after an admin saves the settings). */
function line_optout_settings_reset(): void {
    $GLOBALS['__line_optout_settings'] = null;
}

function line_optout_enabled(): bool {
    return line_optout_setting('line_optout_enabled', '1') === '1';
}

/** Message types that are never filtered (a one-time password must reach even an opted-out number). */
function line_optout_is_exempt_type(?string $messageType): bool {
    if ($messageType === null || $messageType === '') return false;
    $exempt = array_map('trim', explode(',', line_optout_setting('line_optout_exempt_types', 'otp')));
    return in_array($messageType, $exempt, true);
}

/** "۱۱ ", "11", "١١" → "11". Lower-cased, digits Latin, whitespace and ZWNJ removed. */
function line_optout_normalize_text(string $text): string {
    $text = from_persian_digits($text);
    $text = preg_replace('/[\s\x{200C}\x{200F}\x{200E}]+/u', '', $text) ?? $text;
    return mb_strtolower($text, 'UTF-8');
}

/** 'stop', 'start' or null for an inbound text. */
function line_optout_keyword_action(string $content): ?string {
    $text = line_optout_normalize_text($content);
    if ($text === '') return null;
    foreach (['stop' => ['line_optout_stop_keywords', '11'], 'start' => ['line_optout_start_keywords', '12']] as $action => [$key, $default]) {
        $keywords = array_filter(array_map('line_optout_normalize_text', explode(',', line_optout_setting($key, $default))), static fn($k) => $k !== '');
        if (in_array($text, $keywords, true)) return $action;
    }
    return null;
}

/**
 * Handles one inbound message ($msg: id, originator = the recipient's number, destination = our line,
 * content). Returns the action taken ('stop'/'start') or null when the message is not a keyword, the
 * feature is off, or this message was already handled.
 */
function line_optout_process_inbound(array $msg): ?string {
    if (!line_optout_enabled()) return null;
    $action = line_optout_keyword_action((string)($msg['content'] ?? ''));
    if ($action === null) return null;
    $line = normalize_originator((string)($msg['destination'] ?? ''));
    $mobile = normalize_msisdn((string)($msg['originator'] ?? ''));
    $inboundId = (int)($msg['id'] ?? 0);
    if ($line === null || $mobile === null || $inboundId <= 0) return null;

    $db = db();
    // The idempotency claim: a message already recorded is never applied or confirmed twice.
    $claim = $db->prepare(
        'INSERT INTO ellsms_line_optout_events (inbound_message_id, originator, mobile, action) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE inbound_message_id = inbound_message_id'
    );
    $claim->execute([$inboundId, $line, $mobile, $action]);
    if ($claim->rowCount() !== 1) return null;

    if ($action === 'stop') {
        $db->prepare("INSERT INTO ellsms_line_optouts (originator, mobile, source, source_inbound_id) VALUES (?,?,'sms',?)
                      ON DUPLICATE KEY UPDATE id = id")->execute([$line, $mobile, $inboundId]);
    } else {
        $db->prepare('DELETE FROM ellsms_line_optouts WHERE originator = ? AND mobile = ?')->execute([$line, $mobile]);
    }
    Logger::info('line_optout.' . $action, ['line' => $line, 'inbound_message_id' => $inboundId]);
    Metrics::increment('line_optout.' . $action, 1);

    if (line_optout_setting('line_optout_confirm', '1') === '1') {
        $template = line_optout_setting(
            $action === 'stop' ? 'line_optout_stop_text' : 'line_optout_start_text',
            $action === 'stop' ? LINE_OPTOUT_STOP_TEXT_DEFAULT : LINE_OPTOUT_START_TEXT_DEFAULT
        );
        $text = strtr($template, ['{line}' => to_persian_digits($line)]);
        try {
            // system_sms_send(), not dispatch: the confirmation must reach a number that just opted
            // out, and it is a platform notice from the same line, not a customer's paid message.
            $result = system_sms_send(line_optout_sender_user_id($line), $line, [$mobile], $text);
            if ($result['ok']) {
                $db->prepare('UPDATE ellsms_line_optout_events SET confirmed = 1 WHERE inbound_message_id = ?')->execute([$inboundId]);
            }
        } catch (Throwable $t) {
            Logger::warning('line_optout.confirm_failed', ['line' => $line, 'exception' => $t]);
        }
    }
    return $action;
}

/** The account a confirmation is sent under (only the legacy backend path uses it). */
function line_optout_sender_user_id(string $line): int {
    $db = db();
    foreach ([
        'SELECT assigned_user_id FROM ellsms_numbers WHERE number = ? AND assigned_user_id IS NOT NULL LIMIT 1',
        'SELECT user_id FROM ellsms_meta WHERE originator = ? LIMIT 1',
    ] as $sql) {
        try {
            $st = $db->prepare($sql);
            $st->execute([$line]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        } catch (PDOException) {
        }
    }
    return max(1, (int)setting_fresh('registration_sms_sender_user_id', '1'));
}

/**
 * The subset of $destinations that opted out of $originator, as a set (mobile => true). Empty when the
 * feature is off. Chunked so a 100k-recipient check stays a handful of indexed queries.
 *
 * @param list<string> $destinations
 * @return array<string,true>
 */
function line_optout_filter(string $originator, array $destinations): array {
    if ($destinations === [] || !line_optout_enabled()) return [];
    $line = normalize_originator($originator);
    if ($line === null) return [];
    $found = [];
    try {
        foreach (array_chunk(array_values(array_unique(array_map('strval', $destinations))), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("SELECT mobile FROM ellsms_line_optouts WHERE originator = ? AND mobile IN ({$in})");
            $st->execute(array_merge([$line], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mobile) {
                $found[(string)$mobile] = true;
            }
        }
    } catch (PDOException $e) {
        // 2026_09_29_line_optouts.sql not applied yet: nothing is filtered, exactly as before.
        if (!str_contains($e->getMessage(), "doesn't exist")) throw $e;
        return [];
    }
    return $found;
}
