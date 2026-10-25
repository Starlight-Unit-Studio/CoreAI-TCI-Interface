SET NAMES utf8mb4;

-- Die alte MEDIUMTEXT-Spalte kann bereits mehr als kurze Chatnachrichten
-- speichern, trotzdem soll der private CoreUI-Kanal keine kleine
-- Datenbankschranke fuer vollstaendige LLM-Ausgaben behalten.
-- Global-/Allianz-Nachrichten werden weiterhin im API-Code begrenzt.
ALTER TABLE stu_chat_messages MODIFY COLUMN message LONGTEXT NOT NULL;

INSERT INTO stu_schema_migrations (version) VALUES ('015_long_chat_messages')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
