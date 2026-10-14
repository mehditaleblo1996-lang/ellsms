<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/NumberForwarding.php without a database: Vesal-compatible ${...} rendering, the template
 * check (placeholders, scheme, host), the address check (literal IPs only — never real DNS), and
 * the request each line's settings produce.
 */
final class NumberForwardingTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        $this->settings([]);
    }

    protected function tearDown(): void
    {
        \number_forward_cache_reset();
    }

    /** Pins the settings cache so no test reaches for the database. */
    private function settings(array $overrides): void
    {
        $values = [];
        foreach (\NUMBER_FORWARD_SETTINGS as $key => [$default]) {
            $values[$key] = $overrides[$key] ?? $default;
        }
        $GLOBALS['__number_forward_settings'] = ['at' => microtime(true) + 3600, 'values' => $values];
    }

    public function testRendersVesalPlaceholdersUrlEncodedLikeVesal(): void
    {
        $url = \number_forward_render_url(
            'https://example.com/r?from=${originator}&to=${destination}&text=${content}',
            ['originator' => '989121234567', 'destination' => '5000123', 'content' => 'سلام a&b=c d']
        );
        $this->assertSame('https://example.com/r?from=989121234567&to=5000123&text=' . urlencode('سلام a&b=c d'), $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('سلام a&b=c d', $q['text'], 'content cannot break out of its parameter');
    }

    public function testAMissingValueRendersEmpty(): void
    {
        $this->assertSame('https://e.com/?u=', \number_forward_render_url('https://e.com/?u=${udh}', []));
    }

    public function testAGetTemplateWithoutPlaceholdersGetsEveryValueAppended(): void
    {
        $vars = ['id' => '7', 'status' => '2'];
        $this->assertSame('https://e.com/dlr?id=7&status=2', \number_forward_render_url('https://e.com/dlr', $vars));
        $this->assertSame('https://e.com/dlr?k=1&id=7&status=2', \number_forward_render_url('https://e.com/dlr?k=1', $vars));
        $this->assertSame('https://e.com/dlr', \number_forward_render_url('https://e.com/dlr', $vars, 'POST'), 'POST carries them in the body instead');
    }

    public function testTemplateCheckAcceptsTheKindsOwnPlaceholdersOnly(): void
    {
        $this->assertTrue(\number_forward_check_template_syntax('https://e.com/?a=${originator}&b=${content}&c=${udh}', 'mo')['ok']);
        $this->assertTrue(\number_forward_check_template_syntax('https://e.com/?id=${id}&s=${status}&t=${dl_time}&p=${part}', 'dlr')['ok']);
        $check = \number_forward_check_template_syntax('https://e.com/?s=${status}', 'mo');
        $this->assertFalse($check['ok']);
        $this->assertSame('unknown_placeholder', $check['reason']);
    }

    public function testTemplateCheckRefusesBadUrls(): void
    {
        foreach ([
            ''                                    => 'url_empty',
            'ftp://e.com/x'                       => 'unsupported_scheme',
            'https://user:pw@e.com/x'             => 'credentials_in_url',
            'https://${originator}.e.com/x'       => 'url_invalid',
            'https://e.com/a b'                   => 'url_whitespace',
            'not a url'                           => 'url_whitespace',
            'e.com/x'                             => 'url_invalid',
            'https://e.com/' . str_repeat('a', 2100) => 'url_too_long',
        ] as $url => $reason) {
            $check = \number_forward_check_template_syntax((string)$url, 'mo');
            $this->assertFalse($check['ok'], (string)$url);
            $this->assertSame($reason, $check['reason'], (string)$url);
        }
    }

    public function testHttpFollowsTheAdminSetting(): void
    {
        $this->assertTrue(\number_forward_check_template_syntax('http://e.com/x', 'mo')['ok']);
        $this->settings(['number_forward_allow_http' => '0']);
        $this->assertSame('https_required', \number_forward_check_template_syntax('http://e.com/x', 'mo')['reason']);
        $this->assertTrue(\number_forward_check_template_syntax('https://e.com/x', 'mo')['ok']);
    }

    public function testPrivateAndLoopbackTargetsAreRefused(): void
    {
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        foreach (['http://127.0.0.1/x', 'http://10.0.0.5/x', 'http://169.254.169.254/latest', 'http://[::1]/x', 'http://localhost/x', 'http://printer.local/x'] as $url) {
            $this->assertFalse(\number_forward_resolve_target($url)['ok'], $url);
        }
        $ok = \number_forward_resolve_target('https://8.8.8.8:8443/x');
        $this->assertTrue($ok['ok']);
        $this->assertSame(8443, $ok['port']);
    }

    public function testVesalStatusCodes(): void
    {
        $this->assertSame(2, \NUMBER_FORWARD_VESAL_STATUS['delivered']);
        $this->assertSame(3, \NUMBER_FORWARD_VESAL_STATUS['failed']);
        $this->assertSame(7, \NUMBER_FORWARD_VESAL_STATUS['expired']);
        $this->assertSame(8, \NUMBER_FORWARD_VESAL_STATUS['rejected']);
    }

    public function testBuildRequestGetPostFormAndJson(): void
    {
        $vars = ['originator' => '98912', 'destination' => '5000', 'content' => 'hi there'];
        $config = ['mo_enabled' => 1, 'mo_url' => 'https://e.com/r?t=${content}', 'http_method' => 'GET', 'post_format' => 'form'];

        $get = \number_forward_build_request($config, 'mo', $vars, '42');
        $this->assertSame('GET', $get['method']);
        $this->assertSame('https://e.com/r?t=hi+there', $get['url']);
        $this->assertNull($get['body']);
        $this->assertContains('X-ELLSMS-Forward-Id: 42', $get['headers']);
        $this->assertContains('X-ELLSMS-Forward-Kind: mo', $get['headers']);

        $form = \number_forward_build_request(['http_method' => 'POST'] + $config, 'mo', $vars);
        $this->assertSame('POST', $form['method']);
        parse_str((string)$form['body'], $body);
        $this->assertSame($vars, $body);

        $json = \number_forward_build_request(['http_method' => 'POST', 'post_format' => 'json'] + $config, 'mo', $vars);
        $this->assertSame($vars, json_decode((string)$json['body'], true));
        $this->assertContains('Content-Type: application/json; charset=utf-8', $json['headers']);

        $this->assertNull(\number_forward_build_request(['mo_enabled' => 0] + $config, 'mo', $vars), 'switched off');
        $this->assertNull(\number_forward_build_request($config, 'dlr', $vars), 'no DLR URL');
    }

    public function testHttpExecuteRefusesHttpOnceTheAdminClosesIt(): void
    {
        $this->settings(['number_forward_allow_http' => '0']);
        $result = \number_forward_http_execute(['a' => ['url' => 'http://8.8.8.8/x', 'method' => 'GET', 'body' => null, 'headers' => []]], 1, 1);
        $this->assertSame('permanent_failure', $result['a']['outcome']);
        $this->assertSame('https_required', $result['a']['error_code']);
    }

    public function testReasonTextsArePersian(): void
    {
        $this->assertStringContainsString('500', \number_forward_reason_text('http_500'));
        $this->assertStringContainsString('داخلی', \number_forward_reason_text('ssrf_blocked_ip_range'));
    }
}
