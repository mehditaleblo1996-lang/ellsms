<?php
/**
 * ELLSMS — per-message-class latency SLOs (issue #5).
 *
 * Pure, side-effect-free target table + classifier. Kept separate from app/MessageClass.php (the
 * ordering vocabulary) so the SLO numbers themselves — which come from a specific agreed decision,
 * not from the queue architecture — can be read, tested, and changed independently.
 *
 * IMPORTANT — these are engineering SLOs, not provider delivery guarantees (acceptance criterion,
 * issue #5). They describe how fast ELLSMS itself hands a message to the backend/provider (or, for
 * Scheduled, how promptly the worker picks up a due occurrence) — not whether or when the carrier
 * network actually delivers it to a handset, which this application does not control and cannot
 * promise. A breach recorded here means "ELLSMS was slower than intended," never "the SMS was
 * late" or "the SMS failed."
 *
 * "OTP validity 3m" from the issue is a DIFFERENT thing from an SLO and deliberately not
 * represented here: it's the OTP code's own expiry window, a security control on the code itself,
 * not a delivery-speed target. See the module docblock for the discrepancy this surfaced against
 * the existing SMS-2FA implementation (5-minute expiry, README "SMS-based 2FA") — flagged, not
 * silently changed, since shortening a security control's window needs an explicit decision, not
 * a side effect of a queue-latency change.
 */

declare(strict_types=1);

/** Seconds. Every class here is one of MessageClass.php's six; ADVERTISING intentionally shares
 *  BULK_CAMPAIGN's per-item figures — the issue's "Bulk" target doesn't distinguish them and this
 *  file's job is per-CLASS latency, not the separate campaign-completion-time check below. */
function slo_latency_targets(): array {
    return [
        MESSAGE_CLASS_OTP => ['normal_seconds' => 5, 'max_seconds' => 120],
        MESSAGE_CLASS_TRANSACTIONAL => ['normal_seconds' => 10, 'max_seconds' => 60],
        MESSAGE_CLASS_NOTIFICATION => ['normal_seconds' => 30, 'max_seconds' => 300],
        MESSAGE_CLASS_SCHEDULED => ['normal_seconds' => 60, 'max_seconds' => 600],
        MESSAGE_CLASS_BULK_CAMPAIGN => ['normal_seconds' => null, 'max_seconds' => null],
        MESSAGE_CLASS_ADVERTISING => ['normal_seconds' => null, 'max_seconds' => null],
    ];
}

/** Bulk/Advertising have no per-item latency target (issue #5 gives a whole-campaign target
 *  instead — see slo_bulk_campaign_target_seconds()/slo_classify_bulk_job_rate() below). */
function slo_has_per_item_latency_target(string $class): bool {
    $t = slo_latency_targets()[normalize_message_class($class)] ?? null;
    return $t !== null && $t['normal_seconds'] !== null;
}

function slo_target_for_class(string $class): ?array {
    return slo_latency_targets()[normalize_message_class($class)] ?? null;
}

/**
 * Classifies one measured latency against its class's target.
 * @return string|null null = within the normal target; 'normal_exceeded' = past normal but not
 *                      max (a warning-level SLI breach); 'max_exceeded' = past the hard ceiling
 *                      (a critical-level SLI breach). Returns null for a class with no per-item
 *                      target (Bulk Campaign/Advertising) — never fabricates a threshold.
 */
function slo_classify_latency(string $class, float $latencySeconds): ?string {
    $target = slo_target_for_class($class);
    if ($target === null || $target['normal_seconds'] === null) {
        return null;
    }
    if ($latencySeconds > $target['max_seconds']) {
        return 'max_exceeded';
    }
    if ($latencySeconds > $target['normal_seconds']) {
        return 'normal_exceeded';
    }
    return null;
}

/** The one absolute figure the issue gives for Bulk: a 5,000,000-message campaign completing in
 *  <=30 minutes (shared with issue #4's capacity targets — the same number, two issues). */
function slo_bulk_campaign_reference_size(): int {
    return 5_000_000;
}

function slo_bulk_campaign_target_seconds(): int {
    return 1800; // 30 minutes
}

/**
 * The issue gives exactly one (size, time) data point, not a per-item rate — this derives the
 * implied minimum throughput rate from it (5,000,000 / 1800s ≈ 2,778 items/s) so a job of ANY size
 * can be checked against the same underlying commitment, not just a literal 5-million-row job.
 * Documented derivation, not a value restated verbatim from the issue.
 */
function slo_bulk_campaign_min_rate_per_second(): float {
    return slo_bulk_campaign_reference_size() / slo_bulk_campaign_target_seconds();
}

/**
 * Classifies a completed (or in-flight) bulk job's observed throughput against the derived rate.
 * Deliberately requires a minimum sample size ($totalRows) before judging — a 5-row job finishing
 * in 2 seconds is not evidence of anything about 5-million-row capacity in either direction, and
 * checking it would produce noise, not signal.
 *
 * @return string|null null = meets the target (or sample too small to judge), 'rate_below_target'
 *                      = observed throughput is below the derived minimum.
 */
function slo_classify_bulk_job_rate(int $totalRows, float $elapsedSeconds, int $minSampleRows = 1000): ?string {
    if ($totalRows < $minSampleRows || $elapsedSeconds <= 0.0) {
        return null;
    }
    $observedRate = $totalRows / $elapsedSeconds;
    return $observedRate < slo_bulk_campaign_min_rate_per_second() ? 'rate_below_target' : null;
}

/**
 * Single call site every per-item latency measurement funnels through (dispatch_message()'s API
 * round-trip, run_due_schedules()'s queueing delay) — always emits the raw timing metric so it's
 * visible either way, and ADDITIONALLY emits a `sli.latency_breach` counter only when a target is
 * actually breached. That counter, not the timing histogram, is the thing an alert rule watches
 * (issue #5's "alerts can detect threshold breaches") — a rate-of(sli.latency_breach) > 0 query is
 * a far simpler alert condition than a quantile threshold on the raw timing series, and works
 * today with this codebase's log-line metrics (see app/Support/Metrics.php's own docblock on why
 * there's no Prometheus/StatsD client yet — issue #14) exactly as well as it will once a real
 * metrics platform exists.
 */
function sli_record_dispatch_latency(string $metricName, string $class, float $latencySeconds, array $extraTags = []): void {
    $tags = array_merge(['message_class' => $class], $extraTags);
    Metrics::timing($metricName, $latencySeconds * 1000, $tags);
    $breach = slo_classify_latency($class, $latencySeconds);
    if ($breach !== null) {
        Metrics::increment('sli.latency_breach', 1, array_merge($tags, ['severity' => $breach]));
    }
}
