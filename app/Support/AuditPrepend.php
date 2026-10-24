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

    // Internal PHP code still contains historical redirect('/foo.php') calls. After Clean URLs were
    // enabled those responses caused an unnecessary second navigation:
    //     POST /messages/send -> 302 /reports.php -> 301 /messages/reports
    // Besides the extra RTT, Chromium can briefly paint the previous document between those two
    // navigations, which is the broken/half-empty layout users observed after sends. Normalize every
    // INTERNAL Location header at the final header boundary, before bytes leave PHP. This lets old
    // call sites keep working during the gradual code migration while the browser receives exactly
    // one canonical redirect. Query strings and fragments are preserved. Provider callbacks and
    // external absolute URLs are intentionally untouched.
    if (function_exists('header_register_callback')) {
        header_register_callback(static function (): void {
            if (headers_sent()) {
                return;
            }

            $map = [
                '/index.php' => '/dashboard',
                '/landing.php' => '/',
                '/login.php' => '/login',
                '/logout.php' => '/logout',
                '/verify-2fa.php' => '/login/verify-2fa',
                '/register.php' => '/register',
                '/register-verify.php' => '/register/verify',
                '/register-pending.php' => '/register/pending',
                '/pricing.php' => '/pricing',
                '/guide.php' => '/guide',
                '/contact.php' => '/contact',
                '/terms.php' => '/terms',
                '/privacy.php' => '/privacy',
                '/refund.php' => '/refund',
                '/onboarding.php' => '/onboarding',
                '/notifications.php' => '/notifications',
                '/profile.php' => '/account/profile',
                '/profile-document.php' => '/account/profile/document',
                '/kyc-photo.php' => '/account/kyc/photo',
                '/organizations.php' => '/account/organizations',
                '/new-send.php' => '/messages/new',
                '/send.php' => '/messages/send',
                '/p2p-send.php' => '/messages/p2p',
                '/smart-send.php' => '/messages/smart',
                '/schedules.php' => '/messages/schedules',
                '/autoreply.php' => '/messages/autoreply',
                '/reports.php' => '/messages/reports',
                '/report-exports.php' => '/messages/reports/exports',
                '/inbox.php' => '/messages/inbox',
                '/message-detail.php' => '/messages/detail',
                '/contacts.php' => '/contacts',
                '/blacklist.php' => '/contacts/blacklist',
                '/import.php' => '/contacts/import',
                '/import-confirm.php' => '/contacts/import/confirm',
                '/import-status.php' => '/contacts/import/status',
                '/billing.php' => '/billing',
                '/buy-credit.php' => '/billing/credit',
                '/invoices.php' => '/billing/invoices',
                '/financial-log.php' => '/billing/history',
                '/api-keys.php' => '/developers/api-keys',
                '/webhooks.php' => '/developers/webhooks',
                '/number-forwards.php' => '/developers/line-forwarding',
                '/tickets.php' => '/support/tickets',
                '/users.php' => '/admin/users',
                '/user-send-policies.php' => '/admin/users/send-policies',
                '/impersonate.php' => '/admin/impersonate',
                '/registration-requests.php' => '/admin/registrations',
                '/registration-request.php' => '/admin/registrations/detail',
                '/kyc-review.php' => '/admin/kyc/review',
                '/kyc-gates.php' => '/admin/kyc/gates',
                '/analytics.php' => '/admin/analytics',
                '/logs.php' => '/admin/logs',
                '/numbers.php' => '/admin/numbers',
                '/number-categories.php' => '/admin/numbers/categories',
                '/sms-pricing.php' => '/admin/sms/pricing',
                '/sms-gateways.php' => '/admin/sms/gateways',
                '/sms-gateway-clone.php' => '/admin/sms/gateways/clone',
                '/billing-admin.php' => '/admin/billing/plans',
                '/financial-admin.php' => '/admin/billing/finance',
                '/guide-admin.php' => '/admin/content/guide',
                '/slides.php' => '/admin/content/slides',
                '/settings.php' => '/admin/settings',
                '/bootstrap-admin.php' => '/admin/bootstrap',
                '/api-guide.php' => '/developers/api-guide',
                '/subaccounts.php' => '/account/subaccounts',
                '/line-optouts.php' => '/contacts/optouts',
                '/menu-roles.php' => '/admin/users/menu-roles',
                '/reseller-profits.php' => '/admin/users/reseller-profits',
                '/queue-cancellation.php' => '/admin/queue/cancellation',
                '/queue-monitor.php' => '/admin/queue/monitor',
                '/bulk-archive.php' => '/admin/bulk-archive',
                '/alerts.php' => '/admin/alerts',
                '/prohibited-words.php' => '/admin/prohibited-words',
                '/remote-db.php' => '/admin/remote-db',
                '/landing-videos.php' => '/admin/content/videos',
                '/price-quote.php' => '/admin/price-quote',
                '/bale-messages.php' => '/messages/bale',
                '/voice.php' => '/messages/voice',
                '/voice-files.php' => '/messages/voice/files',
                '/voice-settings.php' => '/admin/voice',
                '/regional-bulk.php' => '/messages/regional',
                '/aimed-bulk.php' => '/messages/aimed',
                '/reports-bulk.php' => '/messages/reports/bulk',
                '/bulk-job.php' => '/messages/bulk-jobs',
                '/integrations.php' => '/integrations',
            ];

            foreach (headers_list() as $headerLine) {
                if (stripos($headerLine, 'Location:') !== 0) {
                    continue;
                }
                $location = trim(substr($headerLine, strlen('Location:')));
                if ($location === '' || $location[0] !== '/') {
                    return; // external/absolute destinations are not ours to rewrite
                }

                $fragment = '';
                $fragmentPos = strpos($location, '#');
                if ($fragmentPos !== false) {
                    $fragment = substr($location, $fragmentPos);
                    $location = substr($location, 0, $fragmentPos);
                }
                $query = '';
                $queryPos = strpos($location, '?');
                if ($queryPos !== false) {
                    $query = substr($location, $queryPos);
                    $path = substr($location, 0, $queryPos);
                } else {
                    $path = $location;
                }

                if (isset($map[$path])) {
                    header_remove('Location');
                    header('Location: ' . $map[$path] . $query . $fragment, true);
                }
                return;
            }
        });
    }

    require_once __DIR__ . '/KycGateMiddleware.php';
    kyc_http_gate_enforce();
}
