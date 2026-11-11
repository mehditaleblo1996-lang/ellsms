-- The key of the message ids («شناسه» in the panel's send report, recipients[].id in the API —
-- app/ApiMessageIds.php, docs/public-api.md «One id per message»).
--
-- A message id is not stored: it is the row number put through a keyed permutation, and this is the
-- key — 32 random bytes, as 64 hex characters, in ellsms_settings. Created here, at deploy time, so
-- that it exists before the first id is ever made. INSERT IGNORE: once the row exists it is never
-- replaced; re-running this file changes nothing.
--
-- NEVER change or delete this row: with another key every id already shown to a customer names
-- another message. No table is altered.
SET NAMES utf8mb4;

INSERT IGNORE INTO ellsms_settings (skey, svalue)
VALUES ('message_id_key', LOWER(HEX(RANDOM_BYTES(32))));
