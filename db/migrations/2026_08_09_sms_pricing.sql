-- SMS pricing — admin-managed operators, prefixes, providers, routes, sender-route mapping,
-- effective-dated route/operator prices, and immutable per-send price snapshots
-- (docs/sms-pricing.md).
--
-- Seven new ELLSMS-owned tables plus additive pricing columns on the existing ellsms_bulk_items.
-- Nothing here touches a backend-owned table (user_/domain/outbound_message/inbound_message); every
-- FK is ELLSMS-owned-to-ELLSMS-owned, matching the standing policy in docs/database-audit.md.
--
-- THIS MIGRATION DELIBERATELY SEEDS ROWS, unlike Phase 3/6/11/13's strict "schema only, backfill is
-- a separate operator command" split. The reason is a hard backward-compatibility requirement:
-- pricing is ALWAYS ON (there is no SMS_PRICING_ENABLED master switch — see docs/sms-pricing.md
-- §Configuration), and the pricing engine FAILS CLOSED when a send cannot be priced. An install that
-- applied this migration and then had to wait for a human to configure a provider/route/price before
-- any SMS could be sent would be an outage, not a migration. So the catalog below reproduces the
-- pre-existing behavior EXACTLY — one "Legacy" provider, one default route, one route-default price
-- of 1 credit per segment — which is verbatim what dispatch_message()/bulk_queue_job() charged
-- before this feature existed. Every seeded row is admin-editable afterwards; none of them is
-- special-cased anywhere in the application code. Every seed statement is INSERT IGNORE or
-- INSERT ... SELECT ... WHERE NOT EXISTS, so re-running this file changes nothing.
--
-- No EXISTING row in any pre-existing table is modified or deleted by this migration.
--
-- WHY THE UNIQUENESS "SLOT" COLUMNS BELOW ARE PLAIN COLUMNS, NOT GENERATED COLUMNS. Several tables
-- here need "unique among ACTIVE rows only", which MySQL expresses naturally as a generated column
-- that is NULL when the row is inactive (unique indexes ignore NULLs) -- exactly the technique
-- ellsms_subscriptions.effective_organization_id already uses. That was the first implementation
-- here too, and tests/Integration/RestoreDisasterRecoveryTest.php caught it failing a REAL restore:
-- the mariadb-client mysqldump this project ships with (docker/Dockerfile) emits generated columns
-- as ordinary data, and MySQL then rejects the resulting INSERT with "The value specified for
-- generated column ... is not allowed". A table whose generated column never holds any ROWS never
-- trips it, which is why it had not surfaced before; the seeded catalog below has rows on day one.
-- So these columns are ordinary NULLable columns maintained by public/sms-pricing.php on every
-- write. The DATABASE still enforces the uniqueness (two concurrent admin edits still collide on
-- the index -- that guarantee is not weakened); what changed is only who computes the value.
-- cron/sms-pricing-integrity-check.php audits them for drift, which is the cost of this trade.
--
-- ellsms_sms_operators:         admin-managed carrier catalog. `code` is the stable internal
--                                identifier ('mci'/'mtn'/'rightel'), never the translated display
--                                name. There is deliberately NO 'unknown' operator row — an
--                                unresolvable number is represented by operator_id = NULL, which is
--                                the same value a route DEFAULT price uses, so "unknown number
--                                falls back to the route default price" needs no special case.
-- ellsms_sms_operator_prefixes: number prefixes owned by an operator. Matching is LONGEST-PREFIX
--                                over `normalized_prefix` (the international 98… form). The
--                                `active_prefix` slot column + UNIQUE index is what makes
--                                "two ACTIVE rules can never claim the same prefix" a database
--                                guarantee rather than an application convention.
-- ellsms_sms_providers:         pricing/configuration metadata only. NO credentials live here —
--                                provider secrets stay in the existing secure integration layer
--                                (app/Backend/ApiClient.php + BACKEND_* env), untouched by this
--                                feature. Configuring a provider here does NOT change message
--                                transport (Invariant I).
-- ellsms_sms_routes:            a (provider, message_type) sending lane that prices can hang off.
--                                `default_slot` + UNIQUE makes "at most one ACTIVE
--                                default route per message type" a database guarantee — the
--                                determinism STEP 15 requires without any cheapest-route logic.
-- ellsms_sender_routes:         explicit sender -> route assignment. Keyed by the NORMALIZED
--                                ORIGINATOR STRING, not ellsms_numbers.id, because this product has
--                                two legitimate kinds of sender: a pooled ellsms_numbers row AND a
--                                legacy free-text ellsms_meta.originator (both accepted by
--                                can_use_originator()). A numbers-table FK would silently fail to
--                                price every legacy-originator install.
-- ellsms_sms_route_prices:      effective-dated price per (route, operator-or-route-default).
--                                Money is an INTEGER count of MILLICREDITS (1 credit = 1000) —
--                                never a float. See docs/sms-pricing.md §Money.
-- ellsms_sms_price_snapshots:   the immutable authoritative pricing decision for an accepted send,
--                                one row per (operation, route, operator, unit price) group. This is
--                                what historical cost reporting reads; it never recomputes from the
--                                price tables above, so an admin price change cannot rewrite
--                                history (Invariant G).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sms_operators (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL,           -- stable internal identifier, never the display name
  name         VARCHAR(120) NOT NULL,          -- display name (translatable, NEVER used as an identifier)
  country_code VARCHAR(8) NOT NULL DEFAULT 'IR',
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  priority     INT NOT NULL DEFAULT 0,         -- tie-break only, never a routing preference
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_operator_code (code),
  KEY idx_operator_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sms_operator_prefixes (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  operator_id      INT UNSIGNED NOT NULL,
  prefix           VARCHAR(24) NOT NULL,       -- exactly what the admin typed, digits only (e.g. 0912)
  normalized_prefix VARCHAR(24) NOT NULL,      -- the matched form, international digits (e.g. 98912)
  prefix_length    TINYINT UNSIGNED NOT NULL,  -- CHAR_LENGTH(normalized_prefix), denormalized for longest-match ordering
  status           ENUM('active','archived') NOT NULL DEFAULT 'active',
  priority         INT NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Uniqueness slot: normalized_prefix while ACTIVE, NULL once archived (MySQL unique indexes
  -- permit unlimited NULLs). This makes "two ACTIVE rules can never claim one prefix" a DATABASE
  -- guarantee, which is what keeps longest-prefix matching unambiguous under concurrent admin edits.
  -- Maintained by the application on every write rather than by a GENERATED column -- see this
  -- file's header note on generated columns and mysqldump.
  active_prefix    VARCHAR(24) NULL,
  UNIQUE KEY uniq_active_prefix (active_prefix),
  KEY idx_prefix_operator (operator_id, status),
  KEY idx_prefix_length (prefix_length),
  CONSTRAINT fk_sms_prefix_operator FOREIGN KEY (operator_id) REFERENCES ellsms_sms_operators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sms_providers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  priority    INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_code (code),
  KEY idx_provider_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sms_routes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id  INT UNSIGNED NOT NULL,
  code         VARCHAR(40) NOT NULL,
  name         VARCHAR(120) NOT NULL,
  message_type ENUM('promotional','transactional','otp','default') NOT NULL DEFAULT 'default',
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  is_default   TINYINT(1) NOT NULL DEFAULT 0,
  priority     INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Uniqueness slot: message_type while this route is the ACTIVE default for it, NULL otherwise --
  -- "at most one active default route per message type" as a database guarantee (see above).
  default_slot VARCHAR(20) NULL,
  UNIQUE KEY uniq_route_code_per_provider (provider_id, code),
  UNIQUE KEY uniq_default_route_per_type (default_slot),
  KEY idx_route_status (status, message_type),
  CONSTRAINT fk_sms_route_provider FOREIGN KEY (provider_id) REFERENCES ellsms_sms_providers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sender_routes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender       VARCHAR(24) NOT NULL,           -- normalize_originator() form, digits only
  message_type ENUM('promotional','transactional','otp','default') NOT NULL DEFAULT 'default',
  route_id     INT UNSIGNED NOT NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  priority     INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Uniqueness slot: "<sender>:<message_type>" while active, NULL once archived -- one active
  -- assignment per (sender, message type), so route selection can never face two candidates.
  active_slot  VARCHAR(48) NULL,
  UNIQUE KEY uniq_active_sender_route (active_slot),
  KEY idx_sender_route (sender, status),
  CONSTRAINT fk_sender_route_route FOREIGN KEY (route_id) REFERENCES ellsms_sms_routes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_sms_route_prices (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id       INT UNSIGNED NOT NULL,
  operator_id    INT UNSIGNED NULL,            -- NULL = this route's DEFAULT price (covers every operator, and unknown numbers)
  price_per_segment_millicredits BIGINT UNSIGNED NOT NULL,  -- integer millicredits, 1 credit = 1000. NEVER a float.
  currency       VARCHAR(8) NOT NULL DEFAULT 'credit',
  effective_from DATETIME NOT NULL,            -- UTC, always
  effective_to   DATETIME NULL,                -- UTC, exclusive upper bound. NULL = still in effect
  status         ENUM('active','archived') NOT NULL DEFAULT 'active',
  note           VARCHAR(255) NOT NULL DEFAULT '',
  created_by     BIGINT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- COALESCE(operator_id, 0): a NULL operator_id means "route default", and a unique index would
  -- otherwise ignore those NULLs and permit duplicate default periods. 0 is safe as the sentinel
  -- because ellsms_sms_operators.id is AUTO_INCREMENT and never 0.
  operator_slot  INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_price_period (route_id, operator_slot, effective_from),
  KEY idx_price_lookup (route_id, operator_slot, status, effective_from),
  CONSTRAINT fk_route_price_route FOREIGN KEY (route_id) REFERENCES ellsms_sms_routes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_route_price_operator FOREIGN KEY (operator_id) REFERENCES ellsms_sms_operators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The immutable authoritative pricing decision for one accepted send operation, grouped by
-- (route, operator, unit price) — every message inside one group was priced identically, so the
-- group's total is an exact sum, never an average (STEP 24: correctness over storage). Historical
-- reporting reads THIS table and never ellsms_sms_route_prices.
--
-- `unit_price_millicredits`, `operator_*`, `provider_*`, `route_*`, `pricing_rule_id` and `priced_at`
-- are written once at acceptance and never updated. `committed_cost_credits`/`status` record the
-- SETTLEMENT of that decision (how much of the accepted price was actually spent once the gateway
-- reported), which is a different fact from the price itself — see docs/sms-pricing.md §Snapshots.
CREATE TABLE IF NOT EXISTS ellsms_sms_price_snapshots (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NULL,
  user_id         BIGINT NULL,
  reference_type  VARCHAR(40) NOT NULL,        -- 'direct_send' / 'schedule' / 'bulk_job' / 'autoreply' / ... (same refs the wallet uses)
  reference_id    VARCHAR(191) NOT NULL,
  group_key       VARCHAR(64) NOT NULL,        -- hash of (route, operator, unit price) — makes the row replay-safe
  operator_id     INT UNSIGNED NULL,
  operator_code   VARCHAR(40) NOT NULL DEFAULT 'unknown',
  operator_source VARCHAR(20) NOT NULL DEFAULT 'prefix',  -- how the operator was determined; 'prefix' is a CONFIGURED CLASSIFICATION, not a verified live carrier lookup
  provider_id     INT UNSIGNED NULL,
  provider_code   VARCHAR(40) NOT NULL DEFAULT '',
  route_id        INT UNSIGNED NULL,
  route_code      VARCHAR(40) NOT NULL DEFAULT '',
  message_type    VARCHAR(20) NOT NULL DEFAULT 'default',
  unit_price_millicredits BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency        VARCHAR(8) NOT NULL DEFAULT 'credit',
  price_source    VARCHAR(40) NOT NULL DEFAULT 'route_operator',  -- route_operator | route_default | legacy_fallback | admin_exempt
  pricing_rule_id INT UNSIGNED NULL,           -- ellsms_sms_route_prices.id the decision came from
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  segment_count   INT UNSIGNED NOT NULL DEFAULT 0,
  total_cost_credits BIGINT UNSIGNED NOT NULL DEFAULT 0,     -- accepted (worst-case) cost for this group
  committed_cost_credits BIGINT UNSIGNED NOT NULL DEFAULT 0, -- what was actually spent once settled
  status          ENUM('accepted','settled') NOT NULL DEFAULT 'accepted',
  priced_at       DATETIME NOT NULL,           -- UTC pricing timestamp the decision was resolved against
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_snapshot_group (reference_type, reference_id, group_key),
  KEY idx_snapshot_org (organization_id, created_at),
  KEY idx_snapshot_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-item price snapshot for bulk jobs. A bulk job's recipients can genuinely span several
-- operators at different rates, so the accepted unit price is stored on the ITEM: the worker
-- commits exactly this number and NEVER re-prices, which is what makes a retry cost the same as the
-- first attempt even if an admin changed the rate in between (STEP 24).
-- Guarded ALTER (information_schema check first), consistent with every prior migration here.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'unit_price_millicredits'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_bulk_items
     ADD COLUMN unit_price_millicredits BIGINT UNSIGNED NULL,
     ADD COLUMN price_cost_credits INT UNSIGNED NULL,
     ADD COLUMN price_operator_code VARCHAR(40) NULL,
     ADD COLUMN price_route_id INT UNSIGNED NULL,
     ADD COLUMN price_group_key VARCHAR(64) NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ==========================================================================
-- Seed: the pre-existing behavior, expressed as configuration (see this file's header).
-- ==========================================================================

-- Operators + prefixes reproduce app/bootstrap.php's OPERATOR_PREFIX_MAP, which until now was a
-- hard-coded PHP constant used only for the analytics breakdown. It becomes DATA here so an admin
-- can correct/extend it without a code change (STEP 3 explicitly forbids keeping the Iranian
-- operator list hard-coded in business logic).
INSERT IGNORE INTO ellsms_sms_operators (code, name, country_code, status, priority) VALUES
  ('mci',     'همراه اول', 'IR', 'active', 10),
  ('mtn',     'ایرانسل',   'IR', 'active', 20),
  ('rightel', 'رایتل',     'IR', 'active', 30);

INSERT IGNORE INTO ellsms_sms_operator_prefixes (operator_id, prefix, normalized_prefix, prefix_length, status, priority, active_prefix)
SELECT o.id, p.prefix, p.normalized_prefix, CHAR_LENGTH(p.normalized_prefix), 'active', 0, p.normalized_prefix
FROM ellsms_sms_operators o
JOIN (
            SELECT 'mci' AS code, '0910' AS prefix, '98910' AS normalized_prefix
  UNION ALL SELECT 'mci', '0911', '98911'
  UNION ALL SELECT 'mci', '0912', '98912'
  UNION ALL SELECT 'mci', '0913', '98913'
  UNION ALL SELECT 'mci', '0914', '98914'
  UNION ALL SELECT 'mci', '0915', '98915'
  UNION ALL SELECT 'mci', '0916', '98916'
  UNION ALL SELECT 'mci', '0917', '98917'
  UNION ALL SELECT 'mci', '0918', '98918'
  UNION ALL SELECT 'mci', '0919', '98919'
  UNION ALL SELECT 'mci', '0990', '98990'
  UNION ALL SELECT 'mci', '0991', '98991'
  UNION ALL SELECT 'mci', '0992', '98992'
  UNION ALL SELECT 'mci', '0993', '98993'
  UNION ALL SELECT 'mtn', '0930', '98930'
  UNION ALL SELECT 'mtn', '0933', '98933'
  UNION ALL SELECT 'mtn', '0935', '98935'
  UNION ALL SELECT 'mtn', '0936', '98936'
  UNION ALL SELECT 'mtn', '0937', '98937'
  UNION ALL SELECT 'mtn', '0938', '98938'
  UNION ALL SELECT 'mtn', '0939', '98939'
  UNION ALL SELECT 'mtn', '0901', '98901'
  UNION ALL SELECT 'mtn', '0902', '98902'
  UNION ALL SELECT 'mtn', '0903', '98903'
  UNION ALL SELECT 'mtn', '0905', '98905'
  UNION ALL SELECT 'mtn', '0941', '98941'
  UNION ALL SELECT 'rightel', '0920', '98920'
  UNION ALL SELECT 'rightel', '0921', '98921'
  UNION ALL SELECT 'rightel', '0922', '98922'
) p ON p.code = o.code;

-- One provider + one default route + one route-default price of exactly 1 credit per segment.
-- effective_from is deliberately far in the past (not NOW()) so that a send priced at ANY timestamp
-- — including one replayed from before this migration ran — resolves to it rather than falling
-- through to the fail-closed branch.
INSERT INTO ellsms_sms_providers (code, name, description, status, priority)
SELECT 'legacy', 'ارائه‌دهنده‌ی پیش‌فرض', 'ارائه‌دهنده‌ی سازگاری — رفتار قیمت‌گذاری پیش از فعال‌سازی قیمت‌گذاری مسیرمحور', 'active', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ellsms_sms_providers WHERE code = 'legacy');

INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, priority, default_slot)
SELECT p.id, 'default', 'مسیر پیش‌فرض', 'default', 'active', 1, 0, 'default'
FROM ellsms_sms_providers p
WHERE p.code = 'legacy'
  AND NOT EXISTS (SELECT 1 FROM ellsms_sms_routes r WHERE r.provider_id = p.id AND r.code = 'default');

INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status, note)
SELECT r.id, NULL, 0, 1000, 'credit', '2000-01-01 00:00:00', NULL, 'active', 'seeded legacy parity: 1 credit per segment'
FROM ellsms_sms_routes r
JOIN ellsms_sms_providers p ON p.id = r.provider_id AND p.code = 'legacy'
WHERE r.code = 'default'
  AND NOT EXISTS (SELECT 1 FROM ellsms_sms_route_prices rp WHERE rp.route_id = r.id AND rp.operator_id IS NULL);
