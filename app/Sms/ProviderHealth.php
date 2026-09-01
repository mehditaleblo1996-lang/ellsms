<?php
/**
 * ELLSMS — full provider health model (issue #16), upgraded in place from issue #10's minimal
 * binary healthy/outage seam. SAME table (`ellsms_provider_health_state`), SAME provider_key
 * vocabulary (`legacy_backend`, `gateway:<id>`) — never a second/parallel health system.
 *
 * States: UNKNOWN (no evidence yet) -> UP -> DEGRADED -> DOWN, with hysteresis in both directions:
 * a transition requires CONSECUTIVE evidence, never one data point, so a single slow or failed
 * request can never flip the state on its own (unless an operator explicitly lowers a threshold to
 * 1). Recovery is symmetric and gradual: DOWN can only reach DEGRADED first, never straight back to
 * UP, matching "recovery evidence" rather than "one lucky success."
 *
 * Inputs: passive (every real dispatch attempt already reports success/failure/timeout + latency
 * through provider_health_record_success()/_failure()/_timeout(), zero extra requests) and active
 * (cron/provider-health-check.php, a lightweight synthetic probe on its own configurable interval —
 * see that file). Both write through the exact same state machine below, tagged by
 * last_check_source so an admin can tell which produced the current state.
 *
 * CRITICAL invariant, unchanged from issue #10: this file NEVER changes which provider/route a
 * message uses. It only observes and reports. app/Sms/Pricing.php's routing precedence
 * (sender -> destination-operator -> default) has no dependency on anything in this file, and
 * tests/Integration/ProviderHealthRoutingIndependenceTest.php proves that a DOWN provider's routes
 * still resolve identically to a healthy one's.
 */

declare(strict_types=1);

const PROVIDER_HEALTH_UNKNOWN = 'unknown';
const PROVIDER_HEALTH_UP = 'up';
const PROVIDER_HEALTH_DEGRADED = 'degraded';
const PROVIDER_HEALTH_DOWN = 'down';

/** Consecutive failures/timeouts (combined) while UP before degrading. */
function provider_health_degraded_threshold(): int {
    return max(1, (int)(env('PROVIDER_HEALTH_DEGRADED_THRESHOLD', '3') ?? '3'));
}

/** Consecutive failures/timeouts while already DEGRADED before considering the provider DOWN. */
function provider_health_down_threshold(): int {
    return max(1, (int)(env('PROVIDER_HEALTH_DOWN_THRESHOLD', '5') ?? '5'));
}

/** Consecutive successes needed for UNKNOWN to become UP, or for DEGRADED to recover to UP. */
function provider_health_up_min_successes(): int {
    return max(1, (int)(env('PROVIDER_HEALTH_UP_MIN_SUCCESSES', '5') ?? '5'));
}

/** Consecutive successes needed for DOWN to recover -- deliberately smaller than the full UP
 *  threshold: DOWN -> DEGRADED is "it's responding again," not "it's fully healthy again." */
function provider_health_recovery_min_successes(): int {
    return max(1, (int)(env('PROVIDER_HEALTH_RECOVERY_MIN_SUCCESSES', '2') ?? '2'));
}

/** A successful request slower than this (ms) counts toward degrading even though it succeeded --
 *  "elevated latency," not just outright failure, per the required DEGRADED trigger. */
function provider_health_degraded_latency_ms(): float {
    return max(1.0, (float)(env('PROVIDER_HEALTH_DEGRADED_LATENCY_MS', '3000') ?? '3000'));
}

/** Minimum time between repeated outage alerts for the SAME provider. */
function provider_health_alert_cooldown_seconds(): int {
    return max(0, (int)(env('PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS', '900') ?? '900'));
}

/** Stable key for the single legacy REST backend path (dispatch_message_raw()'s non-gateway send). */
function provider_health_key_legacy_backend(): string {
    return 'legacy_backend';
}

/** Stable key for one configured SMS gateway (app/Sms/GatewayCache.php). */
function provider_health_key_for_gateway(int $gatewayId): string {
    return 'gateway:' . $gatewayId;
}

/** @return array<string,int|string|null> a fresh, never-seen-before provider's implicit state. */
function provider_health_default_state(): array {
    return [
        'status' => PROVIDER_HEALTH_UNKNOWN, 'consecutive_failures' => 0, 'consecutive_successes' => 0,
        'consecutive_timeouts' => 0, 'avg_latency_ms' => null, 'last_alert_at' => null,
    ];
}

