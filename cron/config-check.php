<?php
/**
 * ELLSMS — production configuration validation (Phase 10, STEP 3/4).
 *
 * Read-only, no DB writes. Validates the environment this process is actually running under —
 * required secrets present, no obvious placeholder/weak values in production, no dev/test-only
 * modes active when APP_ENV=production, numeric settings actually numeric. Exits non-zero only for
 * FAIL-level findings, matching cron/db-integrity-check.php's own "exit non-zero only for the
 * things that actually block a safe deploy" convention.
 *
 * Deliberately does NOT require a database connection to run — a config-check that itself needs
 * BACKEND_DB_* to already be correct to even start would be useless for catching a bad
 * BACKEND_DB_* value in the first place. It validates the shape/presence of DB config, not
 * connectivity (predeploy-check does that separately, after config-check passes).
 *
 * Usage:
 *   php cron/config-check.php
 *   php cron/config-check.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);

/** @var array<int, array{level: string, key: string, message: string}> */
$findings = [];

function finding(array &$findings, string $level, string $key, string $message): void {
    $findings[] = ['level' => $level, 'key' => $key, 'message' => $message];
}

$env = env('APP_ENV', 'production') ?? 'production';
$isProduction = $env === 'production';

/* ---------- APP_ENV / APP_DEBUG / APP_URL ---------- */

if (!in_array($env, ['production', 'staging', 'local', 'testing'], true)) {
    finding($findings, 'WARN', 'APP_ENV', "unrecognized value \"{$env}\" — expected production/staging/local/testing; treated as non-production for safety");
}
if ($isProduction && env('APP_DEBUG', '0') === '1') {
    // Not FAIL: app_debug() already hard-forces this off in production (app/bootstrap.php) — but a
    // misconfigured APP_DEBUG=1 in a production .env is still worth surfacing, since it means
    // whoever wrote that file believes debug output is showing, when it structurally cannot.
    finding($findings, 'WARN', 'APP_DEBUG', 'set to 1 with APP_ENV=production — has no effect (forced off), but likely an operator mistake worth fixing at the source');
}
$appUrl = env('APP_URL', '');
if ($isProduction && $appUrl === '') {
    finding($findings, 'WARN', 'APP_URL', 'not set — callback URL derivation falls back to request-derived URLs, which is fine behind a correctly configured proxy but less predictable than an explicit canonical URL');
} elseif ($appUrl !== '' && !preg_match('#^https?://#i', $appUrl)) {
    finding($findings, 'FAIL', 'APP_URL', "\"{$appUrl}\" does not look like a URL (must start with http:// or https://)");
}

/* ---------- Trusted proxy (Phase 10) ---------- */

$trustedProxies = trusted_proxy_ips();
if ($isProduction && !$trustedProxies) {
    finding($findings, 'WARN', 'TRUSTED_PROXY_IPS', 'not set — if this deployment runs behind a reverse proxy (the documented production topology), HTTPS detection and rate-limit IP resolution will silently stop trusting X-Forwarded-* and fall back to the direct connection, which is safe but likely not what is intended; see docs/production-hardening.md');
}
foreach ($trustedProxies as $entry) {
    $ipPart = explode('/', $entry, 2)[0];
    if (@inet_pton($ipPart) === false) {
        finding($findings, 'FAIL', 'TRUSTED_PROXY_IPS', "\"{$entry}\" is not a valid IP or CIDR range");
    }
}

/* ---------- Production/test-mode guards (STEP 4) ---------- */

if ($isProduction && env('ELLSMS_ALLOW_LOAD_TEST', '0') === '1') {
    finding($findings, 'FAIL', 'ELLSMS_ALLOW_LOAD_TEST', 'must never be enabled in production — this flag exists solely to let cron/load-test.php run against a non-"test"-named database');
}
if ($isProduction && env('WEBHOOK_ALLOW_PRIVATE_TARGETS', '0') === '1') {
    finding($findings, 'FAIL', 'WEBHOOK_ALLOW_PRIVATE_TARGETS', 'must never be enabled in production — this flag exists solely for integration-testing real webhook delivery against a local receiver; it disables SSRF protection entirely when set (app/Webhooks.php)');
}
foreach (['FAKE_BACKEND_LATENCY_MS', 'FAKE_BACKEND_LATENCY_JITTER_MS', 'FAKE_BACKEND_FAILURE_RATE', 'FAKE_BACKEND_FAILURE_MIX', 'FAKE_BACKEND_SEED'] as $fakeVar) {
    if ($isProduction && env($fakeVar, '') !== '') {
        finding($findings, 'FAIL', $fakeVar, 'a FAKE_BACKEND_* variable is set in a production environment — these only affect tests/fixtures/fake_backend_server.php and must never be present in a real deployment\'s environment');
    }
}

