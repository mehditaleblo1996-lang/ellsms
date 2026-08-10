<?php
/**
 * ELLSMS — the centralized webhook event-type catalog (Phase 12, STEP 28).
 *
 * Same role as ApiScopes/Permissions: every event_type string ellsms_webhook_endpoints.event_types_json
 * or ellsms_webhook_events.event_type can ever hold is listed here, nothing else. Deliberately a
 * SMALL, stable catalog — STEP 28's own explicit "do not emit dozens of speculative events": each
 * one here is wired to a genuine, already-existing domain action (see app/Webhooks.php's
 * webhook_event_emit() call sites), never spec'd out in advance of the code that would raise it.
 */

declare(strict_types=1);

final class WebhookEvents
{
    public const MESSAGE_SENT      = 'message.sent';
    public const MESSAGE_FAILED    = 'message.failed';
    public const BULK_COMPLETED    = 'bulk.completed';
    public const BULK_FAILED       = 'bulk.failed';
    public const PAYMENT_CREDITED  = 'payment.credited';

    public static function all(): array
    {
        return [
            self::MESSAGE_SENT, self::MESSAGE_FAILED,
            self::BULK_COMPLETED, self::BULK_FAILED,
            self::PAYMENT_CREDITED,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /** Validates+dedupes a caller-supplied subscription list (STEP 27) — mirrors ApiScopes::normalize(). Null = reject the whole request. */
    public static function normalize(array $requested): ?array
    {
        if (!$requested) {
            return null;
        }
        $out = [];
        foreach ($requested as $type) {
            if (!is_string($type) || !self::isValid($type)) {
                return null;
            }
            $out[$type] = true;
        }
        return array_keys($out);
    }
}
