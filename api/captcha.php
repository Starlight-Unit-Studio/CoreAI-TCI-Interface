<?php
declare(strict_types=1);

/*
 * 0.5.79: Captcha fuer "Als Gast chatten" und die Registrierung.
 *
 * Selbst gehostet, ohne Dritte (kein Google, keine Tracking-Cookies), nach dem
 * Prinzip von ALTCHA: Der Server stellt eine signierte Rechenaufgabe (finde die
 * Zahl n, fuer die SHA-256(salt + n) dem Challenge-Wert entspricht), der Browser
 * rechnet etwa eine Sekunde im Hintergrund, der Server prueft das Ergebnis in
 * Mikrosekunden. Jede Loesung gilt einmal und 10 Minuten. Ein Skript, das
 * tausend Gastkonten anlegen will, muss tausendmal rechnen; Menschen tippen nur
 * auf ihren Knopf. Bilderraetsel loesen heutige Bots mit KI ohnehin.
 */

require_once __DIR__ . '/abuse_guard.php';

const COREUI_CAPTCHA_MAX_NUMBER = 300000;
const COREUI_CAPTCHA_TTL = 600;

function coreui_captcha_enabled(PDO $pdo): bool {
  try {
    return stu_app_setting_get($pdo, 'captcha_enabled', '1') === '1';
  } catch (Throwable $e) {
    return true;
  }
}

/** A new signed task; $number and $max only for tests. */
function coreui_captcha_challenge(PDO $pdo, ?int $now = null, ?int $number = null, ?int $max = null): array {
  $now = $now ?? time();
  $max = max(1000, $max ?? COREUI_CAPTCHA_MAX_NUMBER);
  $salt = bin2hex(random_bytes(12)) . '?expires=' . ($now + COREUI_CAPTCHA_TTL);
  $number = $number ?? random_int(0, $max);
  $challenge = hash('sha256', $salt . $number);
  return [
    'algorithm' => 'SHA-256',
    'challenge' => $challenge,
    'salt' => $salt,
    'maxnumber' => $max,
    'signature' => hash_hmac('sha256', 'captcha|' . $challenge, coreui_abuse_secret($pdo)),
  ];
}

/**
 * Checks a solution: '' when valid (and marks it used), otherwise
 * captcha_required, captcha_invalid, captcha_expired or captcha_reused.
 */
function coreui_captcha_verify(PDO $pdo, $payload, ?int $now = null): string {
  $now = $now ?? time();
  if (is_string($payload) && $payload !== '') {
    $decoded = json_decode((string)base64_decode($payload, true), true);
    $payload = is_array($decoded) ? $decoded : null;
  }
  if (!is_array($payload)) return 'captcha_required';
  $challenge = strtolower((string)($payload['challenge'] ?? ''));
  $signature = strtolower((string)($payload['signature'] ?? ''));
  $salt = (string)($payload['salt'] ?? '');
  $number = $payload['number'] ?? null;
  if (preg_match('~^[0-9a-f]{64}$~', $challenge) !== 1 || preg_match('~^[0-9a-f]{64}$~', $signature) !== 1
      || preg_match('~^[0-9a-f]{24}\?expires=(\d{9,11})$~', $salt, $m) !== 1
      || !(is_int($number) || (is_string($number) && preg_match('~^\d{1,8}$~', $number) === 1))) {
    return 'captcha_invalid';
  }
  if ((int)$m[1] < $now) return 'captcha_expired';
  $expected = hash_hmac('sha256', 'captcha|' . $challenge, coreui_abuse_secret($pdo));
  if (!hash_equals($expected, $signature)) return 'captcha_invalid';
  if (!hash_equals($challenge, hash('sha256', $salt . (int)$number))) return 'captcha_invalid';
  $used = coreui_abuse_count($pdo, coreui_abuse_key($pdo, 'captcha_used', $challenge), COREUI_CAPTCHA_TTL, 1, $now);
  if ($used['ok'] && $used['n'] > 1) return 'captcha_reused';
  return '';
}

/** For auth.php: refuse the request unless the captcha is off or solved. */
function coreui_captcha_require(PDO $pdo, array $body): void {
  if (!coreui_captcha_enabled($pdo)) return;
  $error = coreui_captcha_verify($pdo, $body['captcha'] ?? null);
  if ($error !== '') stu_json(['ok' => false, 'error' => $error], 400);
}
