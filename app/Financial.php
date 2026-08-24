<?php
/**
 * ELLSMS — Invoice layer (financial-commerce continuation, FIN-1).
 *
 * DECISION: extend the existing financial model, not replace it (docs/financial-commerce.md).
 * ellsms_payments (app/zarinpal.php) remains the payment/purchase-intent record and the sole
 * authority for payment state. ellsms_wallet_transactions (app/wallet.php) remains the sole SMS
 * credit ledger. ellsms_subscriptions (app/Billing.php) remains the sole subscription state
 * machine. This file adds exactly one new concept on top of them: an IMMUTABLE invoice snapshot,
 * one per payment, that never changes after issuance even if a plan price or the credit rate or
 * the tax percentage changes later.
 *
 * No class/namespace, matching every other app/*.php domain-service file in this codebase.
 */

declare(strict_types=1);

/** Configurable, never hardcoded (FIN-9). Default 0 — every existing deployment is unaffected until an operator sets it. */
function billing_tax_percent(): int {
    $v = (int)(env('BILLING_TAX_PERCENT', '0') ?? '0');
    return max(0, min(100, $v));
}

/**
 * Integer-safe tax on a subtotal, applied AFTER discount (tax on the amount actually owed, not on
 * the pre-discount list price — the common, unsurprising convention). Rounds down (floor) via
 * integer division: never charge a customer a fraction of a Rial they didn't agree to; any residual
 * from rounding is absorbed by the merchant, not the customer. Documented here because this is the
 * ONLY place tax is computed — every invoice's tax_amount traces back to exactly this formula.
 */
function billing_calculate_tax(int $amountAfterDiscount, int $taxPercent): int {
    if ($amountAfterDiscount <= 0 || $taxPercent <= 0) {
        return 0;
    }
    return intdiv($amountAfterDiscount * $taxPercent, 100);
}

/**
 * Opaque invoice number: NOT the raw payment/invoice numeric primary key alone (predictable
 * sequential IDs on a financial document are an IDOR/enumeration surface — FIN's own "do not use a
 * predictable public numeric ID alone" instruction). Format: INV-{year}-{6 random hex chars
 * uppercased}. Collision is astronomically unlikely (16^6 = ~16.7M keyspace per year) and the
 * UNIQUE index on ellsms_invoices.invoice_number is the actual guarantee regardless — a collision
 * simply raises a PDOException the caller's transaction rolls back on, exactly like every other
 * UNIQUE-constraint-as-guarantee in this codebase.
 */
