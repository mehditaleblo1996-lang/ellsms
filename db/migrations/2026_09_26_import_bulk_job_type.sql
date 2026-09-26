-- ---------------------------------------------------------------------------
-- Repair ellsms_bulk_jobs.type for jobs created by the large-file import pipeline.
--
-- import_create_bulk_job() hardcoded type='p2p' for every import, so a large
-- smart-send (or gradual) import was labelled "نظیر به نظیر" in reports and was
-- missing from smart-send.php's own list (which shows type='smart'). New jobs
-- now take the import's source_type; this backfills the existing ones.
--
-- Rerun-safe: only rows still carrying the wrong type are touched.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

UPDATE ellsms_bulk_jobs bj
JOIN ellsms_import_jobs ij ON ij.id = bj.source_import_job_id
SET bj.type = ij.source_type
WHERE bj.type = 'p2p'
  AND ij.source_type IN ('smart', 'gradual');
