<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Auth.php';
require_once __DIR__ . '/../../app/Api/Handlers/WebEngage.php';

/** WebEngage SSP integration (app/WebEngage.php): the payload, status-code and DSN vocabulary. */
final class WebEngageTest extends TestCase
{
    private function request(array $overrides = []): array
    {
        $base = [
            'version'  => '2.0',
            'smsData'  => ['toNumber' => '+989121234567', 'fromNumber' => '5000123', 'body' => 'سلام دنیا'],
            'metadata' => ['campaignType' => 'PROMOTIONAL', 'timestamp' => '2026-10-21T10:00:00+0330', 'messageId' => 'we-msg-1', 'custom' => ['k' => 'v']],
        ];
        return array_replace_recursive($base, $overrides);
    }

    public function testParsesAValidVersionTwoRequest(): void
    {
        $parsed = webengage_parse_request($this->request());
        $this->assertTrue($parsed['ok']);
        $this->assertSame('we-msg-1', $parsed['message_id']);
        $this->assertSame('+989121234567', $parsed['to_number'], 'echoed back unmodified in the DSN');
        $this->assertSame('989121234567', $parsed['destination']);
        $this->assertSame('5000123', $parsed['from_number']);
        $this->assertSame('سلام دنیا', $parsed['content']);
        $this->assertSame('PROMOTIONAL', $parsed['campaign_type']);
    }

    public function testAcceptsVersionOneAndIgnoresUnknownMetadata(): void
    {
        $parsed = webengage_parse_request($this->request(['version' => '1.0', 'metadata' => ['indiaDLT' => ['contentTemplateId' => 'x']]]));
        $this->assertTrue($parsed['ok']);
    }

    public function testRejectsAnUnsupportedVersionWithSupportedVersion(): void
    {
        $parsed = webengage_parse_request($this->request(['version' => '3.0']));
        $this->assertFalse($parsed['ok']);
        $this->assertSame(WEBENGAGE_CODE_VERSION_UNSUPPORTED, $parsed['code']);
        $this->assertSame('2.0', $parsed['extra']['supportedVersion']);
        $this->assertSame(400, webengage_http_status_for_code($parsed['code']));
    }

