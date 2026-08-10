<?php
/**
 * ELLSMS — payment reconciliation (Phase 3, STEP 15).
 *
 * Recovers payments where ZarinPal succeeded but local processing was
 * interrupted or ambiguous:
 *   - status = 'verification_failed' (the verify() API call itself
 *     didn't succeed last time — network error, ZarinPal API error, or a
 *     non-100/101 code — possibly transient; see docs/security-review.md
 *     finding 6 and STEP 14's state-machine split).
 *   - status = 'pending' older than PAYMENT_RECONCILE_STALE_MINUTES
 *     (default 15) — the user's browser never returned to the callback
 *     URL at all (closed the tab, lost connectivity before the redirect),
 *     a real, previously-unrecovered gap documented in
 *     docs/flows/payment.md.
 *
 * For each candidate, re-runs zarinpal_verify() against ZarinPal itself
 * (the actual source of truth for whether money moved) and, only on a
 * fresh success, credits the wallet via the exact same
 * payment_claim_and_credit() transaction public/zarinpal-callback.php
 * uses — so running this script can never double-credit a payment that a
 * user's own browser callback already processed (or that a previous
 * reconciliation run already processed), and running it twice in a row is
 * always safe.
 *
 * Manual/on-demand only in this phase, per explicit instruction not to
 * build a scheduler here — invoke via:
 *   make payments-reconcile
 * or
 *   php cron/payments-reconcile.php [--stale-minutes=N] [--dry-run]
 *
 * Never touches ellsms_payments rows that are already 'paid' or 'failed'
 * (a real user cancellation, from STEP 14, is a final outcome this script
 * does not attempt to override).
 */
require_once __DIR__ . '/../app/zarinpal.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$staleMinutes = 15;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--stale-minutes=')) {
        $staleMinutes = max(1, (int)substr($arg, strlen('--stale-minutes=')));
    }
}

$db = db();
$candidates = $db->prepare(
    "SELECT * FROM ellsms_payments
     WHERE status = 'verification_failed'
        OR (status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))
     ORDER BY id"
);
$candidates->execute([$staleMinutes]);
$rows = $candidates->fetchAll();

Logger::info('payments.reconcile.started', ['candidate_count' => count($rows), 'stale_minutes' => $staleMinutes, 'dry_run' => $dryRun]);

$recovered = 0;
$stillUnresolved = 0;
$errors = 0;

foreach ($rows as $payment) {
    $paymentId = (int)$payment['id'];
    try {
        if (empty($payment['authority'])) {
            // Never even got an authority from ZarinPal — nothing to
            // verify; this one genuinely never started a real payment.
            $stillUnresolved++;
            continue;
        }

        if ($dryRun) {
            Logger::info('payments.reconcile.would_check', ['payment_id' => $paymentId, 'status' => $payment['status']]);
            continue;
        }

        [$ok, $info, $refId] = zarinpal_verify((int)$payment['amount_rial'], $payment['authority']);
        if (!$ok) {
            Logger::warning('payments.reconcile.still_unverified', ['payment_id' => $paymentId, 'info' => $info]);
            $db->prepare("UPDATE ellsms_payments SET status='verification_failed' WHERE id=? AND status IN ('pending','verification_failed')")
               ->execute([$paymentId]);
            $stillUnresolved++;
            continue;
        }

        // Phase 13: routes to the same claim-and-activate path public/zarinpal-callback.php uses for
        // a subscription payment — a subscription charge whose browser callback never arrived must
        // recover here exactly like a credit purchase does, and must never be wallet-credited by
        // mistake (STEP 33).
        $isSubscriptionPayment = ($payment['purpose'] ?? 'credit') === 'subscription';
        $result = $isSubscriptionPayment
            ? payment_claim_and_activate_subscription($payment, $refId)
            : payment_claim_and_credit($payment, $refId);
        if ($result['claimed']) {
            if ($isSubscriptionPayment) {
                audit((int)$payment['user_id'], 'billing.subscription.paid', "#{$paymentId} ref={$refId} (reconciled) activated=" . (int)$result['activated']);
                Logger::info('payments.reconcile.subscription_recovered', [
                    'payment_id' => $paymentId,
                    'activated'  => $result['activated'],
                    'reason'     => $result['reason'],
                ]);
            } else {
                audit((int)$payment['user_id'], 'payment.paid', "#{$paymentId} +{$payment['credits']}cr ref={$refId} (reconciled)");
                Logger::info('payments.reconcile.recovered', [
                    'payment_id' => $paymentId,
                    'user_id'    => $payment['user_id'],
                    'credits'    => $payment['credits'],
                ]);
            }
            $recovered++;
        } else {
            // Someone else (a live callback hit, or a concurrent
            // reconcile run) already claimed this row between our SELECT
            // and now — not an error, just nothing left to do.
            Logger::info('payments.reconcile.already_claimed', ['payment_id' => $paymentId]);
        }
    } catch (Throwable $t) {
        Logger::error('payments.reconcile.row_failed', ['payment_id' => $paymentId, 'exception' => $t]);
        $errors++;
    }
}

Logger::info('payments.reconcile.finished', [
    'candidate_count'  => count($rows),
    'recovered'        => $recovered,
    'still_unresolved' => $stillUnresolved,
    'errors'           => $errors,
    'dry_run'          => $dryRun,
]);

fwrite(STDOUT, sprintf(
    "Payment reconciliation: %d candidate(s), %d recovered, %d still unresolved, %d error(s)%s.\n",
    count($rows), $recovered, $stillUnresolved, $errors, $dryRun ? ' (dry run — nothing changed)' : ''
));
