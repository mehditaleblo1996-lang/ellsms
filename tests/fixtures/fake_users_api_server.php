<?php
/**
 * Router for PHP's built-in webserver standing in for the backend platform's account endpoint,
 * POST /api/users/ — used ONLY by SubaccountsHttpTest to drive a reseller's «ساخت زیرمجموعه» end to
 * end. It writes the new account into the shared test database's user_ stand-in, the way the real
 * endpoint would, and answers 201 with the new id.
 *
 * Usage: php -S 127.0.0.1:<port> tests/fixtures/fake_users_api_server.php
 * Reads the same BACKEND_DB_* environment the app uses.
 */
declare(strict_types=1);

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || rtrim($path, '/') !== '/api/users') {
    http_response_code(404);
    echo json_encode(['detail' => 'not found']);
    return;
}
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT') ?: '3306', getenv('BACKEND_DB_NAME')),
    (string)getenv('BACKEND_DB_USER'),
    (string)getenv('BACKEND_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$exists = $pdo->prepare('SELECT COUNT(*) FROM user_ WHERE username = ?');
$exists->execute([(string)($body['username'] ?? '')]);
if ((int)$exists->fetchColumn() > 0) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['detail' => 'username already exists']);
    return;
}
$pdo->prepare('INSERT INTO user_ (username, password, firstname, lastname, email, mobile, active, deleted) VALUES (?,?,?,?,?,?,1,0)')
    ->execute([(string)$body['username'], hash('sha256', (string)$body['password']), (string)$body['first_name'], (string)$body['last_name'], (string)$body['email'], (string)$body['mobile']]);
http_response_code(201);
header('Content-Type: application/json');
echo json_encode(['id' => (int)$pdo->lastInsertId(), 'username' => (string)$body['username']]);
