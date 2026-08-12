<?php

declare(strict_types=1);

namespace Tests\Unit;

use GatewayJsonNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The long-provider-id primitives: decimal validation and float-free JSON emission.
 *
 * Pure functions, so they belong here rather than in an integration test — and they deserve their own
 * class because the whole `integer_list` feature rests on one property that is easy to lose in a
 * refactor and invisible when lost: a 19-digit provider message id must never pass through a PHP
 * float. `7310136179845801812` becomes `7310136179845801800` if it does, and a status lookup for an
 * id that is off by three at the end simply returns nothing — which reads exactly like "the provider
 * has no record of this message" rather than like data corruption.
 */
final class GatewayNumericListTest extends TestCase
{
    /* ================= token validation ================= */

    public static function acceptedTokens(): array {
        return [
            '19-digit provider id' => ['7310136179845801812'],
            'shorter provider id'  => ['776846774851635393'],
            'zero'                 => ['0'],
            'int64 maximum'        => ['9223372036854775807'],
        ];
    }

    #[DataProvider('acceptedTokens')]
    public function testCanonicalDecimalsAreAcceptedVerbatim(string $token): void {
        $this->assertSame($token, gateway_decimal_token($token));
    }

    public static function rejectedTokens(): array {
        return [
            'negative'          => ['-5'],
            'decimal point'     => ['12.5'],
            'exponent'          => ['1e3'],
            'leading whitespace'=> [' 12'],
            'trailing whitespace' => ['12 '],
            'inner whitespace'  => ['12 34'],
            'leading zero'      => ['012'],
            'plus sign'         => ['+12'],
            'hex'               => ['0x1f'],
            'letters'           => ['abc'],
            'empty'             => [''],
            'separators'        => ['1,2'],
            // Wider than a signed 64-bit integer. Rejected rather than emitted: every JSON consumer
            // this could reach parses numbers as int64 at best, so emitting it would move the same
            // precision loss one hop away, where it is harder to see.
            'int64 maximum + 1' => ['9223372036854775808'],
            'far too wide'      => ['99999999999999999999999'],
        ];
    }

    #[DataProvider('rejectedTokens')]
    public function testMalformedTokensAreRejected(string $token): void {
        $this->assertNull(gateway_decimal_token($token));
    }

    public function testAFloatIsNeverAccepted(): void {
        // By the time a value is a float, the precision is already gone — accepting it would be
        // laundering the corruption this whole type exists to prevent.
        $this->assertNull(gateway_decimal_token(1.5));
        $this->assertNull(gateway_decimal_token(7310136179845801812.0));
    }

    public function testAnIntegerIsAcceptedAsItsExactDecimal(): void {
        $this->assertSame('7310136179845801812', gateway_decimal_token(7310136179845801812));
    }

    /* ================= list building ================= */

    public function testAListPreservesEveryDigitOfEveryId(): void {
        $list = gateway_integer_list('7310136179845801812,776846774851635393,3717114266477167711');

        $this->assertCount(3, $list);
        $this->assertContainsOnlyInstancesOf(GatewayJsonNumber::class, $list);
        $this->assertSame(
            ['7310136179845801812', '776846774851635393', '3717114266477167711'],
            array_map(static fn(GatewayJsonNumber $n): string => $n->decimal, $list)
        );
    }

    public function testAMalformedItemIsDroppedRatherThanCoerced(): void {
        // Turning "12.5" into 12 would ask the provider about a different message, which is worse
        // than asking about one fewer.
        $list = gateway_integer_list('7310136179845801812,12.5,776846774851635393');

        $this->assertCount(2, $list);
        $this->assertSame('7310136179845801812', $list[0]->decimal);
        $this->assertSame('776846774851635393', $list[1]->decimal);
    }

    public function testAnEmptyInputYieldsAnEmptyList(): void {
        $this->assertSame([], gateway_integer_list(''));
        $this->assertSame([], gateway_integer_list(',,'));
    }

    /* ================= JSON emission ================= */

    public function testIdsAreEmittedAsUnquotedJsonNumbers(): void {
        $json = gateway_json_encode_body([
            'username'     => 'u',
            'password'     => 'p',
            'referenceids' => gateway_integer_list('7310136179845801812,776846774851635393'),
        ]);

        $this->assertSame(
            '{"username":"u","password":"p","referenceids":[7310136179845801812,776846774851635393]}',
            $json
        );
    }

