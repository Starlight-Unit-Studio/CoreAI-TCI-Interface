<?php
declare(strict_types=1);
// CoreAI 0.6.84: Settings > Profil overview (api/usage_store.php, api/profile_stats.php), offline.
//   php scripts/profile-stats-selftest.php
// Thinking levels, streaks (longest, current, ended yesterday), tokens counted per account, day,
// Core and level (only with an account, never text), and the overview from SQLite: tokens and
// peak day, longest task, raster of 53 weeks, tools in order, what Mara made, insights; only the
// account's own rows; the wiring. With COREUI_SELFTEST_DB=1 also on MariaDB (migration 029).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/usage_store.php';

function p_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Profile stats FAIL: {$why}\n"); exit(1); }
}

// --- Pure helpers ----------------------------------------------------------------------------------
foreach ([[false, 'off'], [null, 'off'], [true, 'on'], ['high', 'high'], ['MAX', 'max'], ['weird', 'on'], ['', 'off']] as [$in, $out]) {
  p_test(coreui_usage_thinking_key($in) === $out, 'thinking key ' . var_export($in, true));
}
p_test(coreui_usage_thinking_label('high') === 'Hoch' && coreui_usage_thinking_label('off') === 'Ohne Denken', 'thinking labels');
p_test(coreui_usage_streaks([], '2026-11-09') === ['longest' => 0, 'current' => 0, 'active_days' => 0], 'no days');
$s = coreui_usage_streaks(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-05', '2026-11-08', '2026-11-09', '2026-11-09'], '2026-11-09');
p_test($s === ['longest' => 3, 'current' => 2, 'active_days' => 6], 'streaks: ' . json_encode($s));
p_test(coreui_usage_streaks(['2026-11-07', '2026-11-08'], '2026-11-09')['current'] === 2, 'current streak still counts until the day ends');
p_test(coreui_usage_streaks(['2026-11-06', '2026-11-07'], '2026-11-09')['current'] === 0, 'a missed day ends the streak');
p_test(coreui_usage_streaks(['2026-02-28', '2026-03-01', '2026-03-02'], '2026-03-02')['longest'] === 3, 'across a month end');

// --- SQLite ---------------------------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
  'CREATE TABLE stu_users (id INTEGER PRIMARY KEY, username TEXT, permission_level INT, is_guest INT, created_at TEXT)',
  'CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, channel TEXT, session_id TEXT, user_id INT, character_id TEXT, created_at TEXT, deleted_at TEXT)',
  'CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)',
  'CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INT)',
  'CREATE TABLE stu_console_agent_runs (run_id TEXT PRIMARY KEY, user_id INT, status TEXT, created_at TEXT, started_at TEXT, finished_at TEXT)',
  'CREATE TABLE stu_console_work_events (id INTEGER PRIMARY KEY, user_id INT, session_id TEXT, turn_id INT, kind TEXT, title TEXT, details_json TEXT, created_at TEXT)',
  'CREATE TABLE stu_console_artifacts (id TEXT PRIMARY KEY, user_id INT, session_id TEXT, orig_name TEXT, created_at TEXT)',
] as $sql) $pdo->exec($sql);
p_test(!coreui_usage_ready($pdo) && !coreui_usage_record($pdo, 2, 'ember-core:e4b', 10, 5, 'on'), 'before migration 029: nothing counted, no error');
$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo = $pdo2;
foreach ([
  'CREATE TABLE stu_users (id INTEGER PRIMARY KEY, username TEXT, permission_level INT, is_guest INT, created_at TEXT)',
  'CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, channel TEXT, session_id TEXT, user_id INT, character_id TEXT, created_at TEXT, deleted_at TEXT)',
  'CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)',
  'CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INT)',
  'CREATE TABLE stu_console_agent_runs (run_id TEXT PRIMARY KEY, user_id INT, status TEXT, created_at TEXT, started_at TEXT, finished_at TEXT)',
  'CREATE TABLE stu_console_work_events (id INTEGER PRIMARY KEY, user_id INT, session_id TEXT, turn_id INT, kind TEXT, title TEXT, details_json TEXT, created_at TEXT)',
  'CREATE TABLE stu_console_artifacts (id TEXT PRIMARY KEY, user_id INT, session_id TEXT, orig_name TEXT, created_at TEXT)',
  'CREATE TABLE stu_usage_daily (user_id INT, day TEXT, model TEXT, thinking TEXT, calls INT DEFAULT 0, prompt_tokens INT DEFAULT 0, '
    . 'output_tokens INT DEFAULT 0, PRIMARY KEY (user_id, day, model, thinking))',
] as $sql) $pdo->exec($sql);
p_test(coreui_usage_ready($pdo), 'migration 029 seen');

