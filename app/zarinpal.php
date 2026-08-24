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
    // Next: an explicit, admin-controlled APP_URL — safer than deriving
    // from the request below, since $_SERVER['HTTP_HOST'] reflects
    // whatever Host header the client sent, not necessarily the real
    // domain.
    if (app_url() !== '') return app_url() . '/zarinpal-callback.php';
    // Last resort: derive from the current request — convenient default
    // for local/dev use, but a real ZarinPal merchant account typically
    // expects a fixed, pre-registered domain, so either setting above
    // always wins when configured.
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

/**
 * Atomically claim a pending/verification_failed payment row and credit
 * the wallet in ONE transaction (Phase 3, STEP 13) — the single code path
 * both public/zarinpal-callback.php (a live browser return from
 * checkout) and cron/payments-reconcile.php (STEP 15, catching payments
 * that succeeded at the gateway but whose browser never came back) use,
 * so the atomicity/idempotency guarantee only has to be gotten right
 * once. Caller must already know $refId (from a fresh zarinpal_verify()
 * call) and must NOT call this unless that verification already
 * succeeded.
 *
 * Returns ['claimed' => bool, 'credit' => array|null] — claimed=false
 * means someone else already processed this exact payment (replay), not
 * an error.
 */
/**
 * Phase 13 (STEP 32/34) — the SUBSCRIPTION counterpart of payment_claim_and_credit() below.
 * Deliberately a separate function rather than a branch inside that one: a subscription payment
 * credits no wallet, and a credit purchase activates no subscription (STEP 33 — the two remain
 * explicitly different concepts). What they DO share, on purpose, is the exact same proven
 * transaction-integrity model:
 *
 *   - the atomic claim `UPDATE ... WHERE status IN ('pending','verification_failed')` is what makes
 *     a duplicate/retried ZarinPal callback a no-op: only the request that actually flips the row
 *     to 'paid' proceeds, every other one sees rowCount()===0 and does nothing;
 *   - everything happens in ONE transaction, so a crash can never leave a payment marked paid with
 *     no subscription activated (or vice versa);
 *   - the subscription transition itself carries an idempotency key derived from the payment id, a
 *     second independent guard against double-activation even if this were somehow re-entered
 *     outside the claim (Invariant I).
 *
 * Returns ['claimed'=>bool, 'activated'=>bool, 'reason'=>string]. claimed=false means someone else
 * already processed this exact payment — a replay, not an error.
 */
