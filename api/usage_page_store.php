<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.30: Settings > Nutzung after the reference screenshots.
 *
 * Only what CoreAI really counts, for the signed-in account only, numbers only (never message text):
 *   - Plan and limits: the plan of Settings > Konto, the daily message limit (a 24-hour window that
 *     starts with the first message, api/user_policy.php), the storage limit (api/files_store.php),
 *     the largest context window and blocked tools. CoreAI has no 5-hour or weekly limits.
 *   - Own messages per hour (today) and per day, by kind: text, Stimmsequenz (voice messages and live
 *     turns, audio named "Sprachnachricht-…"), with an attachment.
 *   - Tokens per day by Core and thinking level (stu_usage_daily, counted since migration 029).
 *   - Tools (web search, browser, Python, images) and skills per day from the work log.
 *   - The chats with the most own messages in a range.
 */

require_once __DIR__ . '/usage_store.php';
require_once __DIR__ . '/user_policy.php';
require_once __DIR__ . '/abuse_guard.php';
require_once __DIR__ . '/files_store.php';
require_once __DIR__ . '/account_page_store.php';
require_once __DIR__ . '/model_catalog.php';

const COREUI_USAGE_PAGE_RANGES = [7, 30];
const COREUI_USAGE_PAGE_BACK_MAX = 52; // how far "‹" goes back (52 ranges)
const COREUI_USAGE_PAGE_CHATS = 5;
const COREUI_USAGE_PAGE_TOOLS = [
  'web' => ['title' => 'Websuche gestartet', 'label' => 'Websuche'],
  'browse' => ['title' => 'Browserauftrag eingereiht', 'label' => 'Recherche im Browser'],
  'python' => ['title' => 'Python-Job eingereiht', 'label' => 'Python'],
  'image' => ['title' => 'Bild erzeugt', 'label' => 'Bilder'],
];
const COREUI_USAGE_PAGE_KINDS = ['text' => 'Text', 'voice' => 'Stimmsequenzen', 'attach' => 'Mit Anhang'];

/** The database's today ('Y-m-d'), the same clock the counters use. */
function coreui_usage_page_today(PDO $pdo): string {
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $today = (string)(coreui_usage_value($pdo, $sqlite ? "SELECT date('now', 'localtime')" : 'SELECT CURDATE()', []) ?? '');
  return preg_match('~^\d{4}-\d{2}-\d{2}$~', $today) === 1 ? $today : date('Y-m-d');
}

/** 7 or 30 days ending $back ranges before today (pure). */
function coreui_usage_page_range(string $today, int $days, int $back): array {
  $days = in_array($days, COREUI_USAGE_PAGE_RANGES, true) ? $days : 7;
  $back = max(0, min(COREUI_USAGE_PAGE_BACK_MAX, $back));
  $to = date('Y-m-d', strtotime($today . ' -' . ($back * $days) . ' days'));
  $from = date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));
  $list = [];
  for ($i = 0; $i < $days; $i++) $list[] = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
  return ['days' => $days, 'back' => $back, 'from' => $from, 'to' => $to, 'list' => $list, 'latest' => $back === 0];
}

/** Share in percent with one decimal (pure). */
function coreui_usage_page_share(int $part, int $whole): float {
  return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
}

/** SQL for the account's own console messages (not Mara's). */
function coreui_usage_page_own(string $alias = ''): string {
  $p = $alias !== '' ? $alias . '.' : '';
  return "{$p}channel = 'console' AND {$p}user_id = ? AND {$p}character_id <> ? AND {$p}deleted_at IS NULL";
}

/** CASE for the kind of an own message: voice, attach or text. */
function coreui_usage_page_kind_sql(PDO $pdo): string {
  $voiceMedia = "SELECT md.uuid FROM stu_console_media md WHERE md.user_id = m.user_id AND md.kind = 'audio' AND md.orig_name LIKE 'Sprachnachricht-%'";
  $attTable = coreui_usage_page_table_ready($pdo, 'stu_console_message_attachments');
  $voiceAtt = $attTable
    ? "EXISTS (SELECT 1 FROM stu_console_message_attachments a WHERE a.message_id = m.id AND a.media_uuid IN ($voiceMedia))" : '0 = 1';
  $anyAtt = $attTable ? 'EXISTS (SELECT 1 FROM stu_console_message_attachments a2 WHERE a2.message_id = m.id)' : '0 = 1';
  return "CASE WHEN m.file_uuid IN ($voiceMedia) OR $voiceAtt THEN 'voice' "
    . "WHEN (m.file_uuid IS NOT NULL AND m.file_uuid <> '') OR (m.image_url IS NOT NULL AND m.image_url <> '') OR $anyAtt THEN 'attach' "
    . "ELSE 'text' END";
}

