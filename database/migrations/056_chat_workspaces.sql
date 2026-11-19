SET NAMES utf8mb4;

-- An ordinary chat is a first-class development workspace. Its backing
-- project holds the existing secure file/run/VCS stores, but does NOT turn
-- the conversation into a user-facing Project or isolate global context.
-- The FK removes the backing files when the chat is permanently deleted.
ALTER TABLE stu_console_projects
  ADD COLUMN IF NOT EXISTS workspace_session_id VARCHAR(40) NULL AFTER description,
  ADD UNIQUE KEY IF NOT EXISTS uq_console_workspace_session (workspace_session_id);

ALTER TABLE stu_console_projects
  ADD CONSTRAINT fk_console_workspace_session
    FOREIGN KEY (workspace_session_id)
    REFERENCES stu_console_sessions(id) ON DELETE CASCADE;

INSERT INTO stu_schema_migrations (version) VALUES ('056_chat_workspaces')
  ON DUPLICATE KEY UPDATE applied_at=applied_at;
