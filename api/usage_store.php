<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.84: Settings > Profil, modeled on the ChatGPT profile page.
 *
 * Counted from this version on (migration 029, stu_usage_daily): tokens per account, day, Core
 * and thinking level, written after every Ollama call of a chat turn (ember_call_ollama and
 * console_stream_ollama). Only numbers, never text.
 *
 * Taken from the existing history (real, also before this version):
 *   - active days and streaks: days with at least one own chat message
 *   - longest task: the longest completed answer run (stu_console_agent_runs)
 *   - the activity raster before counting started: own messages per day
 *   - tools: web searches, research in the browser, Python jobs, images, files, skills
 *     (stu_console_work_events, stu_console_artifacts)
 *   - files and images Mara made
 * Everything is the signed-in account's own data only.
 */

require_once __DIR__ . '/model_catalog.php';

const COREUI_USAGE_THINKING = ['off', 'on', 'low', 'medium', 'high', 'max'];
const COREUI_USAGE_RASTER_DAYS = 371; // 53 weeks, like the reference

/** Whether migration 029 is applied (cached per connection). */
function coreui_usage_ready(PDO $pdo): bool {
  static $ready = [];
  $key = spl_object_id($pdo);
  if (array_key_exists($key, $ready)) return $ready[$key];
  try {
    $pdo->query('SELECT user_id, day, model, thinking, calls, prompt_tokens, output_tokens FROM stu_usage_daily LIMIT 0');
    return $ready[$key] = true;
  } catch (Throwable $e) {
    return $ready[$key] = false;
  }
}

/** Ollama's "think" value of a call as one stored word (pure). */
function coreui_usage_thinking_key(mixed $think): string {
  if ($think === false || $think === null || $think === '') return 'off';
  if ($think === true) return 'on';
  $value = strtolower(trim((string)$think));
  return in_array($value, COREUI_USAGE_THINKING, true) ? $value : 'on';
}

