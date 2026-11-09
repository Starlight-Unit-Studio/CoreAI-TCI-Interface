-- CoreAI 0.6.85: Settings > Sicherheit und Anmeldung.
-- Per account: Lockdown-Modus, advanced account security, the authenticator app (secret
-- sealed, never plain), the E-Mail code as a second factor, recovery codes (hashes only),
-- passkeys (WebAuthn public keys only) and the security history. All deleted with the account.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_user_security (
  user_id BIGINT UNSIGNED NOT NULL,
  lockdown TINYINT(1) NOT NULL DEFAULT 0,
  advanced_at DATETIME NULL,
  totp_secret TEXT NULL,
  totp_enabled_at DATETIME NULL,
  totp_last_step BIGINT NOT NULL DEFAULT 0,
  email_mfa_at DATETIME NULL,
  recovery_codes TEXT NULL,
  webauthn_handle CHAR(43) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_security_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_passkeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  credential_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  rp_id VARCHAR(253) NOT NULL,
  public_key TEXT NOT NULL,
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  label VARCHAR(96) NOT NULL DEFAULT 'Passkey',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_passkey_credential (credential_id),
  KEY idx_passkey_user (user_id),
  CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(32) NOT NULL,
  device_label VARCHAR(96) NOT NULL DEFAULT '',
  detail VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_security_events_user (user_id, id),
  CONSTRAINT fk_security_events_user FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- From here on the history comes from stu_security_events; older sign-ins from stu_auth_sessions.
INSERT IGNORE INTO stu_app_settings (k, value, updated_at) VALUES ('security_events_since', NOW(), NOW());

INSERT INTO stu_schema_migrations (version) VALUES ('030_sign_in_security')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
