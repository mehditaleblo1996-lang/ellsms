<?php
/**
 * ELLSMS — the one authenticated internal client for backend platform HTTP calls (Phase 8, STEP 5).
 *
 * Replaces the two near-identical cURL blocks that used to live inline in app/backend.php
 * (backend_api_send()'s SMS-send call and backend_create_account()'s account-creation call) — both
 * now thin wrappers around backend_api_request() here. Owns: base URL resolution, connect/request
 * timeouts, HMAC request signing, request-ID propagation, safe JSON response parsing, and typed
 * error classification (STEP 18) — every caller gets the same transport behavior, not five subtly
 * different copies of curl_setopt_array().
 *
 * No namespace, no class — same plain-function convention as every other app/*.php file in this
 * codebase; "one authenticated client" means one FILE callers funnel through, not necessarily one
 * PHP object.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/Logger.php';

/**
 * Typed backend-error classes (STEP 18) — a caller (worker retry logic, STEP 19) should switch on
 * this string, never re-derive meaning from a raw HTTP status code or curl errno itself.
 *
 * BACKEND_UNAVAILABLE: transport/connection never completed, or the backend returned 5xx (its own
 *   side failed) — transient, safe to retry per this codebase's existing retry/backoff policy (Phase
 *   4). BACKEND_TIMEOUT: the request timed out specifically (a distinguishable subset of
 *   unavailable, kept separate since some callers may want different backoff for it). BACKEND_
 *   UNAUTHORIZED: 401/403 — a signing/config problem, not a per-request condition; retrying the
 *   identical request will fail identically. BACKEND_REJECTED: 400/404/422 — the backend understood
 *   the request and explicitly refused it (validation, not-found); not retryable as-is.
 *   BACKEND_CONFLICT: 409 — a real, permanent conflict (e.g. duplicate account). BACKEND_INVALID_
 *   RESPONSE: an HTTP success status but a body that isn't parseable/usable JSON — never trust a 2xx
 *   alone. BACKEND_PERMANENT_FAILURE: any other unclassified non-2xx status.
 */
final class BackendError
{
    public const UNAVAILABLE      = 'BackendUnavailable';
    public const TIMEOUT          = 'BackendTimeout';
    public const UNAUTHORIZED     = 'BackendUnauthorized';
    public const REJECTED         = 'BackendRejected';
    public const CONFLICT         = 'BackendConflict';
    public const INVALID_RESPONSE = 'BackendInvalidResponse';
    public const PERMANENT        = 'BackendPermanentFailure';

    /** Whether Phase 4's worker retry logic should treat this class as transient (retry) or permanent (don't). */
    public static function isRetryable(string $errorClass): bool {
        return in_array($errorClass, [self::UNAVAILABLE, self::TIMEOUT], true);
    }
}

/**
 * Phase 2 (STEP 12), extended Phase 8 with a request id header — CLIENT-SIDE ONLY. See
 * docs/service-boundaries.md section "Backend API Authentication" for the full protocol this
 * signature implements and docs/backend-verifier-reference.md for a standalone reference verifier —
 * backend-side verification is source code outside this repository, so end-to-end authentication
 * remains PARTIAL, not FIXED, exactly as Phase 2 originally disclosed (docs/security-review.md
 * finding 5). Opt-in and fully backward compatible: with BACKEND_SERVICE_ID/BACKEND_SERVICE_SECRET
 * unset (the default), this returns [] and every call is byte-identical to before.
 */
function backend_service_auth_headers(string $method, string $path, string $rawBody, string $requestId): array {
    $serviceId = (string)env('BACKEND_SERVICE_ID', '');
    $secret    = (string)env('BACKEND_SERVICE_SECRET', '');
    if ($serviceId === '' || $secret === '') {
        return [];
    }
    $timestamp = (string)time();
    $bodyHash  = hash('sha256', $rawBody);
    // Canonical signing string — method + path bound in (STEP 6: "Signature input should include
    // HTTP method, request path, timestamp, body hash, service ID"), not just the body, so a
    // captured signature for one endpoint/method can't be replayed against a different one.
    $signingString = implode("\n", [$method, $path, $timestamp, $bodyHash, $serviceId]);
    $signature = hash_hmac('sha256', $signingString, $secret);
    return [
        'X-Ellsms-Service-Id: ' . $serviceId,
        'X-Ellsms-Timestamp: ' . $timestamp,
        'X-Ellsms-Request-Id: ' . $requestId,
        'X-Ellsms-Signature: ' . $signature,
    ];
}

function backend_api_base_url(): string {
    return rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
}

/**
 * The one function that actually calls curl_init() against the backend platform. $path is appended
 * to the configured base URL as-is (e.g. '/api/messages/send'). Returns a normalized, stable shape —
 * never a raw PDO-style row, never the raw curl handle — regardless of what actually happened on the
 * wire:
 *   ok, http, data (decoded JSON array or null), error (human string or null), error_class
 *   (BackendError::* or null when ok), request_id (this call's own, always set), backend_request_id
 *   (from the response header if the backend ever sends one, else null).
 *
 * Never logs the request/response BODY (STEP 20: "Do not log message content unnecessarily") — only
 * method, path, http status, error class, timing, and the two request ids.
 */
