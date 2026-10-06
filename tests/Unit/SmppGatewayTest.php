<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #46 — the pure parts of the PHP side of SMPP gateways (app/Sms/Smpp.php). */
final class SmppGatewayTest extends TestCase
{
    public function testReceiptStatusMapsToCanonicalStates(): void
    {
        $this->assertSame('delivered', smpp_dlr_canonical('DELIVRD'));
        $this->assertSame('failed', smpp_dlr_canonical('UNDELIV'));
        $this->assertSame('failed', smpp_dlr_canonical('DELETED'));
        $this->assertSame('expired', smpp_dlr_canonical('EXPIRED'));
        $this->assertSame('rejected', smpp_dlr_canonical('rejectd'));
        $this->assertSame('accepted', smpp_dlr_canonical('ACCEPTD'));
        $this->assertSame('sent', smpp_dlr_canonical('ENROUTE'));
        $this->assertSame('unknown', smpp_dlr_canonical('WHATEVER'));
        $this->assertSame('unknown', smpp_dlr_canonical(null));
    }

    public function testMessageIdVariantsCoverHexAndDecimalWithoutPrecisionLoss(): void
    {
        // 2^64 - 1 — beyond float precision, so hexdec() would get this wrong.
        $this->assertSame('18446744073709551615', smpp_base_convert('ffffffffffffffff', 16, 10));
        $this->assertSame('ffffffffffffffff', smpp_base_convert('18446744073709551615', 10, 16));
        $this->assertSame('0', smpp_base_convert('0', 10, 16));

        $auto = smpp_message_id_variants('1A2B', 'auto');
        $this->assertContains('1A2B', $auto);
        $this->assertContains('6699', $auto);
        $dec = smpp_message_id_variants('6699', 'auto');
        $this->assertContains('6699', $dec);
        $this->assertContains('1A2B', $dec);
        $this->assertContains('1a2b', $dec);

        $this->assertSame(['1A2B'], smpp_message_id_variants('1A2B', 'as_is'));
        $this->assertSame(['6699'], smpp_message_id_variants('1A2B', 'hex_to_dec'));
        $this->assertSame([], smpp_message_id_variants('  ', 'auto'));
        $this->assertContains('123', smpp_message_id_variants('000123', 'auto'));
    }

    public function testSettingsValidationEnforcesProtocolLimits(): void
    {
        $base = smpp_connector_defaults();
        $base['host'] = 'smsc.example.ir';
        $base['system_id'] = 'ellsms';
        $in = array_map('strval', $base);

        [$errors, $row] = smpp_connector_validate($in + ['password' => '12345678']);
        $this->assertSame([], $errors);
        $this->assertSame('smsc.example.ir', $row['host']);
        $this->assertSame(2775, $row['port']);

        [$errors] = smpp_connector_validate(['password' => '123456789', 'system_id' => str_repeat('x', 16), 'host' => 'bad host;', 'port' => '0', 'tps' => '0', 'system_type' => str_repeat('t', 13)] + $in);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('system_id', $errors);
        $this->assertArrayHasKey('host', $errors);
        $this->assertArrayHasKey('port', $errors);
        $this->assertArrayHasKey('tps', $errors);
        $this->assertArrayHasKey('system_type', $errors);

        // Unknown enum values fall back to the default instead of reaching the database.
        [, $row] = smpp_connector_validate(['bind_mode' => 'evil', 'data_coding' => 'x'] + $in);
        $this->assertSame('trx', $row['bind_mode']);
        $this->assertSame('auto', $row['data_coding']);
    }
}