/* ---------- Session cookie ---------- */

$idle = session_idle_timeout_seconds();
$absolute = session_absolute_timeout_seconds();
if ($idle > $absolute) {
    finding($findings, 'WARN', 'SESSION_IDLE_TIMEOUT_SECONDS', "idle timeout ({$idle}s) exceeds absolute timeout ({$absolute}s) — the absolute limit will always fire first, making the idle setting effectively unused");
}

/* ---------- Database credentials ---------- */

$dbHost = env('BACKEND_DB_HOST', '');
$dbName = env('BACKEND_DB_NAME', '');
$dbUser = env('BACKEND_DB_USER', '');
$dbPass = env('BACKEND_DB_PASS', '');
$placeholders = ['change_me', 'changeme', 'root', 'password', 'admin', 'test'];
foreach (['BACKEND_DB_HOST' => $dbHost, 'BACKEND_DB_NAME' => $dbName, 'BACKEND_DB_USER' => $dbUser] as $key => $value) {
    if ($value === '') {
        finding($findings, 'FAIL', $key, 'not set');
    } elseif ($isProduction && in_array(strtolower($value), $placeholders, true)) {
        finding($findings, 'FAIL', $key, "\"{$value}\" looks like a placeholder/default value, not a real production credential");
    }
}
if ($isProduction && $dbPass === '') {
    finding($findings, 'FAIL', 'BACKEND_DB_PASS', 'empty in a production environment');
}
if ($isProduction && $dbName !== '' && str_contains(strtolower($dbName), 'test')) {
    finding($findings, 'WARN', 'BACKEND_DB_NAME', "\"{$dbName}\" contains \"test\" but APP_ENV=production — double-check this is really the intended database");
}

/* ---------- Backend API / HMAC ---------- */

$apiBaseUrl = env('API_BASE_URL', '');
if ($apiBaseUrl === '') {
    finding($findings, 'WARN', 'API_BASE_URL', 'not set — message sending will fail closed (BackendUnavailable) until configured, either here or via the in-panel settings page');
} elseif (!preg_match('#^https://#i', $apiBaseUrl) && $isProduction) {
    finding($findings, 'FAIL', 'API_BASE_URL', "\"{$apiBaseUrl}\" is not HTTPS — production must not send backend API requests (including HMAC-signed ones) over plain HTTP");
}
$serviceId = env('BACKEND_SERVICE_ID', '');
$serviceSecret = env('BACKEND_SERVICE_SECRET', '');
if (($serviceId === '') !== ($serviceSecret === '')) {
    finding($findings, 'WARN', 'BACKEND_SERVICE_ID/BACKEND_SERVICE_SECRET', 'only one of the pair is set — HMAC signing is a no-op unless BOTH are configured (app/Backend/ApiClient.php), so this is effectively unsigned right now');
}
$weakSecrets = ['changeme', 'change_me', 'secret', 'password', 'test', 'test123', '123456', 'default'];
if ($isProduction && $serviceSecret !== '' && in_array(strtolower($serviceSecret), $weakSecrets, true)) {
    finding($findings, 'FAIL', 'BACKEND_SERVICE_SECRET', 'is a known placeholder/weak value — set a real random secret');
}

/* ---------- Payment provider ---------- */

$merchantId = env('ZARINPAL_MERCHANT_ID', '');
if ($isProduction && $merchantId !== '' && strtolower($merchantId) === 'change_me') {
    finding($findings, 'FAIL', 'ZARINPAL_MERCHANT_ID', 'is the placeholder value from .env.example');
}
if ($isProduction && env('ZARINPAL_SANDBOX', '0') === '1') {
    finding($findings, 'WARN', 'ZARINPAL_SANDBOX', 'enabled with APP_ENV=production — real payments will be routed to ZarinPal\'s sandbox, not the live gateway');
}

