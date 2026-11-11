SET NAMES utf8mb4;

-- A rating persists in stu_chat_reactions (existing). Each *change* is also
-- recorded as a durable one-time prompt for Mara's no-thinking follow-up.
CREATE TABLE IF NOT EXISTS stu_console_feedback_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  rating ENUM('up','down') NOT NULL,
  status ENUM('pending','running','done','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  response_message_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_feedback_events_session (user_id, session_id, status, created_at),
  KEY idx_feedback_events_message (user_id, message_id, id),
  UNIQUE KEY uq_feedback_event_reply (response_message_id),
  CONSTRAINT fk_feedback_event_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_event_session FOREIGN KEY (session_id)
    REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_event_message FOREIGN KEY (message_id)
    REFERENCES stu_chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_event_reply FOREIGN KEY (response_message_id)
    REFERENCES stu_chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('035_feedback_followups')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
