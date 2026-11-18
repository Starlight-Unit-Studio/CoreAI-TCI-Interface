SET NAMES utf8mb4;

-- P13 A: fixed revisions of a skill. Every change (saving, a companion file, restoring, importing)
-- records the whole skill as a new revision: its details and instruction, and every companion file.
-- A revision never changes afterwards; restoring one adds a new revision on top. Contents are kept
-- once per skill (by their SHA-256). The counter on the skill lets a change notice when someone else
-- saved in between. Everything goes with its skill.
ALTER TABLE stu_skill_packages
  ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER priority;

CREATE TABLE IF NOT EXISTS stu_skill_blobs (
  skill_id CHAR(32) NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content MEDIUMTEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (skill_id, sha256),
  CONSTRAINT fk_skill_blobs_skill FOREIGN KEY (skill_id) REFERENCES stu_skill_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_skill_revisions (
  skill_id CHAR(32) NOT NULL,
  rev INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  kind ENUM('create','save','file','restore','import') NOT NULL,
  note VARCHAR(160) NOT NULL DEFAULT '',
  meta MEDIUMTEXT NOT NULL,
  body_sha CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (skill_id, rev),
  KEY idx_skill_revisions_user (user_id),
  CONSTRAINT fk_skill_revisions_skill FOREIGN KEY (skill_id) REFERENCES stu_skill_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_skill_revisions_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_skill_revision_files (
  skill_id CHAR(32) NOT NULL,
  rev INT UNSIGNED NOT NULL,
  path VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind ENUM('reference','template','script','asset') NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  PRIMARY KEY (skill_id, rev, path),
  CONSTRAINT fk_skill_revision_files FOREIGN KEY (skill_id, rev) REFERENCES stu_skill_revisions(skill_id, rev) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('054_skill_revisions')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
