-- Saman Electronic Payment (SEP / پرداخت الکترونیک سامان). docs/sep-payment.md.
--
-- gateway_resnum is the ResNum of the current attempt. A retry of the same payment row gets a new
-- one; SEP uses it to refuse a repeated purchase and echoes it on the callback.
-- gateway_ref is the RefNum (digital receipt). SEP verifies the same RefNum again on a second
-- call, so the UNIQUE key — not the bank — is what stops one receipt from crediting two payments.
--
-- Both columns are nullable. Existing ZarinPal rows stay NULL and are unaffected.

SET @col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'gateway_resnum'
);
SET @sql = IF(@col = 0,
  'ALTER TABLE ellsms_payments ADD COLUMN gateway_resnum VARCHAR(40) NULL AFTER authority',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'gateway_ref'
);
SET @sql = IF(@col = 0,
  'ALTER TABLE ellsms_payments ADD COLUMN gateway_ref VARCHAR(64) NULL AFTER gateway_resnum',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uniq = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND constraint_name = 'uniq_payment_gateway_resnum'
);
SET @dupes = (
  SELECT COUNT(*) FROM (
    SELECT gateway_resnum FROM ellsms_payments
    WHERE gateway_resnum IS NOT NULL
    GROUP BY gateway_resnum HAVING COUNT(*) > 1
  ) d
);
SET @sql = IF(@uniq = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_payments ADD CONSTRAINT uniq_payment_gateway_resnum UNIQUE (gateway_resnum)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uniq = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND constraint_name = 'uniq_payment_gateway_ref'
);
SET @dupes = (
  SELECT COUNT(*) FROM (
    SELECT gateway_ref FROM ellsms_payments
    WHERE gateway_ref IS NOT NULL
    GROUP BY gateway_ref HAVING COUNT(*) > 1
  ) d
);
SET @sql = IF(@uniq = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_payments ADD CONSTRAINT uniq_payment_gateway_ref UNIQUE (gateway_ref)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
