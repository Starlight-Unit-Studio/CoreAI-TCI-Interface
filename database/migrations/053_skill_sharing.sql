SET NAMES utf8mb4;

-- P12 B: shared skills, a precedence per skill and the skills fixed for one chat.
-- A skill of the Inhaber or an Admin can be shared with every account (never a project skill);
-- every account may switch a shared skill off for itself. The precedence (1 high, 0 normal,
-- -1 low) decides which skills come first when more fit than the context window takes, and which
-- variant counts when two skills share a short name. A chat can fix up to three skills (a
-- package by its id, a built-in one as 'b:' and its name); Mara always has them in that chat and
-- uses exactly that variant.
ALTER TABLE stu_skill_packages
  ADD COLUMN IF NOT EXISTS shared TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled,
  ADD COLUMN IF NOT EXISTS priority TINYINT NOT NULL DEFAULT 0 AFTER shared,
  ADD INDEX IF NOT EXISTS idx_skill_packages_shared (shared, enabled);

CREATE TABLE IF NOT EXISTS stu_skill_package_optouts (
  user_id BIGINT UNSIGNED NOT NULL,
  skill_id CHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, skill_id),
  KEY idx_skill_optouts_skill (skill_id),
  CONSTRAINT fk_skill_optouts_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_skill_optouts_skill FOREIGN KEY (skill_id) REFERENCES stu_skill_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_session_skills (
  session_id VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  ref VARCHAR(70) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id, ref),
  KEY idx_session_skills_user (user_id),
  CONSTRAINT fk_session_skills_session FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_session_skills_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('053_skill_sharing')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
