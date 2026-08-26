-- Admin control over whether an unpaid invoice may be paid by the customer.
-- Payment/accounting status remains in `status`; this is an independent operational gate.
ALTER TABLE ellsms_invoices
    ADD COLUMN admin_state ENUM('approved','disabled') NOT NULL DEFAULT 'approved' AFTER status,
    ADD COLUMN admin_note VARCHAR(500) NULL AFTER admin_state,
    ADD COLUMN admin_reviewed_by BIGINT NULL AFTER admin_note,
    ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by,
    ADD KEY idx_invoices_admin_state (admin_state);
