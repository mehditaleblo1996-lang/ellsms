<?php
/**
 * Runs the shared SDK conformance scenario against a real ELLSMS API.
 * Env: ELLSMS_BASE_URL, ELLSMS_API_KEY, ELLSMS_MOBILE, ELLSMS_ORIGINATOR, ELLSMS_WH_SECRET, ELLSMS_WH_TS,
 *      ELLSMS_WH_BODY, ELLSMS_WH_SIG. Prints "OK <checks>" and exits 0, or prints the failure and exits 1.
 */

declare(strict_types=1);

require __DIR__ . '/../src/EllsmsException.php';
require __DIR__ . '/../src/Webhook.php';
require __DIR__ . '/../src/Client.php';

use Ellsms\Client;
use Ellsms\EllsmsException;
use Ellsms\Webhook;

$checks = 0;
function check(bool $condition, string $what): void {
    global $checks;
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$what}\n");
        exit(1);
    }
    $checks++;
}
function expectError(callable $fn, int $status, string $code, string $what): void {
    try {
        $fn();
    } catch (EllsmsException $e) {
        check($e->getStatus() === $status && $e->getErrorCode() === $code, "{$what} (got {$e->getStatus()} {$e->getErrorCode()})");
        check($e->getRequestId() !== null && $e->getRequestId() !== '', "{$what}: request id");
        return;
    }
    check(false, "{$what}: no error raised");
}

try {
    $sms = new Client(getenv('ELLSMS_API_KEY'), getenv('ELLSMS_BASE_URL'), 20, 0);
    $mobile = getenv('ELLSMS_MOBILE');

    $me = $sms->me();
    check(isset($me['organization_id']), 'me() has organization_id');
    $balance = $sms->balance();
    check(isset($balance['available']) && is_int($balance['available']), 'balance() available');

    $created = $sms->createContact(['mobile' => $mobile, 'name' => 'SDK', 'group' => 'sdk-test']);
    $id = (string)$created['id'];
    check($id !== '', 'createContact id');
    expectError(static fn() => $sms->createContact(['mobile' => $mobile, 'name' => 'SDK', 'group' => 'sdk-test']), 409, 'conflict', 'duplicate contact');
    check($sms->getContact($id)['name'] === 'SDK', 'getContact');
    check($sms->updateContact($id, ['name' => 'سلام'])['name'] === 'سلام', 'updateContact keeps unicode');
    $found = false;
    $seen = 0;
    foreach ($sms->eachContact(1) as $c) {
        $seen++;
        if ((string)$c['id'] === $id) $found = true;
    }
    check($found && $seen >= 2, 'eachContact pages through everything');
    $sms->deleteContact($id);
    expectError(static fn() => $sms->getContact($id), 404, 'not_found', 'deleted contact');

    $job = ['type' => 'p2p', 'title' => 'sdk', 'originator' => getenv('ELLSMS_ORIGINATOR'), 'items' => [['mobile' => $mobile, 'content' => 'hi']]];
    $key = Client::newIdempotencyKey();
    $first = $sms->createBulkJob($job, $key);
    $again = $sms->createBulkJob($job, $key);
    check($first['id'] === $again['id'], 'same idempotency key replays the same job');
    check(isset($sms->getBulkJob($first['id'])['status']), 'getBulkJob');
    expectError(static fn() => $sms->createBulkJob(['type' => 'nope', 'items' => []]), 422, 'validation_failed', 'invalid bulk job');

    $bad = new Client('ellsms_live_000000000000_' . str_repeat('x', 40), getenv('ELLSMS_BASE_URL'), 20, 0);
    expectError(static fn() => $bad->me(), 401, 'unauthenticated', 'bad key');

    $secret = getenv('ELLSMS_WH_SECRET');
    $ts = getenv('ELLSMS_WH_TS');
    $body = getenv('ELLSMS_WH_BODY');
    $sig = getenv('ELLSMS_WH_SIG');
    check(Webhook::verify($secret, $ts, $body, $sig), 'webhook signature verifies');
    check(!Webhook::verify($secret, $ts, $body . ' ', $sig), 'tampered body rejected');
    check(!Webhook::verify($secret, (string)((int)$ts - 3600), $body, $sig), 'stale timestamp rejected');
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . get_class($e) . ": " . $e->getMessage() . ($e instanceof EllsmsException ? " " . json_encode($e->getFields()) : "") . "\n");
    exit(1);
}
echo "OK {$checks}\n";
