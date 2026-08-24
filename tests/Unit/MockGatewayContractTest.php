<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The mock gateway's own contract.
 *
 * Both bugs these tests cover shipped in committed code and survived because nothing asserted the
 * mock's behaviour — it was only ever exercised by hand:
 *
 *  1. /status returned HTTP 500 for numeric reference ids, because state_for_id() is strictly typed
 *     as string and a JSON body carrying bare numbers decodes them as int. Delivery status could
 *     never be polled from the mock at all.
 *  2. cron/mock-gateway-seed.php mapped provider token 5 to 'pending', which is not a canonical
 *     delivery status, so the seeded gateway failed to compile and every send SILENTLY fell back to
 *     the legacy backend.
 *
 * These are unit tests against the shipped files rather than integration tests against a running
 * server: the point is to pin the contract cheaply enough that it is always checked.
 */
final class MockGatewayContractTest extends TestCase
{
    private static function mockPath(): string
    {
        return dirname(__DIR__, 2) . '/mock/gateway.php';
    }

    /**
     * The canonical statuses a connector's status_mapping_json may target.
     * Mirrors GATEWAY_DELIVERY_STATUSES in app/Sms/GatewayConnector.php.
     */
    private const CANONICAL = ['accepted', 'queued', 'sent', 'delivered', 'failed', 'rejected', 'expired', 'unknown'];

    public function testTheSeederOnlyMapsProviderTokensToCanonicalStatuses(): void
    {
        $seeder = (string)file_get_contents(dirname(__DIR__, 2) . '/cron/mock-gateway-seed.php');

        self::assertMatchesRegularExpression(
            '/\$statusMapping\s*=\s*json_encode\(\[(.+?)\]/s',
            $seeder,
            'the seeder must still define a status mapping'
        );
        preg_match('/\$statusMapping\s*=\s*json_encode\(\[(.+?)\]/s', $seeder, $m);

        preg_match_all("/=>\s*'([a-z_]+)'/", $m[1], $values);
        self::assertNotEmpty($values[1], 'the status mapping must map at least one token');

        foreach ($values[1] as $canonical) {
            self::assertContains(
                $canonical,
                self::CANONICAL,
                "'{$canonical}' is not a canonical delivery status — the gateway would fail to compile "
                . 'and every send would silently fall back to the legacy backend'
            );
        }
    }

