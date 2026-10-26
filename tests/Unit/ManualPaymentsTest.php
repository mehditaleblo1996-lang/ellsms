<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * app/ManualPayments.php without a database: the card/Sheba/account checks, the buy-credit form's
 * validation, the invoice status a manual payment shows, receipt storage, and the wiring of the
 * pages that use it. Submitting and reviewing (which need the database) are exercised by hand
 * against a migrated database — see docs/manual-payments.md.
 */
final class ManualPaymentsTest extends TestCase
{
    private const CARD = '6037991234567893';
    private const SHEBA = 'IR062960000000100324200001';

    private function cardOption(): array
    {
        return ['collector' => 0, 'destinations' => [[
            'id' => 7, 'owner_user_id' => 0, 'kind' => 'card', 'bank' => 'melli', 'holder_name' => 'شرکت نمونه',
            'card_number' => self::CARD, 'account_number' => null, 'sheba' => null,
        ]]];
    }

    private function validInput(): array
    {
        return ['destination_id' => '7', 'document_number' => '۱۲۳۴۵۶', 'paid_on' => date('Y-m-d', time() - 86400), 'paid_time' => '۱۰:۳۰', 'description' => ' <b>واریز</b> '];
    }

    public function testCardNumbersMustBeSixteenDigitsAndPassLuhn(): void
    {
        self::assertSame(self::CARD, manual_payment_normalize_card('6037-9912-3456-7893'));
        self::assertSame(self::CARD, manual_payment_normalize_card('۶۰۳۷ ۹۹۱۲ ۳۴۵۶ ۷۸۹۳'));
        self::assertSame('', manual_payment_normalize_card('6037991234567894'));
        self::assertSame('', manual_payment_normalize_card('603799123456789'));
        self::assertSame('6037-9912-3456-7893', manual_payment_format_card(self::CARD));
    }

    public function testShebaNeedsTwentyFourDigitsAndAValidCheck(): void
    {
        self::assertSame(self::SHEBA, manual_payment_normalize_sheba('ir06 2960 0000 0010 0324 2000 01'));
        self::assertSame('', manual_payment_normalize_sheba('IR062960000000100324200002'));
        self::assertSame('', manual_payment_normalize_sheba('IR0629600000001003242000'));
    }

    public function testAccountNumbersAllowDigitsDashesAndDots(): void
    {
        self::assertSame('0101-123456-1', manual_payment_normalize_account('۰۱۰۱-۱۲۳۴۵۶-۱'));
        self::assertSame('', manual_payment_normalize_account('12ab34'));
        self::assertSame('', manual_payment_normalize_account('123'));
    }

    public function testDestinationLabelShowsWhereToPay(): void
    {
        $label = manual_payment_destination_label($this->cardOption()['destinations'][0]);
        self::assertStringContainsString('6037-9912-3456-7893', $label);
        self::assertStringContainsString('بانک ملی', $label);
        self::assertStringContainsString('شرکت نمونه', $label);

        $account = manual_payment_destination_label(['kind' => 'account', 'bank' => 'mellat', 'holder_name' => 'الف', 'card_number' => null, 'account_number' => '123-456', 'sheba' => self::SHEBA]);
        self::assertStringContainsString('123-456', $account);
        self::assertStringContainsString(self::SHEBA, $account);
    }

    public function testValidCardPaymentIsAccepted(): void
    {
        $result = manual_payment_validate_input('card', $this->cardOption(), $this->validInput(), 1100000);
        self::assertTrue($result['ok'], $result['reason'] ?? '');
        self::assertSame(7, $result['data']['destination_id']);
        self::assertSame('123456', $result['data']['document_number']);
        self::assertStringEndsWith(' 10:30:00', $result['data']['paid_at']);
        self::assertSame('واریز', $result['data']['description']);
        self::assertStringContainsString('6037-9912-3456-7893', $result['data']['destination_label']);
    }

    public function testCardPaymentRefusals(): void
    {
        $refuse = fn(array $changes, string $reason) => self::assertSame(
            $reason,
            manual_payment_validate_input('card', $this->cardOption(), $changes + $this->validInput(), 1100000)['reason'] ?? null
        );
        $refuse(['destination_id' => '8'], 'destination');
        $refuse(['document_number' => '  '], 'document_number');
        $refuse(['paid_on' => null], 'paid_on');
        $refuse(['paid_time' => '25:00'], 'paid_time');
        $refuse(['paid_on' => date('Y-m-d', time() + 3 * 86400)], 'paid_in_future');
        $refuse(['paid_on' => date('Y-m-d', time() - 400 * 86400)], 'paid_too_old');
    }

    public function testCashNeedsNoDestination(): void
    {
        $result = manual_payment_validate_input('cash', ['collector' => 0, 'destinations' => []], ['destination_id' => ''] + $this->validInput(), 500000);
        self::assertTrue($result['ok']);
        self::assertNull($result['data']['destination_id']);
    }

