<?php
/**
 * ELLSMS — ZarinPal payment gateway (v4 REST API).
 *
 * Verified against ZarinPal's own sample code and official docs
 * (zarinpal.com/docs/paymentGateway) before writing this — endpoint
 * paths, request/response shapes, and critically: amount unit.
 *
 * IMPORTANT — amount unit: ZarinPal's v4 API interprets `amount` as
 * RIAL by default. Toman is only used if the request explicitly sends
 * a `"currency": "IRT"` field, which this integration deliberately
 * never does, so every amount anywhere in ELLSMS's payment code is
 * unambiguously Rial. Admins configure the credit price in Rial
 * (Settings → "چند ریال معادل ۱ واحد اعتبار است") for exactly this
 * reason — don't convert it anywhere.
 *
 * Flow:
 *   1. zarinpal_request() -> get an `authority`, redirect the user to
 *      https://www.zarinpal.com/pg/StartPay/{authority}
 *   2. ZarinPal redirects back to the configured callback URL with
 *      ?Authority=...&Status=OK|NOK (see public/zarinpal-callback.php)
 *   3. zarinpal_verify() -> confirms the payment actually completed
 *      before crediting the account. code 100 = fresh success,
 *      101 = already verified (treat as success but don't re-credit).
 */

require_once __DIR__ . '/bootstrap.php';

function zarinpal_sandbox(): bool {
    return (setting('zarinpal_sandbox', env('ZARINPAL_SANDBOX', '0')) ?? '0') === '1';
}

function zarinpal_base_url(): string {
    return zarinpal_sandbox() ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';
}

function zarinpal_merchant_id(): string {
    return (string)(setting('zarinpal_merchant_id', env('ZARINPAL_MERCHANT_ID', '')) ?? '');
}

function zarinpal_callback_url(): string {
    $configured = setting('zarinpal_callback_url', env('ZARINPAL_CALLBACK_URL', ''));
    if ($configured) return rtrim($configured, '/');
    // Fallback: derive from the current request, same pattern used for
    // the webhook URLs shown in Settings — convenient default, but a
    // real ZarinPal merchant account typically expects a fixed,
    // pre-registered domain, so an explicit setting always wins.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/zarinpal-callback.php';
}

/** Low-level POST to a ZarinPal v4 endpoint. Returns decoded JSON or null on total failure. */
function zarinpal_call(string $path, array $payload): ?array {
    $ch = curl_init(zarinpal_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Start a payment. $amountRial is Rial. $paymentId is ELLSMS's own
 * ellsms_payments.id, embedded in the callback URL so the callback
 * handler knows which row to update (ZarinPal's own callback only
 * echoes back Authority/Status, nothing of ours).
 * Returns [ok, message, authority|null].
 */
function zarinpal_request(int $amountRial, int $paymentId, string $description, string $mobile = ''): array {
    $merchantId = zarinpal_merchant_id();
    if ($merchantId === '') {
        return [false, 'شناسه‌ی درگاه زرین‌پال تنظیم نشده — از مدیر بخواهید آن را در تنظیمات وارد کند.', null];
    }

    $callback = zarinpal_callback_url() . (str_contains(zarinpal_callback_url(), '?') ? '&' : '?') . 'payment_id=' . $paymentId;

    $payload = [
        'merchant_id'  => $merchantId,
        'amount'       => $amountRial,
        'callback_url' => $callback,
        'description'  => $description,
    ];
    if ($mobile) $payload['metadata'] = ['mobile' => $mobile];

    $result = zarinpal_call('/pg/v4/payment/request.json', $payload);
    if ($result === null) {
        return [false, 'اتصال به درگاه زرین‌پال برقرار نشد.', null];
    }
    if (!empty($result['errors'])) {
        $msg = is_array($result['errors']) ? ($result['errors']['message'] ?? json_encode($result['errors'], JSON_UNESCAPED_UNICODE)) : (string)$result['errors'];
        return [false, 'خطای زرین‌پال: ' . $msg, null];
    }
    if (($result['data']['code'] ?? null) == 100) {
        return [true, 'ok', (string)$result['data']['authority']];
    }
    return [false, 'زرین‌پال درخواست را رد کرد (کد ' . ($result['data']['code'] ?? '?') . ').', null];
}

/** URL to redirect the user's browser to, to actually pay. */
function zarinpal_start_pay_url(string $authority): string {
    $base = zarinpal_sandbox() ? 'https://sandbox.zarinpal.com' : 'https://www.zarinpal.com';
    return $base . '/pg/StartPay/' . $authority;
}

/**
 * Confirm a payment actually completed. $amountRial MUST match what
 * was sent to zarinpal_request() for the same authority.
 * Returns [ok, message, refId|null]. ok is true for both a fresh
 * success (code 100) and an already-verified repeat (code 101) —
 * callers must still only credit an account once, by gating on the
 * ellsms_payments row's own status, not on this return value alone.
 */
function zarinpal_verify(int $amountRial, string $authority): array {
    $merchantId = zarinpal_merchant_id();
    $result = zarinpal_call('/pg/v4/payment/verify.json', [
        'merchant_id' => $merchantId,
        'amount'      => $amountRial,
        'authority'   => $authority,
    ]);
    if ($result === null) {
        return [false, 'اتصال به زرین‌پال برای تأیید پرداخت برقرار نشد.', null];
    }
    if (!empty($result['errors'])) {
        $msg = is_array($result['errors']) ? ($result['errors']['message'] ?? json_encode($result['errors'], JSON_UNESCAPED_UNICODE)) : (string)$result['errors'];
        return [false, 'خطای زرین‌پال: ' . $msg, null];
    }
    $code = $result['data']['code'] ?? null;
    if ($code == 100 || $code == 101) {
        return [true, $code == 101 ? 'قبلاً تأیید شده بود.' : 'پرداخت تأیید شد.', (string)($result['data']['ref_id'] ?? '')];
    }
    return [false, 'پرداخت تأیید نشد (کد ' . ($code ?? '?') . ').', null];
}
