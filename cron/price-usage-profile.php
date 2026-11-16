<?php
/**
 * ELLSMS — rebuilds the usage profile the price-change preview prices (app/PriceChanges.php,
 * docs/price-changes.md): the last 30 Tehran days of sent messages, grouped by account, sending line,
 * destination operator and encoding. Run by the scheduler job `price-usage-profile` once a day.
 *
 *   php cron/price-usage-profile.php
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/PriceChanges.php';

if (!price_changes_available()) {
    echo "price usage profile: migration 2026_11_20_price_changes.sql not applied, nothing to build.\n";
    exit(0);
}
$meta = price_usage_profile_build();
printf("profile %s .. %s: %d groups, %d messages\n", $meta['from'], $meta['to'], $meta['groups'], $meta['messages']);
