<?php
/**
 * ELLSMS public API — the ONE front controller for every /api/v1/* route (Phase 12, STEP 4/57).
 *
 * docker/Dockerfile rewrites every /api/v1/... request here (REQUEST_URI stays intact, no PATH_INFO
 * dependency). No HTML rendering happens anywhere in this file or anything it calls — pure JSON in,
 * pure JSON out, via app/Api/Response.php's ApiResponse so the response shape can never drift
 * between endpoints.
 *
 * Request lifecycle, in order:
 *   1. API_ENABLED gate (STEP 57 — OFF by default for existing installs)
 *   2. route match (structural 404/405, independent of auth — STEP 4)
 *   3. bearer-token authentication (app/Api/Auth.php, Invariant C/L)
 *   4. rate limiting (app/Api/RateLimit.php, STEP 14)
 *   5. scope enforcement (Invariant D — fail closed for a missing/unknown scope)
 *   6. body parsing with size/content-type limits (app/Api/Request.php, STEP 15)
 *   7. handler dispatch (app/Api/Handlers/*.php)
 *   8. audit log line (STEP 25)
 */

declare(strict_types=1);

// app/backend.php (not just bootstrap.php) -- dispatch_message()/bulk_queue_job() used by the
// messages/bulk-jobs handlers live there; it requires bootstrap.php itself, same as cron/worker.php.
require_once __DIR__ . '/../../app/backend.php';
require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Request.php';
require_once __DIR__ . '/../../app/Api/Auth.php';
require_once __DIR__ . '/../../app/Api/RateLimit.php';
require_once __DIR__ . '/../../app/Api/Router.php';
require_once __DIR__ . '/../../app/Api/Handlers/Meta.php';
require_once __DIR__ . '/../../app/Api/Handlers/Messages.php';
require_once __DIR__ . '/../../app/Api/Handlers/BulkJobs.php';
require_once __DIR__ . '/../../app/Api/Handlers/Contacts.php';
require_once __DIR__ . '/../../app/Api/Handlers/Balance.php';
require_once __DIR__ . '/../../app/Api/Handlers/Webhooks.php';
require_once __DIR__ . '/../../app/Api/Handlers/Preview.php';

Logger::setCliMirror(false); // pure JSON stdout even if this ever runs under `php -S` for testing (Phase 11 precedent)

$startedAt = microtime(true);

if ((env('API_ENABLED', '0') ?? '0') !== '1') {
    ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'The public API is not enabled on this deployment.');
    exit;
}

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
// Bounded for metrics only (issue #14) -- the raw REQUEST_METHOD header is client-controlled and a
// caller could send an arbitrary token; every route this router actually registers only ever uses
// one of these five, so anything else collapses to 'other' rather than becoming a new label value.
$metricMethod = in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'PUT'], true) ? $method : 'other';