// Counting.
p_test(coreui_usage_record($pdo, 2, 'ember-core:e4b', 1200, 300, 'high'), 'counted');
p_test(coreui_usage_record($pdo, 2, 'ember-core:e4b', 800, 200, 'high'), 'counted again');
p_test(!coreui_usage_record($pdo, 0, 'x', 1, 1, 'on') && !coreui_usage_record($pdo, 2, '', 1, 1, 'on')
  && !coreui_usage_record($pdo, 2, 'x', 0, 0, 'on') && !coreui_usage_record($pdo, 2, str_repeat('m', 121), 1, 1, 'on'), 'nothing without account, Core or tokens');
$row = $pdo->query("SELECT calls, prompt_tokens, output_tokens, thinking, day FROM stu_usage_daily WHERE user_id = 2")->fetch(PDO::FETCH_ASSOC);
p_test((int)$row['calls'] === 2 && (int)$row['prompt_tokens'] === 2000 && (int)$row['output_tokens'] === 500 && $row['thinking'] === 'high'
  && preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)$row['day']) === 1, 'one row per day, Core and level, summed: ' . json_encode($row));
// From a chat turn: the account comes from the work context, the connection from the turn.
$GLOBALS['COREUI_WORK_CONTEXT'] = ['user_id' => 3, 'session_id' => 'cs_x', 'turn_id' => 1, 'run_key' => 'base'];
$GLOBALS['COREUI_WORK_PDO'] = $pdo;
coreui_usage_record_call('ember-core:e2b', 100, 50, false);
coreui_usage_record_call('ember-core:e2b', 0, 0, false);
unset($GLOBALS['COREUI_WORK_CONTEXT']);
coreui_usage_record_call('ember-core:e2b', 100, 50, false);
unset($GLOBALS['COREUI_WORK_PDO']);
p_test((int)$pdo->query('SELECT SUM(calls) FROM stu_usage_daily WHERE user_id = 3')->fetchColumn() === 1
  && (int)$pdo->query('SELECT COUNT(*) FROM stu_usage_daily')->fetchColumn() === 2, 'a turn counts for its account; no account, no count');
$pdo->exec('DELETE FROM stu_usage_daily');

// History of account 2 (today 2026-11-09), with rows of account 9 and of Mara that must not count.
$pdo->exec("INSERT INTO stu_users VALUES (2, 'lena@example.test', 4, 0, '2026-03-14 10:00:00'), (9, 'other@example.test', 4, 0, '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO stu_app_settings VALUES ('usage_counting_since', '2026-11-07', '2026-11-07')");
$msg = static function (int $uid, string $char, string $at, ?string $deleted = null) use ($pdo): void {
  $pdo->prepare("INSERT INTO stu_chat_messages (channel, session_id, user_id, character_id, created_at, deleted_at) VALUES ('console', 'cs_a', ?, ?, ?, ?)")
    ->execute([$uid, $char, $at, $deleted]);
};
foreach (['2026-10-01 09:00:00', '2026-10-02 09:00:00', '2026-10-02 18:00:00', '2026-10-03 09:00:00', '2026-10-04 09:00:00',
  '2026-11-08 20:00:00', '2026-11-09 08:00:00'] as $at) $msg(2, 'lena', $at);
$msg(2, 'ember', '2026-10-05 09:00:00');                         // Mara's answer
$msg(2, 'lena', '2026-10-06 09:00:00', '2026-10-06 10:00:00');   // deleted
$msg(9, 'other', '2026-10-05 09:00:00');                         // another account
$msg(2, 'lena', '2025-01-01 09:00:00');                          // outside the raster, still history
$pdo->exec("INSERT INTO stu_usage_daily VALUES (2, '2026-11-07', 'ember-core:e4b', 'high', 3, 40000, 9000), "
  . "(2, '2026-11-08', 'ember-core:e2b', 'off', 1, 2000, 500), (2, '2026-11-09', 'ember-core:e4b', 'high', 2, 1000000, 200000), "
  . "(2, '2026-11-09', 'ember-core:e4b', 'on', 4, 100, 100), (9, '2026-11-09', 'ember-core:e4b', 'max', 50, 999999999, 1)");
