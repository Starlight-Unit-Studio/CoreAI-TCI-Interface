<?php
declare(strict_types=1);
// CoreAI 0.6.85: emergency reset of the sign-in protection of ONE account, on the server only.
//   php scripts/security-reset.php owner@example.com            second factors and advanced security off
//   php scripts/security-reset.php owner@example.com --passkeys  also remove all passkeys
// For an Inhaber who lost the phone with the authenticator app and the recovery codes. Signs the
// account out everywhere and writes the history. Never reachable over the web (CLI only).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/db.php';
require_once $root . '/api/auth_session_store.php';
require_once $root . '/api/security_store.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
$passkeys = in_array('--passkeys', $argv, true);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  fwrite(STDERR, "Aufruf: php scripts/security-reset.php konto@example.com [--passkeys]\n");
  exit(2);
}
$pdo = stu_pdo();
if (!coreui_security_ready($pdo)) { fwrite(STDERR, "Migration 030 fehlt.\n"); exit(3); }
$st = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
$st->execute([$email]);
$uid = (int)($st->fetchColumn() ?: 0);
if ($uid <= 0) { fwrite(STDERR, "Kein Konto mit dieser E-Mail.\n"); exit(4); }

coreui_security_put($pdo, $uid, ['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => 0,
  'email_mfa_at' => null, 'advanced_at' => null, 'recovery_codes' => null]);
if ($passkeys) $pdo->prepare('DELETE FROM stu_passkeys WHERE user_id = ?')->execute([$uid]);
$revoked = coreui_auth_session_revoke_all($pdo, $uid, 'security_reset');
coreui_security_event($pdo, $uid, 'totp_off', 'Server', 'Notfall-Zurücksetzung');
coreui_security_event($pdo, $uid, 'advanced_off', 'Server', 'Notfall-Zurücksetzung');
echo "Zurückgesetzt: Authenticator-App, E-Mail-Code, Wiederherstellungscodes und erweiterte Kontosicherheit"
  . ($passkeys ? ', alle Passkeys' : '') . ". Abgemeldete Geräte: {$revoked}. Anmeldung jetzt wieder mit dem Passwort.\n";