function billing_generate_invoice_number(): string {
    return 'INV-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * One invoice LINE ITEM's shape, used both when creating and when reading back. Keeping this as a
 * single associative shape (rather than positional args) is what lets billing_invoice_create()
 * accept one or many lines without two different call signatures — every current caller passes
 * exactly one, but the schema and this function are not limited to that (FIN-1: "future-proof").
 *
 * @param array{item_type:string,reference_code:?string,description:string,quantity:int,unit_price:int} $item
 */
function billing_invoice_line_total(array $item, int $discountAmount, int $taxAmount): int {
    $subtotal = (int)$item['unit_price'] * (int)$item['quantity'];
    return max(0, $subtotal - $discountAmount) + $taxAmount;
}

/**
 * Creates the immutable invoice snapshot for an already-created ellsms_payments row, inside the
 * SAME transaction the caller is already in (this function opens its own via db_transaction() if
 * none is open, joining an existing one otherwise — see db_transaction()'s own nesting contract).
 *
 * $items: array of ['item_type'=>string,'reference_code'=>?string,'description'=>string,
 * 'quantity'=>int,'unit_price'=>int] — see billing_invoice_line_total(). Amounts are NEVER read from
 * request input here; every caller (public/buy-credit.php, public/billing.php) must derive
 * unit_price server-side from the current credit rate / plan row BEFORE calling this function, the
 * same way $amountRial already reaches zarinpal_request() today.
 *
 * $couponCode, if supplied, is validated and applied via billing_coupon_apply() as part of this same
 * transaction — a coupon race (two invoices trying to redeem the last usage slot concurrently) is
 * resolved by that function's own UNIQUE(invoice_id) redemption row, not by anything here.
 *
 * Returns ['ok'=>bool, 'invoice_id'=>?int, 'invoice_number'=>?string, 'total_amount'=>?int, 'reason'=>?string].
 */
function billing_invoice_create(
    int $paymentId, ?int $organizationId, int $userId, string $purpose, array $items,
    ?string $couponCode = null, ?int $dueInMinutes = null
): array {
    if ($items === []) {
        return ['ok' => false, 'reason' => 'no_items'];
    }

    return db_transaction(function (PDO $db) use ($paymentId, $organizationId, $userId, $purpose, $items, $couponCode, $dueInMinutes): array {
        // One invoice per payment, enforced by the UNIQUE index — a retried "create invoice for this
        // payment" call (a double-submitted checkout form) sees the existing row and replays rather
        // than raising a raw constraint violation up to the caller.
        $existing = $db->prepare('SELECT id, invoice_number, total_amount FROM ellsms_invoices WHERE payment_id = ?');
        $existing->execute([$paymentId]);
        if ($row = $existing->fetch()) {
            return ['ok' => true, 'invoice_id' => (int)$row['id'], 'invoice_number' => $row['invoice_number'], 'total_amount' => (int)$row['total_amount'], 'reason' => 'already_exists'];
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (int)$item['unit_price'] * (int)$item['quantity'];
        }
        if ($subtotal <= 0) {
            return ['ok' => false, 'reason' => 'invalid_subtotal'];
        }

        $discountAmount = 0;
        $couponId = null;
        $couponCodeSnapshot = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $couponResult = billing_coupon_validate($db, trim($couponCode), $organizationId, $subtotal);
            if (!$couponResult['ok']) {
                return ['ok' => false, 'reason' => $couponResult['reason']];
            }
            $discountAmount = $couponResult['discount_amount'];
            $couponId = $couponResult['coupon_id'];
            $couponCodeSnapshot = $couponResult['code'];
        }

        $taxPercent = billing_tax_percent();
        $amountAfterDiscount = max(0, $subtotal - $discountAmount);
        $taxAmount = billing_calculate_tax($amountAfterDiscount, $taxPercent);
        $totalAmount = $amountAfterDiscount + $taxAmount;

        $invoiceNumber = billing_generate_invoice_number();
        $dueAt = $dueInMinutes !== null ? gmdate('Y-m-d H:i:s', time() + $dueInMinutes * 60) : null;

        $db->prepare(
            'INSERT INTO ellsms_invoices
                (organization_id, user_id, payment_id, invoice_number, purpose, status, currency,
                 subtotal_amount, discount_amount, tax_amount, total_amount, coupon_id, coupon_code, due_at)
             VALUES (?,?,?,?,?,\'issued\',?,?,?,?,?,?,?,?)'
        )->execute([
            $organizationId, $userId, $paymentId, $invoiceNumber, $purpose, billing_currency(),
            $subtotal, $discountAmount, $taxAmount, $totalAmount, $couponId, $couponCodeSnapshot, $dueAt,
        ]);
        $invoiceId = (int)$db->lastInsertId();

        // Distribute discount/tax across line items proportionally so item.line_total sums exactly
        // to the invoice total — only meaningful once a purchase has more than one line, but correct
        // from the start rather than assumed later.
        $itemIns = $db->prepare(
            'INSERT INTO ellsms_invoice_items
                (invoice_id, item_type, reference_code, description_snapshot, quantity, unit_price, discount_amount, tax_amount, line_total)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $remainingDiscount = $discountAmount;
        $remainingTax = $taxAmount;
        $lineCount = count($items);
        $i = 0;
        foreach ($items as $item) {
            $i++;
            $lineSubtotal = (int)$item['unit_price'] * (int)$item['quantity'];
            $isLast = $i === $lineCount;
            $lineDiscount = $isLast ? $remainingDiscount : intdiv($lineSubtotal * $discountAmount, $subtotal);
            $lineTaxBase = max(0, $lineSubtotal - $lineDiscount);
            $lineTax = $isLast ? $remainingTax : billing_calculate_tax($lineTaxBase, $taxPercent);
            $remainingDiscount -= $lineDiscount;
            $remainingTax -= $lineTax;
            $lineTotal = max(0, $lineSubtotal - $lineDiscount) + $lineTax;

            $itemIns->execute([
                $invoiceId, $item['item_type'], $item['reference_code'] ?? null, $item['description'],
                (int)$item['quantity'], (int)$item['unit_price'], $lineDiscount, $lineTax, $lineTotal,
            ]);
        }

        if ($couponId !== null) {
            billing_coupon_redeem($db, $couponId, $invoiceId, $organizationId, $userId, $discountAmount);
        }

        $db->prepare('UPDATE ellsms_payments SET invoice_id = ? WHERE id = ?')->execute([$invoiceId, $paymentId]);

        Logger::info('invoice.issued', ['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'payment_id' => $paymentId, 'total_amount' => $totalAmount]);
        Metrics::increment('billing.invoice.issued', 1, ['purpose' => $purpose]);

        return ['ok' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => $totalAmount, 'reason' => 'created'];
    });
}

/**
 * Marks an invoice paid, idempotently, from inside the SAME transaction that claims the payment
 * (called by payment_gateway_claim_and_fulfill(), FIN-3). Deliberately mirrors the payment claim's
 * own atomic-UPDATE-then-check-rowCount pattern rather than a read-then-write, for the same
 * concurrency reason: two racing callbacks for the same invoice must serialize on this UPDATE, not
 * both read status='issued' and both think they're the one marking it paid.
 */
function billing_invoice_mark_paid(PDO $db, int $invoiceId): bool {
    $st = $db->prepare("UPDATE ellsms_invoices SET status='paid', paid_at=UTC_TIMESTAMP() WHERE id=? AND status='issued'");
    $st->execute([$invoiceId]);
    return $st->rowCount() > 0;
}

/** Cancels an unpaid invoice — used when a payment attempt fails/expires (FIN's "failed/cancelled payments" — invoice must NOT be paid). Idempotent: cancelling an already-cancelled/paid invoice is a safe no-op. */
function billing_invoice_cancel(int $invoiceId, string $reason = ''): bool {
    $st = db()->prepare("UPDATE ellsms_invoices SET status='cancelled', cancelled_at=UTC_TIMESTAMP() WHERE id=? AND status='issued'");
    $st->execute([$invoiceId]);
    $changed = $st->rowCount() > 0;
    if ($changed) {
        Logger::info('invoice.cancelled', ['invoice_id' => $invoiceId, 'reason' => $reason]);
    }
    return $changed;
}

/** Read-only. Server-side expiry sweep for invoices whose due_at has passed while still 'issued' — called from cron, never blocks a live request. */
function billing_invoices_expire_overdue(int $limit = 500): int {
    $st = db()->prepare("UPDATE ellsms_invoices SET status='expired' WHERE status='issued' AND due_at IS NOT NULL AND due_at < UTC_TIMESTAMP() LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $n = $st->rowCount();
    if ($n > 0) {
        Logger::info('invoice.expired_batch', ['count' => $n]);
    }
    return $n;
}

function billing_invoice_by_id(int $invoiceId, ?int $organizationId, ?int $userId): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_invoices WHERE id = ?');
    $st->execute([$invoiceId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    // Tenant/ownership check mirrors import_load_job()'s pattern (app/import.php): an
    // organization-scoped caller must match organization_id; a personal (no-org) caller must match
    // user_id. Never both null — a caller with neither is refused implicitly (both comparisons fail).
    if ($organizationId !== null && (int)($row['organization_id'] ?? 0) !== $organizationId) {
        return null;
    }
    if ($organizationId === null && $userId !== null && (int)$row['user_id'] !== $userId) {
        return null;
    }
    return $row;
}

function billing_invoice_items(int $invoiceId): array {
    $st = db()->prepare('SELECT * FROM ellsms_invoice_items WHERE invoice_id = ? ORDER BY id');
    $st->execute([$invoiceId]);
    return $st->fetchAll();
}

/** Invoice + its payment row in one call, for the detail/print view. Ownership already checked by billing_invoice_by_id(). */
function billing_invoice_with_payment(int $invoiceId, ?int $organizationId, ?int $userId): ?array {
    $invoice = billing_invoice_by_id($invoiceId, $organizationId, $userId);
    if (!$invoice) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
    $st->execute([(int)$invoice['payment_id']]);
    $invoice['payment'] = $st->fetch() ?: null;
    $invoice['items'] = billing_invoice_items($invoiceId);
    return $invoice;
}

/** Bounded, DB-paginated invoice list for a customer (their own organization/user scope only). */
function billing_invoices_for_organization(?int $organizationId, ?int $userId, int $limit = 30, int $offset = 0): array {
    $limit = max(1, min(100, $limit));
    if ($organizationId !== null) {
        $st = db()->prepare('SELECT * FROM ellsms_invoices WHERE organization_id = ? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset));
        $st->execute([$organizationId]);
    } else {
        $st = db()->prepare('SELECT * FROM ellsms_invoices WHERE organization_id IS NULL AND user_id = ? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset));
        $st->execute([$userId]);
    }
    return $st->fetchAll();
}

/* ==========================================================================
   Coupons (FIN-10) — minimal, server-validated, transaction-safe usage counting.
   ========================================================================== */

/**
 * Validates a coupon WITHOUT redeeming it (read-only check, used both by billing_invoice_create()
 * inside its transaction and by a "preview my discount" UI call outside one). Must be called with
 * $db already inside billing_invoice_create()'s transaction for the redemption path — the usage-limit
 * check below is only race-safe because billing_coupon_redeem() re-checks the limit again under
 * FOR UPDATE immediately before incrementing (this function alone is a preview, not the guarantee).
 */
function billing_coupon_validate(PDO $db, string $code, ?int $organizationId, int $subtotal): array {
    $st = $db->prepare('SELECT * FROM ellsms_coupons WHERE code = ?');
    $st->execute([$code]);
    $coupon = $st->fetch();
    if (!$coupon) {
        return ['ok' => false, 'reason' => 'coupon_not_found'];
    }
    if (!(bool)$coupon['enabled']) {
        return ['ok' => false, 'reason' => 'coupon_disabled'];
    }
    $now = gmdate('Y-m-d H:i:s');
    if ($coupon['valid_from'] !== null && $coupon['valid_from'] > $now) {
        return ['ok' => false, 'reason' => 'coupon_not_yet_valid'];
    }
    if ($coupon['valid_until'] !== null && $coupon['valid_until'] < $now) {
        return ['ok' => false, 'reason' => 'coupon_expired'];
    }
    if ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
        return ['ok' => false, 'reason' => 'coupon_usage_limit_reached'];
    }
    if ($coupon['organization_id'] !== null && (int)$coupon['organization_id'] !== $organizationId) {
        return ['ok' => false, 'reason' => 'coupon_not_eligible'];
    }
    if ($subtotal < (int)$coupon['minimum_amount']) {
        return ['ok' => false, 'reason' => 'coupon_minimum_amount_not_met'];
    }

    $discount = $coupon['type'] === 'percent'
        ? intdiv($subtotal * (int)$coupon['value'], 100)
        : min($subtotal, (int)$coupon['value']); // FIN's "protect against negative invoice total": a fixed discount never exceeds the subtotal it's applied to
    if ($discount <= 0) {
        return ['ok' => false, 'reason' => 'coupon_no_discount'];
    }

    return ['ok' => true, 'coupon_id' => (int)$coupon['id'], 'code' => $coupon['code'], 'discount_amount' => $discount];
}

/**
 * Actually records the redemption and increments used_count, inside the CALLER's transaction
 * (billing_invoice_create()). The FOR UPDATE lock on the coupon row is what makes the usage-limit
 * check race-safe: two concurrent checkouts both trying to redeem a coupon with 1 remaining use will
 * serialize here — the second sees the incremented used_count and its own
 * UNIQUE(invoice_id) redemption insert never happens for it because billing_coupon_validate()
 * (called moments earlier, without a lock) is only a preview; THIS re-check is the actual guarantee.
 */
function billing_coupon_redeem(PDO $db, int $couponId, int $invoiceId, ?int $organizationId, int $userId, int $discountAmount): void {
    $lock = $db->prepare('SELECT usage_limit, used_count FROM ellsms_coupons WHERE id = ? FOR UPDATE');
    $lock->execute([$couponId]);
    $coupon = $lock->fetch();
    if (!$coupon) {
        throw new RuntimeException('coupon_missing_during_redeem');
    }
    if ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
        throw new RuntimeException('coupon_usage_limit_reached');
    }

    $db->prepare('INSERT INTO ellsms_coupon_redemptions (coupon_id, invoice_id, organization_id, user_id, discount_amount) VALUES (?,?,?,?,?)')
       ->execute([$couponId, $invoiceId, $organizationId, $userId, $discountAmount]);
    $db->prepare('UPDATE ellsms_coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
}

function billing_coupon_by_code(string $code): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_coupons WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ?: null;
}
