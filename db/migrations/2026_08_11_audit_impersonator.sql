-- Admin support impersonation — record the REAL actor alongside the effective one
-- (docs/admin-impersonation.md).
--
-- One additive nullable column on the existing ellsms_audit_log. Nothing else changes: no existing
-- row is modified, no column is dropped or renamed, and every historical row simply keeps NULL —
-- which reads correctly as "this action was not performed through an impersonation", because before
-- this feature existed none of them were.
--
-- WHY THE COLUMN IS NEEDED. ellsms_audit_log.user_id answers "whose account did this happen to",
-- and dozens of call sites across the codebase already pass the right value for that. During a
-- support impersonation that answer is still correct — the action really did happen to the
-- customer's account — but on its own it is now MISLEADING, because it makes a platform
-- administrator's support activity indistinguishable from the customer acting alone. That is
-- exactly the attribution Invariant D/E forbids losing.
--
-- Rather than change what user_id means (which would break every existing report and every historical
-- row's interpretation), the real human is recorded beside it. app/bootstrap.php's audit() fills this
-- in automatically from the session, so no call site had to change and none can forget.
--
-- NULL is the normal case: ordinary sessions, cron jobs, workers and public-API requests never
-- impersonate, and none of them can — impersonation state lives only in a browser session.
--
-- No foreign key, matching every other reference to a backend-owned `user_` id in this schema
-- (docs/database-audit.md): ELLSMS does not own that table and does not constrain it.
--
-- Guarded ALTER (information_schema check first), consistent with every prior migration here.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_audit_log' AND column_name = 'impersonator_user_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_audit_log
     ADD COLUMN impersonator_user_id BIGINT NULL AFTER user_id,
     ADD KEY idx_impersonator (impersonator_user_id, created_at)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
