-- #39 — message.received is emitted exactly once per inbound message. The auto-responder's scan
-- that sees every new inbound row is at-least-once (a crash before its cursor is saved re-reads
-- rows), so the emit claims the message here first. Rows exist only for organizations that
-- subscribed to message.received; ids come from either inbound store (disjoint ranges, see #37).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_inbound_webhook_claims (
  inbound_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  organization_id    INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
