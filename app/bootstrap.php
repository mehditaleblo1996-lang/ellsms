<?php
/**
 * ELLSMS — Smart SMS Panel
 * Bootstrap: environment, database, session, helpers.
 *
 * IMPORTANT: ELLSMS shares its MySQL database with the backend SMS
 * platform. It does NOT own or migrate the platform's own tables
 * (user_, outbound_message, inbound_message, domain, customer, role,
 * access) — it only reads/writes those, plus its own supplementary
 * tables prefixed `ellsms_` (see db/ellsms_extra.sql).
 */

declare(strict_types=1);

define('ELLSMS_VERSION', '3.0.0');
define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/Support/Logger.php';
require_once __DIR__ . '/Support/Metrics.php';
require_once __DIR__ . '/Support/AppException.php';
require_once __DIR__ . '/Support/ErrorHandler.php';
require_once __DIR__ . '/Support/HealthCheck.php';
// Phase 8 — backend service-boundary adapters (Invariant A/B): loaded before authorization.php/
// wallet.php since both call into these. See docs/service-boundaries.md.
require_once __DIR__ . '/Backend/identity.php';
require_once __DIR__ . '/Backend/credit_projection.php';
require_once __DIR__ . '/Backend/messages.php';
require_once __DIR__ . '/Backend/ApiClient.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/jobqueue.php';
require_once __DIR__ . '/MessageClass.php';
require_once __DIR__ . '/QueueFairness.php';
require_once __DIR__ . '/Slo.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/rbac.php';
// Platform-admin support impersonation (docs/admin-impersonation.md). Loaded after the identity,
// tenant and RBAC primitives it re-validates against, and before anything that renders a page.
require_once __DIR__ . '/impersonation.php';
// Phase 12 — public API/webhooks (docs/public-api.md, docs/webhooks.md). Loaded after
// rbac.php/wallet.php/jobqueue.php since ApiKeys/Webhooks build on organization_status(),
// db_transaction(), and the existing job-retry backoff math rather than duplicating any of it.
require_once __DIR__ . '/Support/ApiScopes.php';
require_once __DIR__ . '/Support/WebhookEvents.php';
require_once __DIR__ . '/ApiKeys.php';
require_once __DIR__ . '/Idempotency.php';
require_once __DIR__ . '/Webhooks.php';
// Phase 13 — plans/subscriptions/entitlements/quotas (docs/plans-and-entitlements.md). Loaded last
// because Entitlements.php builds on Billing.php, which builds on tenant.php's organization model
// and db_transaction(); nothing above depends on either of these two.
require_once __DIR__ . '/Support/Entitlements.php';
require_once __DIR__ . '/Support/Limits.php';
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/Entitlements.php';
// Financial-commerce continuation (FIN-1) — the immutable invoice layer sitting on top of
// ellsms_payments. Loaded after Billing.php because it calls billing_currency().
require_once __DIR__ . '/Financial.php';
// FIN-2/FIN-3 — generic payment gateway abstraction (wraps the existing, unchanged zarinpal.php)
// plus the fake/sandbox adapter. Loaded globally (not per-entrypoint like the old direct
// zarinpal.php require) since payment_gateway_name()/payment_gateway_create()/
// payment_gateway_verify() are now the intended call surface everywhere a payment is created or
// verified, including cron/payments-reconcile.php and the test suite.
require_once __DIR__ . '/Payment/PaymentGateway.php';
// SMS pricing — admin-managed operators/providers/routes/prices and the one resolution pipeline
// every send and every preview prices through (docs/sms-pricing.md). Loaded before the estimator
// because the estimator composes it; it in turn only needs setting()/db()/Logger/Metrics, all above.
require_once __DIR__ . '/Sms/OperatorResolution.php';
require_once __DIR__ . '/Sms/Pricing.php';
require_once __DIR__ . '/Sms/ProviderHealth.php';
require_once __DIR__ . '/BulkCancellation.php';
require_once __DIR__ . '/BulkArchive.php';
require_once __DIR__ . '/Reports/SendDimensionLog.php';
// SMS gateway connectors — admin-configurable provider send/status APIs
// (docs/sms-gateway-connectors.md). Strict load order: the secret vault first (the connector engine
// resolves secret-backed parameters at compile time), then the safety engine that validates and
// compiles admin configuration, then the versioned cache that owns compilation, then the transport
// that consumes a compiled connector. Each layer depends only on the ones above it.
require_once __DIR__ . '/Sms/GatewaySecrets.php';
require_once __DIR__ . '/Sms/GatewayConnector.php';
require_once __DIR__ . '/Sms/GatewayCache.php';
require_once __DIR__ . '/Sms/GatewayTransport.php';
require_once __DIR__ . '/Sms/GatewayStatus.php';
// Cost preview — read-only estimator built on top of the segmentation, pricing, wallet, and quota
// primitives above; loaded last because it composes all four and owns none of them.
require_once __DIR__ . '/Cost/MessageCostEstimator.php';
// Delivery reporting — read-only presentation of the message lifecycle. Composes the segmentation
// engine (sms_parts), the pricing snapshots and the gateway status records; owns none of them and
// writes nothing.
require_once __DIR__ . '/Reports/MessageDetail.php';
require_once __DIR__ . '/Reports/ExportJobs.php';
// Customer/organization profile — personal profile, company legal profile, address, notification
// preferences and private documents (docs/customer-profile.md). Loaded last: it composes tenant.php
// (organization membership), rbac.php (settings.manage) and audit(), and owns none of them.
require_once __DIR__ . '/Profile.php';
// KYC review workflow — state machine, per-document review, submission eligibility, feature gating
// (docs/profile-kyc.md). Composes Profile.php (document storage/catalogs) and audit(); owns neither.
require_once __DIR__ . '/Kyc.php';
require_once __DIR__ . '/AllowedIps.php';
// Large-scale SMS import pipeline (docs/large-import-architecture.md). Loaded last because it
// composes the file reader, pricing, wallet, quota, and bulk job primitives above.
require_once __DIR__ . '/import_reader.php';
require_once __DIR__ . '/import.php';
// The two-pass analyze/insert worker itself (Phase 10). Previously reachable ONLY from
// cron/import-worker.php's own require — every other caller (an admin re-running analysis, an
// integration test, a future feature) had no path to it at all. Loaded here so it is available
// everywhere app/backend.php is, exactly like every other worker module in this chain.
require_once __DIR__ . '/import_worker.php';

