-- AI engines: provider credit balance and low-balance alerts (docs/ai-engines.md §Provider balance
-- and alerts, app/Ai/AiBalance.php, cron/ai-balance-check.php).
--
-- AI providers sell prepaid credit (USD, or Rial on an Iranian gateway), not tokens. Every 15
-- minutes the scheduler job `ai-balance` works out what is left on each provider and how many days
-- that lasts at the last 7 days' spend, and raises an alert (app/Alerting/AlertManager.php: Telegram
-- + email) when it is low:
--   warning   — 7 days or less left (or below warn_amount, if set);
--   critical  — 2 days or less left, or 1 USD or less;
--   emergency — the provider itself refused a call for lack of credit (HTTP 402, insufficient_quota,
--               "credit balance is too low" ...), raised the moment it happens.
--
-- Requires 2026_11_03_ai_engines.sql. Nothing outside these two new tables is altered. Guarded by
-- IF NOT EXISTS, so re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. One row per provider (created on first check). balance is the last known credit, in currency:
--    source 'api' = read from the provider (OpenRouter's /key and /credits), 'ledger' = estimated
--    from the admin's recorded top-ups minus the purchase cost of every answer and test since.
--    daily_burn is the average spend per day over the last 7 days, days_left = balance / daily_burn.
--    exhausted_at is set when the provider refuses a call for lack of credit, and cleared by a
--    recorded top-up, a positive API balance, or a successful call after it.
--    The four thresholds are per provider; a NULL one is not checked. critical_amount defaults to 1
--    for a USD provider.
CREATE TABLE IF NOT EXISTS ellsms_ai_provider_balance (
  provider_id      INT UNSIGNED NOT NULL PRIMARY KEY,
  currency         CHAR(3) NOT NULL DEFAULT 'USD',
  balance          DECIMAL(20,6) NULL,
  balance_source   ENUM('api','ledger') NULL,
  balance_at       TIMESTAMP NULL DEFAULT NULL,
  daily_burn       DECIMAL(20,6) NULL,
  days_left        DECIMAL(10,2) NULL,
  level            ENUM('unknown','ok','warning','critical','emergency') NOT NULL DEFAULT 'unknown',
  warn_days        DECIMAL(6,2) NULL DEFAULT 7.00,
  critical_days    DECIMAL(6,2) NULL DEFAULT 2.00,
  warn_amount      DECIMAL(20,6) NULL,
  critical_amount  DECIMAL(20,6) NULL,
  alerts_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  exhausted_at     TIMESTAMP NULL DEFAULT NULL,
  exhausted_message VARCHAR(500) NULL,
  last_checked_at  TIMESTAMP NULL DEFAULT NULL,
  last_check_error VARCHAR(500) NULL,
  updated_by       BIGINT NULL,                    -- = user_.id
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_balance_provider FOREIGN KEY (provider_id) REFERENCES ellsms_ai_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. What the admin recorded: 'topup' = "I charged the account by this much" (added to the
--    estimate), 'set' = "the provider's panel shows exactly this now" (the estimate restarts from it).
--    Only rows in the provider's current currency count.
CREATE TABLE IF NOT EXISTS ellsms_ai_provider_topups (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED NOT NULL,
  kind        ENUM('topup','set') NOT NULL,
  amount      DECIMAL(20,6) NOT NULL,
  currency    CHAR(3) NOT NULL,
  note        VARCHAR(255) NULL,
  created_by  BIGINT NULL,                         -- = user_.id
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_provider (provider_id, id),
  CONSTRAINT fk_ai_topup_provider FOREIGN KEY (provider_id) REFERENCES ellsms_ai_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
