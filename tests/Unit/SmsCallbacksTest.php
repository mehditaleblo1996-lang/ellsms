<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the provider push callbacks (app/Sms/GatewayCallbacks.php): parameter reading,
 * text decoding (Persian and emoji in every encoding a provider uses), the optional guard and the
 * status-token defaults. The database writes go through gateway_receive_store() and
 * gateway_status_record(), which have their own integration coverage.
 */
final class SmsCallbacksTest extends TestCase
{
    protected function tearDown(): void {
        putenv('SMS_CALLBACK_TOKEN');
        putenv('SMS_CALLBACK_ALLOWED_IPS');
    }

    public function testQueryStringWithPersianAndEmojiDecodesToUtf8(): void {
        $query = 'from=989197684063&to=5000435800&content=' . rawurlencode('سلام دنیا 😀👍');
        $params = sms_callback_params($query, [], '');
        $this->assertSame('989197684063', sms_callback_param($params, SMS_CALLBACK_FROM_KEYS));
        $this->assertSame('5000435800', sms_callback_param($params, SMS_CALLBACK_TO_KEYS));
        $this->assertSame('سلام دنیا 😀👍', sms_callback_decode_text(sms_callback_param($params, SMS_CALLBACK_CONTENT_KEYS)));
    }

    public function testPlusIsASpaceAndAliasesAndJsonBodyAreRead(): void {
        $params = sms_callback_params('', [], json_encode(['Sender' => '09121234567', 'Receiver' => '3000', 'Text' => 'خیلی ممنون ❤️']));
        $this->assertSame('09121234567', sms_callback_param($params, SMS_CALLBACK_FROM_KEYS));
        $this->assertSame('خیلی ممنون ❤️', sms_callback_param($params, SMS_CALLBACK_CONTENT_KEYS));
        $this->assertSame('a b', sms_callback_param(sms_callback_params('content=a+b', [], ''), SMS_CALLBACK_CONTENT_KEYS));
    }

    public function testJavascriptPercentUEscapesIncludingSurrogatePairs(): void {
        $this->assertSame('سلام 😀', sms_callback_decode_text('%u0633%u0644%u0627%u0645 %uD83D%uDE00'));
    }

    public function testUcs2HexOnlyWhenTheProviderSaysSo(): void {
        $hex = strtoupper(bin2hex(mb_convert_encoding('سلام 😀', 'UTF-16BE', 'UTF-8')));
        $this->assertSame('سلام 😀', sms_callback_decode_text($hex, '8'));
        $this->assertSame('سلام 😀', sms_callback_decode_text($hex, 'UCS2'));
        $this->assertSame('1234', sms_callback_decode_text('1234'));
    }

    public function testWindows1256FallbackAndNeverInvalidUtf8(): void {
        $this->assertSame('سلام', sms_callback_decode_text("\xd3\xe1\xc7\xe3"));
        $this->assertTrue(mb_check_encoding(sms_callback_decode_text("ok \xff\xfe\x00"), 'UTF-8'));
    }

    public function testGuardIsOpenByDefaultAndEnforcesTokenAndIps(): void {
        $this->assertNull(sms_callback_authorize([], '1.2.3.4'));

        putenv('SMS_CALLBACK_TOKEN=s3cret');
        $this->assertSame('bad_token', sms_callback_authorize([], '1.2.3.4'));
        $this->assertSame('bad_token', sms_callback_authorize(['token' => 'wrong'], '1.2.3.4'));
        $this->assertNull(sms_callback_authorize(['token' => 's3cret'], '1.2.3.4'));
        $this->assertNull(sms_callback_authorize([], '1.2.3.4', 's3cret'));

        putenv('SMS_CALLBACK_ALLOWED_IPS=10.0.0.0/8, 5.6.7.8');
        $this->assertSame('ip_not_allowed', sms_callback_authorize(['token' => 's3cret'], '1.2.3.4'));
        $this->assertNull(sms_callback_authorize(['token' => 's3cret'], '10.20.30.40'));
        $this->assertNull(sms_callback_authorize(['token' => 's3cret'], '5.6.7.8'));
    }

    public function testStatusTokensUseTheGatewayMapFirstThenSafeDefaults(): void {
        $this->assertSame('delivered', sms_callback_status_canonical(null, 'DELIVRD'));
        $this->assertSame('failed', sms_callback_status_canonical(null, 'undeliv'));
        $this->assertSame('unknown', sms_callback_status_canonical(null, '1'));
        $connector = ['status' => ['statuses' => ['1' => 'delivered', 'delivrd' => 'failed']]];
        $this->assertSame('delivered', sms_callback_status_canonical($connector, '1'));
        $this->assertSame('failed', sms_callback_status_canonical($connector, 'DELIVRD'));
    }

    public function testAPartiallyTypedSenderIsRefused(): void {
        foreach (['98', '98912', '9891230006', '98912300065', ''] as $raw) {
            $this->assertNull(sms_callback_sender($raw), $raw);
        }
        $this->assertSame('989123000657', sms_callback_sender('989123000657'));
        $this->assertSame('989123000657', sms_callback_sender('09123000657'));
        $this->assertSame('447700900123', sms_callback_sender('+447700900123'));
    }

    public function testBrowserPrefetchAndPrerenderAreRecognised(): void {
        $this->assertTrue(sms_callback_is_speculative(['HTTP_SEC_PURPOSE' => 'prefetch;prerender']));
        $this->assertTrue(sms_callback_is_speculative(['HTTP_SEC_PURPOSE' => 'prefetch']));
        $this->assertTrue(sms_callback_is_speculative(['HTTP_PURPOSE' => 'prefetch']));
        $this->assertFalse(sms_callback_is_speculative([]));
    }

    public function testDeliveredAtIsUtcFromTehranWallClock(): void {
        $this->assertSame('2026-10-18 08:30:00', sms_callback_delivered_at('2026-10-18 12:00:00', time()));
    }
}
