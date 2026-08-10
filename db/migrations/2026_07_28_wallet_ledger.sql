-- Phase 3 — wallet ledger, reservations, atomic credit (docs/security-review.md finding 6;
-- docs/technical-debt.md TD-003/TD-005/TD-006). Adds ELLSMS-owned tables only; does not touch
-- user_ (still updated as a synchronized compatibility projection, see app/wallet.php).
--
-- ellsms_wallet_accounts: one row per user. available_balance is what user_.currentcredit
-- mirrors (the actually-spendable amount right now); reserved_balance is credit held against
-- accepted-but-not-yet-fully-executed work (a queued bulk job, a pending schedule) that has
-- already left "available" but hasn't been spent yet. available + reserved is NOT decremented by
-- a reservation — only reallocated between the two columns until a commit actually spends it.
--
-- ellsms_wallet_transactions: append-only ledger. Every row's idempotency_key is UNIQUE — this is
-- the actual mechanism that makes a retried financial operation a safe no-op instead of a double
-- debit/credit (see app/wallet.php's insert-first-catch-duplicate pattern).
--
-- ellsms_wallet_reservations: one row per business operation that reserved credit (a bulk job, a
-- single schedule execution, ...). UNIQUE(reference_type, reference_id) means at most one active
-- reservation can ever exist per business object — a retried "create this bulk job" cannot reserve
-- twice for the same job id.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_wallet_accounts (
  user_id            BIGINT NOT NULL PRIMARY KEY,   -- = user_.id (no FK: ELLSMS doesn't own that table)
  available_balance  BIGINT NOT NULL DEFAULT 0,      -- mirrors user_.currentcredit
  reserved_balance   BIGINT NOT NULL DEFAULT 0,      -- held for accepted, not-yet-committed work
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_wallet_transactions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT NOT NULL,
  type             VARCHAR(40) NOT NULL,             -- purchase, sms_debit, refund, manual_credit,
                                                       -- manual_debit, reservation_release, adjustment,
                                                       -- migration_opening_balance — see docs/wallet-architecture.md
  amount           BIGINT NOT NULL,                  -- signed: positive = credit, negative = debit
  balance_before   BIGINT NOT NULL,                  -- available_balance before this entry
  balance_after    BIGINT NOT NULL,                  -- available_balance after this entry
  reference_type   VARCHAR(40) NOT NULL DEFAULT '',  -- e.g. 'schedule','bulk_item','autoreply','payment','admin_adjustment','direct_send'
  reference_id     VARCHAR(64) NOT NULL DEFAULT '',
  idempotency_key  VARCHAR(191) NOT NULL,            -- the actual exactly-once guarantee (UNIQUE below)
  metadata         TEXT NULL,                        -- small JSON blob, never secrets/OTP/full payloads
  actor_user_id    BIGINT NULL,                      -- who performed it, for manual adjustments
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_idempotency (idempotency_key),
  KEY (user_id, created_at),
  KEY (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_wallet_reservations (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT NOT NULL,
  amount            BIGINT NOT NULL,                 -- original reserved amount
  remaining_amount  BIGINT NOT NULL,                  -- decremented as partial commits happen
  reference_type    VARCHAR(40) NOT NULL,
  reference_id      VARCHAR(64) NOT NULL,
  idempotency_key   VARCHAR(191) NOT NULL,
  status            ENUM('active','committed','released','expired') NOT NULL DEFAULT 'active',
  expires_at        DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_idempotency (idempotency_key),
  UNIQUE KEY uniq_reference (reference_type, reference_id),
  KEY (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
