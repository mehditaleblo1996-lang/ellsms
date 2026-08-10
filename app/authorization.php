<?php
/**
 * ELLSMS — centralized authorization helpers (Phase 2).
 *
 * Every decision function here is fail-closed: an empty/missing/legacy-
 * broken input always resolves to "no access," never to "show
 * everything." This file exists specifically because
 * PATHFINDER-2026-07-26 / docs/security-review.md found the opposite
 * pattern (an empty legacy field silently meaning "unrestricted") to be
 * the root cause of the CRITICAL inbox.php cross-tenant leak.
 *
 * Split deliberately into:
 *  - thin DB-read functions (user_assigned_numbers, resolve_ellsms_managed_user)
 *    whose only job is fetching the row(s) a decision needs, and
 *  - pure decision functions (can_view_inbound_message, can_use_originator,
 *    is_backend_account_active, has_panel_access, can_demote_or_revoke)
 *    that take already-fetched arrays and return a bool — these are
 *    unit-testable with plain fixture arrays, no database required.
 *
 * Required from app/bootstrap.php so every page already gets these for
 * free, the same way current_user()/require_login()/is_admin() work.
 */

declare(strict_types=1);

/**
 * $user's assigned sender-line rows (number + label), for populating a
 * dropdown — the single source of truth this project previously
 * duplicated verbatim across send.php, new-send.php, p2p-send.php,
 * smart-send.php, and autoreply.php (see
 * PATHFINDER-2026-07-26/02-duplication-report.md #1). Admins are never
 * scoped to a numbers pool, so this deliberately returns [] for them —
 * every existing call site already only calls this for non-admins.
 */
function user_assigned_numbers(array $user): array {
    if (($user['role'] ?? null) === 'admin') {
        return [];
    }
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $st->execute([(int)($user['id'] ?? 0)]);
    return $st->fetchAll();
}

/**
 * The sender lines (originators) $user is currently allowed to use.
 * Admins get the sentinel ['*'] (unrestricted, matching every existing
 * admin code path). A non-admin gets their assigned numbers pool if
 * non-empty, otherwise falls back to their own legacy
 * ellsms_meta.originator value if set — and otherwise an EMPTY array,
 * never "everyone"/"everything". This is the fix for the inbox.php
 * finding: the old code's `$me['originator'] !== ''` check meant "empty
 * legacy field -> no filter at all -> see every user's messages"; here
 * an empty result means the opposite; zero permitted originators.
 *
 * Phase 6: if $user['organization_id'] is set, every number OWNED BY that organization is also
 * included — the multi-tenant upgrade to sender ownership (STEP 7): any active member of an
 * organization may use any of that organization's sender lines, not just the one individual a
 * number happened to be assigned to historically. Deliberately reads ONLY $user['organization_id']
 * (a value the caller must have already resolved and attached — require_login() does this for web
 * requests via the session-bound current_organization(); background jobs attach it from their own
 * PERSISTED organization_id column, never from a session that doesn't exist in that context — see
 * docs/multi-tenancy-architecture.md's Worker Tenant Context section) — never unioning across
 * every organization a user might belong to, which would violate Invariant C (Org A leaking into
 * Org B) for a genuinely multi-org member. A call site that hasn't been updated to attach
 * organization_id yet simply gets the exact pre-Phase-6 behavior, unchanged — this is additive,
 * not a replacement.
 */
function allowed_originators(array $user): array {
    if (($user['role'] ?? null) === 'admin') {
        return ['*'];
    }
    $numbers = array_column(user_assigned_numbers($user), 'number');
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId > 0) {
        $orgNumbers = array_column(organization_assigned_numbers($organizationId), 'number');
        $numbers = array_values(array_unique(array_merge($numbers, $orgNumbers)));
    }
    if ($numbers) {
        return $numbers;
    }
    $legacy = normalize_originator((string)($user['originator'] ?? ''));
    return $legacy ? [$legacy] : [];
}

/** Sender numbers owned by $organizationId — any active member of that organization may use any of them (Phase 6, STEP 7). */
function organization_assigned_numbers(int $organizationId): array {
    if ($organizationId <= 0) {
        return [];
    }
    $st = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE organization_id = ? ORDER BY number');
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * True if $user may view/receive inbound messages that arrived on
 * $destination. Fails closed: a user with zero allowed originators can
 * never see any inbound message, regardless of $destination's value.
 */
function can_view_inbound_message(array $user, string $destination): bool {
    $allowed = allowed_originators($user);
    if (in_array('*', $allowed, true)) {
        return true;
    }
    if (!$allowed) {
        return false;
    }
    $dest = normalize_originator($destination);
    return $dest !== null && in_array($dest, $allowed, true);
}

/**
 * True if $user may send FROM $originator right now. Permitted set:
 * admins (unrestricted, matching existing behavior), the user's own
 * allowed_originators(), or the admin-configured system
 * default_originator setting — the latter is deliberate, pre-existing
 * product behavior (every send/new-send/p2p/smart page pre-fills the
 * free-text originator field with this value for users with no assigned
 * numbers, and SMS 2FA codes are sent from it too), not a new hole
 * opened by this function.
 */
function can_use_originator(array $user, string $originator): bool {
    $normalized = normalize_originator($originator);
    if ($normalized === null) {
        return false;
    }
    if (($user['role'] ?? null) === 'admin') {
        return true;
    }
    if (in_array($normalized, allowed_originators($user), true)) {
        return true;
    }
    $default = normalize_originator((string)(setting('default_originator', '') ?? ''));
    return $default !== null && $normalized === $default;
}

/** True if a `user_` row (or null) represents a currently usable backend account. */
function is_backend_account_active(?array $backendUserRow): bool {
    return (bool)$backendUserRow && (bool)$backendUserRow['active'] && !$backendUserRow['deleted'];
}

/** True if an `ellsms_meta` row (or null) currently grants ELLSMS panel access. */
function has_panel_access(?array $metaRow): bool {
    return (bool)$metaRow && (bool)$metaRow['panel_access'];
}

/**
 * Resolve a target user for an ELLSMS-admin management action (edit
 * view, credit change, password reset, KYC edit, role/2FA toggle, ...).
 * Returns null — fail closed — unless the target both exists as a
 * backend account AND already has an `ellsms_meta` row with
 * `panel_access = 1`. An ELLSMS admin is not automatically a global
 * administrator of the backend platform; this is the one gate every
 * such action in users.php goes through.
 *
 * Deliberately NOT used by the "grant" or "create_account" actions —
 * those two are the intentional, narrowly-scoped exception whose entire
 * purpose is bringing a not-yet-managed backend account into ELLSMS for
 * the first time, so requiring pre-existing panel_access would make
 * them impossible to use.
 */
function resolve_ellsms_managed_user(int $targetId): ?array {
    if ($targetId <= 0) {
        return null;
    }
    // Phase 8 (Invariant B): reads through backend_find_user_by_id() (app/Backend/identity.php) —
    // the same superset row current_user() uses, filtered down to this function's own
    // panel_access gate, unchanged.
    $row = backend_find_user_by_id($targetId);
    if (!$row || !$row['panel_access']) {
        return null;
    }
    return $row;
}

/** Prevent an admin from revoking their own access or demoting themselves (self-lockout). */
function can_demote_or_revoke(array $actor, int $targetId): bool {
    return $targetId !== (int)($actor['id'] ?? 0);
}
