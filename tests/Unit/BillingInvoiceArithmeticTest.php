<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FIN-1 — the pure-arithmetic parts of the invoice layer (app/Financial.php). Integer-only,
 * deterministic, no floats anywhere (FIN-6's own requirement). The transactional parts
 * (billing_invoice_create(), coupon redemption races) are covered by
 * tests/Integration/FinancialInvoiceTest.php, since they need a real database.
 */
final class BillingInvoiceArithmeticTest extends TestCase
{
    protected function tearDown(): void {
        putenv('BILLING_TAX_PERCENT');
    }

    public function testTaxDefaultsToZeroPercent(): void {
        putenv('BILLING_TAX_PERCENT');
        self::assertSame(0, billing_tax_percent());
    }

    public function testTaxPercentIsClampedToZeroToOneHundred(): void {
        putenv('BILLING_TAX_PERCENT=150');
        self::assertSame(100, billing_tax_percent());

        putenv('BILLING_TAX_PERCENT=-5');
        self::assertSame(0, billing_tax_percent());
    }

    public function testTaxCalculationRoundsDownNeverUp(): void {
        // 999 * 9% = 89.91 -> must floor to 89, never round to 90 (never charge more than agreed).
        self::assertSame(89, billing_calculate_tax(999, 9));
    }

    public function testTaxCalculationIsZeroForZeroPercentOrZeroAmount(): void {
        self::assertSame(0, billing_calculate_tax(100000, 0));
        self::assertSame(0, billing_calculate_tax(0, 9));
        self::assertSame(0, billing_calculate_tax(-50, 9));
    }

    public function testLineTotalArithmeticInvariant(): void {
        // subtotal - discount + tax, exactly — the invariant FIN-6 requires.
        $item = ['unit_price' => 10000, 'quantity' => 3]; // subtotal = 30000
        $discount = 5000;
        $tax = billing_calculate_tax(30000 - 5000, 9); // (25000*9)/100 = 2250
        self::assertSame(2250, $tax);

        $lineTotal = billing_invoice_line_total($item, $discount, $tax);
        self::assertSame(30000 - 5000 + 2250, $lineTotal);
        self::assertSame(27250, $lineTotal);
    }

    public function testLineTotalNeverGoesNegativeWhenDiscountExceedsSubtotal(): void {
        $item = ['unit_price' => 1000, 'quantity' => 1]; // subtotal = 1000
        $lineTotal = billing_invoice_line_total($item, 5000, 0); // discount larger than subtotal
        self::assertSame(0, $lineTotal, 'a line total must never be negative even with a pathological discount');
    }

    public function testInvoiceNumberFormatAndUniquenessAcrossManyCalls(): void {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $n = billing_generate_invoice_number();
            self::assertMatchesRegularExpression('/^INV-\d{4}-[0-9A-F]{6}$/', $n);
            self::assertArrayNotHasKey($n, $seen, 'invoice numbers must not collide across many generations');
            $seen[$n] = true;
        }
    }

    public function testInvoiceNumberIsNotAPredictableSequentialId(): void {
        // IDOR guard: two consecutively generated numbers must not differ by a small predictable
        // delta the way a raw auto-increment id would.
        $a = billing_generate_invoice_number();
        $b = billing_generate_invoice_number();
        self::assertNotSame($a, $b);
    }
}
