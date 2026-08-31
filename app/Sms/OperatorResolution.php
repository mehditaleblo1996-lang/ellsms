<?php
/**
 * ELLSMS — operator-resolution strategy seam (issue #9, backlog).
 *
 * Audit finding: there is exactly ONE real operator-detection mechanism in this codebase,
 * sms_resolve_operator() (app/Sms/Pricing.php) — prefix matching against the admin-configured
 * ellsms_sms_operator_prefixes catalog. detect_operator() (app/bootstrap.php, used by analytics)
 * already delegates to it, falling back to a static hardcoded map only for an install whose pricing
 * tables aren't migrated yet. No parallel/duplicate detector exists to reconcile.
 *
 * This file is a NO-BEHAVIOR-CHANGE seam around that one mechanism: sms_resolve_operator()'s public
 * signature and return shape are untouched, and every existing caller (pricing, routing — issue #8,
 * analytics) keeps working identically. What changes is internal: resolution now goes through a
 * named strategy so a real MNP (mobile number portability) lookup can be added later by writing ONE
 * new function and flipping OPERATOR_RESOLUTION_STRATEGY, without touching pricing, routing, or
 * analytics at all — "future MNP lookup can be added without rewriting provider routing," per the
 * issue's own acceptance criteria.
 *
 * MNP is explicitly NOT implemented here (the issue's own decision: "MNP/operator lookup is NOT
 * required now"). operator_resolve_via_mnp() below is a stub that only exists to prove the seam is
 * real and to define the cache/failure contract a future implementation must honor — it is never
 * reachable in production: operator_resolution_strategy() refuses to select 'mnp' until a real
 * implementation replaces the stub (see its own docblock).
 */

declare(strict_types=1);

const OPERATOR_RESOLUTION_STRATEGY_PREFIX = 'prefix';
const OPERATOR_RESOLUTION_STRATEGY_MNP = 'mnp';

/** How long a resolved operator is cached per normalized MSISDN, once a real (network-calling) MNP
 *  strategy exists — defined now, before activation, per the issue's explicit acceptance criterion.
 *  The current prefix strategy doesn't need this (sms_pricing_prefix_rules() is already its own
 *  request-lifetime cache — see sms_pricing_cached()), so this constant is currently inert; it is
 *  the contract a future network-calling strategy is expected to honor, not a knob anything reads. */
function operator_resolution_cache_ttl_seconds(): int {
    return max(0, (int)(env('OPERATOR_RESOLUTION_CACHE_TTL_SECONDS', '300') ?? '300'));
}

/**
 * Which strategy actually resolves an operator right now. Defaults to 'prefix' — the only real,
 * implemented strategy. Configuring 'mnp' before a real implementation exists does NOT silently
 * pretend to support it: it logs once per process and falls back to 'prefix', exactly the
 * "prefix detection remains the fallback" acceptance criterion, so a misconfigured or
 * prematurely-flipped setting can never leave sends unrouted or resolution silently broken.
 */
function operator_resolution_strategy(): string {
    static $warned = false;
    $configured = (string)(env('OPERATOR_RESOLUTION_STRATEGY', OPERATOR_RESOLUTION_STRATEGY_PREFIX) ?? OPERATOR_RESOLUTION_STRATEGY_PREFIX);
    if ($configured === OPERATOR_RESOLUTION_STRATEGY_MNP) {
        if (!$warned) {
            Logger::warning('operator_resolution.mnp_not_implemented', [
                'note' => 'OPERATOR_RESOLUTION_STRATEGY=mnp requested but no MNP implementation exists yet (issue #9 backlog) — falling back to prefix detection.',
            ]);
            $warned = true;
        }
        return OPERATOR_RESOLUTION_STRATEGY_PREFIX;
    }
    return OPERATOR_RESOLUTION_STRATEGY_PREFIX;
}

/**
 * The seam itself: dispatches to the active strategy, and — the defined failure contract a future
 * network-calling strategy must honor — catches ANY failure from a non-prefix strategy and falls
 * back to prefix detection rather than letting a resolution failure become a send failure. Prefix
 * detection is a pure local table lookup and is not expected to throw, but the contract exists
 * uniformly so a future MNP strategy's timeout/network-error handling has an established pattern to
 * follow instead of inventing its own.
 */
function operator_resolve(string $normalizedMsisdn): array {
    $strategy = operator_resolution_strategy();
    if ($strategy === OPERATOR_RESOLUTION_STRATEGY_MNP) {
        try {
            return operator_resolve_via_mnp($normalizedMsisdn);
        } catch (Throwable $t) {
            Logger::error('operator_resolution.mnp_failed_falling_back_to_prefix', ['exception' => $t]);
            Metrics::increment('operator_resolution.fallback', 1, ['from' => 'mnp', 'to' => 'prefix']);
        }
    }
    return operator_resolve_via_prefix($normalizedMsisdn);
}

/**
 * NOT IMPLEMENTED (issue #9 backlog, by explicit decision). Exists only to prove the seam above is
 * real and reachable, and to document the shape a future implementation must return (identical to
 * operator_resolve_via_prefix()'s: operator_id/operator_code/operator_name/operator_source/
 * matched_prefix, with operator_source presumably 'mnp' instead of 'prefix'). Deliberately
 * unreachable in production — operator_resolution_strategy() never returns 'mnp' — so this throwing
 * is inert today; it becomes live only once a real caller replaces this body.
 */
function operator_resolve_via_mnp(string $normalizedMsisdn): array {
    throw new \RuntimeException('MNP operator resolution is not implemented (issue #9 backlog).');
}
