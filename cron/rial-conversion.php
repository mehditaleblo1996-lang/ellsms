<?php
/**
 * ELLSMS — convert every stored amount from the old wallet unit ("credit") to Rial, once
 * (app/RialConversion.php, docs/rial-currency.md).
 *
 *   php cron/rial-conversion.php --dry-run                 report what would change, change nothing
 *   php cron/rial-conversion.php                           convert (maintenance mode must be on)
 *
 * Options:
 *   --factor=N                 Rial value of one old credit (default 1200)
 *   --opening-balance=rial     wallet opening balances from `make wallet-backfill` are already Rial
 *                              and are kept as they are (default)
 *   --opening-balance=credit   treat them as old credits too and multiply them like everything else
 *   --chunk=N                  rows per transaction for the large tables (default 20000)
 *
 * Re-running after it finished does nothing. An interrupted run resumes where it stopped.
 */
require_once __DIR__ . '/../app/backend.php';

$args = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$option = static function (string $name, string $default) use ($args): string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
};

$factorRaw = $option('factor', (string)RIAL_CONVERSION_DEFAULT_FACTOR);
$openingMode = $option('opening-balance', 'rial');
$chunk = max(100, (int)$option('chunk', '20000'));
if (!ctype_digit($factorRaw) || (int)$factorRaw < 1) {
    fwrite(STDERR, "--factor must be a positive whole number of Rial.\n");
    exit(2);
}
if (!in_array($openingMode, ['rial', 'credit'], true)) {
    fwrite(STDERR, "--opening-balance must be 'rial' or 'credit'.\n");
    exit(2);
}
$factor = (int)$factorRaw;
$openingIsRial = $openingMode === 'rial';

if (rial_conversion_done()) {
    echo "Already converted: the wallet is Rial-denominated (factor " . (setting_fresh('rial_conversion.factor') ?? '?') . ', finished ' . (setting_fresh('rial_conversion.finished_at') ?? '?') . " UTC). Nothing to do.\n";
    exit(0);
}

$plan = rial_conversion_plan($factor, $openingIsRial);
$n = static fn(int $v): string => number_format($v);

echo ($dryRun ? '[dry-run] ' : '') . "Rial conversion — 1 old credit = {$factor} Rial\n";
echo '  current rial_per_credit setting: ' . ($plan['rial_per_credit'] ?? '(not set)') . ($plan['rial_per_credit'] !== null && (int)$plan['rial_per_credit'] !== $factor ? '   <-- differs from --factor' : '') . "\n";
echo '  maintenance mode: ' . ($plan['maintenance'] ? 'on' : 'OFF') . "\n";
echo '  migration 2026_10_12_rial_currency.sql: ' . ($plan['schema_ready'] ? 'applied' : 'NOT APPLIED — run make db-migrations-apply first') . "\n";
echo '  opening balances: ' . ($openingIsRial ? 'kept as Rial' : 'multiplied like credits') . " ({$plan['wallets_with_opening']} wallet(s) have one)\n";
echo "\n  wallets: " . count($plan['wallets']) . ", clamped to zero: {$plan['wallets_clamped']}\n";
echo '    available: ' . $n($plan['wallet_totals']['available_before']) . ' -> ' . $n($plan['wallet_totals']['available_after']) . " Rial\n";
echo '    reserved:  ' . $n($plan['wallet_totals']['reserved_before']) . ' -> ' . $n($plan['wallet_totals']['reserved_after']) . " Rial\n";
$listed = 0;
foreach ($plan['wallets'] as $w) {
    // Every clamped wallet is listed (each needs a look); otherwise the first 50 are enough to check.
    if (!$w['clamped'] && $listed >= 50) {
        continue;
    }
    $listed++;
    printf("    user #%d  %s -> %s%s%s\n", $w['user_id'], $n($w['available']), $n($w['new']),
        $w['opening'] !== null ? '  (opening ' . $n($w['opening']) . ')' : '', $w['clamped'] ? '  CLAMPED' : '');
}
if (count($plan['wallets']) > $listed) {
    echo '    ... ' . (count($plan['wallets']) - $listed) . " more\n";
}
echo "\n  active reservations: {$plan['active_reservations']}\n";
echo "  route prices: {$plan['route_prices']}, price snapshots: {$plan['price_snapshots']}, priced bulk rows: {$plan['bulk_items_priced']}\n";
echo "  payments: {$plan['payments']} ({$plan['payments_open']} still open)\n";
foreach ($plan['settings'] as $key => [$old, $new]) {
    echo "  setting {$key}: {$old} -> {$new}\n";
}
if ($plan['today_sms_debits'] > 0) {
    echo "\n  note: {$plan['today_sms_debits']} send charge(s) today are in old credits; today's reseller profit will count them at that value.\n";
}

if ($dryRun) {
    echo "\nDry run — nothing changed.\n";
    exit(0);
}
if (!$plan['maintenance']) {
    fwrite(STDERR, "\nRefusing to convert: turn maintenance mode on first (make maintenance-on).\n");
    exit(1);
}

echo "\nConverting...\n";
try {
    $result = rial_conversion_apply($factor, $openingIsRial, $chunk, static function (string $table, int $done, int $max): void {
        echo "  {$table}: " . number_format($done) . ' / ' . number_format($max) . "\n";
    });
} catch (Throwable $t) {
    Logger::error('rial_conversion.failed', ['exception' => $t]);
    fwrite(STDERR, 'FAILED: ' . $t->getMessage() . "\nRe-run the same command to resume.\n");
    exit(1);
}
foreach ($result as $key => $value) {
    if (is_scalar($value)) {
        echo "  {$key}: " . (is_bool($value) ? ($value ? 'yes' : 'no') : $value) . "\n";
    }
}
echo "Done. Turn maintenance mode off (make maintenance-off) and check `make wallet-audit` and `make sms-pricing-integrity-check`.\n";
