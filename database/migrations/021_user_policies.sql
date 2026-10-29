SET NAMES utf8mb4;

-- 0.5.85: Regeln pro Konto unter Admin Core > Benutzer. Werkzeuge (Websuche,
-- Browser, Python) lassen sich pro Konto sperren; der globale Schalter unter
-- Betrieb bleibt die Obergrenze. Limits: Nachrichten pro Tag und das groesste
-- erlaubte Kontextfenster (NULL = keine eigene Grenze).
CREATE TABLE IF NOT EXISTS stu_user_policies (
  user_id BIGINT UNSIGNED NOT NULL,
  tool_web TINYINT(1) NOT NULL DEFAULT 1,
  tool_browse TINYINT(1) NOT NULL DEFAULT 1,
  tool_python TINYINT(1) NOT NULL DEFAULT 1,
  daily_messages INT UNSIGNED NULL,
  max_num_ctx INT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_policy_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('021_user_policies')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
