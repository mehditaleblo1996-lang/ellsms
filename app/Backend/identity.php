<?php
/**
 * ELLSMS — backend identity provider (Phase 8, Invariant B).
 *
 * The ONE place every ELLSMS controller/worker reads `user_` (and the account-creation `domain`
 * lookup) through. Nothing outside this file, `app/Backend/credit_projection.php`,
 * `app/Backend/messages.php`, and `app/Backend/ApiClient.php` should touch `user_`/`domain` directly
 * — see `cron/backend-boundary-check.php` for the enforced allowlist and
 * `docs/service-boundaries.md` for the full ownership matrix.
 *
 * This is a DB adapter (`BACKEND_IDENTITY_MODE=db`, the only mode this install supports today — see
 * this file's own docblock in `docs/service-boundaries.md` for why an `api` mode isn't implementable
 * yet: no backend identity-read endpoint exists anywhere in this repository to call). Every function
 * here does EXACTLY what the call site it replaced did — moving the SQL, not changing behavior
 * (Phase 8's own explicit instruction).
 *
 * Deliberately does NOT touch `ellsms_meta`, `ellsms_organization_memberships`, or any other
 * ELLSMS-owned table — those already live in ELLSMS's own schema and were never a boundary problem;
 * mixing them into this file would blur exactly the ownership line this file exists to draw.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/Logger.php';

/**
 * The full identity/operational-state row for one backend user id — the superset every caller in
 * this codebase needs (current_user()'s session identity, resolve_ellsms_managed_user()'s admin
 * management gate, and the schedule/autoreply/bulk worker revalidation checks all read a subset of
 * these same columns). Returns null if the id doesn't exist — callers decide what "doesn't exist"
 * means for their own fail-closed logic, this function never guesses.
 */
function backend_find_user_by_id(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare(
        'SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.email, u.mobile,
                u.active, u.deleted, u.currentcredit AS credit,
                m.panel_access, m.is_admin, m.originator, m.twofa_enabled
         FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id
         WHERE u.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Pre-authentication lookup by username — login.php and bootstrap-admin.php's identical query
 * (id/password/active/deleted; login.php additionally needs mobile for the 2FA SMS destination).
 * Deliberately does NOT join ellsms_meta (that table is ELLSMS-owned, not this file's concern, and
 * both call sites already fetch it separately, AFTER the password check, unchanged).
 */
function backend_find_user_for_login(string $username): ?array {
    $st = db()->prepare('SELECT id, password, mobile, active, deleted FROM user_ WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

/** verify-2fa.php's pending-challenge re-check — id/username/mobile/active/deleted by id. */
function backend_find_user_login_state_by_id(int $id): ?array {
    $st = db()->prepare('SELECT id, username, mobile, active, deleted FROM user_ WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** profile.php's own-password-change flow — just the hash, nothing else. */
function backend_user_password_hash(int $userId): ?string {
    $st = db()->prepare('SELECT password FROM user_ WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    return $hash !== false ? (string)$hash : null;
}

/**
 * The only place `user_.password` is ever written from ELLSMS — profile.php's self-service change
 * and users.php's platform-admin reset both funnel through this one function now.
 */
function backend_update_user_password(int $userId, string $passwordHash): void {
    db()->prepare('UPDATE user_ SET password = ? WHERE id = ?')->execute([$passwordHash, $userId]);
}

/**
 * Resolve a user id by username — organizations.php's add-member lookup (active members only) and
 * users.php's grant-access lookup (any non-deleted account, active or not — an operator may
 * legitimately grant ELLSMS access to an account the backend currently has deactivated, pending
 * their own reactivation) are the same query with one differing predicate, preserved exactly as
 * each call site already had it.
 */
function backend_find_user_id_by_username(string $username, bool $requireActive = false): ?int {
    $sql = 'SELECT id FROM user_ WHERE username = ? AND deleted = 0' . ($requireActive ? ' AND active = 1' : '');
    $st = db()->prepare($sql);
    $st->execute([$username]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Batch username lookup for display-only joins (a table column showing "who owns this row") —
 * NOT used for any authorization/identity decision, purely presentation. Returns [id => username].
 * Missing ids are simply absent from the result (never guessed at).
 */
function backend_usernames_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, username FROM user_ WHERE id IN ({$placeholders})");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['id']] = $row['username'];
    }
    return $out;
}

/**
 * Batch identity fetch for public/users.php's platform-admin listing — the fields that table needs
 * beyond what ellsms_meta already has (username, name, credit, active/deleted), keyed by id. Same
 * "presentation, not authorization" caveat as backend_usernames_by_ids().
 */
function backend_users_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT id, username, firstname AS first_name, lastname AS last_name, currentcredit AS credit, active, deleted
         FROM user_ WHERE id IN ({$placeholders})"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

/** id+username of every non-deleted account, for an admin filter dropdown (public/reports.php). */
function backend_list_users_summary(): array {
    return db()->query('SELECT id, username FROM user_ WHERE deleted = 0 ORDER BY username')->fetchAll();
}

/** public/users.php's account-creation domain dropdown — the only `domain` table read anywhere. */
function backend_list_domains(): array {
    return db()->query('SELECT id, name FROM domain ORDER BY name')->fetchAll();
}

/**
 * id+username of every account granted ELLSMS panel access (ellsms_meta.panel_access = 1),
 * sorted by username — assignment dropdowns in autoreply.php/numbers.php. The panel_access flag
 * itself is ELLSMS-owned; only the username resolution crosses into user_, and it stays routed
 * through backend_usernames_by_ids() rather than a raw JOIN.
 */
function backend_panel_access_users(): array {
    $ids = db()->query('SELECT user_id FROM ellsms_meta WHERE panel_access = 1')->fetchAll(PDO::FETCH_COLUMN);
    $usernames = backend_usernames_by_ids($ids);
    $out = [];
    foreach ($ids as $id) {
        if (isset($usernames[(int)$id])) {
            $out[] = ['id' => (int)$id, 'username' => $usernames[(int)$id]];
        }
    }
    usort($out, fn($a, $b) => $a['username'] <=> $b['username']);
    return $out;
}
