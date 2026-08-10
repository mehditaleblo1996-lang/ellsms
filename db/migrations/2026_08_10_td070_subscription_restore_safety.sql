-- TD-070 — make ellsms_subscriptions restore-safe (docs/td-070-restore-safety-closure.md).
--
-- WHAT IS WRONG. Phase 13 enforced "at most one EFFECTIVE subscription per organization" with a
-- STORED GENERATED column (`effective_organization_id`) plus a UNIQUE index. The rule itself is
-- correct and is NOT changing here. The problem is purely mechanical: the mariadb-client mysqldump
-- this project ships (docker/Dockerfile) emits generated columns as ordinary column data, and MySQL
-- then refuses the resulting INSERT with "The value specified for generated column ... is not
-- allowed". A table whose generated column holds no ROWS never trips it, which is why Phase 11's
-- disaster-recovery test passed for months — ellsms_subscriptions is empty until an operator runs
-- the billing backfill. On any install that HAS a subscription row, `make backup` produces an
-- artifact `make restore` cannot load. That is a total-loss-recovery defect, not a cosmetic one.
--
-- WHAT THIS MIGRATION DOES. Converts the column from GENERATED to an ordinary nullable column,
-- keeping the same name, the same type, the same values and the SAME UNIQUE INDEX. MySQL's
-- ALTER ... MODIFY retains every stored value when dropping the GENERATED ALWAYS clause, so this is
-- a metadata-level change: no row is rewritten, no id changes, no timestamp moves, and the unique
-- index is never dropped — there is no window in which the one-effective-subscription guarantee is
-- absent. app/Billing.php now derives the value through billing_effective_organization_id() on every
-- write (the same pattern db/migrations/2026_08_09_sms_pricing.sql adopted for its own uniqueness
-- slots), and cron/subscription-integrity-check.php audits every row for drift.
--
-- WHY NOT A TRIGGER. A BEFORE INSERT/UPDATE trigger would keep the derivation inside the database
-- and protect even a raw INSERT that omits the column. It was tested and rejected: creating a
-- trigger requires SUPER (or log_bin_trust_function_creators) whenever binary logging is enabled,
-- which the ordinary application database user does not have on a normal production MySQL — the
-- migration would simply fail there. mysqldump also needs the TRIGGER privilege to dump one, so a
-- restricted backup user would start producing INCOMPLETE backups: the exact class of failure this
-- migration exists to remove. The residual difference is documented in
-- docs/td-070-restore-safety-closure.md §Residual.
--
-- SAFETY. Preflight refuses to run at all if any organization currently has more than one effective
-- subscription — such a database is ambiguous and NO winner is guessed here (STEP 4). The failure
-- surfaces as a missing-table error whose NAME is the instruction, because MySQL does not permit
-- SIGNAL inside a prepared statement and this file must remain plain, splittable SQL like every
-- other migration in this directory.
--
-- RERUN-SAFE. Every step is guarded on the schema's actual current state, so applying this file to
-- an already-converted database is a no-op.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. PREFLIGHT: no organization may already have two effective subscriptions.
--    The pre-existing UNIQUE index makes this impossible to reach in practice; it is checked anyway
--    because this migration is the one moment the enforcement mechanism changes hands, and
--    discovering ambiguity AFTER the conversion would mean discovering it with the guarantee off.
-- ---------------------------------------------------------------------------
SET @td070_dupes = (
  SELECT COUNT(*) FROM (
    SELECT organization_id
    FROM ellsms_subscriptions
    WHERE status IN ('trialing','active','past_due','grace')
    GROUP BY organization_id
    HAVING COUNT(*) > 1
  ) d
);
SET @sql = IF(@td070_dupes > 0,
  'SELECT * FROM `TD070_ABORTED_an_organization_has_more_than_one_effective_subscription_resolve_it_manually_first_see_docs_td_070_restore_safety_closure_md`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. CONVERT the generated column to an ordinary one, in place.
--    Guarded on information_schema.columns.extra, which reads 'STORED GENERATED' only while the
--    column is still generated — so a second run does nothing.
-- ---------------------------------------------------------------------------
SET @td070_generated = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_subscriptions'
    AND column_name = 'effective_organization_id'
    AND extra LIKE '%GENERATED%'
);
SET @sql = IF(@td070_generated > 0,
  'ALTER TABLE ellsms_subscriptions MODIFY COLUMN effective_organization_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. ENSURE the unique index still exists.
--    A safety net, not the normal path: MODIFY does not touch indexes, so this is a no-op on any
--    database that came through step 2. It matters only for a database whose index was dropped by
--    hand — there, restoring the guarantee is exactly the point of this migration. If duplicates
--    exist the ADD UNIQUE fails loudly, which is the correct outcome.
-- ---------------------------------------------------------------------------
SET @td070_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_subscriptions'
    AND index_name = 'uniq_effective_subscription'
);
SET @sql = IF(@td070_index = 0,
  'ALTER TABLE ellsms_subscriptions ADD UNIQUE KEY uniq_effective_subscription (effective_organization_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. BACKFILL / NORMALIZE.
--    A no-op immediately after step 2 (MODIFY keeps every generated value), so on the normal upgrade
--    path this UPDATE matches zero rows. It exists for the database that was ALREADY converted and
--    has since drifted — a raw statement that wrote the column wrongly, or an older row written
--    before app/Billing.php maintained it.
--
--    `updated_at = updated_at` is deliberate: the column carries ON UPDATE CURRENT_TIMESTAMP, and
--    silently restamping a historical subscription row would rewrite state this migration has no
--    business touching (Invariant G).
-- ---------------------------------------------------------------------------
UPDATE ellsms_subscriptions
SET effective_organization_id = CASE WHEN status IN ('trialing','active','past_due','grace') THEN organization_id ELSE NULL END,
    updated_at = updated_at
WHERE NOT (effective_organization_id <=> (CASE WHEN status IN ('trialing','active','past_due','grace') THEN organization_id ELSE NULL END));

-- ---------------------------------------------------------------------------
-- 5. POST-VALIDATE. Every row's column must now equal its derived value, and the column must no
--    longer be generated. Either failure aborts the migration rather than recording it as applied.
-- ---------------------------------------------------------------------------
SET @td070_drift = (
  SELECT COUNT(*) FROM ellsms_subscriptions
  WHERE NOT (effective_organization_id <=> (CASE WHEN status IN ('trialing','active','past_due','grace') THEN organization_id ELSE NULL END))
);
SET @sql = IF(@td070_drift > 0,
  'SELECT * FROM `TD070_ABORTED_effective_organization_id_backfill_did_not_converge`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @td070_still_generated = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_subscriptions'
    AND column_name = 'effective_organization_id'
    AND extra LIKE '%GENERATED%'
);
SET @sql = IF(@td070_still_generated > 0,
  'SELECT * FROM `TD070_ABORTED_effective_organization_id_is_still_a_generated_column`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
