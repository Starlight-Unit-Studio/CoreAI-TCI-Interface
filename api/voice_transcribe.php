<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.11 (P20.1): voice input and audio attachments become text.
 *
 * Two stages, as decided after run 4 of the media test (28.10.2026): Core
 * first writes the transcript with Google's template, a neutral SYSTEM text,
 * no thinking and no options; Mara then answers that text like any message.
 *
 *   recording or file  -> ffmpeg: WAV (RIFF), 16 kHz, mono, 16-bit PCM
 *                      -> header check (api/audio_wav.php)
 *                      -> pauses (ffmpeg silencedetect), parts of at most 60 s
 *                      -> one Core call per part, through the model queue
 *                      -> one retry when Core claims there was no recording
 *
 * Limits: the microphone 2 minutes, audio files 10 minutes (longer files: the
 * first 10 minutes, said openly). Temporary files live in a private folder
 * that is removed before the function returns.
 *
 * The model queue and the cancel check come from api/chat.php. Without the
 * queue nothing is sent: Core never gets a second, unguarded request.
 */

require_once __DIR__ . '/audio_wav.php';
require_once __DIR__ . '/voice_core.php';

const COREUI_VOICE_INPUT_MAX_SECONDS = 120;
const COREUI_VOICE_FILE_MAX_SECONDS = 600;
const COREUI_VOICE_PART_MAX_SECONDS = 60;
/** A pause earlier than this is not used for a cut; parts stay long enough to carry context. */
const COREUI_VOICE_PART_MIN_SECONDS = 20;
const COREUI_VOICE_INPUT_MAX_BYTES = 16 * 1024 * 1024;
/** One part: cold start of Core (up to 24 s measured) plus about 18 s per minute. */
const COREUI_VOICE_CALL_TIMEOUT = 300;
const COREUI_VOICE_SILENCE_DB = -35;
const COREUI_VOICE_SILENCE_MIN = 0.35;
/** Account language -> language name in the template. */
const COREUI_VOICE_TRANSCRIBE_LANGUAGES = ['de' => 'German', 'en' => 'English'];

function coreui_voice_language_name(string $code): string {
  return COREUI_VOICE_TRANSCRIBE_LANGUAGES[$code] ?? 'German';
}

/**
 * Can this Core hear? From its /api/show answer: true or false when Ollama
 * lists the capabilities, null when it does not say (Ollama unreachable or
 * too old to list them; the call itself then decides).
 */
function coreui_voice_core_hears(?array $show): ?bool {
  if (!is_array($show) || !isset($show['capabilities']) || !is_array($show['capabilities'])) return null;
  return in_array('audio', array_map('strval', $show['capabilities']), true);
}

/** Path of a command-line tool, null when it is not installed. */
function coreui_voice_bin(string $name): ?string {
  foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
    if (is_file($dir . $name) && is_executable($dir . $name)) return $dir . $name;
  }
  if (!function_exists('shell_exec')) return null;
  $path = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
  return $path !== '' && is_executable($path) ? $path : null;
}

/** Runs ffmpeg with the given arguments: [exit status, output lines (stderr included)]. */
function coreui_voice_ffmpeg(array $args): array {
  $ff = coreui_voice_bin('ffmpeg');
  if ($ff === null) return [127, ['ffmpeg missing']];
  $cmd = escapeshellarg($ff) . ' -nostdin -hide_banner -y ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
  $lines = [];
  $status = 1;
  @exec($cmd, $lines, $status);
  return [(int)$status, $lines];
}

/** Length of any audio or video file in seconds (ffprobe), null when unknown. */
function coreui_voice_probe_seconds(string $path): ?float {
  $probe = coreui_voice_bin('ffprobe');
  if ($probe === null || !function_exists('shell_exec')) return null;
  $out = @shell_exec(escapeshellarg($probe) . ' -v error -show_entries format=duration -of csv=p=0 '
    . escapeshellarg($path) . ' 2>/dev/null');
  $seconds = is_string($out) ? (float)trim($out) : 0.0;
  return $seconds > 0.05 ? $seconds : null;
}

/** A private working folder; the caller removes it with coreui_voice_workdir_remove(). */
function coreui_voice_workdir(): ?string {
  $base = rtrim(sys_get_temp_dir(), '/');
  for ($i = 0; $i < 3; $i++) {
    $dir = $base . '/coreai-voice-' . bin2hex(random_bytes(8));
    if (@mkdir($dir, 0700)) return $dir;
  }
  return null;
}

