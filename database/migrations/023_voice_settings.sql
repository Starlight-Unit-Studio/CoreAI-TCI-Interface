SET NAMES utf8mb4;

-- 0.6.1: read aloud with Mara's voice (P20.3). Per account: read answers
-- aloud automatically (off unless the account switches it on in KI-Profil).
CREATE TABLE IF NOT EXISTS stu_user_voice_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  auto_read TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_voice_settings_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('023_voice_settings')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
