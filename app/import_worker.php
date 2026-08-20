<?php
/**
 * ELLSMS — asynchronous large-scale import worker.
 *
 * This worker is intentionally separate from cron/worker.php so that heavy
 * file analysis never competes with send workers for ticks. It runs two passes
 * per import job:
 *
 *   Pass 1 (analyze): stream the file in chunks, normalize/validate/dedupe/
 *                     blacklist, compute segments, price, and write unique
 *                     rows into ellsms_import_dedupe. Accumulate total cost.
 *   Reserve: reserve wallet/quota for the exact analyzed cost, create the
 *             linked ellsms_bulk_jobs row, and create insert chunks.
 *   Pass 2 (insert): read ellsms_import_dedupe in chunks, price at the same
 *                    instant used in pass 1, and insert ellsms_bulk_items.
 *
 * Only one worker may run pass 1 for a given job. Pass 2 chunks are claimed
 * atomically and may run in parallel across workers.
 */

declare(strict_types=1);

require_once __DIR__ . '/import.php';

/** Main entry point for the persistent import worker. Returns rows/chunks processed this tick. */
function import_worker_run_once(): int {
    $processed = 0;

    // Prefer advancing insert passes (parallel-safe) before grabbing a new job.
    $insertChunk = import_claim_insert_chunk();
    if ($insertChunk !== null) {
        import_process_insert_chunk($insertChunk);
        $processed++;
    }

    // If no insert work, start analyzing a newly uploaded job.
    $job = import_claim_uploaded_job();
    if ($job !== null) {
        import_job_run_analysis((int)$job['id']);
        $processed++;
    }

    return $processed;
}

/**
 * Atomically claim one uploaded import job for analysis.
 *
 * The claim sets status='analyzing' so no other worker starts pass 1.
 */
function import_claim_uploaded_job(): ?array {
    $db = db();
    $workerId = worker_id();
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));

    $db->prepare(
        "UPDATE ellsms_import_jobs
         SET status='analyzing', analysis_started_at=NOW(), claimed_by=?
         WHERE status='uploaded'
         ORDER BY id
         LIMIT 1"
    )->execute([$claimToken]);

    $st = $db->prepare('SELECT * FROM ellsms_import_jobs WHERE claimed_by = ?');
    $st->execute([$claimToken]);
    $job = $st->fetch();
    return $job ?: null;
}

/**
 * Run the full two-pass analysis for one import job.
 *
 * This is called by the worker that claimed the job in 'uploaded' status.
 * It runs to completion (or failure) for this job within this invocation.
 */
function import_job_run_analysis(int $jobId): void {
    $db = db();
    $job = import_load_job($jobId, null);
    if ($job === null || (string)$job['status'] !== 'analyzing') {
        return;
    }

    Logger::info('import.analysis.started', ['import_job_id' => $jobId, 'total_rows' => $job['total_rows']]);

    // Pass 1: analyze all chunks sequentially (single worker for this job).
    try {
        import_job_analyze_pass($job);
    } catch (Throwable $t) {
        Logger::error('import.analysis.failed', ['import_job_id' => $jobId, 'exception' => $t]);
        $db->prepare("UPDATE ellsms_import_jobs SET status='failed', error_message=?, completed_at=NOW() WHERE id = ?")
           ->execute([mb_strimwidth($t->getMessage(), 0, 500, '…'), $jobId]);
        wallet_release_reservation('import_job', (string)$jobId);
        return;
    }

    // Reserve exact cost and create the bulk job + insert chunks.
    try {
        import_job_reserve_and_stage($job);
    } catch (Throwable $t) {
        Logger::error('import.reservation.failed', ['import_job_id' => $jobId, 'exception' => $t]);
        $db->prepare("UPDATE ellsms_import_jobs SET status='failed', error_message=?, completed_at=NOW() WHERE id = ?")
           ->execute([mb_strimwidth($t->getMessage(), 0, 500, '…'), $jobId]);
        return;
    }

    Logger::info('import.analysis.completed', ['import_job_id' => $jobId]);
}

/**
 * Pass 1: stream every analyze chunk and write unique rows to the dedupe table.
 */
