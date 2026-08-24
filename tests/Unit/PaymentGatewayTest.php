<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FIN-2/FIN-3 — the generic payment gateway dispatcher and the fake/sandbox gateway. Pure
 * function-level coverage (no real network, no database) — the full claim/fulfill pipeline exercised
 * end to end against a real database is tests/Integration/FakePaymentGatewayE2eTest.php (FIN-4/FIN-6).
 */
final class PaymentGatewayTest extends TestCase
{
    protected function tearDown(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        putenv('PAYMENT_DEFAULT_GATEWAY');
    }

    // ------------------------------------------------------------------ default-off safety

    public function testFakeGatewayIsDisabledByDefault(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        self::assertFalse(payment_fake_gateway_enabled());
    }

    public function testGatewayNameFallsBackToZarinpalWhenFakeIsRequestedButNotEnabled(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        putenv('PAYMENT_DEFAULT_GATEWAY=fake');
        self::assertSame('zarinpal', payment_gateway_name(), 'setting PAYMENT_DEFAULT_GATEWAY=fake alone must never select the fake gateway in a misconfigured deployment');
    }

    public function testGatewayNameSelectsFakeOnlyWhenExplicitlyEnabled(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        putenv('PAYMENT_DEFAULT_GATEWAY=fake');
        self::assertSame('fake', payment_gateway_name());
    }

    public function testFakeGatewayCreateRefusesWhenDisabledEvenIfCalledDirectly(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        $result = payment_fake_gateway_create(100000, 1, 'test', '');
        self::assertFalse($result['ok'], 'defense in depth: the adapter itself must refuse, not just the dispatcher');
        self::assertNull($result['authority']);
    }

    public function testFakeGatewayVerifyRefusesWhenDisabledEvenIfCalledDirectly(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        $result = payment_fake_gateway_verify(100000, 'FAKE-SUCCESS-abc123');
        self::assertFalse($result['ok']);
    }

    // ------------------------------------------------------------------ modes

    public function testSuccessModeCreatesAndVerifiesCleanly(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', '');
        self::assertTrue($create['ok']);
        self::assertStringStartsWith('FAKE-SUCCESS-', $create['authority']);

        $verify = payment_fake_gateway_verify(100000, $create['authority']);
        self::assertTrue($verify['ok']);
        self::assertNotNull($verify['ref_id']);
        self::assertSame(100000, $verify['verified_amount_rial']);
    }

    public function testFailedModeRefusesAtCreation(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:FAILED');
        self::assertFalse($create['ok']);
        self::assertNull($create['authority']);
    }

    public function testTimeoutModeRefusesAtCreation(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:TIMEOUT');
        self::assertFalse($create['ok']);
    }

    public function testCancelledModeSucceedsAtCreationButRedirectUrlSignalsNok(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:CANCELLED');
        self::assertTrue($create['ok'], 'a real gateway also issues an authority for a payment the customer later cancels');

        $url = payment_fake_gateway_redirect_url($create['authority']);
        self::assertStringContainsString('Status=NOK', $url);
    }

    public function testVerifyFailureModeFailsAtVerification(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:VERIFY_FAILURE');
        self::assertTrue($create['ok'], 'creation succeeds; only verification fails');

        $verify = payment_fake_gateway_verify(100000, $create['authority']);
        self::assertFalse($verify['ok']);
        self::assertNull($verify['ref_id']);
    }

    public function testAmountMismatchModeReportsADifferentVerifiedAmount(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:AMOUNT_MISMATCH');
        self::assertTrue($create['ok']);

        $verify = payment_fake_gateway_verify(100000, $create['authority']);
        self::assertTrue($verify['ok'], 'the PROVIDER confirms something — verify() itself succeeds; the mismatch is in the AMOUNT, which the caller must check');
        self::assertNotSame(100000, $verify['verified_amount_rial']);
    }

    public function testDuplicateCallbackModeVerifiesRepeatedlyWithoutError(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_fake_gateway_create(100000, 1, 'test', 'test:DUPLICATE_CALLBACK');
        self::assertTrue($create['ok']);

        for ($i = 0; $i < 10; $i++) {
            $verify = payment_fake_gateway_verify(100000, $create['authority']);
            self::assertTrue($verify['ok'], "verify() call #{$i} must succeed — a real gateway's verify endpoint is idempotently callable; the duplicate-prevention guarantee lives in the CALLER's claim transaction, not here");
        }
    }

    // ------------------------------------------------------------------ malformed input

    public function testVerifyRejectsAnAuthorityThatIsNotAFakeOne(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $verify = payment_fake_gateway_verify(100000, 'some-real-looking-authority-string');
        self::assertFalse($verify['ok']);
    }

    public function testCreateRejectsZeroOrNegativeAmount(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        self::assertFalse(payment_fake_gateway_create(0, 1, 'test', '')['ok']);
        self::assertFalse(payment_fake_gateway_create(-100, 1, 'test', '')['ok']);
    }

    // ------------------------------------------------------------------ dispatcher

    public function testGatewaySupportsRefundFlags(): void {
        self::assertFalse(payment_gateway_supports_refund('zarinpal'));
        self::assertTrue(payment_gateway_supports_refund('fake'));
    }

    public function testDispatcherRoutesFakeGatewayCallsToTheFakeAdapter(): void {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        $create = payment_gateway_create('fake', 100000, 1, 'test', '');
        self::assertTrue($create['ok']);
        self::assertStringStartsWith('FAKE-', $create['authority']);
    }
}
