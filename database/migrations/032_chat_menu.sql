-- CoreAI 0.6.87: the chat menu (like the ChatGPT reference): "Anpinnen" keeps a chat at the top
-- of the list. Archiving a chat releases its pin.
SET NAMES utf8mb4;

ALTER TABLE stu_console_sessions
  ADD COLUMN IF NOT EXISTS pinned_at DATETIME NULL AFTER archived_at;

INSERT INTO stu_schema_migrations (version) VALUES ('032_chat_menu')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
