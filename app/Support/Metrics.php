<?php
/**
 * ELLSMS — lightweight metrics abstraction (Phase 9).
 *
 * Deliberately NOT a monitoring platform: no Prometheus/StatsD/Redis client, no background
 * exporter, no persistent aggregation store. Every call is a structured JSON log line through the
 * existing Logger (event name prefixed `metric.`), so it inherits Logger's redaction, level
 * filtering, and log-file rotation for free instead of duplicating any of that. Point-in-time
 * counts/ages (queue depth, stale leases, oldest pending age) are computed on demand by
 * cron/jobs-status.php and cron/performance-snapshot.php directly from the source tables — the
 * same pattern jobs-status.php already used before this phase — rather than tracked as running
 * counters here, since the source tables are already the ground truth and a duplicate counter
 * store would just be another thing that can drift from it.
 *
 * Replaceable: every call site uses this class's static API, never Logger directly for a metric —
 * swapping the backend later (e.g. to a real metrics daemon) means changing this one file's
 * internals, not any call site.
 */

declare(strict_types=1);

final class Metrics
{
    private function __construct() {} // static-only

    /**
     * Sampling rate for metric emission, [0.0, 1.0] — 1.0 (the default) emits every call.
     * METRICS_LOG_SAMPLE_RATE exists for STEP 23 (bounding log volume under heavy load); leave at
     * 1.0 unless a specific deployment's log volume is actually a problem — sampling trades
     * observability for volume, so it should be an explicit operator choice, not a silent default.
     */
    private static function sampleRate(): float {
        $rate = (float)(function_exists('env') ? (env('METRICS_LOG_SAMPLE_RATE', '1') ?? '1') : '1');
        return max(0.0, min(1.0, $rate));
    }

    private static function shouldSample(): bool {
        $rate = self::sampleRate();
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }
        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /** Counter increment — event occurred $by times (default 1). $tags: short key=>scalar context, no message content/secrets (Logger's own redaction still applies as a second layer). */
    public static function increment(string $name, int $by = 1, array $tags = []): void {
        if (!self::shouldSample()) {
            return;
        }
        Logger::info('metric.' . $name, array_merge(['metric_type' => 'counter', 'value' => $by], $tags));
    }

    /** Duration in milliseconds for one timed operation. */
    public static function timing(string $name, float $milliseconds, array $tags = []): void {
        if (!self::shouldSample()) {
            return;
        }
        Logger::info('metric.' . $name, array_merge(['metric_type' => 'timing', 'value_ms' => round($milliseconds, 2)], $tags));
    }

    /** Point-in-time value (a queue depth, an age in seconds, a count) — not cumulative. */
    public static function gauge(string $name, float $value, array $tags = []): void {
        if (!self::shouldSample()) {
            return;
        }
        Logger::info('metric.' . $name, array_merge(['metric_type' => 'gauge', 'value' => $value], $tags));
    }

    /**
     * Times $work and records it under $name, tagging 'ok' => bool and, on exception, the
     * exception class — then rethrows. Convenience wrapper for the common "time this block"
     * shape used around claim/dispatch/finalize calls.
     */
    public static function time(string $name, callable $work, array $tags = []) {
        $start = microtime(true);
        try {
            $result = $work();
            self::timing($name, (microtime(true) - $start) * 1000, array_merge($tags, ['ok' => true]));
            return $result;
        } catch (\Throwable $t) {
            self::timing($name, (microtime(true) - $start) * 1000, array_merge($tags, ['ok' => false, 'exception' => get_class($t)]));
            throw $t;
        }
    }
}
