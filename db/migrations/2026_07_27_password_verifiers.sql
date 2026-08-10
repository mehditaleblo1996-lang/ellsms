-- Phase 2 — supporting infrastructure for a FUTURE, backend-coordinated
-- password hashing migration. This migration does NOT change what
-- user_.password stores or how login is authorized today, and does not
-- by itself fix the weak-hash finding — see docs/security-review.md
-- finding 4 for exactly why that can't safely happen from ELLSMS alone
-- (the backend platform authenticates against the same user_.password
-- column independently of ELLSMS, so ELLSMS cannot unilaterally change
-- its format without breaking the backend's own login).
--
-- What this table IS for: on every successful login, ELLSMS now also
-- opportunistically records a modern Argon2id verifier for that user
-- (backend_verify_password_and_upgrade(), app/bootstrap.php). The
-- legacy SHA-256 check against user_.password remains the sole
-- authoritative gate — nothing reads this table to grant access today.
-- The point is purely to shrink the gap for a later, coordinated
-- migration: by the time backend and ELLSMS teams agree on a real
-- rehash strategy, most active accounts already have a modern verifier
-- ready, rather than starting from zero.
--
-- Idempotent and safe to re-run, matching every other file in db/.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_password_verifiers (
  user_id    BIGINT NOT NULL PRIMARY KEY,  -- = user_.id; no FK constraint — ELLSMS does not own that table (same rationale as ellsms_meta, see db/ellsms_extra.sql)
  verifier   VARCHAR(255) NOT NULL,         -- password_hash() output — Argon2id where available, bcrypt fallback otherwise
  algo       VARCHAR(20) NOT NULL DEFAULT 'argon2id',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
