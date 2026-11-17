SET NAMES utf8mb4;

-- P12 A: skills of an account. A skill has a main instruction (what Mara follows), a short
-- description and trigger words (how she finds it), the tools it needs (shown as missing when an
-- account or a chat does not have them) and where it applies: every chat of the account, or only
-- the chats of one project. Its companion files are addressed by path: references/, templates/,
-- scripts/ and assets/. Loading a file gives Mara its text; nothing in a skill ever runs by itself.
CREATE TABLE IF NOT EXISTS stu_skill_packages (
  id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(80) NOT NULL,
  description VARCHAR(300) NOT NULL,
  body TEXT NOT NULL,
  triggers VARCHAR(600) NOT NULL DEFAULT '',
  requires_tools VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  project_id CHAR(32) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skill_packages_slug (user_id, slug),
  KEY idx_skill_packages_project (project_id),
  CONSTRAINT fk_skill_packages_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_skill_packages_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_skill_package_files (
  skill_id CHAR(32) NOT NULL,
  path VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind ENUM('reference','template','script','asset') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (skill_id, path),
  CONSTRAINT fk_skill_package_files FOREIGN KEY (skill_id) REFERENCES stu_skill_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('052_skill_packages')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