function payment_claim_and_activate_subscription(array $payment, string $refId): array {
    return db_transaction(function (PDO $db) use ($payment, $refId): array {
        $claim = $db->prepare("UPDATE ellsms_payments SET status='paid', ref_id=? WHERE id=? AND status IN ('pending','verification_failed')");
        $claim->execute([$refId, $payment['id']]);
        if ($claim->rowCount() === 0) {
            return ['claimed' => false, 'activated' => false, 'reason' => 'already_processed'];
        }

        // FIN-4: mark the invoice paid in the SAME transaction as the payment claim — this is what
        // makes "invoice paid exactly once" hold under a duplicate/concurrent callback, the identical
        // reasoning as the payment claim itself. A payment created before the invoice layer existed
        // (or one whose invoice creation failed for some other reason) simply has no invoice row to
        // mark — billing_invoice_mark_paid() returning false here is not an error.
        if (isset($payment['invoice_id']) && $payment['invoice_id'] !== null) {
            billing_invoice_mark_paid($db, (int)$payment['invoice_id']);
        }

        $billingRecordId = isset($payment['billing_record_id']) ? (int)$payment['billing_record_id'] : 0;
        if ($billingRecordId <= 0) {
            return ['claimed' => true, 'activated' => false, 'reason' => 'no_billing_record'];
        }

        $brSt = $db->prepare('SELECT * FROM ellsms_billing_records WHERE id = ? FOR UPDATE');
        $brSt->execute([$billingRecordId]);
        $record = $brSt->fetch();
        if (!$record) {
            return ['claimed' => true, 'activated' => false, 'reason' => 'billing_record_missing'];
        }
        // Ownership re-check (STEP 50/51): the billing record and the payment must belong to the
        // SAME organization. Both were written server-side at request time, so a mismatch means
        // tampering or a bug — either way, refuse rather than activate a subscription paid for by
        // somebody else's transaction.
        if ((int)$record['organization_id'] !== (int)($payment['organization_id'] ?? 0)) {
            Logger::critical('billing.payment.organization_mismatch', ['payment_id' => $payment['id'], 'billing_record_id' => $billingRecordId]);
            return ['claimed' => true, 'activated' => false, 'reason' => 'organization_mismatch'];
        }

        $organizationId = (int)$record['organization_id'];
        $planId = (int)$record['plan_id'];
        $idempotencyKey = 'payment_activation:' . $payment['id'];

        $existing = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE effective_organization_id = ? FOR UPDATE');
        $existing->execute([$organizationId]);
        $subscription = $existing->fetch();

        $periodMonths = $record['billing_period'] === 'yearly' ? 12 : ($record['billing_period'] === 'monthly' ? 1 : 0);
        $periodEnd = $periodMonths > 0 ? gmdate('Y-m-d H:i:s', billing_add_months(time(), $periodMonths)) : null;

        if ($subscription) {
            // Extend/upgrade in place. The event row's idempotency key is what stops a concurrent
            // second activation of the SAME payment from extending the period twice (STEP 34).
            if (!subscription_record_event($db, (int)$subscription['id'], $organizationId, 'activated_by_payment', $subscription['status'], 'active', (int)$subscription['plan_id'], $planId, null, $idempotencyKey, "payment={$payment['id']}")) {
                return ['claimed' => true, 'activated' => false, 'reason' => 'already_activated'];
            }
            $db->prepare(
                "UPDATE ellsms_subscriptions
                 SET plan_id = ?, status = 'active', current_period_start = UTC_TIMESTAMP(), current_period_end = ?,
                     pending_plan_id = NULL, cancel_at_period_end = 0, suspended_at = NULL, grace_ends_at = NULL, source = 'payment',
                     effective_organization_id = ?
                 WHERE id = ?"
            )->execute([$planId, $periodEnd, billing_effective_organization_id($organizationId, 'active'), $subscription['id']]);
            $subscriptionId = (int)$subscription['id'];
        } else {
            $db->prepare(
                "INSERT INTO ellsms_subscriptions (organization_id, plan_id, status, current_period_start, current_period_end, source, effective_organization_id)
                 VALUES (?,?, 'active', UTC_TIMESTAMP(), ?, 'payment', ?)"
            )->execute([$organizationId, $planId, $periodEnd, billing_effective_organization_id($organizationId, 'active')]);
            $subscriptionId = (int)$db->lastInsertId();
            if (!subscription_record_event($db, $subscriptionId, $organizationId, 'activated_by_payment', null, 'active', null, $planId, null, $idempotencyKey, "payment={$payment['id']}")) {
                return ['claimed' => true, 'activated' => false, 'reason' => 'already_activated'];
            }
        }

        $db->prepare("UPDATE ellsms_billing_records SET status='paid', subscription_id=?, paid_at=UTC_TIMESTAMP() WHERE id=?")
           ->execute([$subscriptionId, $billingRecordId]);

        Logger::info('billing.subscription.activated_by_payment', ['organization_id' => $organizationId, 'subscription_id' => $subscriptionId, 'payment_id' => $payment['id'], 'plan_id' => $planId]);
        Metrics::increment('billing.subscription.activated', 1, ['plan_code' => $record['plan_code']]);

        return ['claimed' => true, 'activated' => true, 'reason' => 'activated', 'subscription_id' => $subscriptionId, 'organization_id' => $organizationId];
    });
}

function payment_claim_and_credit(array $payment, string $refId): array {
    $result = db_transaction(function (PDO $db) use ($payment, $refId): array {
        $claim = $db->prepare("UPDATE ellsms_payments SET status='paid', ref_id=? WHERE id=? AND status IN ('pending','verification_failed')");
        $claim->execute([$refId, $payment['id']]);
        if ($claim->rowCount() === 0) {
            return ['claimed' => false, 'credit' => null];
        }

        // FIN-4: same reasoning as payment_claim_and_activate_subscription() above — mark the
        // invoice paid inside the SAME transaction as the payment claim, so a duplicate/concurrent
        // callback can never mark it paid twice or credit the wallet while leaving the invoice
        // 'issued'.
        if (isset($payment['invoice_id']) && $payment['invoice_id'] !== null) {
            billing_invoice_mark_paid($db, (int)$payment['invoice_id']);
        }

        $credit = wallet_credit(
            (int)$payment['user_id'], (int)$payment['credits'], 'purchase', 'payment', (string)$payment['id'],
            'payment_credit:' . $payment['id']
        );
        return ['claimed' => true, 'credit' => $credit];
    });

    // Phase 12 (STEP 27/28): fired only for the caller that actually WON the claim (a replay from
    // either public/zarinpal-callback.php or cron/payments-reconcile.php racing the same payment
    // correctly sees claimed=false and emits nothing) — and only after the crediting transaction
    // above has already committed. Skipped for a payment with no organization_id (pre-tenant-backfill).
    if ($result['claimed'] && ($result['credit']['ok'] ?? false) && isset($payment['organization_id']) && $payment['organization_id'] !== null) {
        try {
            webhook_event_emit((int)$payment['organization_id'], WebhookEvents::PAYMENT_CREDITED, 'payment', (string)$payment['id'], [
                'payment_id' => (int)$payment['id'],
                'user_id'    => (int)$payment['user_id'],
                'credits'    => (int)$payment['credits'],
                'ref_id'     => $refId,
            ]);
        } catch (Throwable $t) {
            Logger::error('webhook.event.emit_failed', ['payment_id' => $payment['id'] ?? null, 'exception' => $t]);
        }
    }

    return $result;
}
