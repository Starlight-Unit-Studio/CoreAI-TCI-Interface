<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.81: Web Push without a library (PHP openssl only).
 *
 *   - RFC 8291 message encryption with RFC 8188 "aes128gcm" content coding:
 *     ECDH (P-256) with the browser's key, HKDF-SHA-256 with its auth secret,
 *     AES-128-GCM, one record.
 *   - RFC 8292 VAPID: a JWT signed with ES256 by this server's own key pair, which is
 *     created once and kept in the database (stu_app_settings, never in Git or a file).
 *   - Sending: one HTTPS POST to the browser vendor's push service. Only the push services of
 *     the major browsers are accepted as targets, so a stored "endpoint" can never make this
 *     server call an arbitrary address.
 *
 * The pure parts (base64url, key formats, encryption, JWT) are tested offline against the
 * example in RFC 8291 Appendix A (scripts/notifications-selftest.php).
 */

const COREUI_PUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
const COREUI_PUSH_RECORD_SIZE = 4096;

function coreui_b64url_encode(string $raw): string {
  return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function coreui_b64url_decode(string $text): ?string {
  if (preg_match('~^[A-Za-z0-9_-]*={0,2}$~', $text) !== 1) return null;
  $text = rtrim($text, '=');
  $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
  return $raw === false ? null : $raw;
}

/** An uncompressed P-256 point (65 bytes) as an openssl public key, or null. */
function coreui_push_public_key(string $point) {
  if (strlen($point) !== 65 || $point[0] !== "\x04") return null;
  $der = hex2bin(COREUI_PUSH_P256_SPKI_PREFIX) . $point;
  $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
  $key = openssl_pkey_get_public($pem);
  return $key === false ? null : $key;
}

/** A P-256 private key from its raw scalar d (32 bytes) and public point (65 bytes), or null. */
function coreui_push_private_key(string $d, string $point) {
  if (strlen($d) !== 32 || strlen($point) !== 65) return null;
  $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
  $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
  $key = openssl_pkey_get_private($pem);
  return $key === false ? null : $key;
}

/** The uncompressed public point of a P-256 key (65 bytes). */
function coreui_push_point($key): string {
  $d = openssl_pkey_get_details($key);
  if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) throw new RuntimeException('push_key_invalid');
  return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function coreui_push_new_key() {
  $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if ($key === false) throw new RuntimeException('push_keygen_failed');
  return $key;
}

/**
 * RFC 8291 encryption (pure apart from the random salt and key, which a test may pass in).
 * Returns the request body: salt(16) | rs(4) | idlen(1) | as_public(65) | ciphertext+tag.
 */
function coreui_push_encrypt(string $payload, string $uaPublic, string $authSecret, $asKey = null, ?string $salt = null): string {
  if (strlen($authSecret) !== 16) throw new InvalidArgumentException('push_auth_invalid');
  $uaKey = coreui_push_public_key($uaPublic);
  if ($uaKey === null) throw new InvalidArgumentException('push_key_invalid');
  if (strlen($payload) > 3800) throw new InvalidArgumentException('push_payload_too_long');
  $asKey = $asKey ?? coreui_push_new_key();
  $asPublic = coreui_push_point($asKey);
  $salt = $salt ?? random_bytes(16);
  $shared = openssl_pkey_derive($uaKey, $asKey, 32);
  if (!is_string($shared) || strlen($shared) !== 32) throw new RuntimeException('push_ecdh_failed');
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $tag = '';
  $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
  if (!is_string($cipher)) throw new RuntimeException('push_encrypt_failed');
  return $salt . pack('N', COREUI_PUSH_RECORD_SIZE) . chr(65) . $asPublic . $cipher . $tag;
}

/** RFC 8291 decryption on the browser's side (pure); used by the selftest. Null on any error. */
function coreui_push_decrypt(string $body, $uaKey, string $authSecret): ?string {
  if (strlen($body) < 86 + 17) return null;
  $salt = substr($body, 0, 16);
  if (ord($body[20]) !== 65) return null;
  $asPublic = substr($body, 21, 65);
  $asKey = coreui_push_public_key($asPublic);
  if ($asKey === null) return null;
  $shared = openssl_pkey_derive($asKey, $uaKey, 32);
  if (!is_string($shared)) return null;
  $uaPublic = coreui_push_point($uaKey);
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $data = substr($body, 86);
  $plain = openssl_decrypt(substr($data, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($data, -16));
  if (!is_string($plain)) return null;
  $plain = rtrim($plain, "\0");
  return substr($plain, -1) === "\x02" ? substr($plain, 0, -1) : null;
}

/** A DER ECDSA signature as the 64 raw bytes r|s that JWS ES256 needs (pure). */
function coreui_push_der_to_raw(string $der): ?string {
  if (strlen($der) < 8 || $der[0] !== "\x30") return null;
  $pos = 2;
  if (ord($der[1]) & 0x80) $pos = 2 + (ord($der[1]) & 0x7f);
  $out = '';
  for ($i = 0; $i < 2; $i++) {
    if (!isset($der[$pos]) || $der[$pos] !== "\x02") return null;
    $len = ord($der[$pos + 1]);
    $int = ltrim(substr($der, $pos + 2, $len), "\0");
    if (strlen($int) > 32) return null;
    $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    $pos += 2 + $len;
  }
  return $out;
}

/** The raw r|s back to DER (pure), to verify a JWT with openssl_verify. */
function coreui_push_raw_to_der(string $raw): ?string {
  if (strlen($raw) !== 64) return null;
  $part = static function (string $int): string {
    $int = ltrim($int, "\0");
    if ($int === '' || (ord($int[0]) & 0x80)) $int = "\0" . $int;
    return "\x02" . chr(strlen($int)) . $int;
  };
  $body = $part(substr($raw, 0, 32)) . $part(substr($raw, 32));
  return "\x30" . chr(strlen($body)) . $body;
}

/** The VAPID JWT (RFC 8292) for one push service origin. */
function coreui_push_vapid_jwt(string $audience, string $subject, $privateKey, int $now): string {
  $head = coreui_b64url_encode('{"typ":"JWT","alg":"ES256"}');
  $claims = coreui_b64url_encode((string)json_encode(['aud' => $audience, 'exp' => $now + 12 * 3600, 'sub' => $subject],
    JSON_UNESCAPED_SLASHES));
  $data = $head . '.' . $claims;
  $der = '';
  if (!openssl_sign($data, $der, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('push_sign_failed');
  $raw = coreui_push_der_to_raw($der);
  if ($raw === null) throw new RuntimeException('push_sign_failed');
  return $data . '.' . coreui_b64url_encode($raw);
}

/**
 * Only the push services of the browsers (pure). A stored endpoint never makes this server
 * call any other address: https, port 443, a known vendor host, no credentials in the URL.
 */
function coreui_push_endpoint_allowed(string $endpoint): bool {
  if (strlen($endpoint) > 1000) return false;
  $parts = parse_url($endpoint);
  if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) return false;
  if (isset($parts['port']) && (int)$parts['port'] !== 443) return false;
  $host = strtolower((string)($parts['host'] ?? ''));
  foreach (['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'] as $exact) {
    if ($host === $exact) return true;
  }
  foreach (['.notify.windows.com', '.push.apple.com'] as $suffix) {
    if (str_ends_with($host, $suffix) && preg_match('~^[a-z0-9.-]+$~', $host) === 1) return true;
  }
  return false;
}

/** The origin of an endpoint, the "aud" of its VAPID token (pure). */
function coreui_push_audience(string $endpoint): string {
  $p = parse_url($endpoint);
  return 'https://' . strtolower((string)($p['host'] ?? ''));
}

/** A new VAPID key pair as stored text (pure apart from the random key). */
function coreui_push_vapid_create(): array {
  $key = coreui_push_new_key();
  $pem = '';
  if (!openssl_pkey_export($key, $pem)) throw new RuntimeException('push_keygen_failed');
  return ['private_pem' => $pem, 'public' => coreui_b64url_encode(coreui_push_point($key))];
}

/** A stored VAPID key pair back to ['key' => openssl key, 'public' => base64url], or null. */
function coreui_push_vapid_from(?string $json): ?array {
  $data = is_string($json) ? json_decode($json, true) : null;
  if (!is_array($data) || !is_string($data['private_pem'] ?? null) || !is_string($data['public'] ?? null)) return null;
  $key = openssl_pkey_get_private($data['private_pem']);
  if ($key === false || coreui_b64url_encode(coreui_push_point($key)) !== $data['public']) return null;
  return ['key' => $key, 'public' => $data['public']];
}

/** Whether this PHP can send Web Push at all. */
function coreui_push_supported(): bool {
  return function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && function_exists('curl_init')
    && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/**
 * Sends one notification. $subscription: endpoint, p256dh, auth (base64url). Returns
 * ['ok' => bool, 'status' => int, 'gone' => bool] where gone means the browser dropped the
 * subscription (404/410) and it should be deleted. $http(url, headers, body): [status, error]
 * replaces curl in tests.
 */
function coreui_push_send(array $subscription, array $message, string $subject, array $vapid, ?callable $http = null): array {
  $endpoint = (string)($subscription['endpoint'] ?? '');
  if (!coreui_push_endpoint_allowed($endpoint)) return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'endpoint_not_allowed'];
  $ua = coreui_b64url_decode((string)($subscription['p256dh'] ?? ''));
  $auth = coreui_b64url_decode((string)($subscription['auth'] ?? ''));
  if ($ua === null || $auth === null) return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'keys_invalid'];
  try {
    $body = coreui_push_encrypt((string)json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ua, $auth);
    $jwt = coreui_push_vapid_jwt(coreui_push_audience($endpoint), $subject, $vapid['key'], time());
  } catch (Throwable $e) {
    return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => $e->getMessage()];
  }
  $headers = [
    'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
    'Content-Encoding: aes128gcm',
    'Content-Type: application/octet-stream',
    'TTL: 86400',
    'Urgency: ' . (($message['urgency'] ?? '') === 'high' ? 'high' : 'normal'),
  ];
  [$status, $error] = $http !== null ? $http($endpoint, $headers, $body) : coreui_push_http($endpoint, $headers, $body);
  return ['ok' => $status >= 200 && $status < 300, 'status' => (int)$status, 'gone' => in_array((int)$status, [404, 410], true),
    'error' => (string)$error];
}

function coreui_push_http(string $url, array $headers, string $body): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
  ]);
  curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_errno($ch) ? curl_error($ch) : '';
  curl_close($ch);
  return [$status, $error];
}