/** Whether a table exists (cached per connection and name). */
function coreui_usage_page_table_ready(PDO $pdo, string $table): bool {
  static $known = [];
  $key = spl_object_id($pdo) . ':' . $table;
  if (array_key_exists($key, $known)) return $known[$key];
  if (preg_match('~^[a-z_]+$~', $table) !== 1) return $known[$key] = false;
  try {
    $pdo->query("SELECT 1 FROM $table LIMIT 0");
    return $known[$key] = true;
  } catch (Throwable $e) {
    return $known[$key] = false;
  }
}

/**
 * Übersicht: plan, the daily message limit with its window, storage, today by hour and the last 7 days.
 * $level/$guest as in stu_users; $now for tests.
 */
function coreui_usage_page_overview(PDO $pdo, int $uid, string $assistantId, int $level, bool $guest, array $runtime, array $ctxChoices, ?int $now = null): array {
  $now = $now ?? time();
  $today = coreui_usage_page_today($pdo);
  $plan = coreui_account_plan_summary($pdo, $uid, $level, $guest, $runtime, $ctxChoices);

  // The daily limit: a 24-hour window from the first message; read without counting.
  $limit = $plan['daily_messages'] ?? null;
  $daily = ['limit' => null, 'used' => 0, 'left' => null, 'reset_at' => null, 'started' => false];
  if ($limit !== null) {
    $window = coreui_abuse_count($pdo, coreui_user_policy_day_key($uid), COREUI_USER_POLICY_DAY, 0, $now);
    $used = min((int)$limit, (int)$window['n']);
    $daily = ['limit' => (int)$limit, 'used' => $used, 'left' => max(0, (int)$limit - $used),
      'reset_at' => (int)$window['n'] > 0 ? date('c', (int)$window['reset']) : null, 'started' => (int)$window['n'] > 0];
  }

  $usage = coreui_files_usage($pdo, $uid);
  $storageLimit = coreui_files_limit_bytes($pdo, $uid);

  // Own messages today by hour, and per day for the last 7 days.
  $own = coreui_usage_page_own();
  $hours = array_fill(0, 24, 0);
  foreach (coreui_usage_rows($pdo, "SELECT HOUR(created_at) AS h, COUNT(*) AS n FROM stu_chat_messages WHERE $own AND created_at >= ? GROUP BY HOUR(created_at)",
    [$uid, $assistantId, $today . ' 00:00:00']) as $row) {
    $h = (int)$row['h'];
    if ($h >= 0 && $h < 24) $hours[$h] = (int)$row['n'];
  }
  $week = coreui_usage_page_range($today, 7, 0);
  $perDay = array_fill_keys($week['list'], 0);
  foreach (coreui_usage_rows($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM stu_chat_messages WHERE $own AND created_at >= ? GROUP BY DATE(created_at)",
    [$uid, $assistantId, $week['from'] . ' 00:00:00']) as $row) {
    $d = substr((string)$row['d'], 0, 10);
    if (array_key_exists($d, $perDay)) $perDay[$d] = (int)$row['n'];
  }
  $tokensToday = 0;
  if (coreui_usage_ready($pdo)) {
    $tokensToday = (int)(coreui_usage_value($pdo, 'SELECT SUM(prompt_tokens + output_tokens) FROM stu_usage_daily WHERE user_id = ? AND day = ?', [$uid, $today]) ?? 0);
  }

  return [
    'today' => $today,
    'plan' => ['label' => (string)$plan['label'], 'role' => (string)($plan['role'] ?? ''), 'note' => (string)($plan['note'] ?? ''),
      'features' => array_values($plan['features'] ?? []), 'server_off' => array_values($plan['server_off'] ?? []),
      'max_num_ctx' => $plan['max_num_ctx'] ?? null],
    'daily' => $daily,
    'storage' => ['used' => $usage['used'], 'limit' => $storageLimit],
    'hours' => $hours,
    'week' => array_map(static fn(string $d): array => ['d' => $d, 'n' => $perDay[$d]], $week['list']),
    'messages_today' => array_sum($hours),
    'tokens_today' => $tokensToday,
  ];
}

