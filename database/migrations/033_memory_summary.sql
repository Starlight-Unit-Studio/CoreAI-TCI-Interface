-- CoreAI 0.7.0: Settings > Personalisierung > Erinnerungszusammenfassung. Mara's overview of what
-- she learned about the person, kept until the memories change; deleted with the account.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_memory_summaries (
  user_id BIGINT UNSIGNED NOT NULL,
  summary_md MEDIUMTEXT NOT NULL,
  facts_hash CHAR(64) NOT NULL,
  fact_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_memory_summary_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('033_memory_summary')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
