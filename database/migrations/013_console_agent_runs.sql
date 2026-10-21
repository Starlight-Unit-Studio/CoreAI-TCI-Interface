SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS stu_console_agent_runs (
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  turn_id BIGINT UNSIGNED NOT NULL,
  response_message_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM('queued','running','interrupted','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  error_code VARCHAR(80) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL,
  PRIMARY KEY (run_id),
  KEY idx_agent_owner (user_id,session_id,status),
  KEY idx_agent_turn (turn_id),
  CONSTRAINT fk_agent_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_turn FOREIGN KEY (turn_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO stu_schema_migrations (version) VALUES ('013_console_agent_runs')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
