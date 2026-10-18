<?php
/**
 * ELLSMS — Platform Admin -> live queue monitor (public/queue-monitor.php).
 *
 * One read-only snapshot of every background queue (bulk campaigns, interactive direct sends,
 * schedules, file imports, customer webhooks, MO/DLR forwarding, auto-reply) plus a per-queue
 * "empty it" action. Same ground rules as app/Observability/QueueSnapshot.php: no message content,
 * no destination numbers, only counters — and every query is an indexed lookup over the ACTIVE rows
 * only, never a GROUP BY over a whole table's history (queue_table_status() does that and is fine
 * for a one-off `make jobs-status`, not for a page polled every few seconds).
 *
 * Why a polled page stays cheap:
 *  - bulk items are counted through the small set of active jobs, on the (job_id, status) index, so
 *    millions of sent rows from past campaigns are never touched;
 *  - the snapshot is cached in one file shared by every admin tab of this app container, and only
 *    one request recomputes it at a time (the others keep answering the previous one);
 *  - the cache lifetime grows with the cost of computing it (QUEUE_MONITOR_TTL_FACTOR), so even a
 *    queue of millions of rows costs the database a bounded fraction of one connection.
 *
 * Emptying a queue DELETES its waiting rows (the admin asked for removal, not the logical
 * cancellation of app/BulkCancellation.php), with the same safety rules as that file:
 *  - only rows nobody has claimed are deleted — a row a worker is sending right now ('processing')
 *    is left to finish, exactly as BulkCancellation.php leaves it;
 *  - a bulk campaign is first switched to 'cancelled' and its wallet reservation released (the
 *    first part of bulk_cancel_campaign()), so no worker claims anything more from it and the
 *    customer's reserved credit comes back;
 *  - rows go in bounded chunks within a time budget per request; the page calls again until the
 *    queue reports done, so a huge queue never holds one giant lock or outlives an HTTP timeout;
 *  - every pass is audit-logged (queue_monitor.purge).
 * The auto-reply log is monitor-only: its row IS the "already replied" marker for an inbound
 * message (UNIQUE inbound_message_id), so deleting it would make the worker reply a second time.
 */

declare(strict_types=1);

/** Refresh interval of the page, seconds. */
const QUEUE_MONITOR_POLL_SECONDS = 3;
/** The cache never lives shorter than this... */
const QUEUE_MONITOR_MIN_TTL = 2;
/** ...nor longer than this, however slow the snapshot is. */
const QUEUE_MONITOR_MAX_TTL = 30;
/** Cache lifetime = this × how long the snapshot took: the DB spends at most ~1/factor of its time on it. */
const QUEUE_MONITOR_TTL_FACTOR = 5;
/** Above this many waiting bulk items, the retry-wait split (which reads rows, not just the index) is skipped. */
const QUEUE_MONITOR_BULK_DETAIL_LIMIT = 50000;
/** Campaigns listed in the snapshot (largest backlog first). */
const QUEUE_MONITOR_TOP_JOBS = 15;
/** Seconds one purge request keeps deleting before it answers (the page then calls again). */
const QUEUE_MONITOR_PURGE_BUDGET_SECONDS = 8;
/** Bulk jobs cancelled by a purge whose waiting items are still being deleted (JSON list of ids). */
const QUEUE_MONITOR_DRAIN_SETTING = 'queue_monitor_drain_job_ids';

/**
 * The queues, in display order. 'purge' says whether the page offers to empty it.
 *
 * @return array<string,array{label:string,description:string,purge:bool}>
 */
