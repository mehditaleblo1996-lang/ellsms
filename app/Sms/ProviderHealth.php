<?php
/**
 * ELLSMS — minimal provider-outage tracking and alerting (issue #10).
 *
 * Agreed behavior when the selected provider is unavailable/degraded: messages stay queued (already
 * true — see docs/job-queue-architecture.md's retry/backoff model, untouched by this file), the
 * admin is notified, and switching providers is manual only — this file NEVER changes which
 * provider/route a message uses; it only observes and reports.
 *
 * Deliberately NOT the full health model issue #16 will build (active+passive checks, UP/DEGRADED/
 * DOWN/UNKNOWN) or the full multi-channel/severity/ack/escalation alerting issue #15 will build —
 * this is the minimal real, persisted signal those two larger issues plug into later: a per-provider
 * consecutive-failure counter, one rate-limited alert on crossing the outage threshold, one on
 * recovery, and an admin-visible snapshot.
 */

declare(strict_types=1);

/** Consecutive failures before a provider is considered in outage and an alert fires. */
function provider_health_outage_threshold(): int {
    return max(1, (int)(env('PROVIDER_HEALTH_OUTAGE_THRESHOLD', '5') ?? '5'));
}

/** Minimum time between repeated outage alerts for the SAME provider, so a sustained outage pages
 *  once, not once per failed message. */
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

/**
 * Records one failed dispatch attempt against $providerKey. Increments the consecutive-failure
 * counter; once it reaches provider_health_outage_threshold(), the provider is marked 'outage' and
 * an alert fires — but only once per provider_health_alert_cooldown_seconds(), so a sustained
 * outage (every message failing) doesn't send one alert per message.
 */
function provider_health_record_failure(string $providerKey, string $reason): void {
    try {
        $db = db();
        $db->prepare(
            "INSERT INTO ellsms_provider_health_state (provider_key, status, consecutive_failures, last_failure_at, last_error)
             VALUES (?, 'healthy', 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               consecutive_failures = consecutive_failures + 1,
               last_failure_at = NOW(),
               last_error = VALUES(last_error)"
        )->execute([$providerKey, mb_substr($reason, 0, 500)]);

        $row = $db->prepare('SELECT * FROM ellsms_provider_health_state WHERE provider_key = ?');
        $row->execute([$providerKey]);
        $state = $row->fetch();
        if ($state === false) {
            return;
        }

        $consecutiveFailures = (int)$state['consecutive_failures'];
        $wasHealthy = $state['status'] === 'healthy';
        if ($consecutiveFailures < provider_health_outage_threshold()) {
            return;
        }

        $cooldownPassed = $state['last_alert_at'] === null
            || (time() - strtotime((string)$state['last_alert_at'])) >= provider_health_alert_cooldown_seconds();
        if (!$wasHealthy && !$cooldownPassed) {
            return; // already alerted for this ongoing outage, still within the cooldown window
        }

        $db->prepare("UPDATE ellsms_provider_health_state SET status = 'outage', last_alert_at = NOW() WHERE provider_key = ?")
            ->execute([$providerKey]);

        provider_health_alert($providerKey, 'outage', [
            'consecutive_failures' => $consecutiveFailures,
            'reason' => $reason,
        ]);
    } catch (Throwable $t) {
        // Health tracking must never be why a real send fails -- observability is best-effort.
        Logger::error('provider_health.record_failure_error', ['provider_key' => $providerKey, 'exception' => $t]);
    }
}

/**
 * Records one successful dispatch against $providerKey. If the provider was in outage, fires a
 * recovery alert and resets the counter.
 */
function provider_health_record_success(string $providerKey): void {
    try {
        $db = db();
        $existing = $db->prepare('SELECT status FROM ellsms_provider_health_state WHERE provider_key = ?');
        $existing->execute([$providerKey]);
        $wasOutage = ($existing->fetch()['status'] ?? 'healthy') === 'outage';

        $db->prepare(
            "INSERT INTO ellsms_provider_health_state (provider_key, status, consecutive_failures, last_success_at)
             VALUES (?, 'healthy', 0, NOW())
             ON DUPLICATE KEY UPDATE status = 'healthy', consecutive_failures = 0, last_success_at = NOW()"
        )->execute([$providerKey]);

        if ($wasOutage) {
            provider_health_alert($providerKey, 'recovered', []);
        }
    } catch (Throwable $t) {
        Logger::error('provider_health.record_success_error', ['provider_key' => $providerKey, 'exception' => $t]);
    }
}

/**
 * The alert itself: always a structured log line (so an admin sees it even with no alert channel
 * configured — cron/jobs-status.php / this file's own snapshot surface it), plus Telegram when
 * configured (app/telegram.php, the one channel this codebase already has wired for admin-relevant
 * notifications — issue #15 is where a real multi-channel/severity/escalation system belongs).
 */
function provider_health_alert(string $providerKey, string $type, array $context): void {
    $event = $type === 'recovered' ? 'provider_health.recovered' : 'provider_health.outage_detected';
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

/** Admin-visible snapshot of every provider this install has ever recorded a dispatch outcome for. */
function provider_health_snapshot(): array {
    return db()->query('SELECT * FROM ellsms_provider_health_state ORDER BY status DESC, provider_key ASC')->fetchAll();
}