$router = new ApiRouter();
$router->map('GET',   '/api/v1/me',                          'api_handle_me');
$router->map('GET',   '/api/v1/organization',                'api_handle_organization');
// Preview routes are declared BEFORE their create counterparts so the more specific literal path
// wins — `/api/v1/messages/{id}` would otherwise capture "preview" as an id.
$router->map('POST',  '/api/v1/messages/preview',             'api_handle_messages_preview', ApiScopes::MESSAGES_SEND);
$router->map('POST',  '/api/v1/bulk-jobs/preview',            'api_handle_bulk_jobs_preview', ApiScopes::BULK_WRITE);
$router->map('POST',  '/api/v1/messages',                    'api_handle_messages_send', ApiScopes::MESSAGES_SEND);
$router->map('GET',   '/api/v1/messages/{id}',                'api_handle_messages_get', ApiScopes::MESSAGES_READ);
$router->map('POST',  '/api/v1/bulk-jobs',                   'api_handle_bulk_jobs_create', ApiScopes::BULK_WRITE);
$router->map('GET',   '/api/v1/bulk-jobs/{id}',               'api_handle_bulk_jobs_get', ApiScopes::BULK_READ);
$router->map('GET',   '/api/v1/contacts',                    'api_handle_contacts_list', ApiScopes::CONTACTS_READ);
$router->map('POST',  '/api/v1/contacts',                    'api_handle_contacts_create', ApiScopes::CONTACTS_WRITE);
$router->map('GET',   '/api/v1/contacts/{id}',                'api_handle_contacts_get', ApiScopes::CONTACTS_READ);
$router->map('PATCH', '/api/v1/contacts/{id}',                'api_handle_contacts_update', ApiScopes::CONTACTS_WRITE);
$router->map('DELETE','/api/v1/contacts/{id}',                'api_handle_contacts_delete', ApiScopes::CONTACTS_WRITE);
$router->map('GET',   '/api/v1/balance',                     'api_handle_balance', ApiScopes::BALANCE_READ);
$router->map('GET',   '/api/v1/webhooks',                    'api_handle_webhooks_list', ApiScopes::WEBHOOKS_READ);
$router->map('POST',  '/api/v1/webhooks',                    'api_handle_webhooks_create', ApiScopes::WEBHOOKS_WRITE);
$router->map('GET',   '/api/v1/webhooks/{id}',                'api_handle_webhooks_get', ApiScopes::WEBHOOKS_READ);
$router->map('PATCH', '/api/v1/webhooks/{id}',                'api_handle_webhooks_update', ApiScopes::WEBHOOKS_WRITE);
$router->map('DELETE','/api/v1/webhooks/{id}',                'api_handle_webhooks_delete', ApiScopes::WEBHOOKS_WRITE);
$router->map('POST',  '/api/v1/webhooks/{id}/rotate-secret',  'api_handle_webhooks_rotate_secret', ApiScopes::WEBHOOKS_WRITE);
$router->map('POST',  '/api/v1/webhooks/{id}/test',           'api_handle_webhooks_test', ApiScopes::WEBHOOKS_WRITE);

$route = $router->dispatch($method, $path);
if (!$route['matched']) {
    // STEP 5's status-code table has no entry for 405 — both "unknown path" and "known path, wrong
    // method" collapse to the same 404 not_found here, deliberately (also avoids confirming to a
    // caller that a path exists but their method choice was wrong).
    // Issue #14 final audit: 'route' is the fixed literal 'unmatched', never the raw request path
    // (which could carry an arbitrary/attacker-supplied string) -- bounded cardinality preserved.
    Metrics::increment('api.request', 1, ['route' => 'unmatched', 'method' => $metricMethod, 'status' => '404']);
    api_request_metric_record('unmatched', $metricMethod, '4xx', (microtime(true) - $startedAt) * 1000);
    ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Route not found.');
    exit;
}

// Registered once the route is known, so EVERY exit from here on (rate limit, auth, scope,
// subscription/feature gates, body-parse error, the handler itself, or the uncaught-exception
// branch) is counted exactly once, regardless of which `exit` statement actually runs -- rather
// than hand-instrumenting each of those branches individually and risking one being missed.
$metricRoute = is_string($route['handler']) ? $route['handler'] : 'closure';
register_shutdown_function(static function () use ($metricRoute, $metricMethod, $startedAt): void {
    $statusCode = http_response_code();
    $statusBucket = is_int($statusCode) ? ((int)($statusCode / 100) . 'xx') : 'unknown';
    $durationMs = (microtime(true) - $startedAt) * 1000;
    Metrics::increment('api.request', 1, ['route' => $metricRoute, 'method' => $metricMethod, 'status' => $statusBucket]);
    Metrics::timing('api.request.duration', $durationMs, ['route' => $metricRoute]);
    api_request_metric_record($metricRoute, $metricMethod, $statusBucket, $durationMs);
});

$principal = ApiAuth::authenticate();
$authFailureCategory = $principal === null ? (ApiAuth::authorizationHeader() === null ? 'no_credentials' : 'invalid_credentials') : null;

$rateLimit = api_rate_limit_check($principal);
if (!$rateLimit['ok']) {
    header('Retry-After: ' . $rateLimit['retry_after']);
    Logger::warning('api.rate_limited', ['path' => $path, 'method' => $method, 'api_key_id' => $principal['api_key_id'] ?? null]);
    ApiResponse::error(429, ApiResponse::CODE_RATE_LIMITED, 'Too many requests — slow down and retry after the indicated delay.');
    exit;
}

