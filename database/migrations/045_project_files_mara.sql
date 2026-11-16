SET NAMES utf8mb4;

-- 0.7.71 (P09 B): Mara changes single project files herself in a project chat.
-- Her changes are revisions of their own ("mara"), restorable like every other state.
ALTER TABLE stu_console_project_file_revisions
  MODIFY COLUMN source ENUM('build','create','edit','rename','restore','mara') NOT NULL;

INSERT INTO stu_schema_migrations (version) VALUES ('045_project_files_mara')
  ON DUPLICATE KEY UPDATE applied_at=applied_at;
