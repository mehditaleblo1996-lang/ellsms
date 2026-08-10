<?php
/**
 * ELLSMS — SMS pricing configuration integrity audit (docs/sms-pricing.md).
 *
 * Read-only, same two-use-case design as every other integrity tool here (cron/db-integrity-check.php,
 * cron/rbac-integrity-check.php, cron/subscription-integrity-check.php): migration preflight AND
 * ongoing monitor. It NEVER auto-fixes anything — financial configuration is exactly the category
 * where a tool quietly "correcting" a rate would be worse than the misconfiguration it found.
 *
 * Exits non-zero on CRITICAL findings (things that make a send unpriceable, ambiguous, or wrong);
 * warnings are reported but do not fail the run.
 *
 * Usage: php cron/sms-pricing-integrity-check.php
 */
require_once __DIR__ . '/../app/backend.php';

$critical = 0;
$warnings = 0;

function section(string $title): void {
    echo "\n=== {$title} ===\n";
}

/** Reports a COUNT(*) query; returns the count so the caller decides critical vs. warning. */
function count_check(PDO $db, string $sql, string $label, bool $isCritical = true): int {
    $count = (int)$db->query($sql)->fetchColumn();
    $tag = $count > 0 ? ($isCritical ? "[CRITICAL {$count}]" : "[WARN {$count}]") : '[ok]';
    echo "  {$tag} {$label}\n";
    return $count;
}

section('Static scan: no legacy fixed-price arithmetic left in a send path (STEP 26)');
// Before this feature, cost was the literal expression `sms_parts($content) * $count`, duplicated
// across four call sites. Deleting those was the point of the change; this scan is what stops one
// growing back — a new send path that multiplies segments by a count is charging a price the
// pricing engine never resolved, which no test would necessarily catch because the number happens
// to be right on a legacy-parity install.
//
// Runs BEFORE the database checks deliberately: it needs no connection, so it still reports on an
// install whose migration has not been applied yet.
$scanRoots = [dirname(__DIR__) . '/app', dirname(__DIR__) . '/public', dirname(__DIR__) . '/cron'];
// Every entry here is a place the expression is legitimately NOT a price:
//   app/Sms/Pricing.php        — the pricing engine itself, which owns the arithmetic
//   app/Cost/MessageCostEstimator.php — segment COUNTING (it hands segments to the engine to price)
//   app/backend.php            — one informational Persian string reporting total SEGMENTS, not cost
//   public/analytics.php       — a segment-volume breakdown, explicitly not a cost
//   cron/sms-price-simulate.php / this file — tooling about pricing, not a send path
$scanAllowlist = [
    'app/Sms/Pricing.php', 'app/Cost/MessageCostEstimator.php', 'app/backend.php',
    'public/analytics.php', 'cron/sms-price-simulate.php', 'cron/sms-pricing-integrity-check.php',
    'cron/load-test.php',
];
$legacyPattern = '/(sms_parts\s*\([^)]*\)\s*\*|\*\s*sms_parts\s*\(|\$parts\s*\*\s*\$(?!sentCount\b)|\bcredits_per_segment\s*\*)/';
$legacyHits = 0;
foreach ($scanRoots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $relative = ltrim(str_replace(dirname(__DIR__), '', $file->getPathname()), '/\\');
        $relative = str_replace('\\', '/', $relative);
        if (in_array($relative, $scanAllowlist, true)) continue;
        foreach (file($file->getPathname()) ?: [] as $lineNo => $line) {
            if (preg_match($legacyPattern, $line)) {
                echo '  [CRITICAL] ' . $relative . ':' . ($lineNo + 1) . " looks like fixed-price segment arithmetic — price through sms_pricing_price_messages() instead\n";
                $legacyHits++;
            }
        }
    }
}
$critical += $legacyHits;
if ($legacyHits === 0) {
    echo "  [ok] no fixed-price segment arithmetic outside the pricing engine and its documented exceptions\n";
}

// Only NOW is a database connection needed — everything above is a pure static scan, so this tool
// still reports something useful on a machine with no database configured.
$db = db();

section('Catalog presence');
try {
    $db->query('SELECT 1 FROM ellsms_sms_routes LIMIT 1');
} catch (Throwable) {
    echo "  [CRITICAL] pricing tables are missing — run `make db-migrations-apply` (db/migrations/2026_08_09_sms_pricing.sql)\n";
    echo "\nCRITICAL: pricing catalog not installed.\n";
    exit(1);
}
echo "  [ok] pricing tables present\n";

$fallbackOn = sms_pricing_legacy_fallback_enabled();
echo '  [info] legacy fallback (1 credit/segment when nothing is configured): ' . ($fallbackOn ? 'ENABLED' : 'DISABLED — pricing fails closed') . "\n";

