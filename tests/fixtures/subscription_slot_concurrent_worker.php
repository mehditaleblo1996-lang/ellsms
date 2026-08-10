<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/SubscriptionEffectiveSlotConcurrencyTest.php.
 *
 * TD-070 moved `ellsms_subscriptions.effective_organization_id` from a STORED GENERATED column to
 * one written by app/Billing.php. The risk that introduces is specific: a value the DATABASE used to
 * derive is now derived by application code, so "at most one effective subscription" could quietly
 * degrade from a database guarantee into a read-then-write race between two processes. Proving it
 * did not needs two real OS processes with their own MySQL connections — a single PHPUnit process
 * sharing one PDO can only run these calls sequentially.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=mode [7]=organizationId [8]=planId [9]=label
 * mode: 'create'  -> subscription_create()
 *       'cancel'  -> subscription_transition(... 'cancelled')
 *
 * Prints one line of JSON to stdout (last line — Logger mirrors its own output above it).
 */
putenv('APP_ENV=testing');
putenv('BILLING_ENABLED=1');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));

$mode           = (string)($argv[6] ?? 'create');
$organizationId = (int)($argv[7] ?? 0);
$planId         = (int)($argv[8] ?? 0);
$label          = (string)($argv[9] ?? 'w');

require_once __DIR__ . '/../../app/backend.php';

try {
    if ($mode === 'cancel') {
        // No idempotency key on purpose: two genuinely independent cancellation requests, not one
        // retried request. Exactly one may transition; the other must observe it as already done.
        $result = subscription_transition($organizationId, 'cancelled', 'cancelled_immediate', null, null, 'concurrent ' . $label);
    } else {
        $result = subscription_create($organizationId, $planId, 'active', null, 'self_service');
    }
    $result['worker'] = $label;
    $result['exception'] = null;
} catch (Throwable $t) {
    // A unique-index collision surfacing as a PDOException is a legitimate outcome for this race and
    // must be reported, not swallowed — it is the database refusing a second effective subscription.
    $result = ['ok' => false, 'worker' => $label, 'reason' => 'exception', 'exception' => $t->getMessage()];
}

fwrite(STDOUT, "\n" . json_encode($result));
