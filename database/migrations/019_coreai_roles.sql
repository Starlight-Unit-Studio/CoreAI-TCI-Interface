SET NAMES utf8mb4;

-- 0.5.79: CoreAI-Rollen statt der Game-Raenge. Es bleiben Inhaber (0), Admin (1)
-- und Nutzer (4); Gaeste sind ein eigenes Merkmal (is_guest), keine Stufe. Game-
-- und Chat-Moderator (2, 3) hatten in CoreAI keine eigene Funktion und werden Nutzer.
UPDATE stu_users SET permission_level = 4 WHERE permission_level IN (2, 3);

INSERT INTO stu_schema_migrations (version) VALUES ('019_coreai_roles')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