function coreui_voice_workdir_remove(?string $dir): void {
  if ($dir === null || $dir === '' || !is_dir($dir) || is_link($dir)
      || !str_starts_with(basename($dir), 'coreai-voice-')) return;
  foreach ((array)@scandir($dir) as $name) {
    if (!is_string($name) || $name === '.' || $name === '..') continue;
    $path = $dir . '/' . $name;
    if (is_file($path) || is_link($path)) @unlink($path);
  }
  @rmdir($dir);
}

/** Header of a converted WAV file (at most 10 minutes, about 19 MB). */
function coreui_voice_wav_file_info(string $path): ?array {
  $size = @filesize($path);
  if ($size === false || $size < 44 || $size > 64 * 1024 * 1024) return null;
  return coreui_wav_info((string)@file_get_contents($path));
}

/**
 * Any recording or audio file -> WAV in exactly the format Gemma 4 accepts,
 * at most $maxSeconds long. Returns ok, seconds (of the WAV), source_seconds
 * (null when ffprobe cannot tell), truncated and error.
 */
function coreui_voice_to_wav(string $source, string $target, int $maxSeconds): array {
  $sourceSeconds = coreui_voice_probe_seconds($source);
  [$status] = coreui_voice_ffmpeg(['-loglevel', 'error', '-i', $source, '-vn', '-t', (string)$maxSeconds,
    ...coreui_wav_ffmpeg_args(), $target]);
  if ($status === 127) return ['ok' => false, 'error' => 'ffmpeg_missing'];
  if ($status !== 0 || !is_file($target)) return ['ok' => false, 'error' => 'convert_failed'];
  $info = coreui_voice_wav_file_info($target);
  if (!coreui_wav_gemma_ready($info)) return ['ok' => false, 'error' => 'convert_failed', 'reason' => coreui_wav_reject_reason($info)];
  $seconds = (float)$info['seconds'];
  if ($seconds < 0.2) return ['ok' => false, 'error' => 'empty_audio'];
  return ['ok' => true, 'seconds' => $seconds, 'source_seconds' => $sourceSeconds,
    'truncated' => $sourceSeconds !== null && $sourceSeconds > $maxSeconds + 0.5];
}

/** Pauses in a WAV file as [[start, end], ...] (ffmpeg silencedetect). */
function coreui_voice_silences(string $wav, float $total): array {
  [$status, $lines] = coreui_voice_ffmpeg(['-i', $wav, '-af',
    'silencedetect=noise=' . COREUI_VOICE_SILENCE_DB . 'dB:d=' . COREUI_VOICE_SILENCE_MIN, '-f', 'null', '-']);
  if ($status !== 0) return [];
  return coreui_voice_parse_silences(implode("\n", $lines), $total);
}

function coreui_voice_parse_silences(string $log, float $total): array {
  $pauses = [];
  $start = null;
  foreach (preg_split('~\R~', $log) ?: [] as $line) {
    if (preg_match('~silence_start:\s*(-?[0-9.]+)~', $line, $m) === 1) {
      $start = max(0.0, (float)$m[1]);
    } elseif ($start !== null && preg_match('~silence_end:\s*([0-9.]+)~', $line, $m) === 1) {
      $pauses[] = [$start, min($total, (float)$m[1])];
      $start = null;
    }
  }
  // A pause that lasts to the end has no silence_end line.
  if ($start !== null && $start < $total) $pauses[] = [$start, $total];
  return $pauses;
}

/**
 * Parts of at most $max seconds, cut in the middle of the latest pause that
 * leaves a part of at least $min seconds; without such a pause the cut is
 * hard at $max. A rest shorter than 0.3 s is dropped.
 */
function coreui_voice_parts(float $total, array $pauses, int $max = COREUI_VOICE_PART_MAX_SECONDS,
                            int $min = COREUI_VOICE_PART_MIN_SECONDS): array {
  $parts = [];
  $pos = 0.0;
  while ($total - $pos > $max) {
    $cut = $pos + $max;
    foreach ($pauses as [$a, $b]) {
      $mid = ($a + $b) / 2;
      if ($mid >= $pos + $min && $mid <= $pos + $max) $cut = $mid;
    }
    $parts[] = [round($pos, 3), round($cut, 3)];
    $pos = $cut;
  }
  if ($total - $pos >= 0.3 || $parts === []) $parts[] = [round($pos, 3), round($total, 3)];
  return $parts;
}

/** True when a pause covers the whole part (nothing to transcribe). */
function coreui_voice_part_silent(array $part, array $pauses): bool {
  foreach ($pauses as [$a, $b]) {
    if ($a <= $part[0] + 0.15 && $b >= $part[1] - 0.15) return true;
  }
  return false;
}

/** 75 -> "01:15", the time mark of a part in a transcript. */
function coreui_voice_stamp(float $seconds): string {
  $s = max(0, (int)floor($seconds));
  return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}

