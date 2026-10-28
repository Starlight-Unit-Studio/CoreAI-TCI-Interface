<?php
declare(strict_types=1);

/*
 * 0.5.78: Missbrauchsschutz, der geloeschte Cookies ueberlebt.
 *
 * Bisher zaehlte stu_rate_limit() in der PHP-Sitzung: wer Cookies loeschte,
 * den Browser schloss oder ein Skript ohne Cookie schickte, fing bei null
 * an. Neue Gastkonten gaben so beliebig oft neue Nachrichten.
 *
 * Hier zaehlt der Server in stu_abuse_counters (Migration 018), je Zweck und
 * Netzwerk (IPv4-Adresse, IPv6 als /64) oder je Konto. Gespeichert wird nur
 * ein HMAC mit einem Server-Geheimnis, nie eine IP oder E-Mail im Klartext;
 * Zeilen verfallen mit ihrem Zeitfenster.
 *
 * Hinter einem Reverse-Proxy (CoreAI lauscht auf 127.0.0.1) sieht PHP fuer
 * jeden Besucher dieselbe Adresse. Die echte steht dann in X-Forwarded-For,
 * dem nur lokale Proxys trauen duerfen. Sendet der Proxy keinen Kopf
 * ("proxy_only"), bleiben alle Regeln je Netzwerk aus, damit nicht alle
 * Besucher ein gemeinsames Limit teilen; der Admin-Bereich warnt dann.
 */

const COREUI_TRUSTED_PROXIES_DEFAULT = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

/** Address in a CIDR range, IPv4 or IPv6 (pure). */
function coreui_ip_in_cidr(string $ip, string $cidr): bool {
  $parts = explode('/', trim($cidr), 2);
  $ipBin = @inet_pton($ip);
  $netBin = @inet_pton($parts[0]);
  if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
  $max = strlen($ipBin) * 8;
  $bits = (isset($parts[1]) && preg_match('~^\d{1,3}$~', $parts[1]) === 1) ? min($max, (int)$parts[1]) : $max;
  $bytes = intdiv($bits, 8);
  if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
  $rest = $bits % 8;
  if ($rest === 0) return true;
  $mask = (0xFF << (8 - $rest)) & 0xFF;
  return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/** One address from a header or REMOTE_ADDR, without port or brackets (pure); '' if invalid. */
function coreui_ip_normalise(string $value): string {
  $v = trim($value);
  if (preg_match('~^\[([0-9a-f:.]+)\](?::\d+)?$~i', $v, $m) === 1) $v = $m[1];
  elseif (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3}):\d+$~', $v, $m) === 1) $v = $m[1];
  if (filter_var($v, FILTER_VALIDATE_IP) === false) return '';
  $bin = inet_pton($v);
  // IPv4 inside IPv6 (::ffff:1.2.3.4) counts as the IPv4 address.
  if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return inet_ntop(substr($bin, 12));
  return inet_ntop($bin);
}

/** Trusted proxies: STU_TRUSTED_PROXIES (comma separated ranges) or the local ranges. */
function coreui_trusted_proxies(): array {
  if (defined('STU_TRUSTED_PROXIES') && trim((string)STU_TRUSTED_PROXIES) !== '') {
    return array_values(array_filter(array_map('trim', explode(',', (string)STU_TRUSTED_PROXIES))));
  }
  return COREUI_TRUSTED_PROXIES_DEFAULT;
}

/**
 * Who is the visitor (pure)? source: direct (no proxy), forwarded (a trusted
 * proxy named the visitor), proxy_only (a local proxy without header: every
 * visitor looks the same, so per-network rules must stay off).
 * X-Forwarded-For is read from the right; only trusted proxies may add to it,
 * so a forged entry on the left never wins.
 */
