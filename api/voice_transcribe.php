<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.17 (P20.1): voice messages and audio files, heard by Mara herself.
 *
 * Operator decision (05.11.2026, after 0.6.14 wrote a transcript into the
 * input field first): a voice message is sent at once and Mara hears the WAV
 * through Gemma's own audio input, in her normal call and without thinking.
 * Gemma 4 takes at most 60 seconds per WAV, so a recording becomes several
 * WAVs in the same call:
 *
 *   recording or file  -> ffmpeg: WAV (RIFF), 16 kHz, mono, 16-bit PCM
 *                      -> header check (api/audio_wav.php)
 *                      -> pauses (ffmpeg silencedetect), parts of at most 60 s
 *                      -> coreui_voice_prepare_native(): one WAV per part for Mara
 *
 * After Mara's answer the transcript for the history is written in the
 * background (coreui_voice_transcribe_file(): Google's template, neutral
 * SYSTEM text, no thinking, one queued Core call per part, one retry when
 * Core claims there was no recording). It is kept per account for 7 days
 * (coreui_voice_transcript_*), shown small under the voice message and read
 * by later turns.
 *
 * Limits: the microphone 2 minutes, audio files up to 10 minutes as far as
 * the Core's context window allows (about 25 tokens per second of audio).
 * The model queue and the cancel check come from api/chat.php. Without the
 * queue nothing is sent: Core never gets a second, unguarded request.
 */

require_once __DIR__ . '/audio_wav.php';
require_once __DIR__ . '/voice_core.php';
require_once __DIR__ . '/voice_store.php';

const COREUI_VOICE_INPUT_MAX_SECONDS = 120;
/** 0.7.14: a dictation upload (two minutes of Opus are about 1 MB; other browsers record larger). */
const COREUI_VOICE_DICTATION_MAX_BYTES = 16 * 1024 * 1024;
const COREUI_VOICE_FILE_MAX_SECONDS = 600;
const COREUI_VOICE_PART_MAX_SECONDS = 60;
/** A pause earlier than this is not used for a cut; parts stay long enough to carry context. */
const COREUI_VOICE_PART_MIN_SECONDS = 20;
/** One part: cold start of Core (up to 24 s measured) plus about 18 s per minute. */
const COREUI_VOICE_CALL_TIMEOUT = 300;
const COREUI_VOICE_SILENCE_DB = -35;
const COREUI_VOICE_SILENCE_MIN = 0.35;
/** Measured in P20.0: about 25 tokens per second of audio. */
const COREUI_VOICE_TOKENS_PER_SECOND = 25;
/** Recordings from the microphone are uploaded under this name. */
const COREUI_VOICE_MESSAGE_PREFIX = 'Sprachnachricht-';
/** A transcript that is still being written counts as pending this long. */
const COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS = 1200;
/** Account language -> language name in the template. 0.7.14: the languages of Settings > Stimme > Sprache. */
const COREUI_VOICE_TRANSCRIBE_LANGUAGES = ['de' => 'German', 'en' => 'English', 'fr' => 'French', 'it' => 'Italian',
  'pt' => 'Portuguese', 'es' => 'Spanish'];

