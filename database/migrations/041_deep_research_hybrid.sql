SET NAMES utf8mb4;

-- CoreAI 0.7.42: durable hybrid Deep Research checkpoints.
-- Structured, scoped per-run state stores verified source references, compact
-- worker summaries and current phase; never model chain-of-thought or raw prompts.
CREATE TABLE IF NOT EXISTS stu_deep_research_memory (
  run_id BIGINT UNSIGNED NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  state_json MEDIUMTEXT NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidence is retained independently, preserving source excerpts across
-- context compaction, retries and a final independent Citation Agent review.
CREATE TABLE IF NOT EXISTS stu_deep_research_evidence (
  run_id BIGINT UNSIGNED NOT NULL,
  n SMALLINT UNSIGNED NOT NULL,
  excerpt MEDIUMTEXT NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('041_deep_research_hybrid')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