section('Operators');
$critical += count_check($db,
    "SELECT COUNT(*) FROM (SELECT code FROM ellsms_sms_operators GROUP BY code HAVING COUNT(*) > 1) x",
    'duplicate operator codes (uniq_operator_code should make this impossible — a raw statement bypassing it, or a widened schema)');
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operators o WHERE o.status = 'active'
     AND NOT EXISTS (SELECT 1 FROM ellsms_sms_operator_prefixes p WHERE p.operator_id = o.id AND p.status = 'active')",
    'active operators with no active prefix — they can never be resolved from a number', false);

section('Prefixes');
$critical += count_check($db,
    "SELECT COUNT(*) FROM (
       SELECT normalized_prefix FROM ellsms_sms_operator_prefixes WHERE status = 'active'
       GROUP BY normalized_prefix HAVING COUNT(*) > 1
     ) x",
    'AMBIGUOUS prefixes — two active rules claiming the same prefix, so longest-prefix matching would be non-deterministic');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operator_prefixes WHERE normalized_prefix NOT REGEXP '^[0-9]+$' OR normalized_prefix = ''",
    'malformed prefixes (non-digit or empty) — prefix matching is digits-only, no pattern syntax');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operator_prefixes WHERE prefix_length <> CHAR_LENGTH(normalized_prefix)",
    'prefix_length disagreeing with normalized_prefix — longest-prefix ordering would be wrong');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operator_prefixes p
     LEFT JOIN ellsms_sms_operators o ON o.id = p.operator_id WHERE o.id IS NULL",
    'orphan prefixes (operator row missing)');
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operator_prefixes p
     JOIN ellsms_sms_operators o ON o.id = p.operator_id
     WHERE p.status = 'active' AND o.status = 'archived'",
    'active prefixes belonging to an archived operator — never matched, safe but misleading', false);

section('Providers & routes');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id
     WHERE r.status = 'active' AND p.status = 'archived'",
    'ACTIVE routes under an ARCHIVED provider — unusable for new price resolution, so any sender pinned to one silently loses its route');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_routes r LEFT JOIN ellsms_sms_providers p ON p.id = r.provider_id WHERE p.id IS NULL",
    'orphan routes (provider row missing)');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_routes WHERE message_type NOT IN ('promotional','transactional','otp','default')",
    'routes with an unknown message type — sms_pricing_normalize_message_type() would never select them');
$critical += count_check($db,
    "SELECT COUNT(*) FROM (
       SELECT message_type FROM ellsms_sms_routes WHERE is_default = 1 AND status = 'active'
       GROUP BY message_type HAVING COUNT(*) > 1
     ) x",
    'more than one ACTIVE default route for a message type — route selection would be ambiguous (uniq_default_route_per_type should prevent this)');

// The route the overwhelming majority of sends fall back to. Without it (and without the legacy
// fallback), every sender lacking an explicit assignment is unpriceable.
$defaultRoutes = (int)$db->query("SELECT COUNT(*) FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id
                                  WHERE r.status = 'active' AND r.is_default = 1 AND p.status = 'active'")->fetchColumn();
if ($defaultRoutes === 0) {
    echo '  ' . ($fallbackOn ? '[WARN]' : '[CRITICAL]') . " no active default route under an active provider — senders without an explicit assignment "
        . ($fallbackOn ? "fall back to 1 credit/segment\n" : "cannot be priced at all\n");
    $fallbackOn ? $warnings++ : $critical++;
} else {
    echo "  [ok] at least one active default route exists under an active provider\n";
}

section('Sender -> route assignments');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sender_routes s LEFT JOIN ellsms_sms_routes r ON r.id = s.route_id WHERE r.id IS NULL",
    'orphan sender assignments (route row missing)');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sender_routes s JOIN ellsms_sms_routes r ON r.id = s.route_id
     WHERE s.status = 'active' AND r.status = 'archived'",
    'active sender assignments pointing at an ARCHIVED route — those senders fall through to the default route unexpectedly');
$critical += count_check($db,
    "SELECT COUNT(*) FROM (
       SELECT sender, message_type FROM ellsms_sender_routes WHERE status = 'active'
       GROUP BY sender, message_type HAVING COUNT(*) > 1
     ) x",
    'a sender with two ACTIVE assignments for the same message type — route selection would be ambiguous');
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sender_routes WHERE sender NOT REGEXP '^[0-9]+$' OR sender = ''",
    'sender assignments whose sender is not digits-only — normalize_originator() would never match them', false);

