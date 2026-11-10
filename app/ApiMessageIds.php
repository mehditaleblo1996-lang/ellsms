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
