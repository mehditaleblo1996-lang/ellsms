<?php
/**
 * ELLSMS — automated backend-table boundary scan (Phase 8, STEP 1).
 *
 * Static analysis, not a DB check: greps every tracked *.php file for direct SQL access
 * (FROM/JOIN/UPDATE/INSERT INTO/DELETE FROM) to a backend-owned table — `user_`, `domain`,
 * `inbound_message`, `outbound_message` — outside the small set of files allowed to touch them.
 * Requires no database connection and no app bootstrap, so it can run in CI on every commit.
 *
 * Matching is done against each file's FULL CONTENT (not line-by-line), with a real regex word
 * boundary after the table name — this is deliberate: an earlier manual audit in this codebase
 * used `grep -v "user_id"` to skip already-migrated lines, which also silently discarded lines
 * like `JOIN user_ u ON u.id = m.user_id` (the exclude pattern matched a SUBSTRING elsewhere on
 * the same line, not just accidental self-matches), hiding real violations. `\buser_\b` cannot
 * match "user_id"/"user_kyc"/"user_state" — "_" and the following letter are both word
 * characters, so there is no boundary between them — while still matching "user_ u" and
 * "user_\n" correctly. Scanning full content (not per-line) also catches a JOIN/table name split
 * across a line break, which line-based grep would miss entirely.
 *
 * Exit code: 0 if every match is inside an allowlisted path, 1 otherwise (or on usage error).
 *
 * Usage: php cron/backend-boundary-check.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Every file (or file prefix, for directories) allowed to reference a backend-owned table
 * directly. Each entry must be individually justified — see docs/service-boundaries.md.
 */
$allowlist = [
    // This file's own docblock discusses example SQL fragments (illustrating the exact bug this
    // scanner exists to avoid) that would otherwise match its own pattern.
    'cron/backend-boundary-check.php'  => 'this tool\'s own docblock quotes example SQL fragments for illustration, not real queries',

    // The ONE place each backend-owned table is queried from (Phase 8, Invariants A-C).
    'app/Backend/ApiClient.php'        => 'transport client — signs/sends requests, touches no table directly (kept for symmetry with the other adapter files)',
    'app/Backend/credit_projection.php' => 'the one controlled `UPDATE user_ SET currentcredit` write (Invariant G/H) — see STEP 6',
    'app/Backend/identity.php'         => 'identity/domain repository (Invariant B) — every `user_`/`domain` read/write funnels through here',
    'app/Backend/messages.php'         => 'inbound/outbound message repository (Invariant C) — every `inbound_message`/`outbound_message` read funnels through here',

    // Deliberate, documented exception: the cross-boundary orphan/drift audit tool must be able
    // to inspect both sides of the boundary directly — routing it through the adapters it exists
    // to double-check would make it structurally incapable of catching adapter bugs.
    'cron/db-integrity-check.php'      => 'documented exception (Phase 5/8) — orphan/consistency audit tool must read both sides of the boundary directly',

    // Integration tests exercise the REAL shared schema (a real user_/domain/inbound_message/
    // outbound_message table in the disposable test DB) — fixtures seed/read them directly on
    // purpose; routing test setup through the adapters would only test the adapters, not the
    // schema/constraints the adapters assume.
    'tests/'                           => 'integration test fixtures seed/read the real shared schema directly, on purpose',

    // Phase 9's load-test harness plays the exact same role as the integration test fixtures
    // above (seeds/tears down real disposable user_ rows against a real test database it refuses
    // to run without — see its own safety guard), just structured as a cron/ script instead of a
    // PHPUnit test class, since it needs to spawn real OS worker processes rather than run inside
    // one PHPUnit process.
    'cron/load-test.php'               => 'load-test harness seeds/cleans disposable user_ rows in the test database it requires — see its own safety guard',
];

$tables = ['user_', 'domain', 'inbound_message', 'outbound_message'];
$tablePattern = implode('|', array_map('preg_quote', $tables));
$sqlPattern = '/\b(?:FROM|JOIN|UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(' . $tablePattern . ')\b/i';

$scanDirs = ['app', 'public', 'cron', 'tests'];
$files = [];
foreach ($scanDirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

function is_allowlisted(string $relativePath, array $allowlist): ?string {
    foreach ($allowlist as $prefix => $reason) {
        if ($relativePath === $prefix || str_starts_with($relativePath, rtrim($prefix, '/') . '/')) {
            return $reason;
        }
    }
    return null;
}

$violations = [];
$exceptions = [];

foreach ($files as $absolutePath) {
    $relativePath = ltrim(str_replace($root, '', $absolutePath), '/');
    $content = file_get_contents($absolutePath);
    if ($content === false || !preg_match_all($sqlPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $reason = is_allowlisted($relativePath, $allowlist);

    foreach ($matches[0] as $i => $match) {
        [$matchedText, $offset] = $match;
        $table = $matches[1][$i][0];
        $line = substr_count($content, "\n", 0, $offset) + 1;

        if ($reason !== null) {
            $exceptions[] = ['file' => $relativePath, 'line' => $line, 'table' => $table, 'text' => trim($matchedText), 'reason' => $reason];
        } else {
            $violations[] = ['file' => $relativePath, 'line' => $line, 'table' => $table, 'text' => trim($matchedText)];
        }
    }
}

echo "ELLSMS backend-table boundary scan\n";
echo 'Scanned ' . count($files) . " PHP file(s) under " . implode(', ', $scanDirs) . "\n";
echo 'Watched tables: ' . implode(', ', $tables) . "\n";

echo "\n=== Approved exceptions (" . count($exceptions) . ") ===\n";
$byFile = [];
foreach ($exceptions as $e) {
    $byFile[$e['file']][] = $e;
}
foreach ($byFile as $file => $rows) {
    echo "  {$file} — {$rows[0]['reason']}\n";
    echo '    ' . count($rows) . " reference(s), e.g. line {$rows[0]['line']}: {$rows[0]['table']}\n";
}

if ($violations) {
    echo "\n=== VIOLATIONS (" . count($violations) . ") — direct access to a backend-owned table outside the allowlist ===\n";
    foreach ($violations as $v) {
        echo "  {$v['file']}:{$v['line']}  [{$v['table']}]  {$v['text']}\n";
    }
    echo "\nbackend-boundary-check: FAIL\n";
    exit(1);
}

echo "\nbackend-boundary-check: PASS — no direct backend-table access outside the allowlist.\n";
exit(0);