/* ---------- Environment ---------- */
function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* ---------- App environment (APP_ENV / APP_DEBUG / APP_URL) ----------
 * These govern how the app itself behaves (error visibility, the
 * canonical base URL) rather than a specific integration, so — unlike
 * most other config in this file — they are read from the environment
 * only, never overridable from ellsms_settings.
 */
function app_env(): string {
    return env('APP_ENV', 'production') ?? 'production';
}

/**
 * Hard rule, not just a config default: debug output is NEVER shown
 * when APP_ENV=production, even if APP_DEBUG=1 was also (mis)set —
 * production must never use debug output, full stop, regardless of
 * operator error. Every other environment name (local, staging, ...)
 * honors APP_DEBUG normally.
 */
function app_debug(): bool {
    $requested = env('APP_DEBUG', '0') === '1';
    if ($requested && app_env() === 'production') {
        static $warned = false;
        if (!$warned) {
            $warned = true; // log this contradiction once per process, not on every call
            Logger::warning('app.debug_forced_off_in_production', [
                'reason' => 'APP_DEBUG=1 is ignored whenever APP_ENV=production',
            ]);
        }
        return false;
    }
    return $requested;
}

/** Canonical base URL with no trailing slash, or '' if not configured. */
function app_url(): string {
    return rtrim((string)env('APP_URL', ''), '/');
}

/**
 * Deployed build identifier — operational metadata for logs, the health
 * endpoints, and the panel footer. Falls back to the baked-in
 * ELLSMS_VERSION constant when APP_VERSION isn't set, so nothing needs
 * to change for installs that don't inject one; CI/CD can override it
 * with a git SHA or release tag without a source change.
 */
function app_version(): string {
    return env('APP_VERSION', ELLSMS_VERSION) ?? ELLSMS_VERSION;
}

// Registers the global exception/error/shutdown handlers (see
// app/Support/ErrorHandler.php) as early as possible — before the first
// DB connection attempt, so even a "can't reach MySQL" failure gets the
// same safe/dev-aware treatment instead of a raw stack trace. Never
// shows raw errors to a visitor unless explicitly opted into via
// APP_DEBUG=1; always logs the real exception via Logger regardless.
ErrorHandler::register();

/**
 * Phase 2 (STEP 14) — baseline security headers, applied to every page
 * that loads this file (i.e. all of them). Deliberately a COMPATIBLE
 * policy, not a maximally-strict one that would break the app: this
 * project has no CDN dependency (everything is self-hosted, checked
 * directly), but does use inline <script> blocks, inline
 * onclick/onchange/oninput handlers, and inline style="" attributes
 * widely — so script-src/style-src still allow 'unsafe-inline'. That's
 * real, disclosed remaining debt (see docs/technical-debt.md), not an
 * oversight; tightening it further means migrating those inline
 * handlers to external/nonced scripts, a larger change than this step.
 */
function send_security_headers(): void {
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN'); // legacy fallback alongside frame-ancestors below for older browsers
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "font-src 'self'; " .
        "object-src 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "frame-ancestors 'self'"
    );
    // HSTS is inert over a plain HTTP response (browsers only ever honor
    // it after already receiving it over HTTPS, per RFC 6797), so gating
    // it on request_is_https() is a cleanliness choice, not a safety
    // requirement — it would be harmless either way.
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}
send_security_headers();

