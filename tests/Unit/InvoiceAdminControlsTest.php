<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceAdminControlsTest extends TestCase
{
    public function testInvoiceAdminControlsAndVatWiringExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/db/migrations/2026_08_26_invoice_admin_controls.sql');
        self::assertFileExists($root . '/app/InvoiceAdmin.php');

        $admin = (string)file_get_contents($root . '/public/financial-admin.php');
        self::assertStringContainsString('invoice_disable', $admin);
        self::assertStringContainsString('invoice_approve', $admin);

        $customer = (string)file_get_contents($root . '/public/invoices.php');
        self::assertStringContainsString('invoice_admin_payable', $customer);

        $gateway = (string)file_get_contents($root . '/app/Payment/PaymentGateway.php');
        self::assertStringContainsString('payment_gateway_effective_amount', $gateway);
        self::assertStringContainsString('total_amount FROM ellsms_invoices', $gateway);

        $override = (string)file_get_contents($root . '/docker-compose.override.yml');
        self::assertStringContainsString('BILLING_TAX_PERCENT: "10"', $override);
    }
}
