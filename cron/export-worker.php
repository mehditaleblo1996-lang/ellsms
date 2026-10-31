<?php
/**
 * ELLSMS — report export worker (Phase 8).
 *
 * WHY THIS EXISTS. Generating a CSV of a filtered report used to happen inside the web request. At
 * a few million rows that holds a PHP process and a database cursor open for minutes while the
 * browser gives up, and the row count is not knowable before the query runs. This worker moves the
 * whole job off the request path.
 *
 * SEPARATE CONTAINER, for the same reason webhook-worker and status-worker are separate: a report
 * export is long, I/O-heavy and low-priority. It must never delay a scheduled send, an auto-reply,
 * or a delivery-status poll, and a wedged export must not take the send pipeline with it.
 *
 * BOUNDED MEMORY IS THE WHOLE POINT. Rows are read in keyset pages of EXPORT_CHUNK_ROWS and written
 * straight to the file handle; nothing accumulates. A 5,000,000-row export uses the same memory as
 * a 50-row one. There is deliberately no fetchAll() anywhere in this file.
 *
 * KEYSET, NEVER OFFSET. Each page continues from the last id written (`WHERE m.id < :cursor ORDER
 * BY m.id DESC`). OFFSET 4000000 makes the database count and discard four million rows to produce
 * the next page; the cursor makes every page an index seek instead. It is also what makes a crashed
 * export resumable: last_row_id is committed as it advances.
 *
 *   php cron/export-worker.php          # runs forever (the `export-worker` compose service)
 *   php cron/export-worker.php --once   # one pass, for cron-style or test invocation
 */
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);

const EXPORT_WORKER_MIN_INTERVAL = 5;
const EXPORT_WORKER_DEFAULT_INTERVAL = 10;

$configuredInterval = (int)(env('REPORT_EXPORT_WORKER_INTERVAL_SECONDS', (string)EXPORT_WORKER_DEFAULT_INTERVAL) ?? (string)EXPORT_WORKER_DEFAULT_INTERVAL);
$pollIntervalSeconds = max(EXPORT_WORKER_MIN_INTERVAL, $configuredInterval);

// Rows per keyset page. Large enough that a million-row export is not a million round trips, small
// enough that one page is a trivial amount of memory.
$chunkRows    = max(100, (int)(env('EXPORT_CHUNK_ROWS', '2000') ?? '2000'));
$leaseSeconds = max(60, (int)(env('REPORT_EXPORT_LEASE_SECONDS', '900') ?? '900'));

// A hard ceiling so a runaway filter cannot fill the disk. Reaching it is reported to the user as a
// truncated export rather than being silently ignored -- see the truncation notice below.
$maxRows = max(1000, (int)(env('REPORT_EXPORT_MAX_ROWS', '2000000') ?? '2000000'));

$shuttingDown   = false;
$shutdownReason = null;

$pcntlAvailable = function_exists('pcntl_async_signals')
    && function_exists('pcntl_signal')
    && defined('SIGTERM')
    && defined('SIGINT');

