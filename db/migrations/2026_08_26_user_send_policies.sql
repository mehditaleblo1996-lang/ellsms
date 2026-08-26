CREATE TABLE IF NOT EXISTS ellsms_user_send_policies (
  user_id BIGINT NOT NULL,
  rate_limit_enabled TINYINT(1) NOT NULL DEFAULT 0,
  rate_limit_count INT UNSIGNED NOT NULL DEFAULT 0,
  rate_limit_window_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  ip_restriction_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by_user_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_user_send_policy_updated (updated_at, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_user_send_allowed_ips (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  ip_or_cidr VARCHAR(64) NOT NULL,
  created_by_user_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_send_allowed_ip (user_id, ip_or_cidr),
  KEY idx_user_send_allowed_ip_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
