<?php
declare(strict_types=1);
// CoreAI 0.5.78: protection against guest cheating and password guessing.
// Offline with an in-memory SQLite: deleting browser data and starting a new
// guest only gives back what is left for the network that day, a device comes
// back to its old guest account, IPv6 counts per /64, all guests share a daily
// cap, refused turns give back what they took, failed logins are counted per
// account on the server, and the visitor's address is read safely behind a
// reverse proxy (forged X-Forwarded-For entries never win; no header, no
// network rules).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';
require_once $root . '/api/guest_access.php';

function guard_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Abuse guard FAIL: {$why}\n"); exit(1); }
}

// MySQL statements of the stores, translated for SQLite.
final class GuardTestPdo extends PDO {
  private function sqlite(string $q): string {
    $q = str_replace(
      [' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', ' ON UPDATE CURRENT_TIMESTAMP', 'NOW()', 'AS UNSIGNED',
       'ON DUPLICATE KEY UPDATE value = CAST(value AS INTEGER) + 1, updated_at = CURRENT_TIMESTAMP',
       'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = CURRENT_TIMESTAMP'],
      ['', '', 'CURRENT_TIMESTAMP', 'AS INTEGER',
       'ON CONFLICT(user_id, k) DO UPDATE SET value = CAST(value AS INTEGER) + 1, updated_at = CURRENT_TIMESTAMP',
       'ON CONFLICT(k) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'],
      $q);
    return $q;
  }
  public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sqlite($query), $options); }
  public function exec(string $statement): int|false { return parent::exec($this->sqlite($statement)); }
}
$pdo = new GuardTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE stu_kv (user_id INTEGER NOT NULL, k TEXT NOT NULL, value TEXT NOT NULL, updated_at TEXT, PRIMARY KEY (user_id, k))');
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, guest_key TEXT UNIQUE, is_guest INTEGER, banned_until TEXT NULL)');
$pdo->exec('CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
$migration = (string)file_get_contents($root . '/database/migrations/018_abuse_guard.sql');
preg_match('~CREATE TABLE IF NOT EXISTS stu_abuse_counters \((.*?)\) ENGINE~s', $migration, $m);
guard_test(isset($m[1]), 'migration 018 creates stu_abuse_counters');
$pdo->exec('CREATE TABLE stu_abuse_counters (k CHAR(64) NOT NULL PRIMARY KEY, window_start TEXT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL)');
guard_test(str_contains($migration, "INSERT INTO stu_schema_migrations (version) VALUES ('018_abuse_guard')"), 'migration registers itself');
coreui_abuse_secret($pdo, str_repeat('ab', 32));
stu_app_setting_set($pdo, 'guest_message_limit', '5');

// --- Who is the visitor? -------------------------------------------------------
$trusted = COREUI_TRUSTED_PROXIES_DEFAULT;
$addr = static fn(array $server): array => coreui_client_address($server, $trusted);
guard_test($addr(['REMOTE_ADDR'=>'93.184.216.34']) === ['ip'=>'93.184.216.34', 'source'=>'direct'], 'direct visitor');
guard_test($addr(['REMOTE_ADDR'=>'93.184.216.34', 'HTTP_X_FORWARDED_FOR'=>'1.1.1.1']) === ['ip'=>'93.184.216.34', 'source'=>'direct'],
  'a visitor cannot pretend to be someone else');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1', 'HTTP_X_FORWARDED_FOR'=>'203.0.113.7']) === ['ip'=>'203.0.113.7', 'source'=>'forwarded'],
  'reverse proxy on 127.0.0.1 names the visitor');
guard_test($addr(['REMOTE_ADDR'=>'172.18.0.1', 'HTTP_X_FORWARDED_FOR'=>'6.6.6.6, 203.0.113.7']) === ['ip'=>'203.0.113.7', 'source'=>'forwarded'],
  'a forged entry on the left never wins (Docker gateway in front)');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1', 'HTTP_X_FORWARDED_FOR'=>'203.0.113.7, 10.0.0.2']) === ['ip'=>'203.0.113.7', 'source'=>'forwarded'],
  'two proxies');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1', 'HTTP_X_FORWARDED_FOR'=>'192.168.1.20']) === ['ip'=>'192.168.1.20', 'source'=>'forwarded'],
  'visitor from the home network');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1', 'HTTP_X_REAL_IP'=>'[2001:db8::5]:443']) === ['ip'=>'2001:db8::5', 'source'=>'forwarded'], 'X-Real-IP');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1']) === ['ip'=>'127.0.0.1', 'source'=>'proxy_only'], 'proxy without header');
