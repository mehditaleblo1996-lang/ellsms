-- TD-024 — ellsms_contacts: one row per (user_id, mobile, group_name).
--
-- WHAT WAS WRONG. Nothing stopped the same contact being stored twice, so pasting the same list
-- into Contacts → import a second time silently doubled every row (and each copy counted against
-- the plan's contact limit). Sends were never affected — every send path already de-duplicates its
-- destination list — this is about the stored list itself.
--
-- THE SHAPE. (user_id, mobile, group_name): a number may still appear in several groups (a group
-- is a column on the row, so membership in two groups IS two rows), but never twice in the same
-- group for the same owner. Plain columns only — no generated column, see TD-070 for why this
-- project does not use them (mysqldump/restore).
--
-- WHAT THIS MIGRATION DOES, in order:
--   1. copies every duplicate row it is about to remove into ellsms_contacts_dedupe_backup
--      (nothing is lost — each backed-up row records the id of the row that was kept);
--   2. if the kept (oldest) row has an empty name and a duplicate has one, keeps that name;
--   3. deletes the duplicates, keeping the oldest row of each group;
--   4. adds UNIQUE KEY uniq_contact_user_mobile_group.
--
-- SAFETY. Rows are only ever merged when they also share organization_id (NULL-safe), i.e. only
-- rows that are true copies of each other in the same visibility scope. If duplicates that differ
-- ONLY in organization_id exist, they are left alone and step 4 is skipped rather than guessing a
-- winner — `make db-integrity-check` keeps reporting them so they can be resolved by hand, and the
-- application code is correct with or without the index.
--
-- RERUN-SAFE. Once the index exists there are no duplicates for steps 1-3 to find, and step 4 is
-- guarded on the index's existence.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_contacts_dedupe_backup (
  id              INT UNSIGNED NOT NULL PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  organization_id INT UNSIGNED NULL,
  name            VARCHAR(120) NOT NULL,
  mobile          VARCHAR(20) NOT NULL,
  group_name      VARCHAR(80) NOT NULL DEFAULT '',
  created_at      TIMESTAMP NULL,
  kept_id         INT UNSIGNED NOT NULL,
  backed_up_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kept (kept_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1. Back up every row that step 3 will delete.
INSERT INTO ellsms_contacts_dedupe_backup (id, user_id, organization_id, name, mobile, group_name, created_at, kept_id)
SELECT c.id, c.user_id, c.organization_id, c.name, c.mobile, c.group_name, c.created_at, g.keep_id
FROM ellsms_contacts c
JOIN (
  SELECT user_id, organization_id, mobile, group_name, MIN(id) AS keep_id
  FROM ellsms_contacts
  GROUP BY user_id, organization_id, mobile, group_name
  HAVING COUNT(*) > 1
) g ON g.user_id = c.user_id
   AND g.organization_id <=> c.organization_id
   AND g.mobile = c.mobile
   AND g.group_name = c.group_name
   AND c.id <> g.keep_id
ON DUPLICATE KEY UPDATE kept_id = VALUES(kept_id);

-- 2. The kept row inherits a name only when it has none of its own (newest non-empty name wins).
UPDATE ellsms_contacts k
JOIN (
  SELECT user_id, organization_id, mobile, group_name, MIN(id) AS keep_id,
         MAX(CASE WHEN name <> '' THEN id END) AS named_id
  FROM ellsms_contacts
  GROUP BY user_id, organization_id, mobile, group_name
  HAVING COUNT(*) > 1
) g ON g.keep_id = k.id
JOIN ellsms_contacts n ON n.id = g.named_id
SET k.name = n.name
WHERE k.name = '';

-- 3. Remove the duplicates (each one is already in ellsms_contacts_dedupe_backup).
DELETE c FROM ellsms_contacts c
JOIN (
  SELECT user_id, organization_id, mobile, group_name, MIN(id) AS keep_id
  FROM ellsms_contacts
  GROUP BY user_id, organization_id, mobile, group_name
  HAVING COUNT(*) > 1
) g ON g.user_id = c.user_id
   AND g.organization_id <=> c.organization_id
   AND g.mobile = c.mobile
   AND g.group_name = c.group_name
   AND c.id <> g.keep_id;

-- 4. Enforce it — only if the index is missing AND nothing would violate it.
SET @uniq_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_contacts' AND index_name = 'uniq_contact_user_mobile_group'
);
SET @dupes = (
  SELECT COUNT(*) FROM (SELECT user_id, mobile, group_name FROM ellsms_contacts GROUP BY user_id, mobile, group_name HAVING COUNT(*) > 1) d
);
SET @sql = IF(@uniq_exists = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_contacts ADD UNIQUE KEY uniq_contact_user_mobile_group (user_id, mobile, group_name)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
