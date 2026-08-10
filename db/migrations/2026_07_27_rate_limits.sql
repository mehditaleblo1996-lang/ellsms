-- Phase 2 — lightweight, database-backed rate limiting (no Redis, per
-- explicit instruction). One row per attempt; rate_limit_hit()
-- (app/rate_limit.php) counts rows for a "bucket" within a trailing
-- window and opportunistically deletes old rows for that same bucket,
-- so the table stays small without needing a separate cron job.
--
-- Works correctly even if the app is ever scaled to multiple
-- containers, unlike an in-process counter would — it's the same MySQL
-- database every other ELLSMS feature already depends on.
--
-- Idempotent/guarded like every other migration in this project.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_rate_limits (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(191) NOT NULL,  -- e.g. "login:ip:1.2.3.4" or "2fa_verify:user:42"
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
