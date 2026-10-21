<?php
/**
 * ELLSMS public API — bearer-token authentication entry point (Phase 12, STEP 12).
 *
 * Deliberately separate from the web session auth in app/bootstrap.php (Invariant C/L): this never
 * falls back to $_SESSION, never accepts a key from the query string (simply never reads one from
 * there), and re-validates every fail-closed condition (status/expiry/organization) fresh from the
 * database on every single call via api_key_authenticate() (app/ApiKeys.php) — no session, no
 * process-level cache to go stale.
 */

declare(strict_types=1);

final class ApiAuth
{
    private function __construct() {}

    /** Best-effort header read that works whether or not Apache passed HTTP_AUTHORIZATION through (see docker/Dockerfile's CGIPassAuth note). Public so callers (public/api/index.php) can distinguish "no credentials at all" from "credentials present but wrong" for audit logging, without duplicating this lookup. */
    public static function authorizationHeader(): ?string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return (string)$value;
                }
            }
        }
        return null;
    }

    /**
     * Returns the authenticated principal array (see api_key_authenticate()'s docblock for its
     * shape) or null. Deliberately never distinguishes "no header" from "wrong secret" from
     * "revoked" from "expired" in its return value — every failure is uniformly "not authenticated"
     * (STEP 42: "generic 401, not detailed sensitive status") so a caller probing for valid prefixes
     * learns nothing from the response shape.
     */
    public static function authenticate(bool $allowBasic = false): ?array
    {
        $header = self::authorizationHeader();
        if ($header === null) {
            return null;
        }
        // NEVER pass $header itself to Logger — only ever the already-authenticated principal's
        // key_prefix (not secret-bearing) is logged, by the caller, after this returns.
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return api_key_authenticate(trim($m[1]));
        }
        $key = $allowBasic ? self::basicCredentialKey($header) : null;
        return $key !== null ? api_key_authenticate($key) : null;
    }

    /**
     * The API key carried in an `Authorization: Basic` header, for callers that can only send Basic
     * auth (WebEngage's SSP settings offer Basic or Bearer): the password is the key, the username is
     * free text. A blank password falls back to the username. Only routes that opt in accept this.
     */
    public static function basicCredentialKey(string $header): ?string
    {
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($header), $m)) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false) {
            return null;
        }
        [$username, $password] = array_pad(explode(':', $decoded, 2), 2, '');
        $key = trim($password !== '' ? $password : $username);
        return $key !== '' ? $key : null;
    }
}
