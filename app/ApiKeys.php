<?php
/**
 * ELLSMS — public API key lifecycle (Phase 12).
 *
 * Mirrors app/rbac.php's split: this file is the DB-backed business logic (create/list/revoke/
 * rotate/authenticate); the RBAC gate on who may call create/revoke/rotate lives at the call site
 * (public/api-keys.php, app/Api/Handlers/Webhooks.php's key-management endpoints), exactly the same
 * "caller already checked require_permission()" convention app/wallet.php and app/rbac.php's own
 * mutators already use — this file does not re-check organization RBAC itself.
 *
 * Key format: "ellsms_{environment}_{prefix}_{secret}" — e.g.
 * "ellsms_live_a1b2c3d4e5f6_kQ3f...". `prefix` (12 hex chars, DB-indexed, NOT secret) is how
 * api_key_authenticate() finds the CANDIDATE row in O(1) before ever touching the secret; `secret`
 * (32 raw random bytes, base64url, ~256 bits of entropy) is what actually proves possession.
 * Splitting them this way avoids the alternative of scanning/hashing every active key on every
 * request just to find a match.
 *
 * Hashing decision (STEP 8, documented deliberately): secret_hash is a plain hex SHA-256 digest of
 * the secret, verified with hash_equals() — NOT password_hash()/Argon2id. This is the opposite
 * tradeoff from backend_verify_password() (app/bootstrap.php), and deliberately so: Argon2id is
 * *intentionally* slow because it defends a low-entropy, human-chosen secret (a password) against
 * offline brute force. An API secret here is 256 bits of CSPRNG output — brute-forcing it is
 * infeasible regardless of hash speed, so paying Argon2id's ~100ms+ cost on every single API
 * request (STEP 49 — this IS the hot path, unlike a login form) would be pure latency with no
 * corresponding security gain. This is the same reasoning most API-key providers (Stripe, GitHub,
 * ...) use for token verification; it is NOT used for anything human-chosen anywhere else in this
 * codebase.
 */

declare(strict_types=1);

const API_KEY_PREFIX_BYTES = 6;   // 12 hex chars
const API_KEY_SECRET_BYTES = 32;  // 256 bits

/** base64url, no padding — URL/header-safe without escaping. */
function api_key_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function api_key_generate_prefix(): string {
    return bin2hex(random_bytes(API_KEY_PREFIX_BYTES));
}

function api_key_generate_secret(): string {
    return api_key_b64url(random_bytes(API_KEY_SECRET_BYTES));
}

function api_key_hash(string $secret): string {
    return hash('sha256', $secret);
}

function api_key_format(string $environment, string $prefix, string $secret): string {
    return "ellsms_{$environment}_{$prefix}_{$secret}";
}

/**
 * Parses a raw "Authorization: Bearer ellsms_live_<prefix>_<secret>" value. Returns
 * [environment, prefix, secret] or null for anything that doesn't match the expected shape —
 * malformed input is never partially trusted (Invariant D: fail closed).
 */
function api_key_parse(string $raw): ?array {
    if (!preg_match('/^ellsms_(live|test)_([0-9a-f]{12})_([A-Za-z0-9_-]{20,80})$/', trim($raw), $m)) {
        return null;
    }
    return ['environment' => $m[1], 'prefix' => $m[2], 'secret' => $m[3]];
}

/**
 * Creates a new key for $organizationId. $scopes is validated against ApiScopes' catalog here
 * (STEP 11 — unknown scope rejected, empty set rejected) — this is the ONE place a key's scope list
 * is ever written, so this is also the only place that needs to enforce the catalog.
 *
 * Returns ['ok'=>true, 'id'=>.., 'raw_key'=>.. (SHOWN EXACTLY ONCE), 'prefix'=>..] or
 * ['ok'=>false, 'reason'=>..].
 */
function api_key_create(
    int $organizationId,
    int $actorUserId,
    string $name,
    array $scopes,
    string $environment = 'live',
    ?string $expiresAt = null
): array {
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'reason' => 'name_required'];
    }
    if (!in_array($environment, ['live', 'test'], true)) {
        return ['ok' => false, 'reason' => 'invalid_environment'];
    }
    $normalizedScopes = ApiScopes::normalize($scopes);
    if ($normalizedScopes === null) {
        return ['ok' => false, 'reason' => 'invalid_scopes'];
    }

    $prefix = api_key_generate_prefix();
    $secret = api_key_generate_secret();
    $hash   = api_key_hash($secret);

    db()->prepare(
        'INSERT INTO ellsms_api_keys
            (organization_id, name, environment, key_prefix, secret_hash, scopes_json, status, created_by_user_id, expires_at)
         VALUES (?,?,?,?,?,?,\'active\',?,?)'
    )->execute([$organizationId, $name, $environment, $prefix, $hash, json_encode($normalizedScopes), $actorUserId, $expiresAt]);
    $id = (int)db()->lastInsertId();

    Logger::info('api_key.created', ['organization_id' => $organizationId, 'api_key_id' => $id, 'key_prefix' => $prefix, 'actor_user_id' => $actorUserId, 'scopes' => $normalizedScopes]);
    audit($actorUserId, 'api_key.created', "key_id={$id} prefix={$prefix}");

    return [
        'ok'      => true,
        'id'      => $id,
        'prefix'  => $prefix,
        'raw_key' => api_key_format($environment, $prefix, $secret),
        'scopes'  => $normalizedScopes,
    ];
}

/** Every key for $organizationId (never the secret/hash) — newest first. */
function api_key_list(int $organizationId): array {
    $st = db()->prepare(
        'SELECT id, name, environment, key_prefix, scopes_json, status, created_by_user_id,
                last_used_at, expires_at, revoked_at, created_at
         FROM ellsms_api_keys WHERE organization_id = ? ORDER BY created_at DESC'
    );
    $st->execute([$organizationId]);
    return array_map(static function (array $row): array {
        $row['scopes'] = json_decode($row['scopes_json'], true) ?: [];
        unset($row['scopes_json']);
        return $row;
    }, $st->fetchAll());
}

