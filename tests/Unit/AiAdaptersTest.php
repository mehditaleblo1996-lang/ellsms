<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Ai/* without a database or network: request building and response parsing for every provider
 * type, error classification and secret scrubbing, the outbound address policy, provider-form
 * validation, the vault's encryption, and model-access resolution across an account chain.
 * The network entry point is exercised through the $GLOBALS['__ai_http_transport'] seam only.
 */
final class AiAdaptersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__ai_http_transport']);
    }

    private function cfg(string $type, array $overrides = []): array
    {
        return $overrides + [
            'type' => $type, 'base_url' => \AI_PROVIDER_DEFAULT_BASE_URLS[$type] ?: 'http://hermes.internal:8642/v1',
            'auth_scheme' => 'bearer', 'auth_header' => '', 'api_key' => 'sk-test-secret-123456',
            'extra_headers' => [], 'allow_private_network' => true, 'connect_timeout_ms' => 1000, 'request_timeout_ms' => 2000,
        ];
    }

    /* ---------------- headers ---------------- */

    public function testBearerHeaderAndExtraHeaders(): void
    {
        $h = \ai_request_headers($this->cfg('openai_compatible', ['extra_headers' => ['X-Title' => 'ELLSMS']]), true);
        $this->assertContains('Authorization: Bearer sk-test-secret-123456', $h);
        $this->assertContains('Content-Type: application/json', $h);
        $this->assertContains('X-Title: ELLSMS', $h);
    }

    public function testCustomHeaderAndNoKeyAndNone(): void
    {
        $this->assertContains('api-key: sk-test-secret-123456',
            \ai_request_headers($this->cfg('openai_compatible', ['auth_scheme' => 'header', 'auth_header' => 'api-key']), false));
        $none = \ai_request_headers($this->cfg('openai_compatible', ['auth_scheme' => 'none', 'api_key' => null]), false);
        $this->assertSame(['Accept: application/json'], $none);
    }

    public function testAnthropicAlwaysUsesXApiKeyAndVersion(): void
    {
        $h = \ai_request_headers($this->cfg('anthropic', ['auth_scheme' => 'header', 'auth_header' => 'nope']), true);
        $this->assertContains('x-api-key: sk-test-secret-123456', $h);
        $this->assertContains('anthropic-version: ' . \AI_ANTHROPIC_VERSION, $h);
        foreach ($h as $line) {
            $this->assertStringStartsNotWith('Authorization', $line);
            $this->assertStringStartsNotWith('nope', $line);
        }
    }

    public function testMissingKeyRules(): void
    {
        $this->assertTrue(\ai_config_missing_key($this->cfg('openai', ['api_key' => null])));
        $this->assertTrue(\ai_config_missing_key($this->cfg('anthropic', ['api_key' => ''])));
        $this->assertFalse(\ai_config_missing_key($this->cfg('openai_compatible', ['api_key' => null, 'auth_scheme' => 'none'])));
        $this->assertTrue(\ai_config_missing_key($this->cfg('openai_compatible', ['api_key' => null, 'auth_scheme' => 'bearer'])));
        $this->assertFalse(\ai_config_missing_key($this->cfg('ollama', ['api_key' => null, 'auth_scheme' => 'none'])));
    }

    public function testExtraHeaderParsingRefusesReservedAndBrokenLines(): void
    {
        [$headers, $errors] = \ai_parse_extra_headers("HTTP-Referer: https://panel.example\nAuthorization: Bearer x\nbad line\nX-Ok: 1");
        $this->assertSame(['HTTP-Referer' => 'https://panel.example', 'X-Ok' => '1'], $headers);
        $this->assertCount(2, $errors);
    }

    /* ---------------- model discovery ---------------- */

    public function testModelsUrlsPerType(): void
    {
        $this->assertSame('http://hermes.internal:8642/v1/models', \ai_build_models_request($this->cfg('openai_compatible'))['url']);
        $this->assertSame('http://localhost:11434/api/tags', \ai_build_models_request($this->cfg('ollama'))['url']);
        $this->assertSame('https://api.anthropic.com/v1/models?limit=1000&after_id=m%201',
            \ai_build_models_request($this->cfg('anthropic'), 'm 1')['url']);
        $this->assertNull(\ai_build_models_request($this->cfg('openai'))['body']);
    }

    public function testParsesOpenAiCompatibleAndOllamaAndDropsJunk(): void
    {
        $parsed = \ai_parse_models('openai_compatible', ['object' => 'list', 'data' => [
            ['id' => 'hermes-agent', 'object' => 'model'], ['id' => ''], 'junk', ['id' => 'hermes-agent'], ['id' => 'NousResearch/Hermes-4', 'max_model_len' => 32768],
        ]]);
        $this->assertSame(['hermes-agent', 'NousResearch/Hermes-4'], array_column($parsed['models'], 'id'));
        $this->assertSame(32768, $parsed['models'][1]['context_window']);
        $this->assertNull($parsed['next']);

        $ollama = \ai_parse_models('ollama', ['models' => [['name' => 'hermes3:8b', 'model' => 'hermes3:8b']]]);
        $this->assertSame('hermes3:8b', $ollama['models'][0]['id']);
    }

    public function testParsesOpenRouterPricesIntoPerMillion(): void
    {
        $parsed = \ai_parse_models('openrouter', ['data' => [[
            'id' => 'nousresearch/hermes-4-70b', 'name' => 'Hermes 4 70B', 'context_length' => 131072,
            'pricing' => ['prompt' => '0.00000013', 'completion' => '0.0000004'], 'top_provider' => ['max_completion_tokens' => 8192],
        ]]]);
        $m = $parsed['models'][0];
        $this->assertSame('Hermes 4 70B', $m['display_name']);
        $this->assertSame('0.13', $m['input_cost_per_million']);
        $this->assertSame('0.4', $m['output_cost_per_million']);
        $this->assertSame(8192, $m['max_output_tokens']);
    }

    public function testOpenRouterReportsToolSupportPerModel(): void
    {
        $parsed = \ai_parse_models('openrouter', ['data' => [
            ['id' => 'a/tools', 'supported_parameters' => ['max_tokens', 'tools', 'tool_choice']],
            ['id' => 'b/plain', 'supported_parameters' => ['max_tokens']],
            ['id' => 'c/unknown'],
        ]]);
        $this->assertSame([true, false, null], array_column($parsed['models'], 'supports_tools'));
        $this->assertNull(\ai_parse_models('openai', ['data' => [['id' => 'gpt']]])['models'][0]['supports_tools']);
    }

    public function testOpenRouterRequestAsksForCostAndNamesTheApp(): void
    {
        $cfg = ['type' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'auth_scheme' => 'bearer', 'api_key' => 'sk-or', 'extra_headers' => []];
        $req = \ai_build_chat_request($cfg, 'x/y', [['role' => 'user', 'content' => 'hi']], 50);
        $this->assertSame(['include' => true], json_decode($req['body'], true)['usage']);
        $this->assertContains('X-Title: ELLSMS', $req['headers']);
        $own = \ai_request_headers(['extra_headers' => ['X-Title' => 'Mine']] + $cfg, true);
        $this->assertContains('X-Title: Mine', $own);
        $this->assertNotContains('X-Title: ELLSMS', $own, 'an extra header overrides the default');
        $openai = \ai_build_chat_request(['type' => 'openai_compatible'] + $cfg, 'x/y', [['role' => 'user', 'content' => 'hi']], 50);
        $this->assertArrayNotHasKey('usage', json_decode($openai['body'], true));
        $this->assertNotContains('X-Title: ELLSMS', $openai['headers']);
    }

    public function testReportedCostWinsForUsdModels(): void
    {
        $reply = \ai_parse_chat('openrouter', ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'cost' => 0.00042]]);
        $this->assertSame(0.00042, $reply['cost']);
        $this->assertNull(\ai_parse_chat('openai', ['choices' => [['message' => ['content' => 'ok']]]])['cost']);
        $usd = ['cost_currency' => 'USD', 'input_cost_per_million' => '1', 'output_cost_per_million' => '2'];
        $this->assertSame(0.00042, \ai_call_cost(0.00042, 100, 10, $usd));
        $this->assertEqualsWithDelta(0.00012, \ai_call_cost(null, 100, 10, $usd), 1e-12);
        $this->assertEqualsWithDelta(0.00012, \ai_call_cost(0.5, 100, 10, ['cost_currency' => 'EUR'] + $usd), 1e-12, 'USD report, other currency: estimate');
    }

    public function testOpenRouterKeyInfo(): void
    {
        $cfg = ['type' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'auth_scheme' => 'bearer', 'api_key' => 'k', 'extra_headers' => []];
        $this->assertSame('https://openrouter.ai/api/v1/key', \ai_build_key_request($cfg)['url']);
        $info = \ai_parse_key_info(['data' => ['label' => 'x', 'usage' => 1.25, 'limit' => 10, 'limit_remaining' => 8.75, 'is_free_tier' => false]]);
        $this->assertSame(['usage' => 1.25, 'limit' => 10.0, 'remaining' => 8.75, 'free_tier' => false], $info);
        $this->assertSame('مصرف این کلید: $1.25؛ باقی‌مانده‌ی سقف کلید: $8.75 از $10', \ai_key_info_text($info));
        $this->assertStringContainsString('سقف ندارد', \ai_key_info_text(\ai_parse_key_info(['data' => ['usage' => 0, 'limit' => null]])));
        $this->assertNull(\ai_key_info(['type' => 'openai'] + $cfg), 'only OpenRouter has this endpoint');
    }

    public function testGapGptIsAnOpenAiCompatibleGatewayThatNeedsAKey(): void
    {
        $this->assertSame('https://api.gapgpt.app/v1', \AI_PROVIDER_DEFAULT_BASE_URLS['gapgpt']);
        $cfg = ['type' => 'gapgpt', 'base_url' => 'https://api.gapgpt.app/v1', 'auth_scheme' => 'bearer', 'api_key' => 'gk', 'extra_headers' => []];
        $req = \ai_build_chat_request($cfg, 'gpt-4o', [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'سلام']], 120);
        $this->assertSame('https://api.gapgpt.app/v1/chat/completions', $req['url']);
        $this->assertContains('Authorization: Bearer gk', $req['headers']);
        $body = json_decode($req['body'], true);
        $this->assertSame(120, $body['max_tokens']);
        $this->assertSame(['system', 'user'], array_column($body['messages'], 'role'));
        $this->assertArrayNotHasKey('usage', $body);
        $this->assertSame('https://api.gapgpt.app/v1/models', \ai_build_models_request($cfg)['url']);
        $this->assertTrue(\ai_config_missing_key(['api_key' => null, 'auth_scheme' => 'none'] + $cfg), 'a hosted gateway always needs a key');
        $reply = \ai_parse_chat('gapgpt', ['choices' => [['message' => ['content' => 'درود'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 2]]);
        $this->assertSame(['درود', 9, 2], [$reply['text'], $reply['input_tokens'], $reply['output_tokens']]);
    }

    public function testParsesAnthropicModelsAndPagination(): void
    {
        $parsed = \ai_parse_models('anthropic', ['data' => [
            ['type' => 'model', 'id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'max_input_tokens' => 1000000, 'max_tokens' => 128000],
        ], 'has_more' => true, 'first_id' => 'claude-haiku-5-5', 'last_id' => 'claude-haiku-5-5']);
        $this->assertSame('Claude Haiku 5.5', $parsed['models'][0]['display_name']);
        $this->assertSame(1000000, $parsed['models'][0]['context_window']);
        $this->assertSame('claude-haiku-5-5', $parsed['next']);
    }

    public function testModelsResponseWithoutAListIsInvalid(): void
    {
        $this->expectException(\AiProviderException::class);
        \ai_parse_models('openai', ['models' => []]);
    }

    public function testListModelsFollowsAnthropicPages(): void
    {
        $calls = [];
        $GLOBALS['__ai_http_transport'] = static function (array $request) use (&$calls): array {
            $calls[] = $request['url'];
            $page = str_contains($request['url'], 'after_id') ? 2 : 1;
            return ['status' => 200, 'errno' => 0, 'body' => json_encode($page === 1
                ? ['data' => [['id' => 'a']], 'has_more' => true, 'last_id' => 'a']
                : ['data' => [['id' => 'b']], 'has_more' => false, 'last_id' => 'b'])];
        };
        $result = \ai_list_models($this->cfg('anthropic'));
        $this->assertSame(['a', 'b'], array_column($result['models'], 'id'));
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('after_id=a', $calls[1]);
    }

    /* ---------------- chat ---------------- */

    public function testOpenAiCompatibleChatRequest(): void
    {
        $req = \ai_build_chat_request($this->cfg('openai_compatible'), 'hermes-agent',
            [['role' => 'system', 'content' => 'کوتاه جواب بده'], ['role' => 'user', 'content' => 'سلام']], 200, 0.3);
        $body = json_decode($req['body'], true);
        $this->assertSame('http://hermes.internal:8642/v1/chat/completions', $req['url']);
        $this->assertSame(200, $body['max_tokens']);
        $this->assertSame(0.3, $body['temperature']);
        $this->assertFalse($body['stream']);
        $this->assertSame('سلام', $body['messages'][1]['content']);
        $this->assertStringContainsString('سلام', $req['body'], 'Persian is sent unescaped');
    }

    public function testOpenAiUsesMaxCompletionTokens(): void
    {
        $body = json_decode(\ai_build_chat_request($this->cfg('openai'), 'gpt-x', [['role' => 'user', 'content' => 'hi']], 50)['body'], true);
        $this->assertSame(50, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testOllamaChatRequest(): void
    {
        $req = \ai_build_chat_request($this->cfg('ollama'), 'hermes3:8b', [['role' => 'user', 'content' => 'hi']], 64, 0.0);
        $body = json_decode($req['body'], true);
        $this->assertSame('http://localhost:11434/api/chat', $req['url']);
        $this->assertSame(['num_predict' => 64, 'temperature' => 0], $body['options']);
    }

    public function testAnthropicChatRequestMovesSystemMergesTurnsAndSendsNoTemperature(): void
    {
        $req = \ai_build_chat_request($this->cfg('anthropic'), 'claude-haiku-5-5', [
            ['role' => 'system', 'content' => 'A'], ['role' => 'assistant', 'content' => 'prev'],
            ['role' => 'user', 'content' => 'q1'], ['role' => 'user', 'content' => 'q2'], ['role' => 'system', 'content' => 'B'],
        ], 100, 0.7);
        $body = json_decode($req['body'], true);
        $this->assertSame('https://api.anthropic.com/v1/messages', $req['url']);
        $this->assertSame("A\n\nB", $body['system']);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'), 'starts with user, alternates');
        $this->assertSame("q1\n\nq2", $body['messages'][2]['content']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertSame(100, $body['max_tokens']);
    }

    public function testParsesChatResponses(): void
    {
        $openai = \ai_parse_chat('openai_compatible', ['choices' => [['message' => ['content' => "<think>reasoning</think>\nسلام!"], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]]);
        $this->assertSame(['text' => 'سلام!', 'input_tokens' => 12, 'output_tokens' => 3, 'finish_reason' => 'stop', 'tool_calls' => [], 'cost' => null,
            'reasoning_tokens' => null, 'truncated' => false], $openai);

        // A thinking model that spent the output budget on reasoning: the answer is cut off.
        $cut = \ai_parse_chat('gapgpt', ['choices' => [['message' => ['content' => 'سلام! پایتخت'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 41, 'completion_tokens' => 302, 'completion_tokens_details' => ['reasoning_tokens' => 270]]]);
        $this->assertSame([true, 270], [$cut['truncated'], $cut['reasoning_tokens']]);
        $this->assertTrue(\ai_parse_chat('anthropic', ['content' => [['type' => 'text', 'text' => 'x']], 'stop_reason' => 'max_tokens'])['truncated']);

        $parts = \ai_parse_chat('openai', ['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]]]]]);
        $this->assertSame('ab', $parts['text']);

        $ollama = \ai_parse_chat('ollama', ['message' => ['role' => 'assistant', 'content' => 'ok'], 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 1]);
        $this->assertSame([5, 1, 'stop'], [$ollama['input_tokens'], $ollama['output_tokens'], $ollama['finish_reason']]);

        $anthropic = \ai_parse_chat('anthropic', ['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'Hello']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 9, 'output_tokens' => 2]]);
        $this->assertSame('Hello', $anthropic['text']);
        $this->assertSame(9, $anthropic['input_tokens']);
    }

    public function testEmptyAndRefusedResponsesAreErrors(): void
    {
        try {
            \ai_parse_chat('openai_compatible', ['choices' => [['message' => ['content' => '<think>x</think>  ']]]]);
            $this->fail('empty answer accepted');
        } catch (\AiProviderException $e) {
            $this->assertSame('empty_response', $e->errorCode);
        }
        try {
            \ai_parse_chat('anthropic', ['content' => [], 'stop_reason' => 'refusal']);
            $this->fail('refusal accepted');
        } catch (\AiProviderException $e) {
            $this->assertSame('refusal', $e->errorCode);
        }
    }

    public function testChatThroughTransportSeam(): void
    {
        $GLOBALS['__ai_http_transport'] = static fn(array $request): array => [
            'status' => 200, 'errno' => 0,
            'body' => json_encode(['choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
        ];
        $reply = \ai_chat($this->cfg('openai_compatible'), 'hermes-agent', [['role' => 'user', 'content' => 'ping']], 10);
        $this->assertSame('pong', $reply['text']);
        $this->assertArrayHasKey('latency_ms', $reply);
    }

    /* ---------------- errors ---------------- */

    public function testHttpErrorsAreClassifiedAndTheKeyIsScrubbed(): void
    {
        $GLOBALS['__ai_http_transport'] = static fn(): array => [
            'status' => 401, 'errno' => 0, 'body' => json_encode(['error' => ['message' => 'Incorrect API key provided: sk-test-secret-123456']]),
        ];
        try {
            \ai_list_models($this->cfg('openai'));
            $this->fail('no exception');
        } catch (\AiProviderException $e) {
            $this->assertSame('auth_failed', $e->errorCode);
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringNotContainsString('sk-test-secret-123456', $e->getMessage());
            $this->assertStringContainsString('Incorrect API key', $e->getMessage());
        }
    }

    public function testClassification(): void
    {
        $this->assertSame('rate_limited', \ai_classify_http_error(429, null, '')[0]);
        $this->assertSame('upstream_error', \ai_classify_http_error(503, null, '<h1>down</h1>')[0]);
        $this->assertSame('down', \ai_classify_http_error(503, null, '<h1>down</h1>')[1]);
        $this->assertSame(['not_found', 'model x'], \ai_classify_http_error(404, ['error' => 'model x'], ''));
        $this->assertSame('bad_request', \ai_classify_http_error(400, ['detail' => 'bad'], '')[0]);
    }

    public function testTransportErrorsMapToCodes(): void
    {
        $GLOBALS['__ai_http_transport'] = static fn(): array => ['status' => 0, 'errno' => \CURLE_OPERATION_TIMEDOUT, 'body' => ''];
        try {
            \ai_list_models($this->cfg('openai_compatible'));
            $this->fail('no exception');
        } catch (\AiProviderException $e) {
            $this->assertSame('timeout', $e->errorCode);
        }
        $GLOBALS['__ai_http_transport'] = static fn(): array => ['status' => 200, 'errno' => 0, 'body' => 'not json'];
        try {
            \ai_list_models($this->cfg('openai_compatible'));
            $this->fail('no exception');
        } catch (\AiProviderException $e) {
            $this->assertSame('invalid_json', $e->errorCode);
        }
    }

    /* ---------------- outbound policy ---------------- */

    public function testUrlPolicy(): void
    {
        $this->assertSame('https_required', \ai_check_url_policy('http://api.example.com/v1/models', false)['reason']);
        $this->assertSame('url_invalid', \ai_check_url_policy('ftp://x/y', true)['reason']);
        $this->assertSame('url_invalid', \ai_check_url_policy('https://user:pw@x.example/v1', true)['reason']);
        $this->assertTrue(\ai_check_url_policy('http://host.docker.internal:8642/v1/models', true)['ok']);
        if (!\webhook_local_targets_allowed()) {
            $this->assertSame('blocked_private', \ai_check_url_policy('https://10.0.0.5/v1/models', false)['reason']);
            $this->assertSame('blocked_private', \ai_check_url_policy('https://127.0.0.1/v1/models', false)['reason']);
        }
    }

    /* ---------------- provider form ---------------- */

    public function testProviderValidation(): void
    {
        [$v, $errors] = \ai_provider_validate(['type' => 'openai_compatible', 'code' => 'hermes_stage', 'name' => 'Hermes',
            'base_url' => 'http://host.docker.internal:8642/v1/', 'allow_private_network' => '1', 'auth_scheme' => 'none']);
        $this->assertSame([], $errors);
        $this->assertSame('http://host.docker.internal:8642/v1', $v['base_url'], 'trailing slash removed');
        $this->assertSame(1, $v['allow_private_network']);

        [, $errors] = \ai_provider_validate(['type' => 'openai_compatible', 'code' => 'h', 'name' => 'H', 'base_url' => 'http://x.example/v1']);
        $this->assertNotEmpty($errors, 'short code and http without the private switch');

        [$v, $errors] = \ai_provider_validate(['type' => 'anthropic', 'code' => 'claude', 'name' => 'Claude', 'base_url' => '']);
        $this->assertSame([], $errors);
        $this->assertSame('https://api.anthropic.com/v1', $v['base_url'], 'default base URL filled');

        [, $errors] = \ai_provider_validate(['type' => 'openai', 'code' => 'oa', 'name' => 'OA', 'base_url' => 'https://api.openai.com/v1?x=1']);
        $this->assertNotEmpty($errors, 'query string refused');

        [$v] = \ai_provider_validate(['type' => 'openai', 'name' => 'New name', 'base_url' => 'https://api.openai.com/v1'],
            ['type' => 'openai', 'code' => 'kept_code']);
        $this->assertSame('kept_code', $v['code'], 'code and type cannot change on edit');
    }

    public function testPriceAndCost(): void
    {
        $this->assertNull(\ai_parse_price(''));
        $this->assertSame('0.15', \ai_parse_price('0,15'));
        $this->assertFalse(\ai_parse_price('-1'));
        $this->assertEqualsWithDelta(0.0006, \ai_cost_estimate(1000, 500, '0.1', '1.0'), 1e-12);
        $this->assertNull(\ai_cost_estimate(1000, 500, null, '1.0'));
    }

    /* ---------------- vault ---------------- */

    public function testVaultRoundTripTamperAndKeySeparation(): void
    {
        $master = str_repeat('m', 40);
        $key = \ai_secret_derive_key($master);
        $sealed = \ai_secret_encrypt($key, 'sk-live');
        $this->assertSame('sk-live', \ai_secret_decrypt($key, $sealed['ciphertext'], $sealed['nonce'], $sealed['tag']));
        $this->assertNull(\ai_secret_decrypt($key, $sealed['ciphertext'] . 'x', $sealed['nonce'], $sealed['tag']));
        $this->assertNotSame(hash_hkdf('sha256', $master, 32, \GATEWAY_SECRET_KEY_PURPOSE), $key, 'never the gateway vault key');
        $this->assertNull(\ai_secret_derive_key(''));
        $this->expectException(\AiSecretException::class);
        \ai_secret_derive_key('short');
    }

    /* ---------------- access resolution ---------------- */

    private function level(bool $custom, array $models = [], ?int $default = null): array
    {
        return ['custom' => $custom, 'models' => $models, 'default' => $default];
    }

    public function testTopLevelWithoutSettingsGetsPlatformDefaultOnlyWhenSwitchedOn(): void
    {
        $this->assertSame(['models' => [], 'default' => null, 'source' => 'none'],
            \ai_access_resolve([$this->level(false)], [1, 2], 1, false));
        $this->assertSame(['models' => [1], 'default' => 1, 'source' => 'platform'],
            \ai_access_resolve([$this->level(false)], [1, 2], 1, true));
        $this->assertSame('none', \ai_access_resolve([$this->level(false)], [2], 1, true)['source'], 'disabled platform default is not offered');
    }

    public function testCustomSetReplacesPlatformDefaultAndDropsDisabledModels(): void
    {
        $r = \ai_access_resolve([$this->level(true, [2, 3, 9], 3)], [1, 2, 3], 1, true);
        $this->assertSame(['models' => [2, 3], 'default' => 3, 'source' => 'custom'], $r);
    }

    public function testSubAccountInheritsAndCanOnlyNarrow(): void
    {
        $reseller = $this->level(true, [1, 2], 2);
        $this->assertSame(['models' => [1, 2], 'default' => 2, 'source' => 'inherited'],
            \ai_access_resolve([$reseller, $this->level(false)], [1, 2, 3], 0, false));
        $this->assertSame(['models' => [1], 'default' => 1, 'source' => 'custom'],
            \ai_access_resolve([$reseller, $this->level(true, [1, 3], 3)], [1, 2, 3], 0, false),
            'model 3 is not the reseller\'s to give; the invalid default falls back to the first model');
    }

    public function testDefaultFallsBackToPlatformDefaultWhenInSet(): void
    {
        $r = \ai_access_resolve([$this->level(true, [1, 2])], [1, 2], 2, false);
        $this->assertSame(2, $r['default']);
    }
}
