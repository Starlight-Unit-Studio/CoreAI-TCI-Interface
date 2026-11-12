SET NAMES utf8mb4;

-- 0.7.28: Settings > Speicher after the reference screenshots.
-- storage_mb: the account's own storage limit in MiB, set in Admin Core > Benutzer > Limits.
--   NULL = the limit for all accounts (Admin Core > Betrieb, app setting storage_limit_mb),
--   0 = no limit for this account. The Inhaber has no limit.
ALTER TABLE stu_user_policies
  ADD COLUMN IF NOT EXISTS storage_mb INT UNSIGNED NULL AFTER max_num_ctx;

-- Favourites of Settings > Speicher > Dateien ("m:<media uuid>" or "a:<artifact id>").
CREATE TABLE IF NOT EXISTS stu_user_file_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  item_key VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, item_key),
  CONSTRAINT fk_user_file_favorites_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('039_storage')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