// STEP 22/23: only ever blocks actual HTTP requests -- a CLI script requiring this file (every
// cron/*.php operational command in this phase, run BY the operator specifically to work during a
// maintenance window) must never be blocked by its own maintenance flag. See app/maintenance.php's
// docblock for the full per-surface policy and exemption list.
if (PHP_SAPI !== 'cli' && maintenance_mode_active() && !maintenance_mode_current_script_exempt()) {
    maintenance_mode_respond_and_exit();
}

/* ---------- Database (shared backend platform DB) ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('BACKEND_DB_HOST', 'localhost');
        $port = env('BACKEND_DB_PORT', '3306');
        $name = env('BACKEND_DB_NAME', 'change_me');
        $user = env('BACKEND_DB_USER', 'change_me');
        $pass = env('BACKEND_DB_PASS', '');
        $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo  = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Run $work inside a database transaction: begins, commits if $work
 * returns normally, rolls back and rethrows if it throws anything.
 * $work receives the same PDO instance db() would return, so it rarely
 * needs to call db() itself. Whatever $work returns is returned here.
 *
 * Safe to nest: if a db_transaction() call happens while one is already
 * open (e.g. a function using it calls another function that also uses
 * it), the inner call joins the existing transaction instead of trying
 * to start a second one — PDO has no real nested-transaction support, so
 * only the outermost call actually begins/commits/rolls back.
 *
 * This is infrastructure for later phases to adopt gradually — existing
 * manual beginTransaction()/commit()/rollBack() blocks keep working
 * unchanged; new or refactored code can call this instead.
 */
function db_transaction(callable $work) {
    $db = db();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $result = $work($db);
        if ($ownsTransaction) {
            $db->commit();
        }
        return $result;
    } catch (\Throwable $e) {
        if ($ownsTransaction) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ---------- ELLSMS settings (ellsms_settings key/value, cached) ---------- */
function setting(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT skey, svalue FROM ellsms_settings') as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    }
    $v = $cache[$key] ?? '';
    return ($v !== '' ? $v : null) ?? $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, $value]);
}

/* ---------- Password hashing (matches the backend platform's scheme) ----------
 * The connected backend hashes passwords with plain SHA-256 over the
 * UTF-8 plaintext, stored as raw bytes in user_.password — a known
 * placeholder scheme on that side, not something ELLSMS chose. ELLSMS
 * matches it exactly so both systems can authenticate the same account. */
function backend_hash_password(string $plain): string {
    return hash('sha256', $plain, true); // raw 32-byte digest, binary-safe
}

function backend_verify_password(string $plain, string $stored): bool {
    return hash_equals(backend_hash_password($plain), $stored);
}

/**
 * Phase 2 — NOT a replacement for backend_verify_password(), which
 * remains the sole authoritative check: user_.password can change on
 * the backend platform's side at any time, independent of ELLSMS, so
 * ELLSMS cannot unilaterally decide a different value is "correct."
 * See docs/security-review.md finding 4 for why a full migration away
 * from the legacy SHA-256 scheme requires coordinating with whoever
 * operates the backend platform, and can't safely happen from this
 * repository alone.
 *
 * This is supporting infrastructure only: after a successful legacy
 * verification, it opportunistically records a modern Argon2id verifier
 * for the user in ellsms_password_verifiers (db/migrations/2026_07_27_password_verifiers.sql).
 * Nothing reads that table to grant access today — the point is purely
 * to shrink the gap for a later, coordinated migration. Deliberately
 * NOT called from the legacy URL send API (url_send.html), which
 * authenticates on every single request — Argon2id is intentionally
 * expensive, and re-hashing on a high-frequency API path would add
 * real latency for no benefit login itself doesn't already get.
 */
function backend_verify_password_and_upgrade(int $userId, string $plain, string $storedLegacyHash): bool {
    if (!backend_verify_password($plain, $storedLegacyHash)) {
        return false;
    }
    try {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $verifier = password_hash($plain, $algo);
        if ($verifier !== false) {
            db()->prepare(
                'INSERT INTO ellsms_password_verifiers (user_id, verifier, algo) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE verifier = VALUES(verifier), algo = VALUES(algo)'
            )->execute([$userId, $verifier, $algo === PASSWORD_ARGON2ID ? 'argon2id' : 'bcrypt']);
        }
    } catch (Throwable $e) {
        // Best-effort — never let this block a legitimate login. Most
        // likely cause: the migration hasn't been applied yet on this
        // install (see db/migrations/README.md).
        Logger::error('auth.password_verifier_upgrade_failed', ['user_id' => $userId, 'exception' => $e]);
    }
    return true;
}

/* ---------- Trusted proxy (Phase 10) ---------- */

