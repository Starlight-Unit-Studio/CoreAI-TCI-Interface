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
function coreui_voice_plan_id(string $text): string {
  $registry = (string)@file_get_contents(dirname(__DIR__) . '/config/voice/mara-voice.json');
  return hash('sha256', hash('sha256', $registry) . "\n" . $text);
}

/** Removes plans and audio older than 7 days; only files this feature writes. */
function coreui_voice_cleanup(string $dir, ?int $now = null, int $keep = COREUI_VOICE_KEEP_SECONDS): int {
  $now = $now ?? time();
  $removed = 0;
  foreach ((array)@scandir($dir) as $name) {
    if (!is_string($name) || !preg_match('/^[0-9a-f]{64}(?:-\d{1,3}\.wav|\.json)$/', $name)) continue;
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

/** The plan of an answer: from the cache or from the service (then cached). */
function coreui_voice_plan(string $dir, string $text): array {
  $id = coreui_voice_plan_id($text);
  $file = $dir . '/' . $id . '.json';
  $plan = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
  if (!is_array($plan) || !is_array($plan['parts'] ?? null)) {
    [$status, , $body] = coreui_voice_http('POST', '/plan', ['text' => $text], 15);
    $data = $status === 200 ? json_decode($body, true) : null;
    if (!is_array($data) || !is_array($data['parts'] ?? null)) throw new RuntimeException('voice_unavailable');
    $parts = array_values(array_filter(array_map('strval', $data['parts']), static fn(string $p): bool => trim($p) !== ''));
    $plan = ['parts' => array_slice($parts, 0, COREUI_VOICE_MAX_PARTS), 'textprep' => (string)($data['textprep'] ?? '')];
    coreui_voice_write($file, json_encode($plan, JSON_UNESCAPED_UNICODE));
  }
  if ($plan['parts'] === []) throw new RuntimeException('nothing_to_read');
  return ['id' => $id, 'parts' => count($plan['parts'])];
}

/** Path of one spoken part (WAV), computed by the service when not cached. */
function coreui_voice_audio(string $dir, string $id, int $part): string {
  if (!preg_match(COREUI_VOICE_ID_RE, $id) || $part < 0 || $part >= COREUI_VOICE_MAX_PARTS) throw new InvalidArgumentException('invalid_part');
  $plan = json_decode((string)@file_get_contents($dir . '/' . $id . '.json'), true);
  if (!is_array($plan) || !isset($plan['parts'][$part])) throw new RuntimeException('plan_not_found');
  $file = $dir . '/' . $id . '-' . $part . '.wav';
  if (is_file($file) && !is_link($file)) return $file;
  [$status, $type, $body] = coreui_voice_http('POST', '/speak', ['text' => (string)$plan['parts'][$part]], 120);
  if ($status !== 200 || stripos($type, 'audio/wav') !== 0 || substr($body, 0, 4) !== 'RIFF' || substr($body, 8, 4) !== 'WAVE') {
    $error = json_decode($body, true);
    $code = is_array($error) ? (string)($error['error'] ?? '') : '';
    throw new RuntimeException(in_array($code, ['voice_busy', 'voice_loading', 'voice_timeout'], true) ? $code : 'voice_unavailable');
  }
  if (!coreui_voice_write($file, $body)) throw new RuntimeException('voice_cache_failed');
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
