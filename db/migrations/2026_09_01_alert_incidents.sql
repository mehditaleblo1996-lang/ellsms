-- ELLSMS — unified alert/incident subsystem (issue #15).
--
-- ONE incident table for every alert source (provider health today, anything else later) --
-- deliberately not a per-source table, so acknowledgement/escalation/audit logic lives in exactly
-- one place (app/Alerting/AlertManager.php) rather than being reimplemented per source.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_alert_incidents (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Bounded, code-defined identifier for WHAT is alerting (e.g. "provider_down:legacy_backend",
  -- "queue_backlog:bulk_campaign") -- never a raw message id or other unbounded value (see
  -- docs/observability-cardinality.md's same rule; this key also becomes a Prometheus label).
  alert_key           VARCHAR(160) NOT NULL,
  severity            ENUM('warning','critical','emergency') NOT NULL,
  status              ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  title               VARCHAR(255) NOT NULL,
  message             TEXT NOT NULL,
  context_json        TEXT NULL,
  first_fired_at      DATETIME NOT NULL,
  last_fired_at       DATETIME NOT NULL,
  next_repeat_at      DATETIME NULL,
  fire_count          INT UNSIGNED NOT NULL DEFAULT 1,
  acknowledged_by     BIGINT UNSIGNED NULL,
  acknowledged_at     DATETIME NULL,
  resolved_at         DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Guards the main race window: two concurrent first-fires for the same alert_key can't both
  -- INSERT a new 'open' row. The full "at most one active (open OR acknowledged) incident per
  -- key" invariant is enforced by AlertManager's own lookup-before-insert logic (acknowledging an
  -- incident UPDATEs the existing row's status rather than creating a new one, so an active
  -- incident never actually needs a second row while it's open). A resolved incident's alert_key
  -- becomes reusable for the next occurrence, so history is never overwritten.
  UNIQUE KEY uniq_active_alert_key (alert_key, status),
  KEY idx_alert_status (status),
  KEY idx_alert_severity_status (severity, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_alert_dispatch_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id  BIGINT UNSIGNED NOT NULL,
  channel      ENUM('telegram','email') NOT NULL,
  outcome      ENUM('sent','failed') NOT NULL,
  detail       VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dispatch_incident (incident_id),
  CONSTRAINT fk_alert_dispatch_incident FOREIGN KEY (incident_id) REFERENCES ellsms_alert_incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