function provider_health_state_row(string $providerKey): array {
    $row = db()->prepare('SELECT * FROM ellsms_provider_health_state WHERE provider_key = ?');
    $row->execute([$providerKey]);
    $state = $row->fetch();
    return $state === false ? provider_health_default_state() : $state;
}

/**
 * The hysteresis state machine, pure given a current state + one new outcome -- unit-testable
 * without a database (tests/Unit/ProviderHealthTransitionsTest.php), then applied for real by
 * provider_health_apply_outcome() below. $outcome is 'success' | 'failure' | 'timeout'.
 *
 * @param array{status:string,consecutive_failures:int,consecutive_successes:int,consecutive_timeouts:int,avg_latency_ms:?float} $state
 * @return array{status:string,consecutive_failures:int,consecutive_successes:int,consecutive_timeouts:int,avg_latency_ms:?float,transitioned:bool}
 */
function provider_health_next_state(array $state, string $outcome, ?float $latencyMs = null): array {
    $status = (string)($state['status'] ?? PROVIDER_HEALTH_UNKNOWN);
    $failures = (int)($state['consecutive_failures'] ?? 0);
    $successes = (int)($state['consecutive_successes'] ?? 0);
    $timeouts = (int)($state['consecutive_timeouts'] ?? 0);
    $avgLatency = $state['avg_latency_ms'] ?? null;
    $next = $status;

    if ($outcome === 'success') {
        $failures = 0;
        $timeouts = 0;
        $successes++;
        if ($latencyMs !== null) {
            // Exponential moving average -- O(1) per update, no per-request row storage, and
            // recovers to reflect current conditions within a handful of requests rather than
            // being dragged down forever by one historical spike.
            $avgLatency = $avgLatency === null ? $latencyMs : ($avgLatency * 0.8 + $latencyMs * 0.2);
        }

        if ($status === PROVIDER_HEALTH_UNKNOWN && $successes >= provider_health_up_min_successes()) {
            $next = PROVIDER_HEALTH_UP;
        } elseif ($status === PROVIDER_HEALTH_DOWN && $successes >= provider_health_recovery_min_successes()) {
            $next = PROVIDER_HEALTH_DEGRADED; // recovery evidence, not full health yet
        } elseif ($status === PROVIDER_HEALTH_DEGRADED && $successes >= provider_health_up_min_successes()
                  && ($avgLatency === null || $avgLatency < provider_health_degraded_latency_ms())) {
            $next = PROVIDER_HEALTH_UP;
        } elseif ($status === PROVIDER_HEALTH_UP && $avgLatency !== null && $avgLatency >= provider_health_degraded_latency_ms()) {
            // Elevated latency degrades even on an unbroken streak of successes -- "DEGRADED on
            // elevated latency," the required trigger that isn't just outright failure.
            $next = PROVIDER_HEALTH_DEGRADED;
            $successes = 0;
        }
    } else { // 'failure' or 'timeout'
        $successes = 0;
        $failures++;
        if ($outcome === 'timeout') {
            $timeouts++;
        }

        if (in_array($status, [PROVIDER_HEALTH_UNKNOWN, PROVIDER_HEALTH_UP], true) && $failures >= provider_health_degraded_threshold()) {
            $next = PROVIDER_HEALTH_DEGRADED;
        } elseif ($status === PROVIDER_HEALTH_DEGRADED && $failures >= provider_health_down_threshold()) {
            $next = PROVIDER_HEALTH_DOWN;
        }
        // DOWN stays DOWN -- already the worst state; failures still counted for observability.
    }

    return [
        'status' => $next, 'consecutive_failures' => $failures, 'consecutive_successes' => $successes,
        'consecutive_timeouts' => $timeouts, 'avg_latency_ms' => $avgLatency, 'transitioned' => $next !== $status,
    ];
}

/** Shared apply-and-persist path for every outcome kind -- passive callers below and the active
 *  checker (cron/provider-health-check.php) both funnel through this. */
