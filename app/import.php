<?php
/**
 * ELLSMS — large-scale SMS import job core functions.
 *
 * An import job is the lifecycle tracker for a uploaded file: it records
 * ownership, file location, analysis progress, and cost summary. The actual
 * rows are written into the existing ellsms_bulk_items table, linked through
 * an ellsms_bulk_jobs row with status='staged' until the user confirms.
 *
 * This file is intentionally narrow: it does NOT price, normalize, or dispatch
 * messages — those live in app/import_worker.php and reuse the existing
 * pricing/send machinery.
 */

declare(strict_types=1);

require_once __DIR__ . '/import_reader.php';

/** How many source rows one chunk analyzes. Configurable via env. */
function import_chunk_size(): int {
    return max(100, (int)(env('IMPORT_CHUNK_SIZE', '5000') ?? '5000'));
}

/** How many bulk item rows are inserted in one prepared-statement batch. */
function import_db_insert_batch(): int {
    return max(50, (int)(env('DB_INSERT_BATCH', '1000') ?? '1000'));
}

/** Maximum rows a single import job is allowed to accept from a file. */
function import_max_rows(): int {
    return max(1000, (int)(env('IMPORT_MAX_ROWS', '2000000') ?? '2000000'));
}

/**
 * Create an import job record and pre-create its chunks.
 *
 * The file is already stored by import_store_upload(). This function counts
 * rows (streaming), inserts the job header, and splits the file into chunk
 * records so workers can claim them atomically. The web request then returns
 * immediately.
 *
 * @return array{ok:bool, job_id:?int, error:?string}
 */
