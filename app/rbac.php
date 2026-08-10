<?php
/**
 * ELLSMS — Role-Based Access Control (Phase 7).
 *
 * Sits directly on top of Phase 6's organization/membership model (app/tenant.php): every
 * permission decision here is evaluated for one (user, organization) pair, never globally
 * (Invariant A/B). Mirrors the fail-closed philosophy Phase 2's app/authorization.php and Phase 6's
 * app/tenant.php already established — an unresolved membership, an unknown role, or an unknown
 * permission string always resolves to "no access," never to "allow."
 *
 * Deliberately Option A from this phase's own design menu: FIXED built-in roles (owner/admin/member
 * — unchanged from Phase 6's ellsms_organization_memberships.role ENUM, no schema change needed) with
 * the role -> permission mapping living in code (ROLE_PERMISSIONS below), not a database-backed
 * custom-roles system. No current product need justifies the extra schema/query surface of DB-backed
 * roles (Option B) — see docs/rbac-architecture.md for the full reasoning.
 *
 * The "platform admin" flag ($user['role'] === 'admin', from ellsms_meta.is_admin, Phase 2) is
 * deliberately NEVER read by anything in this file — identical to how app/tenant.php already keeps
 * platform admin orthogonal to organization role (Invariant J). A platform admin who is also an
 * organization member gets EXACTLY that membership's permissions here, nothing more; platform-wide
 * actions (public/users.php, public/settings.php, public/numbers.php, public/analytics.php) continue
 * to gate on require_admin()/is_admin() exactly as before, completely untouched by this file.
 */

declare(strict_types=1);

require_once __DIR__ . '/Support/Permissions.php';

/**
 * The default permission set for each built-in role. OWNER is deliberately "every permission this
 * phase's catalog defines" (Invariant E) — including the reserved ones (Permissions::all() minus the
 * KYC pair, see below), so the day a reserved permission gets a real feature, an owner is instantly
 * and correctly authorized for it with no map edit required elsewhere.
 *
 * ADMIN gets everything OWNER gets except: WALLET_ADJUST (STEP 17 — financial manual-adjustment stays
 * maximally restricted, currently not even granted to owner-level org actions, only platform admin)
 * and anything that touches the 'owner' role tier itself (granting/revoking ownership) — that
 * restriction is NOT expressed here as a missing permission (MEMBERS_MANAGE is still granted to
 * admin, since admin legitimately manages ordinary admin/member memberships) but as a separate,
 * transaction-safe escalation guard in organization_change_member_role()/organization_remove_member()
 * below (Invariant F/H) — a permission string alone cannot express "admin, but only up to admin
 * tier," so that rule lives in the one place that already has to lock and recheck the full member
 * list anyway.
 *
 * MEMBER gets least privilege (Invariant G) for anything administrative or financial, but is NOT
 * stripped of any capability that was universally available to every logged-in organization member
 * before this phase (contacts, campaigns, messaging, schedules, autoreply, viewing reports/wallet/
 * payments) — none of those pages ever gated on role before Phase 7, so granting them here CODIFIES
 * existing behavior rather than silently downgrading it (an explicit non-goal per this phase's own
 * "Do not silently downgrade... without documenting" instruction). What member correctly lacks:
 * MEMBERS_MANAGE, SENDER_MANAGE, WALLET_ADJUST, SETTINGS_MANAGE, AUDIT_VIEW, and both KYC permissions
 * (nobody but platform admin gets those — see Permissions::KYC_VIEW's own docblock).
 */
function role_permissions(string $role): array {
    static $map = null;
    if ($map === null) {
        $everythingExceptKyc = array_values(array_diff(Permissions::all(), [Permissions::KYC_VIEW, Permissions::KYC_MANAGE]));

        $map = [
            'owner' => $everythingExceptKyc,
            // Phase 13 adds BILLING_MANAGE to admin's exclusion list for exactly the same reason
            // WALLET_ADJUST is already excluded: committing the organization to a paid plan (or
            // cancelling one) is an irreversible financial decision, and this codebase's standing
            // precedent is that financial authority stays at the owner tier. BILLING_VIEW is NOT
            // excluded — an admin can see the plan, usage, and limits they operate under, they just
            // cannot change what the organization pays. See docs/plans-and-entitlements.md.
            'admin' => array_values(array_diff($everythingExceptKyc, [Permissions::WALLET_ADJUST, Permissions::BILLING_MANAGE])),
            'member' => [
                Permissions::SENDER_VIEW,
                Permissions::CONTACTS_VIEW, Permissions::CONTACTS_MANAGE,
                Permissions::CAMPAIGNS_VIEW, Permissions::CAMPAIGNS_MANAGE, Permissions::CAMPAIGNS_SEND,
                Permissions::MESSAGES_SEND,
                Permissions::SCHEDULES_VIEW, Permissions::SCHEDULES_MANAGE,
                Permissions::AUTOREPLY_VIEW, Permissions::AUTOREPLY_MANAGE,
                Permissions::WALLET_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::REPORTS_VIEW,
                Permissions::MEMBERS_VIEW, // read-only member-list visibility already existed for everyone pre-Phase-7
            ],
        ];
    }
    return $map[$role] ?? [];
}