/**
 * IP/CIDR allowlist of reverse proxies whose `X-Forwarded-*` headers are trusted — comma-separated,
 * bare IPs and/or CIDR ranges (e.g. "10.0.0.0/8,172.20.0.5"). Empty (the default) means NO proxy is
 * trusted: `X-Forwarded-For`/`X-Forwarded-Proto` are ignored entirely and the app falls back to the
 * raw `REMOTE_ADDR` / actual TLS state — fail-closed, not fail-open. Before this existed, both
 * headers were honored unconditionally from ANY client, which meant HTTPS detection (and therefore
 * the session cookie's `secure` flag) and `client_ip()` (rate-limit bucketing) could be spoofed by
 * anyone able to reach the app directly, trivially defeating IP-based rate limits on login/2FA.
 * Any production deployment behind a reverse proxy (the documented topology — README Production
 * notes) MUST set this explicitly to restore correct HTTPS detection and client-IP resolution; see
 * docs/production-hardening.md.
 */
function trusted_proxy_ips(): array {
    // Deliberately NOT statically cached (unlike setting()) — called at most once or twice per
    // request, not a hot path, and staying uncached keeps it correctly testable across
    // putenv()-varied cases within one PHPUnit process.
    $raw = (string)env('TRUSTED_PROXY_IPS', '');
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
}

/** True if $ip falls inside $cidrOrIp — a bare IP compares exactly, "a.b.c.d/n" compares by prefix. IPv4/IPv6 both supported via inet_pton(). */
function ip_in_cidr(string $ip, string $cidrOrIp): bool {
    if (!str_contains($cidrOrIp, '/')) {
        return $ip === $cidrOrIp;
    }
    [$subnet, $maskBitsRaw] = explode('/', $cidrOrIp, 2);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $maskBits = (int)$maskBitsRaw;
    $fullBytes = intdiv($maskBits, 8);
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }
    $remainderBits = $maskBits % 8;
    if ($remainderBits === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);
    return (substr($ipBin, $fullBytes, 1) & $mask) === (substr($subnetBin, $fullBytes, 1) & $mask);
}

/** True only if the DIRECT connecting peer (REMOTE_ADDR — never a client-suppliable header) is a configured trusted proxy. */
function request_from_trusted_proxy(): bool {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($peer === '') {
        return false;
    }
    foreach (trusted_proxy_ips() as $cidrOrIp) {
        if (ip_in_cidr($peer, $cidrOrIp)) {
            return true;
        }
    }
    return false;
}

/* ---------- Session & Auth ---------- */

/**
 * True if the current request arrived over HTTPS — checks the actual connection first, then the
 * standard reverse-proxy header, but ONLY when the direct peer is a configured trusted proxy
 * (Phase 10) — otherwise a client could simply send `X-Forwarded-Proto: https` over a plain HTTP
 * connection to influence the session cookie's `secure` flag.
 */
function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!request_from_trusted_proxy()) {
        return false;
    }
    $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

const SESSION_IDLE_TIMEOUT_SECONDS_DEFAULT     = 1800;  // 30 minutes
const SESSION_ABSOLUTE_TIMEOUT_SECONDS_DEFAULT = 43200; // 12 hours

function session_idle_timeout_seconds(): int {
    return max(60, (int)(env('SESSION_IDLE_TIMEOUT_SECONDS', (string)SESSION_IDLE_TIMEOUT_SECONDS_DEFAULT)));
}

function session_absolute_timeout_seconds(): int {
    return max(60, (int)(env('SESSION_ABSOLUTE_TIMEOUT_SECONDS', (string)SESSION_ABSOLUTE_TIMEOUT_SECONDS_DEFAULT)));
}

/**
 * Mark the current session as freshly authenticated — resets the
 * absolute-timeout clock to "now". Call once, right alongside
 * session_regenerate_id() on a successful login/2FA/bootstrap-admin
 * transition, so the absolute timeout is measured from the moment of
 * authentication, not from whenever the pre-login session happened to
 * start (e.g. an earlier anonymous visit to the public site).
 */
function session_mark_authenticated(): void {
    $_SESSION['_created_at'] = time();
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    // use_strict_mode rejects any session id the client presents that the
    // server never generated (blocks session fixation via a pre-set
    // cookie); secure is derived from the real request scheme rather than
    // hardcoded, so local/dev HTTP access still works while production
    // HTTPS gets the flag automatically.
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_is_https(),
    ]);
    session_name('ELLSMS_SESSION');
    session_start();

    if (!empty($_SESSION['uid'])) {
        $now          = time();
        $lastActivity = (int)($_SESSION['_last_activity'] ?? $now);
        $createdAt    = (int)($_SESSION['_created_at'] ?? $now);
        // Missing keys default to "now" (not expired) so sessions that
        // predate this feature aren't force-logged-out the moment it
        // deploys — they simply start being tracked from here on.
        if (($now - $lastActivity) > session_idle_timeout_seconds()
            || ($now - $createdAt) > session_absolute_timeout_seconds()) {
            $expiredUserId = (int)$_SESSION['uid'];
            $_SESSION = [];
            session_destroy();
            session_start();
            Logger::info('auth.session_expired', ['user_id' => $expiredUserId]);
        }
    }
    $_SESSION['_last_activity'] = time();
}

