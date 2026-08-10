<?php
/**
 * ELLSMS — centralized structured logging.
 *
 * Replaces scattered error_log() calls across app/ and public/ with one
 * event-based API: Logger::info('sms.send.requested', ['user_id' => ...]).
 * Each entry is one JSON line in storage/logs/ellsms-YYYY-MM-DD.log
 * (application code never touches the filesystem directly). No
 * autoloader exists in this project (no Composer/vendor/), so this file
 * is loaded via a plain require_once from app/bootstrap.php, the same
 * way every other shared helper file is.
 *
 * No namespace — kept in the global namespace like every other class-free
 * function in this codebase, so call sites can just write Logger::info(...)
 * with no `use` statement, matching how the rest of the app is written.
 */

declare(strict_types=1);

final class Logger
{
    private const LEVELS = [
        'debug'    => 0,
        'info'     => 1,
        'warning'  => 2,
        'error'    => 3,
        'critical' => 4,
    ];

    /**
     * Context KEYS matched against this (case-insensitive) are never
     * written as-is — their value is replaced with '[REDACTED]' no
     * matter what level or event logs them. This is the hard rule from
     * the project's logging policy: never log passwords, 2FA codes, API
     * secrets, payment secrets, or full identity documents. Matching is
     * deliberately broad (substring, not exact) so a slightly different
     * key name (password_confirmation, new_password, otp_code) still
     * gets caught.
     */
    private const REDACT_KEY_PATTERN = '/password|passwd|pwd|secret|token|api[_-]?key|merchant_id|authorization|auth[_-]?code|\b2fa\b|otp|verification[_-]?code|card[_-]?number|\bcvv\b|national[_-]?id|id[_-]?card|passport|kyc|id_card_photo|second_doc_photo|document/i';

    private static ?string $requestId = null;

    /**
     * When false, suppresses the CLI stdout mirror below (the log file write still happens).
     * Phase 11's --json operational commands (backup.php, backup-verify.php, backup-status.php,
     * restore.php, ...) need pure-JSON stdout so `| jq` and similar tooling can parse it — every
     * pre-Phase-11 --json command simply never called Logger:: on its way to printing JSON, so
     * this had no chance to surface before. Defaults to true (unchanged behavior for every other
     * of this project's 50+ existing Logger call sites, including worker.php's docker-logs use).
     */
    private static bool $cliMirror = true;

    public static function setCliMirror(bool $enabled): void
    {
        self::$cliMirror = $enabled;
    }

    private function __construct() {} // static-only, never instantiated

    public static function debug(string $event, array $context = []): void
    {
        self::log('debug', $event, $context);
    }

    public static function info(string $event, array $context = []): void
    {
        self::log('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::log('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::log('error', $event, $context);
    }

    public static function critical(string $event, array $context = []): void
    {
        self::log('critical', $event, $context);
    }

    /** Underlying entry point — the five level methods above just fix $level. */
    public static function log(string $level, string $event, array $context = []): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }
        if (self::LEVELS[$level] < self::LEVELS[self::minLevel()]) {
            return; // below the configured LOG_LEVEL threshold — skip entirely
        }

        $context = self::redact($context);
        $userId = self::currentUserIdIfAvailable();
        if ($userId !== null && !array_key_exists('user_id', $context)) {
            $context['user_id'] = $userId;
        }

        $entry = [
            'ts'         => date('c'),
            'level'      => $level,
            'event'      => $event,
            // Operational metadata — which build/environment produced this
            // line, so logs stay identifiable if ever aggregated across
            // deploys or environments. function_exists guards keep Logger
            // usable even if it's ever loaded standalone, before these are defined.
            'env'        => function_exists('app_env') ? app_env() : null,
            'version'    => function_exists('app_version') ? app_version() : null,
            'request_id' => self::requestId(),
            'context'    => $context,
        ];

        self::write($level, $entry);
    }

    /** Public accessor so ErrorHandler can show the same id a user can quote for support. */
    public static function currentRequestId(): string
    {
        return self::requestId();
    }

    /* ---------- internals ---------- */

    private static function minLevel(): string
    {
        $configured = strtolower((string)(function_exists('env') ? (env('LOG_LEVEL', 'debug') ?? 'debug') : 'debug'));
        return isset(self::LEVELS[$configured]) ? $configured : 'debug';
    }

    private static function requestId(): string
    {
        if (self::$requestId === null) {
            try {
                self::$requestId = bin2hex(random_bytes(8));
            } catch (\Throwable $t) {
                self::$requestId = (string)mt_rand(100000000, 999999999);
            }
        }
        return self::$requestId;
    }

    /** Best-effort — never let attaching a user id crash the caller mid-failure-handling. */
    private static function currentUserIdIfAvailable(): ?int
    {
        if (!function_exists('current_user')) {
            return null;
        }
        try {
            $u = current_user();
            return $u ? (int)$u['id'] : null;
        } catch (\Throwable $t) {
            return null;
        }
    }

    /** Recursively redact sensitive keys and normalize non-scalar values (Throwable, objects). */
    private static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT_KEY_PATTERN, $key)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = self::normalize($value);
        }
        return $out;
    }

    private static function normalize($value)
    {
        if ($value instanceof \Throwable) {
            return [
                'exception' => get_class($value),
                'message'   => $value->getMessage(),
                'file'      => $value->getFile(),
                'line'      => $value->getLine(),
            ];
        }
        if (is_array($value)) {
            return self::redact($value);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string)$value : '[object ' . get_class($value) . ']';
        }
        return $value;
    }

    private static function logDir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs';
    }

    private static function write(string $level, array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = json_encode(['ts' => date('c'), 'level' => $level, 'event' => $entry['event'] ?? 'log.encode_failed']);
        }

        $dir = self::logDir();
        $wrote = false;
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            $path = $dir . '/ellsms-' . date('Y-m-d') . '.log';
            $wrote = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
        }

        if (!$wrote) {
            // Last-resort fallback so a permissions problem never silently
            // drops a log line — this is the one place in the app that's
            // still allowed to call error_log() directly.
            error_log('[ellsms] ' . $line);
        }

        // Worker/cron runs are read via `docker logs`, not the log file —
        // mirror a compact line to stdout for CLI processes only, so
        // switching worker.php to Logger doesn't lose that visibility.
        if (PHP_SAPI === 'cli' && self::$cliMirror) {
            fwrite(STDOUT, sprintf(
                "[%s] %s %s %s\n",
                $entry['ts'],
                strtoupper($level),
                $entry['event'],
                empty($entry['context']) ? '' : json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        }
    }
}
