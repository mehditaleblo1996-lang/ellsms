<?php

declare(strict_types=1);

namespace Tests\Unit;

use BackendError;
use PHPUnit\Framework\TestCase;

final class ProviderResponseNormalizerTest extends TestCase
{
    private function section(?string $idPath = null, ?array $successRule = null, ?string $errorCodePath = null, array $errorMap = []): array
    {
        return [
            'success' => gateway_success_rule_compile($successRule),
            'response' => [
                'provider_message_id' => $idPath !== null ? gateway_path_compile($idPath) : [],
                'error_code' => $errorCodePath !== null ? gateway_path_compile($errorCodePath) : [],
            ],
            'errors' => $errorMap,
        ];
    }

    private function normalize(array $section, int $http, string $raw, bool $requireMessageId = true): array
    {
        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        $isJson = json_last_error() === JSON_ERROR_NONE;
        return gateway_normalize_provider_response($section, $http, $raw, $decoded, $isJson, $requireMessageId);
    }

    public function testValidSuccessUsesBuiltInMessageIdFallbackWithoutAdminMapping(): void
    {
        $result = $this->normalize(
            $this->section(),
            200,
            '{"success":true,"data":{"messageId":"7310136179845801812"}}'
        );

        self::assertSame(PROVIDER_RESPONSE_SUCCESS, $result['outcome']);
        self::assertSame('7310136179845801812', $result['provider_message_id']);
        self::assertNull($result['provider_error_code']);
    }

    public function testExplicitProviderErrorIsFailedAndPreservesProviderCodeAndDetail(): void
    {
        $result = $this->normalize(
            $this->section(),
            200,
            '{"success":false,"error_code":"E42","message":"recipient rejected"}'
        );

        self::assertSame(PROVIDER_RESPONSE_FAILED, $result['outcome']);
        self::assertSame('E42', $result['provider_error_code']);
        self::assertSame('recipient rejected', $result['provider_error_detail']);
        self::assertSame(BackendError::REJECTED, $result['error_class']);
    }

    public function testNegativeIdLikeProviderErrorCanNeverBecomeSuccess(): void
    {
        $result = $this->normalize($this->section(), 200, '{"message_id":-103}');

        self::assertSame(PROVIDER_RESPONSE_FAILED, $result['outcome']);
        self::assertSame('-103', $result['provider_error_code']);
        self::assertNull($result['provider_message_id']);
    }

    public function testMalformedTwoHundredResponseIsFailedNotUnknown(): void
    {
        $raw = '<html>ok-ish but not the provider contract</html>';
        $result = gateway_normalize_provider_response(
            $this->section(),
            200,
            $raw,
            null,
            false,
            true
        );

        self::assertSame(PROVIDER_RESPONSE_FAILED, $result['outcome']);
        self::assertSame(BackendError::INVALID_RESPONSE, $result['error_class']);
        self::assertSame('malformed_2xx', $result['reason']);
        self::assertSame($raw, $result['raw_response']);
    }

    public function testTimeoutWithNoProviderResponseIsUnknown(): void
    {
        $result = gateway_normalize_provider_response(
            $this->section(),
            0,
            '',
            null,
            false,
            true,
            BackendError::TIMEOUT,
            'operation timed out'
        );

        self::assertSame(PROVIDER_RESPONSE_UNKNOWN, $result['outcome']);
        self::assertSame(BackendError::TIMEOUT, $result['error_class']);
        self::assertSame('transport_ambiguous', $result['reason']);
    }

    public function testIncompleteAdminOverrideFallsBackToBuiltInAdapter(): void
    {
        $section = $this->section(
            'custom.missingId',
            [
                'http' => ['min' => 200, 'max' => 299],
                'rules' => [[
                    'path' => 'custom.missingStatus',
                    'operator' => 'equals',
                    'values' => ['OK'],
                ]],
            ]
        );

        $result = $this->normalize(
            $section,
            200,
            '{"ok":true,"data":{"messageId":"fallback-123"}}'
        );

        self::assertSame(PROVIDER_RESPONSE_SUCCESS, $result['outcome']);
        self::assertSame('fallback-123', $result['provider_message_id']);
    }

    public function testAdminResponseMappingCanOverrideBuiltInIdLocation(): void
    {
        $result = $this->normalize(
            $this->section('custom.reference'),
            201,
            '{"custom":{"reference":"provider-A-77"},"status":"OK"}'
        );

        self::assertSame(PROVIDER_RESPONSE_SUCCESS, $result['outcome']);
        self::assertSame('provider-A-77', $result['provider_message_id']);
    }

    public function testConfiguredProviderErrorMappingOnlyReclassifiesMatchingError(): void
    {
        $result = $this->normalize(
            $this->section(null, null, 'error.code', ['AUTH-7' => BackendError::UNAUTHORIZED]),
            200,
            '{"ok":false,"error":{"code":"AUTH-7","message":"bad credential"}}'
        );

        self::assertSame(PROVIDER_RESPONSE_FAILED, $result['outcome']);
        self::assertSame('AUTH-7', $result['provider_error_code']);
        self::assertSame(BackendError::UNAUTHORIZED, $result['error_class']);
    }

    public function testUnknownJsonEnvelopeNeverCrashesOrAssumesSuccess(): void
    {
        $result = $this->normalize($this->section(), 200, '{"foo":"bar"}');

        self::assertSame(PROVIDER_RESPONSE_FAILED, $result['outcome']);
        self::assertSame(BackendError::INVALID_RESPONSE, $result['error_class']);
        self::assertSame('missing_or_invalid_provider_message_id', $result['reason']);
    }

    public function testBatchPositionRejectsNegativeProviderReference(): void
    {
        $section = [
            'batch' => [
                'provider_ids_path' => gateway_path_compile('ids'),
            ],
        ];

        [$sent, $ids] = gateway_extract_positional_result($section, ['ids' => ['1001', -103]], ['989121111111', '989121111112']);
        self::assertSame([], $sent);
        self::assertSame([], $ids);
    }
}
