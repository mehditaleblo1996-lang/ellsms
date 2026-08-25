-- BACKEND-OWNED TABLE — run this through the backend DB/schema owner, not ELLSMS migrations.
-- ELLSMS deliberately does not migrate outbound_message (see docs/service-boundaries.md).
--
-- Production slow log on 2026-08-25 showed global date-range/dashboard queries examining ~401k rows.
-- User-scoped indexes already exist in that deployment:
--   (sender_user_id, sent_at, id)
--   (sender_user_id, id)
-- This complementary index serves admin/global date-range queries that do not constrain sender_user_id.

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'outbound_message'
    AND index_name = 'idx_outbound_sent_id'
);

SET @sql = IF(
  @idx_exists = 0,
  'ALTER TABLE outbound_message ADD INDEX idx_outbound_sent_id (sent_at, id)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
