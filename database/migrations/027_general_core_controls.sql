SET NAMES utf8mb4;

-- 0.6.71: General page, independent per-account controls.
-- A separate table avoids overwriting existing AI profile values when a
-- General switch changes.  Nothing modifies operator Modelfiles.
CREATE TABLE IF NOT EXISTS stu_user_general_settings (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  auto_higher_core TINYINT(1) NOT NULL DEFAULT 0,
  thinking_level VARCHAR(16) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO stu_schema_migrations (version) VALUES ('027_general_core_controls')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
