SET NAMES utf8mb4;

-- 0.6.81: Settings > Benachrichtigungen.
-- Per account and category the chosen channels ('' = aus, 'push', 'email', 'push,email').
-- Push subscriptions of the account's browsers (Web Push, RFC 8030/8291/8292). The endpoint
-- is stored once (hash unique); the browser keys are public keys and an auth secret that only
-- this server needs to encrypt messages for that browser. Deleting an account removes both.
CREATE TABLE IF NOT EXISTS stu_notification_prefs (
  user_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(24) NOT NULL,
  channels VARCHAR(24) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, category),
  CONSTRAINT fk_notification_prefs_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(120) NOT NULL,
  auth VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_success_at TIMESTAMP NULL DEFAULT NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('028_notifications')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
