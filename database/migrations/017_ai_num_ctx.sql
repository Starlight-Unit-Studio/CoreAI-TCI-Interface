SET NAMES utf8mb4;

-- 0.5.70 (#113): optionales Kontextfenster pro Konto. NULL bedeutet, dass
-- kein Override gesendet wird und der lokale Core seinen Modelfile-Standard
-- behaelt. Erlaubte Werte prueft api/ai_settings.php (4K bis 128K).
ALTER TABLE stu_user_ai_settings
  ADD COLUMN IF NOT EXISTS num_ctx INT UNSIGNED NULL DEFAULT NULL AFTER num_predict;

INSERT INTO stu_schema_migrations (version) VALUES ('017_ai_num_ctx')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
