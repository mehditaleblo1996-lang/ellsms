-- Issue #18 — durable, bounded provider-response evidence.
--
-- One row represents one outbound provider HTTP attempt (send or status). It intentionally stores
-- the provider RESPONSE only, never the provider request or credentials. The application bounds
-- raw_response/provider_error_detail before inserting so a pathological provider cannot turn this
-- table into an unbounded payload sink.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_provider_response_audit (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id            INT UNSIGNED NOT NULL,
  connector             ENUM('send','status') NOT NULL,
  request_id            VARCHAR(80) NOT NULL DEFAULT '',
  http_status           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  normalized_outcome    ENUM('SUCCESS','FAILED','UNKNOWN') NOT NULL,
  provider_message_id   VARCHAR(190) NULL,
  provider_error_code   VARCHAR(190) NULL,
  provider_error_detail VARCHAR(500) NULL,
  raw_response           MEDIUMTEXT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_provider_response_gateway_time (gateway_id, created_at),
  KEY idx_provider_response_request (request_id),
  KEY idx_provider_response_outcome (normalized_outcome, created_at),
  CONSTRAINT fk_provider_response_gateway
    FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
