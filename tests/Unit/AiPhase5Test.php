<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Phase 5 without a database: proxy URLs, document splitting and Word text, the «پیام اول» request, Telegram lines. */
final class AiPhase5Test extends TestCase
{
    public function testProxyUrls(): void
    {
        $this->assertNull(\ai_proxy_error('socks5://user:pass@relay.example:1080'));
        $this->assertNull(\ai_proxy_error('http://10.0.0.5:3128'));
        $this->assertNotNull(\ai_proxy_error('ftp://x'));
        $this->assertNotNull(\ai_proxy_error('relay.example:1080'));
        $this->assertSame('socks5h://u:p@h:1080', \ai_proxy_for_curl('socks5://u:p@h:1080'), 'the proxy resolves the provider\'s name');
        $this->assertSame('http://h:3128', \ai_proxy_for_curl('http://h:3128'));
    }

    public function testDocumentsAreSplitOnParagraphsSentencesThenWords(): void
    {
        $paragraph = str_repeat('جمله‌ای درباره‌ی شرایط فروش است. ', 40);
        $chunks = \ai_document_chunks("عنوان\n\nبند کوتاه اول.\n\n\n\n" . $paragraph . "\n\nبند آخر.", 300);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, mb_strlen($chunk));
            $this->assertNotSame('', trim($chunk));
        }
        $this->assertStringStartsWith("عنوان\nبند کوتاه اول.", $chunks[0], 'short paragraphs are packed together');
        $this->assertStringEndsWith('بند آخر.', end($chunks));
        $this->assertSame([], \ai_document_chunks("  \n\n "));
        $this->assertCount(4, \ai_document_chunks(str_repeat('ب', 1000), 300), 'a word longer than a piece is hard-cut');
    }

    public function testWordTextKeepsParagraphs(): void
    {
        $xml = '<w:document><w:body><w:p><w:r><w:t>سلام</w:t></w:r><w:r><w:tab/><w:t>دنیا &amp; ما</w:t></w:r></w:p><w:p><w:r><w:t>بند دوم</w:t></w:r></w:p></w:body></w:document>';
        $this->assertSame("سلام دنیا & ما\nبند دوم\n", \ai_document_docx_text($xml));
    }

    public function testOutreachRequestCarriesTheInstructionAndTheRow(): void
    {
        $assistant = ['business_name' => 'فروشگاه'] + \ai_assistant_defaults('5000');
        $dataset = ['id' => 1, 'name' => 'سفارش‌ها', 'description' => '', 'columns' => [['key' => 'c1', 'label' => 'کد سفارش'], ['key' => 'c2', 'label' => 'کد رهگیری']]];
        $messages = \ai_outreach_messages($assistant, $dataset, ['c1' => '4512', 'c2' => 'RT1'], ' خبر بده ارسال شد. ');
        $this->assertSame(['system', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('«فروشگاه»', $messages[0]['content']);
        $this->assertStringNotContainsString('[HUMAN]', $messages[0]['content'], 'nobody wrote yet: no hand-off');
        $this->assertStringContainsString("What the message should do:\nخبر بده ارسال شد.", $messages[1]['content']);
        $this->assertStringContainsString('کد سفارش: 4512 | کد رهگیری: RT1', $messages[1]['content']);
    }

    public function testTelegramLines(): void
    {
        $this->assertSame(12, \ai_tg_account_of('tg:12'));
        $this->assertNull(\ai_tg_account_of('5000123'));
        $this->assertNull(\ai_tg_account_of('tg:x'));
    }
}
