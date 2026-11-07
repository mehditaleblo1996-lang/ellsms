<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A message row's part count (app/MessageParts.php): the stored `parts` when the row has one, else
 * the count of its text — so a row the backfill has not reached yet still reads right.
 */
final class MessagePartsTest extends TestCase
{
    public function testTheStoredCountWins(): void
    {
        self::assertSame(4, message_parts_of_row(['parts' => '4', 'content' => 'hi']));
        self::assertSame(0, message_parts_of_row(['parts' => 0, 'content' => 'hi']), 'a stored 0 (no text, no snapshot) stays 0');
    }

    public function testARowWithoutOneIsCountedFromItsText(): void
    {
        self::assertSame(1, message_parts_of_row(['parts' => null, 'content' => 'hi']));
        self::assertSame(3, message_parts_of_row(['content' => str_repeat('س', 150)]));
        self::assertSame(2, message_parts_of_row(['content' => str_repeat('a', 161)]));
        self::assertSame(0, message_parts_of_row(['parts' => null, 'content' => null]));
    }
}
