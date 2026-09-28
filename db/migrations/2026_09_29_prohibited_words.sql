-- #40 — prohibited words in message content (app/ContentPolicy.php).
-- Admin-managed, platform-wide. A message whose text contains an active pattern is refused on every
-- send path before any credit is reserved. match_type 'contains' = anywhere in the text; 'word' =
-- only as a whole word. Patterns are stored as typed; matching normalizes both sides (Arabic ي/ك,
-- ZWNJ, tatweel, digits, case), so "كلمه" and "کلمه" are the same word.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_prohibited_words (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern     VARCHAR(190) NOT NULL,
  match_type  ENUM('contains','word') NOT NULL DEFAULT 'contains',
  active      TINYINT(1) NOT NULL DEFAULT 1,
  note        VARCHAR(190) NOT NULL DEFAULT '',
  created_by  BIGINT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pattern_type (pattern, match_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
