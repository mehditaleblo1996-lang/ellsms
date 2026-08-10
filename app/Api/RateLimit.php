<?php
/**
 * ELLSMS public API — rate limiting (Phase 12, STEP 14).
 *
 * Built entirely on app/rate_limit.php's existing DB-backed sliding-window counter — no Redis, no
 * new limiter mechanism, per this phase's own explicit instruction. Three independent dimensions,
 * same "combine multiple, never rely on just one" philosophy rate_limit.php's own docblock already
 * establishes for login/2FA:
 *   - per API key   (sustained + a short burst window)
 *   - per organization (a higher ceiling, catches one org spraying requests across many keys)
 *   - per source IP (only ever the trusted-proxy-aware client_ip() — forging X-Forwarded-For from
 *     an untrusted peer cannot move which bucket a request lands in, see app/bootstrap.php)
 *
 * Checked in api_key_id-gated order (see api_rate_limit_check()) so an UNAUTHENTICATED request
 * (bad/missing/revoked key) never touches the per-key or per-organization buckets at all — it can
 * only ever be limited by IP. This is deliberate: it's what makes "a revoked key doesn't consume the
 * active key's quota" true by construction, not by a special case.
 */

declare(strict_types=1);

function api_rate_limit_per_minute(): int {
    return rate_limit_config('API_RATE_LIMIT_PER_MINUTE', 60);
}

function api_rate_limit_burst(): int {
    return rate_limit_config('API_RATE_LIMIT_BURST', 15);
}

/**
 * Phase 13 (STEP 23): the EFFECTIVE per-key rate is the smaller of the system-wide configured
 * ceiling and the organization's plan limit — a plan can only ever LOWER the global safety cap,
 * never raise it above what the operator configured (STEP 23's explicit "do not remove global
 * safety caps"). A plan that doesn't constrain API rate at all simply inherits the system value.
 */
function api_effective_rate_limit(?array $principal): int {
    $systemLimit = api_rate_limit_per_minute();
    if ($principal === null) {
        return $systemLimit;
    }
    return entitlement_effective_cap((int)$principal['organization_id'], Limits::API_REQUESTS_PER_MINUTE, $systemLimit);
}

/**
 * Returns ['ok'=>true] or ['ok'=>false,'retry_after'=>int]. $principal is null for a request that
 * hasn't authenticated yet (or failed to) — such a request is only ever IP-limited.
 */
function api_rate_limit_check(?array $principal): array {
    $ipBucket = rate_limit_bucket('api', 'ip', client_ip());
    // The IP ceiling is deliberately generous (many legitimate keys can share one egress IP behind
    // NAT/a corporate proxy) — its job is catching a single source hammering the endpoint, not
    // enforcing the real per-key budget, which happens below once a key is known.
    if (!rate_limit_hit($ipBucket, api_rate_limit_per_minute() * 10, 60)) {
        return ['ok' => false, 'retry_after' => 60];
    }

    if ($principal === null) {
        return ['ok' => true];
    }

    // Plan-aware from here down. Resolved once so a plan change takes effect on the very next
    // request (STEP 23: "test plan changes taking effect promptly") — there is no cached rate.
    $effectiveRate = api_effective_rate_limit($principal);

    $burstBucket = rate_limit_bucket('api', 'key_burst', (string)$principal['api_key_id']);
    // The burst window is also bounded by the plan — a plan whose whole minute is smaller than the
    // configured burst allowance must not let a client spend it all in one 10-second window.
    if (!rate_limit_hit($burstBucket, min(api_rate_limit_burst(), $effectiveRate), 10)) {
        return ['ok' => false, 'retry_after' => 10];
    }

    $keyBucket = rate_limit_bucket('api', 'key', (string)$principal['api_key_id']);
    if (!rate_limit_hit($keyBucket, $effectiveRate, 60)) {
        return ['ok' => false, 'retry_after' => 60];
    }

    $orgBucket = rate_limit_bucket('api', 'org', (string)$principal['organization_id']);
    if (!rate_limit_hit($orgBucket, $effectiveRate * 5, 60)) {
        return ['ok' => false, 'retry_after' => 60];
    }

    return ['ok' => true];
}