/** Adds one Core call of an account to today's row. */
function coreui_usage_record(PDO $pdo, int $uid, string $model, int $promptTokens, int $outputTokens, string $thinking): bool {
  $model = trim($model);
  if ($uid <= 0 || $model === '' || strlen($model) > 120 || ($promptTokens <= 0 && $outputTokens <= 0)) return false;
  if (!in_array($thinking, COREUI_USAGE_THINKING, true)) $thinking = 'on';
  if (!coreui_usage_ready($pdo)) return false;
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $sql = $sqlite
    ? "INSERT INTO stu_usage_daily (user_id, day, model, thinking, calls, prompt_tokens, output_tokens) VALUES (?, date('now', 'localtime'), ?, ?, 1, ?, ?) "
      . 'ON CONFLICT(user_id, day, model, thinking) DO UPDATE SET calls = calls + 1, '
      . 'prompt_tokens = prompt_tokens + excluded.prompt_tokens, output_tokens = output_tokens + excluded.output_tokens'
    : 'INSERT INTO stu_usage_daily (user_id, day, model, thinking, calls, prompt_tokens, output_tokens) VALUES (?, CURDATE(), ?, ?, 1, ?, ?) '
      . 'ON DUPLICATE KEY UPDATE calls = calls + 1, prompt_tokens = prompt_tokens + VALUES(prompt_tokens), '
      . 'output_tokens = output_tokens + VALUES(output_tokens)';
  try {
    $pdo->prepare($sql)->execute([$uid, $model, $thinking, max(0, $promptTokens), max(0, $outputTokens)]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Called after every Ollama call of a chat turn. The account comes from the turn's work
 * context (console_stream.php) or COREUI_USAGE_UID; calls outside an account are not counted.
 * Never throws: counting must not break an answer.
 */
function coreui_usage_record_call(string $model, int $promptTokens, int $outputTokens, mixed $think): void {
  try {
    $ctx = $GLOBALS['COREUI_WORK_CONTEXT'] ?? null;
    $uid = is_array($ctx) ? (int)($ctx['user_id'] ?? 0) : (int)($GLOBALS['COREUI_USAGE_UID'] ?? 0);
    if ($uid <= 0) return;
    $pdo = $GLOBALS['COREUI_WORK_PDO'] ?? null;
    if (!$pdo instanceof PDO) $pdo = function_exists('stu_pdo') ? stu_pdo() : null;
    if (!$pdo instanceof PDO) return;
    coreui_usage_record($pdo, $uid, $model, $promptTokens, $outputTokens, coreui_usage_thinking_key($think));
  } catch (Throwable $e) {
    // Counting is best effort.
  }
}

/** Longest and current streak from a list of active days 'Y-m-d' (pure). */
function coreui_usage_streaks(array $days, string $today): array {
  $set = [];
  foreach ($days as $d) if (is_string($d) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) $set[$d] = true;
  $list = array_keys($set);
  sort($list);
  $longest = 0;
  $run = 0;
  $prev = null;
  foreach ($list as $d) {
    $run = ($prev !== null && date('Y-m-d', strtotime($prev . ' +1 day')) === $d) ? $run + 1 : 1;
    $longest = max($longest, $run);
    $prev = $d;
  }
  // The current streak still counts when today has no message yet (it ended yesterday).
  $current = 0;
  $cursor = isset($set[$today]) ? $today : date('Y-m-d', strtotime($today . ' -1 day'));
  while (isset($set[$cursor])) {
    $current++;
    $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
  }
  return ['longest' => $longest, 'current' => $current, 'active_days' => count($list)];
}

/** The visible name of a thinking level (pure). */
function coreui_usage_thinking_label(string $key): string {
  return ['off' => 'Ohne Denken', 'on' => 'Standard', 'low' => 'Niedrig', 'medium' => 'Mittel', 'high' => 'Hoch', 'max' => 'Maximal'][$key] ?? 'Standard';
}

function coreui_usage_rows(PDO $pdo, string $sql, array $params): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function coreui_usage_value(PDO $pdo, string $sql, array $params): mixed {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Everything the profile shows for one account. $assistantId is Mara's character id (her
 * messages are not the account's own); $today 'Y-m-d' from the database clock.
 */
function coreui_profile_stats(PDO $pdo, int $uid, string $assistantId, ?string $today = null): array {
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $today = $today ?? (string)(coreui_usage_value($pdo, $sqlite ? "SELECT date('now', 'localtime')" : 'SELECT CURDATE()', []) ?? date('Y-m-d'));
  $from = date('Y-m-d', strtotime($today . ' -' . (COREUI_USAGE_RASTER_DAYS - 1) . ' days'));

  // Own messages per day (the whole history for streaks, the raster for the last 53 weeks).
  $own = "channel = 'console' AND user_id = ? AND character_id <> ? AND deleted_at IS NULL";
  $perDay = [];
  foreach (coreui_usage_rows($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM stu_chat_messages WHERE $own GROUP BY DATE(created_at)",
    [$uid, $assistantId]) as $row) {
    $perDay[(string)$row['d']] = (int)$row['n'];
  }
  $streaks = coreui_usage_streaks(array_keys($perDay), $today);

  // Tokens, counted since migration 029.
  $ready = coreui_usage_ready($pdo);
  $since = (string)(coreui_usage_value($pdo, "SELECT `value` FROM stu_app_settings WHERE `k` = 'usage_counting_since' LIMIT 1", []) ?? '');
  $tokensPerDay = [];
  $byModel = [];
  $byThinking = [];
  if ($ready) {
    foreach (coreui_usage_rows($pdo, 'SELECT day, model, thinking, calls, prompt_tokens, output_tokens FROM stu_usage_daily WHERE user_id = ?', [$uid]) as $row) {
      $day = substr((string)$row['day'], 0, 10);
      $tokens = (int)$row['prompt_tokens'] + (int)$row['output_tokens'];
      $tokensPerDay[$day] = ($tokensPerDay[$day] ?? 0) + $tokens;
      $byModel[(string)$row['model']] = ($byModel[(string)$row['model']] ?? 0) + (int)$row['calls'];
      $byThinking[(string)$row['thinking']] = ($byThinking[(string)$row['thinking']] ?? 0) + (int)$row['calls'];
    }
  }
  $peakDay = '';
  $peak = 0;
  foreach ($tokensPerDay as $day => $tokens) {
    if ($tokens > $peak) { $peak = $tokens; $peakDay = $day; }
  }
  $calls = array_sum($byModel);

  // Longest completed task (an answer run from start to finish).
  $durationSql = $sqlite
    ? "MAX(CAST(ROUND((julianday(finished_at) - julianday(COALESCE(started_at, created_at))) * 86400) AS INTEGER))"
    : 'MAX(TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), finished_at))';
  $longestTask = (int)(coreui_usage_value($pdo, "SELECT $durationSql FROM stu_console_agent_runs WHERE user_id = ? AND status = 'completed' AND finished_at IS NOT NULL",
    [$uid]) ?? 0);

  // Tools from the work log, files from the artifacts.
  $events = [];
  foreach (coreui_usage_rows($pdo, "SELECT title, COUNT(*) AS n FROM stu_console_work_events WHERE user_id = ? AND title IN "
    . "('Websuche gestartet', 'Browserauftrag eingereiht', 'Python-Job eingereiht', 'Bild erzeugt') GROUP BY title", [$uid]) as $row) {
    $events[(string)$row['title']] = (int)$row['n'];
  }
  $skillLoads = (int)(coreui_usage_value($pdo, "SELECT COUNT(*) FROM (SELECT DISTINCT turn_id, title FROM stu_console_work_events "
    . "WHERE user_id = ? AND kind = 'tool' AND title LIKE 'Skill geladen: %') s", [$uid]) ?? 0);
  $skillsFound = (int)(coreui_usage_value($pdo, "SELECT COUNT(DISTINCT title) FROM stu_console_work_events "
    . "WHERE user_id = ? AND kind = 'tool' AND title LIKE 'Skill geladen: %'", [$uid]) ?? 0);
  $files = (int)(coreui_usage_value($pdo, 'SELECT COUNT(*) FROM stu_console_artifacts WHERE user_id = ?', [$uid]) ?? 0);
  $tools = [
    ['id' => 'web', 'label' => 'Websuche', 'count' => $events['Websuche gestartet'] ?? 0],
    ['id' => 'browse', 'label' => 'Recherche im Browser', 'count' => $events['Browserauftrag eingereiht'] ?? 0],
    ['id' => 'python', 'label' => 'Python', 'count' => $events['Python-Job eingereiht'] ?? 0],
    ['id' => 'image', 'label' => 'Bilder', 'count' => $events['Bild erzeugt'] ?? 0],
    ['id' => 'files', 'label' => 'Dateien', 'count' => $files],
    ['id' => 'skills', 'label' => 'Skills', 'count' => $skillLoads],
  ];
  $tools = array_values(array_filter($tools, static fn(array $t): bool => $t['count'] > 0));
  usort($tools, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

  // What Mara made: the latest images and files, each with its chat.
  $made = [];
  foreach (coreui_usage_rows($pdo, "SELECT session_id, details_json, created_at FROM stu_console_work_events WHERE user_id = ? "
    . "AND kind = 'artifact' AND title = 'Bild erzeugt' ORDER BY id DESC LIMIT 6", [$uid]) as $row) {
    $meta = json_decode((string)($row['details_json'] ?? ''), true);
    $uuid = is_array($meta) ? (string)($meta['image_uuid'] ?? '') : '';
    if (preg_match('~^[a-f0-9]{32}$~', $uuid) !== 1) continue;
    $made[] = ['type' => 'image', 'uuid' => $uuid, 'name' => 'Bild', 'session' => (string)$row['session_id'], 'at' => (string)$row['created_at']];
  }
  foreach (coreui_usage_rows($pdo, 'SELECT session_id, orig_name, created_at FROM stu_console_artifacts WHERE user_id = ? ORDER BY created_at DESC LIMIT 6', [$uid]) as $row) {
    $made[] = ['type' => 'file', 'uuid' => '', 'name' => mb_substr(basename((string)$row['orig_name']), 0, 80),
      'session' => (string)$row['session_id'], 'at' => (string)$row['created_at']];
  }
  usort($made, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
  $made = array_slice($made, 0, 6);

  // Insights from the counted calls.
  arsort($byModel);
  arsort($byThinking);
  $topModel = (string)(array_key_first($byModel) ?? '');
  $topThinking = (string)(array_key_first($byThinking) ?? '');
  $fast = 0;
  foreach ($byModel as $model => $n) if (coreui_core_tier_of((string)$model) === 'schnell') $fast += $n;
  $share = static fn(int $n): int => $calls > 0 ? (int)round($n * 100 / $calls) : 0;

  // The raster: one entry per day of the last 53 weeks.
  $raster = [];
  for ($i = 0; $i < COREUI_USAGE_RASTER_DAYS; $i++) {
    $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
    $raster[] = ['d' => $d, 'm' => $perDay[$d] ?? 0, 't' => $tokensPerDay[$d] ?? 0];
  }

  return [
    'today' => $today,
    'tokens' => ['ready' => $ready, 'since' => $since, 'total' => array_sum($tokensPerDay), 'peak' => $peak, 'peak_day' => $peakDay],
    'longest_task_seconds' => max(0, $longestTask),
    'streak' => $streaks,
    'messages' => array_sum($perDay),
    'chats' => (int)(coreui_usage_value($pdo, 'SELECT COUNT(*) FROM stu_console_sessions WHERE user_id = ?', [$uid]) ?? 0),
    'raster' => $raster,
    'tools' => $tools,
    'made' => ['images' => $events['Bild erzeugt'] ?? 0, 'files' => $files, 'latest' => $made],
    'insights' => [
      'calls' => $calls,
      'fast_share' => $share($fast),
      'top_core' => $topModel !== '' ? ['label' => coreui_core_label($topModel), 'share' => $share($byModel[$topModel])] : null,
      'top_thinking' => $topThinking !== '' ? ['label' => coreui_usage_thinking_label($topThinking), 'share' => $share($byThinking[$topThinking])] : null,
      'skills_found' => $skillsFound,
      'skills_used' => $skillLoads,
    ],
  ];
}
