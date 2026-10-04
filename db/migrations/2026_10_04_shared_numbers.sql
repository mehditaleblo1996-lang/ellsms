-- Shared sender lines — one number handed to SEVERAL users at once, on top of the two ownership
-- columns ellsms_numbers already has: assigned_user_id (exactly one owner) and organization_id
-- (one organization, every member of it).
--
-- A share grants SENDING ONLY. The inbox, auto-reply rules and the per-line opt-out list stay with
-- the number's own user/organization — which is why app/authorization.php keeps two separate
-- functions, sendable_originators() (own + organization + shared) and allowed_originators()
-- (own + organization). See docs/shared-numbers.md.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_number_shares (
  number_id  INT UNSIGNED NOT NULL,          -- = ellsms_numbers.id
  user_id    BIGINT NOT NULL,                -- = user_.id; no FK, same convention as ellsms_meta (we don't own user_)
  created_by BIGINT NOT NULL,                -- the platform admin who granted it, for the audit trail
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (number_id, user_id),          -- granting the same line twice is a no-op, not a duplicate row
  KEY idx_user (user_id),                    -- the hot lookup: "every line shared with this user"
  CONSTRAINT fk_number_shares_number FOREIGN KEY (number_id) REFERENCES ellsms_numbers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
