-- Shared sending service: named (private) lines (app/SharedService.php, docs/shared-service.md
-- «خط اختصاصی با اسم»).
--
-- ellsms_shared_lines.name: NULL = a general pool line, used by every customer (the default route).
--   A name (e.g. «تاجماهی») makes the line private: no customer sees or sends through it until a platform
--   admin ticks it for them under «خط هر کاربر». Several lines may share a name (one name, a few
--   providers, with the usual priority and fail-over). The customer sees the name, never the number.
-- ellsms_shared_messages.line_name: the named line a customer picked for the message; NULL = general.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_shared_lines' AND column_name = 'name'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_shared_lines ADD COLUMN name VARCHAR(60) NULL AFTER kind', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_shared_messages' AND column_name = 'line_name'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_shared_messages ADD COLUMN line_name VARCHAR(60) NULL AFTER kind', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