function import_create_job(
    array $user,
    string $sourceType,
    string $originator,
    string $title,
    string $storageKey,
    ?int $throttleCount = null,
    ?int $throttleMinutes = null,
    ?string $messageType = null,
    ?string $template = null,
    ?array $variableHeaders = null
): array {
    $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
    $userId = (int)($user['id'] ?? 0);

    $countResult = import_count_rows($storageKey);
    if (!$countResult['ok']) {
        import_delete_storage($storageKey);
        return ['ok' => false, 'job_id' => null, 'error' => $countResult['error'] ?? 'شمارش ردیف‌ها ناموفق بود.'];
    }

    $totalRows = $countResult['count'];
    if ($totalRows === 0) {
        import_delete_storage($storageKey);
        return ['ok' => false, 'job_id' => null, 'error' => 'فایل خالی است.'];
    }
    if ($totalRows > import_max_rows()) {
        import_delete_storage($storageKey);
        return ['ok' => false, 'job_id' => null, 'error' => 'تعداد ردیف‌های فایل از سقف مجاز بیشتر است.'];
    }

    $chunkSize = import_chunk_size();
    $chunkCount = (int)ceil($totalRows / $chunkSize);

    try {
        $jobId = db_transaction(function (PDO $db) use (
            $userId, $organizationId, $sourceType, $title, $originator,
            $storageKey, $totalRows, $chunkSize, $throttleCount, $throttleMinutes, $messageType
        ): int {
            $db->prepare(
                "INSERT INTO ellsms_import_jobs
                   (organization_id, user_id, source_type, original_filename, storage_key, status,
                    total_rows, chunk_size, throttle_count, throttle_minutes, message_type,
                    template, variable_headers)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $organizationId, $userId, $sourceType, basename($storageKey), $storageKey,
                'uploaded', $totalRows, $chunkSize, $throttleCount, $throttleMinutes, $messageType,
                $template, $variableHeaders !== null ? json_encode($variableHeaders, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $jobId = (int)$db->lastInsertId();

            $chunkIns = $db->prepare(
                'INSERT INTO ellsms_import_chunks (import_job_id, chunk_no, phase, byte_offset, first_row, last_row, status)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $chunkCount = (int)ceil($totalRows / $chunkSize);
            for ($i = 0; $i < $chunkCount; $i++) {
                $firstRow = $i * $chunkSize + 1;
                $lastRow = min(($i + 1) * $chunkSize, $totalRows);
                $chunkIns->execute([$jobId, $i + 1, 'analyze', 0, $firstRow, $lastRow, 'pending']);
            }

            return $jobId;
        });
    } catch (Throwable $t) {
        Logger::error('import.create_job_failed', ['user_id' => $userId, 'exception' => $t]);
        import_delete_storage($storageKey);
        return ['ok' => false, 'job_id' => null, 'error' => 'خطا در ثبت درخواست واردسازی.'];
    }

    Logger::info('import.job.created', [
        'import_job_id' => $jobId, 'user_id' => $userId, 'organization_id' => $organizationId,
        'total_rows' => $totalRows, 'chunks' => $chunkCount,
    ]);

    return ['ok' => true, 'job_id' => $jobId, 'error' => null];
}

/**
 * Load an import job by id, verifying organization ownership.
 */
function import_load_job(int $jobId, ?int $organizationId): ?array {
    $db = db();
    $st = $db->prepare('SELECT * FROM ellsms_import_jobs WHERE id = ?');
    $st->execute([$jobId]);
    $job = $st->fetch();
    if (!$job) {
        return null;
    }
    if ($organizationId !== null && (int)$job['organization_id'] !== $organizationId) {
        return null;
    }
    return $job;
}

/**
 * Atomically claim one pending import chunk for this worker.
 *
 * Mirrors the bulk_claim_items() pattern: a single UPDATE claims a pending
 * chunk, then a SELECT reads back the row by claim token. Also reclaims
 * expired leases.
 *
 * @return array|null the chunk row, or null if nothing is due
 */
function import_claim_chunk(): ?array {
    $db = db();
    $workerId = worker_id();
    $leaseSeconds = job_lease_seconds();
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));

    db_transaction(function (PDO $db) use ($claimToken, $leaseSeconds): void {
        // Fresh pending chunks first.
        $duePending = $db->prepare(
            "UPDATE ellsms_import_chunks
             SET status='processing', claimed_by=?, claimed_at=NOW(),
                 lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
             WHERE status='pending'
             ORDER BY import_job_id, chunk_no
             LIMIT 1"
        );
        $duePending->execute([$claimToken, $leaseSeconds]);
        $remaining = 1 - $duePending->rowCount();

        if ($remaining > 0) {
            $expiredLease = $db->prepare(
                "UPDATE ellsms_import_chunks
                 SET status='processing', claimed_by=?, claimed_at=NOW(),
                     lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
                 WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
                 ORDER BY import_job_id, chunk_no
                 LIMIT 1"
            );
            $expiredLease->execute([$claimToken, $leaseSeconds]);
        }
    });

    $st = $db->prepare('SELECT * FROM ellsms_import_chunks WHERE claimed_by = ?');
    $st->execute([$claimToken]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Mark a chunk completed and optionally update the parent job counters.
 *
 * Insert-pass chunks do NOT roll into the top-level counters because those
 * counters already reflect the source-file analysis pass.
 */
function import_chunk_completed(int $chunkId, array $counters, bool $updateJobCounters = true): void {
    $db = db();
    db_transaction(function (PDO $db) use ($chunkId, $counters, $updateJobCounters): void {
        $db->prepare(
            "UPDATE ellsms_import_chunks
             SET status='completed',
                 rows_total = ?,
                 rows_valid = ?, rows_invalid = ?, rows_duplicate = ?, rows_blacklisted = ?,
                 rows_priced = ?, rows_unpriced = ?,
                 claimed_by = NULL, lease_expires_at = NULL
             WHERE id = ?"
        )->execute([
            $counters['processed'], $counters['valid'], $counters['invalid'], $counters['duplicate'],
            $counters['blacklisted'], $counters['priced'], $counters['unpriced'],
            $chunkId,
        ]);

        if ($updateJobCounters) {
            $db->prepare(
                "UPDATE ellsms_import_jobs
                 SET processed_rows = processed_rows + ?,
                     valid_rows = valid_rows + ?,
                     invalid_rows = invalid_rows + ?,
                     duplicate_rows = duplicate_rows + ?,
                     blacklisted_rows = blacklisted_rows + ?,
                     priced_rows = priced_rows + ?,
                     unpriced_rows = unpriced_rows + ?
                 WHERE id = (SELECT import_job_id FROM ellsms_import_chunks WHERE id = ?)"
            )->execute([
                $counters['processed'], $counters['valid'], $counters['invalid'],
                $counters['duplicate'], $counters['blacklisted'],
                $counters['priced'], $counters['unpriced'],
                $chunkId,
            ]);
        }
    });
}

/**
 * Mark a chunk failed and record the error.
 */
function import_chunk_failed(int $chunkId, string $error): void {
    $db = db();
    $db->prepare(
        "UPDATE ellsms_import_chunks
         SET status='failed', error_log=?, claimed_by=NULL, lease_expires_at=NULL
         WHERE id = ?"
    )->execute([$error, $chunkId]);
}

/**
 * Create the ellsms_bulk_jobs row that will hold the imported items.
 *
 * Called after the first chunk is analyzed so the job exists before bulk items
 * are inserted. It starts as 'staged' and is only promoted to 'pending' on user
 * confirmation.
 *
 * @return int the bulk job id
 */
function import_create_bulk_job(PDO $db, int $importJobId, array $user, string $originator, string $title, ?int $throttleCount, ?int $throttleMinutes): int {
    $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
    $userId = (int)($user['id'] ?? 0);

    $db->prepare(
        "INSERT INTO ellsms_bulk_jobs
           (user_id, organization_id, source_import_job_id, type, title, originator,
            throttle_count, throttle_minutes, status, total_rows)
         VALUES (?,?,?,?,?,?,?,?,?,0)"
    )->execute([
        $userId, $organizationId, $importJobId, 'p2p', $title, $originator,
        $throttleCount, $throttleMinutes, 'staged',
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Maximum recipients handled synchronously by the legacy send path.
 * Files/lists above this threshold use the async import pipeline.
 */
function import_sync_max_recipients(): int {
    return max(1, (int)(env('SMS_SYNC_MAX_RECIPIENTS', '100') ?? '100'));
}

/**
 * Confirm a ready import job: revalidate cost, reserve wallet/quota for the
 * linked bulk job, release the import-level reservations, and promote the bulk
 * job to 'pending' so the send worker takes over.
 *
 * @return array{ok:bool, bulk_job_id:?int, error:?string}
 */
function import_confirm_job(int $jobId, array $user): array {
    $db = db();
    $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
    $userId = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';

    $job = import_load_job($jobId, $isAdmin ? null : $organizationId);
    if ($job === null || (string)$job['status'] !== 'ready_for_confirmation') {
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'درخواست واردسازی در وضعیت تأیید قرار ندارد.'];
    }
    if (!$isAdmin && ((int)$job['user_id'] !== $userId || ($organizationId !== null && (int)$job['organization_id'] !== $organizationId))) {
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'دسترسی به این درخواست وجود ندارد.'];
    }

    $bulkSt = $db->prepare('SELECT id FROM ellsms_bulk_jobs WHERE source_import_job_id = ? AND status = \'staged\'');
    $bulkSt->execute([$jobId]);
    $bulkJobId = (int)$bulkSt->fetchColumn();
    if ($bulkJobId <= 0) {
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'ارسال مرتبط با واردسازی یافت نشد.'];
    }

    $costSt = $db->prepare('SELECT COALESCE(SUM(price_cost_credits),0) AS cost, COUNT(*) AS cnt FROM ellsms_bulk_items WHERE job_id = ?');
    $costSt->execute([$bulkJobId]);
    $costRow = $costSt->fetch();
    $totalCost = (int)$costRow['cost'];
    $rowCount = (int)$costRow['cnt'];

    if ($rowCount === 0) {
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'ردیف معتبری برای ارسال وجود ندارد.'];
    }

    try {
        if ($organizationId !== null && $organizationId > 0) {
            $quota = usage_reserve_messages($organizationId, $rowCount, 'bulk_job', (string)$bulkJobId);
            if (!$quota['ok']) {
                throw new QuotaExceededException();
            }
            usage_commit_messages('bulk_job', (string)$bulkJobId, $rowCount);
        }

        if (!$isAdmin && $totalCost > 0) {
            $reservation = wallet_reserve($userId, $totalCost, 'bulk_job', (string)$bulkJobId, "reserve:bulk_job:{$bulkJobId}");
            if (!$reservation['ok']) {
                throw new WalletInsufficientBalanceException();
            }
        }

        db_transaction(function (PDO $db) use ($jobId, $bulkJobId, $rowCount): void {
            $db->prepare('UPDATE ellsms_bulk_jobs SET status = \'pending\', total_rows = ? WHERE id = ?')
               ->execute([$rowCount, $bulkJobId]);
            $db->prepare("UPDATE ellsms_import_jobs SET status='queued', sending_started_at=NOW() WHERE id = ? AND status='ready_for_confirmation'")
               ->execute([$jobId]);
        });

        wallet_release_reservation('import_job', (string)$jobId);
        usage_release_messages('import_job', (string)$jobId);

        Logger::info('import.confirmed', ['import_job_id' => $jobId, 'bulk_job_id' => $bulkJobId, 'row_count' => $rowCount, 'cost' => $totalCost]);
        return ['ok' => true, 'bulk_job_id' => $bulkJobId, 'error' => null];
    } catch (QuotaExceededException) {
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'سهمیه‌ی سازمان برای این ارسال کافی نیست.'];
    } catch (WalletInsufficientBalanceException) {
        wallet_release_reservation('bulk_job', (string)$bulkJobId);
        usage_release_messages('bulk_job', (string)$bulkJobId);
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'موجودی کیف پول برای این ارسال کافی نیست.'];
    } catch (Throwable $t) {
        Logger::error('import.confirm.failed', ['import_job_id' => $jobId, 'exception' => $t]);
        wallet_release_reservation('bulk_job', (string)$bulkJobId);
        usage_release_messages('bulk_job', (string)$bulkJobId);
        return ['ok' => false, 'bulk_job_id' => null, 'error' => 'خطا در تأیید درخواست واردسازی.'];
    }
}

