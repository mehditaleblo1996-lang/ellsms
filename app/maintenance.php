<?php
/**
 * ELLSMS — operator-controlled maintenance mode (Phase 11, STEP 22/23).
 *
 * A plain file flag under storage/, not a database row or env var: `app` and `worker` already
 * bind-mount the same host directory (docker-compose.yml), so a file created/removed there is
 * visible to both containers instantly, with no restart and no dependency on the database being
 * reachable — appropriate for a switch an operator may specifically need DURING a database
 * problem. Loaded by app/bootstrap.php for every web request; cron/worker.php checks it directly
 * inside its own loop (never through bootstrap's HTTP-response blocking path — a CLI script
 * calling this file must never be blocked here, or every operational command in this phase would
 * stop working during the exact maintenance window they exist to be used in).
 *
 * Policy (STEP 22 — explicit per surface, not left accidental):
 *   - login, authenticated pages, sends, payment CREATION, platform-admin: BLOCKED (503).
 *   - health.php / health-ready.php: NEVER blocked — liveness/readiness must stay visible
 *     regardless of maintenance mode (Invariant L's spirit extended here).
 *   - zarinpal-callback.php (payment CALLBACKS): NEVER blocked — ZarinPal already completed the
 *     charge by the time the browser lands here; blocking it would strand a real payment in
 *     'pending' for no benefit, creating exactly the kind of manual reconciliation work
 *     cron/payments-reconcile.php exists to avoid. See docs/production-runbook.md.
 *   - workers: NOT killed or exited -- cron/worker.php keeps polling/leasing but skips its three
 *     dispatch passes while maintenance is active (own check, see cron/worker.php), so in-flight
 *     leases don't get abandoned mid-send merely because maintenance mode flipped on.
 *   - restore operations: not a web surface at all (STEP 36, CLI-only) -- maintenance mode has
 *     nothing to exempt or block here by construction.
 */

declare(strict_types=1);

function maintenance_mode_file(): string {
    return (string)env('MAINTENANCE_MODE_FILE', APP_ROOT . '/storage/maintenance.flag');
}

function maintenance_mode_active(): bool {
    return is_file(maintenance_mode_file());
}

/** Operator-provided reason (the flag file's own contents), or a sensible default. */
function maintenance_mode_message(): string {
    $custom = trim((string)@file_get_contents(maintenance_mode_file()));
    return $custom !== '' ? $custom : 'سامانه در حال به‌روزرسانی است. لطفاً چند دقیقه‌ی دیگر تلاش کنید.';
}

/** Script filenames that must stay reachable during maintenance -- see this file's own docblock
 * for why each one is on this list. Matched against basename(SCRIPT_FILENAME), not the URL, so it
 * can't be bypassed/broken by rewrite rules or query strings. */
const MAINTENANCE_MODE_EXEMPT_SCRIPTS = ['health.php', 'health-ready.php', 'zarinpal-callback.php'];

function maintenance_mode_current_script_exempt(): bool {
    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return in_array($script, MAINTENANCE_MODE_EXEMPT_SCRIPTS, true);
}

/**
 * Phase 12, STEP 51: the public API follows application readiness like every other authenticated
 * surface (maintenance mode is NOT on the exempt list above — sends/writes must stop the same way
 * they do for the web UI), but an API consumer expects JSON, not an HTML page. Checked by REQUEST
 * URI, not SCRIPT_FILENAME basename (public/api/index.php's basename is "index.php" — identical to
 * public/index.php's, so basename-matching would be ambiguous here specifically).
 */
function maintenance_mode_request_is_api(): bool {
    return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/v1');
}

/** Sends the 503 maintenance response and exits -- only ever called for actual HTTP requests
 * (app/bootstrap.php gates this on PHP_SAPI !== 'cli' before calling it). */
function maintenance_mode_respond_and_exit(): never {
    http_response_code(503);
    header('Retry-After: 300');

    if (maintenance_mode_request_is_api()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code'    => 'service_unavailable',
                'message' => maintenance_mode_message(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars(maintenance_mode_message(), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>در حال به‌روزرسانی — ELLSMS</title></head>'
       . '<body style="font-family:Tahoma,Arial,sans-serif;background:#f6f7f9;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh;margin:0">'
       . '<main style="background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);'
       . 'padding:32px;max-width:640px;width:90%;text-align:center">'
       . '<h1 style="margin:0 0 12px;font-size:20px;color:#333">در حال به‌روزرسانی</h1>'
       . '<p style="margin:0;color:#555">' . $message . '</p>'
       . '</main></body></html>';
    exit;
}