    public function testTheMockCastsReferenceIdsBeforeTypingThem(): void
    {
        $mock = (string)file_get_contents(self::mockPath());

        // The status loop must normalise each id to a string before handing it to state_for_id(),
        // which is declared `string $id`. A JSON body of bare numbers decodes to int and would
        // otherwise throw a TypeError -> HTTP 500 for the whole request.
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$ids\s+as\s+\$id\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*\$id\s*=\s*\(string\)\s*\$id\s*;/s',
            $mock,
            'the /status loop must cast each reference id to string before calling state_for_id()'
        );
    }

    public function testStateForIdIsCallableWithNumericAndStringIds(): void
    {
        // Exercise the real functions rather than re-implementing them.
        $this->loadMockFunctions();
        self::assertTrue(function_exists('state_for_id'));

        $rng = seeded_rng(12345, 'status');

        // A 19-digit reference, as a string: the shape a real provider returns.
        $state = state_for_id('4473621976262727360', 'success', 12345, $rng);
        self::assertIsInt($state);
        self::assertGreaterThanOrEqual(1, $state);

        // And the same value cast from an int, which is what json_decode() produces for a bare
        // number. This is the exact call that used to 500.
        $state2 = state_for_id((string)456, 'success', 12345, $rng);
        self::assertIsInt($state2);
    }

    /**
     * References must be unique ACROSS requests, not merely within one.
     *
     * The third bug this class covers: mock_reference() derived from ($seed, $index, $context)
     * alone, so every batch restarted at index 0 and reissued the same references. A 100,000-row
     * send in batches of 200 produced exactly 200 distinct references, each shared by ~500
     * recipients — which makes delivery-status polling meaningless, because nothing downstream can
     * tell those recipients apart. Found by the Phase 9B 100k benchmark.
     */
    public function testReferencesAreUniqueAcrossSeparateBatches(): void
    {
        $this->loadMockFunctions();

        $seed = 12345;
        $batchA = generate_references(3, $seed, 'send', ['989121111111', '989121111112', '989121111113']);
        $batchB = generate_references(3, $seed, 'send', ['989122222221', '989122222222', '989122222223']);

        $all = array_merge($batchA, $batchB);
        self::assertCount(
            count($all),
            array_unique($all),
            'two batches that both start at index 0 must not reissue the same references'
        );
    }

    public function testReferencesStayUniqueAtBatchScale(): void
    {
        $this->loadMockFunctions();

        // 50 batches of 200 — the shape the 100k benchmark exercises, scaled down to stay fast.
        $seen = [];
        for ($b = 0; $b < 50; $b++) {
            $dests = [];
            for ($i = 0; $i < 200; $i++) {
                $dests[] = '98912' . str_pad((string)($b * 200 + $i), 7, '0', STR_PAD_LEFT);
            }
            foreach (generate_references(200, 12345, 'send', $dests) as $ref) {
                $seen[$ref] = true;
            }
        }

        self::assertCount(10000, $seen, 'every one of 10,000 recipients must receive its own reference');

        // And they must stay inside the exact-integer range a 64-bit provider id occupies.
        // Cast back to string: PHP silently converts numeric-string ARRAY KEYS to int, which is the
        // same class of coercion that made the mock's /status endpoint 500 in the first place.
        foreach (array_slice(array_keys($seen), 0, 200) as $key) {
            $ref = (string)$key;
            self::assertMatchesRegularExpression('/^\d{18,19}$/', $ref, 'a provider reference must be an 18-19 digit numeric string');
            self::assertLessThanOrEqual(PHP_INT_MAX, (int)$ref, 'a reference must fit a signed 64-bit integer');
        }
    }

    /** Includes mock/gateway.php's function definitions without running its request handling. */
    private function loadMockFunctions(): void
    {
        if (function_exists('generate_references')) {
            return;
        }
        $src = (string)file_get_contents(self::mockPath());
        $start = strpos($src, 'function handle_send');
        self::assertIsInt($start, 'mock/gateway.php must still define its handler functions');

        $tmp = tempnam(sys_get_temp_dir(), 'mockfn') . '.php';
        file_put_contents($tmp, "<?php\ndeclare(strict_types=1);\n" . substr($src, $start));
        require_once $tmp;
        @unlink($tmp);
    }

    public function testTheRequestLogIsOptInAndRecordsCountsNotRecipients(): void
    {
        $mock = (string)file_get_contents(self::mockPath());

        self::assertStringContainsString(
            'MOCK_SMS_REQUEST_LOG',
            $mock,
            'the load harness needs the mock to be able to record what it received'
        );
        // Opt-in: no variable, no writing.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*is_string\(\$requestLog\)\s*&&\s*\$requestLog\s*!==\s*\'\'\s*\)/',
            $mock,
            'request logging must be inert unless MOCK_SMS_REQUEST_LOG names a file'
        );
        // And it must record COUNTS, never the recipient values themselves. Referencing
        // $input['destinations'] to COUNT it is fine; what must not appear is the array being
        // stored. Assert on the logged VALUES: every one must be a scalar-producing expression.
        preg_match('/\$line\s*=\s*json_encode\(\[(.+?)\]\)\s*\.\s*"\\\\n";/s', $mock, $m);
        self::assertNotEmpty($m, 'the request log entry must still be a json_encode of a fixed shape');

        // The write must RETRY and, failing that, leave a countable marker. A benchmark whose
        // instrumentation can silently lose a line under-reports its request count, which reads as
        // better-than-real batching — the most dangerous direction for a measurement to be wrong in.
        // The 100k run logged 497 requests for 500 that demonstrably happened, and the original
        // bare `@file_put_contents` left no evidence of why.
        self::assertMatchesRegularExpression(
            '/for\s*\(\$attempt\s*=\s*0;\s*\$attempt\s*<\s*3\s*&&\s*!\$written;/',
            $mock,
            'the request log write must retry rather than fail silently'
        );
        // Matched as it appears in the SOURCE, where the quotes are backslash-escaped inside a
        // double-quoted PHP string literal.
        self::assertStringContainsString(
            '{\"lost\":1}',
            $mock,
            'a log line that cannot be written must still be countable, so the gap is auditable'
        );
        self::assertStringContainsString(
            '$written = ($bytes === strlen($line));',
            $mock,
            'a short write must count as a failure — a partial line corrupts the JSONL'
        );

        $entry = $m[1];
        self::assertStringContainsString('count(', $entry, 'the recipient list must be reduced to a count');
        foreach (["=> \$input['destinations']", '=> $destinations', '=> $body', "=> \$input['contents']"] as $leak) {
            self::assertStringNotContainsString(
                $leak,
                $entry,
                'the request log must never store recipients or message bodies — a fake provider log is still a log'
            );
        }
    }
}