/**
 * The logged-in identity: the backend platform's `user_` row merged
 * with its `ellsms_meta` row (panel_access / is_admin / originator).
 */
function current_user(): ?array {
    // Cache key is $_SESSION['uid'] itself, not a plain "resolved once" flag — mirrors
    // current_organization()'s own (userId, selectedId) keyed cache in app/tenant.php, for the same
    // reason: a plain resolve-once cache would permanently return whatever the FIRST caller within
    // this PHP process resolved (including "no session yet" -> null), even after $_SESSION['uid']
    // later changes to a real user — a real risk in any long-lived process sharing one PHP process
    // across requests/simulated logins (PHPUnit test suites in particular), not just theoretical.
    static $cacheKey = null;
    static $cached = null;

    $uid = !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    $key = 'uid:' . $uid;
    if ($cacheKey === $key) {
        return $cached;
    }

    $user = null;
    if ($uid > 0) {
        // Phase 8 (Invariant B): the raw `user_`/`ellsms_meta` read moved to
        // backend_find_user_by_id() (app/Backend/identity.php) — this is the ONE place ELLSMS
        // identity is read from, this function's own caching/session logic is unchanged.
        $row = backend_find_user_by_id($uid);
        if ($row && $row['active'] && !$row['deleted'] && $row['panel_access']) {
            $row['role']      = $row['is_admin'] ? 'admin' : 'user';
            $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $user = $row;
        }
    }

    $cacheKey = $key;
    $cached = $user;
    return $user;
}

/**
 * Phase 6: attaches organization_id to the returned array from the session-bound
 * current_organization() — called here, AFTER current_user() has already resolved and cached
 * itself, deliberately not from inside current_user() directly (current_organization() itself
 * calls current_user() to find the acting user, which would recurse infinitely if current_user()
 * called it back before finishing its own resolution). Every page that already calls
 * require_login() gets $me['organization_id'] for free from this point on — no per-page edits
 * needed for the attachment itself, only for pages that must additionally ENFORCE an active
 * organization (see require_organization()/require_active_organization(), app/tenant.php).
 * Returns the array UNCHANGED (no 'organization_id' key at all) when no organization could be
 * resolved — never a guessed/default id — so callers checking with isset()/??  fail closed exactly
 * like every other missing-context case in this codebase.
 */
function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    // Re-validates an impersonating session on EVERY authenticated request: a crafted session, an
    // actor who has since lost platform-admin, or an elapsed support window all end here rather
    // than degrading into an administrator silently browsing a customer's account as that customer
    // (docs/admin-impersonation.md). A no-op for ordinary sessions.
    impersonation_enforce();
    $org = current_organization();
    if ($org) {
        $u['organization_id'] = (int)$org['organization_id'];
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    // The platform-admin area is unreachable while impersonating. This is mostly belt-and-braces —
    // the effective user during an impersonation is an ordinary customer, so the role check below
    // already denies — but it is stated explicitly so the operator gets an actionable message
    // instead of a bare 403, and so the rule survives even if a target somehow held admin rights
    // (impersonation_target_refusal() forbids starting one, but this file must not depend on that).
    if (is_impersonating()) {
        http_response_code(403);
        exit('۴۰۳ — در حالت پشتیبانی به بخش مدیریت دسترسی ندارید. ابتدا از حالت پشتیبانی خارج شوید.');
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('۴۰۳ — این بخش فقط برای مدیران است.');
    }
    return $u;
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** True if at least one account has been granted ELLSMS admin. */
function ellsms_has_admin(): bool {
    return (int)db()->query('SELECT COUNT(*) c FROM ellsms_meta WHERE is_admin = 1')->fetch()['c'] > 0;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
            http_response_code(400);
            exit('نشانه‌ی درخواست نامعتبر است. بازگردید و دوباره تلاش کنید.');
        }
    }
}

/* ---------- Flash messages ---------- */
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Output helpers ---------- */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never {
    header('Location: ' . $to);
    exit;
}

/* ---------- SMS helpers ---------- */

/** Normalize a phone number to international digits (98…) */
function normalize_msisdn(string $raw): ?string {
    $n = preg_replace('/[^\d+]/', '', trim($raw));
    if ($n === '') return null;
    if (str_starts_with($n, '+'))  $n = substr($n, 1);
    if (str_starts_with($n, '00')) $n = substr($n, 2);
    if (str_starts_with($n, '09') && strlen($n) === 11) $n = '98' . substr($n, 1);
    if (str_starts_with($n, '9') && strlen($n) === 10)  $n = '98' . $n;
    return preg_match('/^\d{10,15}$/', $n) ? $n : null;
}

/**
 * Normalize a sender line / originator to digits only. Unlike
 * normalize_msisdn(), this does NOT rewrite a leading 09 to 98 — a
 * sender line or short code isn't a mobile number, so that rewrite
 * would corrupt it. The backend API requires this as a plain integer.
 */
