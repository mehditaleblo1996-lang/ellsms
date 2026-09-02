<?php
/**
 * ELLSMS — bounded public-API request metrics (issue #14 final audit).
 *
 * One cheap indexed UPSERT per request into a small, fixed-size table (see
 * db/migrations/2026_09_02_api_request_metrics.sql) -- route/method/status_bucket are all bounded
 * enums, so this table never grows past a few hundred rows regardless of traffic volume. Read back
 * by app/Observability/PrometheusExporter.php at scrape time; this file only ever writes.
 */

declare(strict_types=1);

function api_request_metric_record(string $route, string $method, string $statusBucket, float $durationMs): void {
    try {
        db()->prepare(
            'INSERT INTO ellsms_api_request_metrics (route, method, status_bucket, request_count, total_duration_ms)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1, total_duration_ms = total_duration_ms + VALUES(total_duration_ms)'
        )->execute([$route, $method, $statusBucket, (int)round($durationMs)]);
    } catch (Throwable $t) {
        // Metrics must never be why a real API response fails -- best-effort, same posture as
        // app/Sms/ProviderHealth.php's own outcome recording.
        Logger::error('api.request_metric.record_failed', ['exception' => $t]);
    }
}
