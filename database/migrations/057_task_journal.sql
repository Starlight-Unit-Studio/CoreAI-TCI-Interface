SET NAMES utf8mb4;

-- P13: durable, owner-scoped task manifest and idempotent tool-step claims.
-- The user request remains in stu_chat_messages; only its owned turn ID is
-- referenced here. No system prompts, private reasoning, credentials,
-- source code or raw tool output are stored in the task journal.
CREATE TABLE IF NOT EXISTS stu_console_task_journal (
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  turn_id BIGINT UNSIGNED NOT NULL,
  task_kind VARCHAR(24) NOT NULL DEFAULT 'agent',
  status ENUM('queued','running','waiting_tool','completed','interrupted',
    'blocked','failed','cancelled') NOT NULL DEFAULT 'queued',
  phase ENUM('prepare','execute','verify','finalize') NOT NULL DEFAULT 'prepare',
  checkpoint_version INT UNSIGNED NOT NULL DEFAULT 0,
  resume_checkpoint_version INT UNSIGNED NULL,
  last_step INT UNSIGNED NOT NULL DEFAULT 0,
  last_verified_step INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL,
  requirements_json TEXT NOT NULL,
  evidence_json MEDIUMTEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at DATETIME(3) NULL,
  PRIMARY KEY (run_id),
  KEY idx_task_owner (user_id,session_id,status),
  KEY idx_task_stale (status,updated_at),
  CONSTRAINT fk_task_run FOREIGN KEY (run_id)
    REFERENCES stu_console_agent_runs(run_id) ON DELETE CASCADE,
  CONSTRAINT fk_task_owner FOREIGN KEY (user_id) REFERENCES stu_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_task_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_task_turn FOREIGN KEY (turn_id) REFERENCES stu_chat_messages(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An attempted tool step is a durable claim BEFORE the tool starts. An
-- interrupted/uncertain side effect is NEVER automatically replayed.
CREATE TABLE IF NOT EXISTS stu_console_task_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  step_no INT UNSIGNED NOT NULL,
  tool_name VARCHAR(32) NOT NULL,
  input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('claimed','verified','error','uncertain') NOT NULL DEFAULT 'claimed',
  proof_kind VARCHAR(32) NULL,
  proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  proof_json TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_action (run_id,step_no),
  KEY idx_task_action_owner (user_id,session_id,run_id),
  CONSTRAINT fk_task_action_run FOREIGN KEY (run_id)
    REFERENCES stu_console_agent_runs(run_id) ON DELETE CASCADE,
  CONSTRAINT fk_task_action_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_action_session FOREIGN KEY (session_id)
    REFERENCES stu_console_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('057_task_journal')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
