<?php
/**
 * ELLSMS — unified alert/incident subsystem (issue #15).
 *
 * ONE incident model for every alert source in this codebase, replacing the ad hoc "log line +
 * direct Telegram call" seam issue #10/#16 built (app/Sms/ProviderHealth.php's own
 * provider_health_alert()) with a real incident lifecycle: fire -> repeat (while unacknowledged)
 * -> acknowledge (stops repeats, incident stays open) -> recover (resolves it, sends a recovery
 * notice). Reuses the EXISTING, already-tested Telegram sender (app/telegram.php's
 * telegram_send_message()) and the existing email sender (app/NotificationCenter.php's
 * notification_send_email()) rather than adding a third parallel implementation of either.
 *
 * Severities and their DEFAULT repeat intervals (all admin-configurable via ellsms_settings,
 * never hardcoded -- see repeatIntervalSeconds()):
 *   - warning:   30 minutes
 *   - critical:  5 minutes
 *   - emergency: 2 minutes
 *
 * Dedup: a second alert_fire() call for the same $alertKey while an incident is already open or
 * acknowledged never creates a duplicate row or duplicate notification -- it just updates
 * last_fired_at/fire_count and, if the repeat interval has elapsed and the incident is NOT
 * acknowledged, re-dispatches exactly once. Acknowledging stops repeats but leaves the incident
 * open (only alert_recover() resolves it) -- an admin who has seen and is working on a problem
 * should not keep getting paged, but the incident must not silently vanish from view either.
 */

declare(strict_types=1);

final class AlertManager
{
    private function __construct() {} // static-only

    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_EMERGENCY = 'emergency';

    private const SEVERITIES = [self::SEVERITY_WARNING, self::SEVERITY_CRITICAL, self::SEVERITY_EMERGENCY];

    /**
     * Test-only sender overrides. Both default to null, meaning "use the real channel" --
     * production code never sets these. Kept as a tiny static registry (not a constructor
     * parameter) so every existing call site (app/Sms/ProviderHealth.php included) keeps calling
     * the same simple static API.
     */
    private static $telegramSenderOverride = null;
    private static $emailSenderOverride = null;

    public static function setTelegramSenderForTesting(?callable $sender): void {
        self::$telegramSenderOverride = $sender;
    }

    public static function setEmailSenderForTesting(?callable $sender): void {
        self::$emailSenderOverride = $sender;
    }

    private static function isValidSeverity(string $severity): bool {
        return in_array($severity, self::SEVERITIES, true);
    }

    /**
     * Admin-configurable, never hardcoded -- deliberately env-only (never setting()), matching
     * issue #3's own queue_class_min_share_from_env() precedent exactly, and for the same reason:
     * setting()'s cache is a process-wide static populated once and never refreshed (see
     * IntegrationTestCase's own docblock), so a DB-backed value would be effectively untestable
     * and, in a long-running worker process, unable to pick up an admin's change without a
     * restart -- an env var (settable per-deployment, e.g. via docker-compose.yml like every other
     * *_SECONDS tunable in this codebase) is both simpler and actually configurable at runtime.
     */
    public static function repeatIntervalSeconds(string $severity): int {
        $defaults = [
            self::SEVERITY_WARNING => 1800,
            self::SEVERITY_CRITICAL => 300,
            self::SEVERITY_EMERGENCY => 120,
        ];
        $default = $defaults[$severity] ?? 1800;
        $envKey = 'ALERT_REPEAT_SECONDS_' . strtoupper($severity);
        $raw = env($envKey, null);
        if ($raw === null || trim((string)$raw) === '') {
            return $default;
        }
        $value = (int)$raw;
        // 0 is a legitimate configuration ("repeat on every fire, no wait") -- only negative is invalid.
        return $value >= 0 ? $value : $default;
    }

