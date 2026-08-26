<?php
/**
 * ELLSMS — generic payment gateway abstraction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../zarinpal.php';
require_once __DIR__ . '/FakeGateway.php';

const PAYMENT_GATEWAYS = ['zarinpal', 'fake'];

function payment_gateway_name(): string {
    $configured = (string)(env('PAYMENT_DEFAULT_GATEWAY', 'zarinpal') ?? 'zarinpal');
    if ($configured === 'fake' && !payment_fake_gateway_enabled()) {
        return 'zarinpal';
    }
    return in_array($configured, PAYMENT_GATEWAYS, true) ? $configured : 'zarinpal';
}

/**
 * The gateway amount must always equal the accounting invoice total.
 *
 * - Retry flow: the invoice already exists, so its immutable total is authoritative.
 * - Initial flow where the invoice is issued before gateway creation (credit purchase): same.
 * - Subscription flow currently creates the gateway request before issuing its invoice; in that
 *   narrow case derive the same VAT formula from the server-side base amount, persist it on the
 *   payment row, and billing_invoice_create() will produce the identical total a few lines later.
 *
 * This keeps provider verification, payment.amount_rial and invoice.total_amount identical and
 * prevents a 10% VAT invoice from accidentally charging only the pre-tax amount.
 */
function payment_gateway_effective_amount(int $paymentId, int $baseAmountRial): int {
    $st = db()->prepare('SELECT total_amount FROM ellsms_invoices WHERE payment_id=? LIMIT 1');
    $st->execute([$paymentId]);
    $invoiceTotal = $st->fetchColumn();
    if ($invoiceTotal !== false && (int)$invoiceTotal > 0) {
        $amount = (int)$invoiceTotal;
    } else {
        $taxPercent = function_exists('billing_tax_percent') ? billing_tax_percent() : 10;
        $tax = function_exists('billing_calculate_tax')
            ? billing_calculate_tax($baseAmountRial, $taxPercent)
            : intdiv(max(0, $baseAmountRial) * max(0, $taxPercent), 100);
        $amount = max(0, $baseAmountRial) + $tax;
    }

    db()->prepare('UPDATE ellsms_payments SET amount_rial=? WHERE id=? AND amount_rial<>?')
        ->execute([$amount, $paymentId, $amount]);
    return $amount;
}

function payment_gateway_create(string $gateway, int $amountRial, int $paymentId, string $description, string $mobile = ''): array {
    $amountRial = payment_gateway_effective_amount($paymentId, $amountRial);
    return match ($gateway) {
        'fake' => payment_fake_gateway_create($amountRial, $paymentId, $description, $mobile),
        default => payment_zarinpal_gateway_create($amountRial, $paymentId, $description, $mobile),
    };
}

function payment_gateway_redirect_url(string $gateway, string $authority): string {
    return match ($gateway) {
        'fake' => payment_fake_gateway_redirect_url($authority),
        default => zarinpal_start_pay_url($authority),
    };
}

function payment_gateway_verify(string $gateway, int $amountRial, string $authority): array {
    return match ($gateway) {
        'fake' => payment_fake_gateway_verify($amountRial, $authority),
        default => payment_zarinpal_gateway_verify($amountRial, $authority),
    };
}

function payment_gateway_supports_refund(string $gateway): bool {
    return match ($gateway) {
        'fake' => true,
        default => false,
    };
}

function payment_zarinpal_gateway_create(int $amountRial, int $paymentId, string $description, string $mobile): array {
    [$ok, $message, $authority] = zarinpal_request($amountRial, $paymentId, $description, $mobile);
    return ['ok' => $ok, 'message' => $message, 'authority' => $authority];
}

function payment_zarinpal_gateway_verify(int $amountRial, string $authority): array {
    [$ok, $message, $refId] = zarinpal_verify($amountRial, $authority);
    return ['ok' => $ok, 'message' => $message, 'ref_id' => $refId, 'verified_amount_rial' => null];
}