guard_test($addr(['REMOTE_ADDR'=>'127.0.0.1', 'HTTP_X_FORWARDED_FOR'=>'kaputt']) ['source'] === 'proxy_only', 'garbage header');
guard_test($addr(['REMOTE_ADDR'=>'::ffff:203.0.113.9'])['ip'] === '203.0.113.9' && $addr(['REMOTE_ADDR'=>'1.2.3.4:5678'])['ip'] === '1.2.3.4',
  'mapped IPv4 and ports');
guard_test(coreui_client_network(['ip'=>'127.0.0.1', 'source'=>'proxy_only']) === null, 'no network rules without the real address');
guard_test(coreui_network_of('2001:db8:1:2::1') === coreui_network_of('2001:db8:1:2:ffff::9') && coreui_network_of('2001:db8:1:2::1') === '2001:db8:1:2::/64'
  && coreui_network_of('2001:db8:1:3::1') !== coreui_network_of('2001:db8:1:2::1'), 'IPv6 per /64');
guard_test(coreui_ip_in_cidr('172.31.255.1', '172.16.0.0/12') && !coreui_ip_in_cidr('172.32.0.1', '172.16.0.0/12')
  && coreui_ip_in_cidr('fd12::1', 'fc00::/7') && !coreui_ip_in_cidr('1.2.3.4', '::1/128'), 'ranges');

// --- Counters ---------------------------------------------------------------------
$t0 = 1_800_000_000;
$key = coreui_abuse_key($pdo, 'test', 'x');
guard_test(strlen($key) === 64 && $key !== coreui_abuse_key($pdo, 'test', 'y') && !str_contains($key, 'x'), 'keys are hashes');
guard_test(coreui_abuse_count($pdo, $key, 60, 1, $t0)['n'] === 1 && coreui_abuse_count($pdo, $key, 60, 1, $t0 + 10)['n'] === 2, 'counts up');
guard_test(coreui_abuse_count($pdo, $key, 60, 0, $t0 + 59)['n'] === 2 && coreui_abuse_count($pdo, $key, 60, 0, $t0 + 59)['reset'] === $t0 + 60, 'reads without counting');
guard_test(coreui_abuse_count($pdo, $key, 60, 1, $t0 + 61)['n'] === 1, 'a new window starts over');
coreui_abuse_give_back($pdo, $key);
guard_test(coreui_abuse_count($pdo, $key, 60, 0, $t0 + 62)['n'] === 0, 'give back');
$nothing = new PDO('sqlite::memory:');
$nothing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
guard_test(coreui_abuse_count($nothing, $key, 60, 1, $t0) === ['n'=>0, 'reset'=>$t0 + 60, 'ok'=>false], 'without migration 018 nobody is locked out');

// --- Guests: deleting browser data does not give new messages -----------------------
$net = static fn(string $ip): array => ['ip'=>$ip, 'source'=>'forwarded'];
$home = $net('203.0.113.7');
$guest = static function (int $id) use ($pdo): int {
  $pdo->prepare('INSERT INTO stu_users (id, guest_key, is_guest) VALUES (?, ?, 1)')->execute([$id, 'chat_test' . $id]);
  return $id;
};
$send = static fn(int $uid, array $client, int $times) => array_map(
  static fn() => coreui_guest_take_turn($pdo, $uid, $client), range(1, $times));

guard_test(coreui_guest_start_allowed($pdo, $home)['ok'], 'first guest may start');
$a = $guest(101);
$r = $send($a, $home, 3);
guard_test(end($r)['ok'] && end($r)['quota']['left'] === 2, 'guest A used 3 of 5');
// Browser data deleted: a new guest from the same network gets only the rest.
guard_test(coreui_guest_start_allowed($pdo, $home)['ok'], 'something is left, a new guest may start');
$b = $guest(102);
guard_test(coreui_guest_quota($pdo, $b, $home)['left'] === 2 && coreui_guest_quota($pdo, $b, $home)['shared'] === 'network',
  'the new guest sees only what the network has left');
$r = $send($b, $home, 3);
guard_test($r[0]['ok'] && $r[1]['ok'] && !$r[2]['ok'] && $r[2]['error'] === 'guest_network_limit' && is_int($r[2]['retry_at']),
  'after the rest: guest_network_limit with a time');