/* ---------- Queue/worker numeric settings ---------- */

$numericChecks = [
    'WORKER_POLL_INTERVAL_SECONDS' => ['default' => '8', 'min' => 1],
    'WORKER_JOB_LEASE_SECONDS'     => ['default' => '300', 'min' => 1],
    'JOB_MAX_ATTEMPTS'             => ['default' => '5', 'min' => 1],
    'JOB_RETRY_BASE_SECONDS'       => ['default' => '30', 'min' => 1],
    'JOB_RETRY_MAX_SECONDS'        => ['default' => '1800', 'min' => 1],
    'WORKER_BULK_BATCH_SIZE'       => ['default' => '20', 'min' => 1],
    'RATE_LIMIT_LOGIN_MAX'         => ['default' => '10', 'min' => 1],
    'RATE_LIMIT_LOGIN_WINDOW_SECONDS' => ['default' => '900', 'min' => 1],
    'RATE_LIMIT_2FA_VERIFY_MAX'    => ['default' => '10', 'min' => 1],
    'RATE_LIMIT_2FA_VERIFY_WINDOW_SECONDS' => ['default' => '900', 'min' => 1],
    'RATE_LIMIT_2FA_RESEND_MAX'    => ['default' => '5', 'min' => 1],
    'RATE_LIMIT_2FA_RESEND_WINDOW_SECONDS' => ['default' => '3600', 'min' => 1],
    'RATE_LIMIT_API_SEND_MAX'      => ['default' => '30', 'min' => 1],
    'RATE_LIMIT_API_SEND_WINDOW_SECONDS' => ['default' => '300', 'min' => 1],
    // Phase 12 — public API / webhooks
    'API_RATE_LIMIT_PER_MINUTE'    => ['default' => '60', 'min' => 1],
    'API_RATE_LIMIT_BURST'         => ['default' => '15', 'min' => 1],
    'API_MAX_BODY_BYTES'           => ['default' => '262144', 'min' => 1024],
    'API_MAX_BULK_ITEMS'           => ['default' => '5000', 'min' => 1],
    'API_IDEMPOTENCY_TTL_HOURS'    => ['default' => '48', 'min' => 1],
    'WEBHOOK_TIMEOUT_SECONDS'      => ['default' => '10', 'min' => 1],
    'WEBHOOK_MAX_ATTEMPTS'         => ['default' => '8', 'min' => 1],
    'WEBHOOK_MAX_RESPONSE_BYTES'   => ['default' => '4096', 'min' => 256],
    // Phase 13 — billing/quota
    'SUBSCRIPTION_JOB_BATCH_SIZE'  => ['default' => '200', 'min' => 1],
    'USAGE_RESERVATION_TTL_MINUTES' => ['default' => '60', 'min' => 1],
];
foreach ($numericChecks as $key => $spec) {
    $raw = env($key, '');
    if ($raw === '') {
        continue; // unset -> code's own compiled-in default applies, nothing to validate
    }
    if (!ctype_digit($raw) || (int)$raw < $spec['min']) {
        finding($findings, 'FAIL', $key, "\"{$raw}\" is not a valid positive integer");
    }
}

$sampleRate = env('METRICS_LOG_SAMPLE_RATE', '1');
if ($sampleRate !== '' && (!is_numeric($sampleRate) || (float)$sampleRate < 0 || (float)$sampleRate > 1)) {
    finding($findings, 'FAIL', 'METRICS_LOG_SAMPLE_RATE', "\"{$sampleRate}\" must be a number between 0 and 1");
}

/* ---------- Public API / webhooks (Phase 12) ---------- */

