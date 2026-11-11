SET NAMES utf8mb4;

-- Chat-native workspaces. A project belongs to exactly one account.
-- The mode is immutable after creation: switching an isolated project to
-- account-wide context must never happen as a side effect of a UI control.
CREATE TABLE IF NOT EXISTS stu_console_projects (
  id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  context_mode ENUM('open','isolated') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_console_projects_user (user_id, created_at),
  CONSTRAINT fk_console_projects_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stu_console_sessions
  ADD COLUMN IF NOT EXISTS project_id CHAR(32) NULL AFTER user_id,
  ADD INDEX IF NOT EXISTS idx_console_project_sessions (user_id, project_id, updated_at);

INSERT INTO stu_schema_migrations (version) VALUES ('037_chat_projects')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
