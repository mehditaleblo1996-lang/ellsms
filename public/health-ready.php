<?php
/**
 * ELLSMS — readiness check: everything /health checks, plus whether the
 * backend messaging API is currently reachable. "Not ready" means "up,
 * but not currently able to actually send" — useful for a load balancer
 * or orchestrator deciding whether to route traffic here yet, distinct
 * from /health's plain liveness signal.
 *
 * No auth, no page chrome. Also reachable at the bare path /health/ready
 * (see docker/Dockerfile's rewrite). Deliberately NOT under a
 * public/health/ directory — a physical directory named "health" would
 * collide with the bare /health path and make Apache 301-redirect it to
 * /health/ before the rewrite rule ever runs (caught by testing the
 * built image directly). Never returns credentials, hostnames, API
 * URLs, or exception detail — see app/Support/HealthCheck.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$databaseOk   = HealthCheck::database();
$backendApiOk = HealthCheck::backendApi();
$ok = $databaseOk && $backendApiOk;

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status'      => $ok ? 'ok' : 'error',
    'version'     => app_version(),
    'environment' => app_env(),
    'checks' => [
        'php'         => 'ok',
        'database'    => $databaseOk ? 'ok' : 'error',
        'backend_api' => $backendApiOk ? 'ok' : 'error',
    ],
], JSON_UNESCAPED_SLASHES);
