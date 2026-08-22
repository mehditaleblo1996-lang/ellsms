-- ---------------------------------------------------------------------------
-- PHASE 8 — indexes justified by EXPLAIN, and nothing more.
--
-- Every index here was added because EXPLAIN showed a specific plan problem on a
-- populated table (50,250 outbound_message rows), not because the shape looked
-- plausible. The measurements are recorded in docs/reporting-scalability.md.
--
-- DELIBERATELY NOT INDEXED HERE: outbound_message. It is BACKEND-OWNED (see
-- docs/service-boundaries.md §1 -- "ELLSMS never writes outbound_message"), and
-- that boundary is machine-enforced by cron/backend-boundary-check.php. EXPLAIN
-- does show a real problem there -- the report summary COUNT(*) is a full table
-- scan (type=ALL), and cursor pages fall back to a PRIMARY backward scan because
-- no (sender_user_id, sent_at) index exists -- but the fix belongs to the team
-- that owns the table. It is written up as a recommendation in
-- docs/reporting-scalability.md rather than applied unilaterally from here.
--
-- Additive and rerun-safe: each block is guarded on information_schema, so a
-- second run emits SELECT 1 no-ops.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- ellsms_message_attempts (destination, status)
--
-- EXPLAIN BEFORE: type=index, key=PRIMARY -- i.e. a full index scan.
--
-- Both public/reports.php and cron/export-worker.php enrich a page of report
-- rows with the delivery lifecycle using
--
--     WHERE destination IN (...) AND status = 'accepted'
--
-- once per page. There was no index on destination at all, so every page of
-- every report and every chunk of every export scanned the whole attempts
-- table. On a deployment with millions of attempts that is the single most
-- expensive thing the reporting path does, and it repeats per page.
--
-- Column order: destination first because it is the selective equality/IN
-- predicate; status second to let the same index satisfy the constant filter.
-- ---------------------------------------------------------------------------
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts'
    AND index_name = 'idx_attempt_destination_status'
);
SET @sql = IF(@idx_exists = 0,
  "ALTER TABLE ellsms_message_attempts
     ADD INDEX idx_attempt_destination_status (destination, status)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- ellsms_report_exports (organization_id, id)
--
-- EXPLAIN BEFORE: type=ref, key=idx_org_created, Extra="Using filesort".
--
-- The export list is scoped by organization and ordered by id DESC, but
-- idx_org_created is (organization_id, created_at, id) -- so MySQL uses it to
-- find the organization's rows and then sorts them. The list is capped at 100
-- rows so the filesort is cheap today; this index removes it anyway because
-- (organization_id, id) matches the access pattern exactly and costs almost
-- nothing on a table that holds one row per export request.
-- ---------------------------------------------------------------------------
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_report_exports'
    AND index_name = 'idx_export_org_id'
);
SET @sql = IF(@idx_exists = 0,
  "ALTER TABLE ellsms_report_exports
     ADD INDEX idx_export_org_id (organization_id, id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
