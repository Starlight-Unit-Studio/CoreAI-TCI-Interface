SET NAMES utf8mb4;

-- P11 B: working states are merged. A merge is a version with two parents: the newest version of
-- the working state it lands on (parent_id) and the newest version of the one merged in
-- (merge_parent_id). The next merge of the same working states starts from there, and the Git
-- export writes both parents.
ALTER TABLE stu_console_project_commits
  ADD COLUMN IF NOT EXISTS merge_parent_id BIGINT UNSIGNED NULL AFTER parent_id;

-- A file that changes because of a merge gets a revision of its own kind.
ALTER TABLE stu_console_project_file_revisions
  MODIFY COLUMN source ENUM('build','create','edit','rename','restore','mara','branch','merge') NOT NULL;

INSERT INTO stu_schema_migrations (version) VALUES ('050_project_merges')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
