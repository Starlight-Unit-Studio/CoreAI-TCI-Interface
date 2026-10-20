SET NAMES utf8mb4;

-- 010_console_tools bleibt fuer den frueher vorbereiteten Tool-Stand reserviert.
-- Die Dateiausgabe setzt die im Repository vorhandenen Migrationen 001 bis 009 voraus.
CREATE TABLE IF NOT EXISTS stu_ember_py_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  code MEDIUMTEXT NOT NULL,
  stdout MEDIUMTEXT NULL,
  stderr MEDIUMTEXT NULL,
  exit_code INT NULL,
  duration_ms INT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'console',
  trigger_user_id BIGINT UNSIGNED NULL,
  recipient_uid BIGINT UNSIGNED NULL,
  error TEXT NULL,
  worker_pid INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_py_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stu_ember_py_jobs
  ADD COLUMN IF NOT EXISTS session_id VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS trigger_message_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS trigger_revision INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS artifacts_json MEDIUMTEXT NULL,
  ADD INDEX IF NOT EXISTS idx_py_console_session (recipient_uid, session_id, status);

CREATE TABLE IF NOT EXISTS stu_console_artifacts (
  id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NOT NULL,
  producer VARCHAR(32) NOT NULL DEFAULT 'python',
  storage_key VARCHAR(96) NOT NULL,
  orig_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  partial TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_console_artifact_storage (storage_key),
  KEY idx_console_artifact_message (user_id, session_id, message_id),
  KEY idx_console_artifact_job (job_id),
  CONSTRAINT fk_console_artifact_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_artifact_session
    FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_artifact_message
    FOREIGN KEY (message_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('011_console_artifacts')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
