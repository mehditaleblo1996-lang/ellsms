-- Phase 5 — database integrity: ELLSMS-owned foreign keys and unique constraints, each preceded by
-- a self-contained validation check (docs/database-migrations.md, docs/database-audit.md).
--
-- Every ADD CONSTRAINT/ADD UNIQUE below is guarded THREE ways, not just the usual
-- "does it already exist" check every prior migration in this project uses:
--   1. Does the constraint already exist? (standard idempotency guard)
--   2. Does the CURRENT data actually satisfy it? (orphan/duplicate count computed first)
--   3. Only if both hold does the ALTER actually run — otherwise this file no-ops for that one
--      constraint and moves on, rather than either force-applying (which could error mid-deploy on
--      a production database with unknown history) or aborting the whole file (which would also
--      block every OTHER, unrelated, independently-safe constraint in this same file).
-- Run `make db-integrity-check` before applying this migration — it reports the exact same
-- counts these guards compute, so a skip here is never a silent surprise; see that command's own
-- output for which specific constraint didn't apply and why.
--
-- Every relationship below is between two ELLSMS-owned tables — none of this touches user_,
-- outbound_message, inbound_message, or domain (see docs/database-audit.md's "Do NOT blindly add
-- foreign keys to legacy/backend tables" section for why those are explicitly out of scope here).
--
-- Also drops one Phase 4 index confirmed unused by EXPLAIN against realistic row counts (STEP 9):
-- ellsms_bulk_items.idx_claim (job_id, status, next_attempt_at) was added anticipating a query
-- shape that Phase 4's own concurrency-bug fix (see docs/job-queue-architecture.md) ended up not
-- using — the final claim query hits idx_lease and the existing (job_id, status) index instead, so
-- idx_claim has been pure write overhead (extra index maintenance on every item insert/claim) since
-- Phase 4 shipped, with zero query ever benefiting from it. Nothing else changed about the claim
-- logic itself — this is an index removal, not a query change.
SET NAMES utf8mb4;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND index_name = 'idx_claim'
);
SET @sql = IF(@idx_exists > 0, 'ALTER TABLE ellsms_bulk_items DROP INDEX idx_claim', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_bulk_items.job_id -> ellsms_bulk_jobs.id
-- ON DELETE RESTRICT: no code path deletes ellsms_bulk_jobs rows today (confirmed by grep), so this
-- is a pure safety net against a future accidental delete orphaning items, not a behavior change.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND constraint_name = 'fk_bulk_items_job'
);
SET @orphans = (
  SELECT COUNT(*) FROM ellsms_bulk_items i LEFT JOIN ellsms_bulk_jobs j ON j.id = i.job_id WHERE j.id IS NULL
);
SET @sql = IF(@fk_exists = 0 AND @orphans = 0,
  'ALTER TABLE ellsms_bulk_items ADD CONSTRAINT fk_bulk_items_job FOREIGN KEY (job_id) REFERENCES ellsms_bulk_jobs(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_number_category_items.category_id -> ellsms_number_categories.id
-- ON DELETE CASCADE: a category_item has no meaning without its category (a true dependent
-- composition, not merely convenient) — and public/number-categories.php's own delete action
-- already manually deletes items before their category as two separate statements; this formalizes
-- that existing behavior as a DB-level guarantee/backstop rather than changing it.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_category_items' AND constraint_name = 'fk_category_items_category'
);
SET @orphans = (
  SELECT COUNT(*) FROM ellsms_number_category_items i
  LEFT JOIN ellsms_number_categories c ON c.id = i.category_id WHERE c.id IS NULL
);
SET @sql = IF(@fk_exists = 0 AND @orphans = 0,
  'ALTER TABLE ellsms_number_category_items ADD CONSTRAINT fk_category_items_category FOREIGN KEY (category_id) REFERENCES ellsms_number_categories(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_ticket_replies.ticket_id -> ellsms_tickets.id
-- ON DELETE RESTRICT: no code path deletes ellsms_tickets rows today — same reasoning as bulk_items.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_ticket_replies' AND constraint_name = 'fk_ticket_replies_ticket'
);
SET @orphans = (
  SELECT COUNT(*) FROM ellsms_ticket_replies r LEFT JOIN ellsms_tickets t ON t.id = r.ticket_id WHERE t.id IS NULL
);
SET @sql = IF(@fk_exists = 0 AND @orphans = 0,
  'ALTER TABLE ellsms_ticket_replies ADD CONSTRAINT fk_ticket_replies_ticket FOREIGN KEY (ticket_id) REFERENCES ellsms_tickets(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_wallet_transactions.user_id -> ellsms_wallet_accounts.user_id
-- ON DELETE RESTRICT: no code path deletes ellsms_wallet_accounts rows (Phase 3 ground rule — a
-- financial ledger's account row is never removed). wallet_ensure_account() always creates the
-- account row before any transaction/reservation referencing it is ever written (app/wallet.php),
-- so this should have zero orphans on any install that only ever wrote through app/wallet.php.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_wallet_transactions' AND constraint_name = 'fk_wallet_tx_account'
);
SET @orphans = (
  SELECT COUNT(*) FROM ellsms_wallet_transactions t
  LEFT JOIN ellsms_wallet_accounts a ON a.user_id = t.user_id WHERE a.user_id IS NULL
);
SET @sql = IF(@fk_exists = 0 AND @orphans = 0,
  'ALTER TABLE ellsms_wallet_transactions ADD CONSTRAINT fk_wallet_tx_account FOREIGN KEY (user_id) REFERENCES ellsms_wallet_accounts(user_id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_wallet_reservations.user_id -> ellsms_wallet_accounts.user_id (same reasoning as above)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_wallet_reservations' AND constraint_name = 'fk_wallet_res_account'
);
SET @orphans = (
  SELECT COUNT(*) FROM ellsms_wallet_reservations r
  LEFT JOIN ellsms_wallet_accounts a ON a.user_id = r.user_id WHERE a.user_id IS NULL
);
SET @sql = IF(@fk_exists = 0 AND @orphans = 0,
  'ALTER TABLE ellsms_wallet_reservations ADD CONSTRAINT fk_wallet_res_account FOREIGN KEY (user_id) REFERENCES ellsms_wallet_accounts(user_id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_number_categories.name — UNIQUE. Categories are global/visible-to-everyone (schema
-- comment, db/ellsms_extra.sql), unlike ellsms_contacts which is per-user and explicitly deferred
-- (see docs/database-audit.md and docs/database-migrations.md — that one needs a product decision
-- on (user_id,mobile) vs (user_id,mobile,group_name) this migration does not make unilaterally).
-- A global list having two identically-named entries has no legitimate meaning.
SET @uniq_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_categories' AND constraint_name = 'uniq_category_name'
);
SET @dupes = (
  SELECT COUNT(*) FROM (SELECT name FROM ellsms_number_categories GROUP BY name HAVING COUNT(*) > 1) d
);
SET @sql = IF(@uniq_exists = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_number_categories ADD CONSTRAINT uniq_category_name UNIQUE (name)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_payments.authority — UNIQUE (nullable-safe: MySQL permits multiple NULLs under a UNIQUE
-- index, so payment rows created before ZarinPal responds with an authority are unaffected).
-- ZarinPal issues authority per payment-request call — buy-credit.php creates a new
-- ellsms_payments row and requests a new authority on every purchase attempt (confirmed by
-- reading app/zarinpal.php/public/buy-credit.php), so legitimate reuse of the same authority value
-- across two different payment rows is not an expected case; the existing plain (non-unique) index
-- on this column already implied lookup-by-authority was meant to resolve to one row.
SET @uniq_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND constraint_name = 'uniq_payment_authority'
);
SET @dupes = (
  SELECT COUNT(*) FROM (
    SELECT authority FROM ellsms_payments WHERE authority IS NOT NULL GROUP BY authority HAVING COUNT(*) > 1
  ) d
);
SET @sql = IF(@uniq_exists = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_payments ADD CONSTRAINT uniq_payment_authority UNIQUE (authority)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
