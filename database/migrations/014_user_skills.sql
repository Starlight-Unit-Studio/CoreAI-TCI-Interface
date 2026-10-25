SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS stu_user_skills (
  user_id BIGINT UNSIGNED NOT NULL,
  skill_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id,skill_id),
  CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO stu_schema_migrations (version) VALUES ('014_user_skills')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
