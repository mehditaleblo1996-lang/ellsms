<?php
/**
 * ELLSMS public API — GET /api/v1/me, GET /api/v1/organization (Phase 12, STEP 2).
 */

declare(strict_types=1);

function api_handle_me(array $ctx): void {
    $principal = $ctx['principal'];
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    ApiResponse::success(200, [
        'api_key_id'      => $principal['api_key_id'],
        'key_prefix'      => $principal['key_prefix'],
        'environment'     => $principal['environment'],
        'scopes'          => $principal['scopes'],
        'organization_id' => $principal['organization_id'],
        'acting_user'     => $owner ? [
            'id'       => (int)$owner['id'],
            'username' => $owner['username'],
        ] : null,
    ]);
}

function api_handle_organization(array $ctx): void {
    $principal = $ctx['principal'];
    $st = db()->prepare('SELECT id, name, slug, status FROM ellsms_organizations WHERE id = ?');
    $st->execute([$principal['organization_id']]);
    $org = $st->fetch();
    if (!$org) {
        // Cannot actually happen (api_key_authenticate() already re-validated the organization
        // exists and isn't disabled), but fail closed rather than emit a null body if it somehow did.
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Organization not found.');
        return;
    }
    ApiResponse::success(200, [
        'id'     => (int)$org['id'],
        'name'   => $org['name'],
        'slug'   => $org['slug'],
        'status' => $org['status'],
    ]);
}
