<?php
declare(strict_types=1);

/*
 * 0.5.77: guest access from the login page ("Als Gast chatten").
 *
 * An admin switches it on in /admin/ (Runtime) and sets how many messages a
 * guest may send (default 5). The button creates a guest account (is_guest,
 * no e-mail, no password), provisions it like a normal account and logs it
 * in, so the chat starts at once. A guest only uses the private console.
 *
 * Every accepted turn counts: a sent message, "Neu generieren", "Weiter"
 * and an edited message. The counter lives per account in stu_kv, so
 * deleting a chat does not give the messages back. It is reserved
 * atomically before the turn is stored; the turn past the limit is refused.
 * New guest accounts are capped server wide per hour against abuse.
 *
 * 0.5.78, against cheating (deleting browser data, closing the browser, a private
 * window, logging out and starting again as a new guest):
 * - a device marker (cookie, 30 days) leads back to the same guest account;
 * - all guests of one network (IPv4 address, IPv6 /64) share their messages
 *   for 24 hours: "Gaeste pro Netzwerk und Tag" times the per-guest limit;
 * - all guests together have a daily cap ("Gast-Nachrichten pro Tag").
 * The counters live on the server (abuse_guard.php), not in the browser.
 */

require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/abuse_guard.php';

const COREUI_GUEST_LIMIT_DEFAULT = 5;
const COREUI_GUEST_LIMIT_MAX = 100;
const COREUI_GUEST_HOURLY_CAP = 30;
const COREUI_GUEST_KEY_PREFIX = 'chat_';
const COREUI_GUEST_COUNTER = 'coreui_guest_used';
const COREUI_GUEST_NETWORK_DEFAULT = 1;
const COREUI_GUEST_NETWORK_MAX = 20;
const COREUI_GUEST_DAILY_DEFAULT = 200;
const COREUI_GUEST_DAILY_MAX = 100000;
const COREUI_GUEST_WINDOW = 86400;
const COREUI_GUEST_DEVICE_COOKIE = 'coreui_guest_device';
const COREUI_GUEST_DEVICE_DAYS = 30;

function coreui_guest_enabled(PDO $pdo): bool {
  try {
    return stu_app_setting_get($pdo, 'guest_enabled', '0') === '1';
  } catch (Throwable $e) {
    return false;
  }
}

/** Admin value to a limit: whole messages from 1 to 100, anything else the default. */
function coreui_guest_limit_normalise(mixed $value): int {
  $text = trim((string)$value);
  if (preg_match('~^\d{1,4}$~', $text) !== 1) return COREUI_GUEST_LIMIT_DEFAULT;
  return max(1, min(COREUI_GUEST_LIMIT_MAX, (int)$text));
}

function coreui_guest_limit(PDO $pdo): int {
  try {
    return coreui_guest_limit_normalise(stu_app_setting_get($pdo, 'guest_message_limit', (string)COREUI_GUEST_LIMIT_DEFAULT));
  } catch (Throwable $e) {
    return COREUI_GUEST_LIMIT_DEFAULT;
  }
}

/** "Gaeste pro Netzwerk und Tag": 1 to 20, anything else the default (pure). */
function coreui_guest_network_normalise(mixed $value): int {
  $text = trim((string)$value);
  if (preg_match('~^\d{1,4}$~', $text) !== 1) return COREUI_GUEST_NETWORK_DEFAULT;
  return max(1, min(COREUI_GUEST_NETWORK_MAX, (int)$text));
}

/** "Gast-Nachrichten pro Tag" for all guests: 0 = no cap, at most 100000 (pure). */
function coreui_guest_daily_normalise(mixed $value): int {
  $text = trim((string)$value);
  if (preg_match('~^\d{1,6}$~', $text) !== 1) return COREUI_GUEST_DAILY_DEFAULT;
  return min(COREUI_GUEST_DAILY_MAX, (int)$text);
}

