<?php

declare(strict_types=1);

/** Administrative payment gate for invoices. */
function invoice_admin_state(array $invoice): string {
    $state = (string)($invoice['admin_state'] ?? 'approved');
    return in_array($state, ['approved', 'disabled'], true) ? $state : 'approved';
}

function invoice_admin_payable(array $invoice): bool {
    return ($invoice['status'] ?? '') === 'issued' && invoice_admin_state($invoice) === 'approved';
}

function invoice_admin_set_state(int $invoiceId, string $state, int $adminUserId, string $note = ''): array {
    if ($invoiceId <= 0 || !in_array($state, ['approved', 'disabled'], true)) return ['ok' => false, 'reason' => 'invalid_request'];
    $note = trim($note);
    if ($state === 'disabled' && $note === '') return ['ok' => false, 'reason' => 'note_required'];
    if (mb_strlen($note) > 500) return ['ok' => false, 'reason' => 'note_too_long'];

    return db_transaction(function (PDO $db) use ($invoiceId, $state, $adminUserId, $note): array {
        $st = $db->prepare('SELECT i.id,i.status,i.admin_state,i.invoice_number,i.payment_id,p.status AS payment_status,p.authority
                            FROM ellsms_invoices i LEFT JOIN ellsms_payments p ON p.id=i.payment_id
                            WHERE i.id=? FOR UPDATE');
        $st->execute([$invoiceId]);
        $invoice = $st->fetch();
        if (!$invoice) return ['ok' => false, 'reason' => 'invoice_not_found'];
        if (($invoice['status'] ?? '') !== 'issued') return ['ok' => false, 'reason' => 'invoice_not_issued'];
        if ($state === 'disabled' && ($invoice['payment_status'] ?? '') === 'pending' && trim((string)($invoice['authority'] ?? '')) !== '') {
            return ['ok' => false, 'reason' => 'active_payment'];
        }
        $current = invoice_admin_state($invoice);
        if ($current === $state) return ['ok' => true, 'reason' => 'unchanged', 'invoice_number' => $invoice['invoice_number']];

        $db->prepare('UPDATE ellsms_invoices SET admin_state=?,admin_note=?,admin_reviewed_by=?,admin_reviewed_at=UTC_TIMESTAMP() WHERE id=? AND status=\'issued\'')
            ->execute([$state, $note !== '' ? $note : null, $adminUserId, $invoiceId]);
        audit($adminUserId, $state === 'approved' ? 'invoice.admin_approved' : 'invoice.admin_disabled', 'invoice=' . $invoice['invoice_number'] . ($note !== '' ? ' note=' . $note : ''));
        Logger::info($state === 'approved' ? 'invoice.admin_approved' : 'invoice.admin_disabled', ['invoice_id' => $invoiceId, 'admin_user_id' => $adminUserId]);
        return ['ok' => true, 'reason' => $state, 'invoice_number' => $invoice['invoice_number']];
    });
}

/** Confirm a payment manually while reusing the normal fulfillment path (wallet/subscription). */
function invoice_admin_mark_paid(int $invoiceId, int $adminUserId, string $note): array {
    $note = trim($note);
    if ($invoiceId <= 0) return ['ok' => false, 'reason' => 'invalid_request'];
    if ($note === '') return ['ok' => false, 'reason' => 'note_required'];
    if (mb_strlen($note) > 500) return ['ok' => false, 'reason' => 'note_too_long'];

    $st = db()->prepare('SELECT * FROM ellsms_invoices WHERE id=? LIMIT 1');
    $st->execute([$invoiceId]);
    $invoice = $st->fetch();
    if (!$invoice) return ['ok' => false, 'reason' => 'invoice_not_found'];
    if (($invoice['status'] ?? '') === 'paid') return ['ok' => true, 'reason' => 'already_paid'];
    if (($invoice['status'] ?? '') !== 'issued') return ['ok' => false, 'reason' => 'invoice_not_issued'];
    if (invoice_admin_state($invoice) !== 'approved') return ['ok' => false, 'reason' => 'invoice_disabled'];
    if (empty($invoice['payment_id'])) return ['ok' => false, 'reason' => 'payment_missing'];

    $pst = db()->prepare('SELECT * FROM ellsms_payments WHERE id=? LIMIT 1');
    $pst->execute([(int)$invoice['payment_id']]);
    $payment = $pst->fetch();
    if (!$payment) return ['ok' => false, 'reason' => 'payment_missing'];
    if (($payment['status'] ?? '') === 'paid') {
        db_transaction(function (PDO $db) use ($invoiceId): void { billing_invoice_mark_paid($db, $invoiceId); });
        return ['ok' => true, 'reason' => 'already_paid'];
    }
    if (!in_array($payment['status'], ['pending','verification_failed','failed'], true)) return ['ok' => false, 'reason' => 'payment_state_invalid'];

    if ($payment['status'] === 'failed') {
        $up = db()->prepare("UPDATE ellsms_payments SET status='pending' WHERE id=? AND status='failed'");
        $up->execute([(int)$payment['id']]);
        if ($up->rowCount() !== 1) return ['ok' => false, 'reason' => 'payment_race'];
        $payment['status'] = 'pending';
    }

    $manualRef = 'MANUAL-ADMIN-' . $invoiceId . '-' . gmdate('YmdHis');
    $purpose = (string)($payment['purpose'] ?? $invoice['purpose'] ?? 'credit');
    if ($purpose === 'subscription') {
        $result = payment_claim_and_activate_subscription($payment, $manualRef);
        $fulfilled = !empty($result['claimed']) && !in_array(($result['reason'] ?? ''), ['no_billing_record','billing_record_missing','organization_mismatch'], true);
    } else {
        $result = payment_claim_and_credit($payment, $manualRef);
        $fulfilled = !empty($result['claimed']);
    }
    if (!$fulfilled) return ['ok' => false, 'reason' => ($result['reason'] ?? 'fulfillment_failed')];

    db()->prepare('UPDATE ellsms_invoices SET admin_note=?,admin_reviewed_by=?,admin_reviewed_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$note, $adminUserId, $invoiceId]);
    audit($adminUserId, 'invoice.admin_marked_paid', 'invoice=' . $invoice['invoice_number'] . ' note=' . $note);
    Logger::warning('invoice.admin_marked_paid', ['invoice_id' => $invoiceId, 'admin_user_id' => $adminUserId]);
    return ['ok' => true, 'reason' => 'paid', 'invoice_number' => $invoice['invoice_number']];
}
