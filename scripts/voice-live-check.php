<?php
declare(strict_types=1);
/*
 * CoreAI 0.6.30: live probe for the live conversation (P20.4), on the server with the
 * operator's own Stimmsequenzen, before the live mode is built.
 *
 * A live conversation is a chain of rounds: the person speaks, Mara answers and Eve
 * reads the first sentence as soon as it is there. What decides the waiting time is the
 * Core call of each round, and whether Ollama can keep the beginning of the call (persona
 * and history) in its cache from round to round. Three ways are measured, three rounds
 * each, with the real history of the account's newest chat:
 *
 *   A  two steps, as in the chat today: transcript first (Google's template), then Mara
 *      gets the WAV and the transcript
 *   B  one call: Mara writes a line "Gehoert: ..." with what was said, then answers; the
 *      line is the transcript and is not spoken
 *   C  one call with the WAV only; the transcript would follow in the background
 *
 * Measured: seconds from the end of speaking to the first sentence Eve could speak (Eve
 * needs about 0.15 s more for the first sound), the tokens the Core had to read anew
 * (few = the cache worked), what was heard and how Mara answered, so the operator can see
 * whether she answers the NEW recording. No thinking, Mara's serial queue, nothing stored.
 *
 *   php scripts/voice-live-check.php
 *   php scripts/voice-live-check.php --cores alle
 *   php scripts/voice-live-check.php --uuids a,b,c --cores ember-coreui:12b,ember-coreui:latest
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/model_catalog.php';

$opt = getopt('', ['uuids:', 'cores:', 'user:', 'ways:', 'help']);
if (isset($opt['help'])) {
  echo "php scripts/voice-live-check.php [--user ID] [--uuids a,b,c] [--cores alle|a,b] [--ways ABC]\n";
  exit(0);
}
function live_say(string $line): void { fwrite(STDOUT, $line . "\n"); }
function live_fail(string $why): void { fwrite(STDERR, "FEHLER: {$why}\n"); exit(1); }

/** A line Eve could speak (pure): at least 12 characters ending a sentence, after an optional "Gehoert:" line. */
function live_first_sentence(string $text, bool $heardLine): ?string {
  $text = ltrim($text);
  if ($heardLine) {
    if (preg_match('~^\**\s*Geh(?:ö|oe)rt\s*:~iu', $text) === 1) {
      $nl = strpos($text, "\n");
      if ($nl === false) return null;
      $text = ltrim(substr($text, $nl + 1));
    }
  }
  if (str_starts_with($text, '[') && !str_contains($text, ']')) return null;
  if (preg_match('~^(.{12,}?[.!?])(?:\s|$)~su', $text, $m) !== 1) return null;
  return trim($m[1]);
}

/** The "Gehoert:" line of way B (pure), or ''. */
function live_heard_line(string $text): string {
  return preg_match('~^\**\s*Geh(?:ö|oe)rt\s*:\**\s*(.+)$~imu', ltrim($text), $m) === 1 ? trim($m[1], " \t*\"„“") : '';
}

