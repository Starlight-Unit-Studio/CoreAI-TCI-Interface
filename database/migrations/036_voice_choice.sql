SET NAMES utf8mb4;

-- 0.7.14: Settings > Stimme after the reference screenshots, per account.
-- voice_de / voice_en: the voice Mara reads aloud with in German / English
--   (a name from config/voice/mara-voice.json "choices"; NULL = Eve).
-- input_language: the language of the account's Stimmsequenzen and dictations
--   ('auto' or a language code; NULL = the account's main language).
-- dictation: the dictation button in the chat input field (NULL = on).
ALTER TABLE stu_user_voice_settings
  ADD COLUMN IF NOT EXISTS voice_de VARCHAR(32) NULL AFTER vocabulary,
  ADD COLUMN IF NOT EXISTS voice_en VARCHAR(32) NULL AFTER voice_de,
  ADD COLUMN IF NOT EXISTS input_language VARCHAR(8) NULL AFTER voice_en,
  ADD COLUMN IF NOT EXISTS dictation TINYINT(1) NULL AFTER input_language;

INSERT INTO stu_schema_migrations (version) VALUES ('036_voice_choice')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