function normalize_originator(string $raw): ?string {
    $n = preg_replace('/\D/', '', trim($raw));
    return $n !== '' ? $n : null;
}

/** Parse a textarea / CSV of numbers into a unique normalized list. */
function parse_destinations(string $raw): array {
    $parts = preg_split('/[\s,;،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $n = normalize_msisdn($p);
        if ($n) $out[$n] = true;
    }
    // array_keys() would hand back PHP ints here, not strings: PHP
    // silently casts any array key that looks like a valid decimal
    // integer (e.g. "989197684063") to an int. strval() undoes that so
    // callers — and json_encode() for the API request — see real strings.
    return array_map('strval', array_keys($out));
}

/** Number of SMS parts for a message (GSM-7 vs Unicode). */
function sms_parts(string $content): int {
    $isUnicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $content);
    $len = mb_strlen($content, 'UTF-8');
    if ($len === 0) return 0;
    if ($isUnicode) return $len <= 70  ? 1 : (int)ceil($len / 67);
    return              $len <= 160 ? 1 : (int)ceil($len / 153);
}

/* ---------- Audit log ---------- */
/**
 * Appends an audit row.
 *
 * `impersonator_user_id` is filled in AUTOMATICALLY from the session whenever a platform admin is
 * impersonating, so every existing call site keeps attributing the action to the effective user
 * (which is what the row means) while the real human behind it is never lost — Invariant D/E of
 * docs/admin-impersonation.md, achieved without editing a hundred audit() calls.
 *
 * The column is additive (db/migrations/2026_08_11_audit_impersonator.sql). An install running this
 * code against a not-yet-migrated database silently falls back to the original statement rather
 * than failing every audited action; the fallback is latched, so it costs one failed statement per
 * process, not one per call.
 */
function audit(int $userId, string $action, string $details = ''): void {
    static $hasImpersonatorColumn = true;

    $ip = function_exists('client_ip') && PHP_SAPI !== 'cli' ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    $impersonatorUserId = null;
    if (function_exists('is_impersonating') && is_impersonating()) {
        $impersonatorUserId = real_actor_user_id();
    }

    if ($hasImpersonatorColumn) {
        try {
            db()->prepare('INSERT INTO ellsms_audit_log (user_id, action, details, ip, impersonator_user_id) VALUES (?,?,?,?,?)')
                ->execute([$userId, $action, $details, $ip, $impersonatorUserId]);
            return;
        } catch (PDOException $e) {
            $hasImpersonatorColumn = false;
        }
    }

    db()->prepare('INSERT INTO ellsms_audit_log (user_id, action, details, ip) VALUES (?,?,?,?)')
        ->execute([$userId, $action, $details, $ip]);
}

/* ==========================================================================
   Persian (Jalali) calendar helpers
   Pure PHP, no external library — a well-known algorithmic conversion
   (Kazimierz Borkowski's method), so there's no CDN or package dependency
   for something as central as every date on every page.
   ========================================================================== */

const JALALI_MONTHS = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];

const JALALI_WEEKDAYS = [
    'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
];

/** Gregorian y/m/d -> Jalali [jy, jm, jd]. */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
          + (int)(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * (int)($days / 12053);
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** Jalali y/m/d -> Gregorian [gy, gm, gd]. */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4)
          + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * (int)($days / 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * (int)(--$days / 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 === 0) && ($gy % 100 !== 0)) || ($gy % 400 === 0)) ? 29 : 28,
              31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
    return [$gy, $gm, $gd];
}

/** Convert every ASCII digit in a string to its Persian digit form. */
function to_persian_digits(string $s): string {
    static $en = ['0','1','2','3','4','5','6','7','8','9'];
    static $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($en, $fa, $s);
}

/** Convert Persian/Arabic-Indic digits back to ASCII (for reading $_POST values). */
function from_persian_digits(string $s): string {
    static $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    static $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($fa, $en, $s);
}

/**
 * Format a MySQL DATETIME/DATE string as a Persian Jalali date (and
 * optionally time), with Persian digits. Returns '' for null/empty input.
 */
function jdate(?string $mysqlDateTime, bool $withTime = true): string {
    if (!$mysqlDateTime) return '';
    $ts = strtotime($mysqlDateTime);
    if ($ts === false) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $out = to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    if ($withTime) $out .= ' - ' . to_persian_digits(date('H:i', $ts));
    return $out;
}

/** Same as jdate() but spells the month name out — e.g. "۲۳ تیر ۱۴۰۵". */
function jdate_long(?string $mysqlDateTime): string {
    if (!$mysqlDateTime) return '';
    $ts = strtotime($mysqlDateTime);
    if ($ts === false) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return to_persian_digits((string)$jd) . ' ' . JALALI_MONTHS[$jm] . ' ' . to_persian_digits((string)$jy);
}