/**
 * Payload of one transcription call: neutral SYSTEM, template, audio, no
 * thinking. No options, except the context window from the KI-Profil (the one
 * exception of the parameter-free policy): with another num_ctx Ollama would
 * reload Core twice, once for the transcript and once for Mara's answer.
 */
function coreui_voice_transcribe_payload(string $model, string $wavBase64, string $language, $keepAlive = null,
                                         ?int $numCtx = null): array {
  $payload = [
    'model' => $model,
    'stream' => false,
    'think' => false,
    'messages' => [
      ['role' => 'system', 'content' => coreui_voice_transcribe_system()],
      ['role' => 'user', 'content' => coreui_voice_transcribe_prompt($language), 'images' => [$wavBase64]],
    ],
  ];
  if ($keepAlive !== null) $payload['keep_alive'] = $keepAlive;
  if ($numCtx !== null && $numCtx >= 256) $payload['options'] = ['num_ctx' => $numCtx];
  return $payload;
}

/**
 * One HTTP call to Ollama's /api/chat (no queue here): ok, text, http, error.
 * $abort is asked during the transfer; true stops the call (error 'cancelled').
 */
function coreui_voice_ollama_call(string $url, array $payload, int $timeout, ?callable $abort = null): array {
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $ch = $body !== false ? curl_init($url) : false;
  if ($ch === false) return ['ok' => false, 'error' => 'core_failed', 'http' => 0, 'text' => ''];
  $aborted = false;
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => max(10, $timeout),
    CURLOPT_NOSIGNAL => true,
    CURLOPT_NOPROGRESS => false,
    CURLOPT_XFERINFOFUNCTION => static function () use ($abort, &$aborted): int {
      if ($abort !== null && $abort()) { $aborted = true; return 1; }
      return 0;
    },
  ]);
  $raw = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($aborted) return ['ok' => false, 'error' => 'cancelled', 'http' => $http, 'text' => ''];
  $json = is_string($raw) ? json_decode($raw, true) : null;
  if ($http < 200 || $http >= 300 || !is_array($json)) {
    $message = is_array($json) ? mb_strtolower((string)($json['error'] ?? '')) : '';
    $deaf = $message !== '' && preg_match('~audio|does not support|not support~', $message) === 1;
    return ['ok' => false, 'error' => $deaf ? 'core_without_audio' : 'core_failed', 'http' => $http, 'text' => ''];
  }
  $text = trim((string)($json['message']['content'] ?? ''));
  return ['ok' => true, 'error' => '', 'http' => $http, 'text' => $text];
}

/** True when the current chat run was cancelled (only inside a chat turn). */
function coreui_voice_cancelled(): bool {
  return function_exists('ember_agent_cancel_requested') && ember_agent_cancel_requested();
}

/**
 * One part through Mara's model queue, with one retry when Core claims there
 * was no recording. Returns ok, text, kind ('heard' or 'silence'), error.
 */
function coreui_voice_transcribe_part(string $url, string $model, string $wavBytes, string $language,
                                      int $queueWait, $keepAlive = null, ?int $numCtx = null): array {
  if (!function_exists('ember_global_lock_acquire') || !function_exists('ember_generation_lock_release')) {
    return ['ok' => false, 'error' => 'queue_unavailable'];
  }
  $payload = coreui_voice_transcribe_payload($model, base64_encode($wavBytes), $language, $keepAlive, $numCtx);
  $last = ['ok' => false, 'error' => 'core_failed'];
  for ($attempt = 1; $attempt <= 2; $attempt++) {
    $slot = ember_global_lock_acquire($queueWait);
    if ($slot === false) return ['ok' => false, 'error' => 'queue_timeout'];
    try {
      $call = coreui_voice_ollama_call($url, $payload, COREUI_VOICE_CALL_TIMEOUT, 'coreui_voice_cancelled');
    } finally {
      ember_generation_lock_release($slot);
    }
    if (!$call['ok']) return $call;
    $kind = coreui_voice_reply_kind($call['text']);
    if ($kind === 'heard') return ['ok' => true, 'text' => $call['text'], 'kind' => 'heard', 'attempts' => $attempt];
    if ($kind === 'silence') return ['ok' => true, 'text' => '', 'kind' => 'silence', 'attempts' => $attempt];
    $last = ['ok' => false, 'error' => 'missed', 'attempts' => $attempt];
  }
  return $last;
}

