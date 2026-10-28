SET NAMES utf8mb4;

-- 0.5.78: Missbrauchsschutz. Serverseitige Zaehler, die geloeschte Cookies
-- ueberleben: Ratenlimits je Netzwerk, Login-Fehlversuche je Konto, das
-- Nachrichten-Kontingent der Gaeste je Netzwerk und der Tagesdeckel aller
-- Gaeste. k ist ein HMAC (Server-Geheimnis) aus Zweck und Netzwerk bzw.
-- Konto; es werden keine IP-Adressen oder E-Mail-Adressen im Klartext
-- gespeichert. Abgelaufene Zeilen raeumt api/abuse_guard.php selbst auf.
CREATE TABLE IF NOT EXISTS stu_abuse_counters (
  k CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (k),
  KEY idx_abuse_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('018_abuse_guard')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
