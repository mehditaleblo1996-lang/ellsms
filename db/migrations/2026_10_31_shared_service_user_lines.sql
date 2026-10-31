-- Shared sending service: which pool lines a customer's messages go through (app/SharedService.php,
-- docs/shared-service.md «خط هر کاربر»). A platform admin ticks pool lines per customer; a customer with
-- an assigned line of a kind is sent only through their assigned, enabled lines of that kind. A customer
-- with none of a kind uses the whole pool of that kind, as before. The customer never sees which line.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_shared_user_lines (
  user_id     BIGINT NOT NULL,
  line_id     INT UNSIGNED NOT NULL,
  created_by  BIGINT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (user_id, line_id),
  KEY idx_shared_user_line (line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
