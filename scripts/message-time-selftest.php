<?php
declare(strict_types=1);
// CoreAI 0.6.24: times in the chat carry their zone.
//   php scripts/message-time-selftest.php
// Reported by the operator (06.11.2026): the answer of the browser research showed
// 13:33 under a question from 14:29. The database stores NOW() in its own zone (UTC in
// the container) and the history sent "2026-11-06 13:33:10", which the browser read as
// local time. Now message and chat-list times go out as ISO 8601 in UTC; the browser
// shows them in the viewer's time. Offline: the conversion and the wiring. With a
// database (api/config.local.php or COREUI_SELFTEST_DB=1 with COREUI_DB_* set): a
// session and a message written with NOW() come back as the right instant, with the
// database session in UTC and in +01:00 (a native install), rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_session_store.php';

function time_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Message time FAIL: {$why}\n"); exit(1); }
}

time_test(coreui_db_time_iso(1793971990) === '2026-11-06T13:33:10+00:00', 'a timestamp becomes ISO 8601 in UTC');
time_test(coreui_db_time_iso('1793971990.000000') === '2026-11-06T13:33:10+00:00', 'as MariaDB sends it (with decimals)');
time_test(coreui_db_time_iso(null, '2026-11-06 13:33:10') === '2026-11-06 13:33:10' && coreui_db_time_iso(0, null) === null,
  'without a timestamp the value stays');

$messages = (string)file_get_contents($root . '/api/console_messages.php');
time_test(str_contains($messages, 'UNIX_TIMESTAMP(m.created_at) AS created_ts')
  && str_contains($messages, "\$row['created_at'] = coreui_db_time_iso(\$row['created_ts'] ?? null, \$row['created_at'] ?? null);")
  && str_contains($messages, "unset(\$row['created_ts']);"), 'the history sends message times with their zone');
$store = (string)file_get_contents($root . '/api/console_session_store.php');
time_test(str_contains($store, 'UNIX_TIMESTAMP(s.updated_at) AS updated_ts'), 'the chat list too');
// The browser reads such a time as an instant: the answer at 13:33 UTC shows as 14:33 in Berlin.
$app = (string)file_get_contents($root . '/js/console-app.js');
time_test(str_contains($app, 'var now = createdAt ? new Date(createdAt) : new Date();'), 'the chat shows the time the browser parsed');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI message time: ISO 8601 in UTC for messages and chats, wiring OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
foreach (['+00:00', '+01:00'] as $zone) {
  $pdo->exec("SET time_zone = '" . $zone . "'");
  $pdo->beginTransaction();
  try {
    $uid = random_int(1500000000, 1900000000);
    $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 4, NOW())')
      ->execute([$uid, 'time-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
    $session = coreui_console_session_create($pdo, $uid, 'Zeittest');
    $pdo->prepare("INSERT INTO stu_chat_messages (channel, alliance_id, session_id, user_id, character_id, character_name, message, created_at)
      VALUES ('console', NULL, ?, ?, 'test', 'Test', 'Hallo', NOW())")->execute([(string)$session['id'], $uid]);
    $id = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT created_at, UNIX_TIMESTAMP(created_at) AS created_ts FROM stu_chat_messages WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $iso = coreui_db_time_iso($row['created_ts'] ?? null, $row['created_at'] ?? null);
    $seconds = strtotime((string)$iso);
    time_test(is_string($iso) && str_ends_with($iso, '+00:00') && abs($seconds - time()) < 120,
      "database session {$zone}: the message time is the real instant ({$iso}, stored as {$row['created_at']})");
    $list = coreui_console_session_list($pdo, $uid);
    $updated = (string)($list[0]['updated_at'] ?? '');
    time_test(str_ends_with($updated, '+00:00') && abs(strtotime($updated) - time()) < 120 && !array_key_exists('updated_ts', $list[0]),
      "database session {$zone}: the chat list time too ({$updated})");
  } finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}
$pdo->exec("SET time_zone = SYSTEM");
echo "CoreAI message time: ISO 8601 in UTC for messages and chats, right instant with the database in UTC and +01:00 (rolled back) OK\n";
