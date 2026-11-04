<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 4 without a database: Persian-aware normalization and search scoring (app/Ai/AiData.php),
 * table and row building, what the model is shown, the hand-off marker, and tool calls on the wire for
 * every protocol (app/Ai/AiAdapters.php).
 */
final class AiDataTest extends TestCase
{
    private function dataset(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1, 'name' => 'سفارش‌ها', 'description' => 'وضعیت سفارش‌ها',
            'columns' => [
                ['key' => 'c1', 'label' => 'کد سفارش', 'type' => 'text', 'default' => ''],
                ['key' => 'c2', 'label' => 'موبایل مشتری', 'type' => 'phone', 'default' => ''],
                ['key' => 'c3', 'label' => 'وضعیت', 'type' => 'text', 'default' => 'در حال پردازش'],
                ['key' => 'c4', 'label' => 'مبلغ', 'type' => 'number', 'default' => ''],
            ],
            'key_column' => 'c1', 'phone_column' => 'c2',
        ];
    }

    /* ---------------- normalization & search ---------------- */

    public function testNormalizationUnifiesLettersDigitsAndPunctuation(): void
    {
        $this->assertSame('کیف چرمی 4512', \ai_data_normalize('كيف  چرمي، ۴۵۱۲!'));
        $this->assertSame('می خواهم', \ai_data_normalize("می\u{200C}خواهم"));
        $this->assertSame('abc 12', \ai_data_normalize('ABC-١٢'));
        $this->assertSame('محمد', \ai_data_normalize('مُحَمَّد'));
    }

    public function testQueryTermsDropStopwordsAndPutNumbersFirst(): void
    {
        $this->assertSame(['4512', 'سفارش', 'کجاست'], \ai_data_query_terms('سلام، سفارش من ۴۵۱۲ کجاست؟'));
        $this->assertSame([], \ai_data_query_terms('سلام و ممنون'));
        $this->assertSame(['کیف'], \ai_data_query_terms('کیف 12'), 'two-digit numbers identify nothing');
    }

    public function testScoringPrefersTheKeyThenWholeWords(): void
    {
        $text = \ai_data_normalize('4512 989121234567 ارسال شده');
        $this->assertSame(20, \ai_data_score($text, ['4512'], '4512'), 'exact key, a number: 10 x 2');
        $this->assertSame(6, \ai_data_score($text, ['4512'], null), 'whole word, a number: 3 x 2');
        $this->assertSame(1, \ai_data_score($text, ['ارس'], null), 'substring');
        $this->assertSame(0, \ai_data_score($text, ['7777'], '4512'));
    }

    /* ---------------- tables & rows ---------------- */

    public function testColumnsFromTheForm(): void
    {
        [$cols, $error] = \ai_data_columns_from_form([
            ['key' => 'c3', 'label' => 'کد', 'type' => 'text'], ['label' => ' قیمت ', 'type' => 'number', 'default' => '0'],
            ['label' => ''], ['label' => 'وضعیت', 'type' => 'bogus'],
        ]);
        $this->assertNull($error);
        $this->assertSame(['c3', 'c4', 'c5'], array_column($cols, 'key'), 'existing key kept, new ones numbered after it');
        $this->assertSame(['number', 'text'], [$cols[1]['type'], $cols[2]['type']]);
        $this->assertSame('قیمت', $cols[1]['label']);
        $this->assertNotNull(\ai_data_columns_from_form([['label' => 'کد'], ['label' => 'كد']])[1], 'duplicate after normalization');
        $this->assertNotNull(\ai_data_columns_from_form([])[1]);
    }

    public function testRowBuildFillsDefaultsAndNormalizesKeyPhoneAndNumbers(): void
    {
        $row = \ai_data_row_build($this->dataset(), ['c1' => ' ۴۵۱۲ ', 'c2' => '۰۹۱۲۱۲۳۴۵۶۷', 'c4' => '۱٬۲۵۰٬۰۰۰']);
        $this->assertSame('در حال پردازش', $row['data']['c3'], 'empty cell takes the column default');
        $this->assertSame('1250000', $row['data']['c4']);
        $this->assertSame('4512', $row['row_key']);
        $this->assertSame('989121234567', $row['phone']);
        $this->assertStringContainsString('4512', $row['search_text']);
    }

    public function testHeadersMapToColumnsByLabel(): void
    {
        $this->assertSame([0 => 'c3', 2 => 'c1'], \ai_data_map_headers($this->dataset(), ['وضعيت', 'ستون ناشناخته', 'كد سفارش']));
    }

    public function testResultsAreFormattedPerTableWithoutEmptyCells(): void
    {
        $text = \ai_data_format_results([
            ['dataset' => $this->dataset(), 'data' => ['c1' => '4512', 'c2' => '989121234567', 'c3' => 'ارسال شده', 'c4' => ''], 'score' => 20],
        ]);
        $this->assertSame("### سفارش‌ها (وضعیت سفارش‌ها)\n- کد سفارش: 4512 | موبایل مشتری: 989121234567 | وضعیت: ارسال شده", $text);
        $this->assertSame('', \ai_data_format_results([]));
    }

    public function testTheLookupTool(): void
    {
        $tool = \ai_data_tool([$this->dataset(), $this->dataset(['id' => 2, 'name' => 'محصولات', 'phone_column' => null])]);
        $this->assertSame('lookup_records', $tool['name']);
        $this->assertSame(['سفارش‌ها', 'محصولات'], $tool['parameters']['properties']['table']['enum']);
        $this->assertStringContainsString('only the customer\'s own rows', $tool['description']);
    }

    public function testEveryTemplateIsConsistent(): void
    {
        foreach (\AI_DATA_TEMPLATES as $key => [$label, $name, , $columns, $keyLabel, $phoneLabel]) {
            $labels = array_column($columns, 0);
            $this->assertNotSame('', $name, $key);
            if ($keyLabel !== null) $this->assertContains($keyLabel, $labels, $key);
            if ($phoneLabel !== null) $this->assertContains($phoneLabel, $labels, $key);
        }
    }

    /* ---------------- secretary prompt & hand-off ---------------- */

    public function testHandoffMarkerIsFoundAndRemoved(): void
    {
        $this->assertSame(['همکاران ما تماس می‌گیرند.', true], \ai_secretary_parse_handoff('[HUMAN] همکاران ما تماس می‌گیرند.'));
        $this->assertSame(['سلام، همکاران تماس می‌گیرند.', true], \ai_secretary_parse_handoff('سلام، [human] همکاران تماس می‌گیرند.'));
        $this->assertSame(['سلام', false], \ai_secretary_parse_handoff('سلام'));
    }

    public function testRecordsTravelWithTheMessageAndTheSystemPromptStaysStable(): void
    {
        $assistant = \ai_assistant_defaults('5000') + ['business_name' => 'فروشگاه'];
        $ctx = ['tables' => [$this->dataset()], 'tools' => false, 'handoff' => true, 'records' => '- کد سفارش: 4512'];
        $a = \ai_secretary_build_messages($assistant, [], 'سفارش ۴۵۱۲؟', $ctx);
        $b = \ai_secretary_build_messages($assistant, [], 'سفارش دیگر؟', ['records' => ''] + $ctx);
        $this->assertSame($a[0]['content'], $b[0]['content'], 'system prompt identical across messages (cacheable)');
        $this->assertStringContainsString('[HUMAN]', $a[0]['content']);
        $this->assertStringContainsString('«سفارش‌ها»', $a[0]['content']);
        $this->assertSame("Records found:\n- کد سفارش: 4512\n\nCustomer message:\nسفارش ۴۵۱۲؟", end($a)['content']);
        $this->assertStringContainsString("Records found:\n(none)", end($b)['content']);

        $withManual = \ai_secretary_build_messages($assistant, [['content' => '', 'reply_text' => 'پاسخ اپراتور']], 'ممنون');
        $this->assertSame(['system', 'assistant', 'user'], array_column($withManual, 'role'), 'an operator reply has no customer turn');
    }

    /* ---------------- tool calls on the wire ---------------- */

    private function cfg(string $type): array
    {
        return ['type' => $type, 'base_url' => 'http://x/v1', 'auth_scheme' => 'none', 'api_key' => null, 'extra_headers' => []];
    }

    private function toolConversation(): array
    {
        return [
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'سفارش 4512'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'name' => 'lookup_records', 'arguments' => ['table' => 'سفارش‌ها', 'query' => '4512']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'lookup_records', 'content' => '- کد سفارش: 4512'],
        ];
    }

    public function testOpenAiToolRequest(): void
    {
        $tool = \ai_data_tool([$this->dataset()]);
        $body = json_decode(\ai_build_chat_request($this->cfg('openai_compatible'), 'm', $this->toolConversation(), 100, null, [$tool])['body'], true);
        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('lookup_records', $body['tools'][0]['function']['name']);
        $this->assertSame('{"table":"سفارش‌ها","query":"4512"}', $body['messages'][2]['tool_calls'][0]['function']['arguments']);
        $this->assertSame(['role' => 'tool', 'content' => '- کد سفارش: 4512', 'tool_call_id' => 'call_1'], $body['messages'][3]);
    }

    public function testOllamaToolRequest(): void
    {
        $body = json_decode(\ai_build_chat_request($this->cfg('ollama'), 'm', $this->toolConversation(), 100, null, [\ai_data_tool([$this->dataset()])])['body'], true);
        $this->assertSame(['table' => 'سفارش‌ها', 'query' => '4512'], $body['messages'][2]['tool_calls'][0]['function']['arguments']);
        $this->assertSame('lookup_records', $body['messages'][3]['tool_name']);
    }

    public function testAnthropicToolRequestAndPromptCaching(): void
    {
        $messages = $this->toolConversation();
        $messages[0]['content'] = str_repeat('اطلاعات کسب‌وکار ', 400);
        $body = json_decode(\ai_build_chat_request($this->cfg('anthropic'), 'm', $messages, 100, null, [\ai_data_tool([$this->dataset()])])['body'], true);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type'], 'a long system prompt is cacheable');
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '- کد سفارش: 4512'], $body['messages'][2]['content'][0]);
        $short = json_decode(\ai_build_chat_request($this->cfg('anthropic'), 'm', [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => 'q']], 100)['body'], true);
        $this->assertSame('S', $short['system'], 'a short system prompt stays a string');
        $this->assertSame('q', $short['messages'][0]['content']);
    }

    public function testToolCallsAreParsedForEveryProtocol(): void
    {
        $openai = \ai_parse_chat('openai_compatible', ['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c9', 'type' => 'function',
            'function' => ['name' => 'lookup_records', 'arguments' => '{"table":"t","query":"4512"}']]]], 'finish_reason' => 'tool_calls']]]);
        $this->assertSame([['id' => 'c9', 'name' => 'lookup_records', 'arguments' => ['table' => 't', 'query' => '4512']]], $openai['tool_calls']);
        $this->assertSame('', $openai['text'], 'a tool call needs no text');

        $ollama = \ai_parse_chat('ollama', ['message' => ['content' => '', 'tool_calls' => [['function' => ['name' => 'lookup_records', 'arguments' => ['query' => 'x']]]]]]);
        $this->assertSame('call_0', $ollama['tool_calls'][0]['id']);

        $anthropic = \ai_parse_chat('anthropic', ['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'lookup_records', 'input' => ['query' => 'x']]],
            'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 90, 'output_tokens' => 5]]);
        $this->assertSame('tu_1', $anthropic['tool_calls'][0]['id']);
        $this->assertSame(100, $anthropic['input_tokens'], 'cached prompt tokens are counted as input');
    }
}
