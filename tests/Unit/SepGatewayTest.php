<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Saman (SEP) protocol interpretation. No network and no database — the HTTP calls and the
 * claim transaction are outside these functions. docs/sep-payment.md.
 */
final class SepGatewayTest extends TestCase
{
    public function testTokenSuccessReadsStatusAndToken(): void {
        $parsed = sep_interpret_token(['status' => 1, 'token' => 'abc123token']);
        self::assertTrue($parsed['ok']);
        self::assertSame('abc123token', $parsed['authority']);
    }

    public function testTokenErrorUsesTheBankDescription(): void {
        $parsed = sep_interpret_token(['status' => -1, 'errorCode' => '5', 'errorDesc' => 'شماره تراکنش الزامی است']);
        self::assertFalse($parsed['ok']);
        self::assertNull($parsed['authority']);
        self::assertStringContainsString('شماره تراکنش الزامی است', $parsed['message']);
    }

    public function testTokenNetworkFailureIsDistinct(): void {
        $parsed = sep_interpret_token(null);
        self::assertFalse($parsed['ok']);
        self::assertStringContainsString('اتصال', $parsed['message']);
    }

    public function testVerifySuccessRequiresTheOriginalAmount(): void {
        $parsed = sep_interpret_verify([
            'Success' => true,
            'ResultCode' => 0,
            'ResultDescription' => 'عملیات با موفقیت انجام شد',
            'TransactionDetail' => [
                'RefNum' => 'REF1',
                'TerminalNumber' => 2015,
                'OrginalAmount' => 12000,
                'AffectiveAmount' => 12000,
            ],
        ], '2015', 'REF1');
        self::assertTrue($parsed['ok']);
        self::assertSame(12000, $parsed['verified_amount_rial']);
        self::assertSame('REF1', $parsed['ref_id']);
        self::assertFalse($parsed['final']);
    }

    public function testVerifyDuplicateIsSuccessWhenTheAmountIsPresent(): void {
        $parsed = sep_interpret_verify([
            'ResultCode' => 2,
            'TransactionDetail' => ['RefNum' => 'REF1', 'TerminalNumber' => '2015', 'OrginalAmount' => '12000'],
        ], '2015', 'REF1');
        self::assertTrue($parsed['ok'], 'SEP re-verifies the same receipt; code 2 must still carry the amount');
        self::assertSame(12000, $parsed['verified_amount_rial']);
    }

    public function testVerifyRejectsADifferentTerminalOrReceipt(): void {
        $body = [
            'ResultCode' => 0,
            'Success' => true,
            'TransactionDetail' => ['RefNum' => 'OTHER', 'TerminalNumber' => 999, 'OrginalAmount' => 12000],
        ];
        self::assertFalse(sep_interpret_verify($body, '2015', 'OTHER')['ok']);
        $body['TransactionDetail']['TerminalNumber'] = 2015;
        self::assertFalse(sep_interpret_verify($body, '2015', 'REF1')['ok']);
    }

    public function testVerifyTimeoutIsFinalAndNotFoundIsNot(): void {
        $late = sep_interpret_verify(['ResultCode' => -6, 'Success' => false], '2015', 'REF1');
        self::assertFalse($late['ok']);
        self::assertTrue($late['final']);
        $missing = sep_interpret_verify(['ResultCode' => -2, 'Success' => false], '2015', 'REF1');
        self::assertFalse($missing['final']);
    }

    public function testVerifyWithoutAnAmountDoesNotCountAsSuccess(): void {
        $parsed = sep_interpret_verify(['ResultCode' => 0, 'Success' => true, 'TransactionDetail' => ['RefNum' => 'REF1']], '2015', 'REF1');
        self::assertFalse($parsed['ok']);
        self::assertNull($parsed['verified_amount_rial']);
    }

    public function testReverseTreatsDuplicateAsAlreadyDone(): void {
        self::assertTrue(sep_interpret_reverse(['ResultCode' => 0, 'Success' => true])['ok']);
        self::assertTrue(sep_interpret_reverse(['ResultCode' => 2])['ok']);
        self::assertFalse(sep_interpret_reverse(['ResultCode' => -105])['ok']);
        self::assertFalse(sep_interpret_reverse(null)['ok']);
    }

    public function testStateOkIsExactAndCancelHasABuyerMessage(): void {
        self::assertTrue(sep_state_ok('OK', '2'));
        self::assertTrue(sep_state_ok('OK', ''));
        self::assertFalse(sep_state_ok('ok', '2'));
        self::assertFalse(sep_state_ok('CanceledByUser', '1'));
        self::assertTrue(sep_state_is_final_failure('CanceledByUser'));
        self::assertTrue(sep_state_is_final_failure('TokenNotFound'));
        self::assertFalse(sep_state_is_final_failure(''));
        self::assertFalse(sep_state_is_final_failure('OK'));
        self::assertStringContainsString('لغو', sep_state_message('CanceledByUser'));
    }

    public function testCallbackParamsPreferPostAndIgnoreCardFields(): void {
        $params = sep_callback_params(
            ['State' => 'OK', 'Status' => '2', 'RefNum' => 'R', 'ResNum' => '9-abcd', 'Token' => 'T', 'SecurePan' => '6219****1111'],
            ['State' => 'Failed', 'RefNum' => 'ignored']
        );
        self::assertSame('OK', $params['state']);
        self::assertSame('R', $params['ref_num']);
        self::assertSame('9-abcd', $params['res_num']);
        self::assertArrayNotHasKey('secure_pan', $params);
        $fromGet = sep_callback_params([], ['state' => 'Failed', 'ResNum' => '1-aa', 'Token' => 'tok', 'MID' => '2015']);
        self::assertSame('Failed', $fromGet['state']);
        self::assertSame('2015', $fromGet['terminal_id']);
    }

    public function testCellNumberDropsTheCountryCode(): void {
        self::assertSame('9121234567', sep_normalize_cell('09121234567'));
        self::assertSame('9121234567', sep_normalize_cell('+989121234567'));
        self::assertSame('', sep_normalize_cell('02112345678'));
    }

    public function testResnumIsTiedToThePaymentAndShort(): void {
        $resnum = sep_new_resnum(42);
        self::assertMatchesRegularExpression('/^42-[0-9a-f]{8}$/', $resnum);
        self::assertLessThanOrEqual(40, strlen($resnum));
    }

    public function testCallbackUrlAndSiteUrlRules(): void {
        self::assertTrue(sep_callback_url_acceptable(''));
        self::assertTrue(sep_callback_url_acceptable('https://stage.example.com/sep-callback.php'));
        self::assertFalse(sep_callback_url_acceptable('http://example.com/sep-callback.php'));
        self::assertFalse(sep_callback_url_acceptable('https://user:pass@example.com/cb'));
        self::assertSame('https://datisrayan.com', sep_site_url_from_callback('https://datisrayan.com/sep-callback.php'));
        self::assertSame('', sep_site_url_from_callback('http://datisrayan.com/sep-callback.php'));
    }
}
