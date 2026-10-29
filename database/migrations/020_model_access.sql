SET NAMES utf8mb4;

-- 0.5.83: Freigaben fuer Modelle ausserhalb von CoreAI. Maras Cores (die
-- Gemma-4-Stufen und Cores aus ihrem Modelfile) stehen allen Konten offen;
-- andere Modelle in Ollama (eigene Namen, etwa private Cloud-Modelle) nutzt
-- nur der Inhaber und wem er sie unter Admin Core > Benutzer freigibt.
CREATE TABLE IF NOT EXISTS stu_user_model_access (
  user_id BIGINT UNSIGNED NOT NULL,
  model_name VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, model_name),
  CONSTRAINT fk_model_access_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('020_model_access')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
