<?php
declare(strict_types=1);

/*
 * 0.6.1: Mara reads her answers aloud with Eve (P20.3).
 *
 * The voice service (compose service "voice", tools/voice/voice_service.py)
 * listens on 127.0.0.1 only. PHP asks it for a plan (the answer cut into
 * spoken parts) and then for one part at a time; every result is kept per
 * account under var/voice_cache/<uid> for 7 days, so playing an answer again
 * computes nothing. Signed-in accounts only, never guests; only own answers.
 */

const COREUI_VOICE_KEEP_SECONDS = 7 * 86400;
const COREUI_VOICE_ID_RE = '/^[0-9a-f]{64}$/';
const COREUI_VOICE_MAX_PARTS = 240;

function coreui_voice_url(): string {
  $url = getenv('COREUI_VOICE_URL');
  if (is_string($url) && preg_match('~^http://127\.0\.0\.1:\d{2,5}$~', $url)) return $url;
  $port = (int)(getenv('COREUI_VOICE_PORT') ?: 18790);
  return 'http://127.0.0.1:' . ($port >= 1024 && $port <= 65535 ? $port : 18790);
}

function coreui_voice_cache_root(): string {
  $root = getenv('COREUI_VOICE_CACHE_DIR');
  return is_string($root) && $root !== '' ? rtrim($root, '/') : dirname(__DIR__) . '/var/voice_cache';
}

function coreui_voice_user_dir(string $root, int $uid, bool $create): ?string {
  if ($uid <= 0) return null;
  $dir = $root . '/' . $uid;
  if (is_link($dir)) return null;
  if (!is_dir($dir)) {
    if (!$create || !@mkdir($dir, 0770, true)) return null;
  }
  return $dir;
}

/** Same answer, same voice, same text preparation: same id (and cache). */
/** 0.6.7: the id follows the registry and the code that turns an answer into spoken text, so a
    new dictionary entry (TCI) is heard at once and no stored plan keeps the old reading. */
const COREUI_VOICE_PLAN_SOURCES = ['config/voice/mara-voice.json', 'tools/tts_spike/textprep_de.py', 'tools/voice/textprep_en.py',
  'tools/voice/speakable.py'];
/** 0.6.8: languages Eve speaks; the service detects the language of an answer. */
const COREUI_VOICE_LANGUAGES = ['de', 'en'];

function coreui_voice_plan_basis(): string {
  static $basis = null;
  if ($basis === null) {
    $basis = '';
    foreach (COREUI_VOICE_PLAN_SOURCES as $file) {
      $basis .= hash('sha256', (string)@file_get_contents(dirname(__DIR__) . '/' . $file)) . "\n";
    }
  }
  return $basis;
}

function coreui_voice_plan_id(string $text, string $hint = 'de', array $voices = []): string {
  // 0.6.10: the account's main language decides an unclear answer, so it is part of the plan.
  // 0.7.14: so are the voices chosen instead of Eve (Settings > Stimme); with Eve the id stays as before.
  $chosen = '';
  foreach (coreui_voice_plan_voices($voices) as $language => $name) $chosen .= 'voice:' . $language . '=' . $name . "\n";
  return hash('sha256', coreui_voice_plan_basis() . ($hint !== 'de' ? 'hint:' . $hint . "\n" : '') . $chosen . $text);
}

/** 0.7.14: language => voice for the languages whose voice is not the default one, in a fixed order. */
function coreui_voice_plan_voices(array $voices): array {
  $default = coreui_voice_default_name();
  $out = [];
  foreach (COREUI_VOICE_LANGUAGES as $language) {
    $name = (string)($voices[$language] ?? '');
    if ($name !== '' && $name !== $default && coreui_voice_choice_valid($name, $language)) $out[$language] = $name;
  }
  return $out;
}

/** Removes plans and audio older than 7 days; only files this feature writes. */
function coreui_voice_cleanup(string $dir, ?int $now = null, int $keep = COREUI_VOICE_KEEP_SECONDS): int {
  $now = $now ?? time();
  $removed = 0;
  foreach ((array)@scandir($dir) as $name) {
    // 0.6.14: transcripts of audio attachments (t-<id>.json) expire with the read-aloud audio.
    if (!is_string($name) || !preg_match('/^(?:[0-9a-f]{64}(?:-\d{1,3}\.wav|\.json)|t-[0-9a-f]{64}\.json)$/', $name)) continue;
    $path = $dir . '/' . $name;
    if (is_file($path) && !is_link($path) && (int)@filemtime($path) < $now - $keep && @unlink($path)) $removed++;
  }
  return $removed;
}

function coreui_voice_write(string $path, string $bytes): bool {
  $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
  if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($tmp); return false; }
  @chmod($tmp, 0660);
  return @rename($tmp, $path);
}