/** Pure decision function — no DB access, unit-testable with a plain fixture array (Invariant H). */
function membership_has_permission(array $membership, string $permission): bool {
    $role = $membership['role'] ?? null;
    if (!is_string($role) || $role === '') {
        return false;
    }
    return in_array($permission, role_permissions($role), true);
}

/**
 * DB-backed, fail-closed permission check for an explicit (userId, organizationId) pair — the
 * function background jobs/workers or any code without a live session should call, since it never
 * touches $_SESSION (Invariant D: re-validates real membership every time, never trusts a bare id).
 */
function has_permission(int $userId, int $organizationId, string $permission): bool {
    $membership = organization_membership($userId, $organizationId);
    return $membership !== null && $membership['organization_status'] !== 'disabled' && membership_has_permission($membership, $permission);
}

/**
 * Web-context helper: requires an active organization (fail-closed 403 via require_organization(),
 * same as every other tenant-context gate) AND that the caller's membership in it carries
 * $permission. Deliberately does NOT also require the organization to be non-suspended — that is
 * require_active_organization()'s job (Phase 6) and stays a separate, explicit call at whichever
 * call sites need it (typically alongside a SEND/MANAGE permission, not a VIEW one) so the two
 * concerns (tenant status vs. role authorization) are never silently conflated.
 */
function require_permission(string $permission): array {
    $org = require_organization();
    if (!membership_has_permission($org, $permission)) {
        http_response_code(403);
        Logger::warning('rbac.permission_denied', [
            'user_id' => current_user()['id'] ?? null,
            'organization_id' => $org['organization_id'] ?? null,
            'permission' => $permission,
        ]);
        echo 'شما اجازه‌ی انجام این عملیات را ندارید.';
        exit;
    }
    return $org;
}

/** Like require_permission(), but passes if the caller's membership has ANY of $permissions. */
function require_any_permission(array $permissions): array {
    $org = require_organization();
    foreach ($permissions as $permission) {
        if (membership_has_permission($org, $permission)) {
            return $org;
        }
    }
    http_response_code(403);
    Logger::warning('rbac.permission_denied', [
        'user_id' => current_user()['id'] ?? null,
        'organization_id' => $org['organization_id'] ?? null,
        'permissions' => $permissions,
    ]);
    echo 'شما اجازه‌ی انجام این عملیات را ندارید.';
    exit;
}

/** Like require_permission(), but requires the caller's membership to have EVERY permission in $permissions. */
function require_all_permissions(array $permissions): array {
    $org = require_organization();
    foreach ($permissions as $permission) {
        if (!membership_has_permission($org, $permission)) {
            http_response_code(403);
            Logger::warning('rbac.permission_denied', [
                'user_id' => current_user()['id'] ?? null,
                'organization_id' => $org['organization_id'] ?? null,
                'permissions' => $permissions,
            ]);
            echo 'شما اجازه‌ی انجام این عملیات را ندارید.';
            exit;
        }
    }
    return $org;
}

/**
 * Pure escalation-tier decision (Invariant H): may an actor holding $actorRole change a membership
 * FROM $currentRole TO $newRole? Only an 'owner' may touch the 'owner' tier at all (grant it or take
 * it away) — an 'admin' may freely move a target between 'admin' and 'member' (a lateral/downward
 * move within or below their own tier), but can never create a peer or superior without already
 * being one. Unit-testable in isolation; the transaction-safe callers below are what make it safe
 * under concurrency (this function alone does not check last-owner protection — that needs the full,
 * LOCKED membership list, which only the transaction has).
 */
function can_assign_role(string $actorRole, string $currentRole, string $newRole): bool {
    if ($currentRole === $newRole) {
        return true; // no-op, always allowed (nothing actually escalates)
    }
    if ($newRole === 'owner' || $currentRole === 'owner') {
        return $actorRole === 'owner';
    }
    return in_array($actorRole, ['owner', 'admin'], true);
}

/**
 * The one place ellsms_organization_memberships.role is ever changed by user-facing code —
 * transaction-safe (Invariant I/STEP 31/STEP 8): locks every ACTIVE membership row of the
 * organization with SELECT ... FOR UPDATE before making any decision, so two concurrent requests
 * that would otherwise both see "2 owners, safe to demote one" and race to zero instead serialize —
 * the second transaction blocks until the first commits, then re-reads the ALREADY-UPDATED owner
 * count and correctly rejects if it would now hit zero. A plain read-then-update without this lock
 * cannot make that guarantee (STEP 8's own explicit warning).
 *
 * Returns ['ok' => bool, 'reason' => string, ...]. Never throws for an ordinary denial — only a real
 * DB failure propagates, exactly like this codebase's other db_transaction() callers.
 */