/**
 * The whole way from a recording or file to text.
 *
 * Options: url, model, language ('de' or 'en'), max_seconds, queue_wait,
 * keep_alive, num_ctx, progress (callable(int $done, int $total)).
 * Returns ok, text (parts joined), parts [[start, end, text]], seconds,
 * source_seconds, truncated, error. Errors: ffmpeg_missing, convert_failed,
 * empty_audio, no_speech, missed, core_without_audio, core_failed,
 * queue_timeout, queue_unavailable, cancelled.
 */
function coreui_voice_transcribe_file(string $source, array $options): array {
  $max = (int)($options['max_seconds'] ?? COREUI_VOICE_INPUT_MAX_SECONDS);
  $language = coreui_voice_language_name((string)($options['language'] ?? 'de'));
  $progress = $options['progress'] ?? null;
  $dir = coreui_voice_workdir();
  if ($dir === null) return ['ok' => false, 'error' => 'convert_failed'];
  try {
    $wav = $dir . '/all.wav';
    $converted = coreui_voice_to_wav($source, $wav, $max);
    if (!$converted['ok']) return $converted;
    $total = (float)$converted['seconds'];
    $pauses = coreui_voice_silences($wav, $total);
    $base = ['seconds' => $total, 'source_seconds' => $converted['source_seconds'], 'truncated' => $converted['truncated']];
    $parts = coreui_voice_parts($total, $pauses);
    $speaking = array_values(array_filter($parts, static fn(array $p): bool => !coreui_voice_part_silent($p, $pauses)));
    if ($speaking === []) return ['ok' => false, 'error' => 'no_speech'] + $base;

    $texts = [];
    $done = 0;
    $missed = 0;
    foreach ($speaking as $i => [$start, $end]) {
      if (coreui_voice_cancelled()) return ['ok' => false, 'error' => 'cancelled'] + $base;
      if (is_callable($progress)) $progress($i, count($speaking));
      $clip = $dir . '/part' . $i . '.wav';
      [$status] = coreui_voice_ffmpeg(['-loglevel', 'error', '-ss', sprintf('%.3f', $start), '-t', sprintf('%.3f', $end - $start),
        '-i', $wav, ...coreui_wav_ffmpeg_args(), $clip]);
      $bytes = $status === 0 ? (string)@file_get_contents($clip) : '';
      @unlink($clip);
      if (!coreui_wav_gemma_ready(coreui_wav_info($bytes))) return ['ok' => false, 'error' => 'convert_failed'] + $base;
      $heard = coreui_voice_transcribe_part((string)$options['url'], (string)$options['model'], $bytes, $language,
        (int)($options['queue_wait'] ?? 300), $options['keep_alive'] ?? null,
        isset($options['num_ctx']) ? (int)$options['num_ctx'] : null);
      if (!$heard['ok'] && $heard['error'] === 'missed') { $missed++; continue; }
      if (!$heard['ok']) return ['ok' => false, 'error' => $heard['error']] + $base;
      $done++;
      if ($heard['text'] !== '') $texts[] = [$start, $end, $heard['text']];
    }
    if (is_callable($progress)) $progress(count($speaking), count($speaking));
    if ($texts === []) return ['ok' => false, 'error' => $missed > 0 ? 'missed' : 'no_speech'] + $base;
    return ['ok' => true, 'error' => '', 'text' => implode(' ', array_column($texts, 2)), 'parts' => $texts,
      'missed_parts' => $missed] + $base;
  } finally {
    coreui_voice_workdir_remove($dir);
  }
}

/** Transcript with time marks for Mara: "[00:00] ...\n[01:00] ...". */
function coreui_voice_transcript_with_stamps(array $parts): string {
  $lines = [];
  foreach ($parts as [$start, , $text]) $lines[] = '[' . coreui_voice_stamp((float)$start) . '] ' . trim((string)$text);
  return implode("\n", $lines);
}

/** Short German message for the page, by error code. */
function coreui_voice_error_text(string $code): string {
  return [
    'no_speech' => 'Ich habe keine Sprache gehört.',
    'missed' => 'Core hat die Aufnahme nicht verstanden. Bitte noch einmal versuchen.',
    'core_without_audio' => 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.',
    'convert_failed' => 'Die Aufnahme ließ sich nicht umwandeln.',
    'empty_audio' => 'Die Aufnahme ist leer.',
    'ffmpeg_missing' => 'ffmpeg fehlt auf dem Server.',
    'queue_timeout' => 'Core ist gerade beschäftigt. Bitte gleich noch einmal versuchen.',
    'queue_unavailable' => 'Die Warteschlange von Core ist nicht erreichbar.',
    'cancelled' => 'Abgebrochen.',
    'too_large' => 'Die Aufnahme ist zu groß.',
    'busy' => 'Eine Aufnahme wird schon mitgeschrieben.',
  ][$code] ?? 'Core konnte die Aufnahme nicht mitschreiben.';
}
