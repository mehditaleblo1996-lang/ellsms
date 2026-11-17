<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #46 — the pure parts of the PHP side of SMPP gateways (app/Sms/Smpp.php). */
final class SmppGatewayTest extends TestCase
{
    public function testReceiptStatusMapsToCanonicalStates(): void
    {
        $this->assertSame('delivered', smpp_dlr_canonical('DELIVRD'));
        $this->assertSame('delivered', smpp_dlr_canonical('delivered'));
        // Final "did not reach the handset": charged, never refunded (2026_11_20_smpp_status_mapping.sql).
        $this->assertSame('undelivered', smpp_dlr_canonical('UNDELIV'));
        $this->assertSame('undelivered', smpp_dlr_canonical('UNDELIVERABLE'));
        $this->assertSame('undelivered', smpp_dlr_canonical('EXPIRED'));
        $this->assertSame('failed', smpp_dlr_canonical('DELETED'));
        $this->assertSame('rejected', smpp_dlr_canonical('rejectd'));
        $this->assertSame('accepted', smpp_dlr_canonical('ACCEPTD'));
        $this->assertSame('sent', smpp_dlr_canonical('ENROUTE'));
        $this->assertSame('unknown', smpp_dlr_canonical('UNKNOWN'));
        $this->assertSame('unknown', smpp_dlr_canonical('WHATEVER'));
        $this->assertSame('unknown', smpp_dlr_canonical(null));
    }

    public function testGatewayMappingOverridesTheDefault(): void
    {
        $map = smpp_dlr_mapping('{"UNDELIV": "failed", "expired": "expired", "SUBMITD": "sent", "X": "not-a-state"}');
        $this->assertSame('failed', smpp_dlr_canonical('UNDELIV', $map));
        $this->assertSame('expired', smpp_dlr_canonical('EXPIRED', $map));
        $this->assertSame('sent', smpp_dlr_canonical('SUBMITD', $map));
        $this->assertSame('delivered', smpp_dlr_canonical('DELIVRD', $map), 'unlisted stats keep the default');
        $this->assertSame('unknown', smpp_dlr_canonical('X', $map), 'an invalid target is ignored');
        $this->assertSame(SMPP_DLR_DEFAULT_MAPPING, smpp_dlr_mapping(null));
        $this->assertSame(SMPP_DLR_DEFAULT_MAPPING, smpp_dlr_mapping('not json'));
    }

    public function testMappingValidation(): void
    {
        $this->assertSame([null, null], smpp_dlr_mapping_validate(''));
        // The default itself (as the panel shows it) is stored as NULL: the gateway keeps following it.
        $this->assertSame([null, null], smpp_dlr_mapping_validate((string)json_encode(SMPP_DLR_DEFAULT_MAPPING)));
        [$error, $json] = smpp_dlr_mapping_validate('{"undeliv": "failed", "SUBMITD": "sent"}');
        $this->assertNull($error);
        $this->assertSame(['UNDELIV' => 'failed', 'SUBMITD' => 'sent'], json_decode((string)$json, true));
        $this->assertNotNull(smpp_dlr_mapping_validate('{"UNDELIV": "lost"}')[0]);
        $this->assertNotNull(smpp_dlr_mapping_validate('["DELIVRD"]')[0]);
        $this->assertNotNull(smpp_dlr_mapping_validate('{"BAD STAT!": "failed"}')[0]);
        $this->assertNotNull(smpp_dlr_mapping_validate('{oops')[0]);
    }

    public function testTimeoutAndDeadlineStayInsideTheBudget(): void
    {
        // 200 single-part messages at 50 TPS need 4 s: the 60 s floor applies.
        $this->assertSame(60000, smpp_submit_timeout_ms(200, 1, 50, 270000));
        // 500 five-part messages at 10 TPS need 250 s: capped by what is left of the lease budget.
        $this->assertSame(270000, smpp_submit_timeout_ms(500, 5, 10, 270000));
        $this->assertSame(40000, smpp_submit_timeout_ms(500, 5, 10, 40000));
        // The bridge stops starting messages well before PHP's own timeout.
        $this->assertSame(30000, smpp_submit_deadline_ms(60000, 10000));
        $this->assertSame(5000, smpp_submit_deadline_ms(20000, 10000));
        $this->assertLessThanOrEqual(270000, smpp_send_budget_ms());
        $this->assertSame(3, smpp_send_parts(['message' => 'hi', 'messages' => ['989121234567' => str_repeat('س', 150)]]));
    }

    public function testSubmitErrorCodes(): void
    {
        $this->assertSame('0x0000000B', smpp_result_error_code(['command_status' => 11, 'error_class' => 'rejected']));
        $this->assertSame('smpp_unavailable', smpp_result_error_code(['command_status' => null, 'error_class' => 'unavailable']));
        $this->assertSame('smpp_failed', smpp_result_error_code([]));
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
