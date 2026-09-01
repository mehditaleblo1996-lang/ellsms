<?php
/**
 * ELLSMS — lightweight active provider health probe runner (issue #16).
 *
 * The actual probe/check logic lives in app/Sms/ProviderHealth.php (provider_health_active_probe(),
 * provider_health_active_check_one_pass()) so it can be unit/integration-tested directly without
 * executing this file's run-loop as a side effect -- this file is only the CLI entrypoint, matching
 * this codebase's established split between a worker's library functions (app/Backend/
 * report_summary_cache.php, app/BulkArchive.php, ...) and its thin cron run-loop.
 *
 * Usage:
 *   php cron/provider-health-check.php          # one pass over every active gateway, then exit
 *   php cron/provider-health-check.php --loop    # repeat every PROVIDER_HEALTH_CHECK_INTERVAL_SECONDS
 */
require_once __DIR__ . '/../app/backend.php';

$loop = in_array('--loop', $argv ?? [], true);

do {
    $started = microtime(true);
    try {
        $checked = Metrics::time('provider_health.active_check.pass', fn() => provider_health_active_check_one_pass());
        Logger::info('provider_health.active_check.pass_completed', [
            'checked' => $checked, 'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        ]);
    } catch (Throwable $t) {
        Logger::critical('provider_health.active_check.pass_failed', ['exception' => $t]);
    }
    if (!$loop) {
        break;
    }
    sleep(provider_health_check_interval_seconds());
} while (true);

exit(0);
