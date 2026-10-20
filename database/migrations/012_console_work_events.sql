SET NAMES utf8mb4;

-- P06: account-scoped operational events, not model chain of thought.
CREATE TABLE IF NOT EXISTS stu_console_work_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  turn_id BIGINT UNSIGNED NOT NULL,
  run_key VARCHAR(48) NOT NULL DEFAULT 'base',
  response_message_id BIGINT UNSIGNED NULL,
  kind VARCHAR(16) NOT NULL,
  state VARCHAR(16) NOT NULL,
  title VARCHAR(120) NOT NULL,
  details_json TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_work_turn (user_id,session_id,turn_id,id),
  KEY idx_work_response (user_id,session_id,response_message_id),
  CONSTRAINT fk_work_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_turn FOREIGN KEY (turn_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('012_console_work_events')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
