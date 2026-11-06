SET NAMES utf8mb4;

-- 0.6.22: names and terms for voice messages, per account (KI-Profil).
-- One term per line; Core gets them as a spelling hint for the transcript
-- and Mara in the task of a voice message. Empty unless the account fills it.
ALTER TABLE stu_user_voice_settings
  ADD COLUMN IF NOT EXISTS vocabulary TEXT NULL AFTER auto_read;

INSERT INTO stu_schema_migrations (version) VALUES ('025_voice_vocabulary')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