if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown, &$shutdownReason): void {
        $shuttingDown = true;
        $shutdownReason = 'signal_' . $signo;
        Logger::info('report_export.worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

$dir = report_export_dir();
if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
    Logger::critical('report_export.worker.storage_unavailable', ['dir' => $dir]);
    exit(1);
}

Logger::info('report_export.worker.started', [
    'worker_id'        => worker_id(),
    'once'             => $once,
    'pid'              => getmypid(),
    'interval_seconds' => $pollIntervalSeconds,
    'chunk_rows'       => $chunkRows,
    'signal_handling'  => $pcntlAvailable ? 'enabled' : 'unavailable',
]);

/**
 * Stream one export to disk: every message the report page shows for the stored filters, from its
 * three sources in turn — direct/API sends through the backend (outbound_message), direct/API and
 * scheduled sends straight to a gateway (ellsms_message_attempts), and bulk items — one row each,
 * the first column saying which.
 *
 * Writes to a temporary file and renames only on success, so a reader can never observe a partially
 * written CSV: the file either does not exist yet or is complete. rename() within one filesystem is
 * atomic.
 */
function export_run_one(array $job, int $chunkRows, int $leaseSeconds, int $maxRows): void {
    $exportId = (int)$job['id'];
    $filters  = json_decode((string)$job['filters_json'], true);
    if (!is_array($filters)) {
        throw new RuntimeException('Export job has an unreadable filter set.');
    }

    $db = db();
    $sources = [
        'outbound' => ['label' => 'مستقیم', 'sql' => report_export_filter_sql($filters),
                       'count' => 'backend_outbound_export_count', 'page' => 'backend_outbound_export_page'],
        'gateway'  => ['label' => 'مستقیم (درگاه)', 'sql' => report_export_gateway_filter_sql($filters),
                       'count' => 'report_export_gateway_count', 'page' => 'report_export_gateway_page'],
        'bulk'     => ['label' => 'انبوه', 'sql' => report_export_bulk_filter_sql($filters),
                       'count' => 'report_export_bulk_count', 'page' => 'report_export_bulk_page'],
    ];
    $totalRows = 0;
    foreach ($sources as $source) {
        $totalRows += ($source['count'])(...$source['sql']);
    }
    $db->prepare('UPDATE ellsms_report_exports SET total_rows = ? WHERE id = ?')->execute([$totalRows, $exportId]);

    $storageKey = bin2hex(random_bytes(16)) . '.csv';
    $finalPath  = report_export_path($storageKey);
    $tmpPath    = $finalPath . '.part';

    $out = @fopen($tmpPath, 'w');
    if ($out === false) {
        throw new RuntimeException('Cannot open the export file for writing.');
    }

    try {
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 Persian correctly
        fputcsv($out, ['نوع ارسال','شناسه','کاربر','خط ارسال','گیرنده','متن پیام','تعداد پارت','وضعیت','شناسه‌ی مرجع',
                       'مرجع اپراتور','وضعیت خام درگاه','تعداد تلاش استعلام','کد خطا','زمان ارسال','آخرین استعلام','زمان تحویل']);

        $written   = 0;
        $truncated = false;

        foreach ($sources as $kind => $source) {
            [$whereSql, $params] = $source['sql'];
            $cursor = 0; // 0 = start from the newest row
            while (true) {
                $rows = ($source['page'])($whereSql, $params, $chunkRows, $cursor);
                if ($rows === []) {
                    break;
                }
                $usernames = $kind === 'outbound' ? [] : backend_usernames_by_ids(array_column($rows, 'user_id'));
                $delivery  = $kind === 'outbound' ? export_delivery_for_page($db, $rows) : [];

                foreach ($rows as $r) {
                    // Aimed-bulk rows: the customer's file carries the masked number, as the panel does.
                    if ($kind === 'bulk' && !empty($r['recipients_masked']) && empty($filters['is_admin'])) {
                        $r['destination'] = msisdn_mask((string)$r['destination']);
                    }
                    // The platform's shared lines are «خط اشتراکی» in a customer's file, as in the panel.
                    if (array_key_exists('originator', $r)) {
                        $r['originator'] = shared_line_mask((string)$r['originator'], !empty($filters['is_admin']));
                    }
                    fputcsv($out, array_merge([$source['label']], export_csv_row($kind, $r, $usernames, $delivery)));
                    $cursor = (int)$r['id'];
                    $written++;

                    if ($written >= $maxRows) {
                        $truncated = true;
                        break 3;
                    }
                }

                report_export_touch($exportId, $written, $cursor, $leaseSeconds);

                if (count($rows) < $chunkRows) {
                    break;   // short page = last page
                }
            }
        }

        if ($truncated) {
            // Say so IN THE FILE. A silently truncated export is worse than a failed one: it looks
            // complete and quietly under-reports.
            fputcsv($out, ['— خروجی به سقف ' . $maxRows . ' ردیف رسید و ناقص است؛ بازه را کوچک‌تر کنید —']);
            Logger::warning('report_export.truncated', ['export_id' => $exportId, 'max_rows' => $maxRows]);
        }

        fflush($out);
        $bytes = (int)ftell($out);
        fclose($out);
        $out = null;

        if (!@rename($tmpPath, $finalPath)) {
            throw new RuntimeException('Cannot finalize the export file.');
        }

        report_export_complete($exportId, $storageKey, $written, $bytes);
    } catch (Throwable $t) {
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath);   // never leave a half-written file holding customer data
        }
        throw $t;
    }
}

/** A UTC timestamp as Tehran local time, the time the panel shows. */
function export_local_time(?string $utc): string {
    if ($utc === null || trim($utc) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $utc;
    }
}

/**
 * One CSV row (after the source column) for a row of $kind. A provider message id is prefixed
 * with a tab so a spreadsheet keeps it as text instead of rounding a long number.
 */
