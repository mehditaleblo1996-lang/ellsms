<?php
/**
 * ELLSMS public API — request body reading with hard size/content-type limits (Phase 12, STEP 15).
 *
 * Deliberately checked BEFORE any JSON parsing is attempted — rejecting an oversized body by its
 * declared Content-Length (and, defensively, its ACTUAL length too, since Content-Length is
 * client-supplied and can simply be wrong) is cheap; feeding megabytes of attacker-controlled text
 * into json_decode() first is not.
 */

declare(strict_types=1);

final class ApiRequest
{
    private function __construct() {}

    public static function maxBodyBytes(): int
    {
        return max(1024, (int)(env('API_MAX_BODY_BYTES', '262144') ?? '262144')); // 256KB default
    }

    public static function maxBulkItems(): int
    {
        return max(1, (int)(env('API_MAX_BULK_ITEMS', '5000') ?? '5000'));
    }

    /**
     * Returns ['ok'=>true,'raw'=>string,'data'=>array] or ['ok'=>false,'status'=>int,'code'=>string,
     * 'message'=>string]. $requireJson=false lets GET/DELETE callers skip the Content-Type
     * requirement entirely (STEP 15 only mandates it "for JSON write endpoints").
     */
    public static function jsonBody(bool $requireJson = true): array
    {
        $max = self::maxBodyBytes();

        $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($declaredLength !== null && ctype_digit((string)$declaredLength) && (int)$declaredLength > $max) {
            return ['ok' => false, 'status' => 413, 'code' => ApiResponse::CODE_PAYLOAD_TOO_LARGE, 'message' => 'Request body exceeds the maximum allowed size.'];
        }

        if ($requireJson) {
            $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
            if ($contentType !== 'application/json') {
                return ['ok' => false, 'status' => 415, 'code' => ApiResponse::CODE_INVALID_REQUEST, 'message' => 'Content-Type must be application/json.'];
            }
        }

        $raw = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($raw === false) {
            $raw = '';
        }
        if (strlen($raw) > $max) {
            return ['ok' => false, 'status' => 413, 'code' => ApiResponse::CODE_PAYLOAD_TOO_LARGE, 'message' => 'Request body exceeds the maximum allowed size.'];
        }

        if ($raw === '') {
            return ['ok' => true, 'raw' => '', 'data' => []];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) && $data !== null) {
            // json_decode() on a bare JSON scalar ("5", "true") succeeds but isn't a usable request
            // body shape for this API — every real payload here is a JSON object.
            return ['ok' => false, 'status' => 400, 'code' => ApiResponse::CODE_INVALID_REQUEST, 'message' => 'Request body must be a JSON object.'];
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'status' => 400, 'code' => ApiResponse::CODE_INVALID_REQUEST, 'message' => 'Request body is not valid JSON.'];
        }

        return ['ok' => true, 'raw' => $raw, 'data' => $data ?? []];
    }

    /** Validated Idempotency-Key header, or null if absent/malformed (caller decides whether that's an error). */
    public static function idempotencyKey(): ?string
    {
        $raw = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        return $raw === null ? null : idempotency_normalize_key((string)$raw);
    }

    /** X-Request-ID passthrough (STEP 26) — bounded length/charset; a caller-supplied id that fails validation is simply ignored (Logger already minted our own). */
    public static function callerRequestId(): ?string
    {
        $raw = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($raw === '' || strlen($raw) > 128 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $raw)) {
            return null;
        }
        return $raw;
    }
}

/**
 * Fields a client is NEVER allowed to state, on ANY send or preview endpoint (STEP 42).
 *
 * Route/provider/operator selection and price are server decisions: a customer able to name a route
 * could pick a cheaper tariff than the one their sender is configured for, and one able to state a
 * unit price or cost could simply declare their own bill. This task deliberately does not expose
 * route choice to the public API at all — not even a validated allowlist — so every one of these is
 * REJECTED rather than silently dropped: a client sending them has a wrong mental model of who owns
 * pricing, and a silent ignore would let them believe it worked.
 *
 * `message_type` is on the list for the same reason. It is determined server-side from the send
 * context (an OTP tariff must not be reachable by simply claiming the type — STEP 16); if a future
 * phase permits a client to request one, it will be an explicitly allowlisted, policy-checked field,
 * not this one quietly becoming accepted.
 */
const API_CLIENT_FORBIDDEN_PRICING_FIELDS = [
    'provider_id', 'provider', 'route_id', 'route', 'operator_id', 'operator',
    'unit_price', 'price', 'price_per_segment', 'cost', 'estimated_cost', 'message_type',
];

/**
 * Returns a validation-error map for any forbidden pricing field present in the request body, or []
 * when the body is clean. Shared by the preview AND the real send/bulk endpoints so there is one
 * definition of "the client does not control price."
 */
function api_reject_client_pricing_fields(array $body): array {
    $fields = [];
    foreach (API_CLIENT_FORBIDDEN_PRICING_FIELDS as $forbidden) {
        if (array_key_exists($forbidden, $body)) {
            $fields[$forbidden] = ['not_allowed — pricing, routing and message type are determined server-side'];
        }
    }
    return $fields;
}
