<?php
/**
 * Shared sending service (docs/shared-service.md) — سرویس ارسال اشتراکی.
 *
 * A customer gives only a recipient and a text. The sender is the platform's: a line from its own pool
 * (ellsms_shared_lines), one pool per kind —
 *
 *   service  خدماتی     transactional text, sent at any hour, message type 'transactional'
 *   promo    تبلیغاتی    advertising text, sent only inside the allowed hours, message type 'promotional'
 *
 * so the customer depends on no number and no provider. Each pool line is a number on /numbers.php whose
 * route/gateway decides the provider; switching provider is "disable the old lines, enable the new ones"
 * and nothing changes for the customer. Enabled lines are tried in ascending priority (equal priorities
 * share the load by the recipient's number); when a line's gateway cannot take a message, the next one
 * is tried, each attempt its own wallet/quota operation.
 *
 * Content control, before anything is sent:
 *   - prohibited words (app/ContentPolicy.php) refuse the message outright;
 *   - a promotional message to a number on the customer's blacklist, or one that opted out ("11") of
 *     any promo pool line, is refused;
 *   - human review: each text has a "shape" (shared_service_fingerprint(): normalized, every run of
 *     digits replaced). A shape a platform admin has not approved for this customer holds the message
 *     for review ('new_text'); a customer an admin marked as supervised has every message held
 *     ('supervised'). Approving a held group sends it (and, by default, approves the shape so the
 *     customer's later messages of that shape go straight out); rejecting it refuses the messages and
 *     the shape, which is then refused on submission. A held message nobody reviews expires after
 *     shared_service_hold_hours.
 *
 * Money: priced on the shared-service price rows of the price matrix (GATEWAY_PRICE_SHARED_SERVICE /
 * GATEWAY_PRICE_SHARED_PROMO, app/GatewayPrices.php) — the account's, else the global × the tariff
 * percentage, else the sending line's normal price — and charged from the customer's own wallet only when
 * the message is actually sent (ledger reference 'shared_service'). A held or queued message holds no
 * money; if the wallet is short when it is sent, it fails with that reason.
 *
 * The line is granted to exactly one dispatch_message() call (shared_otp_with_line(), the same grant the
 * shared OTP uses), so it never becomes a line the customer can pick anywhere else.
 *
 * Settings (ellsms_settings, read fresh):
 *   shared_service_enabled         '1' to offer the service
 *   shared_service_promo_start/end allowed hours for promotional messages, 'HH:MM' local time (08:00–21:00)
 *   shared_service_hold_hours      a held message not reviewed within this many hours expires (24)
 *   shared_service_default_trust   'supervised' or 'trusted' for a customer with no explicit level
 *                                  ('trusted' = only new texts are reviewed)
 *
 * Schema: db/migrations/2026_10_31_shared_service.sql. Worker: cron/shared-service-worker.php.
 */

declare(strict_types=1);

const SHARED_SERVICE_REF_TYPE = 'shared_service';
const SHARED_SERVICE_KINDS = ['service' => 'خدماتی', 'promo' => 'تبلیغاتی'];
const SHARED_SERVICE_MESSAGE_TYPES = ['service' => 'transactional', 'promo' => 'promotional'];
const SHARED_SERVICE_PRICE_ROWS = ['service' => GATEWAY_PRICE_SHARED_SERVICE, 'promo' => GATEWAY_PRICE_SHARED_PROMO];
const SHARED_SERVICE_TRUST_LEVELS = ['supervised' => 'تحت نظر — همه‌ی پیام‌ها بررسی می‌شود', 'trusted' => 'مورد اعتماد — فقط متن جدید بررسی می‌شود'];
const SHARED_SERVICE_MAX_CONTENT_CHARS = 1000;
const SHARED_SERVICE_MESSAGE_STATUSES = ['held', 'queued', 'sending', 'sent', 'failed', 'rejected', 'expired'];
/** Messages one worker pass (or one approval) sends at most; the rest wait for the next pass. */
const SHARED_SERVICE_BATCH = 200;

function shared_service_now(): string {
    return date('Y-m-d H:i:s');
}

function shared_service_setting(string $key, string $default = ''): string {
    try {
        return (string)setting_fresh($key, $default);
    } catch (PDOException) {
        return $default;
    }
}

