<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/BillingPaymentTest.php — the Phase 13
 * STEP 34/53 concurrent-activation criterion: two processes attempt to activate the SAME
 * subscription payment simultaneously (a duplicate ZarinPal callback racing a reconciliation pass,
 * the real-world shape of this). Exactly one must activate; no duplicate period extension.
 *
 * Same "real processes, real connections" rationale as tests/fixtures/wallet_concurrent_debit_worker.php.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=paymentId [7]=refId
 */
putenv('APP_ENV=testing');
putenv('BILLING_ENABLED=1');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));

$paymentId = (int)($argv[6] ?? 0);
$refId     = (string)($argv[7] ?? '');

require_once __DIR__ . '/../../app/zarinpal.php';

$st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
$st->execute([$paymentId]);
$payment = $st->fetch();
if (!$payment) {
    fwrite(STDOUT, json_encode(['error' => 'payment_not_found']));
    exit(1);
}

$result = payment_claim_and_activate_subscription($payment, $refId);
fwrite(STDOUT, json_encode($result));