/**
 * Render three <select> boxes (year/month/day) for picking a Jalali date,
 * named "{$name}_y", "{$name}_m", "{$name}_d". $defaultYmd is a Gregorian
 * 'Y-m-d' string (or null for no default / today).
 */
function jalali_date_select(string $name, ?string $defaultYmd = null, int $yearsAhead = 2): string {
    $ts = $defaultYmd ? strtotime($defaultYmd) : time();
    [$dy, $dm, $dd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $todayTs = time();
    [$ty] = gregorian_to_jalali((int)date('Y', $todayTs), (int)date('n', $todayTs), (int)date('j', $todayTs));

    $h = '<span class="jdate">';
    $h .= '<select name="' . e($name) . '_y" class="jdate-y">';
    for ($y = $ty - 1; $y <= $ty + $yearsAhead; $y++) {
        $h .= '<option value="' . $y . '"' . ($y === $dy ? ' selected' : '') . '>' . to_persian_digits((string)$y) . '</option>';
    }
    $h .= '</select>';
    $h .= '<select name="' . e($name) . '_m" class="jdate-m">';
    foreach (JALALI_MONTHS as $num => $label) {
        $h .= '<option value="' . $num . '"' . ($num === $dm ? ' selected' : '') . '>' . $label . '</option>';
    }
    $h .= '</select>';
    $h .= '<select name="' . e($name) . '_d" class="jdate-d">';
    for ($d = 1; $d <= 31; $d++) {
        $h .= '<option value="' . $d . '"' . ($d === $dd ? ' selected' : '') . '>' . to_persian_digits((string)$d) . '</option>';
    }
    $h .= '</select></span>';
    return $h;
}

/** Read back a jalali_date_select() submission (GET or POST) as a Gregorian 'Y-m-d' string, or null. */
function jalali_request_to_gregorian(string $name): ?string {
    $y = (int)($_REQUEST["{$name}_y"] ?? 0);
    $m = (int)($_REQUEST["{$name}_m"] ?? 0);
    $d = (int)($_REQUEST["{$name}_d"] ?? 0);
    if (!$y || !$m || !$d) return null;
    [$gy, $gm, $gd] = jalali_to_gregorian($y, $m, min($d, 31));
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** Render hour/minute <select> boxes named "{$name}_h" / "{$name}_i". */
function time_select(string $name, ?string $defaultHi = null): string {
    [$dh, $di] = $defaultHi ? array_map('intval', explode(':', $defaultHi)) : [(int)date('H'), 0];
    $h = '<span class="jtime">';
    $h .= '<select name="' . e($name) . '_h" class="jtime-h">';
    for ($x = 0; $x < 24; $x++) $h .= '<option value="' . $x . '"' . ($x === $dh ? ' selected' : '') . '>' . to_persian_digits(str_pad((string)$x, 2, '0', STR_PAD_LEFT)) . '</option>';
    $h .= '</select><span class="jtime-sep">:</span><select name="' . e($name) . '_i" class="jtime-i">';
    for ($x = 0; $x < 60; $x += 5) $h .= '<option value="' . $x . '"' . ($x === $di ? ' selected' : '') . '>' . to_persian_digits(str_pad((string)$x, 2, '0', STR_PAD_LEFT)) . '</option>';
    $h .= '</select></span>';
    return $h;
}

/** Read back a time_select() submission as 'H:i', or null. */
function time_post(string $name): ?string {
    if (!isset($_POST["{$name}_h"], $_POST["{$name}_i"])) return null;
    return sprintf('%02d:%02d', (int)$_POST["{$name}_h"], (int)$_POST["{$name}_i"]);
}

/* ==========================================================================
   KYC document uploads
   Stored OUTSIDE the public web root (APP_ROOT/storage/kyc) so a photo of
   someone's ID card is never reachable by a guessed/shared URL — every
   read goes through public/kyc-photo.php, which checks the viewer is
   either that user or an admin before streaming the file.
   ========================================================================== */

define('KYC_STORAGE_DIR', APP_ROOT . '/storage/kyc');
define('KYC_MAX_BYTES', 8 * 1024 * 1024); // 8MB
const KYC_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

/**
 * Validate and store an uploaded KYC document from $_FILES[$field].
 * Returns the stored filename (to save in ellsms_user_kyc) on success,
 * null if no file was submitted, or throws an AppException with a
 * Persian message on validation failure (caller shows it as a flash).
 * AppException extends RuntimeException, so this is safe to display as-is
 * even in production — unlike a bare/internal exception, see
 * app/Support/AppException.php and app/Support/ErrorHandler.php.
 */
function kyc_store_upload(string $field, int $userId): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new AppException('بارگذاری فایل با خطا مواجه شد.');
    }
    if ($f['size'] > KYC_MAX_BYTES) {
        throw new AppException('حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if ($mime === '') {
        // fileinfo unavailable for some reason — fall back to the
        // extension the browser reported. Weaker, but better than
        // rejecting every upload outright.
        $extGuess = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($extGuess === 'jpeg') $extGuess = 'jpg';
        $extToMime = array_flip(KYC_ALLOWED_MIME);
        $mime = $extToMime[$extGuess] ?? '';
    }
    if (!isset(KYC_ALLOWED_MIME[$mime])) {
        throw new AppException('فرمت فایل باید JPG، PNG، WEBP یا PDF باشد.');
    }
    if (!is_dir(KYC_STORAGE_DIR)) {
        mkdir(KYC_STORAGE_DIR, 0750, true);
    }
    $ext = KYC_ALLOWED_MIME[$mime];
    $name = 'u' . $userId . '_' . $field . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], KYC_STORAGE_DIR . '/' . $name)) {
        throw new AppException('ذخیره‌ی فایل ممکن نشد.');
    }
    return $name;
}

