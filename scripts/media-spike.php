<?php
declare(strict_types=1);
/*
 * CoreAI 0.5.75: P20.0 media test on the real server (voice plan).
 *
 * Measures Gemma 4 audio input through Ollama: accepted formats, clip
 * length, time to the first word and transcript; one combined image plus
 * audio request. Since 0.5.75 it compares ways to ask for a transcript on
 * short clips (Mara's persona with the old prompt, Google's transcription
 * template with a neutral SYSTEM text, the template with one second of
 * silence in front, the template with temperature 0), runs the template on
 * the long clips, and times a live reply with thinking, in one step and in
 * two steps (transcript first, then Mara answers the text). Optional TTS
 * and image timings prepared by scripts/media-spike.sh are added to the
 * same report. Changes no model, Modelfile or database row; every Core call
 * waits in Mara's serial model queue like a chat turn and keeps CoreAI's
 * keep_alive.
 *
 * Usage (normally started by scripts/media-spike.sh):
 *   php scripts/media-spike.php --out var/cache/media-spike/STAMP [--model NAME]
 *       [--audio FILE] [--durations 5,15,30,45,60] [--skip-formats]
 *       [--short 3,5] [--repeat 5]
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/audio_wav.php';
require_once $root . '/api/voice_core.php';

$opt = getopt('', ['out:', 'model:', 'audio:', 'durations:', 'skip-formats', 'short:', 'repeat:']);
$out = rtrim((string)($opt['out'] ?? ($root . '/var/cache/media-spike/' . date('Ymd-His'))), '/');
if (!str_starts_with($out, '/')) $out = $root . '/' . $out;
$model = trim((string)($opt['model'] ?? ember_model()));
$secondsList = static fn(string $list): array => array_values(array_unique(array_filter(
  array_map('intval', explode(',', $list)), static fn(int $s): bool => $s >= 1 && $s <= 300)));
$durations = $secondsList((string)($opt['durations'] ?? '5,15,30,45,60'));
$shortClips = $secondsList((string)($opt['short'] ?? '3,5'));
$repeat = max(0, min(20, (int)($opt['repeat'] ?? 5)));
@mkdir($out . '/wav', 0775, true);
if (!is_dir($out . '/wav')) { fwrite(STDERR, "Ausgabeordner nicht beschreibbar: {$out}\n"); exit(1); }

$apiBase = (string)preg_replace('~/api/(?:chat|generate)/?$~', '', ember_url());
$results = ['started'=>date('c'), 'model'=>$model, 'ollama'=>$apiBase, 'cases'=>[]];
function spike_log(string $line): void { fwrite(STDOUT, $line . "\n"); }

function spike_http(string $method, string $url, ?array $body, int $timeout): array {
  $ch = curl_init($url);
  $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json']];
  if ($method === 'POST') {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  curl_setopt_array($ch, $opts);
  $start = microtime(true);
  $raw = curl_exec($ch);
  $wall = microtime(true) - $start;
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $json = is_string($raw) ? json_decode($raw, true) : null;
  return ['code'=>$code, 'wall_s'=>round($wall, 2), 'curl_error'=>$err,
    'json'=>is_array($json) ? $json : null, 'raw'=>is_string($raw) ? substr($raw, 0, 400) : ''];
}

/** One NDJSON line of a streamed /api/chat answer. */
function spike_stream_line(array &$state, string $line, float $start): void {
  $j = json_decode($line, true);
  if (!is_array($j)) return;
  if (isset($j['error'])) { $state['error'] = (string)$j['error']; return; }
  $state['thinking'] .= (string)($j['message']['thinking'] ?? '');
  $piece = (string)($j['message']['content'] ?? '');
  if ($piece !== '') {
    $now = round(microtime(true) - $start, 2);
    if ($state['first_s'] === null && trim($piece) !== '') $state['first_s'] = $now;
    $state['text'] .= $piece;
    // First sentence a voice could speak: a leading "[Analyse: ...]" block
    // is not spoken, so it only counts once that block is closed and removed.
    if ($state['sentence_s'] === null) {
      $text = ltrim($state['text']);
      $open = str_starts_with($text, '[') && !str_contains($text, ']');
      $spoken = $open ? '' : trim(ember_strip_leading_bracket_meta($text));
      if (mb_strlen($spoken) >= 12 && preg_match('~[.!?](?:\s|$)~u', $spoken) === 1) $state['sentence_s'] = $now;
    }
  }
  if (!empty($j['done'])) $state['final'] = $j;
}

