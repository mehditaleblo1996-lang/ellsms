<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** #36 — numeric idempotency ids are all-or-nothing, and duplicate answers match exactly. */
final class GatewayIdempotencyIdsTest extends TestCase
{
    public function testKeysBecomeTheirNumericSuffixInOrder(): void
    {
        self::assertSame(['12', '7', '9000000001'], gateway_idempotency_ids(['ellsms:bulk_item:12', 'ellsms:bulk_item:7', 'ellsms:bulk_item:9000000001']));
    }

    public function testOneKeyWithoutANumberEmptiesTheWholeList(): void
    {
        // A provider like Vesal rejects the WHOLE request when the list is shorter than the recipients.
        self::assertSame([], gateway_idempotency_ids(['ellsms:bulk_item:12', '']));
        self::assertSame([], gateway_idempotency_ids(['ellsms:bulk_item:12', 'opaque-token']));
        self::assertSame([], gateway_idempotency_ids(['ellsms:bulk_item:0']));
        self::assertSame([], gateway_idempotency_ids([]));
    }

    public function testTheContextCarriesTheIdsAlignedWithRecipients(): void
    {
        $ctx = gateway_send_context([
            'recipients' => ['989120000002', '989120000001'],
            'idempotency_keys' => ['989120000001' => 'ellsms:bulk_item:5', '989120000002' => 'ellsms:bulk_item:6'],
        ]);
        self::assertSame(['6', '5'], $ctx['idempotency_ids_array']);
        self::assertSame([], gateway_send_context(['recipients' => ['989120000001']])['idempotency_ids_array'], 'a direct send has no ids');
    }

    public function testDuplicateAnswersMatchOnlyConfiguredValues(): void
    {
        self::assertTrue(gateway_is_duplicate_answer(-453, ['-453']));
        self::assertTrue(gateway_is_duplicate_answer(' -453 ', ['-453']));
        self::assertFalse(gateway_is_duplicate_answer(-5, ['-453']));
        self::assertFalse(gateway_is_duplicate_answer(-453, []), 'nothing is a duplicate unless configured');
        self::assertFalse(gateway_is_duplicate_answer(null, ['-453']));
    }

    public function testIdempotencyIdsArrayIsAnAllowedSendVariable(): void
    {
        self::assertContains('idempotency_ids_array', GATEWAY_SEND_VARIABLES);
    }
}
