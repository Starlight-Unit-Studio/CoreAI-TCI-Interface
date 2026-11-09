<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.82: E-Mail sending (SMTP) set up by the Inhaber in Admin Core > Betrieb.
 *
 * Where the configuration comes from:
 *   1. the server environment (COREUI_SMTP_HOST and the other COREUI_SMTP_* / COREUI_MAIL_*):
 *      it always wins and is shown read-only in Admin Core;
 *   2. otherwise what the Inhaber saved in Admin Core (stu_app_settings 'mail_smtp').
 *
 * Security:
 *   - STARTTLS (usually 587) or SSL/TLS (usually 465); without encryption only to a server on
 *     this machine or in the private network, and then without user name or password.
 *   - The password is never sent back to the page. In the database it is encrypted
 *     (AES-256-GCM) with a key derived from the database password of the server environment
 *     plus a random salt, so a copy of the database alone does not reveal it. If the database
 *     password changes, the stored SMTP password cannot be read any more and must be entered
 *     again (Admin Core says so).
 *   - Changing the server or the user name requires entering the password again, so a stored
 *     password is never sent to a different server.
 */

require_once __DIR__ . '/config.php';

const COREUI_MAIL_SECURITY = ['starttls', 'tls', 'none'];
const COREUI_MAIL_DEFAULT_PORT = ['starttls' => 587, 'tls' => 465, 'none' => 25];

/** The configuration from the server environment, or null when COREUI_SMTP_HOST is not set (pure). */
function coreui_mail_env_config(): ?array {
  $host = defined('STU_SMTP_HOST') ? trim((string)STU_SMTP_HOST) : '';
  if ($host === '') return null;
  $port = defined('STU_SMTP_PORT') ? (int)STU_SMTP_PORT : 587;
  if ($port < 1 || $port > 65535) $port = 587;
  $security = defined('STU_SMTP_SECURITY') ? strtolower(trim((string)STU_SMTP_SECURITY)) : '';
  if (!in_array($security, COREUI_MAIL_SECURITY, true)) $security = $port === 465 ? 'tls' : 'starttls';
  // Without encryption only to a server on this machine or in the private network.
  if ($security === 'none' && !coreui_mail_host_local($host)) $security = 'starttls';
  return [
    'source' => 'env', 'host' => trim($host, '[]'), 'port' => $port, 'security' => $security,
    'user' => defined('STU_SMTP_USER') ? (string)STU_SMTP_USER : '',
    'pass' => defined('STU_SMTP_PASS') ? (string)STU_SMTP_PASS : '',
    'from' => defined('STU_MAIL_FROM') ? (string)STU_MAIL_FROM : '',
    'from_name' => defined('STU_MAIL_FROM_NAME') && STU_MAIL_FROM_NAME !== '' ? (string)STU_MAIL_FROM_NAME : 'CoreAI: TCI Interface',
    'pass_readable' => true,
  ];
}

/** A server on this machine or in the private network (pure; literal addresses and a few names). */
function coreui_mail_host_local(string $host): bool {
  $host = strtolower(trim($host, '[] '));
  if ($host === '') return false;
  if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    if ($host === '::1' || str_starts_with($host, '127.')) return true;
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
  }
  // localhost, Docker service names like "mailpit" or "postfix" (no dot), host.docker.internal,
  // and names of the local network (.local, .lan, .internal, .home.arpa).
  if ($host === 'localhost' || !str_contains($host, '.')) return true;
  return (bool)preg_match('~\.(local|lan|internal|home\.arpa|localhost)$~', $host);
}

/** A host name or address without scheme, path or spaces (pure). */
function coreui_mail_host_valid(string $host): bool {
  if ($host === '' || strlen($host) > 253) return false;
  if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
  return preg_match('~^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$~', $host) === 1;
}

/**
 * Checks what the Inhaber entered (pure). $current is the stored configuration (for keeping
 * the password). Returns the configuration to store; throws InvalidArgumentException(code).
 */
