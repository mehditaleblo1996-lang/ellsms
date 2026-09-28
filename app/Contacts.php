<?php
/**
 * ellsms_contacts writes shared by public/contacts.php and the public API (/api/v1/contacts).
 *
 * TD-024: a contact is unique per (user_id, mobile, group_name) —
 * db/migrations/2026_09_28_contacts_unique.sql adds that UNIQUE key. Inserting a row that already
 * exists is a no-op here instead of a second copy or a PDO exception, so re-importing the same
 * list leaves the stored list unchanged.
 */

declare(strict_types=1);

/**
 * @return bool true when a new row was stored, false when this contact already existed.
 */
function contact_insert(PDO $db, int $userId, ?int $organizationId, string $name, string $mobile, string $group): bool {
    // `id = id` touches nothing, so a duplicate reports 0 affected rows (PDO's default
    // MYSQL_ATTR_FOUND_ROWS=false) and a real insert reports 1.
    $st = $db->prepare(
        'INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $st->execute([$userId, $organizationId, $name, $mobile, $group]);
    return $st->rowCount() === 1;
}

/** The id of the existing row a contact_insert() duplicate collided with, or null. */
function contact_find_id(PDO $db, int $userId, string $mobile, string $group): ?int {
    $st = $db->prepare('SELECT id FROM ellsms_contacts WHERE user_id = ? AND mobile = ? AND group_name = ? ORDER BY id LIMIT 1');
    $st->execute([$userId, $mobile, $group]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** True for a MySQL duplicate-key violation (SQLSTATE 23000 / error 1062). */
function contact_is_duplicate_key(PDOException $e): bool {
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}
