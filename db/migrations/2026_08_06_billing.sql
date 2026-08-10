-- Phase 13 — Plans, subscriptions, entitlements, usage quotas & billing control plane
-- (docs/plans-and-entitlements.md, docs/billing-operations.md).
--
-- Eight new ELLSMS-owned tables plus two additive columns on the existing ellsms_payments. Nothing
-- here touches a backend-owned table (user_/domain/outbound_message/inbound_message); every FK is
-- ELLSMS-owned-to-ELLSMS-owned, matching the standing policy in docs/database-audit.md.
--
-- NO DATA IS MUTATED HERE. Assigning existing organizations to the grandfathered `legacy` plan is a
-- separate, explicit, operator-run backfill (cron/billing-backfill.php, `make billing-backfill`) —
-- the same migration-vs-backfill split Phase 3 (wallet), Phase 6 (tenant), and Phase 11 established.
-- A freshly-migrated install has zero subscription rows, and app/Entitlements.php treats an
-- organization with no subscription row as GRANDFATHERED/UNLIMITED (never locked out — Invariant L),
-- while cron/subscription-integrity-check.php reports it so the gap stays visible rather than silent.
--
-- ellsms_plans:              the catalog. `code` is the stable internal identifier (never the
--                             translated display name). Plans are archived, never deleted, once any
--                             subscription has referenced them (FK RESTRICT enforces this).
-- ellsms_plan_entitlements:  which boolean capabilities a plan includes. Keys validated against
--                             app/Support/Entitlements.php at write time AND by the integrity check.
-- ellsms_plan_limits:        numeric caps per plan. limit_value = NULL means UNLIMITED (deliberately
--                             NULL rather than -1/0, both of which read ambiguously next to a real 0).
-- ellsms_subscriptions:      one row per organization per subscription era. The generated
--                             `effective_organization_id` column + its UNIQUE index is what makes
--                             "at most one EFFECTIVE subscription per organization" a database
--                             guarantee rather than an application convention (STEP 7) — it holds
--                             organization_id while the status is effective and NULL otherwise, and
--                             MySQL unique indexes ignore NULLs, so historical/ended rows coexist
--                             freely while two simultaneously-effective rows are simply impossible.
-- ellsms_subscription_events: append-only audit of every lifecycle transition (Invariant I).
-- ellsms_usage_counters:     (organization, metric, period) accumulator. `used` + `reserved` are
--                             mutated ONLY by atomic conditional UPDATEs (app/Entitlements.php) —
--                             never read-then-write — which is what makes quota enforcement
--                             race-safe (Invariant E/F).
-- ellsms_usage_reservations: ties each outstanding reservation to its originating business
--                             operation, so a worker retry of the same job commits the SAME
--                             reservation instead of consuming quota twice (Invariant: retries never
--                             double-count). Mirrors ellsms_wallet_reservations' shape on purpose.
-- ellsms_billing_records:    immutable price snapshot per charged period (STEP 31) — historical
--                             amounts never recomputed from a plan's current price.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_plans (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40) NOT NULL,          -- stable internal identifier: 'legacy'/'free'/'starter'/'business'
  name           VARCHAR(120) NOT NULL,         -- display name (translatable, NEVER used as an identifier)
  description    VARCHAR(500) NOT NULL DEFAULT '',
  status         ENUM('active','archived') NOT NULL DEFAULT 'active',
  is_default     TINYINT(1) NOT NULL DEFAULT 0, -- the plan a brand-new organization gets
  is_public      TINYINT(1) NOT NULL DEFAULT 1, -- shown in the self-service plan picker (legacy/internal plans are not)
  billing_period ENUM('none','monthly','yearly') NOT NULL DEFAULT 'none', -- 'none' = free/legacy, never charged
  price_amount   BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- in the smallest currency unit (Rial), same convention as ellsms_payments.amount_rial
  currency       VARCHAR(8) NOT NULL DEFAULT 'IRR',
  trial_days     INT UNSIGNED NOT NULL DEFAULT 0,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_plan_code (code),
  KEY idx_status_public (status, is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_plan_entitlements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id         INT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(60) NOT NULL,  -- validated against Entitlements::all()
  enabled         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_plan_entitlement (plan_id, entitlement_key),
  CONSTRAINT fk_plan_entitlements_plan FOREIGN KEY (plan_id) REFERENCES ellsms_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_plan_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT UNSIGNED NOT NULL,
  limit_key    VARCHAR(60) NOT NULL,   -- validated against Limits::all()
  limit_value  BIGINT UNSIGNED NULL,   -- NULL = unlimited (see this file's header for why not -1/0)
  reset_period ENUM('never','daily','monthly') NOT NULL DEFAULT 'never', -- 'never' = a standing resource count (members, API keys), not a usage meter
  enforcement  ENUM('hard','soft') NOT NULL DEFAULT 'hard',
  UNIQUE KEY uniq_plan_limit (plan_id, limit_key),
  CONSTRAINT fk_plan_limits_plan FOREIGN KEY (plan_id) REFERENCES ellsms_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_subscriptions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id       INT UNSIGNED NOT NULL,
  plan_id               INT UNSIGNED NOT NULL,
  status                ENUM('trialing','active','past_due','grace','suspended','cancelled','expired') NOT NULL,
  started_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  current_period_start  TIMESTAMP NULL,
  current_period_end    TIMESTAMP NULL,
  trial_ends_at         TIMESTAMP NULL,
  grace_ends_at         TIMESTAMP NULL,
  cancel_at_period_end  TINYINT(1) NOT NULL DEFAULT 0,
  pending_plan_id       INT UNSIGNED NULL,  -- a scheduled downgrade taking effect at period end (STEP 28)
  cancelled_at          TIMESTAMP NULL,
  suspended_at          TIMESTAMP NULL,
  source                ENUM('backfill','self_service','platform_admin','payment') NOT NULL DEFAULT 'self_service',
  external_reference    VARCHAR(190) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- STEP 7, enforced by the database itself: holds organization_id only while this row is one of the
  -- EFFECTIVE statuses, NULL otherwise. Combined with the UNIQUE index below (MySQL unique indexes
  -- permit unlimited NULLs), two simultaneously-effective subscriptions for one organization cannot
  -- be inserted even by a crafted request or a concurrent race — it is not merely an app-level rule.
  effective_organization_id INT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN status IN ('trialing','active','past_due','grace') THEN organization_id ELSE NULL END
  ) STORED,
  UNIQUE KEY uniq_effective_subscription (effective_organization_id),
  KEY idx_org_status (organization_id, status),
  KEY idx_period_end (status, current_period_end),
  KEY idx_trial_end (status, trial_ends_at),
  KEY idx_grace_end (status, grace_ends_at),
  CONSTRAINT fk_subscriptions_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES ellsms_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriptions_pending_plan FOREIGN KEY (pending_plan_id) REFERENCES ellsms_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_subscription_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  event_type      VARCHAR(60) NOT NULL,   -- 'created'/'activated'/'upgraded'/'downgrade_scheduled'/'cancelled'/'suspended'/'expired'/'renewed'/...
  from_status     VARCHAR(20) NULL,
  to_status       VARCHAR(20) NULL,
  from_plan_id    INT UNSIGNED NULL,
  to_plan_id      INT UNSIGNED NULL,
  actor_user_id   BIGINT NULL,            -- NULL for automated lifecycle transitions (the scheduler)
  idempotency_key VARCHAR(190) NULL,      -- lets a retried transition detect it already happened
  detail          VARCHAR(500) NOT NULL DEFAULT '',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subscription_event_idem (idempotency_key),
  KEY idx_subscription (subscription_id, created_at),
  KEY idx_org (organization_id, created_at),
  CONSTRAINT fk_subscription_events_sub FOREIGN KEY (subscription_id) REFERENCES ellsms_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_usage_counters (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  metric_key      VARCHAR(60) NOT NULL,   -- a Limits::* key with a resettable period (messages, API requests)
  period_start    DATETIME NOT NULL,      -- always UTC (STEP 17) — never server-local time
  period_end      DATETIME NOT NULL,
  used            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reserved        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_usage_period (organization_id, metric_key, period_start),
  KEY idx_period_end (period_end),
  CONSTRAINT fk_usage_counters_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_usage_reservations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  metric_key      VARCHAR(60) NOT NULL,
  period_start    DATETIME NOT NULL,
  amount          BIGINT UNSIGNED NOT NULL,
  status          ENUM('active','committed','released') NOT NULL DEFAULT 'active',
  reference_type  VARCHAR(40) NOT NULL,   -- 'bulk_job'/'api_message'/'schedule'/'autoreply'/'direct_send'
  reference_id    VARCHAR(190) NOT NULL,
  expires_at      DATETIME NULL,          -- stale-reservation reconciliation (STEP 49)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalized_at    TIMESTAMP NULL,
  -- The same (reference_type, reference_id) can only ever hold ONE reservation — this is what makes
  -- a worker retry of the same job a no-op replay instead of a second quota consumption, exactly as
  -- ellsms_wallet_reservations does for money (Phase 3).
  UNIQUE KEY uniq_usage_reservation_ref (reference_type, reference_id, metric_key),
  KEY idx_org_status (organization_id, status),
  KEY idx_expiry (status, expires_at),
  CONSTRAINT fk_usage_reservations_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_billing_records (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,   -- NULL until the payment activates a subscription
  plan_id         INT UNSIGNED NOT NULL,
  -- Immutable snapshot (STEP 31): a historical charge is NEVER recomputed from the plan's current
  -- price. plan_code/billing_period are denormalized here on purpose for exactly that reason.
  plan_code       VARCHAR(40) NOT NULL,
  billing_period  ENUM('none','monthly','yearly') NOT NULL,
  amount          BIGINT UNSIGNED NOT NULL,
  currency        VARCHAR(8) NOT NULL DEFAULT 'IRR',
  status          ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  period_start    TIMESTAMP NULL,
  period_end      TIMESTAMP NULL,
  payment_id      INT UNSIGNED NULL,      -- -> ellsms_payments.id (same table credit purchases use)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         TIMESTAMP NULL,
  KEY idx_org_created (organization_id, created_at),
  KEY idx_payment (payment_id),
  KEY idx_status (status),
  CONSTRAINT fk_billing_records_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_billing_records_plan FOREIGN KEY (plan_id) REFERENCES ellsms_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ellsms_payments gains a `purpose` discriminator so subscription charges reuse the EXACT existing
-- payment/callback/reconciliation machinery (atomic claim, idempotent verification, ownership
-- checks) instead of a parallel payment path — a credit purchase and a subscription charge remain
-- explicitly different concepts (STEP 33) but share one proven transaction integrity model.
-- `credits` stays meaningful only for purpose='credit'; a subscription payment credits no wallet.
-- Guarded ALTER (information_schema check first), consistent with every prior migration here.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'purpose'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_payments
     ADD COLUMN purpose ENUM('credit','subscription') NOT NULL DEFAULT 'credit' AFTER credits,
     ADD COLUMN billing_record_id BIGINT UNSIGNED NULL AFTER purpose,
     ADD KEY idx_purpose (purpose)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
