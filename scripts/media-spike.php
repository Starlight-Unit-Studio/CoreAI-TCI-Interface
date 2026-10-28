<?php
declare(strict_types=1);
/*
 * CoreAI 0.5.73: P20.0 media test on the real server (voice plan).
 *
 * Measures Gemma 4 audio input through Ollama: accepted formats, clip
 * length, latency and transcript; one combined image plus audio request.
 * Optional TTS and image timings prepared by scripts/media-spike.sh are
 * added to the same report. Changes no model, Modelfile or database row;
 * every Core call waits in Mara's serial model queue like a chat turn.
 *
 * Usage (normally started by scripts/media-spike.sh):
 *   php scripts/media-spike.php --out var/cache/media-spike/STAMP [--model NAME]
 *       [--audio FILE] [--durations 5,15,30,45,60] [--skip-formats]
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/audio_wav.php';

$opt = getopt('', ['out:', 'model:', 'audio:', 'durations:', 'skip-formats']);
$out = rtrim((string)($opt['out'] ?? ($root . '/var/cache/media-spike/' . date('Ymd-His'))), '/');
if (!str_starts_with($out, '/')) $out = $root . '/' . $out;
$model = trim((string)($opt['model'] ?? ember_model()));
$durations = array_values(array_filter(array_map('intval',
  explode(',', (string)($opt['durations'] ?? '5,15,30,45,60'))), static fn(int $s): bool => $s >= 1 && $s <= 300));
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

function spike_ffmpeg(array $args): bool {
  $cmd = 'ffmpeg -nostdin -hide_banner -loglevel error -y ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
  exec($cmd, $lines, $status);
  if ($status !== 0) spike_log('  ffmpeg: ' . implode(' | ', array_slice($lines, 0, 3)));
  return $status === 0;
}

/** One Core call in Mara's queue: audio (and optional image) plus a prompt. */
function spike_core(string $apiBase, string $model, string $prompt, array $images, int $timeout = 900): array {
  $lock = ember_global_lock_acquire(1800);
  if ($lock === false) spike_log('  Hinweis: Warteschlange nicht erreichbar, Aufruf laeuft ohne Einreihung.');
  try {
    $r = spike_http('POST', $apiBase . '/api/chat', [
      'model'=>$model, 'stream'=>false, 'think'=>false, 'keep_alive'=>'15m',
      'messages'=>[['role'=>'user', 'content'=>$prompt] + ($images ? ['images'=>$images] : [])],
    ], $timeout);
  } finally {
    if ($lock !== false) ember_generation_lock_release($lock);
  }
  $j = $r['json'] ?? [];
  $ns = static fn(string $k): ?float => isset($j[$k]) ? round((int)$j[$k] / 1e9, 2) : null;
  return [
    'http'=>$r['code'], 'wall_s'=>$r['wall_s'], 'error'=>(string)($j['error'] ?? $r['curl_error'] ?? ''),
    'load_s'=>$ns('load_duration'), 'prompt_eval_s'=>$ns('prompt_eval_duration'), 'eval_s'=>$ns('eval_duration'),
    'prompt_tokens'=>$j['prompt_eval_count'] ?? null, 'output_tokens'=>$j['eval_count'] ?? null,
    'done_reason'=>(string)($j['done_reason'] ?? ''),
    'text'=>trim((string)($j['message']['content'] ?? '')),
  ];
}

// --- Environment -----------------------------------------------------------
spike_log("CoreAI P20.0 Medien-Test\nAusgabe: {$out}\nCore: {$model}\nOllama: {$apiBase}");
exec('ffmpeg -version 2>/dev/null', $ff);
$results['ffmpeg'] = $ff[0] ?? 'nicht gefunden';
if (!isset($ff[0])) { fwrite(STDERR, "ffmpeg fehlt; Audio-Test nicht moeglich.\n"); exit(1); }
$results['ollama_version'] = spike_http('GET', $apiBase . '/api/version', null, 10)['json']['version'] ?? 'unbekannt';
$tags = spike_http('GET', $apiBase . '/api/tags', null, 15)['json']['models'] ?? [];
$names = array_values(array_unique(array_merge([$model], array_values(array_filter(array_map(
  static fn($m) => (string)($m['name'] ?? ''), is_array($tags) ? $tags : []),
  static fn(string $n): bool => stripos($n, 'gemma4') === 0)))));