function provider_health_apply_outcome(string $providerKey, string $outcome, ?float $latencyMs, ?string $reason, string $source): void {
    try {
        $db = db();
        $current = provider_health_state_row($providerKey);
        $result = provider_health_next_state($current, $outcome, $latencyMs);

        $timeField = $outcome === 'success' ? 'last_success_at' : ($outcome === 'timeout' ? 'last_timeout_at' : 'last_failure_at');
        $st = $db->prepare(
            "INSERT INTO ellsms_provider_health_state
                (provider_key, status, consecutive_failures, consecutive_successes, consecutive_timeouts, avg_latency_ms,
                 last_error, last_check_source, {$timeField}, last_transition_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(), ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), consecutive_failures = VALUES(consecutive_failures),
                consecutive_successes = VALUES(consecutive_successes), consecutive_timeouts = VALUES(consecutive_timeouts),
                avg_latency_ms = VALUES(avg_latency_ms), last_error = COALESCE(VALUES(last_error), last_error),
                last_check_source = VALUES(last_check_source), {$timeField} = NOW(),
                last_transition_at = IF(? = 1, NOW(), last_transition_at)"
        );
        $transitioned = $result['transitioned'] ? 1 : 0;
        $st->execute([
            $providerKey, $result['status'], $result['consecutive_failures'], $result['consecutive_successes'],
            $result['consecutive_timeouts'], $result['avg_latency_ms'], $reason !== null ? mb_substr($reason, 0, 500) : null,
            $source, $transitioned ? null : null, $transitioned,
        ]);

        if (!$result['transitioned']) {
            return;
        }

        Metrics::increment('provider_health.transition', 1, ['provider_key' => $providerKey, 'to' => $result['status'], 'source' => $source]);

        $wasWorseOrEqual = in_array((string)($current['status'] ?? PROVIDER_HEALTH_UNKNOWN), [PROVIDER_HEALTH_DEGRADED, PROVIDER_HEALTH_DOWN], true);
        if ($result['status'] === PROVIDER_HEALTH_DOWN) {
            $cooldownState = provider_health_state_row($providerKey);
            $cooldownPassed = $cooldownState['last_alert_at'] === null
                || (time() - strtotime((string)$cooldownState['last_alert_at'])) >= provider_health_alert_cooldown_seconds();
            if ($cooldownPassed) {
                $db->prepare('UPDATE ellsms_provider_health_state SET last_alert_at = NOW() WHERE provider_key = ?')->execute([$providerKey]);
                provider_health_alert($providerKey, 'down', ['consecutive_failures' => $result['consecutive_failures'], 'reason' => $reason]);
            }
        } elseif ($result['status'] === PROVIDER_HEALTH_UP && $wasWorseOrEqual) {
            provider_health_alert($providerKey, 'recovered', []);
        }
    } catch (Throwable $t) {
        // Health tracking must never be why a real send fails -- observability is best-effort.
        Logger::error('provider_health.apply_outcome_error', ['provider_key' => $providerKey, 'outcome' => $outcome, 'exception' => $t]);
    }
}

/** Records one failed dispatch attempt (passive by default; cron/provider-health-check.php's
 *  active probe passes 'active'). */
function provider_health_record_failure(string $providerKey, string $reason, string $source = 'passive'): void {
    provider_health_apply_outcome($providerKey, 'failure', null, $reason, $source);
}

/** Records one attempt that timed out specifically -- tracked separately from a generic failure so
 *  an admin can tell "provider is slow/unresponsive" from "provider is actively rejecting
 *  requests," even though both currently drive the same DEGRADED/DOWN ladder. */
function provider_health_record_timeout(string $providerKey, string $reason = 'timeout', string $source = 'passive'): void {
    provider_health_apply_outcome($providerKey, 'timeout', null, $reason, $source);
}

/** Records one successful attempt. $latencyMs, when known, feeds the DEGRADED-on-elevated-latency
 *  trigger. */
function provider_health_record_success(string $providerKey, ?float $latencyMs = null, string $source = 'passive'): void {
    provider_health_apply_outcome($providerKey, 'success', $latencyMs, null, $source);
}

/**
 * The alert itself: always a structured log line (so an admin sees it even with no alert channel
 * configured), plus Telegram when configured. Issue #15 is where a real multi-channel/severity/
 * escalation system belongs -- this stays the same minimal alert issue #10 built, now firing on
 * DOWN/recovered instead of outage/recovered.
 */
function provider_health_alert(string $providerKey, string $type, array $context): void {
    $event = $type === 'recovered' ? 'provider_health.recovered' : 'provider_health.down_detected';
    Logger::critical($event, array_merge(['provider_key' => $providerKey], $context));
    Metrics::increment('provider_health.alert', 1, ['provider_key' => $providerKey, 'type' => $type]);

    if (function_exists('telegram_configured') && telegram_configured()) {
        $text = $type === 'recovered'
            ? "✅ بازیابی ارائه‌دهنده پیامک: {$providerKey} دوباره در دسترس است."
            : "⚠️ قطعی ارائه‌دهنده پیامک: {$providerKey} — " . (int)($context['consecutive_failures'] ?? 0) . ' شکست پیاپی. پیام‌ها در صف باقی می‌مانند؛ سوییچ ارائه‌دهنده فقط دستی است.';
        try {
            telegram_send_message($text);
        } catch (Throwable $t) {
            Logger::error('provider_health.alert_delivery_failed', ['provider_key' => $providerKey, 'exception' => $t]);
        }
    }
}

