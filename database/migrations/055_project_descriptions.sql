SET NAMES utf8mb4;

-- A short project overview is distinct from the assistant's private project instructions.
-- Existing projects keep an empty overview and can be edited without losing instructions.
ALTER TABLE stu_console_projects
  ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER name;

INSERT INTO stu_schema_migrations (version) VALUES ('055_project_descriptions')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
