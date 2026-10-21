<?php
/**
 * ELLSMS — a reseller's brand logo (app/ResellerPanel.php), served from storage/brand-logos outside
 * the web root. Public: the logo shows on the reseller's login page. Only a stored PNG/JPEG/WebP
 * file name of the reseller's current settings is ever read.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$resellerId = (int)($_GET['r'] ?? 0);
$file = $resellerId > 0 ? reseller_settings($resellerId)['logo_file'] : null;
$path = $file !== null && preg_match('/^\d+-[a-f0-9]{12}\.(png|jpg|webp)$/', $file, $m) ? reseller_brand_logo_dir() . '/' . $file : null;
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$m[1]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
