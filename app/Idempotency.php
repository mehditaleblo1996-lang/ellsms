<?php
/**
 * ELLSMS — public API idempotency (Phase 12, STEP 17/18).
 *
 * The concurrency primitive is the UNIQUE(organization_id, endpoint, idempotency_key) constraint on
 * ellsms_idempotency_keys (db/migrations/2026_08_05_public_api.sql), not an application-level lock:
 * two genuinely concurrent requests for the same tuple both attempt an INSERT, MySQL lets exactly
 * one succeed, and the loser's INSERT fails atomically with no window in between where both could
 * believe they "won." This is the same reasoning app/wallet.php's own idempotency_key columns rely
 * on, just expressed as the FIRST write instead of a locked read-then-check (there is no natural row
 * to lock before the very first request for a given key exists).
 *
 * Call shape for an idempotent write endpoint (see app/Api/Handlers/Messages.php):
 *   $lock = idempotency_begin($orgId, $apiKeyId, 'POST /api/v1/messages', $key, $requestHash);
 *   if ($lock['action'] !== 'claimed') { respond according to $lock and return; }
 *   ... do the real work ...
 *   idempotency_complete($lock['id'], $httpStatus, $responseBodyJson, $resourceType, $resourceId);
 */

declare(strict_types=1);

/** After this long, an 'in_progress' row is treated as abandoned (the process that claimed it crashed before completing) and may be reclaimed by a fresh caller — otherwise a single crash would permanently wedge that idempotency key. */
const IDEMPOTENCY_STALE_IN_PROGRESS_SECONDS = 120;

/** Total time a concurrent second caller will poll a genuinely in-flight first caller before giving up and reporting 409 request_in_progress. */
const IDEMPOTENCY_POLL_TIMEOUT_SECONDS = 8.0;
const IDEMPOTENCY_POLL_INTERVAL_MICROSECONDS = 50000; // 50ms

/** Header-safe normalization — bounded length, no control characters. Returns null for anything unsafe (Invariant: never trust raw header bytes). */
function idempotency_normalize_key(string $raw): ?string {
    $key = trim($raw);
    if ($key === '' || strlen($key) > 200) {
        return null;
    }
    return preg_match('/^[A-Za-z0-9_.:\-]+$/', $key) ? $key : null;
}

function idempotency_request_hash(string $rawBody): string {
    return hash('sha256', $rawBody);
}

/**
 * Issue #7's agreed dedup window for the OPTIONAL client_message_id path — deliberately independent
 * of API_IDEMPOTENCY_TTL_HOURS (default 48h, cron/idempotency-prune.php), which governs the
 * REQUIRED Idempotency-Key feature on POST /messages and /bulk-jobs and must not shrink underneath
 * that already-documented, load-bearing behavior just because this issue wants a precise 24h figure
 * for a different, optional feature. Passed explicitly by the caller (see $maxAgeHours below) rather
 * than baked into idempotency_begin() unconditionally, so every existing caller is unaffected by
 * default (null = no live-expiry check, exactly today's behavior).
 */
const IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS = 24;

/**
 * Attempts to claim ($endpoint, $idempotencyKey) for $organizationId. Returns one of:
 *   ['action' => 'claimed', 'id' => int]                                   — proceed, caller must call idempotency_complete()
 *   ['action' => 'replay', 'status' => int, 'body' => string]              — an identical prior request already completed; return this verbatim
 *   ['action' => 'conflict']                                               — same key, different request body (STEP 17 — 409)
 *   ['action' => 'in_progress']                                            — still running elsewhere after the full poll window (STEP 17 — 409, ask the client to retry)
 *
 * $maxAgeHours (issue #7): when set, an existing row older than this is treated as EXPIRED — deleted
 * and reclaimed fresh — rather than replayed/conflicted against, regardless of whether the periodic
 * prune (cron/idempotency-prune.php, a separate and much longer default TTL) has physically deleted
 * it yet. Relying on the prune cron's cadence alone would make the dedup window "up to
 * API_IDEMPOTENCY_TTL_HOURS, depending on when prune last ran" instead of the precise, agreed
 * "24 hours" — this makes the guarantee exact and independent of prune timing. null (the default)
 * preserves every existing caller's behavior unchanged.
 */
