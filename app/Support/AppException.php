<?php
/**
 * ELLSMS — marker exception for expected, user-facing business errors
 * (validation failures, quota/limit messages, upload rejections, etc.)
 * whose ->getMessage() is written to be safe to show verbatim.
 *
 * Extends RuntimeException on purpose: existing code that already does
 * `catch (RuntimeException $e)` around something like
 * kyc_store_upload() keeps working completely unchanged if that
 * function is switched to throw AppException instead — this is how the
 * mechanism is meant to be adopted gradually, not by rewriting every
 * catch site at once.
 *
 * Anything NOT an AppException (a bare PDOException, TypeError, or any
 * other internal failure) is treated by ErrorHandler as unsafe to show
 * to the user — see app/Support/ErrorHandler.php.
 */

declare(strict_types=1);

class AppException extends \RuntimeException
{
}
