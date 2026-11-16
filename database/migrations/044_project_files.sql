SET NAMES utf8mb4;

-- 0.7.69 (P09): durable project files. A project keeps the text files Mara
-- builds in its chats and the files the account edits, with every earlier
-- state as a revision. Text only; nothing here is executed or served as HTML.
CREATE TABLE IF NOT EXISTS stu_console_project_files (
  id CHAR(32) NOT NULL,
  project_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  content MEDIUMTEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_file_path (project_id, path),
  KEY idx_project_files_user (user_id, project_id),
  CONSTRAINT fk_project_files_project FOREIGN KEY (project_id)
    REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_files_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every state of a file, the current one included. source says where it came
-- from: a build in a project chat, a new file, an edit, a rename or a restore.
CREATE TABLE IF NOT EXISTS stu_console_project_file_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id CHAR(32) NOT NULL,
  project_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  path VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  content MEDIUMTEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  source ENUM('build','create','edit','rename','restore') NOT NULL,
  session_id VARCHAR(40) NULL,
  message_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_file_revision (file_id, revision),
  KEY idx_project_file_revisions_user (user_id, project_id),
  CONSTRAINT fk_project_file_revisions_file FOREIGN KEY (file_id)
    REFERENCES stu_console_project_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_file_revisions_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('044_project_files')
  ON DUPLICATE KEY UPDATE applied_at=applied_at;
