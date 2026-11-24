<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** lba_public_text() (app/Lba/PishgamSmartSms.php): customers never see the provider's name. */
final class LbaPublicTextTest extends TestCase
{
    public function testEverySpellingOfTheProviderIsReplaced(): void
    {
        foreach (['وضعیت در پیشگام رایان: ناموفق', 'پیشگام‌رایان', 'پیشگام', 'PishgamRayan', 'pishgam rayan', 'smsapi.pishgamrayan.com'] as $text) {
            $out = \lba_public_text($text);
            $this->assertStringNotContainsStringIgnoringCase('پیشگام', $out, $text);
            $this->assertStringNotContainsStringIgnoringCase('pishgam', $out, $text);
        }
        $this->assertSame('ناموفق. — خطا', \lba_public_text('ناموفق. — خطا'));
    }

    public function testProviderErrorsAreShownWithoutTheName(): void
    {
        $error = \lba_call_error(['error' => \lba_status_message(66), 'status_code' => 66, 'data' => ['message' => 'موجودی پیشگام رایان کافی نیست'], 'validation_errors' => []]);
        $this->assertStringNotContainsString('پیشگام', $error);
        foreach (\LBA_STATUS_MESSAGES as $message) $this->assertStringNotContainsString('پیشگام', $message);
        $this->assertStringNotContainsString('پیشگام', \lba_status_message(999));
    }
}
