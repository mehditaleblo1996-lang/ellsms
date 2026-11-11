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

    public function testAPanelReferenceIsRecognizedInTheStateId(): void
    {
        $this->assertSame(['attempt', 10321], \api_state_parse_panel_ref('D10321'));
        $this->assertSame(['attempt', 10321], \api_state_parse_panel_ref('d10321'));
        $this->assertSame(['bulk', 12], \api_state_parse_panel_ref('B12'));
        // Not references: API ids, the legacy L…, zero, signs, spaces, anything trailing.
        foreach (['10321', '4821730095512264', 'L5', 'D0', 'D', 'D-1', 'D 12', 'D12x', "D12\n", 'DB12', 'D1234567890123456789', ''] as $id) {
            $this->assertNull(\api_state_parse_panel_ref($id), json_encode($id));
        }
    }

    public function testAPanelReferenceBodyHasTheMessageStateShape(): void
    {
        $body = \api_panel_ref_state_body('D34', 'sent', '989121234567', 'delivered',
            ['message_id' => '222092113875936', 'delivered_at' => '2026-11-10 11:00:12', 'delivery_checked_at' => '2026-11-10 11:01:00'], '2026-11-10 11:00:05');
        $this->assertSame('D34', $body['id']);
        $this->assertSame('delivered', $body['state']);
        $this->assertTrue($body['final']);
        $this->assertSame(1, $body['summary']['delivered']);
        $this->assertSame(1, array_sum($body['summary']));
        $this->assertSame([['id' => '222092113875936', 'destination' => '989121234567', 'state' => 'delivered', 'delivered_at' => '2026-11-10 11:00:12', 'checked_at' => '2026-11-10 11:01:00']], $body['recipients']);
        // A recipient without a transport row has no message id.
        $this->assertNull(\api_recipient_body('989121234567', 'not_available', null)['id']);
        $this->assertFalse(\api_panel_ref_state_body('B7', 'processing', '989121234567', 'queued', [], null)['final']);
    }

    public function testAMessageIdIsFifteenDigitsAndTurnsBackIntoItsRow(): void
    {
        $key = random_bytes(32);
        $seen = [];
        foreach (['attempt', 'bulk', 'legacy'] as $source) {
            foreach ([1, 2, 3, 10321, 123456789, \MESSAGE_ID_MAX_ROW] as $rowId) {
                $id = \message_public_id_encode($source, $rowId, $key);
                $this->assertMatchesRegularExpression('/^[1-3]\d{14}$/', (string)$id, "{$source} {$rowId}");
                $this->assertTrue(\message_public_id_shape($id));
                $this->assertLessThan(2 ** 53, (int)$id);
                $this->assertSame([$source, $rowId], \message_public_id_decode_with($id, $key));
                $this->assertArrayNotHasKey($id, $seen, 'two rows never share an id');
                $seen[$id] = true;
                // Never mistaken for an API send id (16 digits) or a panel reference.
                $this->assertFalse(\api_message_is_public_id($id));
                $this->assertNull(\api_state_parse_panel_ref($id));
            }
        }
        // The same row under the same key is always the same id; under another key, another one.
        $this->assertSame(\message_public_id_encode('attempt', 10321, $key), \message_public_id_encode('attempt', 10321, $key));
        $this->assertNotSame(\message_public_id_encode('attempt', 10321, $key), \message_public_id_encode('attempt', 10321, random_bytes(32)));
        // Neighbouring rows are not neighbouring ids.
        $a = (int)\message_public_id_encode('attempt', 10321, $key);
        $b = (int)\message_public_id_encode('attempt', 10322, $key);
        $this->assertGreaterThan(1000, abs($a - $b));
    }

    public function testThePermutationIsABijection(): void
    {
        $key = random_bytes(32);
        $out = [];
        for ($v = 0; $v < 5000; $v++) {
            $p = \message_id_permute($v, $key);
            $this->assertGreaterThanOrEqual(0, $p);
            $this->assertLessThan(\MESSAGE_ID_DOMAIN, $p);
            $this->assertSame($v, \message_id_permute($p, $key, true));
            $out[$p] = true;
        }
        $this->assertCount(5000, $out);
        $top = \MESSAGE_ID_DOMAIN - 1;
        $this->assertSame($top, \message_id_permute(\message_id_permute($top, $key), $key, true));
    }

    public function testWhatCannotBeAMessageIdIsNotOne(): void
    {
        $key = random_bytes(32);
        $this->assertNull(\message_public_id_encode('attempt', 0, $key));
        $this->assertNull(\message_public_id_encode('attempt', -5, $key));
        $this->assertNull(\message_public_id_encode('attempt', \MESSAGE_ID_MAX_ROW + 1, $key), 'falls back to the old reference');
        $this->assertNull(\message_public_id_encode('otp', 5, $key));
        foreach (['', '482', 'D10321', '4821730095512264', '4026740600623808506', '099999999999999', '381474976710656', '12345678901234x', ' 222092113875936'] as $id) {
            $this->assertFalse(\message_public_id_shape($id), json_encode($id));
            $this->assertNull(\message_public_id_decode_with($id, $key));
        }
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
