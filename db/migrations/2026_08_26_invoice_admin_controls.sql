-- Admin control over whether an unpaid invoice may be paid by the customer.
-- Payment/accounting status remains in `status`; this is an independent operational gate.
--
-- Guarded (information_schema check + PREPARE/EXECUTE), matching every other migration in this
-- codebase (see db/ellsms_extra.sql) — a plain unguarded ALTER TABLE here broke the moment schema
-- was ever applied twice against the same database, which subprocess-spawning integration tests
-- (WebhookDeliveryTest, WalletConcurrencyTest, etc. — each a fresh PHP process that reruns
-- IntegrationTestCase::ensureSchemaLoaded()) do routinely against the one shared test database.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_invoices' AND column_name = 'admin_state'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_invoices
     ADD COLUMN admin_state ENUM(''approved'',''disabled'') NOT NULL DEFAULT ''approved'' AFTER status,
     ADD COLUMN admin_note VARCHAR(500) NULL AFTER admin_state,
     ADD COLUMN admin_reviewed_by BIGINT NULL AFTER admin_note,
     ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by,
     ADD KEY idx_invoices_admin_state (admin_state)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