    /**
     * Raise (or refresh) an incident. Returns the incident id.
     *
     * $alertKey must be a bounded, code-defined identifier (see this file's own docblock and
     * docs/observability-cardinality.md) -- it becomes both a DB row key and a Prometheus label.
     */
    public static function fire(string $alertKey, string $severity, string $title, string $message, array $context = []): int {
        if (!self::isValidSeverity($severity)) {
            throw new InvalidArgumentException("Unknown alert severity: {$severity}");
        }
        $db = db();
        $now = date('Y-m-d H:i:s');

        return db_transaction(function (PDO $db) use ($alertKey, $severity, $title, $message, $context, $now): int {
            $existing = $db->prepare(
                "SELECT * FROM ellsms_alert_incidents WHERE alert_key = ? AND status IN ('open','acknowledged') ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $existing->execute([$alertKey]);
            $incident = $existing->fetch();

            if ($incident === false) {
                $db->prepare(
                    "INSERT INTO ellsms_alert_incidents
                        (alert_key, severity, status, title, message, context_json, first_fired_at, last_fired_at, next_repeat_at, fire_count)
                     VALUES (?, ?, 'open', ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL ? SECOND), 1)"
                )->execute([$alertKey, $severity, $title, $message, json_encode($context, JSON_UNESCAPED_UNICODE), $now, $now, $now, self::repeatIntervalSeconds($severity)]);
                $incidentId = (int)$db->lastInsertId();
                self::dispatch($incidentId, $severity, $title, $message, false);
                Metrics::increment('alert.fired', 1, ['severity' => $severity]);
                return $incidentId;
            }

            $incidentId = (int)$incident['id'];
            $db->prepare('UPDATE ellsms_alert_incidents SET last_fired_at = ?, fire_count = fire_count + 1, message = ? WHERE id = ?')
                ->execute([$now, $message, $incidentId]);

            // Acknowledged incidents never repeat -- that is the entire point of acknowledging one.
            if ($incident['status'] === 'acknowledged') {
                return $incidentId;
            }

            $dueForRepeat = $incident['next_repeat_at'] === null || strtotime((string)$incident['next_repeat_at']) <= strtotime($now);
            if ($dueForRepeat) {
                $db->prepare('UPDATE ellsms_alert_incidents SET next_repeat_at = DATE_ADD(?, INTERVAL ? SECOND) WHERE id = ?')
                    ->execute([$now, self::repeatIntervalSeconds($severity), $incidentId]);
                self::dispatch($incidentId, $severity, $title, $message, true);
                Metrics::increment('alert.repeated', 1, ['severity' => $severity]);
            }

            return $incidentId;
        });
    }

