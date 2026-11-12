-- 0.7.22: "Chat sperren" hides the chat from ordinary lists and title search.
-- PIN-gated application visibility, not cryptographic encryption.
-- A per-account PIN hash is stored separately from chat data.
SET NAMES utf8mb4;

ALTER TABLE stu_console_sessions
  ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL AFTER pinned_at,
  ADD INDEX IF NOT EXISTS idx_console_locked (user_id, locked_at, updated_at);

CREATE TABLE IF NOT EXISTS stu_console_chat_lock_pins (
  user_id BIGINT UNSIGNED NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_chat_lock_pin_user FOREIGN KEY (user_id)
    REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version) VALUES ('038_chat_visibility_lock')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