guard_test(coreui_guest_used($pdo, $b) === 2, 'the refused turn was given back to the guest');
$start = coreui_guest_start_allowed($pdo, $home);
guard_test(!$start['ok'] && $start['error'] === 'guest_network_exhausted', 'no third guest from this network today');
guard_test(!coreui_guest_take_turn($pdo, $a, $home)['ok'], 'guest A is also out (shared budget)');
// Another network is not affected.
$other = $net('198.51.100.20');
$c = $guest(103);
$r = $send($c, $other, 5);
guard_test(end($r)['ok'] && end($r)['quota']['left'] === 0, 'another network has its own 5');
$six = coreui_guest_take_turn($pdo, $c, $other);
guard_test(!$six['ok'] && $six['error'] === 'guest_limit_reached', 'own limit first');
// IPv6: a new address in the same /64 is the same network.
$v6a = $net('2001:db8:aa:1::10');
$d = $guest(104);
$send($d, $v6a, 5);
$e = $guest(105);
guard_test(!coreui_guest_take_turn($pdo, $e, $net('2001:db8:aa:1:dead::beef'))['ok'], 'new IPv6 address, same /64, no new messages');
guard_test(coreui_guest_take_turn($pdo, $e, $net('2001:db8:aa:2::10'))['ok'], 'another /64 is another network');
// The admin allows more guests per network (school, office).
stu_app_setting_set($pdo, 'guest_network_guests', '2');
guard_test(coreui_guest_start_allowed($pdo, $home)['ok'] && coreui_guest_quota($pdo, $b, $home)['left'] === 3, 'two guests per network: 10 together');
stu_app_setting_set($pdo, 'guest_network_guests', '1');
guard_test(coreui_guest_network_normalise('0') === 1 && coreui_guest_network_normalise('99') === 20 && coreui_guest_network_normalise('x') === 1,
  'guests per network 1 to 20');
guard_test(coreui_guest_daily_normalise('0') === 0 && coreui_guest_daily_normalise('999999') === 100000 && coreui_guest_daily_normalise('') === 200,
  'daily cap 0 (off) to 100000');

// Daily cap for all guests together, also when the address is unknown.
$pdo->exec('DELETE FROM stu_abuse_counters');
$pdo->exec('DELETE FROM stu_kv');
stu_app_setting_set($pdo, 'guest_daily_messages', '7');
$hidden = ['ip'=>'127.0.0.1', 'source'=>'proxy_only'];
$f = $guest(106);
$g = $guest(107);
$send($f, $hidden, 5);
$r = $send($g, $hidden, 3);
guard_test($r[0]['ok'] && $r[1]['ok'] && !$r[2]['ok'] && $r[2]['error'] === 'guest_daily_limit', 'daily cap for all guests');
guard_test(coreui_guest_used($pdo, $g) === 2, 'refused turn given back');
$day = coreui_guest_start_allowed($pdo, $hidden);
guard_test(!$day['ok'] && $day['error'] === 'guest_daily_exhausted', 'no new guests when the day is used up');
stu_app_setting_set($pdo, 'guest_daily_messages', '0');
guard_test(coreui_guest_take_turn($pdo, $g, $hidden)['ok'], 'daily cap 0 means no cap; no network rules without the address');

// --- Device marker -------------------------------------------------------------------
$token = str_repeat('c0', 32);
$deviceKey = coreui_guest_device_key($token);
guard_test(is_string($deviceKey) && str_starts_with($deviceKey, 'chat_') && strlen($deviceKey) === 37 && !str_contains($deviceKey, 'c0c0c0'),
  'the database never sees the token');
guard_test(coreui_guest_device_key('kurz') === null && coreui_guest_device_key(str_repeat('Z', 64)) === null, 'only real tokens');
$pdo->prepare('INSERT INTO stu_users (id, guest_key, is_guest) VALUES (201, ?, 1)')->execute([$deviceKey]);
guard_test(coreui_guest_find_device($pdo, $token) === ['id'=>201, 'banned'=>false], 'the device finds its guest account again');
$pdo->exec("UPDATE stu_users SET banned_until = '2999-01-01 00:00:00' WHERE id = 201");
guard_test(coreui_guest_find_device($pdo, $token)['banned'] === true, 'a banned guest stays banned');
guard_test(coreui_guest_find_device($pdo, str_repeat('d1', 32)) === null, 'unknown device');

