-- Phase 7: centralized in-panel notification center.
CREATE TABLE IF NOT EXISTS ellsms_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  organization_id BIGINT NULL,
  event_key VARCHAR(100) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body VARCHAR(1000) NOT NULL DEFAULT '',
  action_url VARCHAR(500) NOT NULL DEFAULT '',
  severity ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_id (user_id, id),
  KEY idx_notifications_user_unread (user_id, read_at, id),
  KEY idx_notifications_org_id (organization_id, id),
  KEY idx_notifications_event_id (event_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