/** 'de' -> 'German'; 0.7.14: 'auto:de' ("Automatisch erkennen", German most likely) -> 'auto:German'. */
function coreui_voice_language_name(string $code): string {
  if (str_starts_with($code, 'auto:')) return 'auto:' . coreui_voice_language_name(substr($code, 5));
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

/**
 * 0.6.18: the length of a recording, rounded to the second ("02:30" for
 * 149.99 s). ffprobe and ffmpeg versions report a container's length a few
 * milliseconds apart (Debian's ffmpeg 5.1 in the php container, 6.1
 * elsewhere); cut off, 150 seconds read "02:29" on one server.
 */
function coreui_voice_length(float $seconds): string {
  return coreui_voice_stamp(round($seconds));
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
                                         ?int $numCtx = null, array $terms = []): array {
  $payload = [
    'model' => $model,
    'stream' => false,
    'think' => false,
    'messages' => [
      ['role' => 'system', 'content' => coreui_voice_transcribe_system()],
      ['role' => 'user', 'content' => coreui_voice_transcribe_prompt($language, $terms), 'images' => [$wavBase64]],
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
                                      int $queueWait, $keepAlive = null, ?int $numCtx = null, array $terms = []): array {
  if (!function_exists('ember_global_lock_acquire') || !function_exists('ember_generation_lock_release')) {
    return ['ok' => false, 'error' => 'queue_unavailable'];
  }
  $payload = coreui_voice_transcribe_payload($model, base64_encode($wavBytes), $language, $keepAlive, $numCtx, $terms);
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
 * keep_alive, num_ctx, terms (names and terms of the account, 0.6.22),
 * progress (callable(int $done, int $total)).
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
        isset($options['num_ctx']) ? (int)$options['num_ctx'] : null, (array)($options['terms'] ?? []));
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

/** True for a recording from the microphone button (not an uploaded audio file). */
function coreui_voice_is_voice_message(string $name): bool {
  return str_starts_with($name, COREUI_VOICE_MESSAGE_PREFIX);
}

/** 0.6.35 (P20.2): does the file carry a sound track? null when ffprobe cannot tell. */
function coreui_voice_has_audio_stream(string $path): ?bool {
  $probe = coreui_voice_bin('ffprobe');
  if ($probe === null || !function_exists('exec')) return null;
  $lines = [];
  $status = 1;
  @exec(escapeshellarg($probe) . ' -v error -select_streams a -show_entries stream=index -of csv=p=0 '
    . escapeshellarg($path) . ' 2>/dev/null', $lines, $status);
  if ($status !== 0) return null;
  foreach ($lines as $line) {
    if (trim($line) !== '') return true;
  }
  return false;
}

/** Does the file carry a picture track? null when ffprobe cannot tell. */
function coreui_voice_has_video_stream(string $path): ?bool {
  $probe = coreui_voice_bin('ffprobe');
  if ($probe === null || !function_exists('exec')) return null;
  $lines = [];
  $status = 1;
  // One line per picture stream: its attached_pic flag (1 = cover art of an audio file).
  @exec(escapeshellarg($probe) . ' -v error -select_streams v -show_entries stream_disposition=attached_pic -of csv=p=0 '
    . escapeshellarg($path) . ' 2>/dev/null', $lines, $status);
  if ($status !== 0) return null;
  foreach ($lines as $line) {
    if (trim($line) !== '' && trim($line) !== '1') return true;
  }
  return false;
}

/**
 * Seconds of audio Mara may hear in one call: the file limit, or less when
 * the Core's context window is small (audio takes at most 40 % of it). At
 * least one part of 60 seconds.
 */
function coreui_voice_native_budget(?int $contextWindow): int {
  if ($contextWindow === null || $contextWindow <= 0) return COREUI_VOICE_FILE_MAX_SECONDS;
  $seconds = (int)floor($contextWindow * 0.4 / COREUI_VOICE_TOKENS_PER_SECOND);
  return max(COREUI_VOICE_PART_MAX_SECONDS, min(COREUI_VOICE_FILE_MAX_SECONDS, $seconds));
}

/**
 * A recording or file -> WAVs of at most 60 s in $targetDir for Mara's call
 * (part0.wav, part1.wav, ...), cut at pauses; parts that are only silence are
 * left out. Returns ok, paths, stamps ("00:00", ...), seconds, source_seconds,
 * truncated and error (no_speech when nothing is said).
 */
function coreui_voice_prepare_native(string $source, string $targetDir, int $maxSeconds): array {
  $work = coreui_voice_workdir();
  if ($work === null || !is_dir($targetDir)) {
    coreui_voice_workdir_remove($work);
    return ['ok' => false, 'error' => 'convert_failed'];
  }
  try {
    $wav = $work . '/all.wav';
    $converted = coreui_voice_to_wav($source, $wav, $maxSeconds);
    if (!$converted['ok']) return $converted;
    $total = (float)$converted['seconds'];
    $pauses = coreui_voice_silences($wav, $total);
    $base = ['seconds' => $total, 'source_seconds' => $converted['source_seconds'], 'truncated' => $converted['truncated']];
    $parts = array_values(array_filter(coreui_voice_parts($total, $pauses),
      static fn(array $p): bool => !coreui_voice_part_silent($p, $pauses)));
    if ($parts === []) return ['ok' => false, 'error' => 'no_speech'] + $base;
    $paths = [];
    $stamps = [];
    $ranges = [];
    foreach ($parts as $i => [$start, $end]) {
      $clip = $targetDir . '/part' . $i . '.wav';
      [$status] = coreui_voice_ffmpeg(['-loglevel', 'error', '-ss', sprintf('%.3f', $start), '-t', sprintf('%.3f', $end - $start),
        '-i', $wav, ...coreui_wav_ffmpeg_args(), $clip]);
      $info = $status === 0 ? coreui_voice_wav_file_info($clip) : null;
      if (!coreui_wav_gemma_ready($info) || (float)$info['seconds'] > COREUI_VOICE_PART_MAX_SECONDS + 0.05) {
        foreach ([...$paths, $clip] as $made) @unlink($made);
        return ['ok' => false, 'error' => 'convert_failed'] + $base;
      }
      $paths[] = $clip;
      $stamps[] = coreui_voice_stamp((float)$start);
      $ranges[] = [(float)$start, (float)$end];
    }
    return ['ok' => true, 'error' => '', 'paths' => $paths, 'stamps' => $stamps, 'ranges' => $ranges] + $base;
  } finally {
    coreui_voice_workdir_remove($work);
  }
}

/** The task for Mara when a recording comes with her normal call. */
function coreui_voice_listen_task(string $senderName, string $text, array $clips, array $terms = [], array $tools = []): string {
  $sender = trim($senderName) !== '' ? trim($senderName) : 'Jemand';
  $lines = [];
  foreach ($clips as $clip) {
    $parts = count((array)($clip['paths'] ?? []));
    $what = !empty($clip['voice']) ? 'Eine Stimmsequenz' : 'Die Audiodatei "' . (string)($clip['name'] ?? 'audio') . '"';
    // 0.6.20: a plain sentence. The bracketed tag "[Angehaengt: Sprachaufnahme, N Sekunden]"
    // (P20.0) made Mara open her answer with "[" (operator's screenshot, 06.11.2026);
    // in the media test it hardly helped anyway (5 of 10 with it, 6 of 10 without).
    $seconds = max(1, (int)round((float)($clip['seconds'] ?? 0)));
    $line = $what . ' von ' . $seconds . ($seconds === 1 ? ' Sekunde' : ' Sekunden');
    $line .= $parts > 1 ? ', als ' . $parts . ' Audioteile nacheinander (Beginn ' . implode(', ', (array)$clip['stamps']) . ')' : ', als Audio';
    if (!empty($clip['truncated'])) $line .= '; nur die ersten ' . coreui_voice_length((float)$clip['seconds']) . ' Minuten liegen bei, sag das offen';
    $line .= '.';
    // 0.6.20: the transcript written just before as an anchor (operator's decision, 06.11.2026):
    // with a long prompt the small Core answered an older voice message instead of the new one.
    $transcript = trim((string)($clip['transcript'] ?? ''));
    if ($transcript !== '') $line .= "\nAutomatische Mitschrift dazu (kann Hoerfehler enthalten, im Zweifel gilt der Audioinhalt): \"" . $transcript . '"';
    $lines[] = $line;
  }
  $voice = (bool)array_filter($clips, static fn(array $c): bool => !empty($c['voice']));
  $task = $sender . ' hat dir ' . ($voice ? 'gesprochen' : 'Audio geschickt') . ":\n" . implode("\n", $lines);
  if (trim($text) !== '') $task .= "\nDazu schreibt " . $sender . ': "' . trim($text) . '"';
  // 0.6.22: names and terms from the KI-Profil, as they are written.
  $terms = array_values(array_filter(array_map(static fn($t): string => trim((string)$t), $terms), static fn(string $t): bool => $t !== ''));
  if ($terms !== []) {
    $task .= "\nNamen und Begriffe, die " . $sender . ' oft sagt (so geschrieben): ' . implode(', ', $terms)
      . '. Klingt etwas im Audio wie einer davon, ist er gemeint.';
  }
  // 0.6.22: the tools of the work loop, as for a typed message.
  $menu = [];
  if (in_array('web', $tools, true)) $menu[] = '[WEB: suchbegriffe] fuer einen schnellen Fakt aus dem Netz (Wetter, ein Datum, ein Preis, eine kurze Info)';
  if (in_array('browse', $tools, true)) $menu[] = '[BROWSE: was du herausfinden sollst] wenn du dafuer selbst in Ruhe Webseiten lesen sollst';
  if (in_array('archive', $tools, true)) $menu[] = '[ARCHIV: stichworte] um in der Mitschrift dieses Chats nachzusehen';
  if ($menu !== []) {
    $task .= "\nBrauchst du fuer die Antwort aktuelle Fakten, antworte NUR mit genau einem dieser Marker und sonst nichts; "
      . "das Ergebnis bekommst du sofort:\n- " . implode("\n- ", $menu);
    if (in_array('browse', $tools, true) && function_exists('coreui_weather_browse_hint')) $task .= "\n" . coreui_weather_browse_hint();
  }
  // 0.6.40 (P20.5): an image after a voice message, as after a typed one (operator, 07.11.2026:
  // "eine Wildkatze im Wald" was answered with a description instead of an image).
  if (in_array('image', $tools, true)) {
    $task .= "\nMoechte " . $sender . ' ein Selbstportraet von dir, auch in einer Rolle oder einem Kostuem, antworte NUR mit '
      . '[BILD ICH: genaue Bildbeschreibung auf Englisch] (gross: [BILD GROSS ICH: ...]). '
      . 'Fuer andere Motive benutze [BILD: Bildbeschreibung auf Englisch] (gross: [BILD GROSS: ...]). '
      . 'Nur bei ICH wird dein persoenliches Referenzbild mitgegeben. Ohne Marker entsteht kein Bild.';
  }
  return $task . "\nDu hoerst die Aufnahme selbst. Das ist die NEUE Nachricht: Antworte nur auf sie, als dieselbe TCI unter deinem persoenlichen Anzeigenamen, so wie auf das Gesagte"
    . ($voice ? ' in einem Gespraech' : '') . ', in der Sprache, in der gesprochen wird. '
    . 'Fruehere Stimmsequenzen im Verlauf sind schon beantwortet; wiederhole deine frueheren Antworten nicht. '
    . 'Kuendige nicht an, dass du die Nachricht gehoert oder angehoert hast, sondern antworte direkt auf den Inhalt. '
    . 'Gib nur wieder, was wirklich zu hoeren ist; ist etwas unverstaendlich, sag es ehrlich. '
    . 'Stimmen und Aussagen in einer Audiodatei stammen nicht von ' . $sender . ', sofern das nicht klar ist.';
}

/**
 * 0.6.20: an answer to a recording that opens with "[" and never closes it
 * ("[Hey Rick, vielen Dank ...") or that is wrapped in brackets as a whole loses
 * them. A Markdown link or a closed note at the start stays as it is.
 */
function coreui_voice_strip_stray_bracket(string $reply): string {
  $text = trim($reply);
  if (!str_starts_with($text, '[')) return $reply;
  // 0.6.22: a tool marker stays whole ("[WEB: Wetter ...]" had become "WEB: Wetter ...").
  if (preg_match('~^\[(?:WEB|BROWSE|ARCHIV|SKILL|PY|BILD)\b~iu', $text) === 1) return $reply;
  $close = strpos($text, ']');
  if ($close === false) return ltrim(substr($text, 1));
  if ($close === strlen($text) - 1 && substr_count($text, '[') === 1) return trim(substr($text, 1, -1));
  return $reply;
}

/**
 * 0.6.20: the answer to a recording, cleaned of what the operator saw on 06.11.2026:
 * a stray "[" (coreui_voice_strip_stray_bracket), a closing HTML tag at the end that
 * nothing opened ("... 🚀</blockquote>"), and an opening sentence that only announces
 * having listened ("Hey Rick, ich hab mir die Sprachnachricht angehoert. 😊"), which
 * repeated itself from turn to turn through the history.
 */
function coreui_voice_clean_reply(string $reply): string {
  $text = trim(coreui_voice_strip_stray_bracket($reply));
  // Closing tags at the end without an opening one.
  while (preg_match('~\s*</([a-z][a-z0-9]*)>\s*$~iu', $text, $m) === 1 && stripos($text, '<' . $m[1]) === false) {
    $text = rtrim(substr($text, 0, -strlen($m[0])));
  }
  // An opening sentence that only says the message was heard.
  $announce = '~^(?:(?:hey|hi|hallo|hej|moin|na)\b[^.!?\n]{0,40}?,\s*)?(?:ich\s+)?(?:hab|habe)\s+(?:mir\s+)?(?:die|deine|eure|diese)\s+'
    . '(?:(?:sprach)?(?:nachricht|aufnahme)|stimmsequenz)\s+(?:gerade\s+|eben\s+)?(?:an)?geh(?:oe|ö)rt\s*[.!]+\s*(?:[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]\x{FE0F}?\s*)*~iu';
  $rest = preg_replace($announce, '', $text, 1);
  if (is_string($rest) && trim($rest) !== '') $text = trim($rest);
  return $text;
}

// --- 0.6.22: names and terms for voice messages (KI-Profil) -------------------

const COREUI_VOICE_TERMS_MAX = 40;
const COREUI_VOICE_TERM_CHARS = 60;

/**
 * The account's list of names and terms, one per line (commas and semicolons
 * separate too): trimmed, inner spaces collapsed, characters other than
 * letters, digits, space, dot, apostrophe, hyphen, ampersand, slash and plus
 * removed (no brackets, quotes or tool markers reach a prompt), at most 60
 * characters each, duplicates dropped regardless of case, at most 40.
 */
function coreui_voice_terms_normalise($input): array {
  $raw = is_array($input) ? implode("\n", array_map('strval', $input)) : (string)$input;
  $terms = [];
  $seen = [];
  foreach (preg_split('~[\r\n,;]+~u', $raw) ?: [] as $term) {
    $term = (string)preg_replace('~[^\p{L}\p{M}\p{N} .\'’&/+\-]~u', '', $term);
    $term = (string)preg_replace(['~\s+~u', '~^[ .\'’&/+\-]+|[ .\'’&/+\-]+$~u'], [' ', ''], $term);
    if ($term === '') continue;
    if (mb_strlen($term) > COREUI_VOICE_TERM_CHARS) $term = rtrim(mb_substr($term, 0, COREUI_VOICE_TERM_CHARS));
    $key = mb_strtolower($term);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $terms[] = $term;
    if (count($terms) >= COREUI_VOICE_TERMS_MAX) break;
  }
  return $terms;
}

/** Column vocabulary exists (migration 025). */
function coreui_voice_terms_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT vocabulary FROM stu_user_voice_settings LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_voice_terms_load(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_voice_terms_ready($pdo)) return [];
  try {
    $st = $pdo->prepare('SELECT vocabulary FROM stu_user_voice_settings WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    return coreui_voice_terms_normalise((string)$st->fetchColumn());
  } catch (Throwable $e) {
    return [];
  }
}

function coreui_voice_terms_save(PDO $pdo, int $uid, $input): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_voice_terms_ready($pdo)) throw new RuntimeException('missing_schema_025');
  $terms = coreui_voice_terms_normalise($input);
  $pdo->prepare('INSERT INTO stu_user_voice_settings (user_id, vocabulary) VALUES (?, ?) ON DUPLICATE KEY UPDATE vocabulary = VALUES(vocabulary)')
    ->execute([$uid, $terms !== [] ? implode("\n", $terms) : null]);
  return coreui_voice_terms_load($pdo, $uid);
}

/** Cache file of the transcript of one attachment (var/voice_cache/<uid>/t-<id>.json). */
function coreui_voice_transcript_path(int $uid, string $uuid, bool $create = false): ?string {
  if (preg_match('~^[a-f0-9]{32}$~', $uuid) !== 1) return null;
  $dir = coreui_voice_user_dir(coreui_voice_cache_root(), $uid, $create);
  return $dir !== null ? $dir . '/t-' . hash('sha256', 'transcript|' . $uuid) . '.json' : null;
}

/** ['state' => 'done', 'text', 'parts', ...], ['state' => 'pending'] or null. */
function coreui_voice_transcript_load(int $uid, string $uuid, ?int $now = null): ?array {
  $path = coreui_voice_transcript_path($uid, $uuid);
  if ($path === null || !is_file($path) || is_link($path)) return null;
  $data = json_decode((string)@file_get_contents($path), true);
  if (!is_array($data)) return null;
  if (($data['state'] ?? '') === 'pending'
      && (int)($data['started'] ?? 0) < ($now ?? time()) - COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS) return null;
  return $data;
}

function coreui_voice_transcript_save(int $uid, string $uuid, array $data): bool {
  $path = coreui_voice_transcript_path($uid, $uuid, true);
  if ($path === null) return false;
  coreui_voice_cleanup(dirname($path));
  return coreui_voice_write($path, (string)json_encode($data, JSON_UNESCAPED_UNICODE));
}
