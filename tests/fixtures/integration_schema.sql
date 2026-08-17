-- Minimal schema for integration tests ONLY. This is NOT the real backend
-- schema (see db/ellsms_extra.sql for the real ELLSMS-owned tables, and
-- docs/database-audit.md for the real backend-owned tables this project
-- never migrates). It exists purely so tests/Integration/*Test.php can run
-- the DB-touching authorization/rate-limit/2FA functions against a real,
-- disposable MySQL instance instead of mocking PDO — reproducing just
-- enough of user_ (backend-owned, minimal columns actually read by
-- app/bootstrap.php::current_user() / app/authorization.php) plus the real
-- ELLSMS-owned tables (loaded verbatim from db/ellsms_extra.sql and
-- db/migrations/*.sql by tests/Integration/IntegrationTestCase.php,
-- appended after this file).
SET NAMES utf8mb4;

DROP TABLE IF EXISTS user_;
CREATE TABLE user_ (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL DEFAULT '',
  firstname     VARCHAR(120) NOT NULL DEFAULT '',
  lastname      VARCHAR(120) NOT NULL DEFAULT '',
  email         VARCHAR(190) NOT NULL DEFAULT '',
  mobile        VARCHAR(20) NOT NULL DEFAULT '',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  deleted       TINYINT(1) NOT NULL DEFAULT 0,
  currentcredit BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 4: minimal stand-ins so dispatch_message_raw()/run_autoreply_pass() (app/backend.php) are
-- callable from tests/Integration/*QueueTest.php — only the columns those two functions actually
-- read/write, same minimal-reproduction approach as user_ above.
DROP TABLE IF EXISTS outbound_message;
CREATE TABLE outbound_message (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  sender_user_id  BIGINT NOT NULL,
  originator      VARCHAR(20) NOT NULL DEFAULT '',
  destination     VARCHAR(20) NOT NULL DEFAULT '',
  content         TEXT NULL,
  status          VARCHAR(40) NOT NULL DEFAULT '',
  error_code      INT NOT NULL DEFAULT 0,
  sent_at         DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Minimal stand-in so backend_list_domains() (app/Backend/identity.php, read by public/users.php's
-- account-creation dropdown) is callable — only the two columns that function actually selects, same
-- minimal-reproduction approach as every other table in this file.
DROP TABLE IF EXISTS domain;
CREATE TABLE domain (
  id   BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS inbound_message;
-- Column is `received_at`, matching the real shared schema (docs/database-audit.md) that
-- app/Backend/messages.php and public/inbox.php actually query (`DATE(received_at)`,
-- `received_at >= ?`) -- this fixture previously named it `created_at`, a drift that let every
-- date-scoped inbound query silently go untested against real MySQL until Phase 8's tenant
-- isolation regression test exercised it.
CREATE TABLE inbound_message (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  originator   VARCHAR(20) NOT NULL DEFAULT '', -- the customer's own number
  destination  VARCHAR(20) NOT NULL DEFAULT '', -- our line that received it
  content      TEXT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