if ($principal === null) {
    Logger::warning('api.auth_failed', ['path' => $path, 'method' => $method, 'category' => $authFailureCategory]);
    ApiResponse::error(401, ApiResponse::CODE_UNAUTHENTICATED, 'Missing or invalid API credentials.');
    exit;
}

if ($route['scope'] !== null && !api_key_has_scope($principal, $route['scope'])) {
    Logger::warning('api.scope_denied', ['path' => $path, 'method' => $method, 'api_key_id' => $principal['api_key_id'], 'required_scope' => $route['scope']]);
    ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'This API key does not have the required scope for this action.');
    exit;
}

// Phase 13 (STEP 11/55) — plan enforcement, applied AFTER authentication + scope and BEFORE any
// handler runs. An API scope and a plan entitlement are deliberately never conflated: a key may
// legitimately hold `messages:send` while the organization's plan doesn't include API access at
// all, and both checks must pass independently (Invariant N).
$apiOrganizationId = (int)$principal['organization_id'];
if (!organization_subscription_serviceable($apiOrganizationId)) {
    Logger::warning('api.subscription_inactive', ['path' => $path, 'organization_id' => $apiOrganizationId, 'api_key_id' => $principal['api_key_id']]);
    Metrics::increment('billing.api.blocked', 1, ['reason' => 'subscription_inactive']);
    ApiResponse::error(402, ApiResponse::CODE_SUBSCRIPTION_INACTIVE, 'This organization\'s subscription is not active.');
    exit;
}
if (!organization_has_entitlement($apiOrganizationId, Entitlements::PUBLIC_API)) {
    Logger::warning('api.entitlement_denied', ['path' => $path, 'organization_id' => $apiOrganizationId, 'entitlement' => Entitlements::PUBLIC_API]);
    Metrics::increment('billing.api.blocked', 1, ['reason' => 'feature_not_available']);
    ApiResponse::error(403, ApiResponse::CODE_FEATURE_NOT_AVAILABLE, 'The public API is not included in this organization\'s current plan.');
    exit;
}
// Webhook routes additionally require the webhooks entitlement — a plan can include API access
// without including webhooks.
if (str_starts_with($path, '/api/v1/webhooks') && !organization_has_entitlement($apiOrganizationId, Entitlements::WEBHOOKS)) {
    Metrics::increment('billing.api.blocked', 1, ['reason' => 'webhooks_not_available']);
    ApiResponse::error(403, ApiResponse::CODE_FEATURE_NOT_AVAILABLE, 'Webhooks are not included in this organization\'s current plan.');
    exit;
}

$requiresJsonBody = in_array($method, ['POST', 'PATCH', 'PUT'], true);
$bodyResult = ApiRequest::jsonBody($requiresJsonBody);
if (!$bodyResult['ok']) {
    ApiResponse::error($bodyResult['status'], $bodyResult['code'], $bodyResult['message']);
    exit;
}

$ctx = [
    'principal' => $principal,
    'params'    => $route['params'],
    'query'     => $_GET,
    'body'      => $bodyResult['data'],
    'raw'       => $bodyResult['raw'],
];

try {
    ($route['handler'])($ctx);
} catch (Throwable $t) {
    // Invariant H: never let a raw exception message/stack trace/SQL fragment reach the client —
    // the full detail still goes to Logger for internal investigation, same split as
    // app/Support/ErrorHandler.php uses for the HTML side of this app.
    Logger::critical('api.handler_exception', ['path' => $path, 'method' => $method, 'exception' => $t]);
    if (!headers_sent()) {
        ApiResponse::error(500, ApiResponse::CODE_INTERNAL_ERROR, 'An unexpected error occurred. Reference the request id if you contact support.');
    }
}

// Metric recording for this request happens in the register_shutdown_function() registered right
// after the route was matched above -- it fires exactly once regardless of which exit path (this
// success path, or any of the earlier gate failures) actually ran.

Logger::info('api.request.completed', [
    'method'          => $method,
    'path'            => $path,
    'status'          => http_response_code(),
    'api_key_id'      => $principal['api_key_id'],
    'key_prefix'      => $principal['key_prefix'],
    'organization_id' => $principal['organization_id'],
    'duration_ms'     => (int)((microtime(true) - $startedAt) * 1000),
]);
