<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/ApiMessageIds.php';
require_once __DIR__ . '/../../app/Api/Handlers/DeliveryState.php';

/** app/ApiMessageIds.php — the pure parts: the public id's shape, which id a row shows, the attempt capture. */
final class ApiMessageIdsTest extends TestCase
{
    public function testANewPublicIdIsSixteenDigitsAndFitsADouble(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = \api_message_new_public_id();
            $this->assertMatchesRegularExpression('/^[1-8]\d{15}$/', $id);
            $this->assertTrue(\api_message_is_public_id($id));
            $this->assertLessThan(2 ** 53, (int)$id, 'a client holding the id in a double keeps every digit');
        }
        $this->assertNotSame(\api_message_new_public_id(), \api_message_new_public_id());
    }

    public function testARowIdIsNeverTakenForAPublicId(): void
    {
        foreach (['1', '482', '999999999999999', '0000000000000482', '9000000000000000', '4026740600623808506', '', 'abc', '12345678901234ab'] as $id) {
            $this->assertFalse(\api_message_is_public_id($id), $id);
        }
        $this->assertTrue(\api_message_is_public_id('1000000000000000'));
        $this->assertTrue(\api_message_is_public_id('8999999999999999'));
    }

    public function testARowShowsItsPublicIdAndFallsBackToItsRowId(): void
    {
        $this->assertSame('4821730095512264', \api_message_public_id_of(['id' => 7, 'public_id' => '4821730095512264']));
        $this->assertSame('7', \api_message_public_id_of(['id' => 7, 'public_id' => null]));
        $this->assertSame('7', \api_message_public_id_of(['id' => '7'])); // before the migration: no column at all
    }

    public function testAttemptIdsAreCollectedOnlyWhileCapturing(): void
    {
        \api_message_attempt_captured(11); // nothing is capturing: ignored
        $this->assertSame([], \api_message_attempts_capture_end());

        \api_message_attempts_capture_begin();
        \api_message_attempt_captured(21);
        \api_message_attempt_captured(22);
        \api_message_attempt_captured(21); // the same row twice
        \api_message_attempt_captured(0);  // a failed insert's lastInsertId()
        $this->assertSame([21, 22], \api_message_attempts_capture_end());

        \api_message_attempt_captured(31); // ended: ignored again
        \api_message_attempts_capture_begin();
        $this->assertSame([], \api_message_attempts_capture_end());
    }

    public function testStateOptionsComeFromTheQueryStringOrAPostBody(): void
    {
        $this->assertSame([], \api_state_options(['query' => [], 'body' => []]));
        $this->assertSame(['limit' => '2', 'state' => 'delivered'], \api_state_options(['query' => ['limit' => '2', 'state' => 'delivered'], 'body' => []]));
        // POST: the same keys in the JSON body; numbers become strings like a query value.
        $this->assertSame(['limit' => '2', 'after' => '120002'], \api_state_options(['query' => [], 'body' => ['limit' => 2, 'after' => '120002']]));
        // The query string wins; non-scalars and other keys are dropped.
        $this->assertSame(['limit' => '5'], \api_state_options(['query' => ['limit' => '5'], 'body' => ['limit' => 9, 'state' => ['x'], 'other' => 1]]));
    }
}
