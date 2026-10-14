-- Per-line forwarding of inbound SMS (MO) and delivery reports (DLR) to a customer's own URL
-- (app/NumberForwarding.php, cron/number-forward-worker.php, public/number-forwards.php).
--
-- The same feature the Vesal platform has on every number (its receiveUrl / deliveryUrl): the owner
-- of a line, or a platform admin, sets an http(s) URL template such as
--   https://example.com/sms/receive?from=${originator}&to=${destination}&text=${content}
-- and ELLSMS calls it for every message received on that line / every final delivery report of a
-- message sent from it. See docs/number-forwarding.md for the placeholders.
--
-- Keyed by the line STRING (not ellsms_numbers.id), like the opt-out list and the pricing routes: a
-- line can also be an account's legacy ellsms_meta.originator, which has no numbers row.
-- Guarded by IF NOT EXISTS, so re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. One row per line: where its MO and its DLR go, and whether each is switched on.
--    The last_* / consecutive_failures columns are written by the worker and shown on the page.
CREATE TABLE IF NOT EXISTS ellsms_number_forwards (
  number               VARCHAR(20) NOT NULL PRIMARY KEY,
  mo_enabled           TINYINT(1) NOT NULL DEFAULT 0,
  mo_url               VARCHAR(2048) NOT NULL DEFAULT '',
  dlr_enabled          TINYINT(1) NOT NULL DEFAULT 0,
  dlr_url              VARCHAR(2048) NOT NULL DEFAULT '',
  http_method          ENUM('GET','POST') NOT NULL DEFAULT 'GET',
  post_format          ENUM('form','json') NOT NULL DEFAULT 'form',
  updated_by           BIGINT NULL,                 -- = user_.id of the last editor
  last_success_at      TIMESTAMP NULL DEFAULT NULL,
  last_failure_at      TIMESTAMP NULL DEFAULT NULL,
  last_error           VARCHAR(255) NULL,
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. The outbox: one row per message to forward.
--    source_key identifies the message it came from ('inbound:<id>', 'attempt:<id>',
--    'bulk_item:<id>'), and UNIQUE(kind, source_key) makes every enqueue exactly-once even though
--    the inbound scan is at-least-once.
--    vars_json holds the placeholder values; the URL is rendered from the line's CURRENT template
--    at delivery time, so fixing a broken URL also fixes the rows still waiting to be retried.
--    status: pending -> processing -> delivered | failed (permanent) | dead (attempts exhausted)
--            | cancelled (forwarding switched off before it went out)
CREATE TABLE IF NOT EXISTS ellsms_number_forward_queue (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind             ENUM('mo','dlr') NOT NULL,
  number           VARCHAR(20) NOT NULL,
  source_key       VARCHAR(80) NOT NULL,
  vars_json        JSON NOT NULL,
  status           ENUM('pending','processing','delivered','failed','dead','cancelled') NOT NULL DEFAULT 'pending',
  attempt_count    INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at  TIMESTAMP NULL DEFAULT NULL,
  claimed_by       VARCHAR(120) NULL,
  lease_expires_at TIMESTAMP NULL DEFAULT NULL,
  http_status      SMALLINT UNSIGNED NULL,
  error_code       VARCHAR(80) NULL,
  response_excerpt VARCHAR(1024) NULL,
  duration_ms      INT UNSIGNED NULL,
  last_url         VARCHAR(2048) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at     TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_kind_source (kind, source_key),
  KEY idx_status_next (status, next_attempt_at),
  KEY idx_claimed (claimed_by),
  KEY idx_number_created (number, created_at),
  KEY idx_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
