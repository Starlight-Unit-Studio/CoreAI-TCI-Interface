<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.85: small secrets at rest (the authenticator app's key). AES-256-GCM with a key
 * derived (HKDF) from the database password of the server configuration, a random salt per
 * secret and the purpose as associated data, so a copy of the database alone does not reveal
 * them and a secret sealed for one purpose cannot be used for another.
 */

require_once __DIR__ . '/config.php';

function coreui_secret_key(string $purpose, string $salt): string {
  $base = defined('STU_DB_PASS') ? (string)STU_DB_PASS : '';
  return hash_hkdf('sha256', 'coreai-secret|' . $base, 32, 'coreai-' . $purpose . '-v1', $salt);
}

function coreui_secret_seal(string $plain, string $purpose): string {
  $salt = random_bytes(16);
  $nonce = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', coreui_secret_key($purpose, $salt), OPENSSL_RAW_DATA, $nonce, $tag, $purpose, 16);
  if (!is_string($cipher)) throw new RuntimeException('secret_seal_failed');
  return 's1:' . base64_encode($salt . $nonce . $tag . $cipher);
}

/** The secret back, or null for another purpose, another server key or damage. */
function coreui_secret_open(string $sealed, string $purpose): ?string {
  if (!str_starts_with($sealed, 's1:')) return null;
  $raw = base64_decode(substr($sealed, 3), true);
  if (!is_string($raw) || strlen($raw) < 45) return null;
  $plain = openssl_decrypt(substr($raw, 44), 'aes-256-gcm', coreui_secret_key($purpose, substr($raw, 0, 16)),
    OPENSSL_RAW_DATA, substr($raw, 16, 12), substr($raw, 28, 16), $purpose);
  return is_string($plain) ? $plain : null;
}