function import_job_analyze_pass(array $job): void {
    $db = db();
    $jobId = (int)$job['id'];
    $userId = (int)$job['user_id'];
    $applyBlacklist = true;
    $template = !empty($job['template']) ? (string)$job['template'] : null;
    $variableHeaders = !empty($job['variable_headers']) ? json_decode((string)$job['variable_headers'], true) : null;
    if (!is_array($variableHeaders)) {
        $variableHeaders = null;
    }

    $dedupeIns = $db->prepare(
        'INSERT IGNORE INTO ellsms_import_dedupe (import_job_id, mobile, content_fingerprint, content, segments)
         VALUES (?,?,?,?,?)'
    );

    $chunkSt = $db->prepare(
        "SELECT * FROM ellsms_import_chunks
         WHERE import_job_id = ? AND phase = 'analyze' AND status IN ('pending','processing')
         ORDER BY chunk_no"
    );
    $chunkSt->execute([$jobId]);

    $counters = [
        'processed' => 0, 'valid' => 0, 'invalid' => 0,
        'duplicate' => 0, 'blacklisted' => 0, 'priced' => 0, 'unpriced' => 0,
    ];

    while ($chunk = $chunkSt->fetch()) {
        import_claim_chunk_row($chunk['id']);
        $chunkCounters = [
            'processed' => 0, 'valid' => 0, 'invalid' => 0,
            'duplicate' => 0, 'blacklisted' => 0, 'priced' => 0, 'unpriced' => 0,
        ];

        $rows = import_read_row_range(
            (string)$job['storage_key'],
            (int)$chunk['first_row'],
            (int)$chunk['last_row']
        );

        // Stage rows, collapsing exact mobile+content duplicates inside this chunk.
        $candidates = [];
        foreach ($rows as $row) {
            $chunkCounters['processed']++;

            if ($variableHeaders !== null) {
                $vars = [];
                foreach ($variableHeaders as $i => $h) {
                    $vars[$h] = trim((string)($row['cells'][$i + 2] ?? ''));
                }
                $rowTemplate = trim((string)($row['cells'][1] ?? ''));
                $content = trim(render_bulk_template($rowTemplate, $vars));
            } elseif ($template !== null) {
                $content = trim(render_bulk_template($template, []));
            } else {
                $content = $row['content'];
            }

            $mobile = $row['mobile'];
            if ($mobile === null || $mobile === '' || $content === '') {
                $chunkCounters['invalid']++;
                continue;
            }
            $fingerprint = hash('sha256', $content);
            $key = $row['mobile'] . "\0" . $fingerprint;
            if (isset($candidates[$key])) {
                $chunkCounters['duplicate']++;
                continue;
            }
            $candidates[$key] = ['mobile' => $mobile, 'content' => $content, 'fingerprint' => $fingerprint];
        }

        // Blacklist filter (per mobile, applied after within-chunk dedupe).
        $mobiles = array_values(array_unique(array_column($candidates, 'mobile')));
        $blockedMobiles = [];
        if ($mobiles !== [] && $applyBlacklist) {
            [$allowed, $blocked] = filter_blacklist($userId, $mobiles);
            $blockedMobiles = array_flip(array_diff($mobiles, $allowed));
        }
        $chunkCounters['blacklisted'] += count(array_filter($candidates, static fn(array $c): bool => isset($blockedMobiles[$c['mobile']])));

        // Price the allowed rows in one batch.
        $toPrice = [];
        foreach ($candidates as $c) {
            if (isset($blockedMobiles[$c['mobile']])) {
                continue;
            }
            $toPrice[] = ['mobile' => $c['mobile'], 'segments' => sms_parts($c['content'])];
        }

        $priced = null;
        if ($toPrice !== []) {
            $priced = sms_pricing_price_messages(
                $toPrice,
                (string)$job['originator'],
                (string)$job['message_type'],
                (string)$job['analysis_started_at'],
                false
            );
        }

        // Insert dedupe rows and count outcomes (cross-chunk duplicates fail with INSERT IGNORE).
        foreach ($candidates as $c) {
            if (isset($blockedMobiles[$c['mobile']])) {
                continue;
            }

            $segments = sms_parts($c['content']);
            $dedupeIns->execute([$jobId, $c['mobile'], $c['fingerprint'], $c['content'], $segments]);
            if ($dedupeIns->rowCount() === 0) {
                $chunkCounters['duplicate']++;
                continue;
            }

            $chunkCounters['valid']++;
            if ($priced !== null && ($priced['per_mobile'][$c['mobile']]['ok'] ?? false)) {
                $chunkCounters['priced']++;
            } else {
                $chunkCounters['unpriced']++;
            }
        }

        import_chunk_completed((int)$chunk['id'], $chunkCounters);
        foreach ($chunkCounters as $k => $v) {
            $counters[$k] += $v;
        }
    }

    Logger::info('import.analyze_pass.completed', [
        'import_job_id' => $jobId,
        'processed' => $counters['processed'],
        'valid' => $counters['valid'],
        'invalid' => $counters['invalid'],
        'duplicate' => $counters['duplicate'],
        'blacklisted' => $counters['blacklisted'],
        'priced' => $counters['priced'],
        'unpriced' => $counters['unpriced'],
    ]);
}

