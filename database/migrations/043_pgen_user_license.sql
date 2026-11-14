-- License consent belongs to the individual account, never the server or all users.
-- Retain the existing operator receipt only for provisioning the shared model.
CREATE TABLE IF NOT EXISTS stu_coreui_pgen_user_licenses (
  user_id BIGINT UNSIGNED NOT NULL,
  license_sha256 CHAR(64) NOT NULL,
  accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_coreui_pgen_license_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('043_pgen_user_license')
  ON DUPLICATE KEY UPDATE applied_at=applied_at;
