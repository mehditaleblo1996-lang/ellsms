-- «پیش‌نمایش، زمان‌بندی و بازگشت تغییر تعرفه» — app/PriceChanges.php, docs/price-changes.md.
--
-- Every save of a price matrix (the global «تعرفه‌ی درگاه‌ها» and each account's «تعرفه‌ی اختصاصی»,
-- ellsms_gateway_prices) is now one row here: the cells it sets, the values it replaced, when it takes
-- effect and what its preview estimated. A change saved for a later time waits here as 'scheduled'
-- until the scheduler job `price-changes` applies it; an applied change can be reverted in one click
-- (a new change that puts the replaced values back).
--
-- ellsms_price_usage_profile is the last 30 days of charged sends, grouped by account, sending line,
-- destination operator and encoding, that the preview prices twice (today's prices and the proposed
-- ones) to estimate how many customers a change touches and how much it moves monthly revenue. It is
-- rebuilt daily by the scheduler job `price-usage-profile` (and on demand from the preview).
--
-- Additive and rerun-safe. Without it the matrices still save exactly as before (no preview, no
-- scheduling, no history).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_price_changes (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_slot             BIGINT NOT NULL DEFAULT 0,          -- 0 = the global matrix, else the account (ellsms_gateway_prices.user_slot)
  status                ENUM('scheduled','applied','cancelled','reverted','failed') NOT NULL,
  cells_json            MEDIUMTEXT NOT NULL,                -- {"gateway|column|encoding": millicredits | null (cleared)}
  before_json           MEDIUMTEXT NULL,                    -- the values the change replaced, written when it is applied
  effective_at          DATETIME NOT NULL,                  -- UTC: when it takes (or took) effect
  impact_json           MEDIUMTEXT NULL,                    -- the preview's estimate when it was saved (price_change_impact())
  reverts_change_id     BIGINT UNSIGNED NULL,               -- set on a change that undoes another
  reverted_by_change_id BIGINT UNSIGNED NULL,               -- set on a change that was undone
  note                  VARCHAR(500) NULL,                  -- why a scheduled change failed, or which cells a revert skipped
  created_by            BIGINT NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at            DATETIME NULL,                      -- UTC
  cancelled_by          BIGINT NULL,
  cancelled_at          DATETIME NULL,                      -- UTC
  KEY idx_price_change_due (status, effective_at),
  KEY idx_price_change_slot (user_slot, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_price_usage_profile (
  user_id         BIGINT NOT NULL,
  sender          VARCHAR(32) NOT NULL DEFAULT '',
  operator_key    VARCHAR(24) NOT NULL,                     -- operator id, or "p" + the number's first digits when none was recorded
  encoding        ENUM('fa','en') NOT NULL,
  sample_mobile   VARCHAR(20) NOT NULL,                     -- one real recipient of the group, priced as its stand-in
  message_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  part_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  charged_credits BIGINT NOT NULL DEFAULT 0,                -- what those messages were charged, where it is recorded
  PRIMARY KEY (user_id, sender, operator_key, encoding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