/** Streams one /api/chat call and notes when the first word and the first spoken sentence arrived. */
function spike_stream(string $url, array $body, int $timeout): array {
  $state = ['buf'=>'', 'text'=>'', 'thinking'=>'', 'final'=>[], 'error'=>'', 'first_s'=>null, 'sentence_s'=>null];
  $start = microtime(true);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$state, $start): int {
      $state['buf'] .= $chunk;
      while (($nl = strpos($state['buf'], "\n")) !== false) {
        $line = trim(substr($state['buf'], 0, $nl));
        $state['buf'] = substr($state['buf'], $nl + 1);
        if ($line !== '') spike_stream_line($state, $line, $start);
      }
      return strlen($chunk);
    },
  ]);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  // An error answer (HTTP 400) is one JSON object without a trailing newline.
  if (trim($state['buf']) !== '') spike_stream_line($state, trim($state['buf']), $start);
  return ['code'=>$code, 'wall_s'=>round(microtime(true) - $start, 2), 'curl_error'=>$err] + $state;
}

function spike_ffmpeg(array $args): bool {
  $cmd = 'ffmpeg -nostdin -hide_banner -loglevel error -y ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
  exec($cmd, $lines, $status);
  if ($status !== 0) spike_log('  ffmpeg: ' . implode(' | ', array_slice($lines, 0, 3)));
  return $status === 0;
}

/**
 * One Core call in Mara's queue: audio (and optional image), a prompt and
 * optionally a SYSTEM text. Ollama options stay empty, with one exception
 * the operator approved on 28.10.2026: temperature 0 for the transcription
 * variant "vorlage_temp0" of this test, never in the chat.
 */
function spike_core(string $apiBase, string $model, string $prompt, array $images, ?string $system = null,
                    bool $think = false, array $options = [], int $timeout = 900): array {
  $messages = $system !== null ? [['role'=>'system', 'content'=>$system]] : [];
  $messages[] = ['role'=>'user', 'content'=>$prompt] + ($images ? ['images'=>$images] : []);
  $body = ['model'=>$model, 'stream'=>true, 'think'=>$think, 'messages'=>$messages];
  if ($options !== []) $body['options'] = $options;
  // CoreAI's own keep_alive: the test never shortens how long Mara stays loaded.
  $keepAlive = ember_keep_alive();
  if ($keepAlive !== null) $body['keep_alive'] = $keepAlive;
  $lock = ember_global_lock_acquire(1800);
  if ($lock === false) spike_log('  Hinweis: Warteschlange nicht erreichbar, Aufruf laeuft ohne Einreihung.');
  try {
    $r = spike_stream($apiBase . '/api/chat', $body, $timeout);
  } finally {
    if ($lock !== false) ember_generation_lock_release($lock);
  }
  $j = $r['final'];
  $ns = static fn(string $k): ?float => isset($j[$k]) ? round((int)$j[$k] / 1e9, 2) : null;
  $text = trim($r['text']);
  return [
    'http'=>$r['code'], 'wall_s'=>$r['wall_s'], 'error'=>$r['error'] !== '' ? $r['error'] : (string)$r['curl_error'],
    'load_s'=>$ns('load_duration'), 'prompt_eval_s'=>$ns('prompt_eval_duration'), 'eval_s'=>$ns('eval_duration'),
    'first_answer_s'=>$r['first_s'], 'first_sentence_s'=>$r['sentence_s'],
    'prompt_tokens'=>$j['prompt_eval_count'] ?? null, 'output_tokens'=>$j['eval_count'] ?? null,
    'done_reason'=>(string)($j['done_reason'] ?? ''),
    'thinking_chars'=>mb_strlen($r['thinking']), 'text_chars'=>mb_strlen($text),
    'kind'=>coreui_voice_reply_kind($text),
    'text'=>$text, 'thinking'=>mb_substr(trim($r['thinking']), 0, 4000),
  ];
}