    /** Resolves the active incident for $alertKey, if any, and sends a recovery notice. No-op if nothing is open/acknowledged for this key -- recovering a condition that never alerted is not itself news. */
    public static function recover(string $alertKey, string $recoveryMessage = ''): void {
        $db = db();
        db_transaction(function (PDO $db) use ($alertKey, $recoveryMessage): void {
            $stmt = $db->prepare("SELECT * FROM ellsms_alert_incidents WHERE alert_key = ? AND status IN ('open','acknowledged') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$alertKey]);
            $incident = $stmt->fetch();
            if ($incident === false) {
                return;
            }
            $incidentId = (int)$incident['id'];
            $db->prepare("UPDATE ellsms_alert_incidents SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$incidentId]);
            $title = 'بازیابی: ' . $incident['title'];
            $message = $recoveryMessage !== '' ? $recoveryMessage : ('شرایط هشدار "' . $incident['title'] . '" برطرف شد.');
            self::dispatch($incidentId, (string)$incident['severity'], $title, $message, false, true);
            Metrics::increment('alert.recovered', 1, ['severity' => (string)$incident['severity']]);
        });
    }

    /** Admin action: stop repeat notifications for an incident without closing it. Returns false if the incident doesn't exist or is already resolved. */
    public static function acknowledge(int $incidentId, int $actorUserId): bool {
        $db = db();
        $stmt = $db->prepare("UPDATE ellsms_alert_incidents SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND status = 'open'");
        $stmt->execute([$actorUserId, $incidentId]);
        if ($stmt->rowCount() > 0) {
            Metrics::increment('alert.acknowledged', 1, []);
            Logger::info('alert.acknowledged', ['incident_id' => $incidentId, 'actor_user_id' => $actorUserId]);
            return true;
        }
        return false;
    }

    /** @return list<array<string,mixed>> open/acknowledged incidents, most severe and most recent first. */
    public static function activeIncidents(): array {
        return db()->query(
            "SELECT * FROM ellsms_alert_incidents WHERE status IN ('open','acknowledged')
             ORDER BY FIELD(severity,'emergency','critical','warning'), first_fired_at DESC"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> most recently resolved incidents, for the admin history view. */
    public static function recentResolvedIncidents(int $limit = 50): array {
        $stmt = db()->prepare("SELECT * FROM ellsms_alert_incidents WHERE status = 'resolved' ORDER BY resolved_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Both channels are attempted independently -- one failing must never suppress the other. */
    private static function dispatch(int $incidentId, string $severity, string $title, string $message, bool $isRepeat, bool $isRecovery = false): void {
        $prefix = self::severityPrefix($severity);
        $label = $isRecovery ? '✅ ' : $prefix . ' ';
        $text = $label . $title . "\n" . $message;

        self::dispatchTelegram($incidentId, $text);
        self::dispatchEmail($incidentId, $title, $message);

        Metrics::gauge('alert.incidents.active', count(self::activeIncidents()), []);
    }

    private static function severityPrefix(string $severity): string {
        return match ($severity) {
            self::SEVERITY_EMERGENCY => '🚨',
            self::SEVERITY_CRITICAL => '⛔',
            default => '⚠️',
        };
    }

    private static function dispatchTelegram(int $incidentId, string $text): void {
        $sender = self::$telegramSenderOverride ?? static function (string $text): array {
            if (!function_exists('telegram_configured') || !telegram_configured()) {
                return [false, 'telegram not configured'];
            }
            return telegram_send_message($text);
        };
        try {
            [$ok, $detail] = $sender($text);
        } catch (Throwable $t) {
            $ok = false;
            $detail = $t->getMessage();
        }
        self::logDispatch($incidentId, 'telegram', $ok, (string)$detail);
    }

    private static function dispatchEmail(int $incidentId, string $title, string $message): void {
        $recipient = (string)(setting('alert_email_recipient', env('ALERT_EMAIL_RECIPIENT', '')) ?? '');
        $sender = self::$emailSenderOverride ?? static function (string $recipient, string $title, string $message): array {
            if ($recipient === '') {
                return [false, 'no alert_email_recipient configured'];
            }
            $ok = function_exists('notification_send_email') ? notification_send_email($recipient, $title, $message) : false;
            return [$ok, $ok ? 'sent' : 'mail() failed or recipient invalid'];
        };
        try {
            [$ok, $detail] = $sender($recipient, $title, $message);
        } catch (Throwable $t) {
            $ok = false;
            $detail = $t->getMessage();
        }
        self::logDispatch($incidentId, 'email', $ok, (string)$detail);
    }

    private static function logDispatch(int $incidentId, string $channel, bool $ok, string $detail): void {
        try {
            db()->prepare('INSERT INTO ellsms_alert_dispatch_log (incident_id, channel, outcome, detail) VALUES (?, ?, ?, ?)')
                ->execute([$incidentId, $channel, $ok ? 'sent' : 'failed', mb_substr($detail, 0, 255)]);
        } catch (Throwable $t) {
            Logger::error('alert.dispatch_log_failed', ['incident_id' => $incidentId, 'channel' => $channel, 'exception' => $t]);
        }
        Metrics::increment('alert.dispatch', 1, ['channel' => $channel, 'outcome' => $ok ? 'sent' : 'failed']);
        if (!$ok) {
            Logger::warning('alert.dispatch_failed', ['incident_id' => $incidentId, 'channel' => $channel, 'detail' => $detail]);
        }
    }
}