function queue_monitor_definitions(): array {
    return [
        'bulk'      => ['label' => 'ارسال دسته‌ای و کمپین‌ها', 'description' => 'نظیربه‌نظیر، هوشمند، تدریجی، هدفمند و واردسازی تأییدشده', 'purge' => true],
        'direct'    => ['label' => 'ارسال فوری',               'description' => 'درخواست‌های ارسال تکی و چندتایی از پنل', 'purge' => true],
        'schedule'  => ['label' => 'ارسال زمان‌بندی‌شده',        'description' => 'ارسال‌های یک‌باره و دوره‌ای فعال', 'purge' => true],
        'import'    => ['label' => 'واردسازی فایل',             'description' => 'فایل‌های در حال تحلیل یا منتظر تأیید کاربر', 'purge' => true],
        'webhook'   => ['label' => 'وب‌هوک مشتریان',            'description' => 'رویدادهایی که باید به آدرس مشتری ارسال شوند', 'purge' => true],
        'forward'   => ['label' => 'فوروارد پیامک به URL',      'description' => 'پیامک دریافتی و گزارش تحویل برای فوروارد', 'purge' => true],
        'autoreply' => ['label' => 'منشی پیامک',               'description' => 'پاسخ خودکار در حال ارسال یا منتظر تلاش دوباره', 'purge' => false],
    ];
}

/** One queue's counters. ready = can be claimed now; delayed = waiting for a retry/run time. */
function queue_monitor_row(int $ready = 0, ?int $delayed = 0, int $processing = 0, int $stale = 0, ?int $oldestAge = null): array {
    return [
        'ready'      => $ready,
        'delayed'    => $delayed,
        'processing' => $processing,
        'stale'      => $stale,
        'oldest_age' => $oldestAge !== null ? max(0, $oldestAge) : null,
    ];
}

/** @return array{rows:array,jobs:list<array>,workers:int} */
function queue_monitor_bulk(PDO $db): array {
    $jobs = $db->query(
        "SELECT id, user_id, type, title, status, total_rows, sent_rows, failed_rows, created_at, throttle_count
         FROM ellsms_bulk_jobs WHERE status IN ('pending','processing') ORDER BY id"
    )->fetchAll();
    if ($jobs === []) {
        return ['rows' => queue_monitor_row(), 'jobs' => [], 'workers' => 0];
    }

    // Index-only on (job_id, status): the per-job counts never read an item row.
    $counts = [];
    foreach ($db->query(
        "SELECT i.job_id, i.status, COUNT(*) AS c
         FROM ellsms_bulk_jobs j
         JOIN ellsms_bulk_items i ON i.job_id = j.id AND i.status IN ('pending','processing')
         WHERE j.status IN ('pending','processing')
         GROUP BY i.job_id, i.status"
    ) as $r) {
        $counts[(int)$r['job_id']][(string)$r['status']] = (int)$r['c'];
    }

    // In-flight rows are few (batch size × workers), so reading them is cheap.
    $flight = $db->query(
        "SELECT SUM(i.lease_expires_at IS NOT NULL AND i.lease_expires_at < NOW()) AS stale,
                COUNT(DISTINCT i.claimed_by) AS workers
         FROM ellsms_bulk_jobs j
         JOIN ellsms_bulk_items i ON i.job_id = j.id AND i.status = 'processing'
         WHERE j.status IN ('pending','processing')"
    )->fetch() ?: [];

    $pending = 0;
    $processing = 0;
    $oldestJobId = null;
    foreach ($jobs as &$job) {
        $id = (int)$job['id'];
        $job['pending_count'] = $counts[$id]['pending'] ?? 0;
        $job['processing_count'] = $counts[$id]['processing'] ?? 0;
        $pending += $job['pending_count'];
        $processing += $job['processing_count'];
        if ($oldestJobId === null && $job['pending_count'] > 0) {
            $oldestJobId = $id;
        }
    }
    unset($job);

    // Oldest waiting item: the first pending row of the oldest job that still has one — one
    // (job_id, status, id) index seek instead of a MIN() over every pending row.
    $oldestAge = null;
    if ($oldestJobId !== null) {
        $st = $db->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM ellsms_bulk_items
             WHERE job_id = ? AND status = 'pending' ORDER BY id LIMIT 1"
        );
        $st->execute([$oldestJobId]);
        $age = $st->fetchColumn();
        $oldestAge = $age !== false && $age !== null ? (int)$age : null;
    }

    // Retry-wait has no index; split it out only while the backlog is small enough to read.
    $delayed = null;
    if ($pending <= QUEUE_MONITOR_BULK_DETAIL_LIMIT) {
        $delayed = (int)$db->query(
            "SELECT COUNT(*) FROM ellsms_bulk_jobs j
             JOIN ellsms_bulk_items i ON i.job_id = j.id AND i.status = 'pending'
             WHERE j.status IN ('pending','processing') AND i.next_attempt_at IS NOT NULL AND i.next_attempt_at > NOW()"
        )->fetchColumn();
    }

    usort($jobs, static fn(array $a, array $b): int => ($b['pending_count'] + $b['processing_count']) <=> ($a['pending_count'] + $a['processing_count']));

    return [
        'rows'    => queue_monitor_row($pending - (int)$delayed, $delayed, $processing, (int)($flight['stale'] ?? 0), $oldestAge),
        'jobs'    => array_slice($jobs, 0, QUEUE_MONITOR_TOP_JOBS),
        'workers' => (int)($flight['workers'] ?? 0),
    ];
}

