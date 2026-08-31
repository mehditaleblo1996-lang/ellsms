<?php
/**
 * ELLSMS — six-month admin-approved archive workflow for ellsms_bulk_items (issue #13).
 *
 * docs/database-audit.md flagged ellsms_bulk_items as unbounded and never pruned, deliberately left
 * "permanent by policy" pending a real retention decision. This is that decision: raw items past a
 * cutoff move to ellsms_bulk_items_archive (never deleted outright), only after an admin previews
 * the exact scope and explicitly approves a specific run — never an automatic silent purge.
 *
 * Same restart-safe/idempotent shape as issue #12's report aggregation: one atomic transaction per
 * chunk (INSERT into the archive, DELETE from the live table, advance the run's high-water mark,
 * all together or not at all), so a crash mid-run leaves zero partial effect and a rerun resumes
 * exactly where the last committed chunk left off.
 *
 * ellsms_bulk_jobs is never archived -- it is one row per campaign, not per recipient, and stays in
 * place so an archived item's job_id always still resolves.
 */

declare(strict_types=1);

/** Only a terminal item can ever be archived -- a 'pending' item is live work, never eligible
 * regardless of age, no matter how old its job is. */
const BULK_ARCHIVE_ELIGIBLE_STATUSES = "'sent','failed','cancelled'";

function bulk_archive_retention_months(): int {
    return max(1, (int)(env('ARCHIVE_RETENTION_MONTHS', '6') ?? '6'));
}

function bulk_archive_default_cutoff_date(): string {
    return date('Y-m-d', strtotime('-' . bulk_archive_retention_months() . ' months'));
}

