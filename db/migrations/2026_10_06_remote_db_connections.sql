-- #45 — Customer database connector ("Remote DB"): ELLSMS reads a customer's own outbound table,
-- sends every row through that customer's account (wallet, pricing, quota, content policy, bulk
-- queue — nothing is bypassed), and writes the send/delivery result back to the same row and the
-- customer's received messages into their inbound table. See docs/remote-db.md.
--
-- ADDITIVE AND INERT: three new ELLSMS-owned tables plus one nullable column on ellsms_bulk_items.
-- Nothing reads them until an admin creates a connection on /admin/remote-db and the
-- remote-db-worker container runs. Idempotent (every DDL is guarded), so a re-run is a no-op.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_remote_db_connections (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(120) NOT NULL,
  user_id               BIGINT NOT NULL,                 -- the account every row is sent as
  organization_id       BIGINT NULL,                     -- resolved at save time, like a bulk job's
  enabled               TINYINT(1) NOT NULL DEFAULT 0,   -- admin pause/resume
  driver                ENUM('mysql','pgsql','sqlsrv') NOT NULL,
  host                  VARCHAR(190) NOT NULL,
  port                  INT UNSIGNED NOT NULL,
  database_name         VARCHAR(128) NOT NULL,
  username              VARCHAR(128) NOT NULL,
  -- Password: AES-256-GCM under a key derived (HKDF, own purpose string) from SMS_GATEWAY_MASTER_KEY.
  -- Never stored or shown in clear; the panel field is write-only.
  password_ciphertext   BLOB NULL,
  password_nonce        VARBINARY(24) NULL,
  password_tag          VARBINARY(16) NULL,
  key_fingerprint       CHAR(16) NOT NULL DEFAULT '',
  tls_mode              ENUM('disable','prefer','require','verify') NOT NULL DEFAULT 'prefer',
  connect_timeout_s     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  query_timeout_s       SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  -- What to do: each part can be switched off on its own.
  send_enabled          TINYINT(1) NOT NULL DEFAULT 1,
  status_writeback      TINYINT(1) NOT NULL DEFAULT 1,
  inbound_enabled       TINYINT(1) NOT NULL DEFAULT 0,
  default_originator    VARCHAR(20) NOT NULL DEFAULT '',  -- used when a row has no / an empty sender column
  message_class         VARCHAR(32) NOT NULL DEFAULT 'bulk_campaign',
  batch_size            SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  poll_interval_s       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  status_sync_interval_s SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  delivery_wait_hours   SMALLINT UNSIGNED NOT NULL DEFAULT 72,  -- stop following a row's delivery after this
  retry_hours           SMALLINT UNSIGNED NOT NULL DEFAULT 24,  -- give up on a row that could not be queued
  -- Table/column/value mapping (validated JSON, see app/RemoteDb/Config.php). Identifiers only — no SQL.
  outbound_mapping_json JSON NULL,
  status_values_json    JSON NULL,
  inbound_mapping_json  JSON NULL,
  config_version        INT UNSIGNED NOT NULL DEFAULT 1,
  -- Inbound write-back cursors: one per inbound store (backend inbound_message / ellsms_inbound_messages).
  inbound_backend_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  inbound_ellsms_cursor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- Scheduling + single-worker lease.
  next_run_at           DATETIME NULL,
  next_status_sync_at   DATETIME NULL,
  lease_owner           VARCHAR(64) NULL,
  lease_until           DATETIME NULL,
  -- Health, shown on the panel.
  last_run_at           DATETIME NULL,
  last_success_at       DATETIME NULL,
  last_error            VARCHAR(500) NULL,
  last_error_at         DATETIME NULL,
  consecutive_failures  INT UNSIGNED NOT NULL DEFAULT 0,
  last_test_at          DATETIME NULL,
  last_test_result      VARCHAR(500) NULL,
  created_by            BIGINT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_remote_db_due (enabled, next_run_at),
  KEY idx_remote_db_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per customer row ELLSMS has taken. UNIQUE(connection_id, remote_row_id) is what makes a
-- customer row impossible to send twice: a row already here is never queued again, whatever state
-- the customer's own status column is put back into.
CREATE TABLE IF NOT EXISTS ellsms_remote_db_rows (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connection_id      INT UNSIGNED NOT NULL,
  remote_row_id      VARCHAR(100) NOT NULL,
  state              ENUM('claimed','queued','failed','done') NOT NULL DEFAULT 'claimed',
  destination        VARCHAR(20) NULL,
  originator         VARCHAR(20) NULL,
  parts              SMALLINT UNSIGNED NULL,
  job_id            INT UNSIGNED NULL,
  bulk_item_id       BIGINT UNSIGNED NULL,
  error_code         VARCHAR(60) NULL,
  queue_attempts     INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at    DATETIME NULL,                    -- a 'claimed' row that could not be queued yet retries from here
  -- '<item status>|<delivery status>' last written to the customer row; a write happens only when it moves.
  written_signature  VARCHAR(80) NOT NULL DEFAULT '',
  written_at         DATETIME NULL,
  final              TINYINT(1) NOT NULL DEFAULT 0,
  claimed_at         DATETIME NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_remote_row (connection_id, remote_row_id),
  KEY idx_remote_rows_open (connection_id, final, id),
  KEY idx_remote_rows_state (connection_id, state, claimed_at),
  KEY idx_remote_rows_created (connection_id, created_at),
  CONSTRAINT fk_remote_rows_connection FOREIGN KEY (connection_id) REFERENCES ellsms_remote_db_connections(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operational log shown on the panel (connection errors, refused rows, backoffs). Pruned by the worker.
CREATE TABLE IF NOT EXISTS ellsms_remote_db_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connection_id  INT UNSIGNED NOT NULL,
  level          ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  event          VARCHAR(60) NOT NULL,
  detail         VARCHAR(1000) NOT NULL DEFAULT '',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_remote_events (connection_id, id),
  KEY idx_remote_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links a queued bulk item to the ellsms_remote_db_rows row it was made from, written in the SAME
-- transaction that creates the item (bulk_queue_job()), so a crash can never leave an item that the
-- connector does not know about — the case that would otherwise end in a duplicate send.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'source_ref'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items
     ADD COLUMN source_ref BIGINT UNSIGNED NULL,
     ADD KEY idx_bulk_items_source_ref (source_ref)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
