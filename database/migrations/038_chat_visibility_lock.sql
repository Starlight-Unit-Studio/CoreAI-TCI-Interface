-- 0.7.22: "Chat sperren" hides the chat from ordinary lists and title search.
-- This is a visibility state, NOT encryption or a separate permission boundary.
SET NAMES utf8mb4;

ALTER TABLE stu_console_sessions
  ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL AFTER pinned_at,
  ADD INDEX IF NOT EXISTS idx_console_locked (user_id, locked_at, updated_at);

INSERT INTO stu_schema_migrations (version) VALUES ('038_chat_visibility_lock')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
