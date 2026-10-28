<?php
declare(strict_types=1);
// CoreAI 0.5.79: self-hosted captcha (proof of work) for guest access and
// sign-up. Offline with an in-memory SQLite: a task is signed by the server,
// the right number passes once, a wrong number, a forged signature, a changed
// salt, an expired task and a second use are refused, the switch turns it
// off, and auth.php asks for it only where new accounts start.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';
require_once $root . '/api/captcha.php';

function captcha_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Captcha FAIL: {$why}\n"); exit(1); }
}

final class CaptchaTestPdo extends PDO {
  private function sqlite(string $q): string {
    return str_replace(
      [' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', ' ON UPDATE CURRENT_TIMESTAMP', 'NOW()',
       'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = CURRENT_TIMESTAMP'],
      ['', '', 'CURRENT_TIMESTAMP', 'ON CONFLICT(k) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'],
      $q);
  }
  public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sqlite($query), $options); }
  public function exec(string $statement): int|false { return parent::exec($this->sqlite($statement)); }
}
$pdo = new CaptchaTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_abuse_counters (k CHAR(64) NOT NULL PRIMARY KEY, window_start TEXT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL)');
coreui_abuse_secret($pdo, str_repeat('cd', 32));
$now = 1_900_000_000;

captcha_test(coreui_captcha_enabled($pdo), 'on by default');
$task = coreui_captcha_challenge($pdo, $now, 4242, 5000);
captcha_test($task['algorithm'] === 'SHA-256' && $task['maxnumber'] === 5000 && preg_match('~^[0-9a-f]{24}\?expires=' . ($now + 600) . '$~', $task['salt']) === 1
  && $task['challenge'] === hash('sha256', $task['salt'] . '4242') && strlen($task['signature']) === 64, 'signed task, 10 minutes');
captcha_test(coreui_captcha_challenge($pdo, $now)['maxnumber'] === COREUI_CAPTCHA_MAX_NUMBER && COREUI_CAPTCHA_MAX_NUMBER === 300000,
  'real tasks: up to 300000 tries (about a second in the browser)');
$solution = ['algorithm'=>'SHA-256', 'challenge'=>$task['challenge'], 'salt'=>$task['salt'], 'number'=>4242, 'signature'=>$task['signature']];

captcha_test(coreui_captcha_verify($pdo, null, $now) === 'captcha_required', 'missing');
captcha_test(coreui_captcha_verify($pdo, ['number'=>4241] + $solution, $now) === 'captcha_invalid', 'wrong number');
captcha_test(coreui_captcha_verify($pdo, ['signature'=>str_repeat('0', 64)] + $solution, $now) === 'captcha_invalid', 'forged signature');
$own = hash('sha256', str_repeat('a', 24) . '?expires=' . ($now + 600) . '1');
captcha_test(coreui_captcha_verify($pdo, ['challenge'=>$own, 'salt'=>str_repeat('a', 24) . '?expires=' . ($now + 600), 'number'=>1] + $solution, $now) === 'captcha_invalid',
  'a self-made task is not signed');
captcha_test(coreui_captcha_verify($pdo, ['salt'=>substr($task['salt'], 0, 24) . '?expires=' . ($now + 9999)] + $solution, $now) === 'captcha_invalid',
  'a longer expiry breaks the hash');
captcha_test(coreui_captcha_verify($pdo, ['number'=>'12a'] + $solution, $now) === 'captcha_invalid', 'number must be digits');
captcha_test(coreui_captcha_verify($pdo, $solution, $now + 601) === 'captcha_expired', 'expired');
captcha_test(coreui_captcha_verify($pdo, $solution, $now) === '', 'the right solution passes');
captcha_test(coreui_captcha_verify($pdo, $solution, $now + 5) === 'captcha_reused', 'only once');
$second = coreui_captcha_challenge($pdo, $now, 77, 5000);
$encoded = base64_encode(json_encode(['challenge'=>$second['challenge'], 'salt'=>$second['salt'], 'number'=>'77', 'signature'=>$second['signature']]));
captcha_test(coreui_captcha_verify($pdo, $encoded, $now) === '', 'base64 form (as ALTCHA widgets send it)');
stu_app_setting_set($pdo, 'captcha_enabled', '0');
captcha_test(!coreui_captcha_enabled($pdo), 'admin switch off');

// Wiring.
$auth = (string)file_get_contents($root . '/api/auth.php');
$guest = substr($auth, (int)strpos($auth, "if (\$action === 'guest') {"));
captcha_test(strpos($guest, 'coreui_guest_find_device(') < strpos($guest, 'coreui_captcha_require($pdo, stu_read_json_body());')
  && strpos($guest, 'coreui_captcha_require($pdo, stu_read_json_body());') < strpos($guest, 'coreui_guest_create($pdo, $deviceToken)'),
  'a new guest needs the captcha, a known device does not');
$register = substr($auth, (int)strpos($auth, "if (\$action === 'register') {"));
captcha_test(strpos($register, 'coreui_captcha_require($pdo, $body);') < strpos($register, 'coreui_account_create('), 'sign-up needs it');
captcha_test(str_contains($auth, "if (\$action === 'captcha') {") && str_contains($auth, "stu_rate_limit('captcha', 30, 600);"), 'task endpoint, rate limited');
$admin = (string)file_get_contents($root . '/api/admin.php');
captcha_test(str_contains($admin, "'captcha_enabled' => '1',") && str_contains($admin, "'captcha_enabled', 'maintenance_enabled'"), 'admin switch saved');
captcha_test(str_contains((string)file_get_contents($root . '/admin/index.html'), 'id="captchaEnabled"'), 'admin switch shown');

echo "CoreAI captcha: signed task, right number once, forgeries, expiry and reuse refused, switch, wiring OK\n";
