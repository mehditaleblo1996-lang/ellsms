<?php
/**
 * ELLSMS — Organization/Tenant context (Phase 6).
 *
 * Mirrors app/bootstrap.php's current_user()/require_login() pattern deliberately — same
 * fail-closed philosophy app/authorization.php established in Phase 2: a missing, revoked, or
 * ambiguous membership always resolves to "no organization," never to "pick one and hope," and
 * NOTHING here trusts an organization_id supplied by the client (query string, POST body, hidden
 * form field) without re-validating real membership against the database first — Invariant D.
 *
 * The "platform admin" flag (ellsms_meta.is_admin, Phase 2) is deliberately NOT wired into any
 * function here — a platform admin manages ELLSMS-managed accounts across the whole install (see
 * app/authorization.php's resolve_ellsms_managed_user()), which is a different, orthogonal
 * privilege from an organization's own owner/admin/member role. See
 * docs/multi-tenancy-architecture.md section on Admin Model for the full reasoning (STEP 21) —
 * conflating the two would silently turn every existing platform admin into a member of every
 * organization, which this file never does.
 */

declare(strict_types=1);

/**
 * Lightweight status lookup by organization id alone — no membership/user context needed. Used by
 * background execution paths (run_due_schedules(), bulk_send_one_item(), autoreply_process_one())
 * to revalidate a job's PERSISTED organization_id is still usable right before dispatch (STEP 26/
 * 27: never derive tenant identity from a session that doesn't exist in a worker, and never let a
 * suspended/disabled organization's queued work keep sending). Returns null for a NULL/0/unknown
 * id — treated by every caller as "no organization context to enforce," i.e. the legacy
 * pre-Phase-6/pre-backfill behavior, not a failure.
 */
