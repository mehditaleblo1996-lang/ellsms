-- Admin "delete message": hides a sent message (or a whole bulk send) from the customer's panel.
--
-- A SOFT delete, deliberately. The send itself happened — it was charged, it may be disputed, and
-- outbound_message is backend-owned — so nothing is removed from the send records. A row here only
-- tells every customer-facing view (reports, dashboard, message detail, CSV export) to leave that
-- message out. Admins still see it, marked as removed, and can restore it. See app/HiddenMessages.php.
--
-- ADDITIVE AND INERT until an admin removes a message. Idempotent: re-running is a no-op.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_hidden_messages (
  source      ENUM('outbound','attempt','bulk_job') NOT NULL,  -- outbound_message.id / ellsms_message_attempts.id / ellsms_bulk_jobs.id
  message_id  BIGINT UNSIGNED NOT NULL,
  hidden_by   BIGINT NOT NULL,                                  -- the admin's user id
  reason      VARCHAR(190) NOT NULL DEFAULT '',
  hidden_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (source, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
