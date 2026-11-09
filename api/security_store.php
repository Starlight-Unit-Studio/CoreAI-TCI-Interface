<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.85: Settings > Sicherheit und Anmeldung (migration 030).
 *
 *   Lockdown-Modus       web search and research in the browser off for the account's chats
 *   Authenticator-App    second factor with one-time codes (RFC 6238), secret sealed
 *   E-Mail-Code          second factor by mail (needs the SMTP sending of 0.6.82)
 *   Wiederherstellung    ten one-time recovery codes, stored as password hashes
 *   Passkeys             WebAuthn public keys only, bound to this server's name (RP ID)
 *   Erweiterte Sicherheit sign-in only with a passkey, no password reset by mail, sessions of
 *                        24 hours, sign-in notifications cannot be switched off
 *   Sicherheitsverlauf   sign-ins, failed attempts, sign-outs and every change on this page
 *
 * Challenges and pending sign-ins live in the PHP session, never in the database.
 */

require_once __DIR__ . '/secret_box.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/webauthn.php';

const COREUI_SECURITY_EVENTS_KEEP = 300;
const COREUI_SECURITY_ADVANCED_HOURS = 24;
const COREUI_SECURITY_CODE_MINUTES = 10;
const COREUI_SECURITY_MFA_TRIES = 5;
const COREUI_SECURITY_RECOVERY_COUNT = 10;

const COREUI_SECURITY_EVENT_LABELS = [
  'login' => 'Anmeldung', 'login_failed' => 'Fehlgeschlagene Anmeldung', 'mfa_failed' => 'Falscher Bestätigungscode',
  'logout' => 'Abmeldung', 'session_revoked' => 'Gerät abgemeldet', 'sessions_others' => 'Andere Geräte abgemeldet',
  'sessions_all' => 'Überall abgemeldet', 'password_changed' => 'Passwort geändert', 'password_reset' => 'Passwort zurückgesetzt',
  'totp_on' => 'Authenticator-App eingerichtet', 'totp_off' => 'Authenticator-App entfernt',
  'email_on' => 'E-Mail-Code eingeschaltet', 'email_off' => 'E-Mail-Code ausgeschaltet',
  'recovery_new' => 'Neue Wiederherstellungscodes', 'recovery_used' => 'Wiederherstellungscode benutzt',
  'passkey_added' => 'Passkey hinzugefügt', 'passkey_removed' => 'Passkey entfernt', 'passkey_renamed' => 'Passkey umbenannt',
  'advanced_on' => 'Erweiterte Kontosicherheit aktiviert', 'advanced_off' => 'Erweiterte Kontosicherheit beendet',
  'lockdown_on' => 'Lockdown-Modus an', 'lockdown_off' => 'Lockdown-Modus aus',
];

function coreui_security_ready(PDO $pdo): bool {
  static $ready = [];
  $key = spl_object_id($pdo);
  if (array_key_exists($key, $ready)) return $ready[$key];
  try {
    $pdo->query('SELECT user_id, lockdown, advanced_at, totp_secret, totp_enabled_at, totp_last_step, email_mfa_at, recovery_codes, webauthn_handle FROM stu_user_security LIMIT 0');
    $pdo->query('SELECT id, user_id, credential_id, rp_id, public_key, sign_count, label, created_at, last_used_at FROM stu_passkeys LIMIT 0');
    $pdo->query('SELECT id, user_id, kind, device_label, detail, created_at FROM stu_security_events LIMIT 0');
    return $ready[$key] = true;
  } catch (Throwable $e) {
    return $ready[$key] = false;
  }
}

