<?php
/**
 * ELLSMS — centralized error/exception handling.
 *
 * One registration point (ErrorHandler::register(), called once from
 * app/bootstrap.php before anything else runs, including the first DB
 * connection attempt) instead of each page fending for itself. Today
 * almost no page catches its own DB/API exceptions — this is the safety
 * net underneath all of them, not a replacement for adding real
 * try/catch where it matters later. Existing pages need zero changes to
 * benefit from this; adopting AppException for a specific user-facing
 * error is opt-in and gradual (see app/Support/AppException.php).
 *
 * Contract:
 *  - Production (APP_DEBUG=0, the default): the user only ever sees a
 *    generic message (or an AppException's own message, since those are
 *    written to be safe to show) plus a request id to quote when asking
 *    for help. Never a stack trace, SQL fragment, or file path.
 *  - Development (APP_DEBUG=1): the same page additionally shows the
 *    exception class, message, file:line, and a trimmed stack trace.
 *  - Either way, the FULL exception always goes to Logger::critical()
 *    first, unconditionally — production hiding it from the user is not
 *    the same as ELLSMS losing the ability to investigate it.
 *
 * Deliberately dependency-free: no calls to db(), current_user(), e(),
 * or any app/views template. This has to keep working even when the
 * failure IS the database connection itself, or when bootstrap.php
 * hasn't finished loading yet.
 */

declare(strict_types=1);

final class ErrorHandler
{
    private static bool $handled = false;

    public static function register(): void
    {
        $debug = function_exists('app_debug') ? app_debug() : false;
        // Belt-and-suspenders alongside app/bootstrap.php's own ini_set —
        // this class owns the "should errors ever reach the browser
        // directly" decision now, callable from anywhere.
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** The one place an uncaught Throwable from anywhere in the app ends up. */
    public static function handleException(\Throwable $e): void
    {
        try {
            Logger::critical('app.uncaught_exception', ['exception' => $e]);
        } catch (\Throwable $loggingFailure) {
            // Logging itself must never be the reason the user sees a raw
            // trace — fall back silently, render() below still runs.
            error_log('[ellsms] logging failed while handling exception: ' . $loggingFailure->getMessage());
        }

        $safeMessage = $e instanceof AppException
            ? $e->getMessage()
            : 'متأسفانه مشکلی در پردازش درخواست شما پیش آمد. لطفاً بعداً دوباره تلاش کنید.';

        self::render(500, $safeMessage, $e);
    }

    /**
     * Non-fatal PHP errors (warning/notice/deprecated/user_*). Respects
     * error suppression (@expr) exactly like PHP's own default handler
     * would, and never changes what actually happens to the value/flow —
     * it only adds logging, and in production also stops the raw
     * message from reaching the response body.
     */
    public static function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        if (!(error_reporting() & $errno)) {
            return false; // respects a leading @ on the offending expression
        }

        $level = match ($errno) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'critical',
            default => 'debug', // notices, deprecations, strict
        };

        try {
            Logger::log($level, 'app.php_error', [
                'errno'   => $errno,
                'message' => $errstr,
                'file'    => $errfile,
                'line'    => $errline,
            ]);
        } catch (\Throwable $loggingFailure) {
            error_log('[ellsms] logging failed while handling PHP error: ' . $loggingFailure->getMessage());
        }

        if ($errno === E_USER_ERROR) {
            // trigger_error(..., E_USER_ERROR) is fatal-class but PHP does
            // NOT auto-terminate after a custom handler returns for it —
            // route it through the same safe/dev rendering as an
            // exception and stop the request ourselves.
            self::render(500, 'متأسفانه مشکلی در پردازش درخواست شما پیش آمد. لطفاً بعداً دوباره تلاش کنید.', null, $errstr, $errfile, $errline);
            exit(1);
        }

        $debug = function_exists('app_debug') ? app_debug() : false;
        // Debug: let PHP's own handling also run (display_errors is already
        // on in that mode). Production: we've already logged it above —
        // suppress the raw message from ever reaching the response body.
        return !$debug;
    }

    /** Catches fatal errors that set_error_handler() cannot (E_ERROR, E_PARSE, ...). */
    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'], $fatal, true)) {
            return;
        }

        try {
            Logger::critical('app.fatal_error', [
                'errno'   => $err['type'],
                'message' => $err['message'],
                'file'    => $err['file'],
                'line'    => $err['line'],
            ]);
        } catch (\Throwable $loggingFailure) {
            error_log('[ellsms] logging failed while handling fatal error: ' . $loggingFailure->getMessage());
        }

        self::render(500, 'متأسفانه مشکلی در پردازش درخواست شما پیش آمد. لطفاً بعداً دوباره تلاش کنید.', null, $err['message'], $err['file'], $err['line']);
    }

    /**
     * Renders the one safe/dev error page. $e is used for the rich
     * dev-mode dump when available (exceptions); the fatal-error path
     * has no $e object, only raw strings, hence the optional params.
     */
    private static function render(
        int $status,
        string $safeMessage,
        ?\Throwable $e,
        ?string $rawMessage = null,
        ?string $rawFile = null,
        ?int $rawLine = null
    ): void {
        if (self::$handled) {
            return; // handleException already rendered; don't double-render from shutdown
        }
        self::$handled = true;

        if (!headers_sent()) {
            http_response_code($status);
        }

        $requestId = null;
        if (class_exists('Logger')) {
            try {
                $requestId = Logger::currentRequestId();
            } catch (\Throwable $ignored) {
                $requestId = null;
            }
        }

        $debug = function_exists('app_debug') ? app_debug() : false;
        $detail = '';
        if ($debug) {
            if ($e !== null) {
                $detail = sprintf(
                    "%s: %s\n%s:%d\n\n%s",
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
            } elseif ($rawMessage !== null) {
                $detail = sprintf("%s\n%s:%d", $rawMessage, $rawFile ?? '', $rawLine ?? 0);
            }
        }

        echo self::renderHtml($safeMessage, $debug ? $detail : null, $requestId);
    }

    private static function renderHtml(string $safeMessage, ?string $detail, ?string $requestId): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $requestIdBlock = $requestId !== null
            ? '<p style="margin:14px 0 0;color:#888;font-size:12px" dir="ltr">Request ID: ' . $esc($requestId) . '</p>'
            : '';

        $detailBlock = '';
        if ($detail !== null) {
            $detailBlock = '<pre style="text-align:left;direction:ltr;white-space:pre-wrap;'
                . 'background:#1e1e1e;color:#f2f2f2;padding:14px;border-radius:8px;'
                . 'font-size:13px;overflow:auto;max-height:50vh;margin-top:16px">'
                . $esc($detail) . '</pre>';
        }

        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>خطا — ELLSMS</title></head>'
            . '<body style="font-family:Tahoma,Arial,sans-serif;background:#f6f7f9;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
            . '<main style="background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);'
            . 'padding:32px;max-width:640px;width:90%;text-align:center">'
            . '<h1 style="margin:0 0 12px;font-size:20px;color:#c0392b">مشکلی پیش آمد</h1>'
            . '<p style="margin:0;color:#333">' . $esc($safeMessage) . '</p>'
            . $requestIdBlock
            . $detailBlock
            . '</main></body></html>';
    }
}
