<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Ai/AiSecretary.php without a database or network: the guards, fitting an answer into its SMS
 * parts, the system prompt and conversation messages, and the assistant form.
 */
final class AiSecretaryTest extends TestCase
{
    private function facts(array $overrides = []): array
    {
        return $overrides + [
            'is_mobile' => true, 'own_line' => false, 'opted_out' => false, 'age_seconds' => 5, 'max_age_seconds' => 1800,
            'burst_count' => 1, 'repeat_count' => 1, 'sender_hour_sent' => 0, 'sender_hourly_limit' => 10,
            'line_day_sent' => 0, 'line_daily_limit' => 500,
        ];
    }

    private function assistant(array $overrides = []): array
    {
        return $overrides + ['business_name' => 'کلینیک لبخند'] + \ai_assistant_defaults('5000123');
    }

    /* ---------------- guards ---------------- */

    public function testAnOrdinaryMessageIsAnswered(): void
    {
        $this->assertNull(\ai_secretary_guard($this->facts()));
    }

    public function testEachGuardStopsTheAnswer(): void
    {
        $cases = [
            'not_mobile'       => ['is_mobile' => false],
            'own_line'         => ['own_line' => true],
            'opted_out'        => ['opted_out' => true],
            'too_old'          => ['age_seconds' => 1801],
            'loop_suspected'   => ['burst_count' => \AI_SECRETARY_BURST_MAX + 1],
            'repeated_message' => ['repeat_count' => \AI_SECRETARY_REPEAT_MAX],
            'sender_limit'     => ['sender_hour_sent' => 10],
            'line_limit'       => ['line_day_sent' => 500],
        ];
        foreach ($cases as $reason => $override) {
            $this->assertSame($reason, \ai_secretary_guard($this->facts($override)), $reason);
            $this->assertNotSame('', \ai_secretary_reason_label($reason));
        }
    }

    public function testZeroMeansNoLimit(): void
    {
        $this->assertNull(\ai_secretary_guard($this->facts(['sender_hour_sent' => 99, 'sender_hourly_limit' => 0,
            'line_day_sent' => 9999, 'line_daily_limit' => 0])));
    }

    public function testMobileDetection(): void
    {
        foreach (['989121234567', '09121234567', '9121234567'] as $mobile) {
            $this->assertTrue(\ai_secretary_is_mobile($mobile), $mobile);
        }
        foreach (['5000123', '30001234', '98912123456', '2188776655', '1000'] as $other) {
            $this->assertFalse(\ai_secretary_is_mobile($other), $other);
        }
    }

    /* ---------------- fitting into SMS parts ---------------- */

    public function testShortAnswersAreKeptAndMarkdownIsDropped(): void
    {
        $this->assertSame('سلام! ساعت کاری ۹ تا ۱۸ است.', \ai_secretary_fit_parts('**سلام!**  ساعت کاری ۹ تا ۱۸ است.', 1));
        $this->assertSame("خط اول\n\nخط دوم", \ai_secretary_fit_parts("خط اول\n\n\n\nخط دوم", 2));
    }

    public function testLongAnswersAreCutToTheLimitAtABoundary(): void
    {
        $sentence = 'ساعت کاری کلینیک از شنبه تا چهارشنبه از ساعت شانزده تا بیست و یک است. ';
        $long = str_repeat($sentence, 10);
        foreach ([1, 2, 3] as $parts) {
            $fitted = \ai_secretary_fit_parts($long, $parts);
            $this->assertLessThanOrEqual($parts, \sms_parts($fitted), "$parts parts");
            $this->assertGreaterThan(\ai_secretary_part_chars($parts) * 0.5, mb_strlen($fitted), 'not cut needlessly short');
            $this->assertMatchesRegularExpression('/(\.|…)$/u', $fitted, 'ends on a sentence or with an ellipsis');
        }
        $latin = \ai_secretary_fit_parts(str_repeat('Opening hours are nine to six. ', 20), 1);
        $this->assertSame(1, \sms_parts($latin));
        $this->assertGreaterThan(100, strlen($latin), 'GSM text gets 160 characters per part');
    }

    public function testAWordWithoutSpacesIsHardCut(): void
    {
        $fitted = \ai_secretary_fit_parts(str_repeat('ب', 500), 2);
        $this->assertSame(2, \sms_parts($fitted));
        $this->assertStringEndsWith('…', $fitted);
    }

    public function testTokenBudgetGrowsWithParts(): void
    {
        $this->assertSame(256, \ai_secretary_max_tokens(1));
        $this->assertSame(750, \ai_secretary_max_tokens(5));
    }

