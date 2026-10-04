<?php
/**
 * The API guide PDF (integrations/ELLSMS-API-Guide.pdf) for any signed-in user — /developers/api-guide.
 *
 * Login is the only gate, deliberately lower than integrations.php's API_KEYS_VIEW: the guide is how a
 * member learns what the API is before anyone grants them a key, and it carries no secret, only the
 * public contract. It is still not a public file, which is why it is streamed from outside public/
 * through require_login() instead of being linked as a static asset.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Integrations.php';
require_login();

$path = integration_repo_root() . '/' . INTEGRATION_API_GUIDE_PDF;
if (!is_file($path)) {
    Logger::error('api_guide.missing', ['path' => INTEGRATION_API_GUIDE_PDF]);
    http_response_code(404);
    exit('فایل راهنما پیدا نشد.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ELLSMS-API-Guide.pdf"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
// private: a shared proxy must never hand a signed-in user's response to someone who is not.
header('Cache-Control: private, max-age=3600');
readfile($path);
