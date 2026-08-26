-- ELLSMS invoice VAT correction.
-- Business rule: every invoice carries 10% VAT.
-- Historical paid/refunded/cancelled/expired invoices are immutable and are NOT changed.
-- Only currently-issued (unpaid) invoices are recalculated so an invoice that has not yet been
-- settled cannot retain the old zero-tax configuration.

UPDATE ellsms_invoice_items ii
INNER JOIN ellsms_invoices i ON i.id = ii.invoice_id
SET
  ii.tax_amount = FLOOR(GREATEST(0, (ii.unit_price * ii.quantity) - ii.discount_amount) * 10 / 100),
  ii.line_total = GREATEST(0, (ii.unit_price * ii.quantity) - ii.discount_amount)
                  + FLOOR(GREATEST(0, (ii.unit_price * ii.quantity) - ii.discount_amount) * 10 / 100)
WHERE i.status = 'issued';

UPDATE ellsms_invoices i
INNER JOIN (
  SELECT invoice_id,
         SUM(tax_amount) AS recalculated_tax,
         SUM(line_total) AS recalculated_total
  FROM ellsms_invoice_items
  GROUP BY invoice_id
) x ON x.invoice_id = i.id
SET
  i.tax_amount = x.recalculated_tax,
  i.total_amount = x.recalculated_total
WHERE i.status = 'issued';

-- Gateway verification must use the exact final invoice amount.
UPDATE ellsms_payments p
INNER JOIN ellsms_invoices i ON i.payment_id = p.id
SET p.amount_rial = i.total_amount
WHERE i.status = 'issued'
  AND p.status <> 'paid';
