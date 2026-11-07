<?php
/**
 * ELLSMS — AI provider credit check (app/Ai/AiBalance.php, docs/ai-engines.md §Provider balance and
 * alerts). Run by the scheduler job `ai-balance` every 15 minutes.
 *
 * For every AI provider: reads the credit left (OpenRouter's API, or the estimate from recorded
 * top-ups minus spend), the days it lasts at the last 7 days' spend, and raises or resolves the
 * ai_balance:<code> alert (Telegram + email through AlertManager).
 *
 *   php cron/ai-balance-check.php
 */
require_once __DIR__ . '/../app/backend.php';

if (!ai_engines_available() || !ai_balance_available()) {
    echo "AI provider balance: migration 2026_11_09_ai_provider_balance.sql not applied, nothing to check.\n";
    exit(0);
}

$failed = false;
foreach (ai_balance_check_all() as $r) {
    printf("%-24s %-10s %s\n", $r['provider'], $r['level'], $r['balance'] ?? '-');
    $failed = $failed || $r['level'] === 'error';
}
exit($failed ? 1 : 0);
