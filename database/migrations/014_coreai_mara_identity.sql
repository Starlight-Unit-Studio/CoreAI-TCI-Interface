SET NAMES utf8mb4;

-- Rename only the historical DEFAULT CoreAI display name. Preserve any
-- custom assistant_name and all stable security/database identifiers.
UPDATE stu_coreui_profiles
   SET assistant_name='Mara', updated_at=NOW()
 WHERE BINARY assistant_name = BINARY 'Ember';

ALTER TABLE stu_coreui_profiles
  MODIFY COLUMN assistant_name VARCHAR(64) NOT NULL DEFAULT 'Mara';

-- The technical character id and name_norm remain 'ember' permanently.
-- This keeps old references, accounts, mutes and unique-name constraints safe.
UPDATE stu_characters
   SET name='Mara'
 WHERE id='ember' AND user_id=1 AND BINARY name = BINARY 'Ember';

-- Change display attribution only, never message body or private content.
UPDATE stu_chat_messages
   SET character_name='Mara'
 WHERE character_id='ember' AND BINARY character_name = BINARY 'Ember';

UPDATE stu_chat_presence
   SET character_name='Mara'
 WHERE character_id='ember' AND BINARY character_name = BINARY 'Ember';

-- Keep bot-character JSON consistent without changing the technical ID.
UPDATE stu_kv
   SET value=JSON_SET(value, '$[0].name', 'Mara'), updated_at=NOW()
 WHERE user_id=1 AND k='stu_characters' AND JSON_VALID(value)=1
   AND JSON_UNQUOTE(JSON_EXTRACT(value, '$[0].id'))='ember'
   AND JSON_UNQUOTE(JSON_EXTRACT(value, '$[0].name'))='Ember';

INSERT INTO stu_schema_migrations (version)
VALUES ('014_coreai_mara_identity')
ON DUPLICATE KEY UPDATE version = VALUES(version);