    /* ---------------- prompt ---------------- */

    public function testBusinessPromptCarriesTheRulesAndTheBusinessData(): void
    {
        $prompt = \ai_secretary_system_prompt($this->assistant([
            'max_parts' => 2, 'instructions' => 'توصیه‌ی پزشکی نده.', 'knowledge' => 'ساعت کاری: ۱۶ تا ۲۱',
        ]));
        $this->assertStringContainsString('«کلینیک لبخند»', $prompt);
        $this->assertStringContainsString('ONLY from the business information', $prompt);
        $this->assertStringContainsString('under 134 characters (2 SMS parts)', $prompt);
        $this->assertStringContainsString('untrusted', $prompt);
        $this->assertStringContainsString("## Instructions from the business\nتوصیه‌ی پزشکی نده.", $prompt);
        $this->assertStringContainsString("## Business information\nساعت کاری: ۱۶ تا ۲۱", $prompt);
    }

    public function testGeneralPromptDoesNotRestrictToBusinessData(): void
    {
        $prompt = \ai_secretary_system_prompt($this->assistant(['mode' => 'general', 'max_parts' => 1]));
        $this->assertStringNotContainsString('ONLY from the business information', $prompt);
        $this->assertStringContainsString('(1 SMS part)', $prompt);
        $this->assertStringNotContainsString('## Business information', $prompt, 'empty sections are left out');
    }

    public function testMessagesCarryTheConversationOldestFirst(): void
    {
        $messages = \ai_secretary_build_messages($this->assistant(), [
            ['content' => 'سلام', 'reply_text' => 'سلام، بفرمایید.'],
            ['content' => 'بدون پاسخ', 'reply_text' => null],
            ['content' => 'آدرس؟', 'reply_text' => 'تهران، ...'],
        ], '  ساعت کاری؟  ');
        $this->assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('سلام', $messages[1]['content']);
        $this->assertSame('تهران، ...', $messages[4]['content']);
        $this->assertSame('ساعت کاری؟', $messages[5]['content']);
        $long = \ai_secretary_build_messages($this->assistant(), [], str_repeat('x', 5000));
        $this->assertSame(\AI_SECRETARY_MAX_INPUT_CHARS, mb_strlen(end($long)['content']));
    }

    /* ---------------- form ---------------- */

    public function testValidationAcceptsAGoodFormAndClampsNothingSilently(): void
    {
        [$v, $errors] = \ai_assistant_validate(['enabled' => '1', 'mode' => 'business', 'template' => 'clinic',
            'business_name' => 'لبخند', 'instructions' => "a\r\nb", 'knowledge' => 'k', 'model_id' => '7',
            'max_parts' => '۳', 'memory_turns' => '4', 'memory_hours' => '12', 'sender_hourly_limit' => '0',
            'line_daily_limit' => '100', 'fallback_text' => 'بعداً پاسخ می‌دهیم'], [7, 8]);
        $this->assertSame([], $errors);
        $this->assertSame([1, 'clinic', "a\nb", 7, 3, 4, 0], [$v['enabled'], $v['template'], $v['instructions'], $v['model_id'],
            $v['max_parts'], $v['memory_turns'], $v['sender_hourly_limit']]);
    }

    public function testValidationRefusesBadInput(): void
    {
        [, $errors] = \ai_assistant_validate(['enabled' => '1', 'mode' => 'business', 'model_id' => '9', 'max_parts' => '6'], [7]);
        $this->assertCount(3, $errors, 'empty business data, a model the account lacks, too many parts');

        [, $errors] = \ai_assistant_validate(['enabled' => '1', 'mode' => 'general'], []);
        $this->assertSame(['هیچ مدل هوش مصنوعی برای این حساب فعال نیست؛ با پشتیبانی تماس بگیرید.'], $errors);

        [$v, $errors] = \ai_assistant_validate(['mode' => 'business', 'template' => 'nope'], []);
        $this->assertSame([], $errors, 'a switched-off assistant may be saved empty');
        $this->assertSame('custom', $v['template']);
        $this->assertNull($v['model_id']);
    }

    public function testTemplatesAreComplete(): void
    {
        foreach (\AI_SECRETARY_TEMPLATES as $key => [$label, $mode, $instructions]) {
            $this->assertNotSame('', $label, $key);
            $this->assertContains($mode, ['business', 'general'], $key);
            $this->assertNotSame('', $instructions, $key);
        }
    }
}