    /** @return array<string, array{array, int}> */
    public static function invalidRequests(): array
    {
        return [
            'empty body'        => [['smsData' => ['body' => '   ']], WEBENGAGE_CODE_EMPTY_BODY],
            'non-string body'   => [['smsData' => ['body' => 12]], WEBENGAGE_CODE_EMPTY_BODY],
            'body too long'     => [['smsData' => ['body' => str_repeat('a', WEBENGAGE_MAX_BODY_CHARS + 1)]], WEBENGAGE_CODE_BODY_TOO_LONG],
            'bad number'        => [['smsData' => ['toNumber' => '12']], WEBENGAGE_CODE_INVALID_NUMBER],
            'non-string number' => [['smsData' => ['toNumber' => ['x']]], WEBENGAGE_CODE_INVALID_NUMBER],
            'non-string sender' => [['smsData' => ['fromNumber' => 5000]], WEBENGAGE_CODE_INVALID_SENDER],
            'no messageId'      => [['metadata' => ['messageId' => '']], WEBENGAGE_CODE_OTHER],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequestsWithTheMatchingCode(array $overrides, int $code): void
    {
        $parsed = webengage_parse_request($this->request($overrides));
        $this->assertFalse($parsed['ok']);
        $this->assertSame($code, $parsed['code']);
    }

    public function testRejectsANonObjectBodyAndMissingSections(): void
    {
        $this->assertFalse(webengage_parse_request(null)['ok']);
        $this->assertFalse(webengage_parse_request(['version' => '1.0'])['ok']);
    }

    public function testResponseBodiesFollowWebEngageShape(): void
    {
        $this->assertSame(['status' => 'sms_accepted'], webengage_accept_body());
        $this->assertSame(
            ['status' => 'sms_rejected', 'statusCode' => 2010, 'message' => 'm', 'supportedVersion' => '2.0'],
            webengage_reject_body(2010, 'm', ['supportedVersion' => '2.0'])
        );
    }

    public function testHttpStatusPerCodeMatchesTheSpec(): void
    {
        $this->assertSame(200, webengage_http_status_for_code(WEBENGAGE_CODE_INSUFFICIENT_CREDIT));
        $this->assertSame(401, webengage_http_status_for_code(WEBENGAGE_CODE_IP_NOT_WHITELISTED));
        $this->assertSame(403, webengage_http_status_for_code(WEBENGAGE_CODE_AUTHORIZATION_FAILED));
        $this->assertSame(401, webengage_http_status_for_code(WEBENGAGE_CODE_AUTHENTICATION_FAILED));
        $this->assertSame(429, webengage_http_status_for_code(WEBENGAGE_CODE_THROTTLED));
        $this->assertSame(403, webengage_http_status_for_code(WEBENGAGE_CODE_BLACKLISTED));
        $this->assertSame(413, webengage_http_status_for_code(WEBENGAGE_CODE_BODY_TOO_LONG));
        $this->assertSame(200, webengage_http_status_for_code(WEBENGAGE_CODE_OTHER));
    }

    public function testSharedApiGateErrorsMapToWebEngageCodes(): void
    {
        $this->assertSame([401, 2011], webengage_code_for_api_error(401, \ApiResponse::CODE_UNAUTHENTICATED));
        $this->assertSame([429, 2015], webengage_code_for_api_error(429, \ApiResponse::CODE_RATE_LIMITED));
        $this->assertSame([403, 2005], webengage_code_for_api_error(403, \ApiResponse::CODE_FORBIDDEN));
        $this->assertSame([403, 2005], webengage_code_for_api_error(402, \ApiResponse::CODE_SUBSCRIPTION_INACTIVE));
        $this->assertSame([403, 2005], webengage_code_for_api_error(403, \ApiResponse::CODE_FEATURE_NOT_AVAILABLE));
        $this->assertSame([413, 2007], webengage_code_for_api_error(413, \ApiResponse::CODE_PAYLOAD_TOO_LARGE));
        // Anything without a WebEngage counterpart keeps the pipeline's own HTTP status.
        $this->assertSame([503, 9988], webengage_code_for_api_error(503, \ApiResponse::CODE_SERVICE_UNAVAILABLE));
        $this->assertSame([415, 9988], webengage_code_for_api_error(415, \ApiResponse::CODE_INVALID_REQUEST));
    }

    public function testErrorRendererSwapsTheEnvelopeAndCanBeReset(): void
    {
        \ApiResponse::setErrorRenderer('webengage_render_api_error');
        try {
            ob_start();
            \ApiResponse::error(401, \ApiResponse::CODE_UNAUTHENTICATED, 'Missing or invalid API credentials.');
            $body = json_decode((string)ob_get_clean(), true);
        } finally {
            \ApiResponse::setErrorRenderer(null);
        }
        $this->assertSame(['status' => 'sms_rejected', 'statusCode' => 2011, 'message' => 'Missing or invalid API credentials.'], $body);

        ob_start();
        \ApiResponse::error(401, \ApiResponse::CODE_UNAUTHENTICATED, 'x');
        $default = json_decode((string)ob_get_clean(), true);
        $this->assertSame('unauthenticated', $default['error']['code']);
    }

    public function testDeliveryStatesMapToDsnStatuses(): void
    {
        $this->assertSame(['sms_sent', 0], webengage_dsn_status_for_state('delivered'));
        $this->assertSame(['sms_failed', 2008], webengage_dsn_status_for_state('expired'));
        $this->assertSame(['sms_failed', 2009], webengage_dsn_status_for_state('failed'));
        $this->assertSame(['sms_failed', 2009], webengage_dsn_status_for_state('rejected'));
        $this->assertSame(['sms_sent', 0], webengage_dsn_status_for_state('no_report'));
    }

    public function testDsnPayload(): void
    {
        $row = ['we_message_id' => 'we-msg-1', 'to_number' => '+989121234567', 'dsn_sms_status' => 'sms_failed', 'dsn_status_code' => '2009', 'parts' => '2', 'delivery_state' => 'failed'];
        $this->assertSame([
            'version' => '1.0', 'messageId' => 'we-msg-1', 'toNumber' => '+989121234567',
            'status' => 'sms_failed', 'statusCode' => 2009, 'smsCount' => 2, 'message' => 'Delivery state: failed',
        ], webengage_dsn_payload($row));

        $sent = webengage_dsn_payload(['dsn_sms_status' => 'sms_sent', 'dsn_status_code' => 0, 'parts' => 0, 'delivery_state' => 'delivered'] + $row);
        $this->assertArrayNotHasKey('message', $sent);
        $this->assertSame(1, $sent['smsCount']);
    }

    public function testDsnUrlPerRegion(): void
    {
        $this->assertSame('https://st.webengage.com/tracking/events', webengage_dsn_url(['dsn_region' => 'us']));
        $this->assertSame('https://st.in.webengage.com/tracking/events', webengage_dsn_url(['dsn_region' => 'in']));
        $this->assertSame('https://st.ksa.webengage.com/tracking/events', webengage_dsn_url(['dsn_region' => 'ksa']));
        $this->assertSame('https://dsn.example.com/hook', webengage_dsn_url(['dsn_region' => 'custom', 'dsn_url' => ' https://dsn.example.com/hook ']));
        $this->assertNull(webengage_dsn_url(['dsn_region' => 'custom', 'dsn_url' => '']));
        $this->assertNull(webengage_dsn_url(['dsn_region' => 'mars']));
    }

    public function testBasicCredentialsCarryTheApiKeyAsPassword(): void
    {
        $this->assertSame('ellsms_live_abc.def', \ApiAuth::basicCredentialKey('Basic ' . base64_encode('webengage:ellsms_live_abc.def')));
        $this->assertSame('key:with:colons', \ApiAuth::basicCredentialKey('Basic ' . base64_encode('user:key:with:colons')));
        $this->assertSame('only_user', \ApiAuth::basicCredentialKey('basic ' . base64_encode('only_user:')));
        $this->assertNull(\ApiAuth::basicCredentialKey('Basic ' . base64_encode(':')));
        $this->assertNull(\ApiAuth::basicCredentialKey('Basic not*base64'));
        $this->assertNull(\ApiAuth::basicCredentialKey('Bearer abc'));
    }

    public function testWebEngageSendsCountAsTheSendMenu(): void
    {
        $this->assertSame('send', menu_key_for_api_path(WEBENGAGE_API_PATH));
    }
}