function spike_case_log(string $name, array $r): void {
  spike_log("  {$name}: HTTP {$r['http']}, {$r['wall_s']} s, erstes Wort " . ($r['first_answer_s'] ?? '-')
    . ' s, Tokens ' . ($r['prompt_tokens'] ?? '-') . '/' . ($r['output_tokens'] ?? '-')
    . ($r['kind'] !== 'heard' ? " ({$r['kind']})" : '')
    . ($r['error'] !== '' ? ", Fehler: {$r['error']}" : '') . ' | ' . mb_substr($r['text'], 0, 90));
}

// --- Environment -----------------------------------------------------------
spike_log("CoreAI P20.0 Medien-Test\nAusgabe: {$out}\nCore: {$model}\nOllama: {$apiBase}");
exec('ffmpeg -version 2>/dev/null', $ff);
$results['ffmpeg'] = $ff[0] ?? 'nicht gefunden';
if (!isset($ff[0])) { fwrite(STDERR, "ffmpeg fehlt; Audio-Test nicht moeglich.\n"); exit(1); }
$results['ollama_version'] = spike_http('GET', $apiBase . '/api/version', null, 10)['json']['version'] ?? 'unbekannt';
$tags = spike_http('GET', $apiBase . '/api/tags', null, 15)['json']['models'] ?? [];
$tags = is_array($tags) ? $tags : [];
$tagInfo = [];
foreach ($tags as $m) {
  $n = (string)($m['name'] ?? '');
  if ($n !== '') $tagInfo[$n] = ['digest'=>substr((string)($m['digest'] ?? ''), 0, 12),
    'size_gb'=>isset($m['size']) ? round((int)$m['size'] / 1e9, 1) : null];
}
$names = array_values(array_unique(array_merge([$model], array_values(array_filter(array_map(
  static fn($m) => (string)($m['name'] ?? ''), $tags), static fn(string $n): bool => stripos($n, 'gemma4') === 0)))));
$results['capabilities'] = [];
$modelShow = [];
foreach ($names as $name) {
  $show = spike_http('POST', $apiBase . '/api/show', ['model'=>$name], 30)['json'] ?? [];
  if ($name === $model) $modelShow = $show;
  $tag = $tagInfo[$name] ?? $tagInfo[$name . ':latest'] ?? ['digest'=>'', 'size_gb'=>null];
  // The weights file behind FROM shows whether several names share one model.
  $weights = preg_match('~^FROM\s+\S*sha256[-:]([0-9a-f]{12})~mi', (string)($show['modelfile'] ?? ''), $w) === 1 ? $w[1] : '';
  $results['capabilities'][$name] = [
    'capabilities'=>$show['capabilities'] ?? null,
    'parameter_size'=>$show['details']['parameter_size'] ?? null,
    'quantization'=>$show['details']['quantization_level'] ?? null,
    'digest'=>$tag['digest'], 'size_gb'=>$tag['size_gb'], 'weights'=>$weights,
  ];
  spike_log("  {$name}: Faehigkeiten " . json_encode($show['capabilities'] ?? 'keine Angabe')
    . ", Gewichte {$weights}");
}
// Read only. A Core created from a Modelfile before 0.5.75 still carries
// <|think|>; persona calls without thinking then send the same text without it.
$system = coreui_voice_modelfile_system(is_array($modelShow) ? $modelShow : []);
$hasThinkToken = str_contains($system, '<|think|>');
$personaNoThink = $hasThinkToken ? coreui_voice_system_without_think($system) : null;
$results['system'] = ['chars'=>mb_strlen($system), 'has_think'=>$hasThinkToken];
$neutral = coreui_voice_transcribe_system();
$template = coreui_voice_transcribe_prompt('German');

