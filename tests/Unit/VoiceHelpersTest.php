<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the voice message service (app/VoiceMessages.php, app/Voice/ZarinCall.php):
 * pulse counting (it decides what a call costs), WAV duration, ZarinCall's number format and answer
 * parsing, and when a batch's counts are final enough to charge.
 */
final class VoiceHelpersTest extends TestCase
{
    public function testPulsesRoundUpAndAreNeverZero(): void
    {
        $this->assertSame(1, voice_pulses(0, 30));
        $this->assertSame(1, voice_pulses(1, 30));
        $this->assertSame(1, voice_pulses(30, 30));
        $this->assertSame(2, voice_pulses(31, 30));
        $this->assertSame(4, voice_pulses(100, 30));
        $this->assertSame(10, voice_pulses(100, 10));
    }

    public function testWavDurationIsReadFromTheHeader(): void
    {
        $rate = 16000;
        $data = str_repeat("\x00", 5 * $rate + 1);
        $wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, $rate, 2, 16)
            . 'LIST' . pack('V', 4) . 'INFO'
            . 'data' . pack('V', strlen($data)) . $data;
        $this->assertSame(6, voice_wav_duration($wav), 'chunks before data are skipped, partial seconds round up');
        $this->assertNull(voice_wav_duration('ID3 an mp3 file'));
        $this->assertNull(voice_wav_duration('RIFF' . str_repeat("\x00", 60)));
    }

    public function testNumbersAreSentInZarinCallsLocalForm(): void
    {
        $this->assertSame('09121234567', zarincall_number('09121234567'));
        $this->assertSame('09121234567', zarincall_number('+98 912 123 4567'));
        $this->assertSame('09121234567', zarincall_number('989121234567'));
        $this->assertSame('09121234567', zarincall_number('9121234567'));
        $this->assertNull(zarincall_number('02188776655'), 'landlines are not mobile numbers');
        $this->assertNull(zarincall_number('0912123'));
        $this->assertNull(zarincall_number('hello'));
    }

    public function testParsingDropsInvalidAndDuplicateNumbers(): void
    {
        $r = voice_parse_numbers(['09121234567', '۰۹۱۲۱۲۳۴۵۶۷', '+989351112233', 'x', 12, null]);
        $this->assertSame(['09121234567', '09351112233'], $r['numbers']);
        $this->assertSame(['989121234567', '989351112233'], $r['msisdns']);
        $this->assertSame(3, $r['invalid']);
    }

    public function testAnswerFieldsAreMatchedRegardlessOfCase(): void
    {
        $data = ['R_Success' => true, 'msgId' => 'abc', 'Data' => ['RecCount' => 3]];
        $this->assertSame('abc', zarincall_field($data, 'MsgID'));
        $this->assertTrue(zarincall_field($data, 'r_success'));
        $this->assertNull(zarincall_field($data, 'VoiceID'));
        $this->assertNull(zarincall_field(null, 'MsgID'));
        $this->assertSame('abc', zarincall_id(' abc '));
        $this->assertNull(zarincall_id(''));
        $this->assertNull(zarincall_id(['x']));
    }

    public function testSpeakersAreReadAsValueEqualsLabelLines(): void
    {
        $this->assertSame([1 => 'مرد', 2 => 'زن'], voice_tts_speakers_parse("1=مرد\r\n ۲ = زن \n"));
        $this->assertSame([7 => 'a=b'], voice_tts_speakers_parse("x=y\n7=a=b\n300=too big\n=empty"));
        $this->assertSame([], voice_tts_speakers_parse(''));
    }

    public function testBatchCountsAreFinalOnlyWhenEveryRecipientHasAnOutcome(): void
    {
        $this->assertTrue(voice_batch_counts_final(3, 2, 1));
        $this->assertFalse(voice_batch_counts_final(3, 1, 1));
        $this->assertFalse(voice_batch_counts_final(null, 0, 0));
        $this->assertFalse(voice_batch_counts_final(0, 0, 0), 'no count reported yet');
    }
}
