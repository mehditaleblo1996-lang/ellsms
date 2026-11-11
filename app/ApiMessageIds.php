<?php
/**
 * ELLSMS — the public id of a POST /api/v1/messages send, and which transport rows belong to it.
 *
 * Two things the API's own resource table (ellsms_api_messages) did not have:
 *
 *  1. A random public id. The `id` the API returned was the table's auto-increment row id, shared by
 *     every customer, so two ids told a customer how many API sends the whole platform made in
 *     between. A new send now gets a random 16-digit `public_id` and the API returns that instead.
 *     A row written before the migration has none and keeps answering to its row id, so an id a
 *     customer stored earlier keeps working.
 *
 *  2. A link to its transport rows. dispatch_message() records one ellsms_message_attempts row per
 *     recipient under a time-bucketed hash, not under the API row's id; the delivery state routes
 *     and the panel had to match them by user, number, line, text and time. The send handler now
 *     collects the attempt rows its own dispatch wrote (api_message_attempts_capture_*) and stores
 *     them in ellsms_api_message_attempts, so «شناسه‌ی API» can be shown next to a report row and
 *     the state of a send is read from exactly its rows.
 *
 * Schema: db/migrations/2026_11_13_api_message_public_id.sql. Everything here is guarded: before the
 * migration is applied the API returns row ids and nothing is linked, exactly as before. Linking is
 * bookkeeping only — a failure is logged and never fails a send.
 */

declare(strict_types=1);

/** Public ids are 16 digits: above any row id, and below 2^53 so a client may hold one in a double. */
const API_MESSAGE_PUBLIC_ID_MIN = 1000000000000000;
const API_MESSAGE_PUBLIC_ID_MAX = 8999999999999999;