// --- Test audio ------------------------------------------------------------
$source = (string)($opt['audio'] ?? '');
if ($source === '' && is_file($out . '/input/tts-sample.wav')) $source = $out . '/input/tts-sample.wav';
$speech = $source !== '' && is_file($source);
if (!$speech) {
  $source = $out . '/wav/tone.wav';
  spike_ffmpeg(['-f', 'lavfi', '-i', 'sine=frequency=440:duration=5', ...coreui_wav_ffmpeg_args(), $source]);
  spike_log('Keine Sprachaufnahme angegeben: nur Formattest mit Sinuston, keine Erkennungsqualitaet.');
}
$results['source'] = $speech ? basename($source) : 'Sinuston (kein Sprachinhalt)';
/** A clip of the first $sec seconds of the source, canonical format, created once. */
$clipOf = static function (int $sec) use ($out, $source): ?string {
  $path = "{$out}/wav/ok_{$sec}s.wav";
  if (!is_file($path)) spike_ffmpeg(['-stream_loop', '-1', '-i', $source, '-t', (string)$sec, ...coreui_wav_ffmpeg_args(), $path]);
  return is_file($path) ? $path : null;
};
/** The same clip with one second of silence in front and 0.3 s behind. */
$paddedOf = static function (int $sec) use ($out, $clipOf): ?string {
  $clip = $clipOf($sec);
  $path = "{$out}/wav/still_{$sec}s.wav";
  if ($clip !== null && !is_file($path)) {
    spike_ffmpeg(['-i', $clip, '-af', 'adelay=1000:all=1,apad=pad_dur=0.3', ...coreui_wav_ffmpeg_args(), $path]);
  }
  return is_file($path) ? $path : null;
};
$b64 = static fn(string $path): string => base64_encode((string)file_get_contents($path));

$prompt = 'Schreibe woertlich und vollstaendig auf, was in der Aufnahme gesagt wird, ohne Kommentar. '
  . 'Wenn keine verstaendliche Sprache zu hoeren ist, antworte nur: (keine Sprache).';