function bulk_archive_audit(array $actor, string $action, int $runId, array $details = []): void {
    $payload = array_merge(['run_id' => $runId], $details);
    \audit((int)($actor['id'] ?? 0), 'bulk_archive.' . $action, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * Read-only scope preview -- exactly what a run would archive if approved right now, computed
 * fresh each call (not from any stored run) so an admin always sees the CURRENT eligible count
 * before deciding on a cutoff.
 *
 * @return array{cutoff_date:string,count:int,min_created_at:?string,max_created_at:?string}
 */
function bulk_archive_preview(?string $cutoffDate = null): array {
    $cutoffDate = $cutoffDate ?? bulk_archive_default_cutoff_date();
    $st = db()->prepare(
        "SELECT COUNT(*), MIN(created_at), MAX(created_at)
         FROM ellsms_bulk_items
         WHERE created_at < ? AND status IN (" . BULK_ARCHIVE_ELIGIBLE_STATUSES . ')'
    );
    $st->execute([$cutoffDate]);
    [$count, $min, $max] = $st->fetch(PDO::FETCH_NUM);
    return ['cutoff_date' => $cutoffDate, 'count' => (int)$count, 'min_created_at' => $min, 'max_created_at' => $max];
}

/**
 * Step 1 of the two-step approval flow: records the exact scope an admin previewed, as a new run
 * awaiting a SEPARATE, explicit approval action (bulk_archive_approve()) -- requesting a run is
 * deliberately not enough on its own to start archiving anything.
 */
function bulk_archive_request(array $actor, string $cutoffDate, string $reason = ''): array {
    $preview = bulk_archive_preview($cutoffDate);
    $db = db();
    $db->prepare(
        'INSERT INTO ellsms_bulk_archive_runs
            (status, cutoff_date, reason, requested_by_user_id, preview_count, preview_min_created_at, preview_max_created_at)
         VALUES (\'pending_approval\', ?, ?, ?, ?, ?, ?)'
    )->execute([$cutoffDate, $reason, (int)($actor['id'] ?? 0), $preview['count'], $preview['min_created_at'], $preview['max_created_at']]);
    $runId = (int)$db->lastInsertId();
    bulk_archive_audit($actor, 'requested', $runId, ['cutoff_date' => $cutoffDate, 'preview_count' => $preview['count'], 'reason' => $reason]);
    return ['ok' => true, 'run_id' => $runId] + $preview;
}

/** Step 2: a separate admin action moves the run from pending_approval to approved. Only an
 * approved run's chunks may ever execute (bulk_archive_run_chunk() enforces this). */
function bulk_archive_approve(array $actor, int $runId): array {
    if (($actor['role'] ?? null) !== 'admin') {
        bulk_archive_audit($actor, 'approve_forbidden', $runId);
        return ['ok' => false, 'error' => 'forbidden'];
    }
    $db = db();
    $st = $db->prepare(
        "UPDATE ellsms_bulk_archive_runs SET status = 'approved', approved_by_user_id = ?, approved_at = NOW()
         WHERE id = ? AND status = 'pending_approval'"
    );
    $st->execute([(int)$actor['id'], $runId]);
    if ($st->rowCount() !== 1) {
        bulk_archive_audit($actor, 'approve_failed', $runId, ['reason' => 'not_pending_approval']);
        return ['ok' => false, 'error' => 'not_pending_approval'];
    }
    bulk_archive_audit($actor, 'approved', $runId);
    return ['ok' => true];
}

function bulk_archive_cancel(array $actor, int $runId): array {
    $st = db()->prepare(
        "UPDATE ellsms_bulk_archive_runs SET status = 'cancelled' WHERE id = ? AND status IN ('pending_approval','approved')"
    );
    $st->execute([$runId]);
    $ok = $st->rowCount() === 1;
    bulk_archive_audit($actor, $ok ? 'cancelled' : 'cancel_failed', $runId);
    return ['ok' => $ok];
}

/** @return array<string,mixed>|null */
function bulk_archive_run(int $runId): ?array {
    $row = db()->prepare('SELECT * FROM ellsms_bulk_archive_runs WHERE id = ?');
    $row->execute([$runId]);
    $r = $row->fetch();
    return $r === false ? null : $r;
}

/** Every column ellsms_bulk_items currently has -- read from information_schema rather than
 * hardcoded, so a future migration adding a column is picked up automatically by both the archive
 * payload and the restore path without this file needing an update. */
function bulk_archive_item_columns(): array {
    static $columns = null;
    if ($columns === null) {
        $st = db()->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' ORDER BY ordinal_position"
        );
        $columns = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    return $columns;
}

/**
 * Advance one atomic chunk: archive (INSERT ... ON DUPLICATE KEY UPDATE id = id, a no-op on a
 * re-run) then delete the same rows from the live table, then advance the run's high-water mark --
 * all in one transaction, so a crash between any two of those steps leaves NONE of them applied.
 *
 * @return array{processed:int,has_more:bool}
 */
function bulk_archive_run_chunk(int $runId, int $chunkRows = 2000): array {
    $chunkRows = max(100, min(20000, $chunkRows));
    $run = bulk_archive_run($runId);
    if ($run === null) {
        throw new RuntimeException('archive run not found: ' . $runId);
    }
    if ($run['status'] === 'completed') {
        return ['processed' => 0, 'has_more' => false];
    }
    if (!in_array($run['status'], ['approved', 'running'], true)) {
        throw new RuntimeException('archive run is not approved/running: ' . $runId);
    }

    if ($run['status'] === 'approved') {
        db()->prepare("UPDATE ellsms_bulk_archive_runs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$runId]);
    }

    $columns = bulk_archive_item_columns();
    $colList = implode(', ', $columns);
    $lastId = (int)$run['last_archived_item_id'];
    $cutoffDate = (string)$run['cutoff_date'];

    $ids = db()->prepare(
        "SELECT id FROM ellsms_bulk_items
         WHERE id > ? AND created_at < ? AND status IN (" . BULK_ARCHIVE_ELIGIBLE_STATUSES . ")
         ORDER BY id ASC LIMIT {$chunkRows}"
    );
    $ids->execute([$lastId, $cutoffDate]);
    $chunkIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if ($chunkIds === []) {
        db()->prepare("UPDATE ellsms_bulk_archive_runs SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$runId]);
        return ['processed' => 0, 'has_more' => false];
    }
    $highId = (int)max($chunkIds);
    $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));

    $result = db_transaction(function (PDO $db) use ($columns, $colList, $chunkIds, $placeholders, $runId, $highId, $lastId): int {
        $rows = $db->prepare("SELECT {$colList} FROM ellsms_bulk_items WHERE id IN ({$placeholders}) ORDER BY id ASC");
        $rows->execute($chunkIds);

        $insert = $db->prepare(
            'INSERT INTO ellsms_bulk_items_archive (id, job_id, status, created_at, archive_run_id, payload)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $archived = 0;
        while ($item = $rows->fetch()) {
            $insert->execute([
                (int)$item['id'], (int)$item['job_id'], (string)$item['status'], (string)$item['created_at'],
                $runId, json_encode($item, JSON_UNESCAPED_UNICODE) ?: '{}',
            ]);
            $archived++;
        }

        $db->prepare("DELETE FROM ellsms_bulk_items WHERE id IN ({$placeholders})")->execute($chunkIds);

        $up = $db->prepare(
            'UPDATE ellsms_bulk_archive_runs SET last_archived_item_id = ?, rows_archived = rows_archived + ? WHERE id = ? AND last_archived_item_id = ?'
        );
        $up->execute([$highId, $archived, $runId, $lastId]);
        if ($up->rowCount() !== 1) {
            throw new RuntimeException('archive run high-water mark changed concurrently: ' . $runId);
        }
        return $archived;
    });

    $more = db()->prepare(
        "SELECT 1 FROM ellsms_bulk_items WHERE id > ? AND created_at < ? AND status IN (" . BULK_ARCHIVE_ELIGIBLE_STATUSES . ') LIMIT 1'
    );
    $more->execute([$highId, $cutoffDate]);
    $hasMore = (bool)$more->fetchColumn();
    if (!$hasMore) {
        db()->prepare("UPDATE ellsms_bulk_archive_runs SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$runId]);
    }

    return ['processed' => $result, 'has_more' => $hasMore];
}

/**
 * One bounded worker pass over a single approved/running run -- loops chunks until either the run
 * completes or $maxRows is reached for this pass (so one very large run can't monopolize the
 * worker loop indefinitely in a single tick).
 *
 * @return array{processed:int,chunks:int,completed:bool}
 */
function bulk_archive_run_worker_pass(int $runId, int $chunkRows = 2000, int $maxRows = 20000): array {
    $processed = 0;
    $chunks = 0;
    $completed = false;
    try {
        while ($processed < $maxRows) {
            $r = bulk_archive_run_chunk($runId, $chunkRows);
            $chunks++;
            $processed += (int)$r['processed'];
            if (!$r['has_more']) {
                $completed = true;
                break;
            }
        }
    } catch (Throwable $t) {
        db()->prepare("UPDATE ellsms_bulk_archive_runs SET status = 'failed', error_message = ? WHERE id = ?")
            ->execute([mb_strimwidth($t->getMessage(), 0, 500, '…'), $runId]);
        Logger::critical('bulk_archive.run_failed', ['run_id' => $runId, 'exception' => $t]);
        throw $t;
    }
    return ['processed' => $processed, 'chunks' => $chunks, 'completed' => $completed];
}

/**
 * Every pending_approval/approved/running run that has no unresolved blocker -- what
 * cron/bulk-archive-worker.php iterates each tick.
 */
function bulk_archive_runnable_runs(): array {
    return db()->query("SELECT id FROM ellsms_bulk_archive_runs WHERE status IN ('approved','running') ORDER BY id ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Restore/retrieval: move archived rows for a run (optionally scoped to one job) back into the live
 * table from their preserved JSON payload, using whatever columns ellsms_bulk_items has TODAY --
 * if the live schema has since dropped a column the payload still has, that key is simply ignored
 * rather than failing the whole restore.
 *
 * @return array{ok:bool,restored:int}
 */
function bulk_archive_restore(array $actor, int $runId, ?int $jobId = null): array {
    if (($actor['role'] ?? null) !== 'admin') {
        bulk_archive_audit($actor, 'restore_forbidden', $runId, ['job_id' => $jobId]);
        return ['ok' => false, 'restored' => 0];
    }

    $where = 'archive_run_id = ?';
    $params = [$runId];
    if ($jobId !== null) {
        $where .= ' AND job_id = ?';
        $params[] = $jobId;
    }
    $rows = db()->prepare("SELECT id, payload FROM ellsms_bulk_items_archive WHERE {$where} ORDER BY id ASC");
    $rows->execute($params);
    $archived = $rows->fetchAll();
    if ($archived === []) {
        bulk_archive_audit($actor, 'restore_empty', $runId, ['job_id' => $jobId]);
        return ['ok' => true, 'restored' => 0];
    }

    $liveColumns = array_flip(bulk_archive_item_columns());
    $restored = db_transaction(function (PDO $db) use ($archived, $liveColumns): int {
        $count = 0;
        foreach ($archived as $row) {
            $payload = json_decode((string)$row['payload'], true) ?: [];
            $payload = array_intersect_key($payload, $liveColumns);
            if ($payload === []) {
                continue;
            }
            $cols = array_keys($payload);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colList = implode(',', $cols);
            $db->prepare("INSERT INTO ellsms_bulk_items ({$colList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE id = id")
                ->execute(array_values($payload));
            $db->prepare('DELETE FROM ellsms_bulk_items_archive WHERE id = ?')->execute([(int)$row['id']]);
            $count++;
        }
        return $count;
    });

    bulk_archive_audit($actor, 'restored', $runId, ['job_id' => $jobId, 'restored' => $restored]);
    return ['ok' => true, 'restored' => $restored];
}