/** One streamed Core call in Mara's queue: first sentence time, tokens read anew, text. */
function live_call(string $model, string $system, string $prompt, array $images, bool $heardLine): array {
  $body = ['model' => $model, 'stream' => true, 'think' => false,
    'messages' => array_values(array_filter([
      $system !== '' ? ['role' => 'system', 'content' => $system] : null,
      ['role' => 'user', 'content' => $prompt] + ($images ? ['images' => $images] : []),
    ]))];
  $keep = ember_keep_alive();
  if ($keep !== null) $body['keep_alive'] = $keep;
  $numCtx = ember_profile_num_ctx($model);
  if ($numCtx !== null && $numCtx >= 256) $body['options'] = ['num_ctx' => $numCtx];
  $state = ['buf' => '', 'text' => '', 'final' => [], 'error' => '', 'sentence_s' => null, 'first_s' => null];
  $lock = ember_global_lock_acquire(1800);
  if ($lock === false) return ['error' => 'queue_timeout', 'text' => '', 'sentence_s' => null, 'wall_s' => 0.0, 'new_tokens' => null];
  $start = microtime(true);
  try {
    $ch = curl_init(ember_url());
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 900,
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$state, $start, $heardLine): int {
        $state['buf'] .= $chunk;
        while (($nl = strpos($state['buf'], "\n")) !== false) {
          $line = trim(substr($state['buf'], 0, $nl));
          $state['buf'] = substr($state['buf'], $nl + 1);
          $j = $line !== '' ? json_decode($line, true) : null;
          if (!is_array($j)) continue;
          if (isset($j['error'])) $state['error'] = (string)$j['error'];
          $piece = (string)($j['message']['content'] ?? '');
          if ($piece !== '') {
            if ($state['first_s'] === null && trim($piece) !== '') $state['first_s'] = microtime(true) - $start;
            $state['text'] .= $piece;
            if ($state['sentence_s'] === null && live_first_sentence($state['text'], $heardLine) !== null) {
              $state['sentence_s'] = microtime(true) - $start;
            }
          }
          if (!empty($j['done'])) $state['final'] = $j;
        }
        return strlen($chunk);
      },
    ]);
    curl_exec($ch);
    if ($state['error'] === '' && curl_errno($ch) !== 0) $state['error'] = curl_error($ch);
    curl_close($ch);
  } finally {
    ember_generation_lock_release($lock);
  }
  return ['error' => $state['error'], 'text' => trim($state['text']), 'sentence_s' => $state['sentence_s'],
    'wall_s' => microtime(true) - $start, 'new_tokens' => $state['final']['prompt_eval_count'] ?? null,
    'load_s' => isset($state['final']['load_duration']) ? (int)$state['final']['load_duration'] / 1e9 : null];
}

$pdo = stu_pdo();
$uid = isset($opt['user']) ? (int)$opt['user'] : (int)($pdo->query('SELECT id FROM stu_users WHERE permission_level = 0 AND is_guest = 0 ORDER BY id LIMIT 1')
  ->fetchColumn() ?: 0);
if ($uid <= 0) live_fail('Kein Konto gefunden (--user ID angeben).');
coreui_ai_runtime_apply($pdo, $uid);
$language = coreui_voice_language_name((string)(coreui_ai_runtime_settings()['main_language'] ?? 'de'));
// The name Mara calls the account by (profile name, else the part of the login before "@").
$stName = $pdo->prepare('SELECT COALESCE(NULLIF(p.display_name, \'\'), u.username) FROM stu_users u '
  . 'LEFT JOIN stu_coreui_profiles p ON p.user_id = u.id WHERE u.id = ? LIMIT 1');
$stName->execute([$uid]);
$sender = trim((string)preg_replace('~@.*$~', '', (string)($stName->fetchColumn() ?: 'Du')));
if ($sender === '') $sender = 'Du';

// The recordings: --uuids, else the account's newest three Stimmsequenzen, oldest first.
$args = [$uid, 'audio'];
$sql = 'SELECT uuid, rel_path, orig_name, created_at FROM stu_console_media WHERE user_id = ? AND kind = ?';
if (isset($opt['uuids'])) {
  $uuids = array_values(array_filter(array_map(static fn($u) => strtolower(trim((string)$u)), explode(',', (string)$opt['uuids'])),
    static fn(string $u): bool => preg_match('~^[a-f0-9]{32}$~', $u) === 1));
  if ($uuids === []) live_fail('--uuids ohne gueltige Kennung.');
  $sql .= ' AND uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')';
  $args = array_merge($args, $uuids);
} else {
  $sql .= ' AND orig_name LIKE ?';
  $args[] = COREUI_VOICE_MESSAGE_PREFIX . '%';
}
$st = $pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 5');
$st->execute($args);
$recordings = array_reverse(array_slice($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 0, 3));
if ($recordings === []) live_fail('Keine Stimmsequenz dieses Kontos gefunden.');

// The history: the account's newest chat, as Mara gets it.
$sessions = coreui_console_session_list($pdo, $uid);
$session = $sessions[0] ?? null;
$history = $session !== null ? ember_build_context($pdo, 10, 'console', $uid, (string)$session['id']) : '';

