SET NAMES utf8mb4;

-- 0.8.34: the saved states of a project file survive a change of working state.
-- Switching working state deletes the file rows that the other state does not have. The foreign key
-- from the revisions to the file row cascaded that delete onto every saved state of the file, so after
-- switching away and back a file started again at revision 1, although the Files tab says earlier
-- states stay restorable. The revisions now hang on the project instead: deleting a project (or the
-- account) still removes them, an explicit file delete removes them in the code as before, and a file
-- that comes back at its path continues its history (api/project_files_store.php,
-- coreui_pfiles_history_trail). The index serves that lookup by path.
-- Every step checks information_schema first, so a repeated run changes nothing.

SET @coreui_fk_file := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stu_console_project_file_revisions'
    AND CONSTRAINT_NAME = 'fk_project_file_revisions_file' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @coreui_sql := IF(@coreui_fk_file > 0,
  'ALTER TABLE stu_console_project_file_revisions DROP FOREIGN KEY fk_project_file_revisions_file',
  'DO 0');
PREPARE coreui_stmt FROM @coreui_sql;
EXECUTE coreui_stmt;
DEALLOCATE PREPARE coreui_stmt;

-- Revisions whose project is already gone cannot get the new key; they are unreachable anyway.
DELETE r FROM stu_console_project_file_revisions r
  LEFT JOIN stu_console_projects p ON p.id = r.project_id
  WHERE p.id IS NULL;

SET @coreui_ix_path := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stu_console_project_file_revisions'
    AND INDEX_NAME = 'idx_project_file_revisions_path');
SET @coreui_sql := IF(@coreui_ix_path = 0,
  'ALTER TABLE stu_console_project_file_revisions ADD KEY idx_project_file_revisions_path (project_id, user_id, path)',
  'DO 0');
PREPARE coreui_stmt FROM @coreui_sql;
EXECUTE coreui_stmt;
DEALLOCATE PREPARE coreui_stmt;

SET @coreui_fk_project := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stu_console_project_file_revisions'
    AND CONSTRAINT_NAME = 'fk_project_file_revisions_project' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @coreui_sql := IF(@coreui_fk_project = 0,
  'ALTER TABLE stu_console_project_file_revisions ADD CONSTRAINT fk_project_file_revisions_project FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE',
  'DO 0');
PREPARE coreui_stmt FROM @coreui_sql;
EXECUTE coreui_stmt;
DEALLOCATE PREPARE coreui_stmt;

INSERT INTO stu_schema_migrations (version) VALUES ('058_project_file_history')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
