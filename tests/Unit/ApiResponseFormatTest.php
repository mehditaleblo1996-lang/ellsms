<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Response.php';

/** Phase 12 (STEP 5): the stable success/error JSON envelope every /api/v1/* response uses. */
final class ApiResponseFormatTest extends TestCase
{
    public function testSuccessEnvelopeShape(): void
    {
        ob_start();
        \ApiResponse::success(200, ['id' => '1', 'name' => 'x']);
        $body = json_decode(ob_get_clean(), true);

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertSame(['id' => '1', 'name' => 'x'], $body['data']);
    }

    public function testSuccessEnvelopeIncludesMetaOnlyWhenProvided(): void
    {
        ob_start();
        \ApiResponse::success(200, ['a' => 1]);
        $withoutMeta = json_decode(ob_get_clean(), true);
        $this->assertArrayNotHasKey('meta', $withoutMeta);

        ob_start();
        \ApiResponse::success(200, ['a' => 1], ['next_cursor' => '5']);
        $withMeta = json_decode(ob_get_clean(), true);
        $this->assertSame(['next_cursor' => '5'], $withMeta['meta']);
    }

    public function testErrorEnvelopeShape(): void
    {
        ob_start();
        \ApiResponse::error(403, \ApiResponse::CODE_FORBIDDEN, 'You are not allowed to do this.');
        $body = json_decode(ob_get_clean(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame('forbidden', $body['error']['code']);
        $this->assertSame('You are not allowed to do this.', $body['error']['message']);
        $this->assertArrayHasKey('request_id', $body['error']);
        $this->assertArrayNotHasKey('fields', $body['error']);
    }

    public function testValidationFailedIncludesFields(): void
    {
        ob_start();
        \ApiResponse::validationFailed(['mobile' => ['invalid_format']]);
        $body = json_decode(ob_get_clean(), true);

        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame(['mobile' => ['invalid_format']], $body['error']['fields']);
    }

    public function testErrorMessageNeverContainsRawPhpExceptionShape(): void
    {
        // A cheap but real regression guard for Invariant H: nothing routed through this envelope
        // should ever look like a leaked stack trace/file path.
        ob_start();
        \ApiResponse::error(500, \ApiResponse::CODE_INTERNAL_ERROR, 'An unexpected error occurred. Reference the request id if you contact support.');
        $body = json_decode(ob_get_clean(), true);
        $this->assertStringNotContainsString('.php', $body['error']['message']);
        $this->assertStringNotContainsString('Stack trace', $body['error']['message']);
    }

    public function testRawEmitsExactBytesUnwrapped(): void
    {
        $json = json_encode(['data' => ['id' => '99']]);
        ob_start();
        \ApiResponse::raw(200, $json);
        $this->assertSame($json, ob_get_clean());
    }
}
