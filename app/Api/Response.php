<?php
/**
 * ELLSMS public API — stable JSON response envelope (Phase 12, STEP 5).
 *
 * Every /api/v1/* response — success or error — goes through this class, never a bare echo/
 * json_encode at a handler call site, so the shape can never silently drift between endpoints.
 * Deliberately class-based (unlike most of this codebase's plain-function files) to match
 * app/Support/Permissions.php's own precedent for a small, stateless, purely-static helper.
 */

declare(strict_types=1);

final class ApiResponse
{
    /** Machine-readable codes this API ever returns — the ONE place the HTTP-status<->code mapping lives (STEP 5). */
    public const CODE_INVALID_REQUEST    = 'invalid_request';
    public const CODE_UNAUTHENTICATED    = 'unauthenticated';
    public const CODE_FORBIDDEN          = 'forbidden';
    public const CODE_NOT_FOUND          = 'not_found';
    public const CODE_CONFLICT           = 'conflict';
    public const CODE_VALIDATION_FAILED  = 'validation_failed';
    public const CODE_RATE_LIMITED       = 'rate_limited';
    public const CODE_PAYLOAD_TOO_LARGE  = 'payload_too_large';
    public const CODE_INTERNAL_ERROR     = 'internal_error';
    public const CODE_SERVICE_UNAVAILABLE = 'service_unavailable';

    /* --- Phase 13 plan/quota codes (STEP 39). Deliberately distinct from the generic codes above
       so an integrator can branch on "you need to upgrade" vs "you did something wrong" without
       parsing message text. Status-code policy, chosen once and applied consistently:
         403 forbidden          — the plan doesn't include this feature at all (feature_not_available)
         402 payment-required   — the subscription itself is inactive/suspended (subscription_inactive)
         409 conflict           — a standing resource cap is full (resource_limit_reached)
         429 rate_limited       — a per-period usage meter is exhausted (quota_exceeded), sent with
                                  Retry-After pointing at the period reset, so existing client
                                  back-off logic for rate limits handles quota exhaustion correctly
                                  too rather than needing a second code path.
       None of these ever leak which plan the organization is on or what the internal limit values
       are — only that a limit was reached. --- */
    public const CODE_FEATURE_NOT_AVAILABLE   = 'feature_not_available';
    public const CODE_SUBSCRIPTION_INACTIVE   = 'subscription_inactive';
    public const CODE_QUOTA_EXCEEDED          = 'quota_exceeded';
    public const CODE_RESOURCE_LIMIT_REACHED  = 'resource_limit_reached';

    private function __construct() {}

    /** Emits a success envelope and returns — does not exit, so callers/tests can still run cleanup. */
    public static function success(int $status, array $data, array $meta = []): void
    {
        $body = ['data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }
        self::emit($status, $body);
    }

    /**
     * Emits the stable error envelope (STEP 5):
     *   {"error": {"code": "...", "message": "...", "request_id": "...", ["fields": {...}]}}
     * $message is ALWAYS a safe, generic, non-leaking string — never a raw exception message, SQL
     * fragment, file path, or stack trace (Invariant H). $fields is only ever present for
     * validation_failed.
     */
    public static function error(int $status, string $code, string $message, array $fields = []): void
    {
        $error = [
            'code'       => $code,
            'message'    => $message,
            'request_id' => Logger::currentRequestId(),
        ];
        if ($fields) {
            $error['fields'] = $fields;
        }
        self::emit($status, ['error' => $error]);
    }

    public static function validationFailed(array $fields): void
    {
        self::error(422, self::CODE_VALIDATION_FAILED, 'Request validation failed.', $fields);
    }

    /** Emits an already-serialized JSON body byte-for-byte — used exclusively for idempotent replay (app/Idempotency.php), so a replayed response is IDENTICAL to the original, not just equivalent. */
    public static function raw(int $status, string $rawJsonBody): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Request-Id: ' . Logger::currentRequestId());
        }
        echo $rawJsonBody;
    }

    private static function emit(int $status, array $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Request-Id: ' . Logger::currentRequestId());
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
