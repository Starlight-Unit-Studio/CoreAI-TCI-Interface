SET NAMES utf8mb4;

-- 0.6.39 (P20.5): Maras Bilder lassen sich pro Konto sperren (Admin Core > Benutzer),
-- wie Websuche, Browser und Python. Der Schalter unter Betrieb bleibt die Obergrenze.
ALTER TABLE stu_user_policies
  ADD COLUMN IF NOT EXISTS tool_image TINYINT(1) NOT NULL DEFAULT 1 AFTER tool_python;

INSERT INTO stu_schema_migrations (version) VALUES ('026_image_tool')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
