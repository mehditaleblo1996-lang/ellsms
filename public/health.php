<?php
/**
 * ELLSMS — liveness check: PHP is running and the database is reachable.
 *
 * No auth, no page chrome, no dependency beyond app/bootstrap.php — this
 * has to answer even when something else in the app is broken. Also
 * reachable at the bare path /health (see docker/Dockerfile's rewrite).
 * Never returns credentials, hostnames, DB versions, or exception
 * detail — see app/Support/HealthCheck.php. Full detail always still
 * goes to Logger for internal investigation.
 */
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$databaseOk = HealthCheck::database();
$ok = $databaseOk;

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status'      => $ok ? 'ok' : 'error',
    'version'     => app_version(),
    'environment' => app_env(),
    'checks' => [
        'php'      => 'ok',
        'database' => $databaseOk ? 'ok' : 'error',
    ],
], JSON_UNESCAPED_SLASHES);
