-- Preserve both profile avatars and extend the same per-account media table with
-- exactly four extra, independently removable Mara visual-reference slots.
ALTER TABLE stu_coreui_profile_media
  MODIFY COLUMN slot ENUM('user','assistant','assistant_ref_1','assistant_ref_2',
                          'assistant_ref_3','assistant_ref_4') NOT NULL;
INSERT INTO stu_schema_migrations (version) VALUES ('042_mara_profile_references')
  ON DUPLICATE KEY UPDATE version = VALUES(version);
