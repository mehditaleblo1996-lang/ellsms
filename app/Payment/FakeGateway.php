<?php
/**
 * ELLSMS — fake/sandbox payment gateway (financial-commerce continuation, FIN-3).
 *
 * Exists ONLY so this project's own financial-flow tests (and manual test-server exploration) can
 * exercise the REAL create -> callback -> verify -> claim -> fulfill pipeline end to end without ever
 * contacting ZarinPal or any other real provider. No external network call anywhere in this file.
 *
 * SAFETY (mirrors docs/sms-load-testing.md's mock-gateway safety model):
 *   - payment_fake_gateway_enabled() is OFF by default (ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=0).
 *   - payment_gateway_name() (PaymentGateway.php) refuses to select 'fake' unless this returns true,
 *     even if PAYMENT_DEFAULT_GATEWAY=fake is set — so a stray env value alone cannot enable it.
 *   - Every function below ALSO independently refuses to act when disabled (defense in depth: even a
 *     caller that bypassed payment_gateway_name() and called payment_fake_gateway_create() directly
 *     gets refused, not a fake success).
 *
 * MODES, selected via the `mobile` field at creation time (fake-only convenience — a real gateway has
 * no such concept, so this never touches the create() contract's real fields) OR via an explicit
 * $mode parameter for direct test use: SUCCESS, FAILED, CANCELLED, TIMEOUT, VERIFY_FAILURE,
 * AMOUNT_MISMATCH, DUPLICATE_CALLBACK. Encoded into the authority string itself
 * ("FAKE-{mode}-{random}") so the later verify() call — which only receives the authority, exactly
 * like a real callback — can recover which mode this payment was created under without any extra
 * state table. This is deliberately NOT bypassing verification: verify() still runs, still returns a
 * real ok/fail outcome, and the caller (the shared callback handler, FIN-4) still goes through the
 * exact same claim-then-fulfill transaction as a real ZarinPal payment would.
 */

declare(strict_types=1);

const PAYMENT_FAKE_MODES = ['SUCCESS', 'FAILED', 'CANCELLED', 'TIMEOUT', 'VERIFY_FAILURE', 'AMOUNT_MISMATCH', 'DUPLICATE_CALLBACK'];

function payment_fake_gateway_enabled(): bool {
    return (env('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED', '0') ?? '0') === '1';
}

/** Extracts the mode encoded in a fake authority string; SUCCESS if the string is malformed (fails closed to the safest observable behavior, not to an error). */
function payment_fake_gateway_mode_from_authority(string $authority): string {
    if (preg_match('/^FAKE-([A-Z_]+)-/', $authority, $m) && in_array($m[1], PAYMENT_FAKE_MODES, true)) {
        return $m[1];
    }
    return 'SUCCESS';
}

/**
 * $mobile carries the requested mode for test convenience (e.g. "test:AMOUNT_MISMATCH") — real
 * mobile numbers never match this pattern, so a genuine mobile value falls through to the SUCCESS
 * default exactly as if no mode were specified.
 */
function payment_fake_gateway_create(int $amountRial, int $paymentId, string $description, string $mobile = ''): array {
    if (!payment_fake_gateway_enabled()) {
        return ['ok' => false, 'message' => 'درگاه آزمایشی پرداخت فعال نیست.', 'authority' => null];
    }
    if ($amountRial <= 0) {
        return ['ok' => false, 'message' => 'مبلغ نامعتبر است.', 'authority' => null];
    }

    $mode = 'SUCCESS';
    if (preg_match('/^test:([A-Z_]+)$/', $mobile, $m) && in_array($m[1], PAYMENT_FAKE_MODES, true)) {
        $mode = $m[1];
    }

    if ($mode === 'FAILED') {
        return ['ok' => false, 'message' => 'پرداخت آزمایشی ناموفق بود (FAILED mode).', 'authority' => null];
    }
    if ($mode === 'TIMEOUT') {
        return ['ok' => false, 'message' => 'اتصال به درگاه آزمایشی زمان‌بر شد (TIMEOUT mode).', 'authority' => null];
    }

    // CANCELLED/AMOUNT_MISMATCH/VERIFY_FAILURE/DUPLICATE_CALLBACK all still succeed at the CREATE
    // step (a real gateway also always issues an authority even for a payment the customer will
    // later cancel or that will fail verification) — the interesting behavior happens at verify().
    $authority = 'FAKE-' . $mode . '-' . bin2hex(random_bytes(12));
    Logger::info('payment.fake_gateway.created', ['payment_id' => $paymentId, 'mode' => $mode, 'amount_rial' => $amountRial]);
    return ['ok' => true, 'message' => 'ok', 'authority' => $authority];
}

/** Not a real external URL — the fake gateway has no checkout page. Points at the SAME callback URL a real gateway would redirect back to, with Status/Authority pre-filled, so a test can literally "click pay" by following this URL exactly like a browser redirect would. */
function payment_fake_gateway_redirect_url(string $authority): string {
    $mode = payment_fake_gateway_mode_from_authority($authority);
    $status = $mode === 'CANCELLED' ? 'NOK' : 'OK';
    return '/zarinpal-callback.php?Authority=' . urlencode($authority) . '&Status=' . $status . '&fake=1';
}

function payment_fake_gateway_verify(int $amountRial, string $authority): array {
    if (!payment_fake_gateway_enabled()) {
        return ['ok' => false, 'message' => 'درگاه آزمایشی پرداخت فعال نیست.', 'ref_id' => null, 'verified_amount_rial' => null];
    }
    if (!str_starts_with($authority, 'FAKE-')) {
        return ['ok' => false, 'message' => 'authority آزمایشی نامعتبر است.', 'ref_id' => null, 'verified_amount_rial' => null];
    }

    $mode = payment_fake_gateway_mode_from_authority($authority);

    if ($mode === 'VERIFY_FAILURE') {
        return ['ok' => false, 'message' => 'تأیید پرداخت آزمایشی ناموفق بود (VERIFY_FAILURE mode).', 'ref_id' => null, 'verified_amount_rial' => null];
    }

    // AMOUNT_MISMATCH: the "provider" reports a DIFFERENT confirmed amount than what was requested —
    // this is the exact scenario FIN-39 requires the caller to fail closed on. Returned as
    // verified_amount_rial rather than making ok=false directly, because the whole point is to
    // exercise the CALLER'S OWN amount-comparison logic, not to shortcut past it here.
    $verifiedAmount = $amountRial;
    if ($mode === 'AMOUNT_MISMATCH') {
        $verifiedAmount = $amountRial + 1; // deliberately off by the smallest possible unit — the comparison must be exact, not "close enough"
    }

    // DUPLICATE_CALLBACK carries no special verify() behavior of its own — verify() is idempotent by
    // nature (a provider's verify endpoint is safe to call more than once; ZarinPal's own code 101
    // "already verified" already models this). The actual duplicate-callback guarantee under test is
    // the CALLER's claim-then-fulfill transaction (payment_claim_and_credit()'s atomic UPDATE), which
    // this mode exists to let a test invoke verify()+claim() ten times in a row against — every call
    // legitimately reports ok=true, exactly like a real gateway would for a repeated verify.
    $refId = 'FAKE-REF-' . substr(hash('sha256', $authority), 0, 12);
    Logger::info('payment.fake_gateway.verified', ['mode' => $mode, 'requested_amount_rial' => $amountRial, 'verified_amount_rial' => $verifiedAmount]);
    return ['ok' => true, 'message' => 'ok', 'ref_id' => $refId, 'verified_amount_rial' => $verifiedAmount];
}
