<?php
/** Loaded by PHP auto_prepend_file for HTTP entrypoints. */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/AuditMongo.php';
    audit_request_register();

    // Phase 6 onboarding: selected customer-facing capabilities may require approved KYC.
    // Bootstrap is safe here (the entrypoint's own require_once becomes a no-op) and gives the
    // middleware the same authenticated user/tenant/KYC policy functions as normal pages.
    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once __DIR__ . '/KycGateMiddleware.php';
    kyc_http_gate_enforce();
}