section('Prices');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE price_per_segment_millicredits = 0 AND status = 'active'",
    'ACTIVE zero prices — a free tariff is expressed by the platform-admin exemption or a plan, never a 0-priced route (0 is indistinguishable from unconfigured)');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE currency <> 'credit'",
    "prices in a currency other than 'credit' — the wallet ledger is credit-denominated and nothing converts");
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE effective_to IS NOT NULL AND effective_to <= effective_from",
    'malformed effective dates (period ends at or before it starts)');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices a
     JOIN ellsms_sms_route_prices b
       ON b.route_id = a.route_id
      AND COALESCE(b.operator_id, 0) = COALESCE(a.operator_id, 0)
      AND b.id > a.id
     WHERE a.status = 'active' AND b.status = 'active'
       AND a.effective_from < COALESCE(b.effective_to, '9999-12-31 23:59:59')
       AND b.effective_from < COALESCE(a.effective_to, '9999-12-31 23:59:59')",
    'OVERLAPPING active price periods for the same route+operator — two rates would be in effect at once');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices rp LEFT JOIN ellsms_sms_routes r ON r.id = rp.route_id WHERE r.id IS NULL",
    'orphan prices (route row missing)');

// Every active route needs SOMETHING priceable right now — a route default, or a specific price for
// every active operator. Reported per route so the fix is obvious.
$now = sms_pricing_now();
$routes = $db->query("SELECT r.id, r.code, r.message_type, p.code AS provider_code
                      FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id
                      WHERE r.status = 'active' AND p.status = 'active' ORDER BY p.code, r.code")->fetchAll();
$activeOperators = $db->query("SELECT id, code FROM ellsms_sms_operators WHERE status = 'active' ORDER BY code")->fetchAll();
$unpriceable = 0;
foreach ($routes as $route) {
    $prices = sms_pricing_prices_for_route((int)$route['id']);
    $hasDefault = sms_pricing_select_price($prices, null, $now) !== null;
    $missing = [];
    foreach ($activeOperators as $op) {
        if (sms_pricing_select_price($prices, (int)$op['id'], $now) === null) {
            $missing[] = $op['code'];
        }
    }
    $label = $route['provider_code'] . '/' . $route['code'] . ' (' . $route['message_type'] . ')';
    if ($hasDefault) {
        echo "  [ok] {$label} — route default price in effect, covers every operator and unknown numbers\n";
    } elseif ($missing === []) {
        echo "  [ok] {$label} — every active operator has a specific price, but NO route default: an UNKNOWN number on this route "
            . ($fallbackOn ? "falls back to 1 credit/segment\n" : "cannot be priced\n");
    } else {
        echo '  ' . ($fallbackOn ? '[WARN]' : '[CRITICAL]') . " {$label} — no route default price and no price for: " . implode(', ', $missing) . "\n";
        $fallbackOn ? $warnings++ : $unpriceable++;
    }
}
$critical += $unpriceable;
if ($routes === []) {
    echo "  [info] no active routes configured\n";
}

section('Uniqueness slot columns (see the migration header for why these are app-maintained)');
// These four columns are what the database's uniqueness guarantees actually index. They are
// maintained by public/sms-pricing.php rather than by a GENERATED expression (that version produced
// dumps the shipped mysqldump could not reload), so drift is possible in a way it would not be with
// a generated column — auditing it here is the explicit price of that trade. Drift is CRITICAL:
// a stale slot means the index is no longer protecting the invariant it exists for.
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_operator_prefixes
     WHERE active_prefix <=> (CASE WHEN status = 'active' THEN normalized_prefix ELSE NULL END) = 0",
    'ellsms_sms_operator_prefixes.active_prefix out of sync with status/normalized_prefix');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_routes
     WHERE default_slot <=> (CASE WHEN is_default = 1 AND status = 'active' THEN message_type ELSE NULL END) = 0",
    'ellsms_sms_routes.default_slot out of sync with is_default/status/message_type');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sender_routes
     WHERE active_slot <=> (CASE WHEN status = 'active' THEN CONCAT(sender, ':', message_type) ELSE NULL END) = 0",
    'ellsms_sender_routes.active_slot out of sync with status/sender/message_type');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE operator_slot <> COALESCE(operator_id, 0)",
    'ellsms_sms_route_prices.operator_slot out of sync with operator_id');

section('Snapshots (historical cost integrity)');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_price_snapshots WHERE committed_cost_credits > total_cost_credits",
    'price snapshots settled for MORE than was accepted — a settlement must never exceed its own reservation');
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_sms_price_snapshots WHERE unit_price_millicredits = 0 AND price_source <> 'admin_exempt'",
    'non-exempt snapshots recorded at a zero unit price', false);

echo "\n";
echo $warnings > 0 ? "{$warnings} warning(s).\n" : "No warnings.\n";
echo $critical > 0
    ? "CRITICAL: {$critical} SMS-pricing configuration violation(s) found — see above. Nothing was changed.\n"
    : "OK: zero critical SMS-pricing configuration violations.\n";

Logger::info('sms_pricing.integrity_check.finished', ['critical' => $critical, 'warnings' => $warnings]);
exit($critical > 0 ? 1 : 0);