// --- Failed logins ----------------------------------------------------------------------
$user = 'mara@example.org';
for ($i = 0; $i < 9; $i++) coreui_login_failed($pdo, $user, '203.0.113.7', $t0);
guard_test(coreui_login_blocked_for($pdo, $user, '203.0.113.7', $t0) === 0, 'nine failures still allowed');
coreui_login_failed($pdo, $user, '203.0.113.7', $t0);
guard_test(coreui_login_blocked_for($pdo, $user, '203.0.113.7', $t0 + 1) === COREUI_LOGIN_FAIL_WINDOW - 1, 'ten failures: wait 15 minutes');
guard_test(coreui_login_blocked_for($pdo, $user, '198.51.100.20', $t0 + 1) === 0, 'the owner elsewhere is not locked out');
guard_test(coreui_login_blocked_for($pdo, $user, '203.0.113.7', $t0 + COREUI_LOGIN_FAIL_WINDOW) === 0, 'free again after the window');
for ($n = 0; $n < 40; $n++) coreui_login_failed($pdo, $user, '10.9.' . $n . '.1', $t0 + 2);
guard_test(coreui_login_blocked_for($pdo, $user, '198.51.100.20', $t0 + 3) > 0, 'guessing from many addresses is stopped per account');
coreui_login_failed($pdo, 'other@example.org', null, $t0);
coreui_login_succeeded($pdo, 'other@example.org', null);
guard_test(coreui_abuse_count($pdo, coreui_login_fail_keys($pdo, 'other@example.org', null)['pair'], 900, 0, $t0)['n'] === 0,
  'a successful login clears the pair');

// --- Wiring ------------------------------------------------------------------------------
$auth = (string)file_get_contents($root . '/api/auth.php');
$guestAction = substr($auth, (int)strpos($auth, "if (\$action === 'guest') {"));
guard_test(strpos($guestAction, 'coreui_guest_find_device($pdo, $deviceToken)') < strpos($guestAction, 'coreui_guest_start_allowed($pdo)')
  && strpos($guestAction, 'coreui_guest_start_allowed($pdo)') < strpos($guestAction, 'coreui_guest_create($pdo, $deviceToken)')
  && str_contains($guestAction, 'coreui_guest_device_cookie_set($deviceToken);'), 'guest start: device first, then the budgets, then a new account with marker');
guard_test(str_contains($auth, 'coreui_abuse_rate_limit($pdoLimit, $bucket, $max, $windowSec);'), 'rate limits also on the server');
$login = substr($auth, (int)strpos($auth, '$blockedFor = coreui_login_blocked_for('));
guard_test(strpos($login, 'too_many_attempts') < strpos($login, 'password_verify(') && substr_count($login, 'coreui_login_failed(') >= 2
  && strpos($login, 'coreui_login_succeeded(') > strpos($login, 'password_verify('), 'login: check, count failures, clear on success');
$lib = (string)file_get_contents($root . '/api/guest_access.php');
guard_test(str_contains($lib, '$turn = coreui_guest_take_turn($pdo, $uid);'), 'every guest turn passes the shared budgets');
$admin = (string)file_get_contents($root . '/api/admin.php');
guard_test(str_contains($admin, "'guest_network_guests', 'guest_daily_messages',") && str_contains($admin, "coreui_client_address_current()['source']"),
  'admin saves both fields and learns whether visitors are recognised');
$adminJs = (string)file_get_contents($root . '/js/admin.js');
guard_test(str_contains($adminJs, "guest_network_guests: document.getElementById('guestNetworkGuests').value,")
  && str_contains($adminJs, 'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;'), 'admin page: fields and proxy hint');
$loginPage = (string)file_get_contents($root . '/login.html');
guard_test(str_contains($loginPage, "'guest_network_exhausted'") && str_contains($loginPage, "'guest_daily_exhausted'")
  && str_contains($loginPage, "'too_many_attempts':"), 'login page explains');
$app = (string)file_get_contents($root . '/js/console-app.js');
guard_test(substr_count($app, '/^guest_(?:limit_reached|network_limit|daily_limit)$/') === 2, 'chat explains every guest limit');
guard_test(str_contains((string)file_get_contents($root . '/api/health.php'), "'stu_abuse_counters',"), 'health checks the table');

echo "CoreAI abuse guard: shared network budget, device marker, IPv6 /64, daily cap, login throttle, proxy-safe address OK\n";
