<?php
/** Loaded by PHP auto_prepend_file for HTTP entrypoints. */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/AuditMongo.php';
    audit_request_register();

    // Phase 6 onboarding: selected customer-facing capabilities may require approved KYC.
    // Bootstrap is safe here (the entrypoint's own require_once becomes a no-op) and gives the
    // middleware the same authenticated user/tenant/KYC policy functions as normal pages.
    require_once dirname(__DIR__) . '/bootstrap.php';

    // Clean-URL deployments may sit behind a TLS-terminating reverse proxy whose externally
    // canonical APP_URL is not byte-for-byte identical to the origin PHP sees while composing the
    // baseline CSP. Chromium then treats an otherwise legitimate POST to the canonical clean URL
    // as outside `form-action 'self'` and blocks it before the request is sent. Keep the policy
    // strict, but explicitly allow the configured canonical HTTPS origin as well as 'self'.
    // Only a validated http(s) APP_URL origin is admitted; no path/query/user input is reflected.
    if (!headers_sent()) {
        $configuredUrl = app_url();
        $parts = $configuredUrl !== '' ? @parse_url($configuredUrl) : false;
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
        $port = is_array($parts) && isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'https' || $scheme === 'http') && $host !== '') {
            $origin = $scheme . '://' . $host;
            if ($port !== null && $port > 0) {
                $origin .= ':' . $port;
            }
            header(
                "Content-Security-Policy: default-src 'self'; " .
                "script-src 'self' 'unsafe-inline'; " .
                "style-src 'self' 'unsafe-inline'; " .
                "img-src 'self' data:; " .
                "font-src 'self'; " .
                "object-src 'none'; " .
                "base-uri 'self'; " .
                "form-action 'self' " . $origin . "; " .
                "frame-ancestors 'self'",
                true
            );
        }
    }

    require_once __DIR__ . '/KycGateMiddleware.php';
    kyc_http_gate_enforce();
}
