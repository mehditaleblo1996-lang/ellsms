-- #43 — regional bulk SMS through Vesal's /backend/bulk API (app/RegionalBulk.php).
-- ELLSMS never sees or stores subscriber numbers: it asks Vesal to count and send to "everyone in
-- province/city/postal code/prefix X". One row per request; money is reserved at confirmation and
-- settled on what Vesal reports as sent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_regional_bulk_requests (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id     INT UNSIGNED NULL,
  user_id             BIGINT NOT NULL,
  criteria_json       JSON NOT NULL,
  originator          VARCHAR(20) NOT NULL,
  content             TEXT NOT NULL,
  scheduled_at        DATETIME NULL,
  user_supplied_id    BIGINT UNSIGNED NULL,
  vesal_reference_id  BIGINT UNSIGNED NULL,
  estimated_count     INT UNSIGNED NULL,
  vesal_price         DECIMAL(16,2) NULL,
  charged_credits     INT UNSIGNED NULL,
  settled_credits     INT UNSIGNED NULL,
  status              ENUM('draft','priced','confirmed','sending','done','failed','cancelled') NOT NULL DEFAULT 'draft',
  vesal_status        TINYINT NULL,
  total_request       INT NULL,
  total_sent          INT NULL,
  total_delivered     INT NULL,
  error               VARCHAR(500) NULL,
  confirmed_at        TIMESTAMP NULL,
  last_polled_at      TIMESTAMP NULL,
  finished_at         TIMESTAMP NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_supplied (user_supplied_id),
  KEY idx_org_created (organization_id, created_at),
  KEY idx_status_polled (status, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Regional bulk is promotional mass messaging: require an approved KYC by default. Platform admins can
-- change it on the KYC gates page like any other gate (existing value, if any, is kept).
INSERT INTO ellsms_settings (skey, svalue) VALUES ('kyc_gate.regional_bulk', '1')
  ON DUPLICATE KEY UPDATE skey = skey;
