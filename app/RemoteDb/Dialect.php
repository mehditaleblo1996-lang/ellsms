<?php
/**
 * #45 — the three SQL dialects the customer database connector speaks (MySQL/MariaDB, PostgreSQL,
 * SQL Server), the connection itself, and the connection password vault.
 *
 * Every difference between the engines that this feature touches lives here and nowhere else:
 * identifier quoting, "first N rows", DSN/TLS options, connect and statement timeouts, and the
 * datetime literal format. app/RemoteDb/Sync.php builds its statements only through these helpers.
 */

declare(strict_types=1);

class RemoteDbException extends AppException {}

/** Quotes a validated identifier ('table' or 'schema.table'). Refuses anything not already valid. */
function remote_db_quote_identifier(string $driver, string $name): string {
    if (!remote_db_identifier_valid($name, true)) {
        throw new RemoteDbException('invalid identifier');
    }
    $parts = explode('.', $name);
    $quoted = array_map(static fn(string $p): string => match ($driver) {
        'mysql'  => '`' . $p . '`',
        'sqlsrv' => '[' . $p . ']',
        default  => '"' . $p . '"',
    }, $parts);
    return implode('.', $quoted);
}

/**
 * "SELECT <cols> FROM <from> WHERE <where> ORDER BY <order>" limited to $limit rows, in the dialect's
 * own syntax. $limit is an int, never a parameter, because SQL Server's TOP and MySQL's LIMIT do not
 * both take a bound value portably.
 */
function remote_db_select_limited(string $driver, string $columns, string $from, string $where, string $orderBy, int $limit): string {
    $limit = max(1, $limit);
    if ($driver === 'sqlsrv') {
        return "SELECT TOP ({$limit}) {$columns} FROM {$from} WHERE {$where} ORDER BY {$orderBy}";
    }
    return "SELECT {$columns} FROM {$from} WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit}";
}

/**
 * Datetime as a string every engine reads the same way regardless of its locale settings:
 * ISO 8601 with a 'T'. SQL Server in particular misreads 'YYYY-MM-DD hh:mm:ss' under some
 * SET DATEFORMAT / language settings, but never the 'T' form.
 */
function remote_db_datetime(?int $timestamp = null): string {
    return date('Y-m-d\TH:i:s', $timestamp ?? time());
}

/** Whether the PDO driver for $driver is compiled into this PHP. */
function remote_db_driver_available(string $driver): bool {
    $pdo = REMOTE_DB_DRIVERS[$driver]['pdo'] ?? null;
    return $pdo !== null && in_array(substr($pdo, 4), PDO::getAvailableDrivers(), true);
}

/**
 * The DSN + PDO options for one connection row. Host and database name were validated on save; they
 * are checked again here because a DSN is a string format and this is the last line before it.
 *
 * @return array{0: string, 1: array}
 */
