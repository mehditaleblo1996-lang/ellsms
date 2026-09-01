<?php
/**
 * ELLSMS — Prometheus-compatible metrics export (issue #14).
 *
 * Deliberately reuses the SAME source-of-truth queries the rest of this codebase already trusts
 * (cron/jobs-status.php's queue snapshot, app/Sms/ProviderHealth.php's provider_health_snapshot(),
 * issue #3/#12's own aggregate tables) rather than building a second counter/gauge store that could
 * drift from them — this file only ever formats what those already compute, at scrape time, from
 * the database (the same "DB is the ground truth" philosophy app/Support/Metrics.php's own docblock
 * already states for the structured-log metrics this complements, not replaces).
 *
 * CRITICAL cardinality rule (issue #14's own acceptance criterion): every label value emitted here
 * MUST come from a small, fixed, code-defined enum — message class, queue name, job status, provider
 * key, HTTP method/status bucket. NEVER a message id, phone number, organization/tenant id, or
 * request id: those are unbounded (grow forever / grow per tenant) and would blow up Prometheus's
 * own storage (a new time series per distinct label combination, forever). See
 * docs/observability-cardinality.md for the enforced list and the reasoning.
 *
 * Counters here (ellsms_bulk_messages_total, ellsms_send_dimension_total) are computed as SUM()/
 * COUNT() over an ever-growing log/aggregate table, which is monotonic in the common case (Prometheus
 * counters only need to never decrease absent a genuine reset) -- see this file's own docblock on
 * bulkMessageCounters() for the one honest exception (bulk-archive deletion, issue #13).
 */

declare(strict_types=1);

final class PrometheusExporter
{
    private function __construct() {} // static-only

    /** Every message class this codebase knows about (app/MessageClass.php) -- the one bounded enum every message_class label is drawn from. */
    private const MESSAGE_CLASSES = [
        MESSAGE_CLASS_OTP,
        MESSAGE_CLASS_TRANSACTIONAL,
        MESSAGE_CLASS_NOTIFICATION,
        MESSAGE_CLASS_SCHEDULED,
        MESSAGE_CLASS_BULK_CAMPAIGN,
        MESSAGE_CLASS_ADVERTISING,
    ];

    /** Renders the full Prometheus text exposition (format version 0.0.4). */
    public static function render(PDO $db): string {
        $lines = [];
        self::appendBuildInfo($lines);
        self::appendDatabaseUp($lines, $db);
        self::appendQueueMetrics($lines, $db);
        self::appendProviderHealthMetrics($lines, $db);
        self::appendBulkJobMetrics($lines, $db);
        self::appendSendDimensionMetrics($lines, $db);
        self::appendAlertMetrics($lines, $db);
        return implode("\n", $lines) . "\n";
    }

