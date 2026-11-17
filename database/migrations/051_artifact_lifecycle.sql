SET NAMES utf8mb4;

-- P11 C: the lifetime of the files Mara made (stu_console_artifacts). A deleted file leaves a note
-- in its chat message (who or what deleted it and when), so the message that pointed at the file
-- still says what happened. The note goes with the account, the chat and the message.
CREATE TABLE IF NOT EXISTS stu_console_artifact_tombstones (
  artifact_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  orig_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  reason ENUM('person','retention') NOT NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (artifact_id),
  KEY idx_artifact_tombstones_message (user_id, message_id),
  CONSTRAINT fk_artifact_tombstones_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_artifact_tombstones_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_artifact_tombstones_message FOREIGN KEY (message_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The retention sweep looks for an account's oldest files.
ALTER TABLE stu_console_artifacts
  ADD INDEX IF NOT EXISTS idx_console_artifact_age (user_id, created_at);

INSERT INTO stu_schema_migrations (version) VALUES ('051_artifact_lifecycle')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
