-- Issue #10: minimal provider-outage tracking -- a persisted consecutive-failure counter per
-- provider/gateway, admin-visible, that triggers ONE rate-limited alert on outage and ONE on
-- recovery. Deliberately NOT the full health model (UP/DEGRADED/DOWN/UNKNOWN with active+passive
-- checks) issue #16 will build, nor the full multi-channel/severity/ack/escalation alerting issue
-- #15 will build -- this is the minimal real signal those two larger issues plug into later.
--
-- `provider_key` is either 'legacy_backend' (the single legacy REST API path) or
-- 'gateway:<gateway_id>' (one row per configured SMS gateway, app/Sms/GatewayCache.php) -- a plain
-- string key rather than a foreign key, since it names two structurally different things and
-- neither owns a shared id space with the other.
CREATE TABLE IF NOT EXISTS ellsms_provider_health_state (
  provider_key         VARCHAR(64) NOT NULL PRIMARY KEY,
  status               ENUM('healthy','outage') NOT NULL DEFAULT 'healthy',
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  last_failure_at      DATETIME NULL,
  last_success_at      DATETIME NULL,
  last_alert_at        DATETIME NULL,
  last_error           VARCHAR(500) NULL,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
