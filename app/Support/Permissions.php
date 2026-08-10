<?php
/**
 * ELLSMS — the centralized RBAC permission catalog (Phase 7).
 *
 * A single source of truth for every organization-scoped permission string this codebase checks —
 * the fix for "arbitrary permission strings scattered across controllers" (typo-prone, impossible to
 * grep for every caller of a given permission). Every check in app/rbac.php and every call site in
 * public/ uses these constants, never a bare string literal.
 *
 * Not every constant here gates a real, already-existing feature today — some are catalog-complete
 * but currently RESERVED (see the "Reserved" section below): the permission exists, is wired into
 * the role matrix (app/rbac.php's DEFAULT_ROLE_PERMISSIONS), and is ready for the day a real
 * organization-scoped feature needs it, but no current UI/action checks it yet, because inventing a
 * new feature just to give a permission something to gate would be scope creep this phase explicitly
 * rejects ("Do not redesign the interface", "Do not create dozens of speculative permissions"). Each
 * reserved constant documents exactly why it has no wiring yet and what today's actual access rule is
 * instead — see docs/rbac-architecture.md for the full accounting.
 *
 * No namespace — same convention as Logger/AppException (app/Support/): global namespace, no
 * autoloader, loaded via require_once from app/bootstrap.php.
 */

declare(strict_types=1);

final class Permissions
{
    // --- Organization membership management (public/organizations.php) ---
    public const MEMBERS_VIEW   = 'members.view';
    public const MEMBERS_MANAGE = 'members.manage';

    // --- Sender numbers (app/authorization.php's allowed_originators()) ---
    // sender.view is real: every member can already see their own usable originators — wiring this
    // in just makes that existing access explicit and consistent with every other permission check.
    public const SENDER_VIEW = 'sender.view';
    // RESERVED: assigning a number to a user (public/numbers.php) is, and remains, a PLATFORM-ADMIN
    // action (require_admin()) — the master sender pool is a shared install-wide resource, not
    // something this phase turns into a per-organization self-service feature. Kept in the role
    // matrix (owner/admin) for the day an org-scoped sender-management UI exists.
    public const SENDER_MANAGE = 'sender.manage';

    // --- Contacts (public/contacts.php) ---
    public const CONTACTS_VIEW   = 'contacts.view';
    public const CONTACTS_MANAGE = 'contacts.manage';

    // --- Saved campaign templates (public/new-send.php) ---
    public const CAMPAIGNS_VIEW   = 'campaigns.view';
    public const CAMPAIGNS_MANAGE = 'campaigns.manage';
    // Dispatching FROM a saved campaign template specifically — distinct from CAMPAIGNS_MANAGE
    // (editing/saving a template) per STEP 14: a user who may edit campaign metadata should not
    // necessarily be allowed to actually send.
    public const CAMPAIGNS_SEND = 'campaigns.send';

    // --- Actually sending a message — send.php / p2p-send.php / smart-send.php / new-send.php's own
    // direct-dispatch path, and the schedule-creation action (a schedule is a deferred send) ---
    public const MESSAGES_SEND = 'messages.send';

    // --- Scheduled sends (public/schedules.php) ---
    public const SCHEDULES_VIEW    = 'schedules.view';
    public const SCHEDULES_MANAGE  = 'schedules.manage';

    // --- Auto-reply rules (public/autoreply.php, own-scope only) ---
    public const AUTOREPLY_VIEW   = 'autoreply.view';
    public const AUTOREPLY_MANAGE = 'autoreply.manage';

    // --- Wallet ---
    // wallet.view is real: every member can already see their own balance (buy-credit.php) — wallet
    // itself stays strictly user_id-keyed (Phase 3, unchanged by Phase 6 or this phase), so this is
    // "can view MY OWN balance", not a shared organization ledger.
    public const WALLET_VIEW = 'wallet.view';
    // RESERVED: manual credit adjustment (app/wallet.php's wallet_manual_adjustment()) is, and
    // remains, callable only from public/users.php — PLATFORM-ADMIN-ONLY (require_admin()), never an
    // organization-role action. This is deliberately the STRICTEST possible default (stricter than
    // "owner-only"): even an organization's owner cannot manually adjust any wallet through this
    // phase's RBAC, matching STEP 17's "manual credit adjustment must require high privilege." Kept
    // in the role matrix (owner only) for the day an org-scoped adjustment feature is built.
    public const WALLET_ADJUST = 'wallet.adjust';