/** Claim a specific chunk row by id (used during pass 1 sequential processing). */
function import_claim_chunk_row(int $chunkId): void {
    $db = db();
    $workerId = worker_id();
    $leaseSeconds = job_lease_seconds();
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));

    $db->prepare(
        "UPDATE ellsms_import_chunks
         SET status='processing', claimed_by=?, claimed_at=NOW(),
             lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
         WHERE id = ? AND status IN ('pending','processing')"
    )->execute([$claimToken, $leaseSeconds, $chunkId]);
}

/**
 * After pass 1: reserve wallet/quota, create bulk job, create insert chunks.
 *
 * Only PRICED rows are reserved; unpriced/invalid/duplicate/blacklisted rows
 * are counted for reporting but never charged or queued.
 */
function import_job_reserve_and_stage(array $job): void {
    $db = db();
    $jobId = (int)$job['id'];
    $userId = (int)$job['user_id'];
    $organizationId = isset($job['organization_id']) ? (int)$job['organization_id'] : null;
    $isAdmin = false; // organization jobs are never admin-exempt

    // Number of rows that actually resolved to a price during analysis.
    $pricedRowsSt = $db->prepare("SELECT SUM(rows_priced) FROM ellsms_import_chunks WHERE import_job_id = ? AND phase = 'analyze' AND status = 'completed'");
    $pricedRowsSt->execute([$jobId]);
    $pricedRows = (int)$pricedRowsSt->fetchColumn();

    // Re-price the full dedupe set at the analysis instant to get the exact total cost.
    $totalCost = 0;
    $batchSize = import_chunk_size();
    $offset = 0;
    do {
        $rows = $db->prepare('SELECT mobile, content, segments FROM ellsms_import_dedupe WHERE import_job_id = ? ORDER BY id LIMIT ? OFFSET ?');
        $rows->execute([$jobId, $batchSize, $offset]);
        $batch = $rows->fetchAll();
        if ($batch === []) {
            break;
        }
        $toPrice = array_map(static fn(array $r): array => ['mobile' => (string)$r['mobile'], 'segments' => (int)$r['segments']], $batch);
        $priced = sms_pricing_price_messages(
            $toPrice,
            (string)$job['originator'],
            (string)$job['message_type'],
            (string)$job['analysis_started_at'],
            $isAdmin
        );
        $totalCost += (int)$priced['total_cost'];
        $offset += $batchSize;
    } while (count($batch) === $batchSize);

    // Reserve outside the main transaction: both wallet and quota reservations are independently
    // idempotent and their own internal transactions commit. If one succeeds and the other fails we
    // must release the first, otherwise an analyzed-but-failed reservation would hold quota/wallet.
    $quotaReserved = false;
    if ($organizationId !== null && $organizationId > 0 && $pricedRows > 0) {
        $quota = usage_reserve_messages($organizationId, $pricedRows, 'import_job', (string)$jobId);
        if (!$quota['ok']) {
            throw new QuotaExceededException('سهمیه‌ی سازمان برای این تعداد پیام کافی نیست.');
        }
        $quotaReserved = true;
    }

    $walletReserved = false;
    if (!$isAdmin && $totalCost > 0) {
        $reservation = wallet_reserve($userId, $totalCost, 'import_job', (string)$jobId, "reserve:import_job:{$jobId}");
        if (!$reservation['ok']) {
            if ($quotaReserved) {
                usage_release_messages('import_job', (string)$jobId);
            }
            throw new WalletInsufficientBalanceException('موجودی کیف پول برای ارسال این تعداد پیام کافی نیست.');
        }
        $walletReserved = true;
    }

    try {
        db_transaction(function (PDO $db) use ($jobId, $totalCost, $pricedRows, $job): void {
            // Update job counters and cost.
            $db->prepare(
                "UPDATE ellsms_import_jobs
                 SET estimated_cost_credits = ?, valid_rows = ?, status='analyzing'
                 WHERE id = ?"
            )->execute([$totalCost, $pricedRows, $jobId]);

            // Create the staged bulk job.
            $user = ['id' => (int)$job['user_id'], 'organization_id' => isset($job['organization_id']) ? (int)$job['organization_id'] : null];
            $bulkJobId = import_create_bulk_job(
                $db, $jobId, $user,
                (string)$job['originator'],
                (string)$job['original_filename'],
                $job['throttle_count'] !== null ? (int)$job['throttle_count'] : null,
                $job['throttle_minutes'] !== null ? (int)$job['throttle_minutes'] : null
            );

            // Create insert chunks based on dedupe id ranges.
            $minMax = $db->prepare('SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM ellsms_import_dedupe WHERE import_job_id = ?');
            $minMax->execute([$jobId]);
            $range = $minMax->fetch();
            $minId = (int)($range['min_id'] ?? 0);
            $maxId = (int)($range['max_id'] ?? 0);

            if ($minId > 0 && $maxId > 0) {
                $insertSize = import_db_insert_batch();
                $chunkNo = 1;
                $chunkIns = $db->prepare(
                    'INSERT INTO ellsms_import_chunks (import_job_id, chunk_no, phase, first_row, last_row, status)
                     VALUES (?,?,?,?,?,?)'
                );
                for ($start = $minId; $start <= $maxId; $start += $insertSize) {
                    $end = min($start + $insertSize - 1, $maxId);
                    $chunkIns->execute([$jobId, $chunkNo++, 'insert', $start, $end, 'pending']);
                }
                $db->prepare('UPDATE ellsms_bulk_jobs SET total_rows = ? WHERE id = ?')
                   ->execute([$pricedRows, $bulkJobId]);
            }
        });
    } catch (Throwable $t) {
        if ($quotaReserved) {
            usage_release_messages('import_job', (string)$jobId);
        }
        if ($walletReserved) {
            wallet_release_reservation('import_job', (string)$jobId);
        }
        throw $t;
    }
}

