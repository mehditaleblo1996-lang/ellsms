<?php
/** The panel topbar's credit box refresh (app/views/header.php): the signed-in user's balance, as JSON. */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$organization = current_organization();
$credit = (int)$me['credit'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'ok' => true,
    'credit' => $credit,
    'credit_text' => to_persian_digits(number_format($credit)),
    'level' => profile_credit_level_for($credit, $organization ? (int)$organization['organization_id'] : 0),
], JSON_UNESCAPED_UNICODE);
