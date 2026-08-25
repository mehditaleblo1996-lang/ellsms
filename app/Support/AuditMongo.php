<?php
/**
 * MongoDB-backed request/audit event store.
 *
 * This layer is deliberately fail-open for the application: MongoDB/audit failures are written to
 * PHP's error log and NEVER break login, sending, billing, admin actions, or any other request.
 * Secrets are redacted before they ever leave PHP memory.
 */
declare(strict_types=1);

function audit_mongo_enabled(): bool {
    $v = getenv('AUDIT_MONGO_ENABLED');
    return $v === false || $v === '' ? true : $v === '1';
}

function audit_mongo_database(): string {
    $v = getenv('AUDIT_MONGO_DATABASE');
    return ($v === false || $v === '') ? 'ellsms_audit' : $v;
}

function audit_mongo_uri(): string {
    $v = getenv('AUDIT_MONGO_URI');
    return ($v === false || $v === '') ? 'mongodb://mongo:27017/ellsms_audit' : $v;
}

function audit_mongo_manager(): ?MongoDB\Driver\Manager {
    static $manager = null;
    static $failed = false;
    if (!audit_mongo_enabled() || $failed) return null;
    if (!extension_loaded('mongodb')) {
        $failed = true;
        error_log('ELLSMS audit: ext-mongodb is not loaded; Mongo audit disabled for this process');
        return null;
    }
    if ($manager instanceof MongoDB\Driver\Manager) return $manager;
    try {
        $manager = new MongoDB\Driver\Manager(audit_mongo_uri(), [
            'connectTimeoutMS' => 250,
            'serverSelectionTimeoutMS' => 250,
        ]);
        return $manager;
    } catch (Throwable $e) {
        $failed = true;
        error_log('ELLSMS audit Mongo connect failed: ' . $e->getMessage());
        return null;
    }
}

function audit_setting_int(string $key, int $default, int $min, int $max): int {
    try {
        if (function_exists('setting')) {
            $v = (int)setting($key, (string)$default);
            return max($min, min($max, $v));
        }
    } catch (Throwable) {
        // DB/settings may be unavailable while an error page is shutting down.
    }
    return $default;
}

function audit_http_retention_days(): int {
    return audit_setting_int('audit_http_retention_days', 90, 1, 3650);
}

function audit_security_retention_days(): int {
    return audit_setting_int('audit_security_retention_days', 365, 1, 3650);
}

function audit_is_security_event(string $eventType): bool {
    foreach (['auth.', 'security.', 'account.', 'admin.', 'settings.', 'impersonation.', 'audit.', 'mutation.'] as $prefix) {
        if (str_starts_with($eventType, $prefix)) return true;
    }
    return false;
}

function audit_redact_key(string $key): bool {
    $k = strtolower($key);
    foreach ([
        'password', 'passwd', 'pass', 'pwd', 'current', 'new', 'repeat',
        'token', 'secret', 'authorization', 'cookie', 'csrf', '_csrf',
        'api_key', 'apikey', 'merchant_id', 'webhook_master_key', 'otp', 'twofa', '2fa'
    ] as $needle) {
        if ($k === $needle || str_contains($k, $needle)) return true;
    }
    return false;
}

function audit_sanitize_value(mixed $value, int $depth = 0): mixed {
    if ($depth > 3) return '[DEPTH_LIMIT]';
    if (is_array($value)) {
        $out = [];
        $count = 0;
        foreach ($value as $k => $v) {
            if (++$count > 50) { $out['_truncated'] = true; break; }
            $key = (string)$k;
            $out[$key] = audit_redact_key($key) ? '[REDACTED]' : audit_sanitize_value($v, $depth + 1);
        }
        return $out;
    }
    if (is_object($value)) return '[OBJECT]';
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    $s = (string)$value;
    if (mb_strlen($s, 'UTF-8') > 1000) $s = mb_substr($s, 0, 1000, 'UTF-8') . '…';
    return $s;
}