function queue_monitor_direct(PDO $db): array {
    // idx_direct_send_queue_claim (status, available_at, id) answers the queued side alone.
    // available_at is written in UTC (direct_send_queue_enqueue()), claimed_at in PHP local time
    // (direct_send_queue_claim_one()'s lease cutoff) — each is compared the way the worker does.
    $q = $db->query(
        "SELECT SUM(available_at <= UTC_TIMESTAMP()) AS ready, SUM(available_at > UTC_TIMESTAMP()) AS later_count,
                TIMESTAMPDIFF(SECOND, MIN(CASE WHEN available_at <= UTC_TIMESTAMP() THEN available_at END), UTC_TIMESTAMP()) AS oldest
         FROM ellsms_direct_send_queue WHERE status = 'queued'"
    )->fetch() ?: [];
    $st = $db->prepare(
        "SELECT COUNT(*) AS processing, SUM(claimed_at IS NOT NULL AND claimed_at < ?) AS stale
         FROM ellsms_direct_send_queue WHERE status = 'processing'"
    );
    $st->execute([date('Y-m-d H:i:s', time() - job_lease_seconds())]);
    $p = $st->fetch() ?: [];
    return queue_monitor_row((int)($q['ready'] ?? 0), (int)($q['later_count'] ?? 0), (int)($p['processing'] ?? 0), (int)($p['stale'] ?? 0), isset($q['oldest']) ? (int)$q['oldest'] : null);
}

function queue_monitor_schedule(PDO $db): array {
    // KEY (status, run_at); active schedules are a small set, reading next_attempt_at is fine.
    $q = $db->query(
        "SELECT SUM(IFNULL(next_attempt_at, run_at) <= NOW()) AS ready, SUM(IFNULL(next_attempt_at, run_at) > NOW()) AS later_count,
                TIMESTAMPDIFF(SECOND, MIN(CASE WHEN IFNULL(next_attempt_at, run_at) <= NOW() THEN IFNULL(next_attempt_at, run_at) END), NOW()) AS oldest
         FROM ellsms_schedule WHERE status = 'active'"
    )->fetch() ?: [];
    $p = $db->query(
        "SELECT COUNT(*) AS processing, SUM(lease_expires_at IS NOT NULL AND lease_expires_at < NOW()) AS stale
         FROM ellsms_schedule WHERE status = 'processing'"
    )->fetch() ?: [];
    return queue_monitor_row((int)($q['ready'] ?? 0), (int)($q['later_count'] ?? 0), (int)($p['processing'] ?? 0), (int)($p['stale'] ?? 0), isset($q['oldest']) ? (int)$q['oldest'] : null);
}

