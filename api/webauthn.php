<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.85: passkeys and security keys (WebAuthn Level 2), without a library.
 *   - CBOR decoding (only what WebAuthn needs, with depth and size limits)
 *   - authenticator data and COSE keys: ES256 (P-256), RS256, EdDSA (Ed25519 via sodium)
 *   - registration: client data (type, challenge, origin), RP ID hash, user presence,
 *     attested credential; attestation statements are not trusted or needed (attestation
 *     "none"), so any FIDO2 key or platform passkey works
 *   - sign-in: client data, RP ID hash, user presence, the signature over
 *     authenticatorData || SHA-256(clientDataJSON), the signature counter
 * Pure functions; the store and the endpoints keep challenges and keys.
 */

const COREUI_WEBAUTHN_ALGS = [-7, -257, -8];

function coreui_b64u(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function coreui_b64u_decode(string $text): ?string {
  if (preg_match('~^[A-Za-z0-9_-]*$~', $text) !== 1) return null;
  $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
  return is_string($raw) ? $raw : null;
}

/** One CBOR item from $data at $pos (advanced). Byte and text strings both become PHP strings. */
function coreui_cbor_item(string $data, int &$pos, int $depth = 0): mixed {
  if ($depth > 16) throw new InvalidArgumentException('cbor_too_deep');
  if ($pos >= strlen($data)) throw new InvalidArgumentException('cbor_truncated');
  $first = ord($data[$pos++]);
  $major = $first >> 5;
  $info = $first & 0x1f;
  $length = static function () use ($data, &$pos, $info): int {
    if ($info < 24) return $info;
    $size = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info] ?? 0;
    if ($size === 0 || $pos + $size > strlen($data)) throw new InvalidArgumentException('cbor_length');
    $value = 0;
    for ($i = 0; $i < $size; $i++) $value = ($value << 8) | ord($data[$pos++]);
    if ($value < 0) throw new InvalidArgumentException('cbor_length');
    return $value;
  };
  switch ($major) {
    case 0: return $length();
    case 1: return -1 - $length();
    case 2:
    case 3:
      $n = $length();
      if ($n > 65536 || $pos + $n > strlen($data)) throw new InvalidArgumentException('cbor_string');
      $s = substr($data, $pos, $n);
      $pos += $n;
      return $s;
    case 4:
      $n = $length();
      if ($n > 256) throw new InvalidArgumentException('cbor_array');
      $out = [];
      for ($i = 0; $i < $n; $i++) $out[] = coreui_cbor_item($data, $pos, $depth + 1);
      return $out;
    case 5:
      $n = $length();
      if ($n > 256) throw new InvalidArgumentException('cbor_map');
      $out = [];
      for ($i = 0; $i < $n; $i++) {
        $key = coreui_cbor_item($data, $pos, $depth + 1);
        if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('cbor_key');
        $out[$key] = coreui_cbor_item($data, $pos, $depth + 1);
      }
      return $out;
    case 6:
      $length();
      return coreui_cbor_item($data, $pos, $depth + 1);
    default:
      if ($info === 20) return false;
      if ($info === 21) return true;
      if ($info === 22 || $info === 23) return null;
      throw new InvalidArgumentException('cbor_simple');
  }
}

function coreui_cbor_decode(string $data): mixed {
  $pos = 0;
  return coreui_cbor_item($data, $pos);
}