function coreui_security_sqlite(PDO $pdo): bool {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function coreui_security_now(PDO $pdo): string {
  return coreui_security_sqlite($pdo) ? "datetime('now', 'localtime')" : 'NOW()';
}

function coreui_security_row(PDO $pdo, int $uid): array {
  $row = ['user_id' => $uid, 'lockdown' => 0, 'advanced_at' => null, 'totp_secret' => null, 'totp_enabled_at' => null,
    'totp_last_step' => 0, 'email_mfa_at' => null, 'recovery_codes' => null, 'webauthn_handle' => null];
  if ($uid <= 0 || !coreui_security_ready($pdo)) return $row;
  $st = $pdo->prepare('SELECT * FROM stu_user_security WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $found = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($found) ? array_merge($row, $found) : $row;
}

/** Sets columns of the account's row (created on first use). Column names are fixed here. */
function coreui_security_put(PDO $pdo, int $uid, array $fields): void {
  if (!coreui_security_ready($pdo)) throw new RuntimeException('security_migration_required');
  $allowed = ['lockdown', 'advanced_at', 'totp_secret', 'totp_enabled_at', 'totp_last_step', 'email_mfa_at', 'recovery_codes', 'webauthn_handle'];
  $cols = [];
  $vals = [];
  foreach ($fields as $col => $value) {
    if (!in_array($col, $allowed, true)) throw new InvalidArgumentException('security_field');
    $cols[] = $col;
    $vals[] = $value;
  }
  if ($cols === []) return;
  $sqlite = coreui_security_sqlite($pdo);
  $sql = 'INSERT INTO stu_user_security (user_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ') '
    . ($sqlite ? 'ON CONFLICT(user_id) DO UPDATE SET ' . implode(', ', array_map(static fn($c) => "$c = excluded.$c", $cols))
      : 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $cols)));
  $pdo->prepare($sql)->execute(array_merge([$uid], $vals));
}

// --- History -------------------------------------------------------------------------------------

function coreui_security_event(PDO $pdo, int $uid, string $kind, string $device = '', string $detail = ''): void {
  if ($uid <= 0 || !isset(COREUI_SECURITY_EVENT_LABELS[$kind]) || !coreui_security_ready($pdo)) return;
  try {
    $clean = static fn(string $s, int $n): string => mb_substr(trim((string)preg_replace('~[\x00-\x1f<>]~u', '', $s)), 0, $n);
    $pdo->prepare('INSERT INTO stu_security_events (user_id, kind, device_label, detail, created_at) VALUES (?, ?, ?, ?, ' . coreui_security_now($pdo) . ')')
      ->execute([$uid, $kind, $clean($device, 96), $clean($detail, 160)]);
    if (random_int(1, 20) === 1) {
      $keep = (int)($pdo->query('SELECT id FROM stu_security_events WHERE user_id = ' . $uid . ' ORDER BY id DESC LIMIT 1 OFFSET '
        . COREUI_SECURITY_EVENTS_KEEP)->fetchColumn() ?: 0);
      if ($keep > 0) $pdo->prepare('DELETE FROM stu_security_events WHERE user_id = ? AND id <= ?')->execute([$uid, $keep]);
    }
  } catch (Throwable $e) {
    // The history is best effort; it must never block a sign-in.
  }
}