function coreui_client_address(array $server, array $trusted): array {
  $isTrusted = static function (string $ip) use ($trusted): bool {
    foreach ($trusted as $cidr) if (coreui_ip_in_cidr($ip, (string)$cidr)) return true;
    return false;
  };
  $remote = coreui_ip_normalise((string)($server['REMOTE_ADDR'] ?? ''));
  if ($remote === '') return ['ip'=>'', 'source'=>'proxy_only'];
  if (!$isTrusted($remote)) return ['ip'=>$remote, 'source'=>'direct'];
  $chain = [];
  foreach (explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? '')) as $entry) {
    $ip = coreui_ip_normalise($entry);
    if ($ip !== '') $chain[] = $ip;
  }
  for ($i = count($chain) - 1; $i >= 0; $i--) {
    if (!$isTrusted($chain[$i])) return ['ip'=>$chain[$i], 'source'=>'forwarded'];
  }
  // Only local addresses in the chain: a visitor from the local network.
  if ($chain) return ['ip'=>$chain[0], 'source'=>'forwarded'];
  $real = coreui_ip_normalise((string)($server['HTTP_X_REAL_IP'] ?? ''));
  if ($real !== '') return ['ip'=>$real, 'source'=>'forwarded'];
  return ['ip'=>$remote, 'source'=>'proxy_only'];
}

function coreui_client_address_current(): array {
  return coreui_client_address($_SERVER, coreui_trusted_proxies());
}