function audit_sanitized_request_data(): array {
    $data = [];
    if ($_GET) $data['query'] = audit_sanitize_value($_GET);
    if ($_POST) $data['form'] = audit_sanitize_value($_POST);
    if ($_FILES) {
        $files = [];
        foreach ($_FILES as $name => $f) {
            $files[$name] = [
                'name' => isset($f['name']) ? basename((string)$f['name']) : '',
                'type' => (string)($f['type'] ?? ''),
                'size' => (int)($f['size'] ?? 0),
                'error' => (int)($f['error'] ?? 0),
            ];
        }
        $data['files'] = $files;
    }
    return $data;
}

function audit_request_id(): string {
    if (class_exists('Logger') && method_exists('Logger', 'requestId')) {
        try { return (string)Logger::requestId(); } catch (Throwable) {}
    }
    return substr(hash('sha256', (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) . '|' . random_int(1, PHP_INT_MAX)), 0, 24);
}

function audit_actor_context(): array {
    $ctx = [
        'user_id' => null,
        'username' => null,
        'role' => null,
        'organization_id' => null,
        'actor_user_id' => null,
    ];
    try {
        if (function_exists('current_user')) {
            $u = current_user();
            if ($u) {
                $ctx['user_id'] = (int)$u['id'];
                $ctx['username'] = (string)($u['username'] ?? '');
                $ctx['role'] = (string)($u['role'] ?? '');
                $ctx['organization_id'] = isset($u['organization_id']) ? (int)$u['organization_id'] : null;
                $ctx['actor_user_id'] = (int)$u['id'];
            }
        }
        if (function_exists('is_impersonating') && is_impersonating() && function_exists('real_actor_user_id')) {
            $ctx['actor_user_id'] = (int)real_actor_user_id();
        }
    } catch (Throwable) {}
    return $ctx;
}

