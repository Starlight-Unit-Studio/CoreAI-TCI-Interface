SET NAMES utf8mb4;

-- P09 C: a chat can branch off at one of Mara's replies into a new chat.
-- The branch keeps where it came from; the original chat is never changed.
-- No foreign key on purpose: the original may be deleted later, the branch stays.
ALTER TABLE stu_console_sessions
  ADD COLUMN IF NOT EXISTS branch_source_id VARCHAR(40) NULL AFTER project_id,
  ADD COLUMN IF NOT EXISTS branch_source_message_id BIGINT UNSIGNED NULL AFTER branch_source_id,
  ADD COLUMN IF NOT EXISTS branch_point_id BIGINT UNSIGNED NULL AFTER branch_source_message_id,
  ADD INDEX IF NOT EXISTS idx_console_session_branch (user_id, branch_source_id);

INSERT INTO stu_schema_migrations (version) VALUES ('046_chat_branches')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