function coreui_guest_network_guests(PDO $pdo): int {
  try {
    return coreui_guest_network_normalise(stu_app_setting_get($pdo, 'guest_network_guests', (string)COREUI_GUEST_NETWORK_DEFAULT));
  } catch (Throwable $e) {
    return COREUI_GUEST_NETWORK_DEFAULT;
  }
}

function coreui_guest_daily_cap(PDO $pdo): int {
  try {
    return coreui_guest_daily_normalise(stu_app_setting_get($pdo, 'guest_daily_messages', (string)COREUI_GUEST_DAILY_DEFAULT));
  } catch (Throwable $e) {
    return COREUI_GUEST_DAILY_DEFAULT;
  }
}

/** Counters shared by guests: their network (null if unknown) and the whole day. */
function coreui_guest_shared_keys(PDO $pdo, ?string $network): array {
  return [
    'network'=>$network !== null ? coreui_abuse_key($pdo, 'guest_net', $network) : null,
    'daily'=>coreui_abuse_key($pdo, 'guest_day', 'all'),
  ];
}

/**
 * What is left for the shared budgets (pure): the tighter of network and day.
 * null = no shared limit applies.
 */
function coreui_guest_shared_left(?int $networkBudget, int $networkUsed, int $dailyCap, int $dailyUsed): ?int {
  $left = null;
  if ($networkBudget !== null) $left = max(0, $networkBudget - $networkUsed);
  if ($dailyCap > 0) $left = $left === null ? max(0, $dailyCap - $dailyUsed) : min($left, max(0, $dailyCap - $dailyUsed));
  return $left;
}