function queue_monitor_import(PDO $db): array {
    // Waiting = import jobs not yet sending; their chunks on idx_claim (status, lease_expires_at).
    // A confirmed import becomes a bulk job and is counted under 'bulk'.
    $jobs = $db->query(
        "SELECT SUM(status IN ('uploaded','analyzing')) AS analysing, SUM(status = 'ready_for_confirmation') AS awaiting,
                TIMESTAMPDIFF(SECOND, MIN(CASE WHEN status IN ('uploaded','analyzing') THEN created_at END), NOW()) AS oldest
         FROM ellsms_import_jobs WHERE status IN ('uploaded','analyzing','ready_for_confirmation')"
    )->fetch() ?: [];
    $chunks = $db->query(
        "SELECT SUM(status = 'processing') AS processing,
                SUM(status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()) AS stale
         FROM ellsms_import_chunks WHERE status IN ('pending','processing')"
    )->fetch() ?: [];
    return queue_monitor_row((int)($jobs['analysing'] ?? 0), (int)($jobs['awaiting'] ?? 0), (int)($chunks['processing'] ?? 0), (int)($chunks['stale'] ?? 0), isset($jobs['oldest']) ? (int)$jobs['oldest'] : null);
}

/** Webhook deliveries and the MO/DLR forward queue share one shape: (status, next_attempt_at) + lease. */
function queue_monitor_retry_table(PDO $db, string $table): array {
    $q = $db->query(
        "SELECT SUM(next_attempt_at IS NULL OR next_attempt_at <= NOW()) AS ready, SUM(next_attempt_at > NOW()) AS later_count
         FROM {$table} WHERE status = 'pending'"
    )->fetch() ?: [];
    // created_at is not in the index; only the first ready row (in id order) is read for its age.
    $oldest = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM {$table}
         WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id LIMIT 1"
    )->fetchColumn();
    $p = $db->query(
        "SELECT COUNT(*) AS processing, SUM(lease_expires_at IS NOT NULL AND lease_expires_at < NOW()) AS stale
         FROM {$table} WHERE status = 'processing'"
    )->fetch() ?: [];
    return queue_monitor_row((int)($q['ready'] ?? 0), (int)($q['later_count'] ?? 0), (int)($p['processing'] ?? 0), (int)($p['stale'] ?? 0), $oldest !== false && $oldest !== null ? (int)$oldest : null);
}

function queue_monitor_autoreply(PDO $db): array {
    // No index on status: the created_at index bounds the read to the last two days, far longer
    // than the full retry schedule (JOB_MAX_ATTEMPTS × JOB_RETRY_MAX_SECONDS). Older stuck rows are
    // `make jobs-recover`'s business.
    $p = $db->query(
        "SELECT SUM(lease_expires_at IS NULL OR lease_expires_at <= NOW()) AS stale_or_ready,
                SUM(claimed_by IS NOT NULL AND lease_expires_at > NOW()) AS processing,
                SUM(claimed_by IS NULL AND lease_expires_at > NOW()) AS later_count
         FROM ellsms_autoreply_log WHERE created_at >= NOW() - INTERVAL 2 DAY AND status = 'processing'"
    )->fetch() ?: [];
    return queue_monitor_row(0, (int)($p['later_count'] ?? 0), (int)($p['processing'] ?? 0), (int)($p['stale_or_ready'] ?? 0));
}

