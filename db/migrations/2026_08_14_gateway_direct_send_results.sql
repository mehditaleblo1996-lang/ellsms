-- SMS gateway connector closure — direct-send provider identity and delivery status.
--
-- THE GAP THIS CLOSES. `provider_message_id` was recorded only on ellsms_bulk_items, so delivery
-- status could only ever be tracked for bulk sends. A direct send (the panel's ارسال سریع, a
-- schedule, an auto-reply, the legacy URL API) went out, got a provider id back, and threw it away —
-- which made generic status tracking structurally incomplete rather than merely unimplemented.
--
-- WHY THIS TABLE. ellsms_message_attempts is ALREADY the ELLSMS-owned record of what happened to a
-- direct send at the transport level (Phase 8, Invariant E: outbound_message is backend-owned and
-- ELLSMS must never fabricate rows in it). Until now it recorded only FAILURES, because a success was
-- always a real outbound_message row written by the backend. With a configured gateway that is no
-- longer true: the gateway answers ELLSMS directly, and nothing else in this system holds the
-- resulting provider id. Recording accepted sends here extends the table along its existing meaning
-- instead of inventing a second, contradictory message history.
--
-- Deliberately NOT done: no column added to any backend-owned table (user_, outbound_message,
-- inbound_message, domain), and no new message-history system. This is the same row shape, with a
-- second status value and the transport identity that was previously missing.
--
-- NO GENERATED COLUMNS (TD-070): mariadb-client mysqldump emits STORED GENERATED columns as ordinary
-- data, which makes the resulting dump unrestorable. Every column here is ordinary.
SET NAMES utf8mb4;

-- 1. Accepted sends may now be recorded, not only failures.
SET @needs_accepted = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts'
    AND column_name = 'status' AND column_type LIKE '%accepted%'
);
SET @sql = IF(@needs_accepted = 1,
  "ALTER TABLE ellsms_message_attempts
     MODIFY COLUMN status ENUM('failed','accepted') NOT NULL DEFAULT 'failed'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Transport identity and delivery state.
--
-- `destination` is stored per row because an accepted send is recorded ONE ROW PER DESTINATION: a
-- provider message id identifies one message to one recipient, and a shared row could not hold two of
-- them. `gateway_config_version` is what ties a delivery problem to the exact configuration that
-- produced it, which is otherwise guesswork after an admin edit.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'gateway_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_message_attempts
     ADD COLUMN gateway_id INT UNSIGNED NULL,
     ADD COLUMN gateway_config_version INT UNSIGNED NULL,
     ADD COLUMN route_id INT UNSIGNED NULL,
     ADD COLUMN operator_id INT UNSIGNED NULL,
     ADD COLUMN destination VARCHAR(32) NULL,
     ADD COLUMN provider_message_id VARCHAR(190) NULL,
     ADD COLUMN provider_status VARCHAR(60) NULL,
     ADD COLUMN delivery_status ENUM('accepted','queued','sent','delivered','failed','rejected','expired','unknown') NULL,
     ADD COLUMN delivery_checked_at TIMESTAMP NULL,
     ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN delivered_at TIMESTAMP NULL,
     ADD KEY idx_attempt_delivery_polling (delivery_status, delivery_checked_at),
     ADD KEY idx_attempt_gateway (gateway_id, provider_message_id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. One accepted record per (gateway, provider message id).
--
-- An application-maintained slot column rather than a UNIQUE on the pair directly: the pair is NULL
-- for every pre-existing failure row, and MySQL's UNIQUE treats each NULL as distinct, so the
-- constraint would be silently vacuous for exactly the rows it is meant to police. The slot is
-- written only for accepted rows, which makes a replayed send a duplicate-key error instead of a
-- second delivery record for one message.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'provider_slot'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_message_attempts
     ADD COLUMN provider_slot VARCHAR(220) NULL,
     ADD UNIQUE KEY uniq_attempt_provider_message (provider_slot)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. error_code is NOT NULL with no default and every accepted row legitimately has no error.
-- Giving it an explicit empty default keeps the accepted-row insert from having to pretend one.
SET @needs_default = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts'
    AND column_name = 'error_code' AND column_default IS NULL
);
SET @sql = IF(@needs_default > 0,
  "ALTER TABLE ellsms_message_attempts MODIFY COLUMN error_code VARCHAR(60) NOT NULL DEFAULT ''",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
