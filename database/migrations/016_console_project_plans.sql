SET NAMES utf8mb4;

-- Durable, account-and-session-bound Markdown project plans. Each revision is
-- immutable; a model never overwrites or loses an earlier approved plan.
CREATE TABLE IF NOT EXISTS stu_console_project_plans (
  id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  source_turn_id BIGINT UNSIGNED NULL,
  response_message_id BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  markdown LONGTEXT NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_console_plan_latest (user_id, session_id, created_at, version),
  KEY idx_console_plan_response (user_id, response_message_id),
  CONSTRAINT fk_console_plan_owner FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_plan_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_plan_turn FOREIGN KEY (source_turn_id) REFERENCES stu_chat_messages(id) ON DELETE SET NULL,
  CONSTRAINT fk_console_plan_response FOREIGN KEY (response_message_id) REFERENCES stu_chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('016_console_project_plans')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