/** Whether 2026_10_31_shared_service.sql has been applied. Cached per process once true. */
function shared_service_available(): bool {
    static $available = false;
    if ($available) return true;
    try {
        db()->query('SELECT 1 FROM ellsms_shared_messages LIMIT 0');
        return $available = true;
    } catch (PDOException) {
        return false;
    }
}

function shared_service_enabled(): bool {
    return shared_service_setting('shared_service_enabled', '0') === '1';
}

/** Offered to customers: switched on, migrated, and at least one enabled line in some pool. */
function shared_service_configured(): bool {
    if (!shared_service_available() || !shared_service_enabled()) return false;
    foreach (array_keys(SHARED_SERVICE_KINDS) as $kind) {
        if (shared_service_pool($kind) !== []) return true;
    }
    return false;
}

function shared_service_kind_valid(string $kind): bool {
    return isset(SHARED_SERVICE_KINDS[$kind]);
}

function shared_service_hold_hours(): int {
    return max(1, (int)shared_service_setting('shared_service_hold_hours', '24'));
}

function shared_service_default_trust(): string {
    $trust = shared_service_setting('shared_service_default_trust', 'supervised');
    return isset(SHARED_SERVICE_TRUST_LEVELS[$trust]) ? $trust : 'supervised';
}

/** @return array{0:string, 1:string} the promotional window, 'HH:MM' each */
function shared_service_promo_window(): array {
    $start = shared_service_parse_hhmm(shared_service_setting('shared_service_promo_start', '08:00')) ?? '08:00';
    $end = shared_service_parse_hhmm(shared_service_setting('shared_service_promo_end', '21:00')) ?? '21:00';
    return [$start, $end];
}

/** Pure: "8:5" / "۰۸:۰۰" → "08:05"; null when not a time of day. */
function shared_service_parse_hhmm(string $raw): ?string {
    if (!preg_match('/^\s*(\d{1,2})\s*:\s*(\d{1,2})\s*$/', from_persian_digits($raw), $m)) return null;
    $h = (int)$m[1];
    $i = (int)$m[2];
    if ($h > 23 || $i > 59) return null;
    return sprintf('%02d:%02d', $h, $i);
}

/**
 * Pure: when a promotional message may go out — null when $now is inside [start, end), else the next
 * start. A window whose end is before its start runs over midnight; start == end means all day.
 */
function shared_service_promo_next_slot(DateTimeImmutable $now, string $start, string $end): ?DateTimeImmutable {
    if ($start === $end) return null;
    $t = $now->format('H:i');
    $inside = $start < $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end);
    if ($inside) return null;
    [$h, $i] = array_map('intval', explode(':', $start));
    $next = $now->setTime($h, $i, 0);
    return $next <= $now ? $next->modify('+1 day') : $next;
}

/**
 * Pure: the "shape" of a text — the same normalization prohibited words use (Persian/Arabic letters,
 * invisible marks, digits, spacing, case), then every run of digits becomes '#', so the same message
 * with another code, amount or date is the same shape.
 */
function shared_service_fingerprint(string $content): string {
    $text = content_policy_normalize($content);
    $text = (string)preg_replace('/\d+([.,\/:\-]\d+)*/', '#', $text);
    return hash('sha256', $text);
}

/* ==========================================================================
   The pool
   ========================================================================== */

/** Enabled lines of $kind, in priority order. @return list<array{number:string, priority:int}> */
function shared_service_pool(string $kind): array {
    if (!shared_service_available()) return [];
    $st = db()->prepare('SELECT number, priority FROM ellsms_shared_lines WHERE kind = ? AND enabled = 1 ORDER BY priority, id');
    $st->execute([$kind]);
    return array_map(static fn(array $r): array => ['number' => (string)$r['number'], 'priority' => (int)$r['priority']], $st->fetchAll());
}

/** Every line of $kind, enabled or not (an opt-out to any of them still counts). */
function shared_service_pool_numbers_all(string $kind): array {
    $st = db()->prepare('SELECT number FROM ellsms_shared_lines WHERE kind = ?');
    $st->execute([$kind]);
    return array_map('strval', array_column($st->fetchAll(), 'number'));
}

/**
 * Pure: the order lines are tried in for $destination — by priority, and within one priority starting
 * at a line picked by a hash of the number (a recipient keeps hearing from the same line; the load
 * spreads over the lines of that priority).
 *
 * @param list<array{number:string, priority:int}> $lines
 * @return list<string>
 */
