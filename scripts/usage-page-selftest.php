<?php
declare(strict_types=1);
// CoreAI 0.7.30: Settings > Nutzung on the PHP side. Checked: the ranges (7 and 30 days, going back),
// shares, the wiring (endpoint for signed-in accounts only, nginx, the page, no message text read) and,
// with a database: the plan and the daily limit window (read without counting), storage, today by hour,
// the last 7 days, tokens by Core and thinking level, tools and skills per day, own messages by kind
// (text, Stimmsequenz, with attachment; never Mara's or another account's), the top chats (locked chats
// without their title) and the Inhaber without limits. The database part runs in a transaction and is
// rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/usage_page_store.php';

function up_test(bool $ok, string $why): void {
  if ($ok) return;
  // exit() skips the finally block below: roll the test data back here.
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && $GLOBALS['pdo']->inTransaction()) $GLOBALS['pdo']->rollBack();
  fwrite(STDERR, "Usage FAIL: {$why}\n");
  exit(1);
}
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);

// --- Pure parts ---------------------------------------------------------------------------------
$r = coreui_usage_page_range('2026-11-12', 7, 0);
up_test($r['from'] === '2026-11-06' && $r['to'] === '2026-11-12' && count($r['list']) === 7 && $r['list'][6] === '2026-11-12' && $r['latest'], '7 days up to today');
$r = coreui_usage_page_range('2026-11-12', 7, 1);
up_test($r['from'] === '2026-10-30' && $r['to'] === '2026-11-05' && !$r['latest'], 'one range back');
$r = coreui_usage_page_range('2026-11-12', 30, 0);
up_test($r['from'] === '2026-10-14' && count($r['list']) === 30, '30 days');
up_test(coreui_usage_page_range('2026-11-12', 12, 0)['days'] === 7 && coreui_usage_page_range('2026-11-12', 7, -3)['back'] === 0
  && coreui_usage_page_range('2026-11-12', 7, 999)['back'] === COREUI_USAGE_PAGE_BACK_MAX, 'only 7 or 30 days, bounded going back');
up_test(coreui_usage_page_share(1, 3) === 33.3 && coreui_usage_page_share(5, 0) === 0.0, 'shares');

// --- Wiring -------------------------------------------------------------------------------------
$api = $read('api/usage.php');
up_test(strpos($api, "stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, "\$action === 'overview'")
  && str_contains($api, 'stu_require_user_id()') && str_contains($api, "header('Allow: GET');"), 'signed-in accounts only, GET only');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $conf) {
  up_test(str_contains($read($conf), '|files|usage|profile|'), $conf . ' lets api/usage.php through');
}
$store = $read('api/usage_page_store.php');
up_test(!preg_match('~SELECT[^;]*\bm?\.?message\b[^_]~', str_replace(['stu_chat_messages', 'message_id'], '', $store)), 'no message text is read');
up_test(str_contains($store, 'coreui_abuse_count($pdo, coreui_user_policy_day_key($uid), COREUI_USER_POLICY_DAY, 0, $now)'),
  'the daily limit is read without counting a message');
$settings = $read('settings.html');
up_test(!str_contains($settings, 'class="cfg-view cfg-view-planned" id="view-nutzung"') && str_contains($settings, 'js/coreui-usage-settings.js')
  && !str_contains($settings, 'id="tab-nutzung" data-view="nutzung" aria-controls="view-nutzung" aria-selected="false" tabindex="-1" title="In Vorbereitung"'),
  'Nutzung is a real page now');

