<?php
/**
 * ELLSMS public API — /api/v1/contacts CRUD (Phase 12, STEP 23).
 *
 * Same ellsms_contacts table public/contacts.php already uses, but scoped strictly by
 * organization_id (never the legacy "organization_id IS NULL AND user_id = ?" fallback
 * contacts.php's web UI still honors for pre-tenant-backfill rows) — an API key only ever exists
 * for an organization that already exists, so there is no legacy-row case to fall back to here, and
 * keeping the API's own scoping rule simpler/stricter than the web UI's is a deliberate, safe choice
 * (STEP 23: never expose another organization's contact by ID manipulation, Invariant B).
 */

declare(strict_types=1);

const API_CONTACTS_DEFAULT_LIMIT = 50;
const API_CONTACTS_MAX_LIMIT = 200;

function api_contacts_pagination(array $query): array {
    $limit = isset($query['limit']) && ctype_digit((string)$query['limit']) ? (int)$query['limit'] : API_CONTACTS_DEFAULT_LIMIT;
    $limit = max(1, min(API_CONTACTS_MAX_LIMIT, $limit));
    $after = isset($query['after']) && ctype_digit((string)$query['after']) ? (int)$query['after'] : 0;
    return [$limit, $after];
}

function api_handle_contacts_list(array $ctx): void {
    $principal = $ctx['principal'];
    [$limit, $after] = api_contacts_pagination($ctx['query']);

    $st = db()->prepare('SELECT id, name, mobile, group_name, created_at FROM ellsms_contacts WHERE organization_id = ? AND id > ? ORDER BY id LIMIT ?');
    $st->bindValue(1, $principal['organization_id'], PDO::PARAM_INT);
    $st->bindValue(2, $after, PDO::PARAM_INT);
    $st->bindValue(3, $limit + 1, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $nextCursor = $hasMore ? (string)end($rows)['id'] : null;

    ApiResponse::success(200, array_map(static fn(array $r) => [
        'id' => (string)$r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'],
        'group' => $r['group_name'], 'created_at' => $r['created_at'],
    ], $rows), ['next_cursor' => $nextCursor, 'limit' => $limit]);
}

function api_handle_contacts_create(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    $mobile = normalize_msisdn((string)($body['mobile'] ?? ''));
    if ($mobile === null) {
        ApiResponse::validationFailed(['mobile' => ['invalid_format']]);
        return;
    }
    $name = is_string($body['name'] ?? null) ? mb_strimwidth(trim($body['name']), 0, 160, '') : '';
    $group = is_string($body['group'] ?? null) ? mb_strimwidth(trim($body['group']), 0, 160, '') : '';

    db()->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
        ->execute([$principal['created_by_user_id'], $principal['organization_id'], $name, $mobile, $group]);
    $id = (string)db()->lastInsertId();

    ApiResponse::success(201, ['id' => $id, 'name' => $name, 'mobile' => $mobile, 'group' => $group]);
}

function api_contacts_find(int $organizationId, string $id): ?array {
    if (!ctype_digit($id)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM ellsms_contacts WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$id, $organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

function api_handle_contacts_get(array $ctx): void {
    $row = api_contacts_find($ctx['principal']['organization_id'], $ctx['params']['id'] ?? '');
    if (!$row) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Contact not found.');
        return;
    }
    ApiResponse::success(200, [
        'id' => (string)$row['id'], 'name' => $row['name'], 'mobile' => $row['mobile'],
        'group' => $row['group_name'], 'created_at' => $row['created_at'],
    ]);
}

function api_handle_contacts_update(array $ctx): void {
    $principal = $ctx['principal'];
    $row = api_contacts_find($principal['organization_id'], $ctx['params']['id'] ?? '');
    if (!$row) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Contact not found.');
        return;
    }
    $body = $ctx['body'];
    $sets = [];
    $params = [];
    if (array_key_exists('mobile', $body)) {
        $mobile = normalize_msisdn((string)$body['mobile']);
        if ($mobile === null) {
            ApiResponse::validationFailed(['mobile' => ['invalid_format']]);
            return;
        }
        $sets[] = 'mobile = ?';
        $params[] = $mobile;
    }
    if (array_key_exists('name', $body)) {
        $sets[] = 'name = ?';
        $params[] = is_string($body['name']) ? mb_strimwidth(trim($body['name']), 0, 160, '') : '';
    }
    if (array_key_exists('group', $body)) {
        $sets[] = 'group_name = ?';
        $params[] = is_string($body['group']) ? mb_strimwidth(trim($body['group']), 0, 160, '') : '';
    }
    if ($sets) {
        $params[] = $row['id'];
        db()->prepare('UPDATE ellsms_contacts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }
    $updated = api_contacts_find($principal['organization_id'], (string)$row['id']);
    ApiResponse::success(200, [
        'id' => (string)$updated['id'], 'name' => $updated['name'], 'mobile' => $updated['mobile'], 'group' => $updated['group_name'],
    ]);
}

function api_handle_contacts_delete(array $ctx): void {
    $principal = $ctx['principal'];
    $row = api_contacts_find($principal['organization_id'], $ctx['params']['id'] ?? '');
    if (!$row) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Contact not found.');
        return;
    }
    db()->prepare('DELETE FROM ellsms_contacts WHERE id = ?')->execute([$row['id']]);
    ApiResponse::success(200, ['id' => (string)$row['id'], 'deleted' => true]);
}
