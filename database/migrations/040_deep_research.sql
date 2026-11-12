SET NAMES utf8mb4;

-- 0.7.38: Deep Research (roadmap P15), after the operator's reference screenshots.
-- A run belongs to one account and one chat. Mara writes a plan (title and steps); it starts by
-- itself after a short countdown or when the person presses Starten. The browse worker
-- (tools/ember_browse_worker.py with tools/coreai_deep_research.py) then searches, reads pages,
-- takes notes with source numbers and writes the report. Searches and read pages are kept as
-- activity; nothing of the Core's raw reasoning or prompts is stored.
CREATE TABLE IF NOT EXISTS stu_deep_research_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  status ENUM('planned','editing','queued','running','writing','done','cancelled','error','replaced') NOT NULL DEFAULT 'planned',
  query TEXT NOT NULL,
  title VARCHAR(200) NOT NULL,
  plan_json TEXT NOT NULL,
  progress_json TEXT NULL,
  model VARCHAR(160) NULL,
  num_ctx INT NULL,
  autostart_at DATETIME NULL,
  cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  citations INT UNSIGNED NOT NULL DEFAULT 0,
  report_md MEDIUMTEXT NULL,
  error VARCHAR(400) NULL,
  worker_pid INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  heartbeat_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_dr_owner (user_id, session_id, id),
  KEY idx_dr_due (status, autostart_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The sources of a run, numbered as the report cites them ([3]).
CREATE TABLE IF NOT EXISTS stu_deep_research_sources (
  run_id BIGINT UNSIGNED NOT NULL,
  n SMALLINT UNSIGNED NOT NULL,
  url VARCHAR(2048) NOT NULL,
  title VARCHAR(300) NOT NULL DEFAULT '',
  domain VARCHAR(255) NOT NULL DEFAULT '',
  cited TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (run_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What Mara did, for the Aktivität tab: a step begins, a search (its words and the domains it
-- found), a page read, a short note in her own words.
CREATE TABLE IF NOT EXISTS stu_deep_research_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('step','search','read','note','info') NOT NULL,
  text VARCHAR(600) NOT NULL DEFAULT '',
  domains VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dr_events_run (run_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
