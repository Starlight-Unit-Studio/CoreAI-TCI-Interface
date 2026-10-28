<?php
declare(strict_types=1);
/*
 * CoreAI 0.5.74: P20.0 media test on the real server (voice plan).
 *
 * Measures Gemma 4 audio input through Ollama: accepted formats, clip
 * length, time to the first answer word and transcript; one combined image
 * plus audio request. Since 0.5.74 also the cost of thinking (the same clip
 * with the Modelfile SYSTEM text minus <|think|>), a live reply with and
 * without thinking, and short clips repeated with and without naming the
 * attachment. Optional TTS and image timings prepared by
 * scripts/media-spike.sh are added to the same report. Changes no model,
 * Modelfile or database row; every Core call waits in Mara's serial model
 * queue like a chat turn and keeps CoreAI's keep_alive.
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
    // First full sentence: what a sentence-wise voice (P20.3/P20.4) could start speaking.
    if ($state['sentence_s'] === null && mb_strlen(trim($state['text'])) >= 12
      && preg_match('~[.!?](?:\s|$)~u', $state['text']) === 1) $state['sentence_s'] = $now;
  }
  if (!empty($j['done'])) $state['final'] = $j;
}

/** Streams one /api/chat call and notes when the first answer word and sentence arrived. */
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

/** One Core call in Mara's queue: audio (and optional image), a prompt and optionally a SYSTEM text. */
function spike_core(string $apiBase, string $model, string $prompt, array $images, ?string $system = null, int $timeout = 900): array {
  $messages = $system !== null ? [['role'=>'system', 'content'=>$system]] : [];
  $messages[] = ['role'=>'user', 'content'=>$prompt] + ($images ? ['images'=>$images] : []);
  $body = ['model'=>$model, 'stream'=>true, 'think'=>false, 'messages'=>$messages];
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
    'text'=>$text, 'thinking'=>mb_substr(trim($r['thinking']), 0, 4000),
  ];
}

