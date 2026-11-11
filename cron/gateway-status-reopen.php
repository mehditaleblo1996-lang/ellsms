<?php
/**
 * ELLSMS — reopen delivery reports a gateway's old status mapping closed (docs/sms-gateway-connectors.md
 * §PishgamRayan). After a mapping change turns a provider code from final (failed) into non-final (sent),
 * rows already stored as failed with that code are set back to the new state, so the status poller
 * checks the recent ones again and a later "delivered" is recorded. Older rows simply read «ارسال‌شده».
 * Walks the tables by id in chunks; safe to run again (a reopened row no longer matches).
 *
 *   php cron/gateway-status-reopen.php --gateway=pishgamrayan            dry run: how many rows
 *   php cron/gateway-status-reopen.php --gateway=pishgamrayan --apply    reopen them
 *   --chunk=5000                                                         ids per statement
 */
require_once __DIR__ . '/../app/backend.php';

$args = $argv ?? [];
$value = static function (string $name) use ($args): ?string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) return substr($arg, strlen($name) + 3);
    }
    return null;
};
$code = trim((string)$value('gateway'));
if ($code === '') {
    fwrite(STDERR, "usage: php cron/gateway-status-reopen.php --gateway=<code> [--apply] [--chunk=5000]\n");
    exit(2);
}
$st = db()->prepare('SELECT id FROM ellsms_sms_gateways WHERE code = ?');
$st->execute([$code]);
$gatewayId = (int)$st->fetchColumn();
if ($gatewayId <= 0) {
    fwrite(STDERR, "no gateway with code '{$code}'\n");
    exit(1);
}
$apply = in_array('--apply', $args, true);
$result = gateway_status_reopen($gatewayId, $apply, max(100, (int)($value('chunk') ?? 5000)));
if ($result['tokens'] === []) {
    echo "{$code}: its status mapping has no non-final code — nothing to reopen\n";
    exit(0);
}
$tokens = implode(', ', array_map(static fn($t, $s) => "{$t}→{$s}", array_keys($result['tokens']), $result['tokens']));
echo ($apply ? 'reopened' : 'would reopen (dry run, add --apply)') . " — {$code} [{$tokens}]: "
    . "bulk items {$result['bulk_item']}, direct-send attempts {$result['attempt']}\n";