/** Network of an address (pure): IPv4 as is, IPv6 as its /64 (one connection, many addresses). */
function coreui_network_of(string $ip): string {
  $bin = @inet_pton($ip);
  if ($bin === false) return '';
  if (strlen($bin) === 4) return inet_ntop($bin);
  return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** The network rules apply only when the visitor is really known. */
function coreui_client_network(?array $client = null): ?string {
  $client = $client ?? coreui_client_address_current();
  if (($client['source'] ?? '') === 'proxy_only' || ($client['ip'] ?? '') === '') return null;
  $net = coreui_network_of((string)$client['ip']);
  return $net !== '' ? $net : null;
}

/** Server secret for the keys (stu_app_settings, created once); tests may set it. */
function coreui_abuse_secret(PDO $pdo, ?string $set = null): string {
  static $secret = null;
  if ($set !== null) return $secret = $set;
  if (is_string($secret) && strlen($secret) >= 32) return $secret;
  $stored = function_exists('stu_app_setting_get') ? (string)stu_app_setting_get($pdo, 'abuse_guard_secret', '') : '';
  if (preg_match('~^[0-9a-f]{64}$~', $stored) !== 1) {
    $stored = bin2hex(random_bytes(32));
    if (function_exists('stu_app_setting_set')) stu_app_setting_set($pdo, 'abuse_guard_secret', $stored);
  }
  return $secret = $stored;
}

/** Counter key: HMAC of purpose and subject, nothing readable is stored. */
function coreui_abuse_key(PDO $pdo, string $purpose, string $subject): string {
  return hash_hmac('sha256', $purpose . '|' . strtolower($subject), coreui_abuse_secret($pdo));
}

/**
 * Adds $add hits in a sliding-start window and returns the state:
 * ['n'=>hits in the current window, 'reset'=>unix time the window ends, 'ok'=>table usable].
 * $add 0 only reads. A missing table (migration 018 not applied) fails open.
 */
function coreui_abuse_count(PDO $pdo, string $key, int $windowSec, int $add = 1, ?int $now = null): array {
  $now = $now ?? time();
  $windowSec = max(1, $windowSec);
  $fmt = static fn(int $t): string => date('Y-m-d H:i:s', $t);
  $cut = $fmt($now - $windowSec);
  try {
    if ($add > 0) {
      // Order matters for MySQL: window_start is assigned last, so every CASE sees the old window.
      $up = $pdo->prepare(
        'UPDATE stu_abuse_counters SET '
        . 'hits = CASE WHEN window_start <= ? THEN ? ELSE hits + ? END, '
        . 'expires_at = CASE WHEN window_start <= ? THEN ? ELSE expires_at END, '
        . 'window_start = CASE WHEN window_start <= ? THEN ? ELSE window_start END '
        . 'WHERE k = ?'
      );
      $args = [$cut, $add, $add, $cut, $fmt($now + $windowSec), $cut, $fmt($now), $key];
      $up->execute($args);
      if ($up->rowCount() === 0) {
        try {
          $pdo->prepare('INSERT INTO stu_abuse_counters (k, window_start, hits, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$key, $fmt($now), $add, $fmt($now + $windowSec)]);
        } catch (PDOException $race) {
          $up->execute($args); // another request created the row first
        }
      }
      if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM stu_abuse_counters WHERE expires_at < ?')->execute([$fmt($now)]);
      }
    }
    $st = $pdo->prepare('SELECT hits, window_start FROM stu_abuse_counters WHERE k = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return ['n'=>0, 'reset'=>$now + $windowSec, 'ok'=>true];
    $start = strtotime((string)$row['window_start']) ?: $now;
    if ($start <= $now - $windowSec) return ['n'=>0, 'reset'=>$now + $windowSec, 'ok'=>true];
    return ['n'=>(int)$row['hits'], 'reset'=>$start + $windowSec, 'ok'=>true];
  } catch (Throwable $e) {
    static $logged = false;
    if (!$logged && function_exists('stu__log_error')) {
      $logged = true;
      stu__log_error(['type'=>'abuse_guard_unavailable', 'message'=>$e->getMessage()]);
    }
    return ['n'=>0, 'reset'=>$now + $windowSec, 'ok'=>false];
  }
}

/** Takes one hit back (a refused turn did not happen). */
function coreui_abuse_give_back(PDO $pdo, string $key): void {
  try {
    $pdo->prepare('UPDATE stu_abuse_counters SET hits = CASE WHEN hits > 0 THEN hits - 1 ELSE 0 END WHERE k = ?')
      ->execute([$key]);
  } catch (Throwable $e) {}
}

function coreui_abuse_reset(PDO $pdo, string $key): void {
  try {
    $pdo->prepare('DELETE FROM stu_abuse_counters WHERE k = ?')->execute([$key]);
  } catch (Throwable $e) {}
}

/**
 * Server-side rate limit per network, on top of the old one in the session.
 * Off while every visitor looks the same (proxy without header).
 */
function coreui_abuse_rate_limit(PDO $pdo, string $bucket, int $max, int $windowSec, ?array $client = null): void {
  $network = coreui_client_network($client);
  if ($network === null) return;
  $state = coreui_abuse_count($pdo, coreui_abuse_key($pdo, 'rl:' . $bucket, $network), $windowSec);
  if ($state['n'] > $max) {
    stu_json(['ok'=>false, 'error'=>'rate_limited', 'retry_after'=>max(1, $state['reset'] - time())], 429);
  }
}

// Login: failed attempts per account and network, and per account overall
// (against guessing from many addresses). Counted only on failures; a
// successful login clears the account and network pair.
const COREUI_LOGIN_FAIL_WINDOW = 900;
const COREUI_LOGIN_FAIL_PER_NETWORK = 10;
const COREUI_LOGIN_FAIL_PER_ACCOUNT = 50;

function coreui_login_fail_keys(PDO $pdo, string $username, ?string $network): array {
  return [
    'pair'=>coreui_abuse_key($pdo, 'login_fail', $username . '|' . ($network ?? 'unknown')),
    'account'=>coreui_abuse_key($pdo, 'login_fail', $username),
  ];
}

/** Seconds to wait before this account may try again from here, 0 if allowed. */
function coreui_login_blocked_for(PDO $pdo, string $username, ?string $network, ?int $now = null): int {
  $now = $now ?? time();
  $keys = coreui_login_fail_keys($pdo, $username, $network);
  $pair = coreui_abuse_count($pdo, $keys['pair'], COREUI_LOGIN_FAIL_WINDOW, 0, $now);
  if ($pair['n'] >= COREUI_LOGIN_FAIL_PER_NETWORK) return max(1, $pair['reset'] - $now);
  $account = coreui_abuse_count($pdo, $keys['account'], COREUI_LOGIN_FAIL_WINDOW, 0, $now);
  if ($account['n'] >= COREUI_LOGIN_FAIL_PER_ACCOUNT) return max(1, $account['reset'] - $now);
  return 0;
}

function coreui_login_failed(PDO $pdo, string $username, ?string $network, ?int $now = null): void {
  foreach (coreui_login_fail_keys($pdo, $username, $network) as $key) {
    coreui_abuse_count($pdo, $key, COREUI_LOGIN_FAIL_WINDOW, 1, $now);
  }
}

function coreui_login_succeeded(PDO $pdo, string $username, ?string $network): void {
  coreui_abuse_reset($pdo, coreui_login_fail_keys($pdo, $username, $network)['pair']);
}
