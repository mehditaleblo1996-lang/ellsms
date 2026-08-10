<?php
/**
 * ELLSMS — lightweight, database-backed rate limiting (Phase 2).
 *
 * No Redis, per explicit instruction — a plain sliding-window counter
 * over ellsms_rate_limits (db/migrations/2026_07_27_rate_limits.sql),
 * the same MySQL database every other feature already uses. This works
 * correctly even if the app is ever scaled to multiple containers,
 * unlike an in-process/APCu counter would.
 *
 * Callers combine multiple dimensions per action (IP AND account
 * identifier, checked as two separate buckets) rather than relying on
 * just one — an IP-only limit is defeated by NAT/shared networks, an
 * account-only limit is defeated by an attacker spraying many accounts.
 */

declare(strict_types=1);

/** "kind:dimension:value" — keeps every call site's bucket naming consistent. */
function rate_limit_bucket(string $action, string $dimension, string $value): string {
    return $action . ':' . $dimension . ':' . $value;
}

/**
 * Record one hit for $bucket and report whether it's still within
 * $maxAttempts over the trailing $windowSeconds. Always records the hit
 * first, even one that turns out to be over the limit, so retrying
 * doesn't let a caller "erase" earlier attempts.
 *
 * Fails OPEN (returns true / not limited) if the check itself errors —
 * most likely because this migration hasn't been applied yet on this
 * install (see db/migrations/README.md). A rate-limiter outage must
 * never be the reason every legitimate user is locked out of login;
 * the failure is still logged so the outage itself is visible.
 */
function rate_limit_hit(string $bucket, int $maxAttempts, int $windowSeconds): bool {
    try {
        $db = db();
        $db->prepare('INSERT INTO ellsms_rate_limits (bucket, created_at) VALUES (?, NOW())')
           ->execute([$bucket]);

        // Opportunistic cleanup for this one bucket, same "prune while
        // you're already here" pattern used elsewhere in this project —
        // keeps the table from growing unbounded without a separate job.
        $db->prepare('DELETE FROM ellsms_rate_limits WHERE bucket = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)')
           ->execute([$bucket, max($windowSeconds, 3600)]);

        $st = $db->prepare(
            'SELECT COUNT(*) c FROM ellsms_rate_limits WHERE bucket = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $st->execute([$bucket, $windowSeconds]);

        return (int)$st->fetch()['c'] <= $maxAttempts;
    } catch (Throwable $e) {
        Logger::error('rate_limit.check_failed', ['bucket' => $bucket, 'exception' => $e]);
        return true;
    }
}

/**
 * Best-effort real client IP. Honors a reverse proxy's X-Forwarded-For ONLY when the direct
 * connecting peer is a configured trusted proxy (`TRUSTED_PROXY_IPS`, app/bootstrap.php, Phase 10)
 * — otherwise this is trivially spoofable: any client can set X-Forwarded-For to a fresh value on
 * every request and defeat every IP-dimension rate-limit bucket outright. When trusted, the
 * RIGHTMOST entry is used, not the leftmost — X-Forwarded-For is built by each hop APPENDING the
 * peer address IT observed, so only the entry appended by our own directly-trusted proxy is
 * actually attacker-uncontrollable; anything to its left (including a value the client set before
 * ever reaching that proxy) is still attacker-supplied text carried along in the same header.
 */
function client_ip(): string {
    if (request_from_trusted_proxy()) {
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            $last = end($parts);
            if ($last !== '' && $last !== false) {
                return $last;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Env-overridable limit, with a safe compiled-in default. */
function rate_limit_config(string $envKey, int $default): int {
    return max(1, (int)(env($envKey, (string)$default)));
}
