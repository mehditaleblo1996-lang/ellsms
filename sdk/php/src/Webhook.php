<?php

declare(strict_types=1);

namespace Ellsms;

/**
 * Verifies an ELLSMS webhook delivery: X-ELLSMS-Signature = hex(HMAC-SHA256(secret, timestamp + "." + raw body)).
 * Always pass the RAW request body, exactly as received.
 */
final class Webhook
{
    public static function verify(string $secret, string $timestamp, string $rawBody, string $signature, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int)$timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Verifies and decodes a delivery from the current PHP request. Returns the decoded event, or null
     * when the signature, timestamp or body is not valid.
     */
    public static function fromGlobals(string $secret, int $toleranceSeconds = 300): ?array
    {
        $raw = (string)file_get_contents('php://input');
        $timestamp = (string)($_SERVER['HTTP_X_ELLSMS_TIMESTAMP'] ?? '');
        $signature = (string)($_SERVER['HTTP_X_ELLSMS_SIGNATURE'] ?? '');
        if (!self::verify($secret, $timestamp, $raw, $signature, $toleranceSeconds)) {
            return null;
        }
        $event = json_decode($raw, true);
        return is_array($event) ? $event : null;
    }
}
