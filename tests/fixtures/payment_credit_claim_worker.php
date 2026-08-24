<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/FakePaymentGatewayE2eTest.php — FIN-16/31's
 * concurrent-callback criterion: two processes attempt to claim-and-credit the SAME credit-purchase
 * payment simultaneously (two racing fake-gateway callbacks, or a browser retry racing
 * cron/payments-reconcile.php). Exactly one may claim; the wallet must be credited exactly once.
 *
 * Same "real processes, real connections" rationale as tests/fixtures/subscription_activation_worker.php.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=paymentId [7]=refId
 */
putenv('APP_ENV=testing');
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

$result = payment_claim_and_credit($payment, $refId);
fwrite(STDOUT, json_encode($result));
