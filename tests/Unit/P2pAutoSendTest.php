<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The support-group message of a P2P import waiting for confirmation (app/P2pAutoSend.php). */
final class P2pAutoSendTest extends TestCase
{
    private const JOB = [
        'id' => 42, 'user_id' => 7, 'title' => 'یادآوری قبض', 'original_filename' => 'list.xlsx',
        'originator' => '3000123', 'queued_rows' => 1500, 'estimated_cost_credits' => 2250000,
    ];

    public function testTheAlertNamesTheUserTheSendAndTheLink(): void
    {
        $text = p2p_ready_alert_text(self::JOB, 'ali', 'https://panel.example');
        self::assertStringStartsWith('⏳ ارسال نظیر به نظیر منتظر تأیید است', $text);
        self::assertStringContainsString('کاربر: ali', $text);
        self::assertStringContainsString('عنوان: یادآوری قبض', $text);
        self::assertStringContainsString('خط ارسال: 3000123', $text);
        self::assertStringContainsString('تعداد پیام: 1,500', $text);
        self::assertStringContainsString('هزینه‌ی تخمینی: 2,250,000 ریال', $text);
        self::assertStringContainsString('https://panel.example/import.php?id=42', $text);
        self::assertStringNotContainsString('علت:', $text);
    }

    public function testAFailedAutoSendSaysWhy(): void
    {
        $text = p2p_ready_alert_text(self::JOB, 'ali', '', 'موجودی کیف پول برای این ارسال کافی نیست.');
        self::assertStringStartsWith('⚠️ ارسال خودکار نظیر به نظیر انجام نشد', $text);
        self::assertStringContainsString('علت: موجودی کیف پول برای این ارسال کافی نیست.', $text);
        self::assertStringEndsWith('واردسازی #42', $text);
    }

    public function testFallsBackToTheFileNameAndTheUserId(): void
    {
        $text = p2p_ready_alert_text(['title' => ''] + self::JOB, '', '');
        self::assertStringContainsString('عنوان: list.xlsx', $text);
        self::assertStringContainsString('کاربر: #7', $text);
    }
}
