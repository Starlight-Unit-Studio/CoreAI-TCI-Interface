-- CoreAI 0.6.84: Settings > Profil shows usage like the ChatGPT profile page.
-- Tokens are counted from this version on, per account, day, Core and thinking level
-- ('off', 'on', 'low', 'medium', 'high', 'max'). Only numbers, never message text.
-- Deleted together with the account.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_usage_daily (
  user_id BIGINT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  model VARCHAR(120) NOT NULL,
  thinking VARCHAR(8) NOT NULL DEFAULT 'off',
  calls INT UNSIGNED NOT NULL DEFAULT 0,
  prompt_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, day, model, thinking),
  KEY idx_usage_user_day (user_id, day),
  CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The day counting started, shown on the page ("gezählt seit").
INSERT IGNORE INTO stu_app_settings (k, value, updated_at) VALUES ('usage_counting_since', CURDATE(), NOW());

INSERT INTO stu_schema_migrations (version) VALUES ('029_usage_counter')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
