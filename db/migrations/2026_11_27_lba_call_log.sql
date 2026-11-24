-- LBA (PishgamRayan smart SMS): the failed provider calls an admin reads in «جزئیات فنی» on
-- /messages/lba — docs/lba.md «Technical details».
--
-- One row per call that did not succeed: what was sent (method, path, JSON body; never the token)
-- and what came back (HTTP status, statusCode, the raw answer). PishgamRayan often answers only
-- «ناموفق.» / «خطا», so the exact request is what its support needs. lba_debug_record() keeps the
-- newest 50 rows of each gateway and deletes older ones as it writes. Rerun-safe.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_lba_call_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,
  http_method   VARCHAR(8) NOT NULL,
  method        VARCHAR(80) NOT NULL,
  query_json    TEXT NULL,
  body_json     MEDIUMTEXT NULL,
  http_status   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status_code   INT NULL,
  error         VARCHAR(1000) NULL,
  response_raw  MEDIUMTEXT NULL,
  duration_ms   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gateway (gateway_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