/** Ownership-checked single-row lookup — never returns a key belonging to a different organization (Invariant B). */
function api_key_find(int $organizationId, int $keyId): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_api_keys WHERE id = ? AND organization_id = ?');
    $st->execute([$keyId, $organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Revocation takes effect promptly (Invariant F) because there is no cache layer between this
 * UPDATE and api_key_authenticate()'s own read — every authentication re-reads `status` fresh from
 * the database on every request (STEP 13: correctness over optimization, no static/process cache).
 */
function api_key_revoke(int $organizationId, int $keyId, int $actorUserId): array {
    $key = api_key_find($organizationId, $keyId);
    if (!$key) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if ($key['status'] === 'revoked') {
        return ['ok' => true, 'reason' => 'already_revoked'];
    }
    db()->prepare("UPDATE ellsms_api_keys SET status = 'revoked', revoked_at = NOW() WHERE id = ?")->execute([$keyId]);
    Logger::info('api_key.revoked', ['organization_id' => $organizationId, 'api_key_id' => $keyId, 'actor_user_id' => $actorUserId]);
    audit($actorUserId, 'api_key.revoked', "key_id={$keyId}");
    return ['ok' => true];
}

/**
 * Rotation (STEP 41): revokes the existing key immediately and issues a brand new one with the
 * same name/scopes/environment. Deliberately NO overlap/grace period — the simplest, safest
 * behavior ("do not silently preserve the old secret forever") and the one this phase ships;
 * a time-bounded overlap window is documented as a possible future enhancement in
 * docs/public-api.md, not implemented here.
 */
function api_key_rotate(int $organizationId, int $keyId, int $actorUserId): array {
    $key = api_key_find($organizationId, $keyId);
    if (!$key) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if ($key['status'] !== 'active') {
        return ['ok' => false, 'reason' => 'not_active'];
    }
    $scopes = json_decode($key['scopes_json'], true) ?: [];

    return db_transaction(function (PDO $db) use ($organizationId, $keyId, $actorUserId, $key, $scopes): array {
        $db->prepare("UPDATE ellsms_api_keys SET status = 'revoked', revoked_at = NOW() WHERE id = ?")->execute([$keyId]);
        $created = api_key_create($organizationId, $actorUserId, $key['name'], $scopes, $key['environment'], $key['expires_at']);
        if ($created['ok']) {
            Logger::info('api_key.rotated', ['organization_id' => $organizationId, 'old_api_key_id' => $keyId, 'new_api_key_id' => $created['id'], 'actor_user_id' => $actorUserId]);
            audit($actorUserId, 'api_key.rotated', "old_key_id={$keyId} new_key_id={$created['id']}");
        }
        return $created;
    });
}

/**
 * The API authentication entry point (STEP 12) — parses, looks up by prefix, verifies the secret
 * with a constant-time comparison, and re-validates every fail-closed condition fresh on every call
 * (no session, no cache — Invariant C/F). Returns the authenticated principal array or null; never
 * throws for an ordinary auth failure (a malformed/wrong/revoked/expired key), only for a genuine
 * DB failure.
 *
 * Principal shape: ['api_key_id', 'organization_id', 'scopes' (array), 'created_by_user_id',
 * 'environment', 'key_prefix'].
 */
function api_key_authenticate(string $rawAuthorizationValue): ?array {
    $parsed = api_key_parse($rawAuthorizationValue);
    if ($parsed === null) {
        return null;
    }

    $st = db()->prepare('SELECT * FROM ellsms_api_keys WHERE key_prefix = ?');
    $st->execute([$parsed['prefix']]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }

    if (!hash_equals($row['secret_hash'], api_key_hash($parsed['secret']))) {
        return null;
    }
    if ($row['status'] !== 'active') {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) <= time()) {
        return null;
    }
    if ($row['environment'] !== $parsed['environment']) {
        // A live secret can never authenticate as "test" or vice versa even if somehow guessed —
        // defense in depth, this branch is unreachable via api_key_parse()+lookup-by-prefix alone
        // since environment isn't part of the lookup key, but kept explicit rather than assumed.
        return null;
    }

    $organizationId = (int)$row['organization_id'];
    $orgStatus = organization_status($organizationId);
    if ($orgStatus === null || $orgStatus === 'disabled') {
        return null;
    }

    // Best-effort bookkeeping — never let a failure here (e.g. a read-only replica momentarily
    // rejecting a write) block an otherwise-valid authentication.
    try {
        db()->prepare('UPDATE ellsms_api_keys SET last_used_at = NOW(), last_used_ip_hash = ? WHERE id = ?')
            ->execute([hash('sha256', client_ip()), $row['id']]);
    } catch (Throwable $e) {
        Logger::warning('api_key.last_used_update_failed', ['api_key_id' => $row['id'], 'exception' => $e]);
    }

    return [
        'api_key_id'        => (int)$row['id'],
        'organization_id'   => $organizationId,
        'organization_status' => $orgStatus,
        'scopes'            => json_decode($row['scopes_json'], true) ?: [],
        'created_by_user_id' => (int)$row['created_by_user_id'],
        'environment'       => $row['environment'],
        'key_prefix'        => $row['key_prefix'],
    ];
}

/** True if $principal (from api_key_authenticate()) carries $scope. Fail closed for a missing/malformed principal. */
function api_key_has_scope(?array $principal, string $scope): bool {
    return $principal !== null && in_array($scope, $principal['scopes'] ?? [], true);
}