function spike_case_log(string $name, array $r): void {
  spike_log("  {$name}: HTTP {$r['http']}, {$r['wall_s']} s, erste Antwort " . ($r['first_answer_s'] ?? '-')
    . ' s, Tokens ' . ($r['prompt_tokens'] ?? '-') . '/' . ($r['output_tokens'] ?? '-')
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
// The digest shows whether several names point to the same weights.
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
  $info = is_array($show['model_info'] ?? null) ? $show['model_info'] : [];
  $audioKeys = array_values(array_filter(array_keys($info), static fn(string $k): bool => stripos($k, 'audio') !== false));
  $tag = $tagInfo[$name] ?? $tagInfo[$name . ':latest'] ?? ['digest'=>'', 'size_gb'=>null];
  $results['capabilities'][$name] = [
    'capabilities'=>$show['capabilities'] ?? null,
    'parameter_size'=>$show['details']['parameter_size'] ?? null,
    'quantization'=>$show['details']['quantization_level'] ?? null,
    'digest'=>$tag['digest'], 'size_gb'=>$tag['size_gb'],
    'audio_metadata_keys'=>array_slice($audioKeys, 0, 8),
  ];
  spike_log("  {$name}: Faehigkeiten " . json_encode($show['capabilities'] ?? 'keine Angabe')
    . ", Digest {$tag['digest']}");
}
// Read only: the live-talk comparison sends the same SYSTEM text minus <|think|>.
$system = coreui_voice_modelfile_system(is_array($modelShow) ? $modelShow : []);
$noThinkSystem = $system !== '' ? coreui_voice_system_without_think($system) : null;
$results['system'] = ['chars'=>mb_strlen($system), 'has_think'=>str_contains($system, '<|think|>')];

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

foreach ($cases as $case) {
  $path = $out . '/wav/' . $case['file'];
  if (!spike_ffmpeg([...$case['args'], $path])) {
    $results['cases'][] = ['name'=>$case['name'], 'error'=>'ffmpeg fehlgeschlagen'];
    continue;
  }
  $bytes = (string)file_get_contents($path);
  $info = coreui_wav_info($bytes);
  spike_log("Fall {$case['name']}: " . ($info ? "{$info['seconds']} s, {$info['bits']} Bit, {$info['sample_rate']} Hz, "
    . "{$info['channels']} Kanal" : 'ohne RIFF-Header') . ', Pruefung: '
    . (coreui_wav_gemma_ready($info) ? 'passt' : coreui_wav_reject_reason($info)));
  $r = spike_core($apiBase, $model, $prompt, [base64_encode($bytes)]);
  $results['cases'][] = ['name'=>$case['name'], 'wav'=>$info, 'check'=>coreui_wav_reject_reason($info)] + $r;
  spike_case_log($case['name'], $r);
}

// Image and audio in the same request (videos with sound, P20.2).
$frame = $out . '/wav/frame.png';
$clip = $clipOf(15);
if (spike_ffmpeg(['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=1', '-frames:v', '1', $frame]) && $clip !== null) {
  spike_log('Fall Bild und Ton gemeinsam ...');
  $r = spike_core($apiBase, $model, 'Beschreibe in einem Satz, was auf dem Bild zu sehen ist. '
    . 'Schreibe danach woertlich auf, was in der Aufnahme gesagt wird.',
    [base64_encode((string)file_get_contents($frame)), base64_encode((string)file_get_contents($clip))]);
  $results['cases'][] = ['name'=>'bild_und_ton'] + $r;
  spike_case_log('bild_und_ton', $r);
}

// --- Thinking and live reply (P20.4) ----------------------------------------
// Same clip as ok_15s: once without <|think|> for the transcript, then a short
// spoken-style reply with and without thinking.
$compareSec = in_array(15, $durations, true) ? 15 : ($durations[0] ?? 15);
$results['compare_seconds'] = $compareSec;
$compareClip = $clipOf($compareSec);
$livePrompt = coreui_voice_attachment_hint($compareSec) . "\nDas ist eine Sprachnachricht an dich. "
  . 'Antworte so, wie du im Gespraech antworten wuerdest: kurz und natuerlich, hoechstens drei Saetze.';
if ($compareClip === null) {
  spike_log('Vergleich ohne Denken uebersprungen: kein Clip.');
} elseif ($noThinkSystem === null) {
  spike_log('Vergleich ohne Denken uebersprungen: der Core hat keinen SYSTEM-Text im Modelfile.');
} else {
  $audio = [base64_encode((string)file_get_contents($compareClip))];
  foreach ([
    ['mitschrift_ohne_denken', $prompt, $noThinkSystem],
    ['live_mit_denken', $livePrompt, null],
    ['live_ohne_denken', $livePrompt, $noThinkSystem],
  ] as [$name, $p, $sys]) {
    spike_log("Fall {$name} ({$compareSec} s) ...");
    $r = spike_core($apiBase, $model, $p, $audio, $sys);
    $results['cases'][] = ['name'=>$name, 'group'=>'compare'] + $r;
    spike_case_log($name, $r);
  }
}

// --- Short clips: does Core notice the recording? ---------------------------
// A 5 s clip was once answered with "Bitte stellen Sie die Aufnahme zur
// Verfuegung"; each length runs with and without naming the attachment.
foreach ($shortClips as $sec) {
  $short = $repeat > 0 ? $clipOf($sec) : null;
  if ($short === null) continue;
  $audio = [base64_encode((string)file_get_contents($short))];
  for ($i = 1; $i <= $repeat; $i++) {
    foreach (['ohne_hinweis'=>$prompt, 'mit_hinweis'=>coreui_voice_attachment_hint($sec) . "\n" . $prompt] as $variant => $p) {
      $name = "kurz_{$sec}s_{$variant}_{$i}";
      $r = spike_core($apiBase, $model, $p, $audio);
      $missed = coreui_voice_missed_audio($r['text']);
      $results['cases'][] = ['name'=>$name, 'group'=>'short', 'seconds'=>$sec, 'variant'=>$variant, 'missed'=>$missed] + $r;
      spike_case_log($name . ($missed ? ' (Audio uebersehen)' : ''), $r);
    }
  }
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
$okCases = array_values(array_filter($results['cases'], static fn(array $c): bool =>
  str_starts_with($c['name'], 'ok_') && ($c['http'] ?? 0) === 200 && is_array($c['wav'] ?? null)));
$summary = [];
if (count($okCases) >= 2) {
  usort($okCases, static fn(array $a, array $b): int => $a['wav']['seconds'] <=> $b['wav']['seconds']);
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
foreach ($results['cases'] as $c) {
  if (($c['group'] ?? '') !== 'short') continue;
  $key = "{$c['seconds']} s " . ($c['variant'] === 'mit_hinweis' ? 'mit Hinweis' : 'ohne Hinweis');
  $summary['short'][$key] ??= ['missed'=>0, 'runs'=>0];
  $summary['short'][$key]['runs']++;
  if (!empty($c['missed'])) $summary['short'][$key]['missed']++;
}
$results['summary'] = $summary;
file_put_contents($out . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// --- Report ----------------------------------------------------------------
$md = "# CoreAI P20.0 Medien-Test\n\n- Zeit: {$results['started']} bis {$results['finished']}\n"
  . "- Core: `{$model}`, Ollama {$results['ollama_version']}\n- ffmpeg: {$results['ffmpeg']}\n"
  . "- Testaufnahme: {$results['source']}\n"
  . "- Modelfile-SYSTEM: {$results['system']['chars']} Zeichen, Denk-Schalter <|think|>: "
  . ($results['system']['has_think'] ? 'ja' : 'nein') . "\n\n## Kurzfassung\n\n";
if (isset($summary['audio_tokens_per_s'])) $md .= "- Audio kostet {$de($summary['audio_tokens_per_s'])} Tokens pro Sekunde.\n";
$md .= "- Einlesen: {$de($summary['prompt_tokens_per_s'])} Tokens pro Sekunde, Ausgabe: {$de($summary['output_tokens_per_s'])} Tokens pro Sekunde.\n"
  . "- Kaltstart beim Aufwaermen: {$de($results['warmup']['load_s'])} s. Neu geladen waehrend des Tests: "
  . ($summary['reloads'] ? implode(', ', $summary['reloads']) : 'nie') . ".\n";
$ref = $byName("ok_{$compareSec}s");
$plain = $byName('mitschrift_ohne_denken');
if ($ref !== null && $plain !== null) {
  $md .= "- Mitschrift {$compareSec} s mit Denken: erstes Wort nach {$de($ref['first_answer_s'])} s, {$de($ref['output_tokens'])} Ausgabe-Tokens; "
    . "ohne Denken: nach {$de($plain['first_answer_s'])} s, {$de($plain['output_tokens'])} Ausgabe-Tokens.\n";
}
$liveThink = $byName('live_mit_denken');
$liveFast = $byName('live_ohne_denken');
if ($liveThink !== null && $liveFast !== null) {
  $md .= "- Live-Antwort auf {$compareSec} s: erster Satz mit Denken nach {$de($liveThink['first_sentence_s'])} s, "
    . "ohne Denken nach {$de($liveFast['first_sentence_s'])} s.\n";
}
foreach ($summary['short'] as $key => $s) {
  $md .= "- Kurze Aufnahme {$key}: Audio {$s['missed']} von {$s['runs']} Mal uebersehen.\n";
}

$md .= "\n## Faehigkeiten laut /api/show\n\n| Core | Groesse | Quant. | Digest | GB | capabilities |\n|---|---|---|---|---|---|\n";
foreach ($results['capabilities'] as $name => $c) {
  $md .= "| `{$name}` | {$c['parameter_size']} | {$c['quantization']} | {$c['digest']} | {$de($c['size_gb'])} | "
    . json_encode($c['capabilities']) . " |\n";
}
$w = $results['warmup'];
$md .= "\nAufwaermen: HTTP {$w['http']}, {$w['wall_s']} s, Laden {$w['load_s']} s\n\n## Audio\n\n"
  . "| Fall | Datei | Pruefung | HTTP | Laden s | Eingabe s | Erstes Wort s | Gesamt s | Tokens ein/aus | Denken Zeichen | Fehler | Antwort (Anfang) |\n"
  . "|---|---|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($results['cases'] as $c) {
  if (isset($c['group'])) continue;
  $wav = is_array($c['wav'] ?? null) ? "{$c['wav']['seconds']} s, {$c['wav']['bits']} Bit, {$c['wav']['sample_rate']} Hz, {$c['wav']['channels']} Kan." : '';
  $check = isset($c['wav']) || array_key_exists('check', $c) ? (($c['check'] ?? '') ?: 'passt') : '';
  $md .= "| {$c['name']} | {$wav} | {$check} | " . $cell($c['http'] ?? '') . ' | ' . $cell($c['load_s'] ?? '')
    . ' | ' . $cell($c['prompt_eval_s'] ?? '') . ' | ' . $cell($c['first_answer_s'] ?? '') . ' | ' . $cell($c['wall_s'] ?? '')
    . ' | ' . $cell($c['prompt_tokens'] ?? '') . '/' . $cell($c['output_tokens'] ?? '') . ' | ' . $cell($c['thinking_chars'] ?? '')
    . ' | ' . $cell($c['error'] ?? '') . ' | ' . $cell(mb_substr((string)($c['text'] ?? ''), 0, 120)) . " |\n";
}

$compare = array_values(array_filter($results['cases'], static fn(array $c): bool => ($c['group'] ?? '') === 'compare'));
if ($compare) {
  $md .= "\n## Denken und Live-Antwort ({$compareSec} s)\n\n"
    . "| Fall | Laden s | Eingabe s | Erstes Wort s | Erster Satz s | Gesamt s | Tokens aus | Denken Zeichen | Antwort |\n|---|---|---|---|---|---|---|---|---|\n";
  foreach (array_merge($ref !== null ? [$ref] : [], $compare) as $c) {
    $md .= "| {$c['name']} | " . $cell($c['load_s']) . ' | ' . $cell($c['prompt_eval_s']) . ' | ' . $cell($c['first_answer_s'])
      . ' | ' . $cell($c['first_sentence_s']) . ' | ' . $cell($c['wall_s']) . ' | ' . $cell($c['output_tokens'])
      . ' | ' . $cell($c['thinking_chars']) . ' | '
      . $cell(str_starts_with($c['name'], 'live_') ? $c['text'] : mb_substr($c['text'], 0, 120)) . " |\n";
  }
}

$shortRows = array_values(array_filter($results['cases'], static fn(array $c): bool => ($c['group'] ?? '') === 'short'));
if ($shortRows) {
  $md .= "\n## Kurze Aufnahmen\n\n| Fall | Gesamt s | Tokens aus | Audio uebersehen | Antwort (Anfang) |\n|---|---|---|---|---|\n";
  foreach ($shortRows as $c) {
    $md .= "| {$c['name']} | " . $cell($c['wall_s']) . ' | ' . $cell($c['output_tokens']) . ' | '
      . ($c['missed'] ? 'ja' : 'nein') . ' | ' . $cell(mb_substr($c['text'], 0, 80)) . " |\n";
  }
}

if ($okCases) {
  $longest = $okCases[count($okCases) - 1];
  $md .= "\n## Vollstaendige Mitschrift ({$longest['name']})\n\n" . trim($longest['text']) . "\n";
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