/** Computes the snapshot from the database (no cache). */
function queue_monitor_compute(): array {
    $db = db();
    $started = microtime(true);
    $queues = [];
    $errors = [];
    $bulk = ['jobs' => [], 'workers' => 0];

    $collectors = [
        'bulk'      => static function () use ($db, &$bulk): array { $bulk = queue_monitor_bulk($db); return $bulk['rows']; },
        'direct'    => static fn(): array => queue_monitor_direct($db),
        'schedule'  => static fn(): array => queue_monitor_schedule($db),
        'import'    => static fn(): array => queue_monitor_import($db),
        'webhook'   => static fn(): array => queue_monitor_retry_table($db, 'ellsms_webhook_deliveries'),
        'forward'   => static fn(): array => queue_monitor_retry_table($db, 'ellsms_number_forward_queue'),
        'autoreply' => static fn(): array => queue_monitor_autoreply($db),
    ];
    // One queue's table missing (a migration not applied yet) must not blank the whole page.
    foreach ($collectors as $key => $collect) {
        try {
            $queues[$key] = $collect();
        } catch (Throwable $e) {
            $queues[$key] = null;
            $errors[$key] = true;
            Logger::warning('queue_monitor.collect_failed', ['queue' => $key, 'exception' => $e]);
        }
    }

    $providers = [];
    try {
        foreach (provider_health_snapshot() as $p) {
            $providers[] = ['provider_key' => (string)$p['provider_key'], 'status' => (string)$p['status'], 'consecutive_failures' => (int)$p['consecutive_failures']];
        }
    } catch (Throwable $e) {
        Logger::warning('queue_monitor.provider_health_failed', ['exception' => $e]);
    }

    $draining = 0;
    try {
        $draining = count(queue_monitor_drain_jobs());
    } catch (Throwable $e) {
        Logger::warning('queue_monitor.drain_list_failed', ['exception' => $e]);
    }

    $durationMs = (int)round((microtime(true) - $started) * 1000);
    return [
        'generated_at' => time(),
        'duration_ms'  => $durationMs,
        'queues'       => $queues,
        'errors'       => $errors,
        'jobs'         => $bulk['jobs'],
        'workers'      => $bulk['workers'],
        'providers'    => $providers,
        'draining'     => $draining,
    ];
}

/** "۳ دقیقه", "۲ ساعت و ۵ دقیقه", … for an age in seconds; '—' when there is none. */
function queue_monitor_age_label(?int $seconds): string {
    if ($seconds === null) return '—';
    if ($seconds < 60) return to_persian_digits((string)$seconds) . ' ثانیه';
    if ($seconds < 3600) return to_persian_digits((string)intdiv($seconds, 60)) . ' دقیقه';
    if ($seconds < 86400) {
        $minutes = intdiv($seconds % 3600, 60);
        return to_persian_digits((string)intdiv($seconds, 3600)) . ' ساعت' . ($minutes > 0 ? ' و ' . to_persian_digits((string)$minutes) . ' دقیقه' : '');
    }
    return to_persian_digits((string)intdiv($seconds, 86400)) . ' روز';
}

function queue_monitor_ttl(int $durationMs): int {
    return (int)min(QUEUE_MONITOR_MAX_TTL, max(QUEUE_MONITOR_MIN_TTL, ceil($durationMs * QUEUE_MONITOR_TTL_FACTOR / 1000)));
}

function queue_monitor_cache_path(): string {
    return rtrim(sys_get_temp_dir(), '/') . '/ellsms-queue-monitor-' . substr(hash('sha256', APP_ROOT . '|' . (string)env('BACKEND_DB_NAME', '')), 0, 12) . '.json';
}

function queue_monitor_cache_read(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['generated_at'], $data['queues']) ? $data : null;
}

/**
 * The snapshot every admin tab shares: served from the cache while it is fresh; when it is stale,
 * exactly one request recomputes it (non-blocking file lock) and the others keep answering the
 * previous snapshot instead of piling the same queries onto the database.
 */
function queue_monitor_snapshot(bool $fresh = false): array {
    $path = queue_monitor_cache_path();
    $cached = queue_monitor_cache_read($path);
    $isFresh = static fn(?array $c): bool => $c !== null && (int)$c['generated_at'] + queue_monitor_ttl((int)($c['duration_ms'] ?? 0)) > time();
    if (!$fresh && $isFresh($cached)) {
        return $cached + ['cached' => true];
    }

    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return queue_monitor_compute() + ['cached' => false];
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            if ($cached !== null) {
                return $cached + ['cached' => true];
            }
            flock($lock, LOCK_EX); // first ever request: wait for the one computing it
            $cached = queue_monitor_cache_read($path);
            if ($cached !== null) {
                return $cached + ['cached' => true];
            }
        }
        $snapshot = queue_monitor_compute();
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $path);
        }
        return $snapshot + ['cached' => false];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Bulk jobs a purge cancelled whose waiting items are not all deleted yet. @return list<int> */