function remote_db_dsn(array $conn): array {
    $driver = (string)$conn['driver'];
    $host = (string)$conn['host'];
    $port = (int)$conn['port'];
    $dbName = (string)$conn['database_name'];
    if (!isset(REMOTE_DB_DRIVERS[$driver]) || !remote_db_host_valid($host) || !remote_db_database_name_valid($dbName) || $port < 1 || $port > 65535) {
        throw new RemoteDbException('invalid connection settings');
    }
    $connectTimeout = max(1, min(120, (int)($conn['connect_timeout_s'] ?? 10)));
    $tls = (string)($conn['tls_mode'] ?? 'prefer');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    switch ($driver) {
        case 'mysql':
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $options[PDO::ATTR_TIMEOUT] = $connectTimeout;
            if ($tls === 'require' || $tls === 'verify') {
                // An empty CA path asks for an encrypted connection without pinning a CA; 'verify'
                // additionally checks the server certificate against the system trust store.
                $options[defined('Pdo\Mysql::ATTR_SSL_CA') ? constant('Pdo\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
                $options[defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $tls === 'verify';
            }
            break;
        case 'pgsql':
            $sslmode = match ($tls) { 'disable' => 'disable', 'require' => 'require', 'verify' => 'verify-full', default => 'prefer' };
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode};connect_timeout={$connectTimeout}";
            break;
        case 'sqlsrv':
            // ODBC Driver 18 encrypts by default; 'prefer' keeps that but trusts the server's
            // certificate, which is what almost every on-premise SQL Server needs.
            $encrypt = $tls === 'disable' ? 'no' : 'yes';
            $trust = $tls === 'verify' ? 'no' : 'yes';
            $dsn = "sqlsrv:Server={$host},{$port};Database={$dbName};LoginTimeout={$connectTimeout};Encrypt={$encrypt};TrustServerCertificate={$trust}";
            break;
        default:
            throw new RemoteDbException('unsupported driver');
    }
    return [$dsn, $options];
}

/** Opens a connection and applies the per-statement timeout. Throws RemoteDbException with a safe message. */
function remote_db_connect(array $conn, string $password): PDO {
    $driver = (string)$conn['driver'];
    if (!remote_db_driver_available($driver)) {
        throw new RemoteDbException('درایور ' . (REMOTE_DB_DRIVERS[$driver]['pdo'] ?? $driver) . ' روی این سرور نصب نیست (docker/Dockerfile).');
    }
    [$dsn, $options] = remote_db_dsn($conn);
    $queryTimeout = max(5, min(600, (int)($conn['query_timeout_s'] ?? 60)));
    if ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
        $options[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = $queryTimeout;
    }
    try {
        $pdo = new PDO($dsn, (string)$conn['username'], $password, $options);
    } catch (PDOException $e) {
        throw new RemoteDbException(remote_db_safe_error($e), 0, $e);
    }
    try {
        if ($driver === 'pgsql') {
            $pdo->exec('SET statement_timeout = ' . ($queryTimeout * 1000));
        } elseif ($driver === 'mysql') {
            // MySQL caps SELECTs in ms; MariaDB names it differently (seconds, every statement).
            // Whichever one the server does not know simply fails and is ignored.
            try { $pdo->exec('SET SESSION max_execution_time = ' . ($queryTimeout * 1000)); } catch (PDOException) {}
            try { $pdo->exec('SET SESSION max_statement_time = ' . $queryTimeout); } catch (PDOException) {}
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        }
    } catch (PDOException $e) {
        throw new RemoteDbException(remote_db_safe_error($e), 0, $e);
    }
    return $pdo;
}

/**
 * A driver error made safe to store and show: one line, bounded, and with the password removed in the
 * unlikely case a driver echoed the DSN or credentials back.
 */
function remote_db_safe_error(Throwable $e, ?string $password = null): string {
    $msg = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
    if ($password !== null && $password !== '') {
        $msg = str_replace($password, '***', $msg);
    }
    $msg = preg_replace('/(password|pwd)\s*=\s*[^;\s]+/i', '$1=***', $msg) ?? $msg;
    return mb_strimwidth(trim($msg), 0, 480, '…');
}

/** Whether a PDO error is the kind that means "the connection itself is gone" (reconnect next time). */
function remote_db_is_connection_error(Throwable $e): bool {
    $m = strtolower($e->getMessage());
    foreach (['gone away', 'lost connection', 'server closed', 'connection', 'communication link', 'broken pipe', 'tcp provider', 'timeout', 'timed out', '08s01', '08001', '08006', 'hyt00'] as $needle) {
        if (str_contains($m, $needle)) return true;
    }
    return false;
}

/* ---------- Password vault ----------
 * Same construction as the gateway secret vault (app/Sms/GatewaySecrets.php: AES-256-GCM under an
 * HKDF-derived key from SMS_GATEWAY_MASTER_KEY) but its OWN purpose string, so a key derived for one
 * feature can never decrypt the other's data.
 */
const REMOTE_DB_SECRET_KEY_PURPOSE = 'ellsms.remote_db.password.v1';

function remote_db_secret_key(): ?string {
    $master = (string)env('SMS_GATEWAY_MASTER_KEY', '');
    if (strlen($master) < 32) {
        return null;
    }
    return hash_hkdf('sha256', $master, 32, REMOTE_DB_SECRET_KEY_PURPOSE);
}

function remote_db_secret_fingerprint(): string {
    $key = remote_db_secret_key();
    return $key === null ? '' : substr(hash('sha256', $key . '|fingerprint'), 0, 16);
}

/** @return array{ciphertext: string, nonce: string, tag: string, fingerprint: string} */
function remote_db_encrypt_password(string $plaintext): array {
    $key = remote_db_secret_key();
    if ($key === null) {
        throw new RemoteDbException('برای ذخیره‌ی رمز دیتابیس، SMS_GATEWAY_MASTER_KEY (حداقل ۳۲ نویسه) باید تنظیم شده باشد.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RemoteDbException('رمزگذاری رمز دیتابیس ممکن نشد.');
    }
    return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag, 'fingerprint' => remote_db_secret_fingerprint()];
}

function remote_db_decrypt_password(array $conn): string {
    if ($conn['password_ciphertext'] === null || $conn['password_ciphertext'] === '') {
        return '';
    }
    $key = remote_db_secret_key();
    if ($key === null) {
        throw new RemoteDbException('SMS_GATEWAY_MASTER_KEY تنظیم نشده؛ رمز دیتابیس قابل خواندن نیست.');
    }
    if ((string)$conn['key_fingerprint'] !== '' && (string)$conn['key_fingerprint'] !== remote_db_secret_fingerprint()) {
        throw new RemoteDbException('رمز این اتصال با کلید اصلی دیگری ذخیره شده است؛ رمز را دوباره وارد کنید.');
    }
    $plain = openssl_decrypt((string)$conn['password_ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, (string)$conn['password_nonce'], (string)$conn['password_tag']);
    if ($plain === false) {
        throw new RemoteDbException('رمزگشایی رمز دیتابیس ممکن نشد.');
    }
    return $plain;
}
