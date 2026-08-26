<?php

declare(strict_types=1);

/**
 * Administrative payment gate for invoices.
 *
 * This is deliberately independent from the accounting status (`issued`, `paid`, ...). Disabling an
 * invoice does not pretend it was cancelled or paid; it only prevents the customer from starting or
 * retrying a gateway payment until an administrator approves it again.
 */
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
        $st = $db->prepare('SELECT id,status,admin_state,invoice_number FROM ellsms_invoices WHERE id=? FOR UPDATE');
        $st->execute([$invoiceId]);
        $invoice = $st->fetch();
        if (!$invoice) {
            return ['ok' => false, 'reason' => 'invoice_not_found'];
        }
        if (($invoice['status'] ?? '') !== 'issued') {
            return ['ok' => false, 'reason' => 'invoice_not_issued'];
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
