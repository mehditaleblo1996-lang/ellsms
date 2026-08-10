-- Phase 8 — ELLSMS-owned message attempt records (STEP 16/17, Invariant E).
--
-- Replaces the previous behavior in app/backend.php's dispatch_message_raw(): when the backend SMS
-- API was unreachable (network/timeout/non-2xx), ELLSMS used to write its own fabricated
-- "send_failed" rows DIRECTLY into outbound_message — a backend-owned table — so the attempt would
-- still show up in the report. That is exactly the "silent write fallback creates dual ownership"
-- pattern this phase's Invariant E forbids: outbound_message rows are supposed to mean "the backend
-- platform's own gateway actually processed this," and a locally-fabricated row broke that
-- guarantee with no way to tell the two apart later.
--
-- This table is ELLSMS's own local audit/reconciliation record of a transport-level failure —
-- never presented as backend-confirmed history, never joined into reports.php's outbound_message
-- report. See cron/jobs-status.php's "Message attempts" section for read-only visibility and
-- docs/service-boundaries.md for the full fallback-policy writeup.
--
-- CREATE TABLE IF NOT EXISTS is already safely idempotent on its own (unlike ADD CONSTRAINT/INDEX,
-- which needs the @sql/PREPARE dynamic-SQL guard other migrations use because that specific syntax
-- has no native IF NOT EXISTS form) — no dynamic SQL needed here.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_message_attempts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NULL,
  user_id            BIGINT NOT NULL, -- = user_.id; no FK, same rationale as every other ellsms_* table
  reference_type     VARCHAR(40) NOT NULL,  -- 'direct_send' / 'schedule' / 'bulk_item' / 'autoreply' / etc — mirrors ellsms_wallet_transactions.reference_type
  reference_id       VARCHAR(190) NOT NULL, -- the same reference id the wallet ledger/job row already uses, for cross-referencing
  idempotency_key    VARCHAR(190) NULL,
  backend_request_id VARCHAR(190) NULL,     -- from the backend API response, when present (Phase 8 ApiClient)
  status             ENUM('failed') NOT NULL DEFAULT 'failed', -- only failures are recorded here; a success is already a real outbound_message row
  error_code         VARCHAR(60) NOT NULL,
  error_message      VARCHAR(500) NULL,
  attempted_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at       TIMESTAMP NULL,
  CONSTRAINT fk_message_attempts_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT,
  KEY (user_id, attempted_at),
  KEY (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