/**
 * Cancel an import job that has not yet been confirmed/queued.
 *
 * Deletes the stored file, cancels pending/processing chunks, and releases any
 * reservation. Bulk items already written remain linked for audit but the bulk
 * job is cancelled so the worker will not send them.
 */
function import_cancel_job(int $jobId, array $user): bool {
    $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
    $userId = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';

    return db_transaction(function (PDO $db) use ($jobId, $organizationId, $userId, $isAdmin): bool {
        $st = $db->prepare('SELECT * FROM ellsms_import_jobs WHERE id = ?');
        $st->execute([$jobId]);
        $job = $st->fetch();
        if (!$job) {
            return false;
        }
        if (!$isAdmin && ((int)$job['user_id'] !== $userId || ($organizationId !== null && (int)$job['organization_id'] !== $organizationId))) {
            return false;
        }
        if (!in_array((string)$job['status'], ['uploaded', 'analyzing', 'ready_for_confirmation'], true)) {
            return false;
        }

        $db->prepare("UPDATE ellsms_import_jobs SET status='cancelled', completed_at=NOW() WHERE id = ?")
           ->execute([$jobId]);
        $db->prepare("UPDATE ellsms_import_chunks SET status='cancelled' WHERE import_job_id = ? AND status IN ('pending','processing')")
           ->execute([$jobId]);
        $db->prepare("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE source_import_job_id = ? AND status = 'staged'")
           ->execute([$jobId]);

        // Release any import-level reservation.
        wallet_release_reservation('import_job', (string)$jobId);
        usage_release_messages('import_job', (string)$jobId);

        import_delete_storage((string)$job['storage_key']);
        return true;
    });
}