$pdo->exec("INSERT INTO stu_console_agent_runs VALUES ('r1', 2, 'completed', '2026-11-01 10:00:00', '2026-11-01 10:00:30', '2026-11-01 11:50:30'), "
  . "('r2', 2, 'failed', '2026-11-02 10:00:00', '2026-11-02 10:00:00', '2026-11-03 10:00:00'), "
  . "('r3', 2, 'completed', '2026-11-03 10:00:00', NULL, '2026-11-03 10:05:00'), ('r4', 9, 'completed', '2026-11-03 10:00:00', '2026-11-03 10:00:00', '2026-11-05 10:00:00')");
$ev = static function (int $uid, int $turn, string $kind, string $title, string $meta = '{}', string $session = 'cs_a', string $at = '2026-11-01 10:00:00') use ($pdo): void {
  $pdo->prepare('INSERT INTO stu_console_work_events (user_id, session_id, turn_id, kind, title, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$uid, $session, $turn, $kind, $title, $meta, $at]);
};
for ($i = 0; $i < 4; $i++) $ev(2, $i, 'tool', 'Websuche gestartet');
$ev(2, 1, 'tool', 'Browserauftrag eingereiht');
$ev(2, 1, 'tool', 'Skill geladen: weather');
$ev(2, 1, 'tool', 'Skill geladen: weather');     // the same load reported twice in one turn
$ev(2, 2, 'tool', 'Skill geladen: weather');
$ev(2, 2, 'tool', 'Skill geladen: pdf');
$ev(2, 3, 'artifact', 'Bild erzeugt', '{"image_uuid":"' . str_repeat('a', 32) . '","image_px":512}', 'cs_img', '2026-11-08 12:00:00');
$ev(2, 4, 'artifact', 'Bild erzeugt', '{"image_uuid":"../../etc"}', 'cs_img', '2026-11-08 13:00:00');
$ev(9, 1, 'tool', 'Python-Job eingereiht');
$pdo->exec("INSERT INTO stu_console_artifacts VALUES ('f1', 2, 'cs_files', 'bericht.pdf', '2026-11-09 07:00:00'), ('f2', 9, 'cs_o', 'secret.txt', '2026-11-09 07:00:00')");
$pdo->exec("INSERT INTO stu_console_sessions VALUES ('cs_a', 2), ('cs_img', 2), ('cs_files', 2), ('cs_o', 9)");

$stats = coreui_profile_stats($pdo, 2, 'ember', '2026-11-09');
p_test($stats['tokens'] === ['ready' => true, 'since' => '2026-11-07', 'total' => 1251700, 'peak' => 1200200, 'peak_day' => '2026-11-09'],
  'tokens: total, peak day, since: ' . json_encode($stats['tokens']));
p_test($stats['longest_task_seconds'] === 6600, 'longest completed task 1 h 50 min (failed and foreign runs ignored): ' . $stats['longest_task_seconds']);
p_test($stats['streak'] === ['longest' => 4, 'current' => 2, 'active_days' => 7], 'streaks from own messages only: ' . json_encode($stats['streak']));
p_test($stats['messages'] === 8 && $stats['chats'] === 3, 'messages and chats: ' . $stats['messages'] . '/' . $stats['chats']);
p_test(count($stats['raster']) === 371 && $stats['raster'][370] === ['d' => '2026-11-09', 'm' => 1, 't' => 1200200]
  && $stats['raster'][0]['d'] === '2025-11-04', 'raster: 53 weeks ending today');
$byDay = array_column($stats['raster'], null, 'd');
p_test($byDay['2026-10-02']['m'] === 2 && $byDay['2026-10-05']['m'] === 0 && $byDay['2026-10-06']['m'] === 0, 'raster: own, not deleted, not Mara');
p_test(array_column($stats['tools'], 'id') === ['web', 'skills', 'image', 'browse', 'files']
  && array_column($stats['tools'], 'count') === [4, 3, 2, 1, 1], 'tools by use: ' . json_encode($stats['tools']));
p_test($stats['made']['images'] === 2 && $stats['made']['files'] === 1 && count($stats['made']['latest']) === 2
  && $stats['made']['latest'][0] === ['type' => 'file', 'uuid' => '', 'name' => 'bericht.pdf', 'session' => 'cs_files', 'at' => '2026-11-09 07:00:00']
  && $stats['made']['latest'][1]['uuid'] === str_repeat('a', 32), 'made: newest first, unsafe image id dropped: ' . json_encode($stats['made']));
p_test($stats['insights'] === ['calls' => 10, 'fast_share' => 10, 'top_core' => ['label' => 'TCI Core Normal', 'share' => 90],
  'top_thinking' => ['label' => 'Hoch', 'share' => 50], 'skills_found' => 2, 'skills_used' => 3], 'insights: ' . json_encode($stats['insights']));
p_test(!str_contains((string)json_encode($stats), 'secret.txt') && !str_contains((string)json_encode($stats), '999999999'), 'nothing of another account');
$empty = coreui_profile_stats($pdo, 5, 'ember', '2026-11-09');
p_test($empty['tokens']['total'] === 0 && $empty['tools'] === [] && $empty['insights']['top_core'] === null
  && $empty['streak']['longest'] === 0 && $empty['made']['latest'] === [], 'a new account: zeros, no errors');

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) -------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  p_test(coreui_usage_ready($my), 'MariaDB: migration 029 applied');
  p_test(preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)$my->query("SELECT `value` FROM stu_app_settings WHERE `k` = 'usage_counting_since'")->fetchColumn()) === 1,
    'MariaDB: counting start stored');
  $name = 'profile-selftest-' . bin2hex(random_bytes(4)) . '@example.test';
  $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)")->execute([$name]);
  $tu = (int)$my->lastInsertId();
  try {
    p_test(coreui_usage_record($my, $tu, 'ember-core:e4b', 100, 20, 'medium') && coreui_usage_record($my, $tu, 'ember-core:e4b', 50, 5, 'medium'),
      'MariaDB: counted');
    $r = $my->query('SELECT calls, prompt_tokens, output_tokens, day = CURDATE() AS today FROM stu_usage_daily WHERE user_id = ' . $tu)->fetch(PDO::FETCH_ASSOC);
    p_test((int)$r['calls'] === 2 && (int)$r['prompt_tokens'] === 150 && (int)$r['output_tokens'] === 25 && (int)$r['today'] === 1, 'MariaDB: ON DUPLICATE KEY, CURDATE');
    $myStats = coreui_profile_stats($my, $tu, 'ember');
    p_test($myStats['tokens']['total'] === 175 && $myStats['insights']['top_thinking']['label'] === 'Mittel'
      && count($myStats['raster']) === 371 && $myStats['longest_task_seconds'] === 0, 'MariaDB: overview (TIMESTAMPDIFF, DATE)');
    $dbChecked = true;
  } finally {
    $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$tu]);
  }
  p_test((int)$my->query('SELECT COUNT(*) FROM stu_usage_daily WHERE user_id = ' . $tu)->fetchColumn() === 0, 'MariaDB: deleted with the account (CASCADE)');
}

