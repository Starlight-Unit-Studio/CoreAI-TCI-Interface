SET NAMES utf8mb4;

-- 0.5.91: per-account personalization, independent of the operator Modelfile.
-- Foreign key removes preferences when an account is deleted.
CREATE TABLE IF NOT EXISTS stu_user_personalization (
  user_id BIGINT UNSIGNED NOT NULL,
  preferences_json TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_personalization_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('022_user_personalization')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