function shared_service_line_order(array $lines, string $destination): array {
    $byPriority = [];
    foreach ($lines as $line) {
        $byPriority[(int)$line['priority']][] = (string)$line['number'];
    }
    ksort($byPriority);
    $out = [];
    foreach ($byPriority as $group) {
        $n = count($group);
        $start = $n > 1 ? crc32($destination) % $n : 0;
        array_push($out, ...array_slice($group, $start), ...array_slice($group, 0, $start));
    }
    return $out;
}

function shared_service_lines_list(): array {
    return db()->query('SELECT l.*, n.id number_id FROM ellsms_shared_lines l LEFT JOIN ellsms_numbers n ON n.number = l.number ORDER BY l.kind, l.priority, l.id')->fetchAll();
}

/** @return array{ok:bool, error?:string} */
function shared_service_line_add(int $adminId, string $number, string $kind, int $priority, string $note = ''): array {
    $line = normalize_originator(from_persian_digits($number));
    if ($line === null) return ['ok' => false, 'error' => 'شماره‌ی خط نامعتبر است.'];
    if (!shared_service_kind_valid($kind)) return ['ok' => false, 'error' => 'نوع خط نامعتبر است.'];
    $exists = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE number = ?');
    $exists->execute([$line]);
    if (!$exists->fetchColumn()) {
        return ['ok' => false, 'error' => "خط {$line} در «شماره‌ها» ثبت نشده است؛ اول آن را ثبت و به مسیر/درگاه وصل کنید."];
    }
    try {
        db()->prepare('INSERT INTO ellsms_shared_lines (number, kind, priority, enabled, note, created_by, created_at) VALUES (?,?,?,1,?,?,?)')
            ->execute([$line, $kind, max(0, min(65535, $priority)), mb_substr(trim($note), 0, 190) ?: null, $adminId, shared_service_now()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return ['ok' => false, 'error' => "خط {$line} از قبل در استخر است."];
        throw $e;
    }
    audit($adminId, 'shared_service.line_add', "{$line} {$kind} p={$priority}");
    return ['ok' => true];
}

/** Enable/disable, change priority or kind, or remove a pool line. */
function shared_service_line_update(int $adminId, int $id, array $changes): array {
    $st = db()->prepare('SELECT * FROM ellsms_shared_lines WHERE id = ?');
    $st->execute([$id]);
    $line = $st->fetch();
    if (!$line) return ['ok' => false, 'error' => 'خط پیدا نشد.'];
    if (!empty($changes['delete'])) {
        db()->prepare('DELETE FROM ellsms_shared_lines WHERE id = ?')->execute([$id]);
        audit($adminId, 'shared_service.line_remove', (string)$line['number']);
        return ['ok' => true];
    }
    $enabled = array_key_exists('enabled', $changes) ? ((bool)$changes['enabled'] ? 1 : 0) : (int)$line['enabled'];
    $priority = array_key_exists('priority', $changes) ? max(0, min(65535, (int)$changes['priority'])) : (int)$line['priority'];
    $kind = isset($changes['kind']) && shared_service_kind_valid((string)$changes['kind']) ? (string)$changes['kind'] : (string)$line['kind'];
    db()->prepare('UPDATE ellsms_shared_lines SET enabled = ?, priority = ?, kind = ?, updated_at = ? WHERE id = ?')
        ->execute([$enabled, $priority, $kind, shared_service_now(), $id]);
    audit($adminId, 'shared_service.line_update', "{$line['number']} enabled={$enabled} p={$priority} {$kind}");
    return ['ok' => true];
}

/* ==========================================================================
   Trust
   ========================================================================== */

function shared_service_trust(int $userId): string {
    $st = db()->prepare('SELECT trust FROM ellsms_shared_trust WHERE user_id = ?');
    $st->execute([$userId]);
    $trust = $st->fetchColumn();
    return is_string($trust) && isset(SHARED_SERVICE_TRUST_LEVELS[$trust]) ? $trust : shared_service_default_trust();
}

/** $trust '' removes the explicit level (the default applies again). */
function shared_service_trust_set(int $adminId, int $userId, string $trust, string $note = ''): array {
    if (resolve_ellsms_managed_user($userId) === null) return ['ok' => false, 'error' => 'کاربر پیدا نشد.'];
    if ($trust === '') {
        db()->prepare('DELETE FROM ellsms_shared_trust WHERE user_id = ?')->execute([$userId]);
    } elseif (isset(SHARED_SERVICE_TRUST_LEVELS[$trust])) {
        db()->prepare('INSERT INTO ellsms_shared_trust (user_id, trust, note, updated_by, updated_at) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE trust = VALUES(trust), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
            ->execute([$userId, $trust, mb_substr(trim($note), 0, 190) ?: null, $adminId, shared_service_now()]);
    } else {
        return ['ok' => false, 'error' => 'سطح اعتماد نامعتبر است.'];
    }
    audit($adminId, 'shared_service.trust', "#{$userId} " . ($trust ?: 'default'));
    return ['ok' => true];
}

function shared_service_trust_list(): array {
    return db()->query('SELECT t.*, u.username FROM ellsms_shared_trust t LEFT JOIN user_ u ON u.id = t.user_id ORDER BY t.updated_at DESC LIMIT 500')->fetchAll();
}

/* ==========================================================================
   Submitting a message
   ========================================================================== */

function shared_service_fail(string $code, string $message, array $extra = []): array {
    return ['ok' => false, 'error_code' => $code, 'error' => $message] + $extra;
}

/** The customer's text row for this shape, created as 'pending' the first time it is seen. */
function shared_service_text_for(array $user, string $kind, string $content): array {
    $fp = shared_service_fingerprint($content);
    $userId = (int)$user['id'];
    $find = db()->prepare('SELECT * FROM ellsms_shared_texts WHERE user_id = ? AND kind = ? AND fingerprint = ?');
    $find->execute([$userId, $kind, $fp]);
    $row = $find->fetch();
    if ($row) return $row;
    db()->prepare('INSERT IGNORE INTO ellsms_shared_texts (organization_id, user_id, kind, fingerprint, sample, status, created_at) VALUES (?,?,?,?,?, \'pending\', ?)')
        ->execute([((int)($user['organization_id'] ?? 0)) ?: null, $userId, $kind, $fp, $content, shared_service_now()]);
    $find->execute([$userId, $kind, $fp]);
    return (array)$find->fetch();
}

/**
 * Accepts one message: refuses it, holds it for review, queues it for the promotional window, or sends
 * it now.
 *
 * @param array $user ['id', 'role', 'organization_id'] — the customer owning and paying for the message
 * @return array{ok:bool, id?:int, status?:string, hold_reason?:?string, send_after?:?string, originator?:?string, parts?:int, error?:string, error_code?:string, fields?:array}
 */
function shared_service_submit(array $user, string $kind, string $destination, string $content, string $source = 'api', ?int $apiKeyId = null, ?string $clientMessageId = null): array {
    if (!shared_service_available() || !shared_service_enabled()) {
        return shared_service_fail('not_enabled', 'سرویس ارسال اشتراکی فعال نیست.');
    }
    if (!shared_service_kind_valid($kind)) {
        return shared_service_fail('invalid_kind', 'نوع ارسال باید خدماتی یا تبلیغاتی باشد.', ['fields' => ['line_type' => ['must_be_one_of: service, promo']]]);
    }
    if (shared_service_pool($kind) === []) {
        return shared_service_fail('not_enabled', 'برای ارسال ' . SHARED_SERVICE_KINDS[$kind] . ' هنوز خطی فعال نشده است.');
    }
    $number = normalize_msisdn(from_persian_digits($destination));
    if ($number === null) {
        return shared_service_fail('invalid_destination', 'شماره‌ی موبایل نامعتبر است.', ['fields' => ['destination' => ['invalid_format']]]);
    }
    $content = str_replace("\r\n", "\n", trim($content));
    if ($content === '') {
        return shared_service_fail('invalid_text', 'متن پیام خالی است.', ['fields' => ['text' => ['required']]]);
    }
    if (mb_strlen($content) > SHARED_SERVICE_MAX_CONTENT_CHARS) {
        return shared_service_fail('invalid_text', 'متن پیام حداکثر ' . to_persian_digits((string)SHARED_SERVICE_MAX_CONTENT_CHARS) . ' نویسه است.', ['fields' => ['text' => ['too_long — max ' . SHARED_SERVICE_MAX_CONTENT_CHARS . ' characters']]]);
    }
    $userId = (int)$user['id'];
    if (($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'shared_service', $userId);
        return shared_service_fail('prohibited_content', CONTENT_POLICY_ERROR, ['fields' => ['text' => ['prohibited_content']]]);
    }
    if ($kind === 'promo') {
        [, $blocked] = filter_blacklist($userId, [$number]);
        if ($blocked > 0) {
            return shared_service_fail('blacklisted', 'این شماره در لیست سیاه شماست؛ پیام تبلیغاتی به آن ارسال نمی‌شود.');
        }
        foreach (shared_service_pool_numbers_all('promo') as $line) {
            if (line_optout_filter($line, [$number]) !== []) {
                return shared_service_fail('opted_out', 'گیرنده دریافت پیام تبلیغاتی را لغو کرده است (۱۱).');
            }
        }
    }

    $text = shared_service_text_for($user, $kind, $content);
    if (($text['status'] ?? '') === 'rejected') {
        return shared_service_fail('text_rejected', 'این متن قبلاً توسط کارشناس رد شده است' . (!empty($text['review_note']) ? ': ' . $text['review_note'] : '.'),
            ['fields' => ['text' => ['rejected_by_review']]]);
    }
    $holdReason = shared_service_trust($userId) === 'supervised'
        ? 'supervised'
        : (($text['status'] ?? '') === 'approved' ? null : 'new_text');

    $now = shared_service_now();
    db()->prepare('INSERT INTO ellsms_shared_messages (organization_id, user_id, kind, text_id, destination, content, status, hold_reason, source, api_key_id, client_message_id, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([((int)($user['organization_id'] ?? 0)) ?: null, $userId, $kind, isset($text['id']) ? (int)$text['id'] : null, $number, $content,
                   $holdReason !== null ? 'held' : 'queued', $holdReason, $source, $apiKeyId, $clientMessageId, $now]);
    $id = (int)db()->lastInsertId();
    Metrics::increment('shared_service.submitted', 1, ['kind' => $kind, 'outcome' => $holdReason !== null ? 'held' : 'accepted']);

    if ($holdReason !== null) {
        return ['ok' => true, 'id' => $id, 'status' => 'held', 'hold_reason' => $holdReason, 'send_after' => null, 'originator' => null, 'parts' => 0];
    }
    $row = shared_service_release($id);
    return ['ok' => true, 'id' => $id] + shared_service_result_of($row);
}

/** @return array{status:string, hold_reason:?string, send_after:?string, originator:?string, parts:int, error?:string} */
function shared_service_result_of(array $row): array {
    return [
        'status'      => (string)$row['status'],
        'hold_reason' => $row['hold_reason'] !== null ? (string)$row['hold_reason'] : null,
        'send_after'  => $row['send_after'] !== null ? (string)$row['send_after'] : null,
        'originator'  => $row['originator'] !== null ? (string)$row['originator'] : null,
        'parts'       => (int)$row['parts'],
    ] + ($row['error'] !== null ? ['error' => (string)$row['error']] : []);
}

function shared_service_message_get(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_shared_messages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * A message cleared to go ('queued'): sends it now, or — a promotional one outside the allowed hours —
 * leaves it queued with send_after at the next window. Returns the row as it ends up.
 */
function shared_service_release(int $id, ?DateTimeImmutable $now = null): array {
    $row = shared_service_message_get($id);
    if ($row === null || $row['status'] !== 'queued') return (array)$row;
    $now ??= new DateTimeImmutable();
    if ($row['kind'] === 'promo') {
        [$start, $end] = shared_service_promo_window();
        $next = shared_service_promo_next_slot($now, $start, $end);
        if ($next !== null) {
            db()->prepare("UPDATE ellsms_shared_messages SET send_after = ?, updated_at = ? WHERE id = ? AND status = 'queued'")
                ->execute([$next->format('Y-m-d H:i:s'), shared_service_now(), $id]);
            return (array)shared_service_message_get($id);
        }
    }
    shared_service_send($row);
    return (array)shared_service_message_get($id);
}

/** Sends one claimed message from its pool, failing over across lines. Never throws. */
function shared_service_send(array $row): void {
    $id = (int)$row['id'];
    $claim = db()->prepare("UPDATE ellsms_shared_messages SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'queued'");
    $claim->execute([shared_service_now(), $id]);
    if ($claim->rowCount() !== 1) return; // someone else took it

    $kind = (string)$row['kind'];
    $owner = backend_find_user_by_id((int)$row['user_id']);
    $user = [
        'id'              => (int)$row['user_id'],
        'role'            => !empty($owner['is_admin']) ? 'admin' : 'user',
        'organization_id' => $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
    ];
    $ok = false;
    $info = 'هیچ خط فعالی برای این نوع ارسال نیست.';
    $parts = 0;
    $usedLine = null;
    try {
        foreach (shared_service_line_order(shared_service_pool($kind), (string)$row['destination']) as $i => $line) {
            $usedLine = $line;
            // Each line is its own wallet/quota operation: a failed attempt is released by
            // dispatch_message() before the next line is tried, so a fail-over never double-charges.
            $result = gateway_price_with_row(SHARED_SERVICE_PRICE_ROWS[$kind], static fn(): array =>
                shared_otp_with_line($line, static fn(): array => dispatch_message(
                    $user, $line, [(string)$row['destination']], (string)$row['content'], null,
                    SHARED_SERVICE_REF_TYPE, $id . ':' . $i, SHARED_SERVICE_MESSAGE_TYPES[$kind]
                ))
            );
            [$ok, $info, $retryable, , , $parts] = array_pad($result, 6, null);
            if ($ok || !$retryable) break;
            Logger::warning('shared_service.line_failover', ['message_id' => $id, 'line' => $line, 'attempt' => $i]);
        }
    } catch (Throwable $t) {
        $ok = false;
        $info = 'خطای داخلی در ارسال.';
        Logger::error('shared_service.send_failed', ['message_id' => $id, 'exception' => $t]);
    }
    $now = shared_service_now();
    db()->prepare('UPDATE ellsms_shared_messages SET status = ?, originator = ?, parts = ?, error_code = ?, error = ?, updated_at = ?, sent_at = ? WHERE id = ?')
        ->execute([$ok ? 'sent' : 'failed', $usedLine, (int)$parts, $ok ? null : 'send_failed', $ok ? null : mb_substr((string)$info, 0, 500), $now, $ok ? $now : null, $id]);
    Metrics::increment('shared_service.sent', 1, ['kind' => $kind, 'outcome' => $ok ? 'sent' : 'failed']);
}

/* ==========================================================================
   Review
   ========================================================================== */

/**
 * Held messages grouped by text: one row per (text, hold reason) with the count, the oldest message
 * and a sample — what the review page lists.
 */
function shared_service_review_queue(int $limit = 200): array {
    $limit = max(1, $limit);
    return db()->query("SELECT m.text_id, m.user_id, m.kind, m.hold_reason, COUNT(*) held, MIN(m.created_at) oldest, MIN(m.id) first_id,
                               t.sample, t.status text_status, u.username
                        FROM ellsms_shared_messages m
                        LEFT JOIN ellsms_shared_texts t ON t.id = m.text_id
                        LEFT JOIN user_ u ON u.id = m.user_id
                        WHERE m.status = 'held'
                        GROUP BY m.text_id, m.user_id, m.kind, m.hold_reason, t.sample, t.status, u.username
                        ORDER BY oldest LIMIT {$limit}")->fetchAll();
}

function shared_service_held_count(): int {
    if (!shared_service_available()) return 0;
    try {
        return (int)db()->query("SELECT COUNT(*) FROM ellsms_shared_messages WHERE status = 'held'")->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/**
 * A platform admin's decision on held messages — all of one text ($textId), or a single message
 * ($messageId, for a supervised customer whose messages should be judged one by one).
 *
 *   approve  the messages are released (sent now, or at the promotional window); with $approveText the
 *            text's shape is approved too, so a trusted customer's later messages of it skip review
 *   reject   the messages are refused with $note (required, the customer sees it); for a whole text the
 *            shape is rejected too, so it is refused on submission from now on
 *
 * @return array{ok:bool, count?:int, error?:string}
 */
function shared_service_review(int $adminId, string $decision, ?int $textId, ?int $messageId = null, string $note = '', bool $approveText = true): array {
    if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'تصمیم نامعتبر است.'];
    $note = mb_substr(trim($note), 0, 500);
    if ($decision === 'reject' && $note === '') return ['ok' => false, 'error' => 'دلیل رد را بنویسید؛ مشتری آن را می‌بیند.'];
    if ($messageId !== null) {
        $st = db()->prepare("SELECT id, user_id, organization_id, text_id FROM ellsms_shared_messages WHERE id = ? AND status = 'held'");
        $st->execute([$messageId]);
    } elseif ($textId !== null) {
        $st = db()->prepare("SELECT id, user_id, organization_id, text_id FROM ellsms_shared_messages WHERE text_id = ? AND status = 'held' ORDER BY id");
        $st->execute([$textId]);
    } else {
        return ['ok' => false, 'error' => 'پیامی انتخاب نشده است.'];
    }
    $rows = $st->fetchAll();
    $now = shared_service_now();

    if ($textId !== null && $messageId === null) {
        $textStatus = $decision === 'reject' ? 'rejected' : ($approveText ? 'approved' : null);
        if ($textStatus !== null) {
            db()->prepare('UPDATE ellsms_shared_texts SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$textStatus, $note !== '' ? $note : null, $adminId, $now, $now, $textId]);
        }
    }
    if ($rows === []) {
        audit($adminId, 'shared_service.review_' . $decision, ($textId !== null ? "text #{$textId}" : "message #{$messageId}") . ' (no held messages)');
        return ['ok' => true, 'count' => 0];
    }

    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in = implode(',', $ids);
    $newStatus = $decision === 'approve' ? 'queued' : 'rejected';
    db()->prepare("UPDATE ellsms_shared_messages SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?,
                          error_code = ?, error = ? WHERE id IN ({$in}) AND status = 'held'")
        ->execute([$newStatus, $note !== '' ? $note : null, $adminId, $now, $now,
                   $decision === 'reject' ? 'rejected' : null, $decision === 'reject' ? mb_substr('رد شد: ' . $note, 0, 500) : null]);
    audit($adminId, 'shared_service.review_' . $decision, ($textId !== null && $messageId === null ? "text #{$textId}" : "message #{$messageId}") . ' messages=' . count($ids) . ($note !== '' ? ' — ' . $note : ''));

    if ($decision === 'reject') {
        $first = $rows[0];
        try {
            notification_insert_panel(
                (int)$first['user_id'],
                $first['organization_id'] !== null ? (int)$first['organization_id'] : null,
                'shared_service.rejected',
                'پیام سرویس اشتراکی رد شد',
                to_persian_digits((string)count($ids)) . ' پیام رد شد: ' . $note,
                '/shared-service.php',
                'warning'
            );
        } catch (Throwable $t) {
            Logger::warning('shared_service.notification_failed', ['exception' => $t]);
        }
    } else {
        // Send right away what fits in one batch; the worker takes the rest.
        foreach (array_slice($ids, 0, SHARED_SERVICE_BATCH) as $id) {
            shared_service_release($id);
        }
    }
    return ['ok' => true, 'count' => count($ids)];
}

/** An approved or rejected text shape back to 'pending' (its next message is reviewed again). */
function shared_service_text_reset(int $adminId, int $textId): array {
    db()->prepare("UPDATE ellsms_shared_texts SET status = 'pending', review_note = NULL, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$adminId, shared_service_now(), shared_service_now(), $textId]);
    audit($adminId, 'shared_service.text_reset', "text #{$textId}");
    return ['ok' => true];
}

function shared_service_texts_list(?string $status = null, ?int $userId = null, int $limit = 300): array {
    $where = ['1=1'];
    $params = [];
    if ($status !== null && in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if ($userId !== null) {
        $where[] = 't.user_id = ?';
        $params[] = $userId;
    }
    $limit = max(1, $limit);
    $st = db()->prepare('SELECT t.*, u.username FROM ellsms_shared_texts t LEFT JOIN user_ u ON u.id = t.user_id WHERE ' . implode(' AND ', $where) . " ORDER BY t.id DESC LIMIT {$limit}");
    $st->execute($params);
    return $st->fetchAll();
}

/* ==========================================================================
   Worker
   ========================================================================== */

/**
 * One pass: expires held messages nobody reviewed in time, then sends queued messages whose time has
 * come (a promotional one found outside the window is pushed to the next one).
 *
 * @return array{expired:int, sent:int, failed:int, deferred:int}
 */
function shared_service_worker_pass(?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable();
    $stats = ['expired' => 0, 'sent' => 0, 'failed' => 0, 'deferred' => 0];
    $cutoff = $now->modify('-' . shared_service_hold_hours() . ' hours')->format('Y-m-d H:i:s');
    $expire = db()->prepare("UPDATE ellsms_shared_messages SET status = 'expired', error_code = 'review_timeout', error = 'در مهلت تعیین‌شده بررسی نشد و ارسال نشد.', updated_at = ?
                             WHERE status = 'held' AND created_at < ?");
    $expire->execute([shared_service_now(), $cutoff]);
    $stats['expired'] = $expire->rowCount();

    $due = db()->prepare("SELECT id FROM ellsms_shared_messages WHERE status = 'queued' AND (send_after IS NULL OR send_after <= ?) ORDER BY id LIMIT " . SHARED_SERVICE_BATCH);
    $due->execute([$now->format('Y-m-d H:i:s')]);
    foreach (array_map('intval', array_column($due->fetchAll(), 'id')) as $id) {
        $row = shared_service_release($id, $now);
        match ($row['status'] ?? '') {
            'sent'   => $stats['sent']++,
            'failed' => $stats['failed']++,
            'queued' => $stats['deferred']++,
            default  => null,
        };
    }
    return $stats;
}

/* ==========================================================================
   Reading
   ========================================================================== */

/** @return array{0:string, 1:list<mixed>} */
function shared_service_scope_sql(array $user, string $a): array {
    if (($user['role'] ?? null) === 'admin') return ['1=1', []];
    $orgId = (int)($user['organization_id'] ?? 0);
    return $orgId > 0 ? ["{$a}.organization_id = ?", [$orgId]] : ["{$a}.user_id = ?", [(int)$user['id']]];
}

/** A message $user may see, or null — another organization's id answers exactly like a missing one. */
function shared_service_message_find(array $user, int $id): ?array {
    [$scope, $params] = shared_service_scope_sql($user, 'm');
    $st = db()->prepare("SELECT m.* FROM ellsms_shared_messages m WHERE m.id = ? AND {$scope}");
    $st->execute([$id, ...$params]);
    return $st->fetch() ?: null;
}

function shared_service_messages_list(array $user, ?string $status = null, int $limit = 50): array {
    [$scope, $params] = shared_service_scope_sql($user, 'm');
    if ($status !== null && in_array($status, SHARED_SERVICE_MESSAGE_STATUSES, true)) {
        $scope .= ' AND m.status = ?';
        $params[] = $status;
    }
    $limit = max(1, $limit);
    $st = db()->prepare("SELECT m.*, u.username FROM ellsms_shared_messages m LEFT JOIN user_ u ON u.id = m.user_id WHERE {$scope} ORDER BY m.id DESC LIMIT {$limit}");
    $st->execute($params);
    return $st->fetchAll();
}

function shared_service_status_label(string $status): array {
    return [
        'held'     => ['در انتظار بررسی', 'badge-pending'],
        'queued'   => ['در صف ارسال', 'badge-processing'],
        'sending'  => ['در حال ارسال', 'badge-processing'],
        'sent'     => ['ارسال شد', 'badge-sent'],
        'failed'   => ['ناموفق', 'badge-failed'],
        'rejected' => ['رد شد', 'badge-failed'],
        'expired'  => ['منقضی (بررسی نشد)', 'badge-off'],
        'pending'  => ['در انتظار بررسی', 'badge-pending'],
        'approved' => ['تأییدشده', 'badge-ok'],
    ][$status] ?? [$status, 'badge-unknown'];
}

function shared_service_hold_reason_label(?string $reason): string {
    return match ($reason) {
        'new_text'   => 'متن جدید',
        'supervised' => 'مشتری تحت نظر',
        null         => '',
        default      => $reason,
    };
}

/** The API's view of a message. The text is returned to its owner; the sender only once it is known. */
function shared_service_message_public(array $m): array {
    return [
        'id'          => (int)$m['id'],
        'line_type'   => (string)$m['kind'],
        'destination' => (string)$m['destination'],
        'text'        => (string)$m['content'],
        'status'      => (string)$m['status'],
        'hold_reason' => $m['hold_reason'] !== null ? (string)$m['hold_reason'] : null,
        'send_after'  => $m['send_after'] !== null ? (string)$m['send_after'] : null,
        'originator'  => $m['originator'] !== null ? (string)$m['originator'] : null,
        'parts'       => (int)$m['parts'],
        'error'       => $m['error'] !== null ? (string)$m['error'] : null,
        'created_at'  => (string)$m['created_at'],
        'sent_at'     => $m['sent_at'] !== null ? (string)$m['sent_at'] : null,
    ];
}