function idempotency_begin(int $organizationId, int $apiKeyId, string $endpoint, string $idempotencyKey, string $requestHash, ?int $maxAgeHours = null): array {
    $db = db();
    $deadline = microtime(true) + IDEMPOTENCY_POLL_TIMEOUT_SECONDS;
    $metricTags = ['endpoint' => $endpoint];

    while (true) {
        try {
            $db->prepare(
                'INSERT INTO ellsms_idempotency_keys (organization_id, api_key_id, endpoint, idempotency_key, request_hash, status)
                 VALUES (?,?,?,?,?,\'in_progress\')'
            )->execute([$organizationId, $apiKeyId, $endpoint, $idempotencyKey, $requestHash]);
            Metrics::increment('idempotency.claimed', 1, $metricTags);
            return ['action' => 'claimed', 'id' => (int)$db->lastInsertId()];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                Metrics::increment('idempotency.error', 1, array_merge($metricTags, ['exception' => get_class($e)]));
                throw $e; // not a duplicate-key conflict — a real DB failure, must not be swallowed
            }
        }

        $existing = idempotency_find($organizationId, $endpoint, $idempotencyKey);
        if ($existing === null) {
            // The row that caused our duplicate-key error was deleted between the failed INSERT and
            // this SELECT (a concurrent prune, most plausibly) — safe to just try claiming again.
            continue;
        }

        if ($maxAgeHours !== null) {
            $ageHours = (time() - strtotime((string)$existing['created_at'])) / 3600;
            if ($ageHours >= $maxAgeHours) {
                // Idempotent delete: another concurrent caller may win this race and delete 0 rows —
                // either way, retrying the claim above now succeeds because the conflicting row is gone.
                $db->prepare('DELETE FROM ellsms_idempotency_keys WHERE id = ?')->execute([$existing['id']]);
                Metrics::increment('idempotency.expired_reclaimed', 1, $metricTags);
                continue;
            }
        }

        if ($existing['request_hash'] !== $requestHash) {
            Metrics::increment('idempotency.conflict', 1, $metricTags);
            return ['action' => 'conflict'];
        }

        if ($existing['status'] === 'completed') {
            Metrics::increment('idempotency.dedup_hit', 1, $metricTags);
            return ['action' => 'replay', 'status' => (int)$existing['response_status'], 'body' => (string)$existing['response_body']];
        }

        // status === 'in_progress': either a genuinely concurrent request that hasn't finished yet
        // (poll and wait for it), or an abandoned claim from a crashed process (reclaim it).
        $ageSeconds = time() - strtotime((string)$existing['created_at']);
        if ($ageSeconds >= IDEMPOTENCY_STALE_IN_PROGRESS_SECONDS) {
            $reclaimed = $db->prepare(
                "UPDATE ellsms_idempotency_keys SET request_hash = ?, created_at = NOW(), completed_at = NULL
                 WHERE id = ? AND status = 'in_progress'"
            );
            $reclaimed->execute([$requestHash, $existing['id']]);
            if ($reclaimed->rowCount() === 1) {
                return ['action' => 'claimed', 'id' => (int)$existing['id']];
            }
            continue; // someone else reclaimed/completed it in the meantime — re-read and decide again
        }

        if (microtime(true) >= $deadline) {
            Metrics::increment('idempotency.in_progress_timeout', 1, $metricTags);
            return ['action' => 'in_progress'];
        }
        usleep(IDEMPOTENCY_POLL_INTERVAL_MICROSECONDS);
    }
}

function idempotency_find(int $organizationId, string $endpoint, string $idempotencyKey): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_idempotency_keys WHERE organization_id = ? AND endpoint = ? AND idempotency_key = ?');
    $st->execute([$organizationId, $endpoint, $idempotencyKey]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Bounded — a response body is never stored past this size, protecting the table from an oversized/pathological payload. */
const IDEMPOTENCY_MAX_STORED_BODY_BYTES = 65536;

function idempotency_complete(int $id, int $httpStatus, string $responseBodyJson, ?string $resourceType = null, ?string $resourceId = null): void {
    $body = strlen($responseBodyJson) > IDEMPOTENCY_MAX_STORED_BODY_BYTES
        ? substr($responseBodyJson, 0, IDEMPOTENCY_MAX_STORED_BODY_BYTES)
        : $responseBodyJson;
    db()->prepare(
        "UPDATE ellsms_idempotency_keys
         SET status = 'completed', response_status = ?, response_body = ?, resource_type = ?, resource_id = ?, completed_at = NOW()
         WHERE id = ?"
    )->execute([$httpStatus, $body, $resourceType, $resourceId, $id]);
}

/**
 * Retention (STEP 55) — deletes COMPLETED records older than $ttlHours. Never deletes a still-
 * in_progress row here regardless of age (idempotency_begin()'s own staleness check is what
 * reclaims those, not pruning) — deleting one out from under a request that's genuinely still
 * running would defeat the entire lock. Dry-run capable; returns the number of rows (that would be)
 * deleted.
 */
function idempotency_prune(int $ttlHours, bool $dryRun): int {
    if ($dryRun) {
        $st = db()->prepare("SELECT COUNT(*) c FROM ellsms_idempotency_keys WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
        $st->execute([$ttlHours]);
        return (int)$st->fetch()['c'];
    }
    $st = db()->prepare("DELETE FROM ellsms_idempotency_keys WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $st->execute([$ttlHours]);
    return $st->rowCount();
}
