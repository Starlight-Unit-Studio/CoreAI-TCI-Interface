-- CoreAI 0.7.1: Settings > Pets. Pets a person uploaded: one sprite sheet each (a row of square
-- frames), kept in var/console_media; deleted with the account.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_user_pets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  stored_name VARCHAR(80) NOT NULL,
  mime_type VARCHAR(20) NOT NULL,
  frames TINYINT UNSIGNED NOT NULL,
  frame_px SMALLINT UNSIGNED NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_pets_user (user_id),
  CONSTRAINT fk_user_pets_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('034_user_pets')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