    /** One line of an exposition family: HELP + TYPE header (once per metric name) + the sample itself. */
    private static function sample(array &$lines, string $name, string $type, string $help, float $value, array $labels = [], array &$emittedHeaders = []): void {
        if (!isset($emittedHeaders[$name])) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} {$type}";
            $emittedHeaders[$name] = true;
        }
        $lines[] = $name . self::formatLabels($labels) . ' ' . self::formatValue($value);
    }

    private static function formatValue(float $value): string {
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        // Integral values render without a trailing ".0" -- cosmetic only, Prometheus parses either.
        return (floor($value) === $value && abs($value) < 1e15) ? (string)(int)$value : (string)$value;
    }

    private static function formatLabels(array $labels): string {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . self::escapeLabelValue((string)$value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function escapeLabelValue(string $value): string {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /**
     * Bounded-cardinality guard: maps an arbitrary DB-sourced string to one of $allowed, or the
     * literal "other" if it doesn't match -- the actual enforcement mechanism behind this file's own
     * cardinality rule. A label value NEVER reaches the exposition text without passing through this
     * (or being a literal, code-defined constant already known to be bounded, e.g. self::MESSAGE_CLASSES
     * iterated directly rather than read from a row).
     */
    public static function boundedLabel(string $value, array $allowed): string {
        return in_array($value, $allowed, true) ? $value : 'other';
    }

    private static function appendBuildInfo(array &$lines): void {
        $headers = [];
        self::sample($lines, 'ellsms_build_info', 'gauge', 'Static build/version info; value is always 1, the version is the label.', 1, [
            'version' => (string)app_version(),
            'environment' => self::boundedLabel(app_env(), ['production', 'staging', 'local', 'testing']),
        ], $headers);
    }

    private static function appendDatabaseUp(array &$lines, PDO $db): void {
        $headers = [];
        $up = 0;
        try {
            $db->query('SELECT 1');
            $up = 1;
        } catch (Throwable $t) {
            $up = 0;
        }
        self::sample($lines, 'ellsms_db_up', 'gauge', 'Whether the shared backend database was reachable at scrape time (1) or not (0).', (float)$up, [], $headers);
    }

    /**
     * Queue depth/oldest-age/active-workers -- reuses cron/jobs-status.php's own queue snapshot
     * helpers (app/Observability/QueueSnapshot.php) so this never drifts from what `jobs-status`
     * and the admin-visible operational tooling already report.
     */
    private static function appendQueueMetrics(array &$lines, PDO $db): void {
        $depthHeaders = [];
        $ageHeaders = [];
        $statusEnum = ['pending', 'processing', 'done', 'cancelled', 'active', 'sent', 'failed'];

        $queues = [
            'bulk_items' => queue_table_status($db, 'ellsms_bulk_items', 'IFNULL(next_attempt_at, created_at)', 'pending', 'next_attempt_at', 'lease_expires_at', 'processing'),
            'schedules' => queue_table_status($db, 'ellsms_schedule', 'IFNULL(next_attempt_at, run_at)', 'active', 'next_attempt_at', 'lease_expires_at', 'processing'),
        ];

        foreach ($queues as $queueName => $snapshot) {
            foreach ($snapshot['by_status'] as $row) {
                self::sample(
                    $lines,
                    'ellsms_queue_items',
                    'gauge',
                    'Number of queue rows currently in each status, per queue.',
                    (float)$row['total'],
                    ['queue' => $queueName, 'status' => self::boundedLabel((string)$row['status'], $statusEnum)],
                    $depthHeaders
                );
            }
            if ($snapshot['oldest_pending_age_seconds'] !== null) {
                self::sample(
                    $lines,
                    'ellsms_queue_oldest_pending_age_seconds',
                    'gauge',
                    'Age in seconds of the longest-waiting claimable row in each queue.',
                    (float)$snapshot['oldest_pending_age_seconds'],
                    ['queue' => $queueName],
                    $ageHeaders
                );
            }
        }

        // Issue #3's own per-class bulk depth -- already computed by bulk_claim_unthrottled_items_by_class()
        // at claim time and logged via Metrics::gauge(); re-derived here directly from the same
        // eligibility predicate (read-only, no claim/lock) so the exporter never depends on a worker
        // tick having just run to have a fresh number.
        $classDepthHeaders = [];
        $classAgeHeaders = [];
        $rows = $db->query(
            "SELECT bj.message_class, COUNT(*) AS depth,
                    TIMESTAMPDIFF(SECOND, MIN(i.created_at), NOW()) AS oldest_age_seconds
             FROM ellsms_bulk_items i
             JOIN ellsms_bulk_jobs bj ON bj.id = i.job_id
             WHERE bj.status = 'processing' AND bj.throttle_count IS NULL
               AND i.status = 'pending' AND (i.next_attempt_at IS NULL OR i.next_attempt_at <= NOW())
             GROUP BY bj.message_class"
        )->fetchAll();
        $byClass = [];
        foreach ($rows as $row) {
            $byClass[normalize_bulk_message_class((string)$row['message_class'])] = $row;
        }
        foreach ([MESSAGE_CLASS_BULK_CAMPAIGN, MESSAGE_CLASS_ADVERTISING] as $class) {
            $row = $byClass[$class] ?? null;
            self::sample($lines, 'ellsms_queue_bulk_depth', 'gauge', 'Claimable (pending, due) bulk queue depth per message class.', (float)($row['depth'] ?? 0), ['message_class' => $class], $classDepthHeaders);
            self::sample($lines, 'ellsms_queue_bulk_oldest_age_seconds', 'gauge', 'Age in seconds of the oldest claimable bulk item per message class.', (float)($row['oldest_age_seconds'] ?? 0), ['message_class' => $class], $classAgeHeaders);
        }

        $headers = [];
        self::sample($lines, 'ellsms_queue_active_workers', 'gauge', 'Distinct worker ids currently holding a live claim lease (max across queue tables; see active_worker_count() for the exact semantics).', (float)active_worker_count($db), [], $headers);
    }

    /** Provider health (issue #16) -- same provider_health_snapshot() the admin UI and jobs-status use. */
    private static function appendProviderHealthMetrics(array &$lines, PDO $db): void {
        $statusHeaders = [];
        $failuresHeaders = [];
        $timeoutsHeaders = [];
        $latencyHeaders = [];
        $statuses = [PROVIDER_HEALTH_UNKNOWN, PROVIDER_HEALTH_UP, PROVIDER_HEALTH_DEGRADED, PROVIDER_HEALTH_DOWN];

        foreach (provider_health_snapshot() as $p) {
            // provider_key is bounded by construction (app/Sms/ProviderHealth.php): "legacy_backend"
            // or "gateway:<id>" for one of this tenant-less admin's own small, finite set of
            // configured SMS gateways -- never a per-tenant or per-message value.
            $providerKey = (string)$p['provider_key'];
            $currentStatus = self::boundedLabel((string)$p['status'], $statuses);
            foreach ($statuses as $status) {
                self::sample(
                    $lines,
                    'ellsms_provider_health_status',
                    'gauge',
                    'Provider health state as a 1/0 indicator per (provider, status) pair -- exactly one status is 1 per provider at a time.',
                    $status === $currentStatus ? 1.0 : 0.0,
                    ['provider_key' => $providerKey, 'status' => $status],
                    $statusHeaders
                );
            }
            self::sample($lines, 'ellsms_provider_health_consecutive_failures', 'gauge', 'Consecutive dispatch failures currently recorded for this provider.', (float)$p['consecutive_failures'], ['provider_key' => $providerKey], $failuresHeaders);
            self::sample($lines, 'ellsms_provider_health_consecutive_timeouts', 'gauge', 'Consecutive dispatch timeouts currently recorded for this provider.', (float)($p['consecutive_timeouts'] ?? 0), ['provider_key' => $providerKey], $timeoutsHeaders);
            if ($p['avg_latency_ms'] !== null) {
                self::sample($lines, 'ellsms_provider_health_avg_latency_ms', 'gauge', 'Exponential moving average dispatch latency in milliseconds for this provider.', (float)$p['avg_latency_ms'], ['provider_key' => $providerKey], $latencyHeaders);
            }
        }
    }

    /**
     * Bulk job/message counters -- SUM()/COUNT() over ellsms_bulk_jobs, which only grows during
     * normal operation. Honest caveat: issue #13's bulk-archive worker eventually DELETES completed
     * job rows out of this table (by design, to bound its size) -- if that ever runs, these specific
     * counters can visibly decrease, which Prometheus's own rate()/increase() functions handle as a
     * counter reset (a brief, correctly-computed dip in the rate for that one scrape interval), not
     * silent data corruption. Documented here and in docs/observability-cardinality.md rather than
     * hidden.
     */
    private static function appendBulkJobMetrics(array &$lines, PDO $db): void {
        $jobHeaders = [];
        $jobRows = $db->query('SELECT status, COUNT(*) AS total FROM ellsms_bulk_jobs GROUP BY status')->fetchAll();
        $jobStatuses = ['pending', 'processing', 'done', 'cancelled'];
        $byStatus = [];
        foreach ($jobRows as $row) {
            $byStatus[(string)$row['status']] = (int)$row['total'];
        }
        foreach ($jobStatuses as $status) {
            self::sample($lines, 'ellsms_bulk_jobs', 'gauge', 'Bulk campaign jobs currently in each status.', (float)($byStatus[$status] ?? 0), ['status' => $status], $jobHeaders);
        }

        $sentHeaders = [];
        $failedHeaders = [];
        $rows = $db->query("SELECT message_class, SUM(sent_rows) AS sent, SUM(failed_rows) AS failed FROM ellsms_bulk_jobs GROUP BY message_class")->fetchAll();
        $byClass = [];
        foreach ($rows as $row) {
            $byClass[normalize_bulk_message_class((string)$row['message_class'])] = $row;
        }
        foreach ([MESSAGE_CLASS_BULK_CAMPAIGN, MESSAGE_CLASS_ADVERTISING] as $class) {
            $row = $byClass[$class] ?? null;
            self::sample($lines, 'ellsms_bulk_messages_sent_total', 'counter', 'Cumulative bulk messages sent, per message class (see this file for the one honest caveat re: archival).', (float)($row['sent'] ?? 0), ['message_class' => $class], $sentHeaders);
            self::sample($lines, 'ellsms_bulk_messages_failed_total', 'counter', 'Cumulative bulk messages permanently failed, per message class (see this file for the one honest caveat re: archival).', (float)($row['failed'] ?? 0), ['message_class' => $class], $failedHeaders);
        }
    }

    /**
     * Issue #12's daily dimension summary -- deliberately aggregated ACROSS organization_id and
     * route_id/operator_id here (never emitted as labels): organization_id is an unbounded
     * per-tenant value that grows forever, exactly what this file's own cardinality rule forbids.
     * message_type and status are both small fixed enums, so those alone are safe as labels.
     */
    /**
     * Issue #15's alert/incident subsystem. All labels are bounded ENUM columns
     * (ellsms_alert_incidents.severity, ellsms_alert_dispatch_log.channel/outcome) -- never
     * alert_key itself (which, while code-defined per call site, is not enumerated here to avoid
     * having to keep this file's own allow-list in lockstep with every alert source that will ever
     * exist; the per-key detail is available in the admin UI's incident list, not the scrape).
     */
    private static function appendAlertMetrics(array &$lines, PDO $db): void {
        if (!$db->query("SHOW TABLES LIKE 'ellsms_alert_incidents'")->fetch()) {
            return;
        }
        $severities = ['warning', 'critical', 'emergency'];

        $activeHeaders = [];
        $activeByServerity = array_fill_keys($severities, 0);
        foreach ($db->query("SELECT severity, COUNT(*) AS c FROM ellsms_alert_incidents WHERE status IN ('open','acknowledged') GROUP BY severity")->fetchAll() as $row) {
            $activeByServerity[self::boundedLabel((string)$row['severity'], $severities)] = (int)$row['c'];
        }
        foreach ($severities as $severity) {
            self::sample($lines, 'ellsms_alert_incidents_active', 'gauge', 'Currently open or acknowledged incidents, per severity.', (float)$activeByServerity[$severity], ['severity' => $severity], $activeHeaders);
        }

        $firedHeaders = [];
        $firedByServerity = array_fill_keys($severities, 0);
        foreach ($db->query('SELECT severity, COUNT(*) AS c FROM ellsms_alert_incidents GROUP BY severity')->fetchAll() as $row) {
            $firedByServerity[self::boundedLabel((string)$row['severity'], $severities)] = (int)$row['c'];
        }
        foreach ($severities as $severity) {
            self::sample($lines, 'ellsms_alert_incidents_total', 'counter', 'Cumulative incidents ever raised, per severity.', (float)$firedByServerity[$severity], ['severity' => $severity], $firedHeaders);
        }

        $recoveredHeaders = [];
        $recoveredByServerity = array_fill_keys($severities, 0);
        foreach ($db->query("SELECT severity, COUNT(*) AS c FROM ellsms_alert_incidents WHERE status = 'resolved' GROUP BY severity")->fetchAll() as $row) {
            $recoveredByServerity[self::boundedLabel((string)$row['severity'], $severities)] = (int)$row['c'];
        }
        foreach ($severities as $severity) {
            self::sample($lines, 'ellsms_alert_incidents_recovered_total', 'counter', 'Cumulative incidents resolved (recovered), per severity.', (float)$recoveredByServerity[$severity], ['severity' => $severity], $recoveredHeaders);
        }

        $ackHeaders = [];
        $ackByServerity = array_fill_keys($severities, 0);
        foreach ($db->query("SELECT severity, COUNT(*) AS c FROM ellsms_alert_incidents WHERE acknowledged_at IS NOT NULL GROUP BY severity")->fetchAll() as $row) {
            $ackByServerity[self::boundedLabel((string)$row['severity'], $severities)] = (int)$row['c'];
        }
        foreach ($severities as $severity) {
            self::sample($lines, 'ellsms_alert_incidents_acknowledged_total', 'counter', 'Cumulative incidents ever acknowledged, per severity.', (float)$ackByServerity[$severity], ['severity' => $severity], $ackHeaders);
        }

        $dispatchHeaders = [];
        $channels = ['telegram', 'email'];
        $outcomes = ['sent', 'failed'];
        $dispatchCounts = [];
        foreach ($db->query('SELECT channel, outcome, COUNT(*) AS c FROM ellsms_alert_dispatch_log GROUP BY channel, outcome')->fetchAll() as $row) {
            $dispatchCounts[$row['channel']][$row['outcome']] = (int)$row['c'];
        }
        foreach ($channels as $channel) {
            foreach ($outcomes as $outcome) {
                self::sample($lines, 'ellsms_alert_dispatch_total', 'counter', 'Cumulative alert dispatch attempts, per channel and outcome.', (float)($dispatchCounts[$channel][$outcome] ?? 0), ['channel' => $channel, 'outcome' => $outcome], $dispatchHeaders);
            }
        }
    }

    private static function appendSendDimensionMetrics(array &$lines, PDO $db): void {
        $headers = [];
        $exists = $db->query("SHOW TABLES LIKE 'ellsms_report_daily_dimension_summary'")->fetch();
        if (!$exists) {
            return;
        }
        $rows = $db->query(
            "SELECT message_type, status, SUM(message_count) AS total
             FROM ellsms_report_daily_dimension_summary
             GROUP BY message_type, status"
        )->fetchAll();
        $statusEnum = ['sent', 'failed', 'cancelled'];
        foreach ($rows as $row) {
            self::sample(
                $lines,
                'ellsms_send_dimension_total',
                'counter',
                'Cumulative dimensioned sends (issue #12), aggregated across tenants -- never break this down by organization_id, it is unbounded.',
                (float)$row['total'],
                [
                    'message_type' => self::boundedLabel((string)$row['message_type'], self::MESSAGE_CLASSES),
                    'status' => self::boundedLabel((string)$row['status'], $statusEnum),
                ],
                $headers
            );
        }
    }
}
