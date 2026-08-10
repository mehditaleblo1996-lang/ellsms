<?php
/**
 * ELLSMS — post-deploy smoke test (Phase 10, STEP 36).
 *
 * Non-destructive: never sends a real SMS, never creates a real payment transaction, never
 * mutates data. Makes real HTTP requests against a RUNNING deployment (unlike every other Phase 10
 * operational command, which introspects local config/DB directly) — this is deliberately the one
 * check that proves the actual deployed web server is answering correctly, not just that the
 * config/code would be correct if served.
 *
 * Usage:
 *   php cron/smoke-test.php https://sms.example.com
 *   SMOKE_TEST_BASE_URL=https://sms.example.com php cron/smoke-test.php
 *   php cron/smoke-test.php --json https://sms.example.com
 */
require_once __DIR__ . '/../app/bootstrap.php';

$args = array_values(array_filter($argv ?? [], static fn($a) => $a !== $argv[0] && $a !== '--json'));
$json = in_array('--json', $argv ?? [], true);
$baseUrl = rtrim($args[0] ?? env('SMOKE_TEST_BASE_URL', app_url()) ?? '', '/');

if ($baseUrl === '') {
    fwrite(STDERR, "Usage: php cron/smoke-test.php <base-url>  (or set SMOKE_TEST_BASE_URL / APP_URL)\n");
    exit(2);
}

/** @return array{http: int, body: string, headers: string, ok: bool} */
function smoke_fetch(string $url, int $timeoutSeconds = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false, // redirects are meaningful signals here, not noise to hide
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['http' => 0, 'body' => '', 'headers' => '', 'ok' => false];
    }
    return ['http' => $http, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize), 'ok' => $errno === 0];
}

$results = [];
$failures = 0;

function record(array &$results, int &$failures, string $name, bool $pass, string $detail): void {
    $results[$name] = ['pass' => $pass, 'detail' => $detail];
    if (!$pass) $failures++;
}

// 1. Liveness
$health = smoke_fetch($baseUrl . '/health.php');
record($results, $failures, 'liveness (/health.php)', $health['http'] === 200, "http={$health['http']}");

// 2. Readiness (informational -- backend API being down shouldn't fail the whole smoke test,
// since readiness is explicitly designed to report that degraded state rather than hide it)
$ready = smoke_fetch($baseUrl . '/health-ready.php');
$readyOk = in_array($ready['http'], [200, 503], true);
record($results, $failures, 'readiness (/health-ready.php)', $readyOk, "http={$ready['http']}" . ($ready['http'] === 503 ? ' (degraded -- check backend_api connectivity)' : ''));

// 3. No debug leakage on either health response
$noLeak = !preg_match('/Fatal error|Stack trace|<b>Warning<\/b>|BACKEND_DB_PASS|BACKEND_SERVICE_SECRET/i', $health['body'] . $ready['body']);
record($results, $failures, 'no debug/secret leakage on health endpoints', $noLeak, $noLeak ? 'clean' : 'response body contains a suspicious string -- inspect manually, do not trust this deployment');

// 4. Login page reachable -- a fresh/empty install legitimately redirects /login.php to
// /bootstrap-admin.php (no ELLSMS admin exists yet, public/login.php's own first check), so a 302
// there is a healthy signal too, not just a plain 200.
$login = smoke_fetch($baseUrl . '/login.php');
$loginLocation = '';
if (preg_match('/^Location:\s*(.+)$/mi', $login['headers'], $m)) {
    $loginLocation = trim($m[1]);
}
$loginOk = $login['http'] === 200 || ($login['http'] === 302 && str_contains($loginLocation, 'bootstrap-admin.php'));
record($results, $failures, 'login page reachable', $loginOk, "http={$login['http']}" . ($loginLocation !== '' ? " -> {$loginLocation}" : ''));

// 5. Protected route denies/redirects an unauthenticated request (never 200 with real content)
$protected = smoke_fetch($baseUrl . '/index.php');
$protectedOk = in_array($protected['http'], [200, 302, 303], true) && !str_contains($protected['body'], 'داشبورد');
record($results, $failures, 'protected route does not serve an authenticated page anonymously', $protectedOk, "http={$protected['http']}");

// 6. Internal tooling not web-accessible
$internal = smoke_fetch($baseUrl . '/cron/worker.php');
$internalDenied = $internal['http'] === 404 || $internal['http'] === 403 || $internal['http'] === 0;
record($results, $failures, 'internal cron/ scripts are not web-accessible', $internalDenied, "http={$internal['http']}");

// 7. .env not web-accessible
$envFile = smoke_fetch($baseUrl . '/.env');
$envDenied = $envFile['http'] === 404 || $envFile['http'] === 403 || $envFile['http'] === 0;
record($results, $failures, '.env is not web-accessible', $envDenied, "http={$envFile['http']}");

// 8. Basic security headers present on a real response
$hasHeaders = str_contains(strtolower($login['headers']), 'x-content-type-options')
    && str_contains(strtolower($login['headers']), 'content-security-policy');
record($results, $failures, 'security headers present', $hasHeaders, $hasHeaders ? 'present' : 'missing X-Content-Type-Options or CSP');

$status = $failures === 0 ? 'PASS' : 'FAIL';

if ($json) {
    echo json_encode(['base_url' => $baseUrl, 'status' => $status, 'checks' => $results], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($failures === 0 ? 0 : 1);
}

echo "ELLSMS smoke test — {$baseUrl}\n\n";
foreach ($results as $name => $r) {
    echo '  [' . ($r['pass'] ? 'PASS' : 'FAIL') . "] {$name} — {$r['detail']}\n";
}
echo "\n{$status}\n";
exit($failures === 0 ? 0 : 1);
