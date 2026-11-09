<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.85: one-time codes for the authenticator app (RFC 4226 HOTP, RFC 6238 TOTP with
 * SHA-1, 6 digits, 30 seconds, as Google Authenticator, Aegis, 1Password and others expect).
 * Pure functions; the account's secret is kept sealed (api/secret_box.php).
 */

const COREUI_TOTP_PERIOD = 30;
const COREUI_TOTP_DIGITS = 6;

function coreui_base32_encode(string $data): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
  $out = '';
  foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
  return $data === '' ? '' : $out;
}

/** Base32 to bytes; spaces, dashes and lower case are allowed; null for anything else. */
function coreui_base32_decode(string $text): ?string {
  $text = strtoupper(preg_replace('~[\s-]+~', '', $text) ?? '');
  $text = rtrim($text, '=');
  if ($text === '' || preg_match('~^[A-Z2-7]+$~', $text) !== 1) return null;
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  foreach (str_split($text) as $c) $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
  $out = '';
  foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
  return $out;
}

/** RFC 4226 HOTP with HMAC-SHA1 (also SHA256/SHA512 for the RFC 6238 test vectors). */
function coreui_hotp(string $key, int $counter, int $digits = COREUI_TOTP_DIGITS, string $algo = 'sha1'): string {
  $hash = hash_hmac($algo, pack('J', $counter), $key, true);
  $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
  $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
  return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function coreui_totp_at(string $key, int $time, int $digits = COREUI_TOTP_DIGITS, string $algo = 'sha1'): string {
  return coreui_hotp($key, intdiv($time, COREUI_TOTP_PERIOD), $digits, $algo);
}

/**
 * Checks a code with one step of clock drift either way. Returns the matched time step, or
 * null; a step at or below $lastStep was already used (no replay).
 */
function coreui_totp_verify(string $key, string $code, int $now, int $lastStep = 0): ?int {
  $code = preg_replace('~\s+~', '', $code) ?? '';
  if (preg_match('~^\d{6}$~', $code) !== 1 || $key === '') return null;
  $step = intdiv($now, COREUI_TOTP_PERIOD);
  foreach ([0, -1, 1] as $drift) {
    $s = $step + $drift;
    if ($s > $lastStep && hash_equals(coreui_hotp($key, $s), $code)) return $s;
  }
  return null;
}

/** The otpauth:// link the authenticator app reads from the QR code. */
function coreui_totp_uri(string $secretBase32, string $account, string $issuer = 'CoreAI'): string {
  return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
    . '?secret=' . $secretBase32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