function export_csv_row(string $kind, array $r, array $usernames, array $delivery): array {
    $providerId = static fn($id): string => $id !== null && $id !== '' ? "\t" . (string)$id : '';
    if ($kind === 'outbound') {
        $d = $delivery[(string)$r['destination']] ?? null;
        return [
            $r['id'],
            $r['username'],
            $r['originator'],
            $r['destination'],
            $r['content'],
            sms_parts((string)$r['content']),
            $d !== null && !empty($d['delivery_status']) ? (string)$d['delivery_status'] : (string)$r['status'],
            $r['reference_id'] ?? '',
            $d !== null ? $providerId($d['provider_message_id']) : '',
            $d !== null ? (string)($d['provider_status'] ?? '') : '',
            $d !== null ? (string)(int)$d['delivery_attempts'] : '',
            $r['error_code'],
            export_local_time($r['sent_at']),
            $d !== null ? export_local_time($d['delivery_checked_at'] ?? null) : '',
            export_local_time($d !== null && !empty($d['delivered_at']) ? (string)$d['delivered_at'] : ($r['delivered_at'] ?? null)),
        ];
    }
    $content = (string)($r['content'] ?? '');
    $status = !empty($r['delivery_status']) ? (string)$r['delivery_status'] : (string)($r['status'] ?? 'sent');
    return [
        $r['id'],
        $usernames[(int)$r['user_id']] ?? ('#' . $r['user_id']),
        $r['originator'],
        $r['destination'],
        $content,
        $content !== '' ? sms_parts($content) : '',
        $status,
        $kind === 'bulk' ? 'bulk_job:' . $r['job_id'] : $r['reference_type'] . ':' . $r['reference_id'],
        $providerId($r['provider_message_id']),
        (string)($r['provider_status'] ?? ''),
        (string)(int)($r['delivery_attempts'] ?? 0),
        $kind === 'bulk' ? (string)($r['error'] ?? '') : (string)($r['error_code'] ?? ''),
        export_local_time($kind === 'bulk' ? $r['sent_at'] : $r['attempted_at']),
        export_local_time($r['delivery_checked_at'] ?? null),
        export_local_time($r['delivered_at'] ?? null),
    ];
}

/**
 * Delivery lifecycle for one page of rows, in a single query.
 *
 * Correlated by destination, matching how public/reports.php enriches its own page. Degrades to an
 * empty map on error so an export still succeeds without the lifecycle columns rather than failing
 * outright.
 */
function export_delivery_for_page(PDO $db, array $rows): array {
    $dests = array_values(array_unique(array_map(
        static fn(array $r): string => (string)$r['destination'],
        $rows
    )));
    if ($dests === []) {
        return [];
    }

    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($dests), '?'));
        $q  = $db->prepare(
            "SELECT destination, provider_message_id, provider_status, delivery_status,
                    delivery_attempts, delivery_checked_at, delivered_at
             FROM ellsms_message_attempts
             WHERE destination IN ({$ph}) AND status = 'accepted'
             ORDER BY id DESC"
        );
        $q->execute($dests);
        while ($a = $q->fetch()) {
            $out[(string)$a['destination']] ??= $a;   // first row wins = most recent attempt
        }
    } catch (Throwable $t) {
        Logger::warning('report_export.delivery_lookup_failed', ['exception' => $t]);
        return [];
    }
    return $out;
}

$cleanupEvery = 60;   // passes between retention sweeps
$passNo = 0;

do {
    // A long-lived worker must survive MySQL dropping its connection (wait_timeout, restart).
    db_ensure_connected();
    if (maintenance_mode_active()) {
        if ($once) break;
        sleep($pollIntervalSeconds);
        continue;
    }

    $didWork = false;
    try {
        $job = report_export_claim($leaseSeconds);
        if ($job !== null) {
            $didWork = true;
            $startedAt = microtime(true);
            try {
                Metrics::time(
                    'report_export.run',
                    fn() => export_run_one($job, $chunkRows, $leaseSeconds, $maxRows)
                );
                Logger::info('report_export.completed', [
                    'export_id'  => (int)$job['id'],
                    'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                ]);
            } catch (Throwable $t) {
                // FAILURE ISOLATION. One malformed filter set, one unreadable row or one full disk
                // costs that export and nothing more. A worker that dies on the first bad job would
                // stall every queued export behind it.
                report_export_fail((int)$job['id'], $t);
                Metrics::increment('report_export.failed', 1);
            }
        }
    } catch (Throwable $t) {
        Logger::critical('report_export.worker.pass_failed', ['exception' => $t]);
        Metrics::increment('report_export.worker.pass.failed', 1);
    }

    if (++$passNo % $cleanupEvery === 0 || $once) {
        try {
            report_export_cleanup();
        } catch (Throwable $t) {
            Logger::error('report_export.cleanup_failed', ['exception' => $t]);
        }
    }

    if ($once || $shuttingDown) break;

    // Only idle when there was nothing to do; a queue with work in it drains at full speed.
    if (!$didWork) {
        sleep($pollIntervalSeconds);
    }
} while (!$once && !$shuttingDown);

Logger::info('report_export.worker.stopped', [
    'worker_id' => worker_id(),
    'pid'       => getmypid(),
    'reason'    => $once ? 'once_mode_complete' : ($shutdownReason ?? 'loop_exited'),
]);
exit(0);
