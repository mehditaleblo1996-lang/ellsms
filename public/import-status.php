<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();
$jobId = (int)($_GET['id'] ?? 0);
$organizationId = isset($me['organization_id']) ? (int)$me['organization_id'] : null;

$job = import_load_job($jobId, is_admin() ? null : $organizationId);
if ($job === null || (!is_admin() && (int)$job['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$percent = $job['total_rows'] > 0
    ? min(100, (int)round(($job['processed_rows'] / $job['total_rows']) * 100))
    : 0;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'id' => (int)$job['id'],
    'status' => (string)$job['status'],
    'total_rows' => (int)$job['total_rows'],
    'processed_rows' => (int)$job['processed_rows'],
    'valid_rows' => (int)$job['valid_rows'],
    'invalid_rows' => (int)$job['invalid_rows'],
    'duplicate_rows' => (int)$job['duplicate_rows'],
    'blacklisted_rows' => (int)$job['blacklisted_rows'],
    'priced_rows' => (int)$job['priced_rows'],
    'unpriced_rows' => (int)$job['unpriced_rows'],
    'queued_rows' => (int)$job['queued_rows'],
    'estimated_cost_credits' => (int)$job['estimated_cost_credits'],
    'percent' => $percent,
    'error_message' => $job['error_message'] ? (string)$job['error_message'] : null,
], JSON_UNESCAPED_UNICODE);