/** One HTTP call to the local voice service: [status, content type, body]. */
function coreui_voice_http(string $method, string $path, ?array $json, int $timeout): array {
  $ch = curl_init(coreui_voice_url() . $path);
  $headers = ['Accept: application/json, audio/wav'];
  $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout,
    CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
  if ($method === 'POST') {
    $headers[] = 'Content-Type: application/json';
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = json_encode($json ?? [], JSON_UNESCAPED_UNICODE);
  }
  $options[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $options);
  $body = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  return [$body === false ? 0 : $status, $type, $body === false ? '' : (string)$body];
}

function coreui_voice_health(): array {
  [$status, , $body] = coreui_voice_http('GET', '/health', null, 3);
  $data = $status === 200 ? json_decode($body, true) : null;
  if (!is_array($data)) return ['available' => false, 'loading' => false, 'voice' => ''];
  return ['available' => !empty($data['ready']), 'loading' => !empty($data['loading']), 'voice' => (string)($data['voice'] ?? '')];
}

/** The plan of an answer: from the cache or from the service (then cached). 0.7.14: $voices, language => voice. */
function coreui_voice_plan(string $dir, string $text, string $hint = 'de', array $voices = []): array {
  if (!in_array($hint, COREUI_VOICE_LANGUAGES, true)) $hint = 'de';
  $voices = coreui_voice_plan_voices($voices);
  $id = coreui_voice_plan_id($text, $hint, $voices);
  $file = $dir . '/' . $id . '.json';
  $plan = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
  if (!is_array($plan) || !is_array($plan['parts'] ?? null)) {
    [$status, , $body] = coreui_voice_http('POST', '/plan', ['text' => $text, 'language' => $hint], 15);
    $data = $status === 200 ? json_decode($body, true) : null;
    if (!is_array($data) || !is_array($data['parts'] ?? null)) throw new RuntimeException('voice_unavailable');
    $parts = array_values(array_filter(array_map('strval', $data['parts']), static fn(string $p): bool => trim($p) !== ''));
    $language = in_array($data['language'] ?? 'de', COREUI_VOICE_LANGUAGES, true) ? (string)($data['language'] ?? 'de') : 'de';
    $plan = ['parts' => array_slice($parts, 0, COREUI_VOICE_MAX_PARTS), 'textprep' => (string)($data['textprep'] ?? ''), 'language' => $language];
    if ($voices !== []) $plan['voices'] = $voices;
    coreui_voice_write($file, json_encode($plan, JSON_UNESCAPED_UNICODE));
  }
  if ($plan['parts'] === []) throw new RuntimeException('nothing_to_read');
  $language = (string)($plan['language'] ?? 'de');
  return ['id' => $id, 'parts' => count($plan['parts']), 'language' => in_array($language, COREUI_VOICE_LANGUAGES, true) ? $language : 'de'];
}

/** Path of one spoken part (WAV), computed by the service when not cached. */
function coreui_voice_audio(string $dir, string $id, int $part): string {
  if (!preg_match(COREUI_VOICE_ID_RE, $id) || $part < 0 || $part >= COREUI_VOICE_MAX_PARTS) throw new InvalidArgumentException('invalid_part');
  $plan = json_decode((string)@file_get_contents($dir . '/' . $id . '.json'), true);
  if (!is_array($plan) || !isset($plan['parts'][$part])) throw new RuntimeException('plan_not_found');
  $file = $dir . '/' . $id . '-' . $part . '.wav';
  if (is_file($file) && !is_link($file)) return $file;
  // Plans from before 0.6.8 have no language: German.
  $language = (string)($plan['language'] ?? 'de');
  if (!in_array($language, COREUI_VOICE_LANGUAGES, true)) $language = 'de';
  $request = ['text' => (string)$plan['parts'][$part], 'language' => $language];
  // 0.7.14: the voice chosen for this language when the plan was made (none: Eve).
  $voice = (string)(is_array($plan['voices'] ?? null) ? ($plan['voices'][$language] ?? '') : '');
  if ($voice !== '' && coreui_voice_choice_valid($voice, $language)) $request['voice'] = $voice;
  $body = coreui_voice_speak($request);
  if (!coreui_voice_write($file, $body)) throw new RuntimeException('voice_cache_failed');
  return $file;
}

/** One spoken part from the service: the WAV, or RuntimeException with the reason. */
function coreui_voice_speak(array $request): string {
  [$status, $type, $body] = coreui_voice_http('POST', '/speak', $request, 120);
  if ($status !== 200 || stripos($type, 'audio/wav') !== 0 || substr($body, 0, 4) !== 'RIFF' || substr($body, 8, 4) !== 'WAVE') {
    $error = json_decode($body, true);
    $code = is_array($error) ? (string)($error['error'] ?? '') : '';
    throw new RuntimeException(in_array($code, ['voice_busy', 'voice_loading', 'voice_timeout'], true) ? $code : 'voice_unavailable');
  }
  return $body;
}

