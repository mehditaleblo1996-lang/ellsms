<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceAdminVatTest extends TestCase
{
    public function testRuntimeForcesTenPercentVatAndBackfillExists(): void
    {
        $root = dirname(__DIR__, 2);
        $override = (string)file_get_contents($root . '/docker-compose.override.yml');
        self::assertStringContainsString('BILLING_TAX_PERCENT: 10', $override);
        self::assertFileExists($root . '/db/migrations/2026_08_26_invoice_vat10_backfill.sql');
        $migration = (string)file_get_contents($root . '/db/migrations/2026_08_26_invoice_vat10_backfill.sql');
        self::assertStringContainsString("WHERE i.status = 'issued'", $migration);
        self::assertStringContainsString('SET p.amount_rial = i.total_amount', $migration);
    }

    public function testAdminInvoiceControlsUseFulfillmentPath(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/InvoiceAdmin.php');
        self::assertStringContainsString('function invoice_admin_mark_paid', $service);
        self::assertStringContainsString('payment_claim_and_credit', $service);
        self::assertStringContainsString('payment_claim_and_activate_subscription', $service);

        $detail = (string)file_get_contents($root . '/public/invoices.php');
        self::assertStringContainsString('invoice_mark_paid', $detail);
        self::assertStringContainsString('invoice_unpaid', $detail);
        self::assertStringContainsString('invoice_disable', $detail);
    }
}