function coreui_mail_validate(array $in, ?array $current): array {
  $host = strtolower(trim(trim((string)($in['host'] ?? '')), '[]'));
  $security = (string)($in['security'] ?? 'starttls');
  if (!in_array($security, COREUI_MAIL_SECURITY, true)) throw new InvalidArgumentException('mail_security_invalid');
  if (!coreui_mail_host_valid($host)) throw new InvalidArgumentException('mail_host_invalid');
  $port = (int)($in['port'] ?? 0);
  if ($port === 0) $port = COREUI_MAIL_DEFAULT_PORT[$security];
  if ($port < 1 || $port > 65535) throw new InvalidArgumentException('mail_port_invalid');
  $user = trim((string)($in['user'] ?? ''));
  if (strlen($user) > 200 || preg_match('~[\r\n\0]~', $user)) throw new InvalidArgumentException('mail_user_invalid');
  $pass = (string)($in['pass'] ?? '');
  if (strlen($pass) > 300 || preg_match('~[\r\n\0]~', $pass)) throw new InvalidArgumentException('mail_pass_invalid');
  $from = trim((string)($in['from'] ?? ''));
  if (!filter_var($from, FILTER_VALIDATE_EMAIL) || strlen($from) > 200) throw new InvalidArgumentException('mail_from_invalid');
  $fromName = trim((string)($in['from_name'] ?? ''));
  if ($fromName === '') $fromName = 'CoreAI: TCI Interface';
  if (mb_strlen($fromName) > 100 || preg_match('~[\r\n\0"<>]~', $fromName)) throw new InvalidArgumentException('mail_from_name_invalid');
  if ($security === 'none') {
    if (!coreui_mail_host_local($host)) throw new InvalidArgumentException('mail_plain_not_local');
    if ($user !== '' || $pass !== '') throw new InvalidArgumentException('mail_plain_no_login');
  }
  if ($pass === '' && $user !== '') {
    // Keep the stored password only for the same server and the same user.
    $same = is_array($current) && ($current['host'] ?? '') === $host && ($current['user'] ?? '') === $user;
    if (!$same || (string)($current['pass'] ?? '') === '') throw new InvalidArgumentException('mail_pass_required');
    $pass = (string)$current['pass'];
  }
  if ($user === '' && $security !== 'none' && $pass !== '') throw new InvalidArgumentException('mail_user_required');
  return ['host' => $host, 'port' => $port, 'security' => $security, 'user' => $user, 'pass' => $pass,
    'from' => $from, 'from_name' => $fromName];
}

/** The key that protects the stored password: from the DB password of the environment and a salt. */
function coreui_mail_key(string $salt): string {
  $secret = defined('STU_DB_PASS') ? (string)STU_DB_PASS : '';
  return hash_hkdf('sha256', 'coreai-mail|' . $secret, 32, 'coreai-smtp-password-v1', $salt);
}

/** Encrypts the SMTP password for storage (pure apart from the random nonce). */
function coreui_mail_seal(string $pass, string $salt): string {
  if ($pass === '') return '';
  $nonce = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($pass, 'aes-256-gcm', coreui_mail_key($salt), OPENSSL_RAW_DATA, $nonce, $tag, 'smtp', 16);
  if (!is_string($cipher)) throw new RuntimeException('mail_encrypt_failed');
  return 'v1:' . base64_encode($nonce . $tag . $cipher);
}

/** The stored password back, or null when it cannot be read (other DB password, damaged) (pure). */
function coreui_mail_open(string $sealed, string $salt): ?string {
  if ($sealed === '') return '';
  if (!str_starts_with($sealed, 'v1:')) return null;
  $raw = base64_decode(substr($sealed, 3), true);
  if (!is_string($raw) || strlen($raw) < 29) return null;
  $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', coreui_mail_key($salt), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'smtp');
  return is_string($plain) ? $plain : null;
}

function coreui_mail_db_get(PDO $pdo, string $key): ?string {
  try {
    $st = $pdo->prepare('SELECT `value` FROM stu_app_settings WHERE `k` = ? LIMIT 1');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
  } catch (Throwable $e) {
    return null;
  }
}

function coreui_mail_db_put(PDO $pdo, string $key, ?string $value): void {
  if ($value === null) {
    $pdo->prepare('DELETE FROM stu_app_settings WHERE `k` = ?')->execute([$key]);
    return;
  }
  $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? 'INSERT INTO stu_app_settings (`k`, `value`) VALUES (?, ?) ON CONFLICT(`k`) DO UPDATE SET `value` = excluded.`value`'
    : 'INSERT INTO stu_app_settings (`k`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
  $pdo->prepare($sql)->execute([$key, $value]);
}

/** What the Inhaber saved in Admin Core, or null. */
function coreui_mail_db_config(PDO $pdo): ?array {
  $raw = coreui_mail_db_get($pdo, 'mail_smtp');
  $data = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($data) || !is_string($data['host'] ?? null) || $data['host'] === '') return null;
  $salt = (string)($data['salt'] ?? '');
  $pass = coreui_mail_open((string)($data['pass'] ?? ''), $salt);
  return ['source' => 'db', 'host' => (string)$data['host'], 'port' => (int)($data['port'] ?? 587),
    'security' => in_array($data['security'] ?? '', COREUI_MAIL_SECURITY, true) ? (string)$data['security'] : 'starttls',
    'user' => (string)($data['user'] ?? ''), 'pass' => $pass ?? '', 'pass_readable' => $pass !== null,
    'from' => (string)($data['from'] ?? ''), 'from_name' => (string)($data['from_name'] ?? 'CoreAI: TCI Interface'),
    'updated_at' => (string)($data['updated_at'] ?? '')];
}