// Which Cores and which ways.
$cores = [ember_model()];
if (isset($opt['cores'])) {
  $wanted = trim((string)$opt['cores']);
  if (in_array(strtolower($wanted), ['alle', 'all'], true)) {
    $cores = [];
    foreach (coreui_ollama_models() as $m) {
      $n = (string)$m['name'];
      if (str_ends_with($n, '-cloud') || str_contains($n, ':cloud')) continue;
      if (coreui_voice_core_hears(ember_ollama_show_json($n)) === true) $cores[] = $n;
    }
  } else {
    $cores = array_values(array_filter(array_map('trim', explode(',', $wanted)), static fn(string $c): bool => $c !== ''));
  }
}
if ($cores === []) live_fail('Kein Core mit Audio-Eingang gefunden.');
$ways = array_values(array_intersect(str_split(strtoupper((string)($opt['ways'] ?? 'ABC'))), ['A', 'B', 'C']));

$work = coreui_voice_workdir();
if ($work === null) live_fail('Kein Arbeitsordner.');
register_shutdown_function(static function () use ($work): void {
  foreach ((array)@glob($work . '/r*') as $sub) {
    foreach ((array)@glob($sub . '/*') as $file) if (is_file((string)$file)) @unlink((string)$file);
    @rmdir((string)$sub);
  }
  coreui_voice_workdir_remove($work);
});

// WAVs of each recording (at most 60 s each, as in the chat) and its stored transcript.
$rounds = [];
foreach ($recordings as $i => $rec) {
  $source = $root . '/' . ltrim((string)$rec['rel_path'], '/');
  @mkdir($work . '/r' . $i, 0700);
  $wavs = is_file($source) ? coreui_voice_prepare_native($source, $work . '/r' . $i, COREUI_VOICE_INPUT_MAX_SECONDS) : ['ok' => false];
  if (empty($wavs['ok'])) { live_say('Hinweis: Aufnahme ' . $rec['orig_name'] . ' uebersprungen (nicht umwandelbar).'); continue; }
  $known = coreui_voice_transcript_load($uid, (string)$rec['uuid']);
  $rounds[] = ['name' => (string)$rec['orig_name'], 'seconds' => (float)($wavs['source_seconds'] ?? $wavs['seconds']),
    'images' => array_map(static fn(string $p): string => base64_encode((string)file_get_contents($p)), (array)$wavs['paths']),
    'clip' => ['voice' => true, 'seconds' => (float)$wavs['seconds'], 'paths' => (array)$wavs['paths'], 'stamps' => (array)($wavs['stamps'] ?? [])],
    'stored' => is_array($known) && ($known['state'] ?? '') === 'done' ? trim((string)$known['text']) : ''];
}
if ($rounds === []) live_fail('Keine Aufnahme liess sich umwandeln.');

live_say('CoreAI Live-Probe (' . date('d.m.Y H:i') . ')');
live_say('Verlauf: ' . ($session !== null ? 'Chat "' . (string)$session['title'] . '", ' : '') . mb_strlen($history) . ' Zeichen');
foreach ($rounds as $i => $r) {
  live_say('Runde ' . ($i + 1) . ': ' . $r['name'] . ', ' . coreui_voice_length($r['seconds']) . ($r['stored'] !== '' ? ', gespeicherte Mitschrift "' . $r['stored'] . '"' : ''));
}
live_say('Wege: A zweistufig (Mitschrift, dann WAV + Mitschrift), B ein Aufruf mit "Gehoert:"-Zeile, C nur WAV. Eve braucht danach etwa 0,15 s bis zum ersten Ton.');
live_say('');