$results['capabilities'] = [];
foreach ($names as $name) {
  $show = spike_http('POST', $apiBase . '/api/show', ['model'=>$name], 30)['json'] ?? [];
  $info = is_array($show['model_info'] ?? null) ? $show['model_info'] : [];
  $audioKeys = array_values(array_filter(array_keys($info), static fn(string $k): bool => stripos($k, 'audio') !== false));
  $results['capabilities'][$name] = [
    'capabilities'=>$show['capabilities'] ?? null,
    'parameter_size'=>$show['details']['parameter_size'] ?? null,
    'quantization'=>$show['details']['quantization_level'] ?? null,
    'audio_metadata_keys'=>array_slice($audioKeys, 0, 8),
  ];
  spike_log("  {$name}: Faehigkeiten " . json_encode($show['capabilities'] ?? 'keine Angabe')
    . ', Audio-Metadaten: ' . count($audioKeys));
}

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
  spike_log("  HTTP {$r['http']}, {$r['wall_s']} s, Eingabe-Tokens {$r['prompt_tokens']}"
    . ($r['error'] !== '' ? ", Fehler: {$r['error']}" : '') . ' | ' . mb_substr($r['text'], 0, 90));
}

// Image and audio in the same request (videos with sound, P20.2).
$frame = $out . '/wav/frame.png';
$clip = $out . '/wav/ok_15s.wav';
if (!is_file($clip)) spike_ffmpeg(['-stream_loop', '-1', '-i', $source, '-t', '15', ...coreui_wav_ffmpeg_args(), $clip]);
if (spike_ffmpeg(['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=1', '-frames:v', '1', $frame]) && is_file($clip)) {
  spike_log('Fall Bild und Ton gemeinsam ...');
  $r = spike_core($apiBase, $model, 'Beschreibe in einem Satz, was auf dem Bild zu sehen ist. '
    . 'Schreibe danach woertlich auf, was in der Aufnahme gesagt wird.',
    [base64_encode((string)file_get_contents($frame)), base64_encode((string)file_get_contents($clip))]);
  $results['cases'][] = ['name'=>'bild_und_ton'] + $r;
  spike_log("  HTTP {$r['http']}, {$r['wall_s']} s | " . mb_substr($r['text'], 0, 90));
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

// --- Report ----------------------------------------------------------------
$results['finished'] = date('c');
file_put_contents($out . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$md = "# CoreAI P20.0 Medien-Test\n\n- Zeit: {$results['started']} bis {$results['finished']}\n"
  . "- Core: `{$model}`, Ollama {$results['ollama_version']}\n- ffmpeg: {$results['ffmpeg']}\n"
  . "- Testaufnahme: {$results['source']}\n\n## Faehigkeiten laut /api/show\n\n| Core | Groesse | Quant. | capabilities | Audio-Metadaten |\n|---|---|---|---|---|\n";
foreach ($results['capabilities'] as $name => $c) {
  $md .= "| `{$name}` | {$c['parameter_size']} | {$c['quantization']} | " . json_encode($c['capabilities'])
    . ' | ' . count($c['audio_metadata_keys']) . " |\n";
}
$w = $results['warmup'];
$md .= "\nAufwaermen: HTTP {$w['http']}, {$w['wall_s']} s, Laden {$w['load_s']} s\n\n## Audio\n\n"
  . "| Fall | Datei | Pruefung | HTTP | Gesamt s | Eingabe s | Eingabe-Tokens | Ausgabe-Tokens | Fehler | Antwort (Anfang) |\n|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($results['cases'] as $c) {
  $wav = is_array($c['wav'] ?? null) ? "{$c['wav']['seconds']} s, {$c['wav']['bits']} Bit, {$c['wav']['sample_rate']} Hz, {$c['wav']['channels']} Kan." : '';
  $text = str_replace(['|', "\n"], ['/', ' '], mb_substr((string)($c['text'] ?? ''), 0, 160));
  $check = isset($c['wav']) || array_key_exists('check', $c) ? (($c['check'] ?? '') ?: 'passt') : '';
  $md .= "| {$c['name']} | {$wav} | {$check} | " . ($c['http'] ?? '') . ' | ' . ($c['wall_s'] ?? '')
    . ' | ' . ($c['prompt_eval_s'] ?? '') . ' | ' . ($c['prompt_tokens'] ?? '') . ' | ' . ($c['output_tokens'] ?? '')
    . ' | ' . str_replace('|', '/', (string)($c['error'] ?? '')) . " | {$text} |\n";
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
