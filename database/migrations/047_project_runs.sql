SET NAMES utf8mb4;

-- P10 B: a command runs on the saved files of a project in the sandbox container of the
-- Python worker (no host access, no secrets, hard limits). The row keeps the real output,
-- the exit code and the duration. A run belongs to one project of one account and goes with it.
CREATE TABLE IF NOT EXISTS stu_console_project_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  project_id CHAR(32) NOT NULL,
  session_id VARCHAR(40) NULL,
  command VARCHAR(400) NOT NULL,
  status ENUM('queued','running','done','error','cancelled') NOT NULL DEFAULT 'queued',
  exit_code INT NULL,
  stdout MEDIUMTEXT NULL,
  stderr MEDIUMTEXT NULL,
  error VARCHAR(255) NULL,
  duration_ms INT UNSIGNED NULL,
  files_count INT UNSIGNED NULL,
  files_sha256 CHAR(64) NULL,
  worker_pid INT NULL,
  cancel_requested_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_project_runs_queue (status, id),
  KEY idx_project_runs_project (user_id, project_id, id),
  CONSTRAINT fk_project_runs_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_runs_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('047_project_runs')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