    public function testChequeMustMatchThePayableTotalAndCarryItsDetails(): void
    {
        $option = ['collector' => 12, 'destinations' => [[
            'id' => 3, 'owner_user_id' => 12, 'kind' => 'account', 'bank' => 'mellat', 'holder_name' => 'نماینده',
            'card_number' => null, 'account_number' => '123456789', 'sheba' => null,
        ]]];
        $input = ['destination_id' => '3', 'cheque_amount' => '1100000', 'cheque_bank' => 'saderat', 'cheque_branch_code' => '۴۵۶',
                  'cheque_owner_name' => 'علی رضایی', 'cheque_owner_sheba' => self::SHEBA, 'cheque_owner_national_id' => '0012345678',
                  'cheque_sayad_number' => '1234567890123456', 'cheque_serial' => '123456', 'cheque_series' => '۱۲ب',
                  'cheque_due_on' => date('Y-m-d', time() + 30 * 86400)] + $this->validInput();

        $ok = manual_payment_validate_input('cheque', $option, $input, 1100000);
        self::assertTrue($ok['ok'], $ok['reason'] ?? '');
        self::assertSame('456', $ok['data']['cheque_branch_code']);
        self::assertSame('12ب', $ok['data']['cheque_series']);

        self::assertSame('cheque_amount', manual_payment_validate_input('cheque', $option, ['cheque_amount' => '1000000'] + $input, 1100000)['reason']);
        self::assertSame('cheque_owner_sheba', manual_payment_validate_input('cheque', $option, ['cheque_owner_sheba' => 'IR00'] + $input, 1100000)['reason']);
        self::assertSame('cheque_sayad_number', manual_payment_validate_input('cheque', $option, ['cheque_sayad_number' => '123'] + $input, 1100000)['reason']);
        self::assertSame('cheque_owner_national_id', manual_payment_validate_input('cheque', $option, ['cheque_owner_national_id' => '123'] + $input, 1100000)['reason']);
        self::assertSame('cheque_bank', manual_payment_validate_input('cheque', $option, ['cheque_bank' => 'nope'] + $input, 1100000)['reason']);
        self::assertSame('cheque_due_on', manual_payment_validate_input('cheque', $option, ['cheque_due_on' => null] + $input, 1100000)['reason']);
    }

    public function testInvoiceStatusShowsAwaitingReviewAndRejection(): void
    {
        $issued = ['status' => 'issued'];
        self::assertSame(['در انتظار بررسی', 'review'], manual_payment_invoice_status($issued, ['review_state' => 'pending']));
        self::assertSame(['ردشده', 'off'], manual_payment_invoice_status(['status' => 'cancelled'], ['review_state' => 'rejected']));
        self::assertSame(['پرداخت‌شده', 'ok'], manual_payment_invoice_status(['status' => 'paid'], ['review_state' => 'approved']));
        self::assertSame(['پرداخت‌نشده', 'pending'], manual_payment_invoice_status($issued, null));
    }

    public function testMethodLabelsAndManualGatewayDetection(): void
    {
        self::assertTrue(manual_payment_is_manual_gateway('cheque'));
        self::assertFalse(manual_payment_is_manual_gateway('zarinpal'));
        self::assertFalse(manual_payment_is_manual_gateway(null));
        self::assertSame('کارت به کارت', manual_payment_method_label('card'));
        self::assertStringContainsString('زرین‌پال', manual_payment_method_label('zarinpal'));
        foreach (array_keys(MANUAL_PAYMENT_METHODS) as $method) {
            self::assertLessThanOrEqual(10, strlen($method), 'fits ellsms_payment_method_settings.method');
        }
    }

    public function testReceiptStorageAcceptsOnlyRealImages(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $stored = manual_payment_attachment_store_path($png, (int)filesize($png));
        self::assertTrue($stored['ok']);
        self::assertMatchesRegularExpression('/^r[0-9a-f]{24}\.png$/', $stored['file']);
        $path = manual_payment_attachment_path($stored['file']);
        self::assertNotNull($path);
        @unlink($path);

        $text = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($text, '<?php echo 1;');
        self::assertSame('attachment_type', manual_payment_attachment_store_path($text, (int)filesize($text))['reason']);
        self::assertSame('attachment_size', manual_payment_attachment_store_path($text, MANUAL_PAYMENT_ATTACHMENT_MAX_BYTES + 1)['reason']);
        self::assertNull(manual_payment_attachment_path('../../.env'));
        @unlink($png);
        @unlink($text);
    }

    public function testPagesAreWiredToTheReviewFlow(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/db/migrations/2026_10_25_manual_payments.sql');

        $buy = (string)file_get_contents($root . '/public/buy-credit.php');
        self::assertStringContainsString('manual_payment_submit(', $buy);
        self::assertStringContainsString('enctype="multipart/form-data"', $buy);

        $invoices = (string)file_get_contents($root . '/public/invoices.php');
        self::assertStringContainsString('manual_payment_is_manual_gateway(', $invoices, 'a manual invoice is never re-paid online');
        self::assertStringContainsString('manual_payment_invoice_status(', $invoices);

        $invoiceAdmin = (string)file_get_contents($root . '/app/InvoiceAdmin.php');
        self::assertStringContainsString('manual_review_required', $invoiceAdmin, 'manual payments are approved through their review, not invoice mark-paid');

        self::assertArrayHasKey('payments.manage', RESELLER_STAFF_PERMISSIONS);
        self::assertContains('payment-receipt.php', RESELLER_STAFF_ALLOWED_PAGES);
        self::assertArrayHasKey('payment.manual_submitted', NOTIFICATION_EVENTS);
    }
}
