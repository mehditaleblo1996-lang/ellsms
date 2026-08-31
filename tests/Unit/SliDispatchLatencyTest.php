<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sli_record_dispatch_latency() (app/Slo.php) — the single call site dispatch_message() and
 * run_due_schedules() both funnel through to emit per-class latency SLIs (issue #5). Verifies the
 * actual end-to-end wiring (Metrics -> Logger -> log file), not just the pure classifier math
 * already covered by SloTest — same real-log-file pattern as LoggerTest.php, the one other test in
 * this suite with a real side effect.
 */
final class SliDispatchLatencyTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/ellsms-' . date('Y-m-d') . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    /**
     * @return list<array<string,mixed>> every metric.* JSON line logged since the marker position,
     *         with 'event' plus its 'context' fields flattened into one array for easy assertions.
     */
    private function loggedMetricsSince(int $offset): array
    {
        $contents = (string)file_get_contents($this->logPath, false, null, $offset);
        $out = [];
        foreach (array_filter(explode("\n", $contents)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && str_starts_with((string)($decoded['event'] ?? ''), 'metric.')) {
                $out[] = array_merge(['event' => $decoded['event']], $decoded['context'] ?? []);
            }
        }
        return $out;
    }

    private function offsetNow(): int
    {
        return is_file($this->logPath) ? (int)filesize($this->logPath) : 0;
    }

    public function testWithinTargetLatencyEmitsOnlyTheTimingNotABreach(): void
    {
        $start = $this->offsetNow();
        \sli_record_dispatch_latency('dispatch.accept_to_provider_seconds', MESSAGE_CLASS_OTP, 1.0);
        $metrics = $this->loggedMetricsSince($start);

        $timing = array_values(array_filter($metrics, static fn($m) => $m['event'] === 'metric.dispatch.accept_to_provider_seconds'));
        $this->assertCount(1, $timing);
        $this->assertSame('timing', $timing[0]['metric_type']);
        $this->assertSame(MESSAGE_CLASS_OTP, $timing[0]['message_class']);
        $this->assertEqualsWithDelta(1000.0, $timing[0]['value_ms'], 0.5);

        $breach = array_filter($metrics, static fn($m) => $m['event'] === 'metric.sli.latency_breach');
        $this->assertCount(0, $breach, 'a within-target latency must not emit a breach counter');
    }

    public function testNormalExceededLatencyEmitsABreachCounterWithSeverity(): void
    {
        $start = $this->offsetNow();
        // OTP: normal=5s, max=120s -- 30s is past normal, well under max.
        \sli_record_dispatch_latency('dispatch.accept_to_provider_seconds', MESSAGE_CLASS_OTP, 30.0);
        $metrics = $this->loggedMetricsSince($start);

        $breach = array_values(array_filter($metrics, static fn($m) => $m['event'] === 'metric.sli.latency_breach'));
        $this->assertCount(1, $breach);
        $this->assertSame('counter', $breach[0]['metric_type']);
        $this->assertSame(MESSAGE_CLASS_OTP, $breach[0]['message_class']);
        $this->assertSame('normal_exceeded', $breach[0]['severity']);
    }

    public function testMaxExceededLatencyIsTaggedWithCriticalSeverity(): void
    {
        $start = $this->offsetNow();
        \sli_record_dispatch_latency('schedule.dispatch_delay_seconds', MESSAGE_CLASS_SCHEDULED, 700.0);
        $metrics = $this->loggedMetricsSince($start);

        $breach = array_values(array_filter($metrics, static fn($m) => $m['event'] === 'metric.sli.latency_breach'));
        $this->assertCount(1, $breach);
        $this->assertSame('max_exceeded', $breach[0]['severity']);
        $this->assertSame(MESSAGE_CLASS_SCHEDULED, $breach[0]['message_class']);
    }

    public function testExtraTagsAreCarriedOntoBothTheTimingAndTheBreachCounter(): void
    {
        $start = $this->offsetNow();
        \sli_record_dispatch_latency('bulk.job.completion_seconds', MESSAGE_CLASS_BULK_CAMPAIGN, 999999.0, ['total_rows' => 42]);
        $metrics = $this->loggedMetricsSince($start);

        $timing = array_values(array_filter($metrics, static fn($m) => $m['event'] === 'metric.bulk.job.completion_seconds'));
        $this->assertCount(1, $timing);
        $this->assertSame(42, $timing[0]['total_rows']);
        // Bulk Campaign has no per-item latency target, so even a huge latency never breaches.
        $this->assertCount(0, array_filter($metrics, static fn($m) => $m['event'] === 'metric.sli.latency_breach'));
    }
}