// --- With a database ------------------------------------------------------------------------------
$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI usage page: ranges, shares and wiring OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
require_once $root . '/api/ai_settings.php';
$pdo = stu_pdo();
up_test(coreui_usage_ready($pdo), 'migration 029 applied');
$assistant = 'ember';
// stu_app_setting_get creates its table once per process (CREATE TABLE IF NOT EXISTS), which would end the
// transaction in MariaDB; do it before.
stu_app_setting_get($pdo, 'usage_counting_since', '');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $other = $uid + 1;
  $owner = $uid + 2;
  $ins = $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, ?, NOW())');
  foreach ([[$uid, 3], [$other, 3], [$owner, 0]] as [$id, $level]) $ins->execute([$id, 'usage-' . bin2hex(random_bytes(4)) . '@coreui.invalid', $level]);
  $pdo->prepare('INSERT INTO stu_user_policies (user_id, tool_web, tool_browse, tool_python, daily_messages, max_num_ctx, updated_by) VALUES (?, 1, 1, 0, 10, 16384, ?)')
    ->execute([$uid, $owner]);
  $today = coreui_usage_page_today($pdo);
  $old = date('Y-m-d', strtotime($today . ' -10 days'));
  $threeAgo = date('Y-m-d', strtotime($today . ' -3 days'));

  $session = static function (int $user, string $title, bool $locked = false) use ($pdo): string {
    $id = 'cs_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, title) VALUES (?, ?, ?)')->execute([$id, $user, $title]);
    if ($locked) $pdo->prepare('UPDATE stu_console_sessions SET locked_at = NOW() WHERE id = ?')->execute([$id]);
    return $id;
  };
  $message = static function (int $user, string $sid, string $when, bool $mara = false, ?string $file = null) use ($pdo, $assistant): int {
    $pdo->prepare('INSERT INTO stu_chat_messages (channel, session_id, user_id, character_id, character_name, message, file_uuid, created_at) '
      . "VALUES ('console', ?, ?, ?, ?, 'geheim', ?, ?)")
      ->execute([$sid, $user, $mara ? $assistant : 'user', $mara ? 'Mara' : 'Lena', $file, $when]);
    return (int)$pdo->lastInsertId();
  };
  $media = static function (int $user, string $kind, string $name) use ($pdo): string {
    $uuid = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, rel_path, mime_type, file_size) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$uuid, $user, $kind, $name, $uuid, 'var/console_media/' . $uuid, $kind === 'audio' ? 'audio/wav' : 'image/png', 10]);
    return $uuid;
  };
  $attach = static function (int $messageId, string $uuid, int $user) use ($pdo): void {
    $pdo->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position) VALUES (?, ?, ?, 0)')->execute([$messageId, $uuid, $user]);
  };
  $event = static function (int $user, string $sid, int $turn, string $kind, string $title, string $when) use ($pdo): void {
    $pdo->prepare('INSERT INTO stu_console_work_events (user_id, session_id, turn_id, kind, state, title, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$user, $sid, $turn, $kind, 'done', $title, $when]);
  };

  $plan = $session($uid, 'Planung');
  $trip = $session($uid, 'Reise');
  $secret = $session($uid, 'Geheimprojekt', stu_schema_has_column($pdo, 'stu_console_sessions', 'locked_at'));
  $foreign = $session($other, 'Fremd');
  $now = $today . ' 09:15:00';
  // Today: 3 text messages in "Planung", one Stimmsequenz (attachment) and one live turn (file_uuid), one photo.
  $t1 = $message($uid, $plan, $now);
  $message($uid, $plan, $today . ' 09:40:00');
  $message($uid, $plan, $today . ' 11:05:00');
  $voice = $message($uid, $trip, $today . ' 11:20:00');
  $attach($voice, $media($uid, 'audio', 'Sprachnachricht-2026-11-12.wav'), $uid);
  $message($uid, $trip, $today . ' 11:30:00', false, $media($uid, 'audio', 'Sprachnachricht-live.wav'));
  $photo = $message($uid, $trip, $today . ' 11:45:00');
  $attach($photo, $media($uid, 'image', 'Foto.png'), $uid);
  // Three days ago one in the locked chat; ten days ago one in "Planung" (only in 30 days).
  $message($uid, $secret, $threeAgo . ' 20:00:00');
  $message($uid, $plan, $old . ' 08:00:00');
  // Not counted: Mara's answers, another account, a deleted message.
  $message($uid, $plan, $now, true);
  $message($other, $foreign, $now);
  $gone = $message($uid, $plan, $now);
  $pdo->prepare('UPDATE stu_chat_messages SET deleted_at = NOW() WHERE id = ?')->execute([$gone]);
  // Tools and skills: one web search and one Python job today, a skill loaded twice in one turn.
  $event($uid, $plan, $t1, 'tool', 'Websuche gestartet', $now);
  $event($uid, $plan, $t1, 'tool', 'Python-Job eingereiht', $now);
  $event($uid, $plan, $t1, 'tool', 'Skill geladen: tabellen', $now);
  $event($uid, $plan, $t1, 'tool', 'Skill geladen: tabellen', $now);
  $event($other, $foreign, $t1, 'tool', 'Websuche gestartet', $now);
  // Tokens by Core and thinking.
  $usage = $pdo->prepare('INSERT INTO stu_usage_daily (user_id, day, model, thinking, calls, prompt_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
  $usage->execute([$uid, $today, 'ember-core:e4b', 'off', 3, 1000, 500]);
  $usage->execute([$uid, $today, 'ember-core:e2b', 'on', 1, 300, 200]);
  $usage->execute([$uid, $threeAgo, 'ember-core:e4b', 'on', 2, 2000, 1000]);
  $usage->execute([$other, $today, 'ember-core:e4b', 'off', 1, 9999, 9999]);
  // Three messages in the current 24-hour window.
  $start = strtotime($today . ' 09:15:00');
  coreui_abuse_count($pdo, coreui_user_policy_day_key($uid), COREUI_USER_POLICY_DAY, 3, $start);

  // Übersicht.
  $o = coreui_usage_page_overview($pdo, $uid, $assistant, 3, false, coreui_operator_runtime_load($pdo), coreui_ai_num_ctx_choices(), $start + 3600);
  up_test($o['daily'] === ['limit' => 10, 'used' => 3, 'left' => 7, 'reset_at' => date('c', $start + 86400), 'started' => true], 'the daily limit window');
  up_test((int)coreui_abuse_count($pdo, coreui_user_policy_day_key($uid), COREUI_USER_POLICY_DAY, 0, $start + 3600)['n'] === 3, 'reading counts nothing');
  up_test($o['plan']['max_num_ctx'] === 16384 && in_array('Bis zu 10 Nachrichten pro Tag', $o['plan']['features'], true), 'the plan with its limits');
  up_test($o['messages_today'] === 6 && $o['hours'][9] === 2 && $o['hours'][11] === 4 && array_sum($o['hours']) === 6, 'today by hour');
  up_test(count($o['week']) === 7 && $o['week'][6] === ['d' => $today, 'n' => 6] && $o['week'][3]['n'] === 1, 'the last 7 days');
  up_test($o['tokens_today'] === 2000 && $o['storage']['used'] === 30, 'tokens today and storage (the three test files of 10 bytes)');

  // Analysen, 7 days.
  $a = coreui_usage_page_analytics($pdo, $uid, $assistant, 7, 0);
  $core = array_column($a['tokens']['by_core'], 'total', 'label');
  up_test($a['tokens']['total'] === 5000 && array_sum($core) === 5000 && count($core) === 2, 'tokens by Core');
  up_test($a['tokens']['by_core'][0]['total'] === 4500 && $a['tokens']['by_core'][0]['days'][6] === 1500 && $a['tokens']['by_core'][0]['days'][3] === 3000,
    'tokens per day, biggest Core first');
  $think = array_column($a['tokens']['by_thinking'], 'total', 'label');
  up_test(array_sum($think) === 5000 && count($think) === 2, 'tokens by thinking level');
  $tools = array_column($a['tools']['series'], 'total', 'id');
  up_test($tools === ['web' => 1, 'browse' => 0, 'python' => 1, 'image' => 0] && $a['tools']['total'] === 2, 'tools of this account only');
  up_test($a['skills']['total'] === 1 && $a['skills']['names'] === [['name' => 'tabellen', 'n' => 1]], 'a skill counts once per turn');
  $kinds = array_column($a['messages']['series'], 'total', 'id');
  up_test($kinds === ['text' => 4, 'voice' => 2, 'attach' => 1] && $a['messages']['total'] === 7 && $a['messages']['days'][6] === 6,
    'own messages by kind (live turns and voice messages are Stimmsequenzen)');
  $a30 = coreui_usage_page_analytics($pdo, $uid, $assistant, 30, 0);
  up_test($a30['messages']['total'] === 8 && count($a30['range']['list']) === 30, '30 days reach further back');
  $back = coreui_usage_page_analytics($pdo, $uid, $assistant, 7, 1);
  up_test($back['messages']['total'] === 1 && $back['tokens']['total'] === 0 && !$back['range']['latest'], 'one range back');

  // Top chats.
  $c = coreui_usage_page_chats($pdo, $uid, $assistant, 7, 0, 0);
  up_test(array_column($c['items'], 'title') === ['Planung', 'Reise', 'Gesperrter Chat'] || array_column($c['items'], 'title') === ['Reise', 'Planung', 'Gesperrter Chat'],
    'chats by own messages, the locked one without its title');
  $byTitle = array_column($c['items'], null, 'title');
  up_test($byTitle['Planung']['messages'] === 3 && $byTitle['Reise']['messages'] === 3 && $byTitle['Planung']['tools'] === 2 && $byTitle['Reise']['tools'] === 0
    && $byTitle['Planung']['share'] === 42.9, 'messages, share and tools per chat');
  if (stu_schema_has_column($pdo, 'stu_console_sessions', 'locked_at')) {
    up_test($byTitle['Gesperrter Chat']['id'] === '' && $byTitle['Gesperrter Chat']['locked'], 'a locked chat gives no link');
  }
  up_test(!$c['has_more'] && $c['total_messages'] === 7 && !in_array($foreign, array_column($c['items'], 'id'), true), 'no other account');

  // The Inhaber has no limits.
  $oo = coreui_usage_page_overview($pdo, $owner, $assistant, 0, false, coreui_operator_runtime_load($pdo), coreui_ai_num_ctx_choices());
  up_test($oo['plan']['label'] === 'Inhaber' && $oo['daily']['limit'] === null && $oo['storage']['limit'] === null, 'the Inhaber has no limits');
  up_test($pdo->inTransaction(), 'still inside the transaction');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "CoreAI usage page: daily limit window, plan, storage, today, 7 days, tokens by Core and thinking, tools, skills, messages by kind, top chats"
  . " and wiring on the database (rolled back) OK\n";
