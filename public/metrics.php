<?php
/**
 * ELLSMS — Prometheus scrape endpoint (issue #14).
 *
 * Plain-text exposition format (see app/Observability/PrometheusExporter.php for what's exported
 * and the bounded-cardinality rule every metric here obeys). No page chrome, no session — this is
 * meant to be hit by Prometheus's own scraper, not a browser.
 *
 * Optional bearer-token gate: if METRICS_TOKEN is set, a request must carry it (as
 * "Authorization: Bearer <token>" or "?token=<token>", the latter only because some scrape setups
 * cannot easily set a custom header) or gets 401 with an empty body -- never a hint about what the
 * token should be. Off by default (matching this app's other optional-integration flags, e.g.
 * API_ENABLED) since a fresh install has no secret configured yet and this endpoint carries no
 * credentials/PII, only bounded operational counts -- operators deploying behind a Prometheus that
 * can reach this app directly are expected to also restrict it at the network layer (this app's own
 * docker-compose.yml keeps Prometheus on the same internal network, never publishing its own port).
 */
require_once __DIR__ . '/../app/bootstrap.php';

$expectedToken = (string)env('METRICS_TOKEN', '');
if ($expectedToken !== '') {
    $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $provided = '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $provided = substr($authHeader, 7);
    } elseif (isset($_GET['token'])) {
        $provided = (string)$_GET['token'];
    }
    if (!hash_equals($expectedToken, $provided)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit;
    }
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
try {
    echo PrometheusExporter::render(db());
} catch (Throwable $t) {
    Logger::error('metrics.export_failed', ['exception' => $t]);
    http_response_code(500);
    echo "# export failed\n";
}
