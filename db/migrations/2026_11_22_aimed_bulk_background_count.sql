-- Aimed bulk counts run in the background on covering indexes (app/AimedBulk.php, docs/aimed-bulk.md).
--
-- «شمارش شماره‌ها» used to COUNT(*) the matching numbers inside the web request. On a bank of tens of
-- millions, a prefix such as 0912 made MySQL either look up every matching row in the table to check
-- `blocklisted` (uniq_mobile has no other column) or walk most of the table, and the page hit the
-- proxy timeout (Cloudflare 524). Two changes:
--
--   1. Covering indexes on ellsms_aimed_mobiles. Each index holds every column a filter can use
--      (blocklisted, postal_code / province_code / city_code, sim_type, mobile), so a count or a
--      target snapshot reads only the index and never the table rows:
--        idx_prefix        (blocklisted, mobile, sim_type)                               prefix [+ SIM]
--        idx_postal_cover  (blocklisted, postal_code, sim_type, mobile)                  postal [+ SIM, prefix]
--        idx_place_cover   (blocklisted, province_code, city_code, sim_type, mobile)     province [+ city, SIM, prefix]
--        idx_city_cover    (blocklisted, city_code, sim_type, mobile)                    city [+ SIM, prefix]
--      They replace idx_postal, idx_province and idx_city, which are the leading columns of the new
--      ones. Added and dropped in ONE in-place ALTER (one pass over the table, no lock: imports and
--      sends keep running). On a large bank this takes minutes and needs free disk for the new
--      indexes (roughly 40 bytes per number per index) while it runs.
--
--   2. ellsms_aimed_counts: one row per counted criteria set. The page queues a count and returns at
--      once; the worker (aimed_bulk_count_pass() in cron/worker.php) counts it, only up to the
--      per-request limit, and the page polls for the result. A finished count is reused for an hour,
--      and the importer clears the table after every load.
--
-- Guarded by information_schema checks, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @has_table = (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles');
SET @has_prefix  = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_prefix');
SET @has_postal2 = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_postal_cover');
SET @has_place2  = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_place_cover');
SET @has_city2   = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_city_cover');
SET @has_postal  = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_postal');
SET @has_prov    = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_province');
SET @has_city    = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_mobiles' AND index_name = 'idx_city');

-- CONCAT_WS skips the NULLs, so only the missing / leftover indexes end up in the statement.
SET @changes = CONCAT_WS(', ',
  IF(@has_prefix  = 0, 'ADD INDEX idx_prefix (blocklisted, mobile, sim_type)', NULL),
  IF(@has_postal2 = 0, 'ADD INDEX idx_postal_cover (blocklisted, postal_code, sim_type, mobile)', NULL),
  IF(@has_place2  = 0, 'ADD INDEX idx_place_cover (blocklisted, province_code, city_code, sim_type, mobile)', NULL),
  IF(@has_city2   = 0, 'ADD INDEX idx_city_cover (blocklisted, city_code, sim_type, mobile)', NULL),
  IF(@has_postal  > 0, 'DROP INDEX idx_postal', NULL),
  IF(@has_prov    > 0, 'DROP INDEX idx_province', NULL),
  IF(@has_city    > 0, 'DROP INDEX idx_city', NULL)
);
SET @sql = IF(@has_table = 0 OR @changes IS NULL OR @changes = '',
  'SELECT 1',
  CONCAT('ALTER TABLE ellsms_aimed_mobiles ', @changes, ', ALGORITHM=INPLACE, LOCK=NONE'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_aimed_counts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criteria_hash     CHAR(64) NOT NULL,
  criteria_json     JSON NOT NULL,
  cap               INT UNSIGNED NOT NULL,
  status            ENUM('queued','counting','done','failed') NOT NULL DEFAULT 'queued',
  matched           INT UNSIGNED NULL,
  error             VARCHAR(500) NULL,
  claimed_by        VARCHAR(80) NULL,
  lease_expires_at  DATETIME NULL,
  requested_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  counted_at        TIMESTAMP NULL,
  UNIQUE KEY uniq_criteria (criteria_hash),
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