/** Whether ellsms_api_messages.public_id exists. */
function api_message_public_ids_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            $st = db()->query("SELECT COUNT(*) FROM information_schema.columns
                               WHERE table_schema = DATABASE() AND table_name = 'ellsms_api_messages' AND column_name = 'public_id'");
            $available = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/** Whether ellsms_api_message_attempts exists. */
function api_message_links_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_api_message_attempts LIMIT 0');
            $available = true;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/** A new random public id, as a decimal string. */
function api_message_new_public_id(): string {
    return (string)random_int(API_MESSAGE_PUBLIC_ID_MIN, API_MESSAGE_PUBLIC_ID_MAX);
}

/** Pure: whether $id has the shape of a public id (16 digits in range) rather than a row id. */
function api_message_is_public_id(string $id): bool {
    return strlen($id) === 16 && ctype_digit($id) && (int)$id >= API_MESSAGE_PUBLIC_ID_MIN && (int)$id <= API_MESSAGE_PUBLIC_ID_MAX;
}

/** Pure: the id the API shows for a row — its public id, or its row id when it has none. */
function api_message_public_id_of(array $row): string {
    $public = $row['public_id'] ?? null;
    return $public !== null && (string)$public !== '' ? (string)$public : (string)$row['id'];
}

/**
 * The API message a caller's id names, within one organization: by public id, or — for a row that
 * has no public id — by its row id. Null when there is none (also for another organization's id).
 */
function api_message_find(string $id, int $organizationId): ?array {
    // A row id fits BIGINT UNSIGNED; anything longer cannot be an id.
    if ($id === '' || !ctype_digit($id) || strlen($id) > 19) return null;
    $db = db();
    if (api_message_public_ids_available()) {
        if (api_message_is_public_id($id)) {
            $st = $db->prepare('SELECT * FROM ellsms_api_messages WHERE public_id = ? AND organization_id = ?');
            $st->execute([$id, $organizationId]);
            $row = $st->fetch();
            if ($row) return $row;
        }
        $st = $db->prepare('SELECT * FROM ellsms_api_messages WHERE id = ? AND organization_id = ? AND public_id IS NULL');
    } else {
        $st = $db->prepare('SELECT * FROM ellsms_api_messages WHERE id = ? AND organization_id = ?');
    }
    $st->execute([$id, $organizationId]);
    return $st->fetch() ?: null;
}

/* ---------- collecting the attempt rows one dispatch writes ---------- */

/**
 * The capture state: null while nothing is capturing, else the attempt ids seen so far.
 *
 * @return array{ids: ?list<int>}
 */
function &api_message_attempts_capture_state(): array {
    static $state = ['ids' => null];
    return $state;
}

/** Starts collecting the ids of the ellsms_message_attempts rows this process writes. */
function api_message_attempts_capture_begin(): void {
    $state = &api_message_attempts_capture_state();
    $state['ids'] = [];
}

/**
 * Called by the functions that insert an ellsms_message_attempts row (app/Backend/messages.php,
 * dispatch_gateway_result()). Does nothing unless a capture is running; never throws.
 */
function api_message_attempt_captured(int $attemptId): void {
    $state = &api_message_attempts_capture_state();
    if ($state['ids'] !== null && $attemptId > 0) {
        $state['ids'][] = $attemptId;
    }
}

/** Stops collecting and returns the ids seen, each once. */
function api_message_attempts_capture_end(): array {
    $state = &api_message_attempts_capture_state();
    $ids = array_values(array_unique($state['ids'] ?? []));
    $state['ids'] = null;
    return $ids;
}

/* ---------- the link table ---------- */

/** Records that $attemptIds are the transport rows of API message $apiMessageId. Never throws. */
function api_message_link_attempts(int $apiMessageId, array $attemptIds): void {
    $attemptIds = array_values(array_unique(array_filter(array_map('intval', $attemptIds), static fn(int $id): bool => $id > 0)));
    if ($apiMessageId <= 0 || $attemptIds === [] || !api_message_links_available()) return;
    try {
        $values = implode(',', array_fill(0, count($attemptIds), '(?, ?)'));
        $params = [];
        foreach ($attemptIds as $attemptId) {
            array_push($params, $attemptId, $apiMessageId);
        }
        db()->prepare("INSERT IGNORE INTO ellsms_api_message_attempts (attempt_id, api_message_id) VALUES {$values}")->execute($params);
    } catch (Throwable $t) {
        Logger::warning('api_message.link_failed', ['api_message_id' => $apiMessageId, 'exception' => $t->getMessage()]);
    }
}

/** The ids of the transport rows linked to one API message (empty: none, or sent before linking existed). */
function api_message_attempt_ids(int $apiMessageId): array {
    if ($apiMessageId <= 0 || !api_message_links_available()) return [];
    try {
        $st = db()->prepare('SELECT attempt_id FROM ellsms_api_message_attempts WHERE api_message_id = ?');
        $st->execute([$apiMessageId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $t) {
        Logger::warning('api_message.link_read_failed', ['api_message_id' => $apiMessageId, 'exception' => $t->getMessage()]);
        return [];
    }
}

/**
 * «شناسه‌ی API» for transport rows: attempt id => the id the API returned for the send it belongs
 * to. Rows that were not sent through POST /api/v1/messages (or before linking existed) are absent.
 * For the panel's report and message pages; never throws.
 *
 * @param list<int|string> $attemptIds
 * @return array<int, string>
 */
function api_message_ids_for_attempts(array $attemptIds): array {
    $attemptIds = array_values(array_unique(array_filter(array_map('intval', $attemptIds), static fn(int $id): bool => $id > 0)));
    if ($attemptIds === [] || !api_message_links_available()) return [];
    try {
        $public = api_message_public_ids_available() ? 'm.public_id' : 'NULL';
        $in = implode(',', array_fill(0, count($attemptIds), '?'));
        $st = db()->prepare("SELECT l.attempt_id, m.id, {$public} AS public_id
                               FROM ellsms_api_message_attempts l JOIN ellsms_api_messages m ON m.id = l.api_message_id
                              WHERE l.attempt_id IN ({$in})");
        $st->execute($attemptIds);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['attempt_id']] = api_message_public_id_of($row);
        }
        return $out;
    } catch (Throwable $t) {
        Logger::warning('api_message.ids_for_attempts_failed', ['exception' => $t->getMessage()]);
        return [];
    }
}

/* ---------- one random id per message, shared by the panel and the API ---------- */

/**
 * «شناسه» of ONE message to ONE recipient — the number the panel's send report shows for a row and
 * the API returns as recipients[].id. It replaces the report's sequential references (D34, B12, L56),
 * which told a customer how many messages the whole platform sent between two of theirs.
 *
 * Nothing is stored: the id is the row's (source, row id) put through a keyed permutation, and is
 * turned back into the row by the same key. So every message — also one sent years ago — has an id,
 * no table gains a column, and the send path writes nothing extra.
 *
 *   value  = row id * 4 + source code            (46 bits of row id, 2 bits of source: < 2^48)
 *   id     = 10^14 + permute(value)              always 15 digits, 100000000000000 … 381474976710655
 *
 * 15 digits keeps it apart from an API send id (16 digits, app: api_message_new_public_id()) and
 * from the old short row numbers, and below 2^53 so a client may hold it in a double.
 *
 * The key is 32 random bytes kept in ellsms_settings (`message_id_key`), created by
 * db/migrations/2026_11_14_message_id_key.sql (or on first use, see message_id_key()). It
 * must never change: with another key every id names another row. A database copy (staging) carries
 * it along. Without a readable key, or for a row id beyond 46 bits, there is no id and callers fall
 * back to the old reference.
 */
const MESSAGE_ID_SOURCES = ['attempt' => 0, 'bulk' => 1, 'legacy' => 2];
const MESSAGE_ID_BASE = 100000000000000;          // 10^14
const MESSAGE_ID_DOMAIN = 281474976710656;        // 2^48
const MESSAGE_ID_MAX_ROW = 70368744177663;        // 2^46 - 1
const MESSAGE_ID_KEY_SETTING = 'message_id_key';

/**
 * The permutation key (raw bytes), or null when there is none to use. A key that was read is kept
 * for the life of the process; "none" is not, so a worker started before the key existed finds it
 * on a later call.
 *
 * The key is created by db/migrations/2026_11_14_message_id_key.sql. Where that has not run yet it
 * is created here on first use — but never inside a database transaction: a key written in a
 * transaction that later rolls back would be gone while this process had already made ids with it.
 * In that case there is simply no id yet (callers fall back to the old reference).
 */
function message_id_key(): ?string {
    static $key = null;
    if ($key !== null) return $key;
    try {
        $hex = setting_fresh(MESSAGE_ID_KEY_SETTING);
        if ($hex === null) {
            $db = db();
            if ($db->inTransaction()) return null;
            // INSERT IGNORE lets exactly one of several concurrent processes create it; everyone
            // then reads the row that won.
            $db->prepare('INSERT IGNORE INTO ellsms_settings (skey, svalue) VALUES (?, ?)')
               ->execute([MESSAGE_ID_KEY_SETTING, bin2hex(random_bytes(32))]);
            $hex = setting_fresh(MESSAGE_ID_KEY_SETTING);
        }
        if (is_string($hex) && strlen($hex) === 64 && ctype_xdigit($hex)) {
            $key = hex2bin($hex);
            return $key;
        }
        Logger::error('message_id.key_invalid', []);
    } catch (Throwable $t) {
        Logger::warning('message_id.key_unavailable', ['exception' => $t->getMessage()]);
    }
    return null;
}

/**
 * Pure: a keyed permutation of 0 … 2^48-1 (an 8-round Feistel network over two 24-bit halves, round
 * function HMAC-SHA256). $decrypt runs the rounds backwards: the inverse permutation.
 */
function message_id_permute(int $value, string $key, bool $decrypt = false): int {
    $left = ($value >> 24) & 0xFFFFFF;
    $right = $value & 0xFFFFFF;
    $round = static function (int $n, int $half) use ($key): int {
        $mac = hash_hmac('sha256', chr($n) . pack('N', $half), $key, true);
        return ((ord($mac[0]) << 16) | (ord($mac[1]) << 8) | ord($mac[2]));
    };
    if (!$decrypt) {
        for ($n = 0; $n < 8; $n++) {
            [$left, $right] = [$right, $left ^ $round($n, $right)];
        }
    } else {
        for ($n = 7; $n >= 0; $n--) {
            [$left, $right] = [$right ^ $round($n, $left), $left];
        }
    }
    return ($left << 24) | $right;
}

/** Pure: the id of ($source, $rowId) under $key, or null when it cannot have one. */
function message_public_id_encode(string $source, int $rowId, string $key): ?string {
    if (!isset(MESSAGE_ID_SOURCES[$source]) || $rowId <= 0 || $rowId > MESSAGE_ID_MAX_ROW) return null;
    return (string)(MESSAGE_ID_BASE + message_id_permute($rowId * 4 + MESSAGE_ID_SOURCES[$source], $key));
}

/** Pure: whether $id has the shape of a message id (15 digits in range). Says nothing about the row existing. */
function message_public_id_shape(string $id): bool {
    return strlen($id) === 15 && ctype_digit($id) && (int)$id >= MESSAGE_ID_BASE && (int)$id < MESSAGE_ID_BASE + MESSAGE_ID_DOMAIN;
}

/**
 * Pure: [source, row id] of a message id under $key, or null when it is not one.
 *
 * @return array{0:string, 1:int}|null
 */
function message_public_id_decode_with(string $id, string $key): ?array {
    if (!message_public_id_shape($id)) return null;
    $value = message_id_permute((int)$id - MESSAGE_ID_BASE, $key, true);
    $source = array_search($value & 3, MESSAGE_ID_SOURCES, true);
    $rowId = $value >> 2;
    return $source !== false && $rowId > 0 ? [$source, $rowId] : null;
}

/** «شناسه» of a message row ('attempt' | 'bulk' | 'legacy'), or null when there is no key. */
function message_public_id(string $source, int $rowId): ?string {
    $key = message_id_key();
    return $key === null ? null : message_public_id_encode($source, $rowId, $key);
}

/** @return array{0:string, 1:int}|null [source, row id] of a message id, or null */
function message_public_id_decode(string $id): ?array {
    if (!message_public_id_shape($id)) return null; // before touching the key: most ids are not one
    $key = message_id_key();
    return $key === null ? null : message_public_id_decode_with($id, $key);
}