$apiEnabled = env('API_ENABLED', '0') === '1';
if ($apiEnabled) {
    if (!in_array(env('WEBHOOK_REQUIRE_HTTPS', '1'), ['0', '1'], true)) {
        finding($findings, 'FAIL', 'WEBHOOK_REQUIRE_HTTPS', 'must be 0 or 1');
    } elseif ($isProduction && env('WEBHOOK_REQUIRE_HTTPS', '1') === '0') {
        finding($findings, 'FAIL', 'WEBHOOK_REQUIRE_HTTPS', 'disabled in production — webhook endpoints could be configured with plain-HTTP URLs, exposing the signed payload (and, if intercepted, replayable within the signature tolerance window) in transit');
    }

    $masterKeyRaw = env('WEBHOOK_MASTER_KEY', '');
    if ($masterKeyRaw === '') {
        finding($findings, 'FAIL', 'WEBHOOK_MASTER_KEY', 'not set but API_ENABLED=1 — webhook endpoint secrets cannot be encrypted/decrypted without it; webhook creation will fail closed until this is set');
    } else {
        $decoded = base64_decode($masterKeyRaw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            finding($findings, 'FAIL', 'WEBHOOK_MASTER_KEY', 'must be exactly 32 bytes, base64-encoded (e.g. `openssl rand -base64 32`) — current value does not decode to 32 bytes');
        } elseif ($isProduction && in_array($masterKeyRaw, ['change_me', 'changeme'], true)) {
            finding($findings, 'FAIL', 'WEBHOOK_MASTER_KEY', 'is a placeholder value, not a real random key');
        }
    }
} else {
    finding($findings, 'WARN', 'API_ENABLED', 'not enabled — the public API and its routes are entirely inactive on this deployment (safe default for an existing install; see docs/public-api.md to activate)');
}

/* ---------- Plans/subscriptions/quotas (Phase 13) ---------- */

if (env('SUBSCRIPTION_GRACE_DAYS', '7') !== '' && !ctype_digit((string)env('SUBSCRIPTION_GRACE_DAYS', '7'))) {
    finding($findings, 'FAIL', 'SUBSCRIPTION_GRACE_DAYS', 'must be a non-negative integer (0 is valid and means "suspend immediately when payment lapses")');
}

// BILLING_ENABLED=0 is the RECOMMENDED default for an existing install, so it deliberately produces
// NO finding — warning about the safe default would be pure noise. Only an ENABLED billing system
// has configuration that can be wrong.
if ((env('BILLING_ENABLED', '0') ?? '0') === '1') {
    // Deliberately NOT a DB query — config-check must stay runnable without a reachable database
    // (see this file's own header). Whether DEFAULT_PLAN_CODE actually matches a plan row is checked
    // by cron/subscription-integrity-check.php, which does connect.
    if ((string)env('DEFAULT_PLAN_CODE', '') === '') {
        finding($findings, 'FAIL', 'DEFAULT_PLAN_CODE', 'not set but BILLING_ENABLED=1 — a newly created organization would have no plan to fall back to');
    }
    if ((string)env('BILLING_CURRENCY', 'IRR') === '') {
        finding($findings, 'FAIL', 'BILLING_CURRENCY', 'must not be empty when billing is enabled');
    }
    finding($findings, 'WARN', 'BILLING_ENABLED', 'billing is ENABLED — confirm `make billing-backfill` has run and `make subscription-integrity-check` reports every organization on its intended plan before relying on enforcement (docs/billing-operations.md)');
}

/* ---------- Report ---------- */

$failCount = count(array_filter($findings, static fn($f) => $f['level'] === 'FAIL'));
$warnCount = count(array_filter($findings, static fn($f) => $f['level'] === 'WARN'));

if ($json) {
    echo json_encode([
        'app_env'      => $env,
        'findings'     => $findings,
        'fail_count'   => $failCount,
        'warn_count'   => $warnCount,
        'status'       => $failCount > 0 ? 'FAIL' : ($warnCount > 0 ? 'WARN' : 'PASS'),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($failCount > 0 ? 1 : 0);
}

echo "ELLSMS configuration check — APP_ENV={$env}\n\n";
if (!$findings) {
    echo "PASS — no configuration issues found.\n";
    exit(0);
}
foreach ($findings as $f) {
    echo "[{$f['level']}] {$f['key']}: {$f['message']}\n";
}
echo "\n" . ($failCount > 0
    ? "FAIL — {$failCount} blocking issue(s), {$warnCount} warning(s). Fix FAIL items before deploying.\n"
    : "WARN — {$warnCount} warning(s), no blocking issues.\n");
exit($failCount > 0 ? 1 : 0);