/** Stores the Inhaber's configuration (checked first). */
function coreui_mail_save(PDO $pdo, array $in): array {
  if (coreui_mail_env_config() !== null) throw new RuntimeException('mail_env_locked');
  $current = coreui_mail_db_config($pdo);
  if ($current !== null && !$current['pass_readable']) $current['pass'] = '';
  $cfg = coreui_mail_validate($in, $current);
  $salt = bin2hex(random_bytes(16));
  coreui_mail_db_put($pdo, 'mail_smtp', (string)json_encode([
    'host' => $cfg['host'], 'port' => $cfg['port'], 'security' => $cfg['security'], 'user' => $cfg['user'],
    'pass' => coreui_mail_seal($cfg['pass'], $salt), 'salt' => $salt,
    'from' => $cfg['from'], 'from_name' => $cfg['from_name'], 'updated_at' => gmdate('c'),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  return (array)coreui_mail_db_config($pdo);
}

function coreui_mail_delete(PDO $pdo): void {
  if (coreui_mail_env_config() !== null) throw new RuntimeException('mail_env_locked');
  coreui_mail_db_put($pdo, 'mail_smtp', null);
}

/** The configuration that is used: environment first, then Admin Core, else null. */
function coreui_mail_effective(?PDO $pdo = null): ?array {
  $env = coreui_mail_env_config();
  if ($env !== null) return $env;
  if ($pdo === null) {
    if (!function_exists('stu_pdo')) {
      $db = __DIR__ . '/db.php';
      if (is_file($db)) require_once $db;
    }
    try { $pdo = function_exists('stu_pdo') ? stu_pdo() : null; } catch (Throwable $e) { $pdo = null; }
  }
  return $pdo instanceof PDO ? coreui_mail_db_config($pdo) : null;
}

/** Whether mail can go out with this configuration (pure). */
function coreui_mail_ready(?array $cfg): bool {
  if ($cfg === null || $cfg['host'] === '' || !filter_var($cfg['from'], FILTER_VALIDATE_EMAIL) || !$cfg['pass_readable']) return false;
  if ($cfg['security'] === 'none') return true;
  return $cfg['user'] === '' ? true : $cfg['pass'] !== '';
}

/** What Admin Core shows: never the password (pure). */
function coreui_mail_public(?array $cfg): array {
  if ($cfg === null) return ['source' => 'none', 'ready' => false];
  return ['source' => $cfg['source'], 'ready' => coreui_mail_ready($cfg), 'host' => $cfg['host'], 'port' => $cfg['port'],
    'security' => $cfg['security'], 'user' => $cfg['user'], 'has_password' => $cfg['pass'] !== '',
    'password_unreadable' => !$cfg['pass_readable'], 'from' => $cfg['from'], 'from_name' => $cfg['from_name'],
    'updated_at' => (string)($cfg['updated_at'] ?? '')];
}

/** A readable sentence for a mailer error code (pure). */
function coreui_mail_error_text(string $code): string {
  if (preg_match('~^smtp_unexpected_(53[0-9])~', $code)) return 'Der SMTP-Server hat die Anmeldung abgelehnt. Benutzer und Passwort prüfen.';
  if (preg_match('~^smtp_unexpected_(55[0-9])~', $code)) return 'Der SMTP-Server hat Absender oder Empfänger abgelehnt. Absenderadresse prüfen.';
  if (preg_match('~^smtp_unexpected_~', $code)) return 'Der SMTP-Server hat unerwartet geantwortet (' . substr($code, 16) . ').';
  return match ($code) {
    'smtp_connect_failed' => 'Keine Verbindung zum SMTP-Server. Server, Port und Firewall prüfen.',
    'smtp_tls_failed' => 'Die verschlüsselte Verbindung kam nicht zustande. Verschlüsselung und Port prüfen.',
    'smtp_no_response' => 'Der SMTP-Server hat nicht geantwortet.',
    'mail_not_configured' => 'Der E-Mail-Versand ist noch nicht eingerichtet.',
    'mail_recipient_invalid' => 'Die Empfängeradresse ist ungültig.',
    'mail_password_unreadable' => 'Das gespeicherte Passwort ist nicht mehr lesbar (Datenbank-Passwort geändert?). Bitte neu eingeben.',
    default => 'Die Testmail konnte nicht gesendet werden.',
  };
}