function organization_change_member_role(int $organizationId, array $actorMembership, int $targetUserId, string $newRole): array {
    if (!in_array($newRole, ['owner', 'admin', 'member'], true)) {
        return ['ok' => false, 'reason' => 'invalid_role'];
    }
    // Defense in depth (Invariant B/D): $actorMembership must actually BE a membership of
    // $organizationId, not merely trusted because the caller happened to pass matching values —
    // today's one call site (public/organizations.php) always does, but this function must stay
    // safe on its own for any future caller, not rely on that discipline.
    if ((int)($actorMembership['organization_id'] ?? 0) !== $organizationId) {
        return ['ok' => false, 'reason' => 'forbidden'];
    }
    if (!membership_has_permission($actorMembership, Permissions::MEMBERS_MANAGE)) {
        return ['ok' => false, 'reason' => 'forbidden'];
    }
    $actorRole = (string)($actorMembership['role'] ?? '');

    return db_transaction(function (PDO $db) use ($organizationId, $actorRole, $targetUserId, $newRole) {
        $lockSt = $db->prepare(
            "SELECT user_id, role FROM ellsms_organization_memberships WHERE organization_id = ? AND status = 'active' FOR UPDATE"
        );
        $lockSt->execute([$organizationId]);
        $byUser = [];
        foreach ($lockSt->fetchAll() as $row) {
            $byUser[(int)$row['user_id']] = $row['role'];
        }

        if (!isset($byUser[$targetUserId])) {
            return ['ok' => false, 'reason' => 'not_a_member'];
        }
        $currentRole = $byUser[$targetUserId];

        if (!can_assign_role($actorRole, $currentRole, $newRole)) {
            return ['ok' => false, 'reason' => 'insufficient_authority'];
        }
        if ($currentRole === $newRole) {
            return ['ok' => true, 'reason' => 'unchanged'];
        }

        if ($currentRole === 'owner' && $newRole !== 'owner') {
            $ownerCount = count(array_filter($byUser, static fn($r) => $r === 'owner'));
            if ($ownerCount <= 1) {
                return ['ok' => false, 'reason' => 'last_owner'];
            }
        }

        $db->prepare('UPDATE ellsms_organization_memberships SET role = ? WHERE organization_id = ? AND user_id = ?')
           ->execute([$newRole, $organizationId, $targetUserId]);

        return ['ok' => true, 'reason' => 'updated', 'previous_role' => $currentRole];
    });
}

/**
 * Revokes a membership — same locked-read pattern as organization_change_member_role() and for the
 * same reason: removing the last owner must be exactly as impossible under concurrency as demoting
 * them.
 */
function organization_remove_member(int $organizationId, array $actorMembership, int $targetUserId): array {
    // Same defense-in-depth self-check as organization_change_member_role() — see its comment.
    if ((int)($actorMembership['organization_id'] ?? 0) !== $organizationId) {
        return ['ok' => false, 'reason' => 'forbidden'];
    }
    if (!membership_has_permission($actorMembership, Permissions::MEMBERS_MANAGE)) {
        return ['ok' => false, 'reason' => 'forbidden'];
    }
    $actorRole = (string)($actorMembership['role'] ?? '');

    return db_transaction(function (PDO $db) use ($organizationId, $actorRole, $targetUserId) {
        $lockSt = $db->prepare(
            "SELECT user_id, role FROM ellsms_organization_memberships WHERE organization_id = ? AND status = 'active' FOR UPDATE"
        );
        $lockSt->execute([$organizationId]);
        $byUser = [];
        foreach ($lockSt->fetchAll() as $row) {
            $byUser[(int)$row['user_id']] = $row['role'];
        }

        if (!isset($byUser[$targetUserId])) {
            return ['ok' => false, 'reason' => 'not_a_member'];
        }
        $targetRole = $byUser[$targetUserId];

        if ($targetRole === 'owner' && $actorRole !== 'owner') {
            return ['ok' => false, 'reason' => 'insufficient_authority'];
        }
        if ($targetRole === 'owner') {
            $ownerCount = count(array_filter($byUser, static fn($r) => $r === 'owner'));
            if ($ownerCount <= 1) {
                return ['ok' => false, 'reason' => 'last_owner'];
            }
        }

        $db->prepare("UPDATE ellsms_organization_memberships SET status = 'revoked' WHERE organization_id = ? AND user_id = ?")
           ->execute([$organizationId, $targetUserId]);

        return ['ok' => true];
    });
}