$cases = [];
foreach ($durations as $sec) {
  $cases[] = ['name'=>"ok_{$sec}s", 'file'=>"ok_{$sec}s.wav",
    'args'=>['-stream_loop', '-1', '-i', $source, '-t', (string)$sec, ...coreui_wav_ffmpeg_args()]];
}
if (!isset($opt['skip-formats'])) {
  $cases[] = ['name'=>'format_24bit', 'file'=>'f_s24.wav', 'args'=>['-stream_loop', '-1', '-i', $source, '-t', '15', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s24le']];
  $cases[] = ['name'=>'format_float32', 'file'=>'f_f32.wav', 'args'=>['-stream_loop', '-1', '-i', $source, '-t', '15', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_f32le']];
  $cases[] = ['name'=>'format_44k_stereo', 'file'=>'f_44k.wav', 'args'=>['-stream_loop', '-1', '-i', $source, '-t', '15', '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le']];
  $cases[] = ['name'=>'format_raw_pcm', 'file'=>'f_raw.pcm', 'args'=>['-stream_loop', '-1', '-i', $source, '-t', '15', '-ac', '1', '-ar', '16000', '-f', 's16le']];
}

// Load the Core once so the cases measure warm latency.
spike_log('Lade Core (Aufwaermen) ...');
$results['warmup'] = spike_core($apiBase, $model, 'Antworte nur mit: ok', []);

// --- Long clips and formats: Mara's persona with the old prompt ("bisher") --
foreach ($cases as $case) {
  $path = $out . '/wav/' . $case['file'];
  if (!is_file($path) && !spike_ffmpeg([...$case['args'], $path])) {
    $results['cases'][] = ['name'=>$case['name'], 'error'=>'ffmpeg fehlgeschlagen'];
    continue;
  }
  $bytes = (string)file_get_contents($path);
  $info = coreui_wav_info($bytes);
  spike_log("Fall {$case['name']}: " . ($info ? "{$info['seconds']} s, {$info['bits']} Bit, {$info['sample_rate']} Hz, "
    . "{$info['channels']} Kanal" : 'ohne RIFF-Header') . ', Pruefung: '
    . (coreui_wav_gemma_ready($info) ? 'passt' : coreui_wav_reject_reason($info)));
  $r = spike_core($apiBase, $model, $prompt, [base64_encode($bytes)], $personaNoThink);
  $results['cases'][] = ['name'=>$case['name'], 'wav'=>$info, 'check'=>coreui_wav_reject_reason($info)] + $r;
  spike_case_log($case['name'], $r);
}
// The same long clips with Google's template and a neutral SYSTEM text.
foreach ($durations as $sec) {
  $clip = $clipOf($sec);
  if ($clip === null) continue;
  $r = spike_core($apiBase, $model, $template, [$b64($clip)], $neutral);
  $results['cases'][] = ['name'=>"vorlage_{$sec}s", 'group'=>'long', 'seconds'=>$sec] + $r;
  spike_case_log("vorlage_{$sec}s", $r);
}

// Image and audio in the same request (videos with sound, P20.2).
$frame = $out . '/wav/frame.png';
$clip = $clipOf(15);
if (spike_ffmpeg(['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=1', '-frames:v', '1', $frame]) && $clip !== null) {
  spike_log('Fall Bild und Ton gemeinsam ...');
  $r = spike_core($apiBase, $model, 'Beschreibe in einem Satz, was auf dem Bild zu sehen ist. '
    . 'Schreibe danach woertlich auf, was in der Aufnahme gesagt wird.', [$b64($frame), $b64($clip)], $personaNoThink);
  $results['cases'][] = ['name'=>'bild_und_ton'] + $r;
  spike_case_log('bild_und_ton', $r);
}

// --- Short clips: which way of asking hears them reliably? -----------------
// 3 and 5 s were missed in 11 of 20 runs on 28.10.2026 (persona, old prompt).
$variants = [
  'bisher'         => fn(int $s): array => [$prompt, $clipOf($s), $personaNoThink, []],
  'vorlage'        => fn(int $s): array => [$template, $clipOf($s), $neutral, []],
  'vorlage_stille' => fn(int $s): array => [$template, $paddedOf($s), $neutral, []],
  'vorlage_temp0'  => fn(int $s): array => [$template, $clipOf($s), $neutral, ['temperature'=>0]],
];
for ($i = 1; $i <= $repeat; $i++) {
  foreach ($shortClips as $sec) {
    foreach ($variants as $variant => $build) {
      [$p, $file, $sys, $options] = $build($sec);
      if ($file === null) continue;
      $name = "kurz_{$sec}s_{$variant}_{$i}";
      $r = spike_core($apiBase, $model, $p, [$b64($file)], $sys, false, $options);
      $results['cases'][] = ['name'=>$name, 'group'=>'short', 'seconds'=>$sec, 'variant'=>$variant] + $r;
      spike_case_log($name, $r);
    }
  }
}

// --- Live reply (P20.4) ------------------------------------------------------
$liveSec = in_array(15, $durations, true) ? 15 : ($durations[0] ?? 15);
$results['live_seconds'] = $liveSec;
$liveClip = $clipOf($liveSec);
$liveAsk = 'Antworte so, wie du im Gespraech antworten wuerdest: kurz und natuerlich, hoechstens drei Saetze. '
  . 'Antworte direkt, ohne vorangestellte Analyse.';
$liveAudioPrompt = coreui_voice_attachment_hint($liveSec) . "\nDas ist eine Sprachnachricht an dich. " . $liveAsk;
if ($liveClip !== null) {
  $audio = [$b64($liveClip)];
  // think=true: the gemma4 renderer adds <|think|> itself (the switch in the chat).
  foreach ([['live_mit_denken', null, true], ['live_einstufig', $personaNoThink, false],
            ['live_einstufig_2', $personaNoThink, false]] as [$name, $sys, $think]) {
    $r = spike_core($apiBase, $model, $liveAudioPrompt, $audio, $sys, $think);
    $results['cases'][] = ['name'=>$name, 'group'=>'live', 'steps_s'=>null] + $r;
    spike_case_log($name, $r);
  }
  // Two steps: transcript with the template, then Mara answers the text.
  $step1 = spike_core($apiBase, $model, $template, $audio, $neutral);
  $heard = trim($step1['text']);
  $step2 = spike_core($apiBase, $model, "Sprachnachricht an dich (Mitschrift): \"{$heard}\"\n" . $liveAsk, [], $personaNoThink);
  $sentence = $step2['first_sentence_s'] !== null ? round($step1['wall_s'] + $step2['first_sentence_s'], 2) : null;
  $first = $step2['first_answer_s'] !== null ? round($step1['wall_s'] + $step2['first_answer_s'], 2) : null;
  $results['cases'][] = ['name'=>'live_zweistufig', 'group'=>'live', 'steps_s'=>[$step1['wall_s'], $step2['wall_s']],
    'transcript'=>$heard] + array_merge($step2, ['first_answer_s'=>$first, 'first_sentence_s'=>$sentence,
    'wall_s'=>round($step1['wall_s'] + $step2['wall_s'], 2), 'kind'=>$step1['kind']]);
  spike_case_log('live_zweistufig', $step2);
}

// --- TTS and image timings from media-spike.sh ------------------------------
$results['tts'] = [];
if (is_file($out . '/tts/timings.tsv')) {
  foreach (file($out . '/tts/timings.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
    [$name, $threads, $sentence, $wall, $rss, $file] = array_pad(explode("\t", $row), 6, '');
    $info = is_file($file) ? coreui_wav_info((string)file_get_contents($file)) : null;
    $seconds = (float)($info['seconds'] ?? 0);
    $results['tts'][] = ['candidate'=>$name, 'threads'=>(int)$threads, 'sentence'=>$sentence,
      'wall_s'=>(float)$wall, 'max_rss_mb'=>$rss !== '' ? round((int)$rss / 1024) : null,
      'audio_s'=>$seconds, 'rtf'=>$seconds > 0 ? round((float)$wall / $seconds, 3) : null,
      'file'=>basename($file)];
  }
}
$results['image'] = [];
if (is_file($out . '/image/timings.tsv')) {
  foreach (file($out . '/image/timings.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
    [$name, $threads, $wall, $rss, $file, $status] = array_pad(explode("\t", $row), 6, '');
    $results['image'][] = ['run'=>$name, 'threads'=>(int)$threads, 'wall_s'=>(float)$wall,
      'max_rss_mb'=>$rss !== '' ? round((int)$rss / 1024) : null, 'file'=>basename($file),
      'exists'=>is_file($file), 'exit'=>$status];
  }
}

// --- Summary -----------------------------------------------------------------
$results['finished'] = date('c');
$cell = static fn($v): string => str_replace(['|', "\n"], ['/', ' '], (string)($v ?? ''));
$de = static fn($v): string => $v === null ? '?' : str_replace('.', ',', (string)$v);
$byName = static function (string $name) use (&$results): ?array {
  foreach ($results['cases'] as $c) if ($c['name'] === $name) return $c;
  return null;
};
$avg = static function (array $cases, string $key): ?float {
  $v = array_values(array_filter(array_map(static fn(array $c) => $c[$key] ?? null, $cases), static fn($x) => $x !== null));
  return $v ? round(array_sum($v) / count($v), 1) : null;
};
$okCases = array_values(array_filter($results['cases'], static fn(array $c): bool =>
  str_starts_with($c['name'], 'ok_') && ($c['http'] ?? 0) === 200 && is_array($c['wav'] ?? null)));
usort($okCases, static fn(array $a, array $b): int => $a['wav']['seconds'] <=> $b['wav']['seconds']);
$summary = [];
if (count($okCases) >= 2) {
  $first = $okCases[0];
  $last = $okCases[count($okCases) - 1];
  $span = $last['wav']['seconds'] - $first['wav']['seconds'];
  if ($span > 0 && $first['prompt_tokens'] !== null && $last['prompt_tokens'] !== null) {
    $summary['audio_tokens_per_s'] = round(($last['prompt_tokens'] - $first['prompt_tokens']) / $span, 1);
  }
}
$rates = static function (array $cases, string $tokens, string $seconds): ?float {
  $t = 0; $s = 0.0;
  foreach ($cases as $c) {
    if (($c[$tokens] ?? null) === null || (float)($c[$seconds] ?? 0) <= 0) continue;
    $t += (int)$c[$tokens]; $s += (float)$c[$seconds];
  }
  return $s > 0 ? round($t / $s, 1) : null;
};
$summary['prompt_tokens_per_s'] = $rates($okCases, 'prompt_tokens', 'prompt_eval_s');
$summary['output_tokens_per_s'] = $rates($results['cases'], 'output_tokens', 'eval_s');
$summary['reloads'] = array_values(array_map(static fn(array $c): string => "{$c['name']} ({$c['load_s']} s)",
  array_filter($results['cases'], static fn(array $c): bool => (float)($c['load_s'] ?? 0) > 2.0)));
$summary['short'] = [];
foreach (array_keys($variants) as $variant) {
  $rows = array_values(array_filter($results['cases'], static fn(array $c): bool =>
    ($c['group'] ?? '') === 'short' && $c['variant'] === $variant));
  if (!$rows) continue;
  $count = static fn(string $kind): int => count(array_filter($rows, static fn(array $c): bool => $c['kind'] === $kind));
  $summary['short'][$variant] = ['runs'=>count($rows), 'heard'=>$count('heard'), 'missed'=>$count('missed'),
    'silence'=>$count('silence'), 'wall_s'=>$avg($rows, 'wall_s'), 'first_s'=>$avg($rows, 'first_answer_s')];
}
$liveThink = $byName('live_mit_denken');
$summary['think_switch'] = $liveThink === null ? null : (int)$liveThink['thinking_chars'] > 0;
$results['summary'] = $summary;
file_put_contents($out . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// --- Report ----------------------------------------------------------------
$md = "# CoreAI P20.0 Medien-Test\n\n- Zeit: {$results['started']} bis {$results['finished']}\n"
  . "- Core: `{$model}`, Ollama {$results['ollama_version']}\n- ffmpeg: {$results['ffmpeg']}\n"
  . "- Testaufnahme: {$results['source']}\n"
  . "- Modelfile-SYSTEM: {$results['system']['chars']} Zeichen, festes <|think|>: "
  . ($hasThinkToken ? 'ja (Core vor 0.5.75 erstellt: ollama create ausfuehren)' : 'nein') . "\n\n## Kurzfassung\n\n";
if ($summary['think_switch'] !== null) {
  $md .= '- Denkschalter: think=true liefert ' . ($summary['think_switch']
    ? "Denken ({$liveThink['thinking_chars']} Zeichen), der Schalter wirkt." : 'kein Denken, der Schalter wirkt nicht.') . "\n";
}
if (isset($summary['audio_tokens_per_s'])) $md .= "- Audio kostet {$de($summary['audio_tokens_per_s'])} Tokens pro Sekunde.\n";
$md .= "- Einlesen: {$de($summary['prompt_tokens_per_s'])} Tokens pro Sekunde, Ausgabe: {$de($summary['output_tokens_per_s'])} Tokens pro Sekunde.\n"
  . "- Kaltstart beim Aufwaermen: {$de($results['warmup']['load_s'])} s. Neu geladen waehrend des Tests: "
  . ($summary['reloads'] ? implode(', ', $summary['reloads']) : 'nie') . ".\n";

if ($summary['short']) {
  $md .= "\n### Kurze Aufnahmen (" . implode(' und ', array_map(static fn(int $s): string => "{$s} s", $shortClips))
    . ", je {$repeat}x)\n\n| Variante | Erkannt | Keine Aufnahme | Keine Sprache | Gesamt s (Mittel) | Erstes Wort s |\n|---|---|---|---|---|---|\n";
  foreach ($summary['short'] as $variant => $s) {
    $md .= "| {$variant} | {$s['heard']} von {$s['runs']} | {$s['missed']} | {$s['silence']} | {$de($s['wall_s'])} | {$de($s['first_s'])} |\n";
  }
  $md .= "\nbisher: Maras Persona mit dem alten Auftrag. vorlage: Googles Transkriptionsvorlage mit neutralem SYSTEM-Text. "
    . "stille: 1 s Stille davor. temp0: temperature 0 (nur in diesem Test).\n";
}

$live = array_values(array_filter($results['cases'], static fn(array $c): bool => ($c['group'] ?? '') === 'live'));
if ($live) {
  $md .= "\n### Live-Antwort auf {$liveSec} s\n\n| Weg | Erstes Wort s | Erster gesprochener Satz s | Gesamt s | Antwort |\n|---|---|---|---|---|\n";
  foreach ($live as $c) {
    $md .= "| {$c['name']} | {$cell($c['first_answer_s'])} | {$cell($c['first_sentence_s'])} | {$cell($c['wall_s'])} | "
      . $cell(mb_substr(trim(ember_strip_leading_bracket_meta($c['text'])), 0, 160)) . " |\n";
  }
  $two = $byName('live_zweistufig');
  if ($two !== null) {
    $md .= "\nzweistufig: Mitschrift {$de($two['steps_s'][0])} s, dann Antwort {$de($two['steps_s'][1])} s. "
      . 'Gehoert: "' . $cell(mb_substr($two['transcript'], 0, 160)) . "\"\n";
  }
}

$md .= "\n## Lange Aufnahmen\n\n| Fall | Datei | Pruefung | HTTP | Laden s | Eingabe s | Erstes Wort s | Gesamt s | Tokens ein/aus | Ergebnis | Antwort (Anfang) |\n"
  . "|---|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($results['cases'] as $c) {
  if (isset($c['group']) && $c['group'] !== 'long') continue;
  $wav = is_array($c['wav'] ?? null) ? "{$c['wav']['seconds']} s, {$c['wav']['bits']} Bit, {$c['wav']['sample_rate']} Hz, {$c['wav']['channels']} Kan." : '';
  $check = isset($c['wav']) || array_key_exists('check', $c) ? (($c['check'] ?? '') ?: 'passt') : '';
  $md .= "| {$c['name']} | {$wav} | {$check} | " . $cell($c['http'] ?? '') . ' | ' . $cell($c['load_s'] ?? '')
    . ' | ' . $cell($c['prompt_eval_s'] ?? '') . ' | ' . $cell($c['first_answer_s'] ?? '') . ' | ' . $cell($c['wall_s'] ?? '')
    . ' | ' . $cell($c['prompt_tokens'] ?? '') . '/' . $cell($c['output_tokens'] ?? '') . ' | '
    . $cell(($c['error'] ?? '') !== '' ? $c['error'] : ($c['kind'] ?? '')) . ' | ' . $cell(mb_substr((string)($c['text'] ?? ''), 0, 100)) . " |\n";
}
if ($okCases) {
  $longest = $okCases[count($okCases) - 1];
  $sec = (int)round((float)$longest['wav']['seconds']);
  $md .= "\n### Vollstaendige Mitschrift {$sec} s, bisher\n\n" . trim($longest['text']) . "\n";
  $tpl = $byName("vorlage_{$sec}s");
  if ($tpl !== null) $md .= "\n### Vollstaendige Mitschrift {$sec} s, vorlage\n\n" . trim($tpl['text']) . "\n";
}

$failures = array_values(array_filter($results['cases'], static fn(array $c): bool =>
  ($c['group'] ?? '') === 'short' && $c['kind'] !== 'heard'));
if ($failures) {
  $md .= "\n## Fehlantworten kurzer Aufnahmen\n\n";
  foreach ($failures as $c) $md .= "- {$c['name']}: " . $cell(mb_substr($c['text'], 0, 90)) . "\n";
}

$md .= "\n## Faehigkeiten laut /api/show\n\n| Core | Groesse | Quant. | Gewichte | GB | capabilities |\n|---|---|---|---|---|---|\n";
foreach ($results['capabilities'] as $name => $c) {
  $md .= "| `{$name}` | {$c['parameter_size']} | {$c['quantization']} | {$c['weights']} | {$de($c['size_gb'])} | "
    . json_encode($c['capabilities']) . " |\n";
}
if ($results['tts']) {
  $md .= "\n## Sprachausgabe (TTS)\n\n| Kandidat | Kerne | Satz | Rechenzeit s | Audio s | Echtzeitfaktor | max. RAM MB |\n|---|---|---|---|---|---|---|\n";
  foreach ($results['tts'] as $t) {
    $md .= "| {$t['candidate']} | {$t['threads']} | {$t['sentence']} | {$t['wall_s']} | {$t['audio_s']} | {$t['rtf']} | {$t['max_rss_mb']} |\n";
  }
  $md .= "\nEchtzeitfaktor unter 1 bedeutet: schneller erzeugt als abgespielt.\n";
}
if ($results['image']) {
  $md .= "\n## Bildgenerierung\n\n| Lauf | Kerne | Zeit s | max. RAM MB | Datei vorhanden | Exitcode |\n|---|---|---|---|---|---|\n";
  foreach ($results['image'] as $i) {
    $md .= "| {$i['run']} | {$i['threads']} | {$i['wall_s']} | {$i['max_rss_mb']} | " . ($i['exists'] ? 'ja' : 'nein') . " | {$i['exit']} |\n";
  }
}
if (is_file($out . '/host.txt')) $md .= "\n## Server\n\n```\n" . trim((string)file_get_contents($out . '/host.txt')) . "\n```\n";
file_put_contents($out . '/report.md', $md);
spike_log("\nFertig. Bericht: {$out}/report.md (Rohdaten: results.json)");