    public function testASingleIdIsStillAOneElementArray(): void {
        $json = gateway_json_encode_body(['referenceids' => gateway_integer_list('7310136179845801812')]);

        $this->assertSame('{"referenceids":[7310136179845801812]}', $json);
    }

    public function testTheCorruptedFloatFormNeverAppears(): void {
        $json = gateway_json_encode_body(['referenceids' => gateway_integer_list('7310136179845801812')]);

        // The exact value a float round trip produces. This assertion is what fails loudly if a cast
        // is ever reintroduced anywhere in the path.
        $this->assertStringNotContainsString('7310136179845801800', $json);
        $this->assertStringContainsString('7310136179845801812', $json);
    }

    public function testOrdinaryStringsAreStillQuoted(): void {
        // The placeholder trick must not become a general "numeric-looking strings become numbers"
        // rule — a phone number, a national id or an OTP with a leading zero would all be corrupted
        // by that, which is precisely why JSON_NUMERIC_CHECK was not used.
        $json = gateway_json_encode_body([
            'mobile' => '09121234567',
            'otp'    => '007',
            'ids'    => gateway_integer_list('12'),
        ]);

        $this->assertSame('{"mobile":"09121234567","otp":"007","ids":[12]}', $json);
    }

    public function testPersianContentIsStillUnescaped(): void {
        $json = gateway_json_encode_body(['text' => 'سلام', 'ids' => gateway_integer_list('12')]);

        $this->assertSame('{"text":"سلام","ids":[12]}', $json);
    }

    public function testABodyWithNoNumericListIsEncodedNormally(): void {
        // The common case must be byte-identical to plain json_encode(), or every existing gateway's
        // request would change shape.
        $body = ['sender_user_id' => 1, 'originator' => 5000, 'destinations' => ['989121234567'], 'content' => 'x'];

        $this->assertSame(json_encode($body, JSON_UNESCAPED_UNICODE), gateway_json_encode_body($body));
    }

    public function testANestedNumericListIsAlsoEmittedAsNumbers(): void {
        $json = gateway_json_encode_body(['filter' => ['ids' => gateway_integer_list('7310136179845801812')]]);

        $this->assertSame('{"filter":{"ids":[7310136179845801812]}}', $json);
    }

    /* ================= catalogs ================= */

    public function testTheStatusCatalogCarriesBothIdVariablesAndTheSendCatalogCarriesNeither(): void {
        $this->assertContains('provider_message_id', GATEWAY_STATUS_VARIABLES);
        $this->assertContains('provider_message_ids', GATEWAY_STATUS_VARIABLES);

        // Separate catalogs, deliberately: a send template cannot read an id that does not exist yet.
        $this->assertNotContains('provider_message_id', GATEWAY_SEND_VARIABLES);
        $this->assertNotContains('provider_message_ids', GATEWAY_SEND_VARIABLES);
    }

    public function testThePluralVariableIsNotConsideredPerMessage(): void {
        // It is the one variable that makes batching possible, so treating it as per-message would
        // disable batching entirely — a subtle way to "fix" nothing while appearing to.
        $this->assertNotContains('provider_message_ids', GATEWAY_PER_MESSAGE_STATUS_VARIABLES);
        $this->assertContains('provider_message_id', GATEWAY_PER_MESSAGE_STATUS_VARIABLES);
        $this->assertContains('recipient', GATEWAY_PER_MESSAGE_STATUS_VARIABLES);
    }

    public function testTheStatusContextPopulatesThePluralFormFromASingleId(): void {
        $context = gateway_status_context(['provider_message_id' => '7310136179845801812']);

        $this->assertSame('7310136179845801812', $context['provider_message_ids']);
        $this->assertSame('7310136179845801812', $context['provider_message_id']);
    }

    public function testTheStatusContextJoinsManyIdsLosslessly(): void {
        $context = gateway_status_context(['provider_message_ids' => ['7310136179845801812', '776846774851635393']]);

        // A comma can never appear inside a validated decimal id, so the join is reversible.
        $this->assertSame('7310136179845801812,776846774851635393', $context['provider_message_ids']);
        $this->assertSame('7310136179845801812', $context['provider_message_id'], 'the singular form names the first');
    }
}
