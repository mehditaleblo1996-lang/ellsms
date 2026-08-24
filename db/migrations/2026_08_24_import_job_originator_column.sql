-- ---------------------------------------------------------------------------
-- REPAIR — restore ellsms_import_jobs.originator.
--
-- WHY THIS EXISTS. import_create_job() (app/import.php) accepts $originator
-- as a parameter -- every caller (new-send.php gradual mode, p2p-send.php,
-- smart-send.php) passes the sender the user actually selected -- but the
-- INSERT into ellsms_import_jobs never included it, and no migration ever
-- created the column. The value was silently discarded at job creation.
--
-- Every later stage that needs the sender reads it back from the job row:
--   - import_job_analyze_pass()        prices each analyze chunk against it
--   - import_job_reserve_and_stage()   re-prices the exact total against it,
--                                       and passes it to import_create_bulk_job()
--                                       as the SEND-TIME originator on
--                                       ellsms_bulk_jobs
--   - import_process_insert_chunk()    prices each insert chunk against it
--
-- With the column missing, every one of those four reads silently evaluated
-- $job['originator'] as NULL (a PHP warning, not a fatal -- caught nowhere),
-- cast to the empty string. sms_pricing_price_messages('') falls through to
-- the tenant's default route rather than the route for the sender the user
-- actually picked, and import_create_bulk_job() then created the ACTUAL SEND
-- job with originator='' too -- meaning a confirmed large import would have
-- sent from no sender at all, not the one shown during setup, and would have
-- been priced (and the user charged) at the default route's price instead of
-- the selected sender's route. Found writing the first integration tests this
-- pipeline has ever had (tests/Integration/LargeImportPipelineTest.php); the
-- test fixtures' senders happened to also resolve to the default route, which
-- is why assertions passed even though a PHP warning was firing.
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped,
-- and a second run emits SELECT 1 no-ops.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'originator'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN originator VARCHAR(20) NOT NULL DEFAULT '' AFTER source_type",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