/** Analysen for one range: tokens by Core and thinking, tools, skills and messages per day. */
function coreui_usage_page_analytics(PDO $pdo, int $uid, string $assistantId, int $days, int $back): array {
  $range = coreui_usage_page_range(coreui_usage_page_today($pdo), $days, $back);
  $empty = array_fill_keys($range['list'], 0);
  $start = $range['from'] . ' 00:00:00';
  $end = date('Y-m-d', strtotime($range['to'] . ' +1 day')) . ' 00:00:00';

  // Tokens (counted since migration 029).
  $ready = coreui_usage_ready($pdo);
  $since = (string)(coreui_usage_value($pdo, "SELECT `value` FROM stu_app_settings WHERE `k` = 'usage_counting_since' LIMIT 1", []) ?? '');
  $byCore = [];
  $byThinking = [];
  if ($ready) {
    foreach (coreui_usage_rows($pdo, 'SELECT day, model, thinking, SUM(prompt_tokens + output_tokens) AS t FROM stu_usage_daily '
      . 'WHERE user_id = ? AND day BETWEEN ? AND ? GROUP BY day, model, thinking', [$uid, $range['from'], $range['to']]) as $row) {
      $d = substr((string)$row['day'], 0, 10);
      if (!array_key_exists($d, $empty)) continue;
      $t = (int)$row['t'];
      $core = coreui_core_label((string)$row['model']);
      $think = coreui_usage_thinking_label((string)$row['thinking']);
      $byCore[$core] = $byCore[$core] ?? $empty;
      $byCore[$core][$d] += $t;
      $byThinking[$think] = $byThinking[$think] ?? $empty;
      $byThinking[$think][$d] += $t;
    }
  }
  $series = static function (array $groups): array {
    $out = [];
    foreach ($groups as $label => $perDay) $out[] = ['label' => (string)$label, 'total' => array_sum($perDay), 'days' => array_values($perDay)];
    usort($out, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    return $out;
  };

  // Tools and skills from the work log.
  $tools = [];
  foreach (COREUI_USAGE_PAGE_TOOLS as $id => $tool) $tools[$id] = $empty;
  $titles = array_map(static fn(array $t): string => $t['title'], COREUI_USAGE_PAGE_TOOLS); // 'web' => 'Websuche gestartet', ...
  $byTitle = array_flip($titles);
  $titles = array_values($titles); // positional parameters only
  $marks = implode(',', array_fill(0, count($titles), '?'));
  foreach (coreui_usage_rows($pdo, "SELECT DATE(created_at) AS d, title, COUNT(*) AS n FROM stu_console_work_events WHERE user_id = ? "
    . "AND created_at >= ? AND created_at < ? AND title IN ($marks) GROUP BY DATE(created_at), title", array_merge([$uid, $start, $end], $titles)) as $row) {
    $d = substr((string)$row['d'], 0, 10);
    $id = $byTitle[(string)$row['title']] ?? null;
    if ($id !== null && array_key_exists($d, $empty)) $tools[$id][$d] += (int)$row['n'];
  }
  $skills = $empty;
  $skillNames = [];
  foreach (coreui_usage_rows($pdo, "SELECT DATE(created_at) AS d, title, COUNT(DISTINCT turn_id) AS n FROM stu_console_work_events WHERE user_id = ? "
    . "AND kind = 'tool' AND title LIKE 'Skill geladen: %' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at), title", [$uid, $start, $end]) as $row) {
    $d = substr((string)$row['d'], 0, 10);
    if (!array_key_exists($d, $empty)) continue;
    $skills[$d] += (int)$row['n'];
    $name = trim(substr((string)$row['title'], strlen('Skill geladen: ')));
    if ($name !== '') $skillNames[$name] = ($skillNames[$name] ?? 0) + (int)$row['n'];
  }
  arsort($skillNames);

  // Own messages per day by kind.
  $kinds = [];
  foreach (COREUI_USAGE_PAGE_KINDS as $id => $label) $kinds[$id] = $empty;
  $own = coreui_usage_page_own('m');
  $kindSql = coreui_usage_page_kind_sql($pdo);
  foreach (coreui_usage_rows($pdo, "SELECT DATE(m.created_at) AS d, $kindSql AS k, COUNT(*) AS n FROM stu_chat_messages m WHERE $own "
    . 'AND m.created_at >= ? AND m.created_at < ? GROUP BY DATE(m.created_at), k', [$uid, $assistantId, $start, $end]) as $row) {
    $d = substr((string)$row['d'], 0, 10);
    $k = (string)$row['k'];
    if (isset($kinds[$k]) && array_key_exists($d, $empty)) $kinds[$k][$d] += (int)$row['n'];
  }

  $toolSeries = [];
  foreach (COREUI_USAGE_PAGE_TOOLS as $id => $tool) {
    $toolSeries[] = ['id' => $id, 'label' => $tool['label'], 'total' => array_sum($tools[$id]), 'days' => array_values($tools[$id])];
  }
  $kindSeries = [];
  foreach (COREUI_USAGE_PAGE_KINDS as $id => $label) {
    $kindSeries[] = ['id' => $id, 'label' => $label, 'total' => array_sum($kinds[$id]), 'days' => array_values($kinds[$id])];
  }
  $messagesPerDay = $empty;
  foreach ($kinds as $perDay) foreach ($perDay as $d => $n) $messagesPerDay[$d] += $n;

  return [
    'range' => ['days' => $range['days'], 'back' => $range['back'], 'from' => $range['from'], 'to' => $range['to'],
      'list' => $range['list'], 'latest' => $range['latest'], 'oldest' => $range['back'] >= COREUI_USAGE_PAGE_BACK_MAX],
    'tokens' => ['ready' => $ready, 'since' => $since, 'by_core' => $series($byCore), 'by_thinking' => $series($byThinking),
      'total' => array_sum(array_map(static fn(array $s): int => $s['total'], $series($byCore)))],
    'tools' => ['series' => $toolSeries, 'total' => array_sum(array_map(static fn(array $s): int => $s['total'], $toolSeries))],
    'skills' => ['days' => array_values($skills), 'total' => array_sum($skills),
      'names' => array_map(static fn(string $n, int $c): array => ['name' => $n, 'n' => $c], array_keys(array_slice($skillNames, 0, 5, true)),
        array_values(array_slice($skillNames, 0, 5, true)))],
    'messages' => ['series' => $kindSeries, 'total' => array_sum($messagesPerDay), 'days' => array_values($messagesPerDay)],
  ];
}

/** The chats with the most own messages in a range (title hidden for locked chats). */
function coreui_usage_page_chats(PDO $pdo, int $uid, string $assistantId, int $days, int $back, int $offset): array {
  $range = coreui_usage_page_range(coreui_usage_page_today($pdo), $days, $back);
  $start = $range['from'] . ' 00:00:00';
  $end = date('Y-m-d', strtotime($range['to'] . ' +1 day')) . ' 00:00:00';
  $offset = max(0, min(1000, $offset));
  $own = coreui_usage_page_own('m');
  $total = (int)(coreui_usage_value($pdo, "SELECT COUNT(*) FROM stu_chat_messages m WHERE $own AND m.created_at >= ? AND m.created_at < ?",
    [$uid, $assistantId, $start, $end]) ?? 0);
  $locked = stu_schema_has_column($pdo, 'stu_console_sessions', 'locked_at') ? 's.locked_at' : 'NULL';
  $rows = coreui_usage_rows($pdo, "SELECT s.id, s.title, s.archived_at, $locked AS locked_at, COUNT(*) AS n, MAX(m.created_at) AS last "
    . "FROM stu_chat_messages m JOIN stu_console_sessions s ON s.id = m.session_id AND s.user_id = m.user_id WHERE $own "
    . 'AND m.created_at >= ? AND m.created_at < ? GROUP BY s.id, s.title, s.archived_at, locked_at ORDER BY n DESC, last DESC '
    . 'LIMIT ' . (COREUI_USAGE_PAGE_CHATS + 1) . ' OFFSET ' . $offset, [$uid, $assistantId, $start, $end]);
  $more = count($rows) > COREUI_USAGE_PAGE_CHATS;
  $rows = array_slice($rows, 0, COREUI_USAGE_PAGE_CHATS);
  $toolsBySession = [];
  if ($rows !== []) {
    $ids = array_map(static fn(array $r): string => (string)$r['id'], $rows);
    $titles = array_values(array_map(static fn(array $t): string => $t['title'], COREUI_USAGE_PAGE_TOOLS));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $tmarks = implode(',', array_fill(0, count($titles), '?'));
    foreach (coreui_usage_rows($pdo, "SELECT session_id, COUNT(*) AS n FROM stu_console_work_events WHERE user_id = ? AND session_id IN ($marks) "
      . "AND created_at >= ? AND created_at < ? AND title IN ($tmarks) GROUP BY session_id", array_merge([$uid], $ids, [$start, $end], $titles)) as $row) {
      $toolsBySession[(string)$row['session_id']] = (int)$row['n'];
    }
  }
  $items = [];
  foreach ($rows as $row) {
    $isLocked = !empty($row['locked_at']);
    $id = (string)$row['id'];
    $items[] = [
      'id' => $isLocked ? '' : $id,
      'title' => $isLocked ? 'Gesperrter Chat' : (trim((string)$row['title']) !== '' ? (string)$row['title'] : 'Neuer Chat'),
      'locked' => $isLocked,
      'archived' => !empty($row['archived_at']),
      'messages' => (int)$row['n'],
      'share' => coreui_usage_page_share((int)$row['n'], $total),
      'tools' => $toolsBySession[$id] ?? 0,
      'last' => (string)$row['last'],
    ];
  }
  return ['items' => $items, 'has_more' => $more, 'offset' => $offset, 'total_messages' => $total];
}
