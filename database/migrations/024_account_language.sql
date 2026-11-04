SET NAMES utf8mb4;

-- 0.6.10 (P20.3b): main language per account, chosen in the KI-Profil.
-- 'de' or 'en': Mara's default answer language (she still answers in the
-- language a person writes in) and the read-aloud voice when the language
-- of an answer is unclear. The interface itself is translated later.
ALTER TABLE stu_user_ai_settings
  ADD COLUMN IF NOT EXISTS main_language CHAR(2) NOT NULL DEFAULT 'de' AFTER num_ctx;

INSERT INTO stu_schema_migrations (version) VALUES ('024_account_language')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
