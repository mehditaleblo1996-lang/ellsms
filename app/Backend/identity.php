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
 * An account's balance is its ELLSMS wallet (ellsms_wallet_accounts.available_balance): `credit`
 * reads it. user_.currentcredit is only a copy ELLSMS writes for the backend
 * (app/Backend/credit_projection.php); it is read as `legacy_credit` (`+ 0`: a FLOAT column's full
 * stored value rather than its 6-digit display), and as `credit` only for an
 * account whose wallet doesn't exist yet, because the wallet is opened with exactly that amount
 * (wallet_ensure_account(), app/wallet.php). Selected from `user_ u LEFT JOIN
 * ellsms_wallet_accounts w`.
 */
const BACKEND_CREDIT_COLUMNS = 'COALESCE(w.available_balance, CAST(ROUND(u.currentcredit + 0) AS SIGNED)) AS credit, u.currentcredit + 0 AS legacy_credit';

/**
 * How far user_.currentcredit may legitimately sit from the wallet balance $amount it was written
 * from. The backend platform declares the column FLOAT on some installs: a single-precision float
 * holds only ~7 significant digits, so 6,876,385,000 is stored as 6,876,384,768 (and shown as
 * 6.87638e9). Such a copy is as exact as the column allows and is not drift. 0 for an integer or
 * decimal column, which stores the balance exactly.
 */
function backend_legacy_credit_tolerance(int $amount): int {
    static $isFloat = null;
    if ($isFloat === null) {
        $st = db()->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_' AND COLUMN_NAME = 'currentcredit'");
        $isFloat = strtolower((string)$st->fetchColumn()) === 'float';
    }
    // A float's spacing is 2^-23 of its magnitude; a nearest-rounded value is within half of that.
    return $isFloat ? (int)ceil(abs($amount) / 2 ** 23) : 0;
}

/**
 * user_.currentcredit of one account: the opening balance of a wallet created for it
 * (wallet_ensure_account()). 0 for an id with no user_ row.
 */
function backend_user_legacy_credit(PDO $db, int $userId): int {
    $st = $db->prepare('SELECT CAST(ROUND(currentcredit + 0) AS SIGNED) FROM user_ WHERE id = ?');
    $st->execute([$userId]);
    $credit = $st->fetchColumn();
    return $credit === false || $credit === null ? 0 : max(0, (int)$credit);
}

function backend_find_user_by_id(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare(
        'SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.email, u.mobile,
                u.active, u.deleted, ' . BACKEND_CREDIT_COLUMNS . ',
                m.panel_access, m.is_admin, m.originator, m.twofa_enabled
         FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id
         LEFT JOIN ellsms_wallet_accounts w ON w.user_id = u.id
         WHERE u.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * The backend domain an account belongs to — a reseller's sub-accounts are created in the
 * reseller's own domain. Null when the backend schema has no domain_id column on user_ or the
 * account has none.
 */
function backend_user_domain_id(int $userId): ?int {
    try {
        $st = db()->prepare('SELECT domain_id FROM user_ WHERE id = ?');
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id !== false && $id !== null && (int)$id > 0 ? (int)$id : null;
    } catch (PDOException) {
        return null;
    }
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
        "SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, " . BACKEND_CREDIT_COLUMNS . ", u.active, u.deleted
         FROM user_ u LEFT JOIN ellsms_wallet_accounts w ON w.user_id = u.id
         WHERE u.id IN ({$placeholders})"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    return $out;
}

/**
 * The reseller panel's customer list (app/ResellerPanel.php): identity, contact details and balance
 * of the given accounts, keyed by id.
 */
function backend_user_details_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.email, u.mobile,
                " . BACKEND_CREDIT_COLUMNS . ", u.active, u.deleted
         FROM user_ u LEFT JOIN ellsms_wallet_accounts w ON w.user_id = u.id
         WHERE u.id IN ({$placeholders})"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    return $out;
}

/**
 * Of $userIds, those whose username, first or last name, email or mobile contains $needle. Bounded
 * by the caller's id list (a reseller's own customers), so it never scans the backend's whole table.
 *
 * @return list<int>
 */
function backend_user_ids_matching(array $userIds, string $needle): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    $needle = trim($needle);
    if (!$ids || $needle === '') return $ids;
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT id FROM user_ WHERE id IN ({$placeholders})
           AND (username LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ? OR email LIKE ? OR mobile LIKE ?)"
    );
    $st->execute([...$ids, $like, $like, $like, $like, $like, $like]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
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
