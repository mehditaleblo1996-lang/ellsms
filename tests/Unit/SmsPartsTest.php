<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sms_parts() (app/bootstrap.php) — GSM-7 vs. Unicode segment counting.
 * This number is multiplied by destination count to compute credit cost
 * in dispatch_message() and bulk_queue_job() — a regression here directly
 * mischarges every user in the system, so it's a high-value, zero-side-effect
 * candidate for locking in with tests.
 */
final class SmsPartsTest extends TestCase
{
    public function testEmptyContentCostsNothing(): void
    {
        $this->assertSame(0, sms_parts(''));
    }

    public function testShortAsciiContentIsOnePart(): void
    {
        $this->assertSame(1, sms_parts('Hello there'));
    }

    public function testAsciiContentAtTheGsm7SinglePartLimitIsOnePart(): void
    {
        $this->assertSame(1, sms_parts(str_repeat('a', 160)));
    }

    public function testAsciiContentOneCharacterOverTheLimitIsTwoParts(): void
    {
        $this->assertSame(2, sms_parts(str_repeat('a', 161)));
    }

    public function testAsciiContentSplitsAtOneHundredFiftyThreeCharsPerPartAfterTheFirst(): void
    {
        // 306 = 153*2 exactly -> still 2 parts
        $this->assertSame(2, sms_parts(str_repeat('a', 306)));
        // one more character must roll over into a third part
        $this->assertSame(3, sms_parts(str_repeat('a', 307)));
    }

    public function testShortPersianContentIsOnePart(): void
    {
        $this->assertSame(1, sms_parts('سلام دنیا'));
    }

    public function testPersianContentAtTheUnicodeSinglePartLimitIsOnePart(): void
    {
        $this->assertSame(1, sms_parts(str_repeat('س', 70)));
    }

    public function testPersianContentOneCharacterOverTheUnicodeLimitIsTwoParts(): void
    {
        $this->assertSame(2, sms_parts(str_repeat('س', 71)));
    }

    public function testAnySingleNonGsm7CharacterForcesUnicodeSegmentation(): void
    {
        // A message that's otherwise plain ASCII but contains one Persian
        // character must be costed using the 70/67-char Unicode rule, not
        // the 160/153-char GSM-7 rule — this is the actual business rule,
        // not just "Persian text is 1 part" from the tests above.
        $content = str_repeat('a', 68) . 'س';
        $this->assertSame(1, sms_parts($content)); // 69 chars, <=70
        $this->assertSame(2, sms_parts($content . 'aa')); // 71 chars, >70
    }
}
