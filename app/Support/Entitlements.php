<?php
/**
 * ELLSMS — the centralized plan ENTITLEMENT catalog (Phase 13, STEP 3).
 *
 * Same role Permissions (Phase 7) and ApiScopes (Phase 12) already play for their own domains: a
 * single source of truth for every boolean capability a PLAN can include, so no controller ever
 * compares against a bare string literal and an unknown key can never be silently accepted into
 * ellsms_plan_entitlements.
 *
 * This is a THIRD, deliberately independent authorization layer (Invariant N/O):
 *   - Permissions  answers "may this USER perform this action in this organization?" (RBAC)
 *   - ApiScopes    answers "may this API KEY call this endpoint?" (Phase 12)
 *   - Entitlements answers "does this ORGANIZATION'S SUBSCRIPTION include this capability at all?"
 * All three must pass independently. A plan never grants a permission; an owner never bypasses a
 * plan limit. Conflating any two of them would be exactly the escalation path STEP 11 forbids.
 *
 * Every constant here gates a capability that ALREADY EXISTS in this product — nothing speculative
 * (STEP 2's explicit "do not invent unsupported features"). Capabilities deliberately absent from
 * this catalog, and why:
 *   - login / password reset / account recovery / profile — never gated (STEP 14).
 *   - platform-admin operations (users.php, settings.php, numbers.php, analytics.php) — governed by
 *     ellsms_meta.is_admin only, NEVER by an organization's plan (Invariant O). A platform admin's
 *     authority is install-wide and orthogonal to any customer's subscription.
 *   - contacts / reports / inbox / basic send — always available on every plan including `free`;
 *     they are QUANTITY- or USAGE-limited (see Limits) rather than feature-gated, because taking
 *     them away entirely would make the product unusable rather than merely smaller.
 *
 * No namespace, no autoloader — same convention as Permissions/ApiScopes, required from bootstrap.
 */

declare(strict_types=1);

final class Entitlements
{
    /** The Phase 12 public REST API (/api/v1/*). Gated as a whole: a plan without this cannot use any API route. */
    public const PUBLIC_API = 'public_api';

    /** Phase 12 webhook endpoints — configuring them and receiving deliveries. */
    public const WEBHOOKS = 'webhooks';

    /** Saved campaign templates (public/new-send.php's save-a-campaign action). */
    public const CAMPAIGNS = 'campaigns';

    /** Scheduled/deferred sends (public/schedules.php, public/send.php's schedule branch). */
    public const SCHEDULES = 'schedules';

    /** SMS auto-responder rules (منشی پیامک — public/autoreply.php). */
    public const AUTOREPLY = 'autoreply';

    /** Bulk personalized sending (نظیر به نظیر / پیامک هوشمند / ارسال تدریجی). */
    public const BULK_SEND = 'bulk_send';

    /** The per-operator analytics/advanced reporting breakdown, beyond the basic send report every plan gets. */
    public const REPORTS_ADVANCED = 'reports_advanced';

    /**
     * Every entitlement key this class defines — never hand-maintained twice. Used by the plan
     * editor, cron/subscription-integrity-check.php, and app/Entitlements.php's own validation.
     */
    public static function all(): array
    {
        return [
            self::PUBLIC_API,
            self::WEBHOOKS,
            self::CAMPAIGNS,
            self::SCHEDULES,
            self::AUTOREPLY,
            self::BULK_SEND,
            self::REPORTS_ADVANCED,
        ];
    }

    /** True only for an exactly-matching cataloged key — fail closed for anything else (Invariant C). */
    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /** Human-readable label for the billing UI / operational output. Falls back to the raw key rather than throwing. */
    public static function label(string $key): string
    {
        return [
            self::PUBLIC_API       => 'API عمومی',
            self::WEBHOOKS         => 'وب‌هوک‌ها',
            self::CAMPAIGNS        => 'قالب‌های کمپین',
            self::SCHEDULES        => 'ارسال زمان‌بندی‌شده',
            self::AUTOREPLY        => 'منشی پیامک',
            self::BULK_SEND        => 'ارسال گروهی',
            self::REPORTS_ADVANCED => 'گزارش‌های پیشرفته',
        ][$key] ?? $key;
    }
}
