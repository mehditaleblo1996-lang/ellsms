<?php

declare(strict_types=1);

namespace Tests\Integration;

final class ProviderResponseAuditIntegrationTest extends IntegrationTestCase
{
    public function testNormalizedProviderEvidenceIsPersistedWithoutRequestSecrets(): void
    {
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status) VALUES (?,?, 'active')")
            ->execute(['audit-test-' . bin2hex(random_bytes(3)), 'Audit test gateway']);
        $gatewayId = (int)db()->lastInsertId();

        $raw = '{"ok":false,"error_code":"P-77","message":"provider rejected recipient"}';
        gateway_provider_response_audit($gatewayId, 'send', 'req-provider-audit', 200, [
            'outcome' => PROVIDER_RESPONSE_FAILED,
            'provider_message_id' => null,
            'provider_error_code' => 'P-77',
            'provider_error_detail' => 'provider rejected recipient',
            'raw_response' => $raw,
        ]);

        $st = db()->prepare('SELECT * FROM ellsms_provider_response_audit WHERE gateway_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$gatewayId]);
        $row = $st->fetch();

        self::assertIsArray($row);
        self::assertSame('send', $row['connector']);
        self::assertSame('req-provider-audit', $row['request_id']);
        self::assertSame('FAILED', $row['normalized_outcome']);
        self::assertSame('P-77', $row['provider_error_code']);
        self::assertSame('provider rejected recipient', $row['provider_error_detail']);
        self::assertSame($raw, $row['raw_response']);
    }

    public function testRawProviderResponseIsBoundedBeforePersistence(): void
    {
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status) VALUES (?,?, 'active')")
            ->execute(['audit-bound-' . bin2hex(random_bytes(3)), 'Audit bound gateway']);
        $gatewayId = (int)db()->lastInsertId();

        gateway_provider_response_audit($gatewayId, 'send', 'req-long', 502, [
            'outcome' => PROVIDER_RESPONSE_FAILED,
            'provider_message_id' => null,
            'provider_error_code' => 'UPSTREAM',
            'provider_error_detail' => str_repeat('d', 1000),
            'raw_response' => str_repeat('x', 80000),
        ]);

        $st = db()->prepare('SELECT provider_error_detail, raw_response FROM ellsms_provider_response_audit WHERE gateway_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$gatewayId]);
        $row = $st->fetch();

        self::assertIsArray($row);
        self::assertLessThanOrEqual(500, mb_strlen((string)$row['provider_error_detail']));
        self::assertLessThanOrEqual(65535, mb_strlen((string)$row['raw_response']));
    }
}
