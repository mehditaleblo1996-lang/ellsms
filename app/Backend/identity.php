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

function backend_find_user_by_id(int $id): ?array {
    if ($id <= 0) return null;
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

function backend_find_user_for_login(string $username): ?array {
    $st = db()->prepare('SELECT id, password, mobile, active, deleted FROM user_ WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

function backend_find_user_login_state_by_id(int $id): ?array {
    $st = db()->prepare('SELECT id, username, mobile, active, deleted FROM user_ WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function backend_user_password_hash(int $userId): ?string {
    $st = db()->prepare('SELECT password FROM user_ WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    return $hash !== false ? (string)$hash : null;
}

function backend_update_user_password(int $userId, string $passwordHash): void {
    db()->prepare('UPDATE user_ SET password = ? WHERE id = ?')->execute([$passwordHash, $userId]);
}

function backend_find_user_id_by_username(string $username, bool $requireActive = false): ?int {
    $sql = 'SELECT id FROM user_ WHERE username = ? AND deleted = 0' . ($requireActive ? ' AND active = 1' : '');
    $st = db()->prepare($sql);
    $st->execute([$username]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function backend_usernames_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, username FROM user_ WHERE id IN ({$placeholders})");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row['username'];
    return $out;
}

function backend_users_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT id, username, firstname AS first_name, lastname AS last_name, currentcredit AS credit, active, deleted
         FROM user_ WHERE id IN ({$placeholders})"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    return $out;
}

function backend_list_users_summary(): array {
    return db()->query('SELECT id, username FROM user_ WHERE deleted = 0 ORDER BY username')->fetchAll();
}

function backend_list_domains(): array {
    return db()->query('SELECT id, name FROM domain ORDER BY name')->fetchAll();
}

function backend_panel_access_users(): array {
    $ids = db()->query('SELECT user_id FROM ellsms_meta WHERE panel_access = 1')->fetchAll(PDO::FETCH_COLUMN);
    $usernames = backend_usernames_by_ids($ids);
    $out = [];
    foreach ($ids as $id) {
        if (isset($usernames[(int)$id])) $out[] = ['id' => (int)$id, 'username' => $usernames[(int)$id]];
    }
    usort($out, fn($a, $b) => $a['username'] <=> $b['username']);
    return $out;
}

/**
 * Mobile numbers of active ELLSMS platform admins. Used only as a safe fallback for operational
 * notifications when registration_admin_mobiles is not explicitly configured. The join stays in
 * this identity adapter so no registration/controller code reaches into backend-owned user_.
 *
 * @return list<string>
 */
function backend_ellsms_admin_mobiles(): array {
    $st = db()->query(
        "SELECT u.mobile
         FROM user_ u
         JOIN ellsms_meta m ON m.user_id = u.id
         WHERE m.panel_access = 1 AND m.is_admin = 1 AND u.active = 1 AND u.deleted = 0
           AND u.mobile IS NOT NULL AND u.mobile <> ''
         ORDER BY u.id"
    );
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mobile) {
        $normalized = normalize_msisdn((string)$mobile);
        if ($normalized !== null) $out[] = $normalized;
    }
    return array_values(array_unique($out));
}