$summary = [];
foreach ($cores as $core) {
  live_say('== ' . coreui_core_label($core) . ' (' . $core . ')');
  if (coreui_voice_core_hears(ember_ollama_show_json($core)) === false) { live_say('   hat keinen Audio-Eingang, uebersprungen'); live_say(''); continue; }
  $system = coreui_voice_system_without_think(ember_persona_system_for($core));
  $prefix = ($history !== '' ? "VERLAUF:\n" . $history . "\n\n" : '');
  // Warm up like the live mode will: load the Core with the persona and the history.
  $warm = live_call($core, $system, $prefix . 'Antworte nur mit: Bereit.', [], false);
  live_say(sprintf('   Vorwaermen: %.1f s%s', $warm['wall_s'], $warm['error'] !== '' ? ' (Fehler: ' . $warm['error'] . ')' : ''));
  foreach ($ways as $way) {
    $talk = '';
    $times = [];
    foreach ($rounds as $i => $r) {
      $transcriptS = 0.0;
      $heard = '';
      if ($way === 'A') {
        $t0 = microtime(true);
        $parts = [];
        foreach ($r['clip']['paths'] as $path) {
          $h = coreui_voice_transcribe_part(ember_url(), $core, (string)file_get_contents($path), $language, 600,
            ember_keep_alive(), ember_profile_num_ctx($core));
          if (!empty($h['ok']) && ($h['kind'] ?? '') === 'heard') $parts[] = trim((string)$h['text']);
        }
        $transcriptS = microtime(true) - $t0;
        $heard = implode(' ', $parts);
      }
      $clip = $r['clip'] + ['transcript' => $way === 'A' ? $heard : ''];
      $task = coreui_voice_listen_task($sender, '', [$clip]);
      if ($way === 'B') {
        $task .= "\nSchreib zuerst eine einzige Zeile \"Gehoert: \" mit dem, was in der Aufnahme wortwoertlich gesagt wird. "
          . 'Antworte ab der naechsten Zeile.';
      }
      $prompt = $prefix . ($talk !== '' ? "LIVE-GESPRAECH BISHER:\n" . $talk . "\n" : '') . 'ANHANG-AUFGABE: ' . $task;
      $call = live_call($core, $system, $prompt, $r['images'], $way === 'B');
      if ($way === 'B') $heard = live_heard_line($call['text']);
      $answer = $way === 'B' ? trim((string)preg_replace('~^\**\s*Geh(?:ö|oe)rt\s*:[^\n]*\n?~iu', '', $call['text'], 1)) : $call['text'];
      $total = $call['sentence_s'] !== null ? $transcriptS + $call['sentence_s'] : null;
      if ($total !== null) $times[] = $total;
      live_say(sprintf('   %s Runde %d: erster Satz %s%s, neu gelesen %s Tokens%s', $way, $i + 1,
        $total !== null ? sprintf('nach %.1f s', $total) : 'nie',
        $way === 'A' ? sprintf(' (Mitschrift %.1f s + Antwort %s)', $transcriptS, $call['sentence_s'] !== null ? sprintf('%.1f s', $call['sentence_s']) : '-') : '',
        $call['new_tokens'] !== null ? (string)$call['new_tokens'] : '?', $call['error'] !== '' ? ' FEHLER: ' . $call['error'] : ''));
      live_say('      gehoert: ' . ($heard !== '' ? '"' . $heard . '"' : ($way === 'C' ? '(nicht abgefragt)' : '(nichts)'))
        . ' | Antwort: "' . mb_substr((string)preg_replace('~\s+~u', ' ', $answer), 0, 160) . '"');
      $said = $heard !== '' ? $heard : ($r['stored'] !== '' ? $r['stored'] : '(Stimmsequenz)');
      $talk .= $sender . ': ' . $said . "\nMara: " . mb_substr((string)preg_replace('~\s+~u', ' ', $answer), 0, 600) . "\n";
    }
    if ($times !== []) $summary[] = sprintf('%s, Weg %s: im Mittel %.1f s bis zum ersten Satz', coreui_core_label($core), $way, array_sum($times) / count($times));
  }
  live_say('');
}
live_say('Zusammenfassung:');
foreach ($summary as $line) live_say('   ' . $line);
live_say('Fertig. Bitte die ganze Ausgabe schicken; danach baue ich das Live-Gespraech mit dem besten Weg.');