    // --- Payments — read-only; ellsms_payments rows are strictly user_id-keyed, same as wallet ---
    public const PAYMENTS_VIEW = 'payments.view';

    // --- Reports (public/reports.php) ---
    public const REPORTS_VIEW = 'reports.view';

    // --- Organization-level settings. Real: public/organizations.php gains one small, existing-
    // pattern action (rename the active organization) gated by this, giving the permission a genuine
    // target without inventing a new page. Global platform settings (public/settings.php — API base
    // URL, ZarinPal credentials, contact-page text) remain PLATFORM-ADMIN-ONLY (require_admin()),
    // completely untouched by this permission — STEP 20's explicit "do not confuse the two." ---
    public const SETTINGS_MANAGE = 'settings.manage';

    // --- KYC. RESERVED, and deliberately NOT granted to any organization role by default (not even
    // owner) — public/users.php's kyc_save action is, and remains, PLATFORM-ADMIN-ONLY, and
    // public/kyc-photo.php's read gate is, and remains, "platform admin OR the document's own
    // subject", never an organization role. STEP 21 explicitly warns against broadening identity-
    // document access "merely because RBAC exists" — these two constants exist for cataloging/
    // documentation completeness only. ---
    public const KYC_VIEW   = 'kyc.view';
    public const KYC_MANAGE = 'kyc.manage';

    // --- Audit log. RESERVED — ellsms_audit_log has no organization_id column and no viewer UI
    // exists yet (writes only, via audit()). Kept in the role matrix (owner/admin) for the day an
    // organization-scoped audit viewer is built. ---
    public const AUDIT_VIEW = 'audit.view';

    // --- Public API keys (public/api-keys.php, Phase 12) ---
    // Deliberately a SEPARATE layer from ApiScopes (app/Support/ApiScopes.php, no relation): this
    // gates who may create/rotate/revoke a key at all; ApiScopes gates what an already-issued key
    // may call. See STEP 9/10's own explicit "these are separate layers."
    public const API_KEYS_VIEW   = 'api_keys.view';
    public const API_KEYS_MANAGE = 'api_keys.manage';

    // --- Webhook endpoints (public/webhooks.php, Phase 12) ---
    public const WEBHOOKS_VIEW   = 'webhooks.view';
    public const WEBHOOKS_MANAGE = 'webhooks.manage';

    // --- Subscription/billing (public/billing.php, Phase 13, STEP 37) ---
    // Deliberately a SEPARATE layer from Entitlements (app/Support/Entitlements.php, no relation):
    // these gate who inside an organization may VIEW or CHANGE that organization's own subscription;
    // Entitlements gate what the resulting plan actually unlocks. Invariant N — a paid plan never
    // grants a permission, and holding BILLING_MANAGE never bypasses a plan limit.
    // PLATFORM-level billing administration (assigning an arbitrary plan to any organization,
    // suspending, granting a trial) is NOT expressible as an organization permission at all and
    // stays gated on require_admin() in public/billing-admin.php — Invariant O.
    public const BILLING_VIEW   = 'billing.view';
    public const BILLING_MANAGE = 'billing.manage';

    /** Every permission constant this class defines, for integrity checks and iteration — never hand-maintained twice. */
    public static function all(): array {
        return [
            self::MEMBERS_VIEW, self::MEMBERS_MANAGE,
            self::SENDER_VIEW, self::SENDER_MANAGE,
            self::CONTACTS_VIEW, self::CONTACTS_MANAGE,
            self::CAMPAIGNS_VIEW, self::CAMPAIGNS_MANAGE, self::CAMPAIGNS_SEND,
            self::MESSAGES_SEND,
            self::SCHEDULES_VIEW, self::SCHEDULES_MANAGE,
            self::AUTOREPLY_VIEW, self::AUTOREPLY_MANAGE,
            self::WALLET_VIEW, self::WALLET_ADJUST,
            self::PAYMENTS_VIEW,
            self::REPORTS_VIEW,
            self::SETTINGS_MANAGE,
            self::KYC_VIEW, self::KYC_MANAGE,
            self::AUDIT_VIEW,
            self::API_KEYS_VIEW, self::API_KEYS_MANAGE,
            self::WEBHOOKS_VIEW, self::WEBHOOKS_MANAGE,
            self::BILLING_VIEW, self::BILLING_MANAGE,
        ];
    }
}