/* ==========================================================================
   SMS-based two-factor login
   (send_2fa_code() / verify_2fa_code() live in app/backend.php — they call
   dispatch_message(), which is defined there.)
   ========================================================================== */

const TWOFA_CODE_TTL_SECONDS = 300; // 5 minutes
const TWOFA_RESEND_COOLDOWN  = 60;  // seconds between resend requests

/* ==========================================================================
   Mobile operator detection (for the per-operator analytics breakdown)

   This is a STATIC, best-effort mapping from the 3-digit block after
   "989" (e.g. "912" for a 0912 number) to an Iranian mobile operator.
   It only covers the long-established, stable ranges for the three
   major carriers — anything else (newer/reassigned blocks, smaller
   MVNOs, landlines, foreign numbers) falls into "سایر / نامشخص"
   (other/unknown) rather than guessing. The regulator periodically
   reassigns number blocks, so this table can go stale — it is NOT a
   live registry lookup. If precision here matters for billing or
   compliance, verify/extend OPERATOR_PREFIX_MAP below rather than
   trusting it blindly.
   ========================================================================== */
const OPERATOR_PREFIX_MAP = [
    // Hamrah-e Avval (MCI)
    '910' => 'همراه اول', '911' => 'همراه اول', '912' => 'همراه اول', '913' => 'همراه اول',
    '914' => 'همراه اول', '915' => 'همراه اول', '916' => 'همراه اول', '917' => 'همراه اول',
    '918' => 'همراه اول', '919' => 'همراه اول', '990' => 'همراه اول', '991' => 'همراه اول',
    '992' => 'همراه اول', '993' => 'همراه اول',
    // Irancell
    '930' => 'ایرانسل', '933' => 'ایرانسل', '935' => 'ایرانسل', '936' => 'ایرانسل',
    '937' => 'ایرانسل', '938' => 'ایرانسل', '939' => 'ایرانسل', '901' => 'ایرانسل',
    '902' => 'ایرانسل', '903' => 'ایرانسل', '905' => 'ایرانسل', '941' => 'ایرانسل',
    // Rightel
    '920' => 'رایتل', '921' => 'رایتل', '922' => 'رایتل',
];

/**
 * Best-effort operator name for a normalized Iranian mobile number (98912...).
 *
 * Now resolves through the ADMIN-CONFIGURED operator/prefix catalog (app/Sms/Pricing.php) so the
 * analytics breakdown and the pricing engine can never disagree about which carrier a number
 * belongs to — an admin who corrects a reassigned block in one place has corrected it everywhere.
 * OPERATOR_PREFIX_MAP above survives only as the fallback for an install whose pricing tables are
 * not migrated yet, which is exactly the behavior that install had before.
 */
function detect_operator(string $normalizedMobile): string {
    if (function_exists('sms_resolve_operator')) {
        $resolved = sms_resolve_operator($normalizedMobile);
        if ($resolved['operator_id'] !== null) {
            return $resolved['operator_name'];
        }
        // A resolvable catalog that simply has no rule for this number is a genuine "unknown" —
        // don't second-guess it with the stale constant below.
        if (sms_pricing_prefix_rules() !== []) {
            return 'سایر / نامشخص';
        }
    }
    if (!preg_match('/^98(9\d{2})\d{7}$/', $normalizedMobile, $m)) {
        return 'سایر / نامشخص';
    }
    return OPERATOR_PREFIX_MAP[$m[1]] ?? 'سایر / نامشخص';
}

/**
 * Remove any destination on $userId's do-not-contact list.
 * Returns [filteredDestinations, blockedCount].
 */
function filter_blacklist(int $userId, array $destinations): array {
    if (!$destinations) return [$destinations, 0];
    $st = db()->prepare('SELECT mobile FROM ellsms_blacklist WHERE user_id = ?');
    $st->execute([$userId]);
    $blocked = array_flip(array_column($st->fetchAll(), 'mobile'));
    if (!$blocked) return [$destinations, 0];

    $kept = [];
    $blockedCount = 0;
    foreach ($destinations as $d) {
        if (isset($blocked[$d])) $blockedCount++;
        else $kept[] = $d;
    }
    return [$kept, $blockedCount];
}
