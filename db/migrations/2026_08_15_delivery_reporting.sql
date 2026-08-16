-- Delivery runtime & reporting closure — raw provider status on bulk items.
--
-- THE GAP THIS CLOSES. ellsms_message_attempts already has `provider_status` (added by
-- 2026_08_14_gateway_direct_send_results.sql) but ellsms_bulk_items never did, so the SAME poller
-- writing through the SAME gateway_status_record() could preserve a provider's raw token for a
-- direct send and had nowhere to put it for a bulk recipient. That asymmetry is a reporting hole,
-- not a behavioural one: the canonical delivery_status was always recorded correctly on both.
--
-- WHY THE RAW TOKEN IS WORTH A COLUMN. `delivery_status` is the MAPPED state, and an unmapped
-- provider token maps to `unknown` by design (gateway_status_map() never guesses `delivered`).
-- Without the raw token an operator sees a row stuck at `unknown` with no way to discover WHICH
-- token is missing from the mapping. With it, "provider said 2, we stored unknown" is a two-second
-- diagnosis and a one-line configuration fix.
--
-- Deliberately NOT done: no backfill. A historical row's raw token was never captured and cannot be
-- reconstructed — inventing one from today's mapping would run the mapping backwards and produce a
-- value the provider may never have sent. Pre-existing rows keep NULL and the report shows them as
-- unavailable, which is the truth (B10/B4 policy: leave it clearly unavailable rather than invent).
--
-- Additive, rerun-safe (information_schema guard), fresh-DB safe, and backup/restore safe: an
-- ordinary nullable column, no generated columns (TD-070).
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'provider_status'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD COLUMN provider_status VARCHAR(60) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Route/operator affinity for bulk recipients.
--
-- The status poller already reads ma.route_id/ma.operator_id for direct sends and selects literal
-- NULLs for bulk items (app/Sms/GatewayStatus.php), which means a batch-capable connector cannot
-- group bulk rows by their route/operator override set the way it groups direct sends. Recording
-- what the send ACTUALLY used also lets the recipient table show a per-row operator without
-- re-resolving today's configuration (B16: reports describe what happened, not what would happen
-- now). Nullable, so every pre-existing row is simply "not recorded".
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'route_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items
     ADD COLUMN route_id INT UNSIGNED NULL,
     ADD COLUMN operator_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Report lookup path: "every attempt for this reference", which is how the message detail page
-- resolves a direct send and how the recipient table resolves a bulk job's own attempts. Without
-- this the detail page falls back to a scan on a table that grows with every send.
--
-- idx_attempt_delivery_polling (delivery_status, delivery_checked_at) already covers the poller's
-- own selection, so this adds the ONE access path that had none rather than speculatively indexing
-- every filterable column (B20: only add indexes the query plans justify).
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts'
    AND index_name = 'idx_attempt_reference'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD KEY idx_attempt_reference (reference_type, reference_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
