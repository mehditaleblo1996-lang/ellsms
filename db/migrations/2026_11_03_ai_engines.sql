-- AI engines (موتورهای هوش مصنوعی), phase 1 of the AI SMS secretary (docs/ai-engines.md,
-- app/Ai/*, public/ai-engines.php).
--
-- A platform admin connects AI providers the same way SMS gateways are connected: a provider is an
-- endpoint (OpenAI, Anthropic, OpenRouter, Ollama, or any OpenAI-compatible server such as a Hermes
-- deployment), its credential lives in its OWN encrypted vault, "تست اتصال" checks it and
-- "تشخیص مدل‌ها" lists the provider's models into ellsms_ai_models — switched OFF until the admin
-- turns them on. The admin then gives accounts the models they may use.
--
-- Nothing here touches a backend-owned table, and no table outside this file is altered. Guarded by
-- IF NOT EXISTS, so re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. Providers. type decides the wire protocol (app/Ai/AiAdapters.php); base_url is where it is
--    served. auth_scheme 'bearer' sends "Authorization: Bearer <key>", 'header' sends the key raw in
--    auth_header, 'none' sends nothing (a Hermes on the private network often needs no key).
--    Anthropic always uses x-api-key and ignores auth_scheme.
--    allow_private_network: off = the host must resolve to a public address, the connection is
--    pinned to the address that was checked, and plain http:// is refused; on = an internal address
--    (http://host.docker.internal:8642/v1) is allowed. Only an admin can set it.
--    The last_* columns are written by every connection test and shown in the provider list.
CREATE TABLE IF NOT EXISTS ellsms_ai_providers (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(40) NOT NULL,
  name                  VARCHAR(120) NOT NULL,
  type                  ENUM('openai_compatible','openai','anthropic','openrouter','ollama') NOT NULL,
  status                ENUM('active','disabled') NOT NULL DEFAULT 'active',
  base_url              VARCHAR(2048) NOT NULL DEFAULT '',
  auth_scheme           ENUM('bearer','header','none') NOT NULL DEFAULT 'bearer',
  auth_header           VARCHAR(80) NOT NULL DEFAULT '',
  extra_headers_json    JSON NULL,
  allow_private_network TINYINT(1) NOT NULL DEFAULT 0,
  connect_timeout_ms    INT UNSIGNED NOT NULL DEFAULT 5000,
  request_timeout_ms    INT UNSIGNED NOT NULL DEFAULT 60000,
  last_test_status      ENUM('never_tested','healthy','unhealthy') NOT NULL DEFAULT 'never_tested',
  last_tested_at        TIMESTAMP NULL DEFAULT NULL,
  last_success_at       TIMESTAMP NULL DEFAULT NULL,
  last_failure_at       TIMESTAMP NULL DEFAULT NULL,
  last_error_code       VARCHAR(80) NULL,
  last_error_message    VARCHAR(500) NULL,
  last_latency_ms       INT UNSIGNED NULL,
  created_by            BIGINT NULL,                -- = user_.id
  updated_by            BIGINT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Provider credentials: AES-256-GCM, key derived from AI_MASTER_KEY (or SMS_GATEWAY_MASTER_KEY)
--    with an AI-only purpose label, so this vault and the SMS gateway vault never share a key
--    (app/Ai/AiSecrets.php). Never rendered back, never logged.
CREATE TABLE IF NOT EXISTS ellsms_ai_provider_secrets (
  provider_id     INT UNSIGNED NOT NULL,
  secret_key      VARCHAR(60) NOT NULL,
  ciphertext      VARBINARY(4096) NOT NULL,
  nonce           VARBINARY(12) NOT NULL,
  tag             VARBINARY(16) NOT NULL,
  key_fingerprint VARCHAR(16) NOT NULL DEFAULT '',
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider_id, secret_key),
  CONSTRAINT fk_ai_secret_provider FOREIGN KEY (provider_id) REFERENCES ellsms_ai_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Models. One row per (provider, model id the provider reports). Discovery inserts new models
--    DISABLED and never switches an existing one on or off; a model the provider stopped listing
--    gets missing_since, so the admin sees it instead of it silently vanishing.
--    input/output_cost_per_million: what WE pay the provider (USD by default) — the base for the
--    phase-3 tariff. max_output_tokens: the cap sent with every request to this model.
CREATE TABLE IF NOT EXISTS ellsms_ai_models (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id             INT UNSIGNED NOT NULL,
  external_model_id       VARCHAR(191) NOT NULL,
  display_name            VARCHAR(191) NOT NULL,
  status                  ENUM('active','disabled') NOT NULL DEFAULT 'disabled',
  context_window          INT UNSIGNED NULL,
  max_output_tokens       INT UNSIGNED NULL,
  input_cost_per_million  DECIMAL(20,8) NULL,
  output_cost_per_million DECIMAL(20,8) NULL,
  cost_currency           CHAR(3) NOT NULL DEFAULT 'USD',
  source                  ENUM('discovered','manual') NOT NULL DEFAULT 'discovered',
  discovered_at           TIMESTAMP NULL DEFAULT NULL,
  last_seen_at            TIMESTAMP NULL DEFAULT NULL,
  missing_since           TIMESTAMP NULL DEFAULT NULL,
  last_tested_at          TIMESTAMP NULL DEFAULT NULL,
  last_test_ok            TINYINT(1) NULL,
  last_error_code         VARCHAR(80) NULL,
  last_latency_ms         INT UNSIGNED NULL,
  sort_order              INT NOT NULL DEFAULT 100,
  updated_by              BIGINT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_model (provider_id, external_model_id),
  KEY idx_status (status),
  CONSTRAINT fk_ai_model_provider FOREIGN KEY (provider_id) REFERENCES ellsms_ai_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Test runs: one row per connection test, discovery, model test or playground message. No
--    prompt or reply text is stored — only the outcome, so the table is safe to keep and to show.
CREATE TABLE IF NOT EXISTS ellsms_ai_test_runs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id       INT UNSIGNED NOT NULL,
  model_id          INT UNSIGNED NULL,
  kind              ENUM('connection','discovery','model','playground') NOT NULL,
  actor_user_id     BIGINT NULL,
  ok                TINYINT(1) NOT NULL,
  error_code        VARCHAR(80) NULL,
  error_message     VARCHAR(500) NULL,
  http_status       SMALLINT UNSIGNED NULL,
  latency_ms        INT UNSIGNED NULL,
  discovered_count  INT UNSIGNED NULL,
  input_tokens      INT UNSIGNED NULL,
  output_tokens     INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_provider_created (provider_id, created_at),
  KEY idx_created (created_at),
  CONSTRAINT fk_ai_run_provider FOREIGN KEY (provider_id) REFERENCES ellsms_ai_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Which models an account may use. An account with rows here has a CUSTOM set (custom = 1 in
--    ellsms_ai_accounts); without, it inherits its parent's set (a reseller's customer) or, at the
--    top, the platform default (settings ai_default_model_id / ai_default_access).
--    A sub-account never gets more than its parent: the effective set is always intersected with
--    the parent's (app/Ai/AiEngines.php, ai_access_resolve()).
CREATE TABLE IF NOT EXISTS ellsms_ai_accounts (
  user_id          BIGINT NOT NULL PRIMARY KEY,     -- = user_.id
  custom           TINYINT(1) NOT NULL DEFAULT 0,
  default_model_id INT UNSIGNED NULL,
  updated_by       BIGINT NULL,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_ai_model_access (
  user_id    BIGINT NOT NULL,                       -- = user_.id
  model_id   INT UNSIGNED NOT NULL,
  granted_by BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, model_id),
  KEY idx_model (model_id),
  CONSTRAINT fk_ai_access_model FOREIGN KEY (model_id) REFERENCES ellsms_ai_models (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
