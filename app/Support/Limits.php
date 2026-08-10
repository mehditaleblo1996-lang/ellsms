<?php
/**
 * ELLSMS — the centralized plan LIMIT catalog (Phase 13, STEP 3).
 *
 * Companion to app/Support/Entitlements.php: entitlements are boolean ("may this organization use
 * webhooks at all?"), limits are numeric ("how many webhook endpoints?"). Same fail-closed
 * catalog discipline — an unknown limit key can never enter ellsms_plan_limits.
 *
 * Two structurally different kinds of limit live here, distinguished by resetPeriod():
 *
 *   RESOURCE COUNTS (reset period 'never') — members, contacts, API keys, webhook endpoints,
 *     schedules, campaigns. The current value is a COUNT(*) over the owning table, so it can never
 *     drift from reality. Enforced by app/Entitlements.php's entitlement_with_resource_slot(),
 *     which serializes concurrent creates for one organization behind a real row lock (STEP 16 —
 *     a read-count-then-insert race is explicitly not acceptable).
 *
 *   USAGE METERS (reset period 'daily'/'monthly') — messages, API requests. The current value lives
 *     in ellsms_usage_counters and is mutated only by atomic conditional UPDATEs, never
 *     read-then-write (Invariant E/F). These reset on a UTC period boundary (STEP 17).
 *
 * A plan's limit_value of NULL means UNLIMITED — deliberately NULL rather than -1 or 0, both of
 * which read ambiguously next to a genuine limit of 0.
 */

declare(strict_types=1);

final class Limits
{
    /* ---------- Resource counts (reset period 'never') ---------- */

    /** Active memberships in the organization (ellsms_organization_memberships, status='active'). */
    public const MEMBERS = 'members';

    /** Contact records owned by the organization (ellsms_contacts). */
    public const CONTACTS = 'contacts';

    /** Active (non-revoked) public API keys (ellsms_api_keys). */
    public const API_KEYS = 'api_keys';

    /** Webhook endpoints (ellsms_webhook_endpoints). */
    public const WEBHOOK_ENDPOINTS = 'webhook_endpoints';

    /** Currently-active scheduled sends (ellsms_schedule, status='active'). */
    public const ACTIVE_SCHEDULES = 'active_schedules';

    /** Saved campaign templates (ellsms_campaigns). */
    public const CAMPAIGNS = 'campaigns';

    /* ---------- Usage meters (reset period 'monthly'/'daily') ---------- */

    /** Messages accepted into the send pipeline this billing month (UTC). */
    public const MONTHLY_MESSAGES = 'monthly_messages';

    /** Messages accepted into the send pipeline today (UTC). */
    public const DAILY_MESSAGES = 'daily_messages';

    /* ---------- Per-request caps (not counters — evaluated per call) ---------- */

    /** Maximum items in a single bulk job. Effective cap is min(plan, API_MAX_BULK_ITEMS) — STEP 24. */
    public const BULK_ITEMS_PER_JOB = 'bulk_items_per_job';

    /** Plan's API request/minute ceiling. Effective rate is min(system, plan) — STEP 23, never removes the global safety cap. */
    public const API_REQUESTS_PER_MINUTE = 'api_requests_per_minute';

    public static function all(): array
    {
        return [
            self::MEMBERS, self::CONTACTS, self::API_KEYS, self::WEBHOOK_ENDPOINTS,
            self::ACTIVE_SCHEDULES, self::CAMPAIGNS,
            self::MONTHLY_MESSAGES, self::DAILY_MESSAGES,
            self::BULK_ITEMS_PER_JOB, self::API_REQUESTS_PER_MINUTE,
        ];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /**
     * 'never' = a standing resource count (enforced by COUNT(*) under a lock);
     * 'daily'/'monthly' = a usage meter accumulated in ellsms_usage_counters.
     * Anything not listed defaults to 'never', which is the more conservative reading (a limit that
     * never resets on its own is stricter than one that does).
     */
    public static function resetPeriod(string $key): string
    {
        return [
            self::MONTHLY_MESSAGES => 'monthly',
            self::DAILY_MESSAGES   => 'daily',
        ][$key] ?? 'never';
    }

    /** True for keys accumulated in ellsms_usage_counters (as opposed to counted live from their owning table). */
    public static function isMeter(string $key): bool
    {
        return self::resetPeriod($key) !== 'never';
    }

    /**
     * The table + tenant column each RESOURCE COUNT limit is counted from — the single place that
     * mapping lives, so entitlement_current_resource_count() never needs a switch statement and a
     * new resource limit is a one-line addition here. Returns null for meters and per-request caps,
     * which are not counted this way.
     *
     * Each entry is [table, organization column, optional extra WHERE fragment].
     */
    public static function resourceSource(string $key): ?array
    {
        return [
            self::MEMBERS           => ['ellsms_organization_memberships', 'organization_id', "status = 'active'"],
            self::CONTACTS          => ['ellsms_contacts', 'organization_id', null],
            self::API_KEYS          => ['ellsms_api_keys', 'organization_id', "status = 'active'"],
            self::WEBHOOK_ENDPOINTS => ['ellsms_webhook_endpoints', 'organization_id', null],
            self::ACTIVE_SCHEDULES  => ['ellsms_schedule', 'organization_id', "status = 'active'"],
            self::CAMPAIGNS         => ['ellsms_campaigns', 'organization_id', null],
        ][$key] ?? null;
    }

    public static function label(string $key): string
    {
        return [
            self::MEMBERS                 => 'اعضای سازمان',
            self::CONTACTS                => 'مخاطبین',
            self::API_KEYS                => 'کلیدهای API',
            self::WEBHOOK_ENDPOINTS       => 'وب‌هوک‌ها',
            self::ACTIVE_SCHEDULES        => 'زمان‌بندی‌های فعال',
            self::CAMPAIGNS               => 'قالب‌های کمپین',
            self::MONTHLY_MESSAGES        => 'پیامک در ماه',
            self::DAILY_MESSAGES          => 'پیامک در روز',
            self::BULK_ITEMS_PER_JOB      => 'حداکثر ردیف در هر ارسال گروهی',
            self::API_REQUESTS_PER_MINUTE => 'درخواست API در دقیقه',
        ][$key] ?? $key;
    }
}
