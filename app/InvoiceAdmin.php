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
    if ($invoiceId <= 0 || !in_array($state, ['approved', 'disabled'], true)) {
        return ['ok' => false, 'reason' => 'invalid_request'];
    }
    $note = trim($note);
    if ($state === 'disabled' && $note === '') {
        return ['ok' => false, 'reason' => 'note_required'];
    }
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'reason' => 'note_too_long'];
    }

    return db_transaction(function (PDO $db) use ($invoiceId, $state, $adminUserId, $note): array {
        $st = $db->prepare('SELECT i.id,i.status,i.admin_state,i.invoice_number,i.payment_id,p.status AS payment_status,p.authority
                            FROM ellsms_invoices i
                            LEFT JOIN ellsms_payments p ON p.id=i.payment_id
                            WHERE i.id=? FOR UPDATE');
        $st->execute([$invoiceId]);
        $invoice = $st->fetch();
        if (!$invoice) {
            return ['ok' => false, 'reason' => 'invoice_not_found'];
        }
        if (($invoice['status'] ?? '') !== 'issued') {
            return ['ok' => false, 'reason' => 'invoice_not_issued'];
        }
        // Once a provider authority has been issued and payment is actively pending, disabling the
        // local button cannot reliably cancel the provider-side payment. Refuse the operation rather
        // than create an orphan payment that could capture money while ELLSMS ignores the callback.
        if ($state === 'disabled'
            && ($invoice['payment_status'] ?? '') === 'pending'
            && trim((string)($invoice['authority'] ?? '')) !== '') {
            return ['ok' => false, 'reason' => 'active_payment'];
        }

        $current = invoice_admin_state($invoice);
        if ($current === $state) {
            return ['ok' => true, 'reason' => 'unchanged', 'invoice_number' => $invoice['invoice_number']];
        }

        $db->prepare('UPDATE ellsms_invoices SET admin_state=?, admin_note=?, admin_reviewed_by=?, admin_reviewed_at=UTC_TIMESTAMP() WHERE id=? AND status=\'issued\'')
            ->execute([$state, $note !== '' ? $note : null, $adminUserId, $invoiceId]);

        audit($adminUserId, $state === 'approved' ? 'invoice.admin_approved' : 'invoice.admin_disabled',
            'invoice=' . $invoice['invoice_number'] . ($note !== '' ? ' note=' . $note : ''));
        Logger::info($state === 'approved' ? 'invoice.admin_approved' : 'invoice.admin_disabled', [
            'invoice_id' => $invoiceId,
            'admin_user_id' => $adminUserId,
        ]);

        return ['ok' => true, 'reason' => $state, 'invoice_number' => $invoice['invoice_number']];
    });
}