function audit_client_ip_safe(): string {
    try {
        if (function_exists('client_ip')) return (string)client_ip();
    } catch (Throwable) {}
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function audit_mongo_ensure_indexes(): void {
    static $done = false;
    if ($done) return;
    $manager = audit_mongo_manager();
    if (!$manager) return;
    try {
        $db = audit_mongo_database();
        $manager->executeCommand($db, new MongoDB\Driver\Command([
            'createIndexes' => 'audit_events',
            'indexes' => [
                ['key' => ['expires_at' => 1], 'name' => 'ttl_expires_at', 'expireAfterSeconds' => 0],
                ['key' => ['timestamp' => -1], 'name' => 'idx_timestamp'],
                ['key' => ['user_id' => 1, 'timestamp' => -1], 'name' => 'idx_user_time'],
                ['key' => ['organization_id' => 1, 'timestamp' => -1], 'name' => 'idx_org_time'],
                ['key' => ['event_type' => 1, 'timestamp' => -1], 'name' => 'idx_event_time'],
                ['key' => ['ip' => 1, 'timestamp' => -1], 'name' => 'idx_ip_time'],
                ['key' => ['path' => 1, 'timestamp' => -1], 'name' => 'idx_path_time'],
                ['key' => ['request_id' => 1], 'name' => 'idx_request_id'],
            ],
        ]));
        $done = true;
    } catch (Throwable $e) {
        error_log('ELLSMS audit index ensure failed: ' . $e->getMessage());
    }
}

function audit_mongo_event(string $eventType, array $extra = [], ?bool $securityRetention = null): void {
    $manager = audit_mongo_manager();
    if (!$manager) return;
    try {
        audit_mongo_ensure_indexes();
        $nowMs = (int)round(microtime(true) * 1000);
        $security = $securityRetention ?? audit_is_security_event($eventType);
        $days = $security ? audit_security_retention_days() : audit_http_retention_days();
        $actor = audit_actor_context();
        $doc = array_merge([
            'timestamp' => new MongoDB\BSON\UTCDateTime($nowMs),
            'expires_at' => new MongoDB\BSON\UTCDateTime($nowMs + ($days * 86400000)),
            'event_type' => $eventType,
            'request_id' => audit_request_id(),
            'user_id' => $actor['user_id'],
            'username' => $actor['username'],
            'role' => $actor['role'],
            'organization_id' => $actor['organization_id'],
            'actor_user_id' => $actor['actor_user_id'],
            'ip' => audit_client_ip_safe(),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8'),
        ], audit_sanitize_value($extra));
        $bulk = new MongoDB\Driver\BulkWrite(['ordered' => false]);
        $bulk->insert($doc);
        $manager->executeBulkWrite(audit_mongo_database() . '.audit_events', $bulk);
    } catch (Throwable $e) {
        error_log('ELLSMS audit write failed: ' . $e->getMessage());
    }
}

function audit_infer_action_event(string $path, string $method): ?string {
    if ($method === 'POST' && $path === '/login.php') return http_response_code() >= 300 && http_response_code() < 400 ? 'auth.login_success' : 'auth.login_failed';
    if ($method === 'POST' && $path === '/logout.php') return 'auth.logout';
    if ($method !== 'POST' && !in_array($method, ['PUT','PATCH','DELETE'], true)) return null;
    $action = trim((string)($_POST['do'] ?? ''));
    if ($path === '/profile.php' && $action === 'password') return 'account.password_change_attempt';
    if ($path === '/settings.php') return 'settings.' . ($action !== '' ? $action : 'update');
    $slug = trim(str_replace(['.php','/'], ['', '.'], $path), '.');
    return 'mutation.' . ($slug !== '' ? $slug : 'request') . ($action !== '' ? '.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $action) : '');
}

function audit_request_register(): void {
    if (PHP_SAPI === 'cli' || !audit_mongo_enabled()) return;
    $started = microtime(true);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $requestData = audit_sanitized_request_data();

    register_shutdown_function(static function () use ($started, $method, $path, $requestData): void {
        $status = http_response_code();
        if ($status <= 0) $status = 200;
        audit_mongo_event('http.request', [
            'method' => $method,
            'path' => $path,
            'status_code' => $status,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'referer' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000, 'UTF-8'),
            'request' => $requestData,
        ], false);

        $event = audit_infer_action_event($path, $method);
        if ($event !== null) {
            audit_mongo_event($event, [
                'method' => $method,
                'path' => $path,
                'status_code' => $status,
                'request' => $requestData,
            ], true);
        }
    });
}

/** @return list<array<string,mixed>> */
function audit_mongo_list(array $filters, int $limit = 100, ?string $beforeId = null): array {
    $manager = audit_mongo_manager();
    if (!$manager) return [];
    audit_mongo_ensure_indexes();
    $q = [];
    foreach (['event_type','username','ip','path','request_id'] as $key) {
        $v = trim((string)($filters[$key] ?? ''));
        if ($v !== '') $q[$key] = $v;
    }
    if (($filters['user_id'] ?? '') !== '') $q['user_id'] = (int)$filters['user_id'];
    if ($beforeId) {
        try { $q['_id'] = ['$lt' => new MongoDB\BSON\ObjectId($beforeId)]; } catch (Throwable) {}
    }
    try {
        $cursor = $manager->executeQuery(audit_mongo_database() . '.audit_events', new MongoDB\Driver\Query($q, [
            'sort' => ['_id' => -1],
            'limit' => max(1, min(201, $limit)),
        ]));
        $out = [];
        foreach ($cursor as $doc) {
            $a = (array)$doc;
            $a['_id'] = isset($a['_id']) ? (string)$a['_id'] : '';
            foreach (['timestamp','expires_at'] as $dk) {
                if (($a[$dk] ?? null) instanceof MongoDB\BSON\UTCDateTime) {
                    $a[$dk] = $a[$dk]->toDateTime()->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
                }
            }
            $out[] = $a;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('ELLSMS audit query failed: ' . $e->getMessage());
        return [];
    }
}
