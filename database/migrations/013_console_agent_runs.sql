SET NAMES utf8mb4;

-- P05: one durable run per user turn and generation request.
-- A crashed "running" run is NOT silently restarted. Its provider state
-- may be unknown; a new run requires an explicit new action.
CREATE TABLE IF NOT EXISTS stu_console_agent_runs (
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  turn_id BIGINT UNSIGNED NOT NULL,
  run_key VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'base',
  response_message_id BIGINT UNSIGNED NULL,
  status ENUM('queued','running','waiting_tool','interrupted','completed','failed','cancelled')
    NOT NULL DEFAULT 'queued',
  error_code VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  started_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at DATETIME(3) NULL,
  PRIMARY KEY (run_id),
  UNIQUE KEY uq_agent_turn (user_id,session_id,turn_id,run_key),
  KEY idx_agent_owner (user_id,session_id,status),
  KEY idx_agent_response (user_id,session_id,response_message_id),
  CONSTRAINT fk_agent_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_turn FOREIGN KEY (turn_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('013_console_agent_runs')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
