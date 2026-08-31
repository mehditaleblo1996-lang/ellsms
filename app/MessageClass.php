<?php
/**
 * ELLSMS — canonical message classes and priority order (issue #3).
 *
 * Six agreed classes, highest priority first: OTP > Transactional > Notification > Scheduled >
 * Bulk Campaign > Advertising. This file is the single source of truth for that ordering — every
 * place that needs to compare/sort by class (queue claim ordering, per-tick quota allocation,
 * metrics labels) calls into here instead of re-declaring the list, so the order can never drift
 * between call sites.
 *
 * Only Bulk Campaign and Advertising actually contend for worker claim capacity today (they are
 * the two classes that share ellsms_bulk_jobs/ellsms_bulk_items). OTP/Transactional/Notification
 * are dispatched synchronously from the web request (dispatch_message()) and never queue, and
 * Scheduled already has its own table/claim query and its own worker pass that runs before the
 * bulk pass — so those four classes are already isolated from bulk backlog by construction. The
 * per-class quota machinery in QueueFairness.php exists for all six so a future path (e.g. queuing
 * Notification sends) only has to tag its rows with the right class, not invent new fairness logic.
 */

declare(strict_types=1);

const MESSAGE_CLASS_OTP = 'otp';
const MESSAGE_CLASS_TRANSACTIONAL = 'transactional';
const MESSAGE_CLASS_NOTIFICATION = 'notification';
const MESSAGE_CLASS_SCHEDULED = 'scheduled';
const MESSAGE_CLASS_BULK_CAMPAIGN = 'bulk_campaign';
const MESSAGE_CLASS_ADVERTISING = 'advertising';

/** Highest priority first. Index in this array IS the priority rank. */
function message_classes_by_priority(): array {
    return [
        MESSAGE_CLASS_OTP,
        MESSAGE_CLASS_TRANSACTIONAL,
        MESSAGE_CLASS_NOTIFICATION,
        MESSAGE_CLASS_SCHEDULED,
        MESSAGE_CLASS_BULK_CAMPAIGN,
        MESSAGE_CLASS_ADVERTISING,
    ];
}

/** Lower number = higher priority. Unknown class sorts last (safest default: never jumps the queue). */
function message_class_rank(string $class): int {
    $rank = array_search($class, message_classes_by_priority(), true);
    return $rank === false ? PHP_INT_MAX : $rank;
}

function is_valid_message_class(?string $class): bool {
    return $class !== null && in_array($class, message_classes_by_priority(), true);
}

/** Unknown/absent input normalizes to the safest, lowest-priority class rather than guessing. */
function normalize_message_class(?string $class): string {
    return is_valid_message_class($class) ? $class : MESSAGE_CLASS_BULK_CAMPAIGN;
}

/**
 * Maps the existing SMS_MESSAGE_TYPES pricing vocabulary (app/Sms/Pricing.php) onto a queue
 * message class. Pricing and queueing classify messages for different purposes (cost/route vs.
 * worker priority) so this is a deliberate translation, not a reuse of one enum for both — 'default'
 * pricing (no explicit type) is an ordinary app notification, and 'promotional' pricing is exactly
 * what the queue calls Advertising.
 */
function message_class_from_pricing_type(?string $pricingType): string {
    return match ($pricingType) {
        'otp' => MESSAGE_CLASS_OTP,
        'transactional' => MESSAGE_CLASS_TRANSACTIONAL,
        'promotional' => MESSAGE_CLASS_ADVERTISING,
        default => MESSAGE_CLASS_NOTIFICATION,
    };
}

/**
 * ellsms_bulk_jobs.message_class is a 2-value ENUM ('bulk_campaign','advertising') — the only two
 * classes that actually share that table today (see the module docblock above). Anything else
 * (null, a typo, a future class not yet wired into this table) safely falls back to the class
 * every real bulk job has always been: 'bulk_campaign'.
 */
function normalize_bulk_message_class(?string $class): string {
    return $class === MESSAGE_CLASS_ADVERTISING ? MESSAGE_CLASS_ADVERTISING : MESSAGE_CLASS_BULK_CAMPAIGN;
}

/** Sort classes highest-priority-first; used wherever a set of classes (not full rows) needs ordering. */
function sort_message_classes(array $classes): array {
    usort($classes, static fn(string $a, string $b): int => message_class_rank($a) <=> message_class_rank($b));
    return $classes;
}
