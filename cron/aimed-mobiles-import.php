<?php
/**
 * ELLSMS — load the aimed-bulk subscriber bank from Vesal's table exports (docs/aimed-bulk.md).
 *
 * Export each table from Vesal's Oracle database as CSV with its header row (UTF-8), then:
 *
 *   php cron/aimed-mobiles-import.php --provinces=TBL_PROVINCE.csv --cities=TBL_CITY.csv --mobiles=TBL_MOBILE.csv
 *
 * Any of the three can be given alone; when they come together, provinces are loaded before cities
 * (TBL_CITY points at TBL_PROVINCE.ID). Every load is an upsert keyed by the number / code, so running
 * it again with a newer export refreshes the bank instead of duplicating it.
 *
 *   --dry-run      read and validate only; write nothing
 *   --batch=N      rows per INSERT for the mobiles file (default 1000)
 *   --stats        only recount the bank for the admin page (also done after every real load)
 */
require_once __DIR__ . '/../app/backend.php';

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$dryRun = !empty($opts['dry-run']);
$files = array_filter([
    'provinces' => is_string($opts['provinces'] ?? null) ? $opts['provinces'] : null,
    'cities'    => is_string($opts['cities'] ?? null) ? $opts['cities'] : null,
    'mobiles'   => is_string($opts['mobiles'] ?? null) ? $opts['mobiles'] : null,
]);
if ($files === [] && empty($opts['stats'])) {
    fwrite(STDERR, "usage: php cron/aimed-mobiles-import.php [--provinces=FILE] [--cities=FILE] [--mobiles=FILE] [--dry-run] [--batch=N] [--stats]\n");
    exit(2);
}
foreach ($files as $kind => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "{$kind}: cannot read {$path}\n");
        exit(2);
    }
}

$exit = 0;
foreach ($files as $kind => $path) {
    $started = microtime(true);
    echo ($dryRun ? '[dry run] ' : '') . "{$kind}: {$path}\n";
    try {
        $stats = match ($kind) {
            'provinces' => aimed_import_provinces($path, $dryRun),
            'cities'    => aimed_import_cities($path, $dryRun),
            'mobiles'   => aimed_import_mobiles($path, $dryRun, max(1, (int)($opts['batch'] ?? 1000)), static function (array $s): void {
                if ($s['imported'] % 100000 === 0) echo '  … ' . number_format($s['imported']) . " rows\n";
            }),
        };
    } catch (Throwable $t) {
        fwrite(STDERR, "{$kind}: {$t->getMessage()}\n");
        $exit = 1;
        continue;
    }
    printf("  read %s, %s %s, skipped %s invalid (%.1fs)\n",
        number_format($stats['read']), $dryRun ? 'valid' : 'imported', number_format($stats['imported']),
        number_format($stats['invalid']), microtime(true) - $started);
    Logger::info('aimed_bulk.import', ['kind' => $kind, 'dry_run' => $dryRun] + $stats);
}
if (!$dryRun) {
    $bank = aimed_bulk_bank_stats_refresh();
    printf("bank now holds %s numbers (%s sendable), %s provinces, %s cities\n",
        number_format($bank['total']), number_format($bank['sendable']), number_format($bank['provinces']), number_format($bank['cities']));
}
exit($exit);