function backend_api_request(string $method, string $path, ?array $jsonBody, int $connectTimeoutSeconds = 5, int $requestTimeoutSeconds = 30): array {
    $requestId = Logger::currentRequestId();
    $base = backend_api_base_url();
    if ($base === '') {
        Logger::error('backend.api.not_configured', ['path' => $path, 'request_id' => $requestId]);
        Metrics::increment('backend.request.not_configured', 1, ['method' => $method]);
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'آدرس API تنظیم نشده است — آن را در بخش تنظیمات وارد کنید.', 'error_class' => BackendError::UNAVAILABLE, 'request_id' => $requestId, 'backend_request_id' => null];
    }

    $payload = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE) : '';
    $headers = array_merge(['Content-Type: application/json'], backend_service_auth_headers($method, $path, $payload, $requestId));

    $startedAt = microtime(true);
    $ch = curl_init($base . $path);
    $curlOpts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_TIMEOUT        => $requestTimeoutSeconds,
    ];
    if ($jsonBody !== null) {
        $curlOpts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $curlOpts);
    $raw      = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $http      = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

    if ($raw === false) {
        $errorClass = $curlErrno === CURLE_OPERATION_TIMEDOUT ? BackendError::TIMEOUT : BackendError::UNAVAILABLE;
        Logger::error('backend.api.unreachable', [
            'method' => $method, 'path' => $path, 'request_id' => $requestId,
            'curl_errno' => $curlErrno, 'error_class' => $errorClass, 'elapsed_ms' => $elapsedMs,
        ]);
        Metrics::timing('backend.request', $elapsedMs, ['method' => $method, 'result' => 'unreachable', 'error_class' => $errorClass]);
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $curlError ?: 'برقراری اتصال ناموفق بود.', 'error_class' => $errorClass, 'request_id' => $requestId, 'backend_request_id' => null];
    }

    $responseHeaders = substr($raw, 0, $headerSize);
    $body            = substr($raw, $headerSize);
    $backendRequestId = null;
    if (preg_match('/^X-Backend-Request-Id:\s*(.+)$/mi', $responseHeaders, $m)) {
        $backendRequestId = trim($m[1]);
    }

    $decoded = json_decode($body, true);
    $bodyIsValidJson = json_last_error() === JSON_ERROR_NONE;

    if ($http >= 200 && $http < 300) {
        if (!$bodyIsValidJson) {
            Logger::error('backend.api.invalid_response', ['method' => $method, 'path' => $path, 'request_id' => $requestId, 'http' => $http]);
            Metrics::timing('backend.request', $elapsedMs, ['method' => $method, 'result' => 'invalid_response', 'http' => $http, 'error_class' => BackendError::INVALID_RESPONSE]);
            return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'پاسخ نامعتبر از API دریافت شد.', 'error_class' => BackendError::INVALID_RESPONSE, 'request_id' => $requestId, 'backend_request_id' => $backendRequestId];
        }
        Logger::info('backend.api.success', ['method' => $method, 'path' => $path, 'request_id' => $requestId, 'http' => $http, 'elapsed_ms' => $elapsedMs, 'backend_request_id' => $backendRequestId]);
        Metrics::timing('backend.request', $elapsedMs, ['method' => $method, 'result' => 'success', 'http' => $http]);
        return ['ok' => true, 'http' => $http, 'data' => $decoded, 'error' => null, 'error_class' => null, 'request_id' => $requestId, 'backend_request_id' => $backendRequestId];
    }

    $errorClass = match (true) {
        $http === 401, $http === 403 => BackendError::UNAUTHORIZED,
        $http === 409               => BackendError::CONFLICT,
        $http === 400, $http === 404, $http === 422 => BackendError::REJECTED,
        $http >= 500                => BackendError::UNAVAILABLE,
        default                     => BackendError::PERMANENT,
    };
    Logger::warning('backend.api.error_response', [
        'method' => $method, 'path' => $path, 'request_id' => $requestId, 'http' => $http,
        'error_class' => $errorClass, 'elapsed_ms' => $elapsedMs, 'backend_request_id' => $backendRequestId,
    ]);
    Metrics::timing('backend.request', $elapsedMs, ['method' => $method, 'result' => 'error_response', 'http' => $http, 'error_class' => $errorClass]);
    return [
        'ok' => false, 'http' => $http, 'data' => null,
        'error' => is_string($body) ? substr($body, 0, 1000) : 'unexpected response',
        'error_class' => $errorClass, 'request_id' => $requestId, 'backend_request_id' => $backendRequestId,
    ];
}

/**
 * Turn a failed API response into a readable Persian message. FastAPI validation errors (HTTP 422)
 * come back as {"detail": [{"loc": [...], "msg": "..."}]} — this pulls the field name and reason out
 * instead of just showing the HTTP status code. Unchanged from Phase 2/3's original implementation,
 * only relocated.
 */
function describe_api_error(int $http, ?string $rawBody): string {
    if ($http === 0) {
        return $rawBody ?: 'اتصال به API برقرار نشد.';
    }
    $decoded = $rawBody ? json_decode($rawBody, true) : null;
    if (is_array($decoded['detail'] ?? null)) {
        $parts = [];
        foreach ($decoded['detail'] as $d) {
            $field = is_array($d['loc'] ?? null) ? end($d['loc']) : null;
            $msg   = $d['msg'] ?? null;
            if ($field && $msg) $parts[] = "{$field}: {$msg}";
        }
        if ($parts) return 'درخواست توسط API رد شد (HTTP ' . $http . '): ' . implode('؛ ', $parts) . '.';
    } elseif (is_string($decoded['detail'] ?? null)) {
        return 'درخواست توسط API رد شد (HTTP ' . $http . '): ' . $decoded['detail'] . '.';
    }
    return "API پاسخ HTTP {$http} را برگرداند" . ($rawBody ? ': ' . mb_strimwidth($rawBody, 0, 200, '…') : '.') . '.';
}