/** Newest first: the history, plus sign-ins and sign-outs from before it existed (stu_auth_sessions). */
function coreui_security_history(PDO $pdo, int $uid, int $limit = 60): array {
  $out = [];
  if (coreui_security_ready($pdo)) {
    $st = $pdo->prepare('SELECT kind, device_label, detail, created_at FROM stu_security_events WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(300, $limit)));
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $out[] = ['kind' => (string)$r['kind'], 'label' => COREUI_SECURITY_EVENT_LABELS[(string)$r['kind']] ?? (string)$r['kind'],
        'device' => (string)$r['device_label'], 'detail' => (string)$r['detail'], 'at' => (string)$r['created_at']];
    }
  }
  try {
    $since = (string)($pdo->query("SELECT `value` FROM stu_app_settings WHERE `k` = 'security_events_since' LIMIT 1")->fetchColumn() ?: '');
    $sql = 'SELECT device_label, created_at, revoked_at, revoked_reason FROM stu_auth_sessions WHERE user_id = ?'
      . ($since !== '' ? ' AND created_at < ?' : '') . ' ORDER BY id DESC LIMIT 40';
    $st = $pdo->prepare($sql);
    $st->execute($since !== '' ? [$uid, $since] : [$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $out[] = ['kind' => 'login', 'label' => 'Anmeldung', 'device' => (string)$r['device_label'], 'detail' => '', 'at' => (string)$r['created_at']];
      if (!empty($r['revoked_at']) && (string)$r['revoked_reason'] !== 'token_rotated') {
        $out[] = ['kind' => 'logout', 'label' => 'Abmeldung', 'device' => (string)$r['device_label'], 'detail' => '', 'at' => (string)$r['revoked_at']];
      }
    }
  } catch (Throwable $e) {
    // Older servers without stu_auth_sessions: the new history only.
  }
  usort($out, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
  return array_slice($out, 0, $limit);
}

// --- State ---------------------------------------------------------------------------------------------

function coreui_security_lockdown(PDO $pdo, int $uid): bool {
  return (int)coreui_security_row($pdo, $uid)['lockdown'] === 1;
}

function coreui_security_advanced(PDO $pdo, int $uid): bool {
  return !empty(coreui_security_row($pdo, $uid)['advanced_at']);
}

function coreui_security_recovery_left(array $row): int {
  $list = json_decode((string)($row['recovery_codes'] ?? ''), true);
  return is_array($list) ? count($list) : 0;
}

function coreui_security_passkey_count(PDO $pdo, int $uid): int {
  if (!coreui_security_ready($pdo)) return 0;
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_passkeys WHERE user_id = ?');
  $st->execute([$uid]);
  return (int)$st->fetchColumn();
}

/** What is switched on for the account (for the page and the sign-in). */
function coreui_security_methods(PDO $pdo, int $uid): array {
  $row = coreui_security_row($pdo, $uid);
  return ['ready' => coreui_security_ready($pdo), 'totp' => !empty($row['totp_enabled_at']) && !empty($row['totp_secret']),
    'email' => !empty($row['email_mfa_at']), 'passkeys' => coreui_security_passkey_count($pdo, $uid),
    'recovery_left' => coreui_security_recovery_left($row), 'advanced' => !empty($row['advanced_at']), 'lockdown' => (int)$row['lockdown'] === 1];
}

function coreui_security_mfa_required(array $methods): bool {
  return !empty($methods['totp']) || !empty($methods['email']);
}

function coreui_security_set_lockdown(PDO $pdo, int $uid, bool $on, string $device = ''): void {
  coreui_security_put($pdo, $uid, ['lockdown' => $on ? 1 : 0]);
  coreui_security_event($pdo, $uid, $on ? 'lockdown_on' : 'lockdown_off', $device);
}

// --- Authenticator app ------------------------------------------------------------------------------------

function coreui_security_totp_key(PDO $pdo, int $uid): ?string {
  $row = coreui_security_row($pdo, $uid);
  if (empty($row['totp_enabled_at']) || empty($row['totp_secret'])) return null;
  return coreui_secret_open((string)$row['totp_secret'], 'totp');
}

/** Stores the key after the first correct code; returns false for a wrong code. */
function coreui_security_totp_enable(PDO $pdo, int $uid, string $key, string $code, int $now): bool {
  $step = coreui_totp_verify($key, $code, $now, 0);
  if ($step === null) return false;
  coreui_security_put($pdo, $uid, ['totp_secret' => coreui_secret_seal($key, 'totp'), 'totp_enabled_at' => date('Y-m-d H:i:s', $now), 'totp_last_step' => $step]);
  return true;
}

/** A code at sign-in; each time step is accepted once (the stored step only grows). */
function coreui_security_totp_check(PDO $pdo, int $uid, string $code, int $now): bool {
  $row = coreui_security_row($pdo, $uid);
  $key = coreui_security_totp_key($pdo, $uid);
  if ($key === null) return false;
  $step = coreui_totp_verify($key, $code, $now, (int)$row['totp_last_step']);
  if ($step === null) return false;
  $st = $pdo->prepare('UPDATE stu_user_security SET totp_last_step = ? WHERE user_id = ? AND totp_last_step < ?');
  $st->execute([$step, $uid, $step]);
  return $st->rowCount() === 1;
}

function coreui_security_totp_disable(PDO $pdo, int $uid): void {
  coreui_security_put($pdo, $uid, ['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => 0]);
}

// --- Codes by mail and recovery codes ------------------------------------------------------------------------

function coreui_security_code_new(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** A code kept in the session: only its hash, with expiry and tries. */
function coreui_security_code_hold(string $code, int $now): array {
  $salt = bin2hex(random_bytes(8));
  return ['hash' => hash('sha256', $salt . '|' . $code), 'salt' => $salt, 'exp' => $now + COREUI_SECURITY_CODE_MINUTES * 60, 'tries' => 0, 'sent' => $now];
}

/** 'ok', 'wrong', 'expired' or 'locked'; $held counts the try. */
function coreui_security_code_check(?array &$held, string $code, int $now): string {
  if (!is_array($held) || $now > (int)($held['exp'] ?? 0)) return 'expired';
  if ((int)($held['tries'] ?? 0) >= COREUI_SECURITY_MFA_TRIES) return 'locked';
  $held['tries'] = (int)($held['tries'] ?? 0) + 1;
  $code = preg_replace('~\s+~', '', $code) ?? '';
  if (preg_match('~^\d{6}$~', $code) !== 1) return 'wrong';
  return hash_equals((string)$held['hash'], hash('sha256', (string)$held['salt'] . '|' . $code)) ? 'ok' : 'wrong';
}

/** l•••@example.com */
function coreui_security_mask_email(string $email): string {
  $at = strrpos($email, '@');
  if ($at === false || $at < 1) return '';
  return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
}

/** Ten new recovery codes (shown once), stored as password hashes. */
function coreui_security_recovery_new(PDO $pdo, int $uid): array {
  $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
  $codes = [];
  for ($i = 0; $i < COREUI_SECURITY_RECOVERY_COUNT; $i++) {
    $c = '';
    for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
  }
  $hashes = array_map(static fn(string $c): string => (string)password_hash($c, PASSWORD_DEFAULT), $codes);
  coreui_security_put($pdo, $uid, ['recovery_codes' => json_encode($hashes)]);
  return $codes;
}

function coreui_security_recovery_use(PDO $pdo, int $uid, string $code): bool {
  $code = strtolower(trim($code));
  if (preg_match('~^[a-z0-9]{5}-?[a-z0-9]{5}$~', $code) !== 1) return false;
  if (!str_contains($code, '-')) $code = substr($code, 0, 5) . '-' . substr($code, 5);
  $list = json_decode((string)(coreui_security_row($pdo, $uid)['recovery_codes'] ?? ''), true);
  if (!is_array($list)) return false;
  foreach ($list as $i => $hash) {
    if (is_string($hash) && password_verify($code, $hash)) {
      unset($list[$i]);
      coreui_security_put($pdo, $uid, ['recovery_codes' => json_encode(array_values($list))]);
      return true;
    }
  }
  return false;
}

// --- Passkeys -------------------------------------------------------------------------------------------------

/** This server's WebAuthn name: the host of COREUI_PUBLIC_URL, else the request's host (pure apart from $_SERVER). */
function coreui_webauthn_rp_id(?array $server = null): string {
  $server = $server ?? $_SERVER;
  $public = defined('STU_PUBLIC_BASE_URL') ? (string)STU_PUBLIC_BASE_URL : '';
  $host = $public !== '' ? (string)(parse_url($public, PHP_URL_HOST) ?? '') : (string)($server['HTTP_HOST'] ?? '');
  $host = strtolower(trim($host));
  if (str_starts_with($host, '[')) return '';
  $host = (string)preg_replace('~:\d+$~', '', $host);
  return preg_match('~^[a-z0-9.-]{1,253}$~', $host) === 1 ? $host : '';
}

function coreui_security_webauthn_handle(PDO $pdo, int $uid): string {
  $handle = (string)(coreui_security_row($pdo, $uid)['webauthn_handle'] ?? '');
  if (preg_match('~^[A-Za-z0-9_-]{43}$~', $handle) === 1) return $handle;
  $handle = coreui_b64u(random_bytes(32));
  coreui_security_put($pdo, $uid, ['webauthn_handle' => $handle]);
  return $handle;
}

/** The account's passkeys for the page: no keys, no credential ids. */
function coreui_security_passkeys(PDO $pdo, int $uid): array {
  if (!coreui_security_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT id, label, created_at, last_used_at FROM stu_passkeys WHERE user_id = ? ORDER BY id');
  $st->execute([$uid]);
  return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['label'],
    'created_at' => (string)$r['created_at'], 'last_used_at' => $r['last_used_at']], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function coreui_security_passkey_ids(PDO $pdo, int $uid): array {
  if (!coreui_security_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT credential_id FROM stu_passkeys WHERE user_id = ?');
  $st->execute([$uid]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function coreui_security_passkey_label(string $label): string {
  $label = trim((string)preg_replace('~[\x00-\x1f<>]~u', '', $label));
  return $label === '' ? 'Passkey' : mb_substr($label, 0, 60);
}

/** Creation options for navigator.credentials.create (the challenge is the caller's to keep). */
function coreui_security_passkey_create_options(PDO $pdo, int $uid, string $username, string $displayName, string $challenge, string $rpId): array {
  return ['challenge' => coreui_b64u($challenge), 'rp' => ['name' => 'CoreAI', 'id' => $rpId],
    'user' => ['id' => coreui_security_webauthn_handle($pdo, $uid), 'name' => $username, 'displayName' => $displayName !== '' ? $displayName : $username],
    'pubKeyCredParams' => array_map(static fn(int $alg): array => ['type' => 'public-key', 'alg' => $alg], COREUI_WEBAUTHN_ALGS),
    'timeout' => 120000, 'attestation' => 'none',
    'authenticatorSelection' => ['residentKey' => 'preferred', 'requireResidentKey' => false, 'userVerification' => 'preferred'],
    'excludeCredentials' => array_map(static fn(string $id): array => ['type' => 'public-key', 'id' => $id], coreui_security_passkey_ids($pdo, $uid))];
}

function coreui_security_passkey_add(PDO $pdo, int $uid, array $reg, string $rpId, string $label): int {
  if (coreui_security_passkey_count($pdo, $uid) >= 20) throw new RuntimeException('passkey_limit');
  try {
    $pdo->prepare('INSERT INTO stu_passkeys (user_id, credential_id, rp_id, public_key, sign_count, label, created_at) VALUES (?, ?, ?, ?, ?, ?, '
      . coreui_security_now($pdo) . ')')->execute([$uid, $reg['credential_id'], $rpId, $reg['public_key'], (int)$reg['sign_count'], coreui_security_passkey_label($label)]);
  } catch (PDOException $e) {
    throw new RuntimeException('passkey_exists');
  }
  return (int)$pdo->lastInsertId();
}

function coreui_security_passkey_find(PDO $pdo, string $credentialId): ?array {
  if (!coreui_security_ready($pdo) || preg_match('~^[A-Za-z0-9_-]{16,255}$~', $credentialId) !== 1) return null;
  $st = $pdo->prepare('SELECT id, user_id, credential_id, rp_id, public_key, sign_count, label FROM stu_passkeys WHERE credential_id = ? LIMIT 1');
  $st->execute([$credentialId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function coreui_security_passkey_used(PDO $pdo, int $id, int $signCount): void {
  $pdo->prepare('UPDATE stu_passkeys SET sign_count = ?, last_used_at = ' . coreui_security_now($pdo) . ' WHERE id = ?')->execute([$signCount, $id]);
}

function coreui_security_passkey_rename(PDO $pdo, int $uid, int $id, string $label): bool {
  $st = $pdo->prepare('UPDATE stu_passkeys SET label = ? WHERE id = ? AND user_id = ?');
  $st->execute([coreui_security_passkey_label($label), $id, $uid]);
  return $st->rowCount() === 1;
}

/** Removes one; the last one stays while advanced account security needs it. */
function coreui_security_passkey_remove(PDO $pdo, int $uid, int $id): bool {
  if (coreui_security_advanced($pdo, $uid) && coreui_security_passkey_count($pdo, $uid) <= 1) throw new RuntimeException('advanced_needs_passkey');
  $st = $pdo->prepare('DELETE FROM stu_passkeys WHERE id = ? AND user_id = ?');
  $st->execute([$id, $uid]);
  return $st->rowCount() === 1;
}

// --- Advanced account security ----------------------------------------------------------------------------------

/** Turns it on; needs a passkey. Returns new recovery codes when the account had none. */
function coreui_security_advanced_enable(PDO $pdo, int $uid, int $now): array {
  if (coreui_security_passkey_count($pdo, $uid) < 1) throw new RuntimeException('advanced_needs_passkey');
  $codes = coreui_security_recovery_left(coreui_security_row($pdo, $uid)) > 0 ? [] : coreui_security_recovery_new($pdo, $uid);
  coreui_security_put($pdo, $uid, ['advanced_at' => date('Y-m-d H:i:s', $now)]);
  return $codes;
}

function coreui_security_advanced_disable(PDO $pdo, int $uid): void {
  coreui_security_put($pdo, $uid, ['advanced_at' => null]);
}

/** Hours a new sign-in lasts: 24 with advanced account security, else 30 days. */
function coreui_security_session_hours(PDO $pdo, int $uid): int {
  return coreui_security_advanced($pdo, $uid) ? COREUI_SECURITY_ADVANCED_HOURS : 30 * 24;
}