/**
 * Atomically claim one pending insert chunk.
 */
function import_claim_insert_chunk(): ?array {
    $db = db();
    $workerId = worker_id();
    $leaseSeconds = job_lease_seconds();
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));

    db_transaction(function (PDO $db) use ($claimToken, $leaseSeconds): void {
        $duePending = $db->prepare(
            "UPDATE ellsms_import_chunks
             SET status='processing', claimed_by=?, claimed_at=NOW(),
                 lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
             WHERE phase='insert' AND status='pending'
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
                 WHERE phase='insert' AND status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
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
 * Pass 2: read one insert chunk's dedupe rows, price at the job's analysis
 * instant, and insert the corresponding bulk_items.
 */
function import_process_insert_chunk(array $chunk): void {
    $db = db();
    $chunkId = (int)$chunk['id'];
    $jobId = (int)$chunk['import_job_id'];

    $job = import_load_job($jobId, null);
    if ($job === null || (string)$job['status'] !== 'analyzing') {
        import_chunk_failed($chunkId, 'وضعیت درخواست واردسازی نامعتبر است.');
        return;
    }

    $bulkSt = $db->prepare('SELECT id FROM ellsms_bulk_jobs WHERE source_import_job_id = ? AND status = \'staged\'');
    $bulkSt->execute([$jobId]);
    $bulkJobId = (int)$bulkSt->fetchColumn();
    if ($bulkJobId <= 0) {
        import_chunk_failed($chunkId, 'ارسال مرتبط با واردسازی یافت نشد.');
        return;
    }

    try {
        $rows = $db->prepare(
            'SELECT mobile, content, segments FROM ellsms_import_dedupe
             WHERE import_job_id = ? AND id >= ? AND id <= ?
             ORDER BY id'
        );
        $rows->execute([$jobId, (int)$chunk['first_row'], (int)$chunk['last_row']]);
        $batch = $rows->fetchAll();

        if ($batch === []) {
            import_chunk_completed($chunkId, [
                'processed' => 0, 'valid' => 0, 'invalid' => 0,
                'duplicate' => 0, 'blacklisted' => 0, 'priced' => 0, 'unpriced' => 0,
            ], false);
            import_job_check_insert_completion($jobId);
            return;
        }

        $toPrice = array_map(
            static fn(array $r): array => ['mobile' => (string)$r['mobile'], 'segments' => (int)$r['segments']],
            $batch
        );
        $priced = sms_pricing_price_messages(
            $toPrice,
            (string)$job['originator'],
            (string)$job['message_type'],
            (string)$job['analysis_started_at'],
            false
        );

        $insertBuffer = [];
        $inserted = 0;
        $unpriced = 0;
        $batchSize = import_db_insert_batch();
        foreach ($batch as $index => $row) {
            $p = $priced['per_index'][$index] ?? null;
            if ($p === null || !($p['ok'] ?? false)) {
                $unpriced++;
                continue;
            }
            $insertBuffer[] = [
                $bulkJobId,
                (string)$row['mobile'],
                (string)$row['content'],
                (int)$p['unit_price'],
                (int)$p['cost'],
                (string)($p['operator_code'] ?? ''),
                $p['route_id'] ?? null,
                (string)($p['group_key'] ?? ''),
            ];
            if (count($insertBuffer) >= $batchSize) {
                import_insert_bulk_items_batch($db, $insertBuffer);
                $inserted += count($insertBuffer);
                $insertBuffer = [];
            }
        }
        if ($insertBuffer !== []) {
            import_insert_bulk_items_batch($db, $insertBuffer);
            $inserted += count($insertBuffer);
        }

        import_chunk_completed($chunkId, [
            'processed' => count($batch), 'valid' => $inserted, 'invalid' => 0,
            'duplicate' => 0, 'blacklisted' => 0, 'priced' => $inserted, 'unpriced' => $unpriced,
        ], false);

        import_job_check_insert_completion($jobId);
    } catch (Throwable $t) {
        Logger::error('import.insert_chunk.failed', ['chunk_id' => $chunkId, 'exception' => $t]);
        import_chunk_failed($chunkId, mb_strimwidth($t->getMessage(), 0, 500, '…'));
    }
}

/**
 * Insert a bounded batch of bulk_items rows in a single multi-row statement.
 *
 * @param list<array{0:int,1:string,2:string,3:int,4:int,5:string,6:?int,7:string}> $rows
 */
function import_insert_bulk_items_batch(PDO $db, array $rows): void {
    if ($rows === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?)'));
    $flat = array_merge([], ...$rows);
    $db->prepare(
        "INSERT INTO ellsms_bulk_items
           (job_id, mobile, content, unit_price_millicredits, price_cost_credits,
            price_operator_code, price_route_id, price_group_key)
         VALUES {$placeholders}"
    )->execute($flat);
}

/**
 * After an insert chunk finishes, check whether all insert chunks are done and
 * promote the import job to ready_for_confirmation.
 */
function import_job_check_insert_completion(int $jobId): void {
    $db = db();
    $pending = (int)$db->prepare(
        "SELECT COUNT(*) FROM ellsms_import_chunks
         WHERE import_job_id = ? AND phase = 'insert' AND status IN ('pending','processing')"
    )->execute([$jobId])->fetchColumn();

    if ($pending > 0) {
        return;
    }

    $failed = (int)$db->prepare(
        "SELECT COUNT(*) FROM ellsms_import_chunks
         WHERE import_job_id = ? AND phase = 'insert' AND status = 'failed'"
    )->execute([$jobId])->fetchColumn();

    if ($failed > 0) {
        $db->prepare("UPDATE ellsms_import_jobs SET status='failed', error_message='بخشی از درج ردیف‌ها ناموفق بود.', completed_at=NOW() WHERE id = ?")
           ->execute([$jobId]);
        wallet_release_reservation('import_job', (string)$jobId);
        usage_release_messages('import_job', (string)$jobId);
        return;
    }

    $queuedRows = (int)$db->prepare(
        "SELECT COALESCE(SUM(rows_valid),0) FROM ellsms_import_chunks
         WHERE import_job_id = ? AND phase = 'insert' AND status = 'completed'"
    )->execute([$jobId])->fetchColumn();
    $db->prepare(
        "UPDATE ellsms_import_jobs
         SET status='ready_for_confirmation', queued_rows = ?, analysis_completed_at = NOW()
         WHERE id = ?"
    )->execute([$queuedRows, $jobId]);

    Logger::info('import.ready_for_confirmation', ['import_job_id' => $jobId, 'queued_rows' => $queuedRows]);
}
