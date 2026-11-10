-- CoreAI 0.6.86: Settings > Konto, modeled on the ChatGPT account page.
-- Benutzername: an @handle per account, unique on this server (lowercase only, so the
-- binary collation is enough). Plan: the template (free, go, plus, pro) an Inhaber or
-- Admin chose for an account; the plan writes the real rules into the same row.
-- Everything is deleted with the account.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_usernames (
  user_id BIGINT UNSIGNED NOT NULL,
  handle VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_usernames_handle (handle),
  CONSTRAINT fk_usernames_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stu_user_policies
  ADD COLUMN IF NOT EXISTS plan VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER max_num_ctx;

INSERT INTO stu_schema_migrations (version) VALUES ('031_account_page')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
