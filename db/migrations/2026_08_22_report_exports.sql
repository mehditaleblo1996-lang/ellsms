-- ---------------------------------------------------------------------------
-- PHASE 8 — Durable report export jobs.
--
-- WHY THIS EXISTS. A CSV export of a filtered report used to run inside the web
-- request. At a few thousand rows that is fine; at a few million it holds a PHP
-- process and a database cursor open for minutes, and the browser gives up long
-- before the file is finished. Worse, the row count is not knowable in advance:
-- the same URL is harmless for one organization and fatal for another.
--
-- So an export becomes a JOB. The web request records what to export and returns
-- immediately; a dedicated worker streams the rows to a file in bounded chunks
-- and marks the job ready. Nothing about the export runs on the request path.
--
-- MIRRORS ellsms_import_jobs DELIBERATELY (2026_08_16_import_jobs.sql): the same
-- claim/lease columns, the same progress counters, the same opaque storage_key.
-- An operator who has debugged a stuck import can debug a stuck export without
-- learning a second vocabulary, and the reclaim semantics are already proven.
--
-- Additive and rerun-safe: CREATE TABLE IF NOT EXISTS only, no ALTER of any
-- existing table, no data migration. Safe on a fresh database, on an upgrade,
-- and after a restore.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_report_exports (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Tenant identity is captured AT REQUEST TIME and re-enforced by the worker.
  -- The worker must never widen scope by re-resolving "who can see what" later:
  -- the requester's organization is a fact of the request, not of the run.
  organization_id     INT UNSIGNED NOT NULL,
  user_id             BIGINT NOT NULL,

  -- The report filters, stored as JSON exactly as the request understood them
  -- (date range, status, user, destination, search text). Kept as data, never
  -- as SQL: the worker rebuilds the WHERE clause through the same code path the
  -- report page uses, so a stored filter can never become an injection vector.
  filters_json        JSON NOT NULL,

  format              ENUM('csv') NOT NULL DEFAULT 'csv',

  status              ENUM('queued','processing','ready','failed','expired','cancelled')
                        NOT NULL DEFAULT 'queued',

  -- Opaque, randomized basename under storage/exports/ -- NOT under the web
  -- root, and NOT derived from the filters. A filename must not leak a phone
  -- number, a search term, or a date range to anyone who can see a directory
  -- listing, a proxy log, or a browser history entry.
  storage_key         VARCHAR(120) NULL,

  -- What the user sees when the browser saves the file. Safe to be descriptive
  -- because it is only ever sent in a Content-Disposition header, never used as
  -- a path.
  download_filename   VARCHAR(255) NOT NULL DEFAULT '',

  -- Progress. total_rows is the count at claim time and may drift if new
  -- messages arrive mid-export; it is a progress indicator, not a guarantee.
  total_rows          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  exported_rows       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_bytes          BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- Keyset cursor: the last outbound_message.id written to the file. A resumed
  -- or retried run continues from here instead of restarting a million-row scan,
  -- and it is why the worker never needs OFFSET.
  last_row_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,

  error_message       TEXT NULL,

  -- Claim/lease, identical in shape to ellsms_import_chunks so a crashed worker
  -- releases its job by lease expiry rather than stranding it forever.
  claimed_by          VARCHAR(80) NULL,
  claimed_at          DATETIME NULL,
  lease_expires_at    DATETIME NULL,
  attempt_count       INT UNSIGNED NOT NULL DEFAULT 0,

  started_at          DATETIME NULL,
  completed_at        DATETIME NULL,

  -- Retention. Generated files hold real message content, so they are not kept
  -- indefinitely; the cleanup pass deletes the file and marks the row 'expired'
  -- after this instant. The row itself survives as an audit trail of who
  -- exported what, and when.
  expires_at          DATETIME NULL,

  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- The worker's claim query: WHERE status='queued' ORDER BY id. Also serves the
  -- reclaim scan for leases that have expired.
  KEY idx_claim (status, lease_expires_at, id),

  -- The "my exports" list on the download page: one organization's rows, newest
  -- first. Tenant column leads so the index is usable for the scoped lookup.
  KEY idx_org_created (organization_id, created_at, id),

  -- The retention sweep: find ready files whose expires_at has passed.
  KEY idx_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