// --- 0.7.14: Settings > Stimme (voices, language of Stimmsequenzen, dictation) -----------

const COREUI_VOICE_NAME_RE = '/^[a-z][a-z_]{1,31}$/';
/** Languages of Stimmsequenzen (the Core writes the transcript); '' = the account's main language. */
const COREUI_VOICE_INPUT_LANGUAGES = ['de', 'en', 'fr', 'it', 'pt', 'es'];
/** What Mara says when a voice is heard before it is chosen. */
const COREUI_VOICE_PREVIEW_TEXT = [
  'de' => 'Hallo, ich bin Mara. So klinge ich mit dieser Stimme.',
  'en' => "Hi, I'm Mara. This is how I sound with this voice.",
];
const COREUI_VOICE_PREVIEW_KEEP_SECONDS = 30 * 86400;

/** config/voice/mara-voice.json, read once per request. */
function coreui_voice_registry(): array {
  static $registry = null;
  if ($registry === null) {
    $file = getenv('COREUI_VOICE_REGISTRY');
    $path = is_string($file) && $file !== '' ? $file : dirname(__DIR__) . '/config/voice/mara-voice.json';
    $data = json_decode((string)@file_get_contents($path), true);
    $registry = is_array($data) ? $data : [];
  }
  return $registry;
}

function coreui_voice_default_name(): string {
  $registry = coreui_voice_registry();
  $name = (string)($registry['choices']['default'] ?? ($registry['voice']['name'] ?? 'eve'));
  return preg_match(COREUI_VOICE_NAME_RE, $name) ? $name : 'eve';
}

/** The voices an account may choose: name, label, gender, license, origin, languages. */
function coreui_voice_choices(): array {
  $out = [];
  foreach ((array)(coreui_voice_registry()['choices']['voices'] ?? []) as $voice) {
    if (!is_array($voice)) continue;
    $name = (string)($voice['name'] ?? '');
    if (!preg_match(COREUI_VOICE_NAME_RE, $name) || isset($out[$name])) continue;
    $languages = [];
    foreach (COREUI_VOICE_LANGUAGES as $language) {
      $file = $voice['files'][$language] ?? null;
      if (is_array($file) && preg_match('/^[0-9a-f]{64}$/', (string)($file['sha256'] ?? ''))) $languages[] = $language;
    }
    if ($languages === []) continue;
    $out[$name] = ['name' => $name, 'label' => (string)($voice['label'] ?? $name), 'gender' => (string)($voice['gender'] ?? ''),
      'license' => (string)($voice['license'] ?? ''), 'origin' => (string)($voice['origin'] ?? ''), 'languages' => $languages];
  }
  return array_values($out);
}

function coreui_voice_choice_valid(string $name, string $language): bool {
  if (!in_array($language, COREUI_VOICE_LANGUAGES, true) || !preg_match(COREUI_VOICE_NAME_RE, $name)) return false;
  foreach (coreui_voice_choices() as $voice) {
    if ($voice['name'] === $name) return in_array($language, $voice['languages'], true);
  }
  return false;
}

