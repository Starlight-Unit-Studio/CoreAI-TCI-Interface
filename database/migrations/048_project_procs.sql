SET NAMES utf8mb4;

-- P10 C: a process of a project keeps running in the sandbox container of the Python worker
-- (a program, a shell or a development server). The row keeps the latest output with its end
-- position, the real exit code and the state. Input lines and requests to a development server
-- inside the container wait in their own tables until the worker takes them. Everything belongs
-- to one project of one account and goes with it.
CREATE TABLE IF NOT EXISTS stu_console_project_procs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  project_id CHAR(32) NOT NULL,
  command VARCHAR(400) NOT NULL,
  port SMALLINT UNSIGNED NULL,
  status ENUM('queued','running','exited','stopped','error') NOT NULL DEFAULT 'queued',
  exit_code INT NULL,
  error VARCHAR(255) NULL,
  output MEDIUMTEXT NULL,
  output_end BIGINT UNSIGNED NOT NULL DEFAULT 0,
  files_count INT UNSIGNED NULL,
  files_sha256 CHAR(64) NULL,
  synced_at DATETIME NULL,
  worker_pid INT NULL,
  beat_at DATETIME NULL,
  seen_at DATETIME NULL,
  stop_requested_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_project_procs_queue (status, id),
  KEY idx_project_procs_project (user_id, project_id, id),
  CONSTRAINT fk_project_procs_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_procs_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_proc_input (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proc_id BIGINT UNSIGNED NOT NULL,
  data TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_project_proc_input (proc_id, sent_at, id),
  CONSTRAINT fk_project_proc_input FOREIGN KEY (proc_id) REFERENCES stu_console_project_procs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_project_proc_fetch (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proc_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(8) NOT NULL DEFAULT 'GET',
  path VARCHAR(1000) NOT NULL,
  content_type VARCHAR(160) NULL,
  body MEDIUMBLOB NULL,
  status ENUM('queued','done','error') NOT NULL DEFAULT 'queued',
  response_status SMALLINT UNSIGNED NULL,
  response_path VARCHAR(1000) NULL,
  response_type VARCHAR(160) NULL,
  response_body MEDIUMBLOB NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_project_proc_fetch_queue (status, id),
  KEY idx_project_proc_fetch_proc (proc_id, id),
  CONSTRAINT fk_project_proc_fetch FOREIGN KEY (proc_id) REFERENCES stu_console_project_procs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('048_project_procs')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
