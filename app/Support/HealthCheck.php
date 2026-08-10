<?php
/**
 * ELLSMS — dependency checks backing public/health.php and
 * public/health-ready.php.
 *
 * Every check returns a plain bool. Callers turn that into JSON; nothing
 * here ever returns — or lets escape into a response — an exception
 * message, a hostname, a credential, or a version string. Per the
 * project's health-endpoint policy, only "ok"/"error" may ever reach an
 * HTTP response; the full exception always still goes to Logger for
 * internal investigation.
 */

declare(strict_types=1);

final class HealthCheck
{
    private function __construct() {}

    /** PHP itself being able to run this is already proven by the fact that we got here. */
    public static function php(): bool
    {
        return true;
    }

    public static function database(): bool
    {
        try {
            db()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            Logger::error('health.database_check_failed', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Bare TCP/TLS connectivity to the configured backend messaging API.
     * Deliberately ignores the actual HTTP status the base URL returns
     * (a 404/405 from hitting a non-endpoint path still proves DNS, TCP,
     * and TLS all work) — only a curl-level failure (timeout, DNS
     * failure, connection refused, handshake failure) counts as
     * unreachable. An unconfigured base URL is reported unreachable too,
     * without ever revealing whether/what URL is configured.
     */
    public static function backendApi(): bool
    {
        try {
            // setting() itself queries the DB to populate its cache, so
            // it must be inside this try too — an unreachable DB must
            // not crash this check, only make it correctly report false.
            $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
            if ($base === '') {
                return false;
            }
            $ch = curl_init($base);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            $errNo = curl_errno($ch);
            curl_close($ch);
            if ($errNo !== 0) {
                Logger::warning('health.backend_api_unreachable', ['curl_errno' => $errNo]);
            }
            return $errNo === 0;
        } catch (\Throwable $e) {
            Logger::error('health.backend_api_check_failed', ['exception' => $e]);
            return false;
        }
    }
}