function coreui_guest_is(PDO $pdo, int $uid): bool {
  if ($uid <= 0) return false;
  $st = $pdo->prepare('SELECT is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  return (int)$st->fetchColumn() === 1;
}

function coreui_guest_used(PDO $pdo, int $uid): int {
  $st = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id = ? AND k = ? LIMIT 1');
  $st->execute([$uid, COREUI_GUEST_COUNTER]);
  return max(0, (int)$st->fetchColumn());
}

/**
 * What the chat shows a guest: null for normal accounts. "left" is what the
 * guest can really still send, also when the network or the day is the
 * tighter limit (then "shared" says which one).
 */
function coreui_guest_quota(PDO $pdo, int $uid, ?array $client = null): ?array {
  if (!coreui_guest_is($pdo, $uid)) return null;
  $limit = coreui_guest_limit($pdo);
  $used = min($limit, coreui_guest_used($pdo, $uid));
  $left = max(0, $limit - $used);
  $network = coreui_client_network($client);
  $keys = coreui_guest_shared_keys($pdo, $network);
  $netBudget = $network !== null ? $limit * coreui_guest_network_guests($pdo) : null;
  $netUsed = $keys['network'] !== null ? coreui_abuse_count($pdo, $keys['network'], COREUI_GUEST_WINDOW, 0)['n'] : 0;
  $daily = coreui_guest_daily_cap($pdo);
  $dailyUsed = $daily > 0 ? coreui_abuse_count($pdo, $keys['daily'], COREUI_GUEST_WINDOW, 0)['n'] : 0;
  $shared = coreui_guest_shared_left($netBudget, $netUsed, $daily, $dailyUsed);
  $out = ['limit'=>$limit, 'used'=>$used, 'left'=>$left];
  if ($shared !== null && $shared < $left) {
    $out['left'] = $shared;
    $out['shared'] = ($netBudget !== null && max(0, $netBudget - $netUsed) === $shared) ? 'network' : 'daily';
  }
  return $out;
}

/**
 * Counts one turn of a guest, or refuses it (pure decision, for the tests):
 * the counter after the atomic increment decides.
 */
function coreui_guest_turn_allowed(int $countAfter, int $limit): bool {
  return $countAfter <= $limit;
}

/**
 * Decides one guest turn and counts it (testable without a request): the
 * guest's own messages, then the network's and the day's shared budgets.
 * A refused turn gives back what it took. Returns ['ok'=>true, 'quota'=>...]
 * or ['ok'=>false, 'error'=>..., 'retry_at'=>unix time or null].
 */
function coreui_guest_take_turn(PDO $pdo, int $uid, ?array $client = null, ?int $now = null): array {
  $now = $now ?? time();
  $limit = coreui_guest_limit($pdo);
  $pdo->prepare(
    'INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, \'1\', NOW()) '
    . 'ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1, updated_at = NOW()'
  )->execute([$uid, COREUI_GUEST_COUNTER]);
  $count = coreui_guest_used($pdo, $uid);
  $giveBackOwn = static function () use ($pdo, $uid): void {
    $pdo->prepare(
      'UPDATE stu_kv SET value = CASE WHEN CAST(value AS UNSIGNED) > 0 THEN CAST(value AS UNSIGNED) - 1 ELSE 0 END '
      . 'WHERE user_id = ? AND k = ?'
    )->execute([$uid, COREUI_GUEST_COUNTER]);
  };
  if (!coreui_guest_turn_allowed($count, $limit)) {
    $giveBackOwn();
    return ['ok'=>false, 'error'=>'guest_limit_reached', 'retry_at'=>null,
      'quota'=>['limit'=>$limit, 'used'=>$limit, 'left'=>0]];
  }
  $network = coreui_client_network($client);
  $keys = coreui_guest_shared_keys($pdo, $network);
  if ($keys['network'] !== null) {
    $net = coreui_abuse_count($pdo, $keys['network'], COREUI_GUEST_WINDOW, 1, $now);
    if ($net['ok'] && $net['n'] > $limit * coreui_guest_network_guests($pdo)) {
      coreui_abuse_give_back($pdo, $keys['network']);
      $giveBackOwn();
      return ['ok'=>false, 'error'=>'guest_network_limit', 'retry_at'=>$net['reset'],
        'quota'=>['limit'=>$limit, 'used'=>$count - 1, 'left'=>0, 'shared'=>'network']];
    }
  }
  $daily = coreui_guest_daily_cap($pdo);
  if ($daily > 0) {
    $day = coreui_abuse_count($pdo, $keys['daily'], COREUI_GUEST_WINDOW, 1, $now);
    if ($day['ok'] && $day['n'] > $daily) {
      coreui_abuse_give_back($pdo, $keys['daily']);
      if ($keys['network'] !== null) coreui_abuse_give_back($pdo, $keys['network']);
      $giveBackOwn();
      return ['ok'=>false, 'error'=>'guest_daily_limit', 'retry_at'=>$day['reset'],
        'quota'=>['limit'=>$limit, 'used'=>$count - 1, 'left'=>0, 'shared'=>'daily']];
    }
  }
  return ['ok'=>true, 'quota'=>coreui_guest_quota($pdo, $uid, $client) ?? ['limit'=>$limit, 'used'=>$count, 'left'=>max(0, $limit - $count)]];
}

/**
 * Reserves one turn for a guest before it is stored, or answers
 * guest_limit_reached (own messages), guest_network_limit (the network's
 * share for today) or guest_daily_limit (all guests today). Normal accounts
 * pass untouched. Guests chat only in the private console.
 */
function coreui_guest_reserve_turn(PDO $pdo, int $uid, string $channel = 'console'): ?array {
  if (!coreui_guest_is($pdo, $uid)) return null;
  if ($channel !== 'console') stu_json(['ok'=>false, 'error'=>'guest_console_only'], 403);
  $turn = coreui_guest_take_turn($pdo, $uid);
  if (!$turn['ok']) {
    stu_json(['ok'=>false, 'error'=>$turn['error'], 'guest'=>$turn['quota'],
      'retry_at'=>$turn['retry_at'] !== null ? date('c', (int)$turn['retry_at']) : null], 403);
  }
  return $turn['quota'];
}

/** Guest key of a device marker (pure): the token itself never reaches the database. */
function coreui_guest_device_key(string $token): ?string {
  if (preg_match('~^[0-9a-f]{64}$~', $token) !== 1) return null;
  return COREUI_GUEST_KEY_PREFIX . substr(hash('sha256', 'coreai-guest-device|' . $token), 0, 32);
}

/** The guest account this device used before: ['id'=>..., 'banned'=>bool], or null. */
function coreui_guest_find_device(PDO $pdo, string $token): ?array {
  $key = coreui_guest_device_key($token);
  if ($key === null) return null;
  $st = $pdo->prepare('SELECT id, banned_until FROM stu_users WHERE guest_key = ? AND is_guest = 1 LIMIT 1');
  $st->execute([$key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) return null;
  $until = trim((string)($row['banned_until'] ?? ''));
  $banned = $until !== '' && ($ts = strtotime($until)) !== false && $ts > time();
  return ['id'=>(int)$row['id'], 'banned'=>$banned];
}

/**
 * May a new guest start here (testable)? Refused when the network's share
 * for today or the day's cap for all guests is used up.
 */
function coreui_guest_start_allowed(PDO $pdo, ?array $client = null, ?int $now = null): array {
  $limit = coreui_guest_limit($pdo);
  $network = coreui_client_network($client);
  $keys = coreui_guest_shared_keys($pdo, $network);
  if ($keys['network'] !== null) {
    $net = coreui_abuse_count($pdo, $keys['network'], COREUI_GUEST_WINDOW, 0, $now);
    if ($net['n'] >= $limit * coreui_guest_network_guests($pdo)) {
      return ['ok'=>false, 'error'=>'guest_network_exhausted', 'retry_at'=>$net['reset']];
    }
  }
  $daily = coreui_guest_daily_cap($pdo);
  if ($daily > 0) {
    $day = coreui_abuse_count($pdo, $keys['daily'], COREUI_GUEST_WINDOW, 0, $now);
    if ($day['n'] >= $daily) return ['ok'=>false, 'error'=>'guest_daily_exhausted', 'retry_at'=>$day['reset']];
  }
  return ['ok'=>true];
}

/** Creates and provisions a guest account; the caller logs it in. With a device token it can be found again. */
function coreui_guest_create(PDO $pdo, ?string $deviceToken = null): int {
  $recent = $pdo->prepare(
    "SELECT COUNT(*) FROM stu_users WHERE is_guest = 1 AND guest_key LIKE ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
  );
  $recent->execute([COREUI_GUEST_KEY_PREFIX . '%']);
  if ((int)$recent->fetchColumn() >= COREUI_GUEST_HOURLY_CAP) throw new RuntimeException('guest_capacity');

  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    $pdo->prepare(
      'INSERT INTO stu_users (guest_key, created_at, username, password_hash, is_guest, permission_level) '
      . 'VALUES (?, NOW(), NULL, NULL, 1, 4)'
    )->execute([($deviceToken !== null ? coreui_guest_device_key($deviceToken) : null)
      ?? COREUI_GUEST_KEY_PREFIX . bin2hex(random_bytes(16))]);
    $uid = (int)$pdo->lastInsertId();
    coreui_account_provision($pdo, $uid, '', 'Gast');
    if ($started) $pdo->commit();
    return $uid;
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** The device marker sent by the browser, if well formed. */
function coreui_guest_device_cookie(): ?string {
  $token = (string)($_COOKIE[COREUI_GUEST_DEVICE_COOKIE] ?? '');
  return preg_match('~^[0-9a-f]{64}$~', $token) === 1 ? $token : null;
}

/** Sets (or renews) the device marker: HttpOnly, 30 days, same path as the session. */
function coreui_guest_device_cookie_set(string $token): void {
  if (headers_sent()) return;
  $params = session_get_cookie_params();
  setcookie(COREUI_GUEST_DEVICE_COOKIE, $token, [
    'expires'=>time() + COREUI_GUEST_DEVICE_DAYS * 86400,
    'path'=>(string)($params['path'] ?? '/'),
    'secure'=>(bool)($params['secure'] ?? false),
    'httponly'=>true,
    'samesite'=>'Lax',
  ]);
}