function coreui_voice_prefs_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT voice_de, voice_en, input_language, dictation FROM stu_user_voice_settings LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** voices (language => name), input_language ('' = the main language, 'auto' or a code), dictation. */
function coreui_voice_prefs(PDO $pdo, int $uid): array {
  $default = coreui_voice_default_name();
  $prefs = ['voices' => array_fill_keys(COREUI_VOICE_LANGUAGES, $default), 'input_language' => '', 'dictation' => true];
  if ($uid <= 0 || !coreui_voice_prefs_ready($pdo)) return $prefs;
  $st = $pdo->prepare('SELECT voice_de, voice_en, input_language, dictation FROM stu_user_voice_settings WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) return $prefs;
  foreach (COREUI_VOICE_LANGUAGES as $language) {
    $name = (string)($row['voice_' . $language] ?? '');
    if ($name !== '' && coreui_voice_choice_valid($name, $language)) $prefs['voices'][$language] = $name;
  }
  $input = (string)($row['input_language'] ?? '');
  if ($input === 'auto' || in_array($input, COREUI_VOICE_INPUT_LANGUAGES, true)) $prefs['input_language'] = $input;
  if ($row['dictation'] !== null) $prefs['dictation'] = (int)$row['dictation'] === 1;
  return $prefs;
}

/** Saves what $changes names (voice_de, voice_en, input_language, dictation); unknown values are refused. */
function coreui_voice_prefs_save(PDO $pdo, int $uid, array $changes): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $columns = [];
  foreach (COREUI_VOICE_LANGUAGES as $language) {
    if (!array_key_exists('voice_' . $language, $changes)) continue;
    $name = $changes['voice_' . $language];
    if (!is_string($name) || !coreui_voice_choice_valid($name, $language)) throw new InvalidArgumentException('invalid_voice');
    $columns['voice_' . $language] = $name === coreui_voice_default_name() ? null : $name;
  }
  if (array_key_exists('input_language', $changes)) {
    $input = $changes['input_language'];
    if (!is_string($input) || ($input !== '' && $input !== 'auto' && !in_array($input, COREUI_VOICE_INPUT_LANGUAGES, true))) {
      throw new InvalidArgumentException('invalid_input_language');
    }
    $columns['input_language'] = $input === '' ? null : $input;
  }
  if (array_key_exists('dictation', $changes)) {
    $on = filter_var($changes['dictation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($on === null) throw new InvalidArgumentException('invalid_dictation');
    $columns['dictation'] = $on ? 1 : 0;
  }
  if ($columns === []) return coreui_voice_prefs($pdo, $uid);
  if (!coreui_voice_prefs_ready($pdo)) throw new RuntimeException('missing_schema_036');
  $names = array_keys($columns);
  $sql = 'INSERT INTO stu_user_voice_settings (user_id, ' . implode(', ', $names) . ') VALUES (?' . str_repeat(', ?', count($names)) . ')'
    . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => $c . ' = VALUES(' . $c . ')', $names));
  $pdo->prepare($sql)->execute(array_merge([$uid], array_values($columns)));
  return coreui_voice_prefs($pdo, $uid);
}

/** The language code for a transcript: the setting, else the main language; 'auto:<main>' for "Automatisch erkennen". */
function coreui_voice_input_code(PDO $pdo, int $uid, string $main): string {
  $main = in_array($main, COREUI_VOICE_LANGUAGES, true) ? $main : 'de';
  $input = coreui_voice_prefs($pdo, $uid)['input_language'];
  if ($input === 'auto') return 'auto:' . $main;
  return $input !== '' ? $input : $main;
}

/** A key that keeps one account's recordings apart from another's on a shared device (no secret, no user id in clear). */
function coreui_voice_recordings_key(int $uid): string {
  return substr(hash('sha256', 'coreai-recordings|' . $uid), 0, 24);
}

/** A voice heard before it is chosen: the same short sentence, kept for all accounts (no account data in it). */
function coreui_voice_preview(string $name, string $language): string {
  if (!coreui_voice_choice_valid($name, $language)) throw new InvalidArgumentException('invalid_voice');
  $dir = coreui_voice_cache_root() . '/preview';
  if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0770, true))) throw new RuntimeException('voice_cache_failed');
  $text = COREUI_VOICE_PREVIEW_TEXT[$language];
  $file = $dir . '/' . hash('sha256', coreui_voice_plan_basis() . $language . "\n" . $name . "\n" . $text) . '.wav';
  if (is_file($file) && !is_link($file)) return $file;
  foreach ((array)@scandir($dir) as $old) {
    $path = $dir . '/' . $old;
    if (is_string($old) && preg_match('/^[0-9a-f]{64}\.wav$/', $old) && is_file($path) && (int)@filemtime($path) < time() - COREUI_VOICE_PREVIEW_KEEP_SECONDS) @unlink($path);
  }
  $request = ['text' => $text, 'language' => $language];
  if ($name !== coreui_voice_default_name()) $request['voice'] = $name;
  if (!coreui_voice_write($file, coreui_voice_speak($request))) throw new RuntimeException('voice_cache_failed');
  return $file;
}

// --- Setting: read answers aloud automatically (off by default) -----------

function coreui_voice_settings_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, auto_read FROM stu_user_voice_settings LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_voice_auto_read(PDO $pdo, int $uid): bool {
  if ($uid <= 0 || !coreui_voice_settings_ready($pdo)) return false;
  $st = $pdo->prepare('SELECT auto_read FROM stu_user_voice_settings WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  return (int)$st->fetchColumn() === 1;
}

function coreui_voice_set_auto_read(PDO $pdo, int $uid, bool $on): bool {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_voice_settings_ready($pdo)) throw new RuntimeException('missing_schema_023');
  $pdo->prepare('INSERT INTO stu_user_voice_settings (user_id, auto_read) VALUES (?, ?) ON DUPLICATE KEY UPDATE auto_read = VALUES(auto_read)')
    ->execute([$uid, $on ? 1 : 0]);
  return coreui_voice_auto_read($pdo, $uid);
}