/** Shared Persian label/CSS-color for one health status -- the one place every UI/CLI consumer
 *  (public/sms-gateways.php, public/queue-cancellation.php, cron/jobs-status.php) maps the four
 *  states to display text, so they can never drift out of sync with each other. */
function provider_health_status_label(string $status): array {
    return match ($status) {
        PROVIDER_HEALTH_UP => ['label' => 'سالم', 'color' => '#2e7d32'],
        PROVIDER_HEALTH_DEGRADED => ['label' => 'کاهش‌یافته', 'color' => '#e08e0b'],
        PROVIDER_HEALTH_DOWN => ['label' => 'قطعی', 'color' => '#c0392b'],
        default => ['label' => 'نامشخص', 'color' => '#757575'],
    };
}

/** Admin-visible snapshot of every provider this install has ever recorded a dispatch outcome for. */
function provider_health_snapshot(): array {
    return db()->query(
        "SELECT * FROM ellsms_provider_health_state
         ORDER BY FIELD(status,'down','degraded','unknown','up'), provider_key ASC"
    )->fetchAll();
}

/* ==========================================================================
   Active check (issue #16) -- cron/provider-health-check.php's run-loop calls into these; kept
   here (not in the cron file) so they're directly unit/integration-testable, matching this
   codebase's established split between a worker's library and its thin cron entrypoint.
   ========================================================================== */

function provider_health_check_interval_seconds(): int {
    return max(10, (int)(env('PROVIDER_HEALTH_CHECK_INTERVAL_SECONDS', '60') ?? '60'));
}

function provider_health_check_timeout_seconds(): float {
    return max(0.5, (float)(env('PROVIDER_HEALTH_CHECK_TIMEOUT_SECONDS', '3') ?? '3'));
}

/**
 * One TCP-connect probe against $host:$port, bounded by provider_health_check_timeout_seconds().
 * Deliberately minimal and safe: a TCP-level liveness check, never an authenticated API call --
 * avoids two real risks a "real" status-API probe would carry: (a) some providers charge per API
 * call or rate-limit aggressively, so a periodic synthetic business-logic request could itself
 * become a cost/abuse concern, and (b) not every gateway configures a status connector at all
 * (GatewayStatusPollTest's own established finding: "most gateways genuinely have no delivery API
 * — that is not an error"). A TCP-level check works for every gateway uniformly and costs the
 * provider nothing.
 */
function provider_health_active_probe(string $host, int $port): array {
    $startedAt = microtime(true);
    $conn = @fsockopen($host, $port, $errno, $errstr, provider_health_check_timeout_seconds());
    $elapsedMs = (microtime(true) - $startedAt) * 1000;
    if ($conn === false) {
        $timedOut = $elapsedMs >= (provider_health_check_timeout_seconds() * 1000 - 50);
        return ['ok' => false, 'timed_out' => $timedOut, 'elapsed_ms' => $elapsedMs, 'error' => $errstr ?: 'connection failed'];
    }
    fclose($conn);
    return ['ok' => true, 'timed_out' => false, 'elapsed_ms' => $elapsedMs, 'error' => null];
}

/**
 * One bounded pass over every active gateway. Bounded concurrency: strictly sequential, one
 * gateway at a time -- the simplest possible bound, and no shared resource (worker claim tables,
 * connection pools) this touches at all, so it can never contend with or overload the real send
 * workers.
 */
function provider_health_active_check_one_pass(): int {
    $checked = 0;
    $gatewayIds = db()->query("SELECT id FROM ellsms_sms_gateways WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($gatewayIds as $gatewayId) {
        $compiled = gateway_compiled((int)$gatewayId);
        $endpoint = $compiled['send']['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            continue;
        }
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? null;
        if ($host === null) {
            continue;
        }
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
        $providerKey = provider_health_key_for_gateway((int)$gatewayId);

        $probe = provider_health_active_probe($host, (int)$port);
        $checked++;
        if ($probe['ok']) {
            provider_health_record_success($providerKey, $probe['elapsed_ms'], 'active');
        } elseif ($probe['timed_out']) {
            provider_health_record_timeout($providerKey, 'active_check_timeout: ' . $probe['error'], 'active');
        } else {
            provider_health_record_failure($providerKey, 'active_check_failed: ' . $probe['error'], 'active');
        }
    }
    return $checked;
}
