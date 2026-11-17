SET NAMES utf8mb4;

-- P11 A: versions of a project. A version ("Sicherung") keeps every file of one moment as a
-- path and the sha256 of its content; each content is stored once per project. A working state
-- ("Arbeitsstand", a branch) points at its newest version. The files of the active working state
-- are the project files themselves (stu_console_project_files); an inactive working state keeps
-- its own unsaved files in stu_console_project_worktrees, so two working states never change
-- each other. Everything belongs to one project of one account and goes with it.
CREATE TABLE IF NOT EXISTS stu_console_project_blobs (
  project_id CHAR(32) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  content MEDIUMTEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, sha256),
  KEY idx_project_blobs_user (user_id),
  CONSTRAINT fk_project_blobs_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_blobs_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_branches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  head_commit_id BIGINT UNSIGNED NULL,
  worktree_saved TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_branch_name (project_id, name),
  KEY idx_project_branches_user (user_id, project_id),
  CONSTRAINT fk_project_branches_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_branches_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_commits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  parent_id BIGINT UNSIGNED NULL,
  message VARCHAR(200) NOT NULL,
  author ENUM('person','mara','auto') NOT NULL DEFAULT 'person',
  files_count INT UNSIGNED NOT NULL DEFAULT 0,
  bytes INT UNSIGNED NOT NULL DEFAULT 0,
  tree_sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_commits_project (project_id, id),
  CONSTRAINT fk_project_commits_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_commits_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_commits_branch FOREIGN KEY (branch_id) REFERENCES stu_console_project_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_commit_files (
  commit_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  sha256 CHAR(64) NOT NULL,
  PRIMARY KEY (commit_id, path),
  CONSTRAINT fk_project_commit_files FOREIGN KEY (commit_id) REFERENCES stu_console_project_commits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_worktrees (
  branch_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  sha256 CHAR(64) NOT NULL,
  PRIMARY KEY (branch_id, path),
  CONSTRAINT fk_project_worktrees_branch FOREIGN KEY (branch_id) REFERENCES stu_console_project_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_vcs (
  project_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  active_branch_id BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_project_vcs_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_vcs_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A file that changes because another working state or version comes into the project files
-- gets a revision of its own kind.
ALTER TABLE stu_console_project_file_revisions
  MODIFY COLUMN source ENUM('build','create','edit','rename','restore','mara','branch') NOT NULL;

INSERT INTO stu_schema_migrations (version) VALUES ('049_project_versions')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