// --- Wiring ---------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$chat = $read('api/chat.php');
p_test(str_contains($chat, "require_once __DIR__ . '/usage_store.php';")
  && str_contains($chat, "coreui_usage_record_call(\$model, (int)\$GLOBALS['STU_EMBER_LAST_CALL']['prompt_eval_count'],"), 'chat.php counts');
p_test(str_contains($read('api/console_stream.php'), "coreui_usage_record_call(\$model, \$promptEvalCount, \$evalCount, \$payload['think'] ?? false);"), 'stream counts');
$endpoint = $read('api/profile_stats.php');
p_test(str_contains($endpoint, "'guest_not_allowed'") && str_contains($endpoint, 'coreui_profile_stats($pdo, $uid, $assistantId)')
  && str_contains($endpoint, "header('Allow: GET');"), 'endpoint: own data, no guests, GET only');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $f) {
  p_test(str_contains($read($f), '|profile|profile_media|profile_stats|'), $f . ' allows profile_stats');
}
$migration = $read('database/migrations/029_usage_counter.sql');
p_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_usage_daily') && str_contains($migration, 'ON DELETE CASCADE')
  && str_contains($migration, "'usage_counting_since'") && str_contains($migration, "('029_usage_counter')"), 'migration 029');
p_test(str_contains($read('api/account_export.php'), "'usage_daily' =>"), 'account export includes the counted numbers');
p_test((bool)preg_match('~<script src="\./js/coreui-profile-stats\.js\?v=\d{25}"></script>~', $read('settings.html')), 'settings.html loads coreui-profile-stats.js');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $f) {
  $pre = $read($f);
  foreach (['database/migrations/029_usage_counter.sql', 'api/usage_store.php', 'api/profile_stats.php', 'js/coreui-profile-stats.js',
    'scripts/profile-stats-selftest.php', 'scripts/profile-stats-ui-selftest.cjs'] as $need) {
    p_test(str_contains($pre, $need), $f . ' lists ' . $need);
  }
}

echo "CoreAI profile stats: thinking levels, streaks, tokens per account/day/Core/level (only with an account), overview "
  . "(tokens and peak day, longest task, 53-week raster, tools, what Mara made, insights), own rows only, wiring OK"
  . ($dbChecked ? ', MariaDB statements OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
