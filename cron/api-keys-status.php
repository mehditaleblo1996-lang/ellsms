<?php
/**
 * ELLSMS — public API key inventory snapshot (Phase 12, STEP 54).
 *
 * Read-only. Reports active/revoked/expired counts per organization and flags keys that have never
 * been used or haven't been used in a long time — an operational hygiene signal, never a security
 * gate on its own. Exit code is always 0 (this is informational, not a pass/fail check like
 * backup-status.php).
 *
 * Usage:
 *   php cron/api-keys-status.php
 *   php cron/api-keys-status.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$rows = db()->query(
    "SELECT k.id, k.organization_id, o.name AS organization_name, k.name, k.key_prefix, k.environment,
            k.status, k.expires_at, k.last_used_at, k.created_at
     FROM ellsms_api_keys k JOIN ellsms_organizations o ON o.id = k.organization_id
     ORDER BY o.name, k.created_at DESC"
)->fetchAll();

$active = 0;
$revoked = 0;
$expired = 0;
$neverUsed = 0;
$staleDays90 = 0;
foreach ($rows as $r) {
    $isExpired = $r['expires_at'] !== null && strtotime($r['expires_at']) <= time();
    if ($r['status'] === 'revoked') {
        $revoked++;
    } elseif ($isExpired) {
        $expired++;
    } else {
        $active++;
    }
    if ($r['status'] === 'active' && !$isExpired) {
        if ($r['last_used_at'] === null) {
            $neverUsed++;
        } elseif (strtotime($r['last_used_at']) < time() - 90 * 86400) {
            $staleDays90++;
        }
    }
}

$result = [
    'total_keys'   => count($rows),
    'active'       => $active,
    'revoked'      => $revoked,
    'expired_not_revoked' => $expired,
    'never_used'   => $neverUsed,
    'unused_90d'   => $staleDays90,
    'keys'         => array_map(static fn($r) => [
        'organization_id'   => (int)$r['organization_id'],
        'organization_name' => $r['organization_name'],
        'name'              => $r['name'],
        'key_prefix'        => $r['key_prefix'],
        'environment'       => $r['environment'],
        'status'            => $r['status'],
        'expires_at'        => $r['expires_at'],
        'last_used_at'      => $r['last_used_at'],
    ], $rows),
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS API key inventory\n\n";
    echo "  Total:              {$result['total_keys']}\n";
    echo "  Active:             {$active}\n";
    echo "  Revoked:            {$revoked}\n";
    echo "  Expired (not revoked yet): {$expired}\n";
    echo "  Never used:         {$neverUsed}\n";
    echo "  Unused > 90 days:   {$staleDays90}\n";
}
exit(0);