function queue_monitor_drain_jobs(): array {
    $st = db()->prepare('SELECT svalue FROM ellsms_settings WHERE skey = ?');
    $st->execute([QUEUE_MONITOR_DRAIN_SETTING]);
    $ids = json_decode((string)($st->fetchColumn() ?: '[]'), true);
    return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
}

/** @param list<int> $ids */
function queue_monitor_set_drain_jobs(array $ids): void {
    set_setting(QUEUE_MONITOR_DRAIN_SETTING, json_encode(array_values(array_unique($ids))) ?: '[]');
}

/** Repeats a chunked DELETE until it matches nothing or the deadline passes. @return array{0:int,1:bool} deleted, done */
function queue_monitor_delete_chunks(PDOStatement $delete, array $params, float $deadline): array {
    $deleted = 0;
    do {
        $delete->execute($params);
        $affected = $delete->rowCount();
        $deleted += $affected;
    } while ($affected > 0 && microtime(true) < $deadline);
    return [$deleted, $affected === 0];
}

/**
 * Stops every active bulk job ('cancelled' + wallet reservation released — the first half of
 * bulk_cancel_campaign()) and records it for draining. @return int jobs stopped
 */
function queue_monitor_stop_bulk_jobs(): int {
    $db = db();
    $ids = array_map('intval', $db->query("SELECT id FROM ellsms_bulk_jobs WHERE status IN ('pending','processing')")->fetchAll(PDO::FETCH_COLUMN));
    $stopped = [];
    $flip = $db->prepare("UPDATE ellsms_bulk_jobs SET status = 'cancelled' WHERE id = ? AND status IN ('pending','processing')");
    foreach ($ids as $id) {
        $flip->execute([$id]);
        if ($flip->rowCount() === 1) {
            wallet_release_reservation('bulk_job', (string)$id);
            $stopped[] = $id;
        }
    }
    if ($stopped !== []) {
        queue_monitor_set_drain_jobs(array_merge(queue_monitor_drain_jobs(), $stopped));
    }
    return count($stopped);
}

/** Deletes the waiting items of the jobs queue_monitor_stop_bulk_jobs() stopped. @return array{0:int,1:bool} */
function queue_monitor_drain_bulk_items(float $deadline): array {
    $chunk = bulk_cancellation_chunk_size();
    $delete = db()->prepare("DELETE FROM ellsms_bulk_items WHERE job_id = ? AND status = 'pending' ORDER BY id LIMIT {$chunk}");
    $remaining = queue_monitor_drain_jobs();
    $deleted = 0;
    while ($remaining !== [] && microtime(true) < $deadline) {
        [$n, $done] = queue_monitor_delete_chunks($delete, [$remaining[0]], $deadline);
        $deleted += $n;
        if (!$done) break;
        array_shift($remaining);
    }
    queue_monitor_set_drain_jobs($remaining);
    return [$deleted, $remaining === []];
}

/**
 * Empties queue $queue: deletes every row still waiting in it. Rows a worker holds right now are
 * left alone. Runs for at most QUEUE_MONITOR_PURGE_BUDGET_SECONDS; call again with $continue while
 * 'done' is false. For bulk/import a continuation only keeps deleting the rows of the jobs the first
 * call stopped — a campaign created since then is not stopped by it.
 *
 * @return array{ok:bool,queue:string,deleted:int,jobs_stopped:int,done:bool,error?:string}
 */
