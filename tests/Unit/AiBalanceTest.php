<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Ai/AiBalance.php's pure rules and the adapter pieces it uses, without a database or network:
 * spotting an "out of credit" provider error, OpenRouter's credit, days left, the alert level and the
 * ledger estimate.
 */
final class AiBalanceTest extends TestCase
{
    public function testOutOfCreditErrorsAreRecognised(): void
    {
        // OpenRouter: 402.
        $this->assertSame('provider_out_of_credit', \ai_classify_http_error(402, ['error' => ['message' => 'Insufficient credits']], '')[0]);
        // OpenAI: 429 with insufficient_quota.
        $this->assertSame('provider_out_of_credit', \ai_classify_http_error(429, ['error' => ['code' => 'insufficient_quota',
            'message' => 'You exceeded your current quota, please check your plan and billing details.']], '')[0]);
        // Anthropic: 400 with a message.
        $this->assertSame('provider_out_of_credit', \ai_classify_http_error(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'Your credit balance is too low to access the Anthropic API.']], '')[0]);
        // A Persian gateway.
        $this->assertSame('provider_out_of_credit', \ai_classify_http_error(403, ['detail' => 'موجودی حساب شما کافی نیست'], '')[0]);
        $this->assertSame('provider_out_of_credit', \ai_classify_http_error(400, null, 'insufficient balance')[0]);
        // Not credit: a plain rate limit, a bad key, a bad request.
        $this->assertSame('rate_limited', \ai_classify_http_error(429, ['error' => ['message' => 'Rate limit exceeded: free-models-per-day']], '')[0]);
        $this->assertSame('auth_failed', \ai_classify_http_error(401, ['error' => ['message' => 'Invalid API key']], '')[0]);
        $this->assertSame('bad_request', \ai_classify_http_error(400, ['error' => ['message' => 'max_tokens is too large']], '')[0]);
        $this->assertStringContainsString('شارژ', \ai_error_label('provider_out_of_credit'));
    }

    public function testOutOfCreditIsItsOwnErrorOverTheWire(): void
    {
        $GLOBALS['__ai_http_transport'] = static fn(): array => ['status' => 402, 'errno' => 0, 'body' => json_encode(['error' => ['message' => 'Insufficient credits']])];
        try {
            \ai_list_models(['type' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'auth_scheme' => 'bearer', 'auth_header' => '',
                'extra_headers' => [], 'api_key' => 'sk-or-123456', 'allow_private_network' => false]);
            $this->fail('no exception');
        } catch (\AiProviderException $e) {
            $this->assertSame('provider_out_of_credit', $e->errorCode);
            $this->assertSame(402, $e->httpStatus);
        } finally {
            unset($GLOBALS['__ai_http_transport']);
        }
    }

    public function testOpenRouterCredit(): void
    {
        $this->assertSame(7.5, \ai_parse_credits(['data' => ['total_credits' => 20, 'total_usage' => 12.5]]));
        $this->assertNull(\ai_parse_credits(['data' => []]));
        $key = \ai_parse_key_info(['data' => ['usage' => 1, 'limit' => 5, 'limit_remaining' => 4]]);
        $this->assertSame(4.0, \ai_remaining_credit($key, 7.5), 'the key limit is the smaller one');
        $this->assertSame(2.0, \ai_remaining_credit($key, 2.0), 'the account runs out first');
        $noLimit = \ai_parse_key_info(['data' => ['usage' => 1, 'limit' => null]]);
        $this->assertSame(7.5, \ai_remaining_credit($noLimit, 7.5));
        $this->assertNull(\ai_remaining_credit($noLimit, null));
        $this->assertSame(0.0, \ai_remaining_credit(null, -0.3), 'never negative');
        $this->assertNull(\ai_provider_credit(['type' => 'gapgpt']), 'only OpenRouter reports a balance');
    }

    public function testDaysLeft(): void
    {
        $this->assertSame(5.0, \ai_balance_days_left(10.0, 2.0));
        $this->assertNull(\ai_balance_days_left(10.0, 0.0), 'nothing spent: no forecast');
        $this->assertNull(\ai_balance_days_left(null, 2.0));
        $this->assertSame(0.0, \ai_balance_days_left(-1.0, 2.0));
    }

    public function testLevels(): void
    {
        $t = ['warn_days' => 7.0, 'critical_days' => 2.0, 'warn_amount' => null, 'critical_amount' => 1.0];
        $this->assertSame('ok', \ai_balance_level(50.0, 30.0, $t, false));
        $this->assertSame('ok', \ai_balance_level(50.0, null, $t, false), 'no spend: only the amount counts');
        $this->assertSame('warning', \ai_balance_level(50.0, 7.0, $t, false));
        $this->assertSame('critical', \ai_balance_level(50.0, 1.5, $t, false));
        $this->assertSame('critical', \ai_balance_level(1.0, null, $t, false), '1 USD or less');
        $this->assertSame('critical', \ai_balance_level(0.4, 40.0, $t, false));
        $this->assertSame('critical', \ai_balance_level(0.0, null, ['warn_days' => null, 'critical_days' => null, 'warn_amount' => null, 'critical_amount' => null], false));
        $this->assertSame('emergency', \ai_balance_level(50.0, 30.0, $t, true), 'the provider said it is out');
        $this->assertSame('unknown', \ai_balance_level(null, null, $t, false));
        $this->assertSame('warning', \ai_balance_level(4.0, null, ['warn_amount' => 5.0] + $t, false));
    }

    public function testLedgerBase(): void
    {
        $this->assertNull(\ai_balance_ledger_base([]));
        $this->assertSame(30.0, \ai_balance_ledger_base([['kind' => 'topup', 'amount' => '10'], ['kind' => 'topup', 'amount' => '20']]));
        $this->assertSame(17.0, \ai_balance_ledger_base([['kind' => 'topup', 'amount' => '100'], ['kind' => 'set', 'amount' => '12'],
            ['kind' => 'topup', 'amount' => '5']]), 'a "set" restarts the estimate');
    }

    public function testFormatting(): void
    {
        $this->assertSame('$12.35', \ai_balance_format(12.345, 'USD'));
        $this->assertSame('$0.4200', \ai_balance_format(0.42, 'USD'));
        $this->assertSame('1,250,000 IRR', \ai_balance_format(1250000.0, 'IRR'));
        $this->assertSame('—', \ai_balance_format(null, 'USD'));
        $this->assertSame('حدود ۳.۵ روز', \ai_balance_days_text(3.5));
        $this->assertSame('حدود ۶ ساعت', \ai_balance_days_text(0.25));
        $this->assertSame(2.5, \ai_balance_parse_amount('۲.۵'));
        $this->assertSame(1000.0, \ai_balance_parse_amount('1,000'));
        $this->assertNull(\ai_balance_parse_amount(''));
        $this->assertFalse(\ai_balance_parse_amount('-3'));
    }
}