function organization_status(?int $organizationId): ?string {
    if ($organizationId === null || $organizationId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT status FROM ellsms_organizations WHERE id = ?');
    $st->execute([$organizationId]);
    $row = $st->fetch();
    return $row ? $row['status'] : null;
}

/** Every ACTIVE membership $userId currently holds, joined with the organization's own row. */
function user_organization_memberships(int $userId): array {
    if ($userId <= 0) {
        return [];
    }
    $st = db()->prepare(
        "SELECT m.id AS membership_id, m.organization_id, m.role, m.status AS membership_status,
                o.name, o.slug, o.status AS organization_status
         FROM ellsms_organization_memberships m
         JOIN ellsms_organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.status = 'active'
         ORDER BY o.name"
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * The full membership+organization row for ($userId, $organizationId), or null unless the
 * membership is active. This is the single query every other function in this file funnels
 * through for a specific org — the one place "does this user really belong here" is decided.
 */
function organization_membership(int $userId, int $organizationId): ?array {
    if ($userId <= 0 || $organizationId <= 0) {
        return null;
    }
    $st = db()->prepare(
        "SELECT m.id AS membership_id, m.organization_id, m.role, m.status AS membership_status,
                o.name, o.slug, o.status AS organization_status
         FROM ellsms_organization_memberships m
         JOIN ellsms_organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.organization_id = ? AND m.status = 'active'"
    );
    $st->execute([$userId, $organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * The organization id $userId's own active membership resolves to, when there is exactly one
 * (the universal shape for a legacy-migrated user). Used by admin-driven "create this resource on
 * behalf of a specific target user" flows (e.g. autoreply.php's admin-assigns-a-rule-to-another-
 * user path) to resolve the correct owning organization for the TARGET, not the acting admin.
 * Returns null for zero or multiple memberships — never guesses among several.
 */
function user_default_organization_id(int $userId): ?int {
    $memberships = user_organization_memberships($userId);
    return count($memberships) === 1 ? (int)$memberships[0]['organization_id'] : null;
}

/**
 * user_.id values for every currently-active member of $organizationId — used to scope reports/
 * queries against BACKEND-OWNED tables that only carry a user_id, never an organization_id
 * (outbound_message, inbound_message — ELLSMS does not control that schema, STEP 23), so
 * organization-wide visibility for those has to be expressed as "any of these user ids" rather
 * than a direct organization_id column filter.
 */
function organization_member_user_ids(int $organizationId): array {
    if ($organizationId <= 0) {
        return [];
    }
    $st = db()->prepare("SELECT user_id FROM ellsms_organization_memberships WHERE organization_id = ? AND status = 'active'");
    $st->execute([$organizationId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** True if $userId has a currently-active membership in $organizationId AND that organization is not disabled. */
function can_access_organization(int $userId, int $organizationId): bool {
    $membership = organization_membership($userId, $organizationId);
    return $membership !== null && $membership['organization_status'] !== 'disabled';
}

/**
 * Resolves the CURRENT active organization for the logged-in user — server-side, session-selected
 * (Invariant B/D/H). Falls back to auto-selecting the user's own membership only when there is
 * EXACTLY ONE active, non-disabled candidate (the universal shape for every legacy-migrated
 * single-person organization — STEP 4); with zero or multiple candidates and nothing explicitly
 * selected, this returns null rather than guessing, and the caller (require_organization() /
 * the organization switcher) decides what to do about it.
 *
 * A previously-selected organization that the user can no longer access (membership revoked,
 * organization disabled since selection) is detected and cleared here, every call — Invariant B:
 * "a user may only access organizations where they have an active membership," re-checked live,
 * never cached across a membership change within the same request lifecycle in a way that could
 * go stale.
 */
function current_organization(): ?array {
    // Cache key is (userId, selectedId) together, not selectedId alone — keying on selection only
    // would risk returning one user's cached organization row for a DIFFERENT user who happens to
    // have the same $_SESSION['organization_id'] value (a real risk across PHPUnit test cases
    // sharing one PHP process with auto-incrementing organization ids, not just a theoretical one).
    static $cacheKey = null;
    static $cached = null;

    $user = current_user();
    if (!$user) {
        return null;
    }
    $userId = (int)$user['id'];
    $selectedId = isset($_SESSION['organization_id']) ? (int)$_SESSION['organization_id'] : 0;
    $key = $userId . ':' . $selectedId;

    if ($cacheKey === $key) {
        return $cached;
    }

    $org = null;
    if ($selectedId > 0) {
        $org = organization_membership($userId, $selectedId);
        if ($org === null || $org['organization_status'] === 'disabled') {
            unset($_SESSION['organization_id']);
            $org = null;
            $selectedId = 0;
        }
    }

    if ($org === null) {
        $memberships = user_organization_memberships($userId);
        $usable = array_values(array_filter($memberships, static fn($m) => $m['organization_status'] !== 'disabled'));
        if (count($usable) === 1) {
            $organizationId = (int)$usable[0]['organization_id'];
            $_SESSION['organization_id'] = $organizationId;
            $org = organization_membership($userId, $organizationId);
            $selectedId = $organizationId;
        }
    }

    $cacheKey = $userId . ':' . $selectedId;
    $cached = $org;
    return $org;
}

/** Requires an active organization context; fails closed (403) rather than guessing one. */
function require_organization(): array {
    $org = current_organization();
    if (!$org) {
        http_response_code(403);
        Logger::warning('tenant.no_active_organization', ['user_id' => current_user()['id'] ?? null]);
        echo 'دسترسی به سازمانی یافت نشد. لطفاً با پشتیبانی تماس بگیرید یا سازمان خود را انتخاب کنید.';
        exit;
    }
    return $org;
}

/**
 * Requires an active organization context that is also not 'suspended' — the fail-closed gate for
 * sending / admin mutations / new financial commitments (STEP 3). Historical/read-only pages
 * should call require_organization() instead, not this, so a suspended organization's owner can
 * still review their own data.
 */
function require_active_organization(): array {
    $org = require_organization();
    if ($org['organization_status'] === 'suspended') {
        http_response_code(403);
        Logger::warning('tenant.suspended_organization_blocked', ['organization_id' => $org['organization_id']]);
        echo 'این سازمان معلق شده است. ارسال و عملیات مالی جدید تا رفع تعلیق امکان‌پذیر نیست.';
        exit;
    }
    return $org;
}

/**
 * Switches the session's active organization — the ONLY place $_SESSION['organization_id'] should
 * be written from user-facing code. Re-validates membership before switching (Invariant D: a bare
 * ID must never grant access) — returns false and changes nothing if the user isn't an active
 * member, so a crafted/guessed organization_id in a switch request is a silent no-op, not a leak.
 */
function select_organization(int $organizationId): bool {
    $user = current_user();
    if (!$user || !can_access_organization((int)$user['id'], $organizationId)) {
        return false;
    }
    $_SESSION['organization_id'] = $organizationId;
    return true;
}

/** True if $membership's role is 'owner' or 'admin' — the two roles allowed to manage the organization itself (not a platform-admin check). */
function is_organization_manager(array $membership): bool {
    return in_array($membership['role'] ?? null, ['owner', 'admin'], true);
}

/**
 * Atomically creates a new organization, an 'owner' membership for $creatorUserId, and a wallet
 * account for it — all-or-nothing (STEP 38). Slug is derived from $name, de-duplicated with a
 * numeric suffix on collision rather than failing the whole creation over a cosmetic clash.
 */
function create_organization(int $creatorUserId, string $name): array {
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'reason' => 'name_required'];
    }
    return db_transaction(function (PDO $db) use ($creatorUserId, $name): array {
        $baseSlug = organization_slugify($name);
        $slug = $baseSlug;
        $suffix = 1;
        $existsSt = $db->prepare('SELECT COUNT(*) c FROM ellsms_organizations WHERE slug = ?');
        do {
            $existsSt->execute([$slug]);
            $exists = (int)$existsSt->fetch()['c'] > 0;
            if ($exists) {
                $suffix++;
                $slug = $baseSlug . '-' . $suffix;
            }
        } while ($exists);

        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, \'active\', ?)')
           ->execute([$name, $slug, $creatorUserId]);
        $organizationId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")
           ->execute([$organizationId, $creatorUserId]);

        wallet_ensure_account($db, $creatorUserId);
        $db->prepare('UPDATE ellsms_wallet_accounts SET organization_id = ? WHERE user_id = ?')
           ->execute([$organizationId, $creatorUserId]);

        Logger::info('tenant.organization_created', ['organization_id' => $organizationId, 'creator_user_id' => $creatorUserId]);
        return ['ok' => true, 'organization_id' => $organizationId, 'slug' => $slug];
    });
}

function organization_slugify(string $name): string {
    $slug = mb_strtolower(trim($name));
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? 'org';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'org';
}
