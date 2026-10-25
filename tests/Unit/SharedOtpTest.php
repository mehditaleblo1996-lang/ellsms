<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure parts of app/SharedOtp.php: template parsing, rendering, the line list and the line grant. */
final class SharedOtpTest extends TestCase
{
    public function testTemplateListsItsVariablesInOrder(): void
    {
        $r = shared_otp_template_parse("فروشگاه نمونه\n{name} عزیز، کد ورود شما: {code}\nاعتبار: {minutes} دقیقه — {code}");
        self::assertTrue($r['ok']);
        self::assertSame(['name', 'code', 'minutes'], $r['variables']);
    }

    public function testTemplateNeedsAVariableAndFixedText(): void
    {
        self::assertSame('no_variable', shared_otp_template_parse('کد شما ۱۲۳۴ است')['error_code']);
        self::assertSame('no_fixed_text', shared_otp_template_parse('{code} {name}')['error_code']);
        self::assertSame('empty', shared_otp_template_parse("  \n ")['error_code']);
        self::assertSame('too_long', shared_otp_template_parse(str_repeat('ا', SHARED_OTP_MAX_CONTENT_CHARS) . '{code}')['error_code']);
    }

    public function testTemplateRejectsBadPlaceholdersAndTooManyVariables(): void
    {
        self::assertSame('bad_variable', shared_otp_template_parse('کد شما: {کد} و {code}')['error_code']);
        self::assertSame('bad_variable', shared_otp_template_parse('کد شما: {code')['error_code']);
        self::assertSame('too_many_variables', shared_otp_template_parse('کد شما: {a}{b}{c}{d}{e}{f}')['error_code']);
    }

    public function testRenderByNameAndByPosition(): void
    {
        $content = '{name} عزیز، کد شما: {code}';
        $vars = ['name', 'code'];
        self::assertSame('علی عزیز، کد شما: 4821', shared_otp_render($content, $vars, ['code' => '4821', 'name' => 'علی'])['content']);
        self::assertSame('علی عزیز، کد شما: 4821', shared_otp_render($content, $vars, ['علی', 4821])['content']);
    }

    public function testRenderRefusesMissingExtraAndUnsafeValues(): void
    {
        $content = 'کد شما: {code}';
        $vars = ['code'];
        self::assertSame(['parameters.code' => ['required']], shared_otp_render($content, $vars, ['code' => ' '])['fields'], 'blank counts as missing');
        self::assertSame(['parameters.code' => ['required']], shared_otp_render($content, $vars, [])['fields']);
        self::assertArrayHasKey('parameters.other', shared_otp_render($content, $vars, ['code' => '1', 'other' => '2'])['fields']);
        self::assertSame(['parameters' => ['expected_1_values']], shared_otp_render($content, $vars, ['1', '2'])['fields']);
        self::assertSame(['links_not_allowed'], shared_otp_render($content, $vars, ['code' => 'https://x.io'])['fields']['parameters.code']);
        self::assertSame(['links_not_allowed'], shared_otp_render($content, $vars, ['code' => 'shop.ir'])['fields']['parameters.code']);
        self::assertSame(['single_line_only'], shared_otp_render($content, $vars, ['code' => "12\nتخفیف ویژه"])['fields']['parameters.code']);
        self::assertSame(['invalid_characters'], shared_otp_render($content, $vars, ['code' => '{code}'])['fields']['parameters.code']);
        self::assertStringStartsWith('too_long', shared_otp_render($content, $vars, ['code' => str_repeat('1', SHARED_OTP_MAX_VALUE_CHARS + 1)])['fields']['parameters.code'][0]);
        self::assertSame(['must_be_string'], shared_otp_render($content, $vars, ['code' => ['1']])['fields']['parameters.code']);
    }

    public function testLinesAreNormalizedAndDeduplicated(): void
    {
        self::assertSame(['10004000', '2000500'], shared_otp_parse_lines("۱۰۰۰۴۰۰۰، 2000500\n10004000 , x"));
        self::assertSame([], shared_otp_parse_lines(''));
    }

    public function testLineOrderIsStablePerRecipientAndCoversEveryLine(): void
    {
        $lines = ['1001', '1002', '1003'];
        $order = shared_otp_line_order($lines, '989121234567');
        self::assertSame($order, shared_otp_line_order($lines, '989121234567'));
        $sorted = $order;
        sort($sorted);
        self::assertSame($lines, $sorted);
        self::assertSame(['1001'], shared_otp_line_order(['1001'], '989121234567'));
        $starts = [];
        for ($i = 0; $i < 50; $i++) {
            $starts[shared_otp_line_order($lines, '98912000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT))[0]] = true;
        }
        self::assertCount(3, $starts, 'recipients are spread over every line');
    }

    public function testTheLineGrantLastsOnlyForTheCall(): void
    {
        self::assertNull(shared_otp_granted_line());
        $seen = shared_otp_with_line('۱۰۰۰۴۰۰۰', static fn() => shared_otp_granted_line());
        self::assertSame('10004000', $seen);
        self::assertNull(shared_otp_granted_line());
        try {
            shared_otp_with_line('10004000', static function (): void { throw new RuntimeException('boom'); });
        } catch (RuntimeException) {
        }
        self::assertNull(shared_otp_granted_line(), 'cleared even when the send throws');
    }
}