/** DER helpers for public keys. */
function coreui_der_len(int $n): string {
  if ($n < 0x80) return chr($n);
  $bytes = ltrim(pack('N', $n), "\0");
  return chr(0x80 | strlen($bytes)) . $bytes;
}
function coreui_der(int $tag, string $body): string {
  return chr($tag) . coreui_der_len(strlen($body)) . $body;
}
function coreui_der_int(string $unsigned): string {
  $unsigned = ltrim($unsigned, "\0");
  if ($unsigned === '' || (ord($unsigned[0]) & 0x80)) $unsigned = "\0" . $unsigned;
  return coreui_der(0x02, $unsigned);
}
function coreui_pem(string $der): string {
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** A COSE public key as what we store: ['alg' => -7|-257|-8, 'pem' => ... | 'raw' => base64]. */
function coreui_cose_key(array $cose): array {
  $kty = $cose[1] ?? null;
  $alg = $cose[3] ?? null;
  if ($kty === 2 && $alg === -7) {
    $x = $cose[-2] ?? '';
    $y = $cose[-3] ?? '';
    if (($cose[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
      throw new InvalidArgumentException('webauthn_key_ec');
    }
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    $pem = coreui_pem($spki);
    if (openssl_pkey_get_public($pem) === false) throw new InvalidArgumentException('webauthn_key_ec');
    return ['alg' => -7, 'pem' => $pem];
  }
  if ($kty === 3 && $alg === -257) {
    $n = $cose[-1] ?? '';
    $e = $cose[-2] ?? '';
    if (!is_string($n) || !is_string($e) || strlen($n) < 256 || $e === '') throw new InvalidArgumentException('webauthn_key_rsa');
    $rsa = coreui_der(0x30, coreui_der_int($n) . coreui_der_int($e));
    $spki = coreui_der(0x30, coreui_der(0x30, hex2bin('06092a864886f70d0101010500')) . coreui_der(0x03, "\0" . $rsa));
    $pem = coreui_pem($spki);
    if (openssl_pkey_get_public($pem) === false) throw new InvalidArgumentException('webauthn_key_rsa');
    return ['alg' => -257, 'pem' => $pem];
  }
  if ($kty === 1 && $alg === -8 && ($cose[-1] ?? null) === 6) {
    $x = $cose[-2] ?? '';
    if (!is_string($x) || strlen($x) !== 32 || !function_exists('sodium_crypto_sign_verify_detached')) {
      throw new InvalidArgumentException('webauthn_key_okp');
    }
    return ['alg' => -8, 'raw' => base64_encode($x)];
  }
  throw new InvalidArgumentException('webauthn_alg_unsupported');
}

/** Authenticator data: RP ID hash, flags, counter and (on registration) the new credential. */
function coreui_webauthn_auth_data(string $data): array {
  if (strlen($data) < 37) throw new InvalidArgumentException('webauthn_auth_data');
  $flags = ord($data[32]);
  $out = ['rp_id_hash' => substr($data, 0, 32), 'flags' => $flags, 'up' => (bool)($flags & 0x01),
    'uv' => (bool)($flags & 0x04), 'sign_count' => unpack('N', substr($data, 33, 4))[1], 'credential_id' => null, 'cose' => null];
  if ($flags & 0x40) {
    if (strlen($data) < 55) throw new InvalidArgumentException('webauthn_auth_data');
    $idLen = unpack('n', substr($data, 53, 2))[1];
    if ($idLen < 16 || $idLen > 1023 || strlen($data) < 55 + $idLen + 1) throw new InvalidArgumentException('webauthn_credential_id');
    $out['credential_id'] = substr($data, 55, $idLen);
    $pos = 55 + $idLen;
    $cose = coreui_cbor_item($data, $pos);
    if (!is_array($cose)) throw new InvalidArgumentException('webauthn_key');
    $out['cose'] = $cose;
  }
  return $out;
}

/** The origin a browser reports must belong to our RP ID: https, or http only on this machine. */
function coreui_webauthn_origin_ok(string $origin, string $rpId): bool {
  $parts = parse_url($origin);
  if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['path']) || isset($parts['user'])) return false;
  if (strtolower((string)$parts['host']) !== strtolower($rpId)) return false;
  if ($parts['scheme'] === 'https') return true;
  return $parts['scheme'] === 'http' && in_array(strtolower($rpId), ['localhost', '127.0.0.1'], true);
}

function coreui_webauthn_client_data(string $json, string $type, string $challenge, string $rpId): array {
  $client = json_decode($json, true);
  if (!is_array($client)) throw new InvalidArgumentException('webauthn_client_data');
  if (($client['type'] ?? '') !== $type) throw new InvalidArgumentException('webauthn_type');
  if (!is_string($client['challenge'] ?? null) || !hash_equals(coreui_b64u($challenge), $client['challenge'])) {
    throw new InvalidArgumentException('webauthn_challenge');
  }
  if (!coreui_webauthn_origin_ok((string)($client['origin'] ?? ''), $rpId)) throw new InvalidArgumentException('webauthn_origin');
  if (!empty($client['crossOrigin'])) throw new InvalidArgumentException('webauthn_cross_origin');
  return $client;
}

/**
 * Checks a new passkey. $in: clientDataJSON and attestationObject (base64url).
 * Returns ['credential_id' => base64url, 'public_key' => JSON, 'sign_count' => int].
 */
function coreui_webauthn_register_verify(array $in, string $challenge, string $rpId): array {
  $clientJson = coreui_b64u_decode((string)($in['clientDataJSON'] ?? ''));
  $attestation = coreui_b64u_decode((string)($in['attestationObject'] ?? ''));
  if ($clientJson === null || $attestation === null || strlen($attestation) > 16384) throw new InvalidArgumentException('webauthn_input');
  coreui_webauthn_client_data($clientJson, 'webauthn.create', $challenge, $rpId);
  $att = coreui_cbor_decode($attestation);
  if (!is_array($att) || !is_string($att['authData'] ?? null) || !is_string($att['fmt'] ?? null)) throw new InvalidArgumentException('webauthn_attestation');
  $auth = coreui_webauthn_auth_data($att['authData']);
  if (!hash_equals(hash('sha256', $rpId, true), $auth['rp_id_hash'])) throw new InvalidArgumentException('webauthn_rp_id');
  if (!$auth['up']) throw new InvalidArgumentException('webauthn_user_presence');
  if ($auth['credential_id'] === null || !is_array($auth['cose'])) throw new InvalidArgumentException('webauthn_no_credential');
  $key = coreui_cose_key($auth['cose']);
  return ['credential_id' => coreui_b64u($auth['credential_id']), 'public_key' => (string)json_encode($key),
    'sign_count' => (int)$auth['sign_count']];
}

/**
 * Checks a sign-in with a stored passkey ($stored: public_key JSON, sign_count). $in:
 * clientDataJSON, authenticatorData, signature (base64url). Returns the new counter.
 */
function coreui_webauthn_assert_verify(array $in, string $challenge, string $rpId, array $stored): int {
  $clientJson = coreui_b64u_decode((string)($in['clientDataJSON'] ?? ''));
  $authData = coreui_b64u_decode((string)($in['authenticatorData'] ?? ''));
  $signature = coreui_b64u_decode((string)($in['signature'] ?? ''));
  if ($clientJson === null || $authData === null || $signature === null || $signature === '') throw new InvalidArgumentException('webauthn_input');
  coreui_webauthn_client_data($clientJson, 'webauthn.get', $challenge, $rpId);
  $auth = coreui_webauthn_auth_data($authData);
  if (!hash_equals(hash('sha256', $rpId, true), $auth['rp_id_hash'])) throw new InvalidArgumentException('webauthn_rp_id');
  if (!$auth['up']) throw new InvalidArgumentException('webauthn_user_presence');
  $key = json_decode((string)($stored['public_key'] ?? ''), true);
  if (!is_array($key)) throw new InvalidArgumentException('webauthn_key');
  $signed = $authData . hash('sha256', $clientJson, true);
  $alg = (int)($key['alg'] ?? 0);
  if ($alg === -7 || $alg === -257) {
    $ok = openssl_verify($signed, $signature, (string)($key['pem'] ?? ''), OPENSSL_ALGO_SHA256) === 1;
  } elseif ($alg === -8 && function_exists('sodium_crypto_sign_verify_detached')) {
    $raw = base64_decode((string)($key['raw'] ?? ''), true);
    $ok = is_string($raw) && strlen($raw) === 32 && strlen($signature) === 64 && sodium_crypto_sign_verify_detached($signature, $signed, $raw);
  } else {
    $ok = false;
  }
  if (!$ok) throw new InvalidArgumentException('webauthn_signature');
  $old = (int)($stored['sign_count'] ?? 0);
  $new = (int)$auth['sign_count'];
  // A counter that does not grow means a copied authenticator; 0/0 is a passkey without counter.
  if (($old > 0 || $new > 0) && $new <= $old) throw new InvalidArgumentException('webauthn_counter');
  return $new;
}
