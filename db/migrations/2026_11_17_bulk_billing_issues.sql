-- «مغایرت مالی ارسال گروهی» — app/BulkBilling.php, docs/financial-log.md.
--
-- The financial log («تاریخچه مالی») shows one row per bulk send: the per-message charges stay in the
-- wallet ledger (delivery refunds and crash-replay protection key on them) and are summed for display.
-- So that a summed row can never hide a billing fault, every bulk job is checked when it finishes or is
-- cancelled: messages sent vs. messages charged, charged amount vs. the prices frozen on the messages,
-- and the job's reservation vs. charged + released. A job that does not add up gets one row here; the
-- financial log flags it («⚠ مغایرت», admins only), platform admins get a panel notification and the
-- support Telegram group a message.
--
-- Additive and rerun-safe. Without it the check still logs (bulk.billing.mismatch) and notifies, but
-- the financial log cannot show the flag.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_bulk_billing_issues (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id      BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT NOT NULL,
  details     VARCHAR(1000) NOT NULL,
  sent_count  INT UNSIGNED NOT NULL DEFAULT 0,
  charged_count INT UNSIGNED NOT NULL DEFAULT 0,
  expected_amount BIGINT NOT NULL DEFAULT 0,
  charged_amount  BIGINT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bulk_billing_issue_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
