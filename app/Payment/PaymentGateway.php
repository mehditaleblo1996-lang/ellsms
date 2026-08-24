<?php
/**
 * ELLSMS — generic payment gateway abstraction (financial-commerce continuation, FIN-2).
 *
 * Core commerce logic (public/buy-credit.php, public/billing.php, the shared callback handler) must
 * not depend on ZarinPal-specific field names or call zarinpal_*() functions directly. This file is
 * the contract every gateway adapter implements, plus a name-based dispatcher so a caller only ever
 * writes `payment_gateway_request(payment_gateway_name(), ...)` regardless of which gateway is
 * actually configured.
 *
 * Every adapter function has the SAME shape (a plain array return, never an exception for an
 * ordinary business outcome — network/API failures included, since a payment flow must always be
 * able to show the customer a message rather than a stack trace):
 *
 *   payment_gateway_create(string $gateway, int $amountRial, int $paymentId, string $description, string $mobile): array
 *     -> ['ok'=>bool, 'message'=>string, 'authority'=>?string]
 *
 *   payment_gateway_redirect_url(string $gateway, string $authority): string
 *     -> the URL to send the customer's browser to
 *
 *   payment_gateway_verify(string $gateway, int $amountRial, string $authority): array
 *     -> ['ok'=>bool, 'message'=>string, 'ref_id'=>?string, 'verified_amount_rial'=>?int]
 *        verified_amount_rial is the amount the PROVIDER confirms was actually paid, when the
 *        provider's API exposes it — used by the amount-mismatch fail-closed check (FIN-4/FIN-39).
 *        Null when a provider's verify response doesn't carry a separate confirmed amount (ZarinPal
 *        does not; the fake gateway does, specifically so AMOUNT_MISMATCH mode can be tested).
 *
 *   payment_gateway_supports_refund(string $gateway): bool
 *     -> capability flag only (FIN-13: exposed, never auto-invoked)
 *
 * Preserves existing live ZarinPal behavior exactly — payment_gateway_zarinpal_*() below are thin
 * wrappers around the untouched zarinpal_*() functions in app/zarinpal.php, not a rewrite.
 */

declare(strict_types=1);

require_once __DIR__ . '/../zarinpal.php';
require_once __DIR__ . '/FakeGateway.php';

/** Gateway codes this dispatcher knows about. Adding a real second provider later means adding one entry here plus its own adapter file — never touching a caller. */
const PAYMENT_GATEWAYS = ['zarinpal', 'fake'];

/**
 * Which gateway a NEW payment should use. 'fake' is only ever selectable when the fake gateway is
 * actually enabled (FIN-3) — an operator setting PAYMENT_DEFAULT_GATEWAY=fake in a misconfigured
 * production .env still fails closed to zarinpal, because payment_fake_gateway_enabled() gates it
 * here, not just at the adapter's own entry points.
 */
function payment_gateway_name(): string {
    $configured = (string)(env('PAYMENT_DEFAULT_GATEWAY', 'zarinpal') ?? 'zarinpal');
    if ($configured === 'fake' && !payment_fake_gateway_enabled()) {
        return 'zarinpal';
    }
    return in_array($configured, PAYMENT_GATEWAYS, true) ? $configured : 'zarinpal';
}

function payment_gateway_create(string $gateway, int $amountRial, int $paymentId, string $description, string $mobile = ''): array {
    return match ($gateway) {
        'fake' => payment_fake_gateway_create($amountRial, $paymentId, $description, $mobile),
        default => payment_zarinpal_gateway_create($amountRial, $paymentId, $description, $mobile),
    };
}

function payment_gateway_redirect_url(string $gateway, string $authority): string {
    return match ($gateway) {
        'fake' => payment_fake_gateway_redirect_url($authority),
        default => zarinpal_start_pay_url($authority),
    };
}

function payment_gateway_verify(string $gateway, int $amountRial, string $authority): array {
    return match ($gateway) {
        'fake' => payment_fake_gateway_verify($amountRial, $authority),
        default => payment_zarinpal_gateway_verify($amountRial, $authority),
    };
}

function payment_gateway_supports_refund(string $gateway): bool {
    return match ($gateway) {
        'fake' => true,  // the fake gateway CAN simulate a refund outcome; whether ELLSMS acts on it is FIN-13's separate policy
        default => false, // ZarinPal refund is not implemented in this integration — see docs/financial-commerce.md
    };
}

/* ==========================================================================
   ZarinPal adapter — thin wrapper, zero behavior change from app/zarinpal.php.
   ========================================================================== */

function payment_zarinpal_gateway_create(int $amountRial, int $paymentId, string $description, string $mobile): array {
    [$ok, $message, $authority] = zarinpal_request($amountRial, $paymentId, $description, $mobile);
    return ['ok' => $ok, 'message' => $message, 'authority' => $authority];
}

function payment_zarinpal_gateway_verify(int $amountRial, string $authority): array {
    [$ok, $message, $refId] = zarinpal_verify($amountRial, $authority);
    // ZarinPal's v4 verify response does not separately echo a "confirmed amount" distinct from what
    // was requested — the amount IS the request parameter, and code 100/101 means "yes, that exact
    // amount was captured." verified_amount_rial is therefore null here; the amount-mismatch check
    // (FIN-39) is real and enforced for gateways that DO expose it (the fake gateway), and is
    // correctly a no-op for ZarinPal since there is nothing independent to compare against.
    return ['ok' => $ok, 'message' => $message, 'ref_id' => $refId, 'verified_amount_rial' => null];
}
