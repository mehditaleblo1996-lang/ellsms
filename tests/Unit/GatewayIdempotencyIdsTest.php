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

    public function testRequestTagNamesTheBulkItemsOfTheRequestWithinFortyCharacters(): void
    {
        self::assertSame('ellsms:5-12', gateway_request_tag(['ellsms:bulk_item:12', 'ellsms:bulk_item:5', 'ellsms:bulk_item:9']));
        self::assertSame('ellsms:7', gateway_request_tag(['ellsms:bulk_item:7']));
        self::assertSame('ellsms', gateway_request_tag([]), 'a direct send still gets a non-empty tag');
        self::assertLessThanOrEqual(40, strlen(gateway_request_tag(['ellsms:bulk_item:999999999999999', 'ellsms:bulk_item:100000000000000'])),
            "PishgamRayan's userTag limit");
        $ctx = gateway_send_context([
            'recipients' => ['989120000002', '989120000001'],
            'idempotency_keys' => ['989120000001' => 'ellsms:bulk_item:5', '989120000002' => 'ellsms:bulk_item:6'],
        ]);
        self::assertSame('ellsms:5-6', $ctx['request_tag']);
        self::assertContains('request_tag', GATEWAY_SEND_VARIABLES);
    }

    public function testOnlyAFailureBeforeTheConnectionCountsAsNeverReachingTheProvider(): void
    {
        self::assertTrue(gateway_curl_error_never_reached_provider(CURLE_COULDNT_CONNECT));
        self::assertTrue(gateway_curl_error_never_reached_provider(CURLE_COULDNT_RESOLVE_HOST));
        self::assertFalse(gateway_curl_error_never_reached_provider(CURLE_OPERATION_TIMEDOUT), 'a timeout may have been processed');
        self::assertFalse(gateway_curl_error_never_reached_provider(CURLE_RECV_ERROR), 'a reset after sending may have been processed');
    }

    public function testIdempotencyIdsArrayIsAnAllowedSendVariable(): void
    {
        self::assertContains('idempotency_ids_array', GATEWAY_SEND_VARIABLES);
    }
}