function queue_monitor_purge(string $queue, array $actor, bool $continue = false): array {
    $definition = queue_monitor_definitions()[$queue] ?? null;
    if (($actor['role'] ?? null) !== 'admin') {
        return ['ok' => false, 'queue' => $queue, 'deleted' => 0, 'jobs_stopped' => 0, 'done' => true, 'error' => 'forbidden'];
    }
    if ($definition === null || !$definition['purge']) {
        return ['ok' => false, 'queue' => $queue, 'deleted' => 0, 'jobs_stopped' => 0, 'done' => true, 'error' => 'not_purgeable'];
    }

    $db = db();
    $deadline = microtime(true) + QUEUE_MONITOR_PURGE_BUDGET_SECONDS;
    $chunk = bulk_cancellation_chunk_size();
    $jobsStopped = 0;

    switch ($queue) {
        case 'bulk':
            $jobsStopped = $continue ? 0 : queue_monitor_stop_bulk_jobs();
            [$deleted, $done] = queue_monitor_drain_bulk_items($deadline);
            break;

        case 'direct':
            [$deleted, $done] = queue_monitor_delete_chunks(
                $db->prepare("DELETE FROM ellsms_direct_send_queue WHERE status = 'queued' ORDER BY id LIMIT {$chunk}"), [], $deadline);
            break;

        case 'schedule':
            [$deleted, $done] = queue_monitor_delete_chunks(
                $db->prepare("DELETE FROM ellsms_schedule WHERE status = 'active' ORDER BY id LIMIT {$chunk}"), [], $deadline);
            break;

        case 'import':
            // import_cancel_job() is the one cancel path an import has: it releases the import's
            // reservations, deletes its stored file and cancels its staged bulk job. Its chunks are
            // then deleted, and the staged job's rows are drained like a stopped campaign.
            $ids = $continue ? [] : array_map('intval', $db->query("SELECT id FROM ellsms_import_jobs WHERE status IN ('uploaded','analyzing','ready_for_confirmation') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
            $deleted = 0;
            $stagedJobs = [];
            $staged = $db->prepare("SELECT id FROM ellsms_bulk_jobs WHERE source_import_job_id = ? AND status = 'staged'");
            $chunks = $db->prepare("DELETE FROM ellsms_import_chunks WHERE import_job_id = ? AND status = 'cancelled'");
            foreach ($ids as $id) {
                $staged->execute([$id]);
                $stagedIds = array_map('intval', $staged->fetchAll(PDO::FETCH_COLUMN));
                if (!import_cancel_job($id, $actor)) continue;
                $jobsStopped++;
                $chunks->execute([$id]);
                $stagedJobs = array_merge($stagedJobs, $stagedIds);
            }
            if ($stagedJobs !== []) {
                queue_monitor_set_drain_jobs(array_merge(queue_monitor_drain_jobs(), $stagedJobs));
            }
            [$deleted, $done] = queue_monitor_drain_bulk_items($deadline);
            break;

        case 'webhook':
            [$deleted, $done] = queue_monitor_delete_chunks(
                $db->prepare("DELETE FROM ellsms_webhook_deliveries WHERE status = 'pending' ORDER BY id LIMIT {$chunk}"), [], $deadline);
            break;

        case 'forward':
            [$deleted, $done] = queue_monitor_delete_chunks(
                $db->prepare("DELETE FROM ellsms_number_forward_queue WHERE status = 'pending' ORDER BY id LIMIT {$chunk}"), [], $deadline);
            break;

        default:
            return ['ok' => false, 'queue' => $queue, 'deleted' => 0, 'jobs_stopped' => 0, 'done' => true, 'error' => 'not_purgeable'];
    }

    audit((int)($actor['id'] ?? 0), 'queue_monitor.purge', json_encode([
        'queue' => $queue, 'continue' => $continue, 'deleted' => $deleted, 'jobs_stopped' => $jobsStopped, 'done' => $done,
    ], JSON_UNESCAPED_UNICODE) ?: '');
    Logger::warning('queue_monitor.purge', ['queue' => $queue, 'continue' => $continue, 'deleted' => $deleted, 'jobs_stopped' => $jobsStopped, 'done' => $done, 'actor_id' => (int)($actor['id'] ?? 0)]);
    @unlink(queue_monitor_cache_path()); // the next poll shows the emptied queue at once

    return ['ok' => true, 'queue' => $queue, 'deleted' => $deleted, 'jobs_stopped' => $jobsStopped, 'done' => $done];
}
