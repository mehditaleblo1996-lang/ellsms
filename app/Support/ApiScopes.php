<?php
/**
 * ELLSMS — the centralized public API scope catalog (Phase 12).
 *
 * Mirrors app/Support/Permissions.php's own role (Phase 7): a single source of truth for every
 * scope string an API key can be granted, so app/Api/* and app/ApiKeys.php never compare against a
 * bare string literal. This is a DELIBERATELY separate layer from Permissions — Invariant D/STEP 10:
 * organization RBAC (Permissions::API_KEYS_MANAGE) controls WHO may create/rotate/revoke a key;
 * these scopes control WHAT a key, once created, may actually call. A key with every scope granted
 * still cannot do anything a human with API_KEYS_MANAGE couldn't already ask it to do, but the two
 * checks happen at completely different times (key-creation time vs. every single API request) and
 * must never be conflated into one.
 *
 * No namespace, no autoloader — same convention as Permissions, loaded via require_once from
 * app/bootstrap.php.
 */

declare(strict_types=1);

final class ApiScopes
{
    public const MESSAGES_SEND  = 'messages:send';
    public const MESSAGES_READ  = 'messages:read';
    public const BULK_WRITE     = 'bulk:write';
    public const BULK_READ      = 'bulk:read';
    public const CONTACTS_READ  = 'contacts:read';
    public const CONTACTS_WRITE = 'contacts:write';
    public const BALANCE_READ   = 'balance:read';
    public const WEBHOOKS_READ  = 'webhooks:read';
    public const WEBHOOKS_WRITE = 'webhooks:write';

    /** Every scope constant this class defines — never hand-maintained twice (mirrors Permissions::all()). */
    public static function all(): array
    {
        return [
            self::MESSAGES_SEND, self::MESSAGES_READ,
            self::BULK_WRITE, self::BULK_READ,
            self::CONTACTS_READ, self::CONTACTS_WRITE,
            self::BALANCE_READ,
            self::WEBHOOKS_READ, self::WEBHOOKS_WRITE,
        ];
    }

    /** True only for a string that exactly matches a cataloged scope — fail closed for anything else (Invariant D). */
    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Normalizes a caller-supplied scope list at key-creation/rotation time (STEP 11): validates
     * every entry against the catalog (an unknown scope is rejected outright, never silently
     * dropped — the whole request fails so the caller notices), then de-duplicates. Returns null on
     * any invalid entry or an empty result; callers must treat null as a hard validation failure.
     */
    public static function normalize(array $requested): ?array
    {
        if (!$requested) {
            return null;
        }
        $out = [];
        foreach ($requested as $scope) {
            if (!is_string($scope) || !self::isValid($scope)) {
                return null;
            }
            $out[$scope] = true;
        }
        return array_keys($out);
    }
}
