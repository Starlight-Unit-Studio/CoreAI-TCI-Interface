<?php
declare(strict_types=1);

/*
 * 0.5.76: Auto-Installer for the TCI tiers, for operators without SSH.
 *
 * A local tier missing in Ollama shows greyed in the chat Core menu. Its
 * window gives a short hardware check with a recommendation, and an admin
 * can install it from there: Ollama pulls the Gemma base (/api/pull), then
 * a Core is created from config/Modelfile.template with only FROM swapped,
 * the same way scripts/install-native.sh builds Mara's own Core
 * (/api/create). The Modelfile itself is never changed.
 *
 * One install at a time (flock). The job runs after the answer went out
 * (like the Core preload) and reports into var/cache/core-install.json,
 * which the window polls. Pull and create do not wait in Mara's serial
 * queue: they load nothing into memory.
 */

require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/ai_context_estimate.php';

const COREUI_CORE_INSTALL_PERMISSION_MAX = 1; // admin and system admin
const COREUI_CORE_INSTALL_ACTIVE = ['pull', 'create', 'verify'];

/** Core name for an installed tier: Mara's family with the size as tag, e.g. ember-coreui:12b. */
function coreui_core_install_name(string $tier): string {
  $size = array_search($tier, COREUI_CORE_TIER_SIZES, true);
  if (!is_string($size) || !isset(COREUI_CORE_INSTALL_CATALOG[$tier])) throw new RuntimeException('unknown_tier');
  return coreui_core_mara_family() . ':' . $size;
}

/** Modelfile commands in order as [COMMAND, argument]; triple quotes may span lines. */
function coreui_modelfile_commands(string $text): array {
  $lines = preg_split('~\R~', $text) ?: [];
  $out = [];
  for ($i = 0, $n = count($lines); $i < $n; $i++) {
    $line = ltrim($lines[$i]);
    if ($line === '' || $line[0] === '#') continue;
    if (preg_match('~^([A-Za-z]+)[ \t]+(.*)$~', $line, $m) !== 1) throw new RuntimeException('modelfile_syntax');
    $arg = $m[2];
    if (str_starts_with($arg, '"""')) {
      $rest = substr($arg, 3);
      $parts = [];
      while (($end = strpos($rest, '"""')) === false) {
        $parts[] = $rest;
        if (++$i >= $n) throw new RuntimeException('modelfile_unclosed');
        $rest = $lines[$i];
      }
      $parts[] = substr($rest, 0, $end);
      $arg = implode("\n", $parts);
    } else {
      $arg = coreui_modelfile_unquote(trim($arg));
    }
    $out[] = [strtoupper($m[1]), $arg];
  }
  return $out;
}

function coreui_modelfile_unquote(string $value): string {
  return (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') ? substr($value, 1, -1) : $value;
}

/** PARAMETER values typed like Ollama's parser does: numbers, booleans, else text. */
function coreui_modelfile_value(string $raw): int|float|bool|string {
  if (preg_match('~^-?\d+$~', $raw) === 1) return (int)$raw;
  if (is_numeric($raw)) return (float)$raw;
  $lower = strtolower($raw);
  if ($lower === 'true' || $lower === 'false') return $lower === 'true';
  return coreui_modelfile_unquote($raw);
}

/**
 * /api/create body for a Core from Mara's Modelfile: everything as written,
 * only FROM points to the tier's base. An unknown command stops the install
 * instead of creating a Core that differs from the template.
 */
function coreui_core_create_request(string $modelfile, string $name, string $base): array {
  $request = ['model'=>$name, 'from'=>$base];
  $from = 0;
  foreach (coreui_modelfile_commands($modelfile) as [$command, $arg]) {
    switch ($command) {
      case 'FROM': $from++; break;
      case 'TEMPLATE': $request['template'] = $arg; break;
      case 'SYSTEM': $request['system'] = $arg; break;
      case 'RENDERER': $request['renderer'] = $arg; break;
      case 'PARSER': $request['parser'] = $arg; break;
      case 'LICENSE': $request['license'][] = $arg; break;
      case 'MESSAGE':
        if (preg_match('~^(system|user|assistant)[ \t]+(.*)$~s', $arg, $m) !== 1) throw new RuntimeException('modelfile_message_invalid');
        $request['messages'][] = ['role'=>$m[1], 'content'=>coreui_modelfile_unquote($m[2])];
        break;
      case 'PARAMETER':
        if (preg_match('~^([A-Za-z_][A-Za-z0-9_]*)[ \t]+(.+)$~s', $arg, $m) !== 1) throw new RuntimeException('modelfile_parameter_invalid');
        $key = strtolower($m[1]);
        if ($key === 'stop') $request['parameters']['stop'][] = coreui_modelfile_unquote(trim($m[2]));
        else $request['parameters'][$key] = coreui_modelfile_value(trim($m[2]));
        break;
      default:
        throw new RuntimeException('modelfile_command_unsupported');
    }
  }
  if ($from !== 1) throw new RuntimeException('modelfile_from_missing');
  $request['stream'] = false;
  return $request;
}

/** What the PHP side can see of the server. Every value may be null when unreadable. */
function coreui_core_install_hardware(): array {
  $meminfo = (string)@file_get_contents('/proc/meminfo');
  $cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
  $root = dirname(__DIR__);
  $free = @disk_free_space($root);
  $flags = preg_match('~^(?:flags|Features)\s*:\s*(.*)$~mi', $cpuinfo, $f) === 1 ? ' ' . strtolower($f[1]) . ' ' : '';
  $threads = preg_match_all('~^processor\s*:~mi', $cpuinfo);
  $version = coreui_ollama_read_json('version');
  $ps = coreui_ollama_read_json('ps');
  $loaded = is_array($ps['models'] ?? null) ? $ps['models'] : [];
  $gpu = null;
  foreach ($loaded as $row) {
    if (!is_array($row)) continue;
    $gpu = ($gpu === true) || (int)($row['size_vram'] ?? 0) > 0;
  }
  return [
    'mem_total' => preg_match('~^MemTotal:\s+(\d+)\s+kB~mi', $meminfo, $m) === 1 ? (int)$m[1] * 1024 : null,
    'mem_available' => coreui_mem_available_bytes($meminfo),
    'threads' => $threads > 0 ? $threads : null,
    'avx2' => $flags !== '' ? str_contains($flags, ' avx2 ') : null,
    'avx512' => $flags !== '' ? str_contains($flags, ' avx512f ') : null,
    'disk_free' => is_float($free) || is_int($free) ? (int)$free : null,
    'ollama' => is_array($version) && isset($version['version']) ? (string)$version['version'] : null,
    'gpu' => $gpu,
  ];
}

/** GB as the chat shows them: one decimal below 10, whole numbers above. */
function coreui_core_install_gb(float $bytes): string {
  $gb = $bytes / COREUI_GIB;
  return ($gb < 10 ? str_replace('.', ',', (string)round($gb, 1)) : (string)(int)round($gb)) . ' GB';
}

/**
 * The mini hardware check, a pure function: rows with ok, warn or bad and a
 * verdict (gut, knapp, abraten). Rough values, never a hardware promise:
 * weights plus a fifth plus 2 GB for runtime and a moderate context window.
 */
function coreui_core_install_verdict(array $hw, array $entry): array {
  $download = (float)$entry['download_gb'] * 1e9;
  $need = $download * 1.2 + 2 * COREUI_GIB;
  $level = 0;
  $rows = [];
  $row = static function (string $key, string $label, string $value, string $state) use (&$rows, &$level): void {
    $rows[] = ['key'=>$key, 'label'=>$label, 'value'=>$value, 'state'=>$state];
    $level = max($level, $state === 'bad' ? 2 : ($state === 'warn' ? 1 : 0));
  };
  $blocked = $hw['ollama'] === null;
  $row('ollama', 'Ollama', $blocked ? 'nicht erreichbar, so kann nichts installiert werden' : 'Version ' . $hw['ollama'] . ' erreichbar',
    $blocked ? 'bad' : 'ok');

  $total = $hw['mem_total'];
  $avail = $hw['mem_available'];
  $needText = 'braucht ca. ' . coreui_core_install_gb($need);
  if ($total === null) {
    $row('ram', 'Arbeitsspeicher', 'nicht lesbar · ' . $needText, 'warn');
  } else {
    $have = coreui_core_install_gb((float)$total) . ' gesamt' . ($avail !== null ? ', ' . coreui_core_install_gb((float)$avail) . ' frei' : '');
    if ($need > $total * 0.9) $row('ram', 'Arbeitsspeicher', $have . ' · ' . $needText . ', das reicht nicht', 'bad');
    elseif ($avail !== null && $need > $avail) $row('ram', 'Arbeitsspeicher', $have . ' · ' . $needText . ', Ollama muss dafür andere Cores entladen', 'warn');
    else $row('ram', 'Arbeitsspeicher', $have . ' · ' . $needText, 'ok');
  }

  $cpu = $hw['threads'] !== null ? $hw['threads'] . ' Threads' : 'Threads unbekannt';
  if ($hw['avx512'] === true) $row('cpu', 'Prozessor', $cpu . ' · AVX-512', 'ok');
  elseif ($hw['avx2'] === true) $row('cpu', 'Prozessor', $cpu . ' · AVX2', 'ok');
  elseif ($hw['avx2'] === false) $row('cpu', 'Prozessor', $cpu . ' · ohne AVX2, ohne Grafikkarte sehr langsam', 'warn');
  else $row('cpu', 'Prozessor', $cpu, 'ok');

  $free = $hw['disk_free'];
  $dl = 'Download ca. ' . str_replace('.', ',', (string)$entry['download_gb']) . ' GB';
  if ($free === null) $row('disk', 'Speicherplatz', 'nicht lesbar · ' . $dl, 'warn');
  elseif ($free < $download * 1.1) $row('disk', 'Speicherplatz', coreui_core_install_gb((float)$free) . ' frei · ' . $dl . ', das reicht nicht', 'bad');
  elseif ($free < $download * 1.1 + 10 * COREUI_GIB) $row('disk', 'Speicherplatz', coreui_core_install_gb((float)$free) . ' frei · ' . $dl . ', danach wird es eng', 'warn');
  else $row('disk', 'Speicherplatz', coreui_core_install_gb((float)$free) . ' frei · ' . $dl, 'ok');

  $active = (float)$entry['active_b'];
  $speed = $active <= 5 ? 'flott' : ($active <= 14 ? 'mittel' : 'langsam');
  if ($hw['gpu'] === true) $row('speed', 'Tempo', 'Grafikkarte im Einsatz, ' . $speed . ' bis flott', 'ok');
  elseif ($speed === 'langsam') $row('speed', 'Tempo', 'ohne Grafikkarte langsam: große Stufe, jede Antwort braucht deutlich länger', 'warn');
  else $row('speed', 'Tempo', $speed . ($hw['gpu'] === false ? ' (ohne Grafikkarte)' : ' (Richtwert für den Prozessor)'), 'ok');

  return ['verdict'=>['gut', 'knapp', 'abraten'][$level], 'blocked'=>$blocked, 'rows'=>$rows, 'ram_need'=>(int)$need];
}

/**
 * Check for one tier: catalog entry and installed state, with the hardware
 * rows and the verdict only for those who may install (the server's
 * memory, processor and Ollama version are not every account's business).
 */
function coreui_core_install_check(string $tier, bool $hardware = true): array {
  $entry = COREUI_CORE_INSTALL_CATALOG[$tier] ?? null;
  if ($entry === null) throw new RuntimeException('unknown_tier');
  try {
    $missing = array_column(coreui_core_missing_tiers(coreui_ollama_models()), 'tier');
    $installed = !in_array($tier, $missing, true);
  } catch (RuntimeException $e) {
    $installed = false;
  }
  return ['tier'=>$tier, 'label'=>COREUI_CORE_TIER_LABELS[$tier], 'base'=>$entry['base'],
    'core'=>coreui_core_install_name($tier), 'download_gb'=>$entry['download_gb'], 'installed'=>$installed]
    + ($hardware ? coreui_core_install_verdict(coreui_core_install_hardware(), $entry)
      : ['verdict'=>null, 'blocked'=>false, 'rows'=>[], 'ram_need'=>null]);
}

function coreui_core_install_dir(): string {
  $dir = defined('STU_EMBER_CACHE_DIR') ? trim((string)STU_EMBER_CACHE_DIR) : '';
  return $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/var/cache';
}

/** Exclusive install lock, or false while another install runs. */
function coreui_core_install_lock(): mixed {
  $dir = coreui_core_install_dir();
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $handle = @fopen($dir . '/core-install.lock', 'c');
  if ($handle === false) throw new RuntimeException('install_state_unwritable');
  if (!flock($handle, LOCK_EX | LOCK_NB)) {
    fclose($handle);
    return false;
  }
  return $handle;
}

/** A shared probe: several status reads never see each other as a running install. */
function coreui_core_install_running(): bool {
  $handle = @fopen(coreui_core_install_dir() . '/core-install.lock', 'r');
  if ($handle === false) return false;
  $free = flock($handle, LOCK_SH | LOCK_NB);
  if ($free) flock($handle, LOCK_UN);
  fclose($handle);
  return !$free;
}

function coreui_core_install_write(array $state): void {
  $state['updated_at'] = time();
  $path = coreui_core_install_dir() . '/core-install.json';
  $tmp = $path . '.' . getmypid() . '.tmp';
  $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json) || @file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) @unlink($tmp);
}

/** Last install as the window shows it; a job that died without a word reads as interrupted. */
function coreui_core_install_status(): ?array {
  $raw = @file_get_contents(coreui_core_install_dir() . '/core-install.json');
  $state = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($state) || !isset($state['tier'], $state['phase'])) return null;
  $running = coreui_core_install_running();
  if (!$running && in_array($state['phase'], COREUI_CORE_INSTALL_ACTIVE, true)) {
    $state['phase'] = 'error';
    $state['error'] = 'install_interrupted';
    $state['message'] = 'Die Installation wurde unterbrochen (Server neu gestartet?). Du kannst sie neu starten.';
  }
  $public = ['running'=>$running];
  foreach (['tier', 'label', 'base', 'core', 'phase', 'step', 'completed', 'total', 'percent', 'error', 'message', 'started_at', 'updated_at'] as $key) {
    if (array_key_exists($key, $state)) $public[$key] = $state[$key];
  }
  return $public;
}

function coreui_core_install_begin(array $check): array {
  $state = ['tier'=>$check['tier'], 'label'=>$check['label'], 'base'=>$check['base'], 'core'=>$check['core'],
    'phase'=>'pull', 'step'=>'Startet …', 'completed'=>0, 'total'=>0, 'percent'=>0, 'layers'=>[],
    'started_at'=>time()];
  coreui_core_install_write($state);
  return $state;
}

/** Ollama's pull error in words an operator without a terminal can act on. */
function coreui_core_pull_error_text(string $error, string $base): string {
  $e = strtolower($error);
  if (str_contains($e, 'file does not exist') || str_contains($e, 'not found') || str_contains($e, 'manifest unknown')) {
    return 'Ollama kennt ' . $base . ' nicht. Entweder gibt es diese Stufe noch nicht oder Ollama ist zu alt für Gemma 4.';
  }
  if (str_contains($e, 'no space left')) return 'Auf dem Laufwerk von Ollama ist kein Platz mehr frei.';
  if (preg_match('~dial tcp|lookup |no such host|connection refused|i/o timeout|tls handshake|network is unreachable~', $e) === 1) {
    return 'Ollama erreicht seine Bibliothek im Internet nicht. Hat der Server gerade Internet?';
  }
  return 'Ollama meldet: ' . mb_substr(trim($error), 0, 200);
}

/** Folds one line of Ollama's /api/pull stream into the job state (pure, for the tests). */
function coreui_core_pull_fold(array $state, array $line): array {
  if (isset($line['error'])) {
    $state['phase'] = 'error';
    $state['error'] = 'pull_failed';
    $state['message'] = coreui_core_pull_error_text((string)$line['error'], (string)($state['base'] ?? ''));
    return $state;
  }
  $status = (string)($line['status'] ?? '');
  $digest = (string)($line['digest'] ?? '');
  if ($digest !== '' && isset($line['total'])) {
    $state['layers'][$digest] = [max(0, (int)$line['total']), max(0, (int)($line['completed'] ?? 0))];
  }
  $total = 0;
  $done = 0;
  foreach ((array)($state['layers'] ?? []) as $layer) {
    $total += (int)$layer[0];
    $done += min((int)$layer[1], (int)$layer[0]);
  }
  $state['total'] = $total;
  $state['completed'] = $done;
  $state['percent'] = $total > 0 ? (int)floor($done * 100 / $total) : 0;
  if ($status === 'pulling manifest') $state['step'] = 'Ollama holt die Paketliste …';
  elseif (str_starts_with($status, 'pulling ')) $state['step'] = 'Lädt herunter …';
  elseif (str_starts_with($status, 'verifying')) $state['step'] = 'Prüft den Download …';
  elseif ($status === 'writing manifest') $state['step'] = 'Trägt die Stufe bei Ollama ein …';
  elseif ($status === 'success') {
    $state['step'] = 'Download fertig.';
    $state['pulled'] = true;
  }
  return $state;
}

function coreui_core_install_pull(array $state): array {
  $buffer = '';
  $last = 0.0;
  $ch = curl_init(coreui_ollama_api_url('pull'));
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5,
    // No overall limit: a big tier may take long. A stalled stream ends it.
    CURLOPT_TIMEOUT=>0, CURLOPT_LOW_SPEED_LIMIT=>1, CURLOPT_LOW_SPEED_TIME=>900,
    CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['model'=>$state['base'], 'stream'=>true], JSON_UNESCAPED_SLASHES),
    CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$buffer, &$state, &$last): int {
      $buffer .= $chunk;
      while (($pos = strpos($buffer, "\n")) !== false) {
        $line = json_decode(trim(substr($buffer, 0, $pos)), true);
        $buffer = substr($buffer, $pos + 1);
        if (is_array($line)) $state = coreui_core_pull_fold($state, $line);
      }
      if (microtime(true) - $last >= 0.7) {
        coreui_core_install_write($state);
        $last = microtime(true);
      }
      return strlen($chunk);
    },
  ]);
  $ok = curl_exec($ch);
  $errno = curl_errno($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  $tail = json_decode(trim($buffer), true);
  if (is_array($tail)) $state = coreui_core_pull_fold($state, $tail);
  if ($state['phase'] === 'error') return $state;
  if ($ok === false || $status < 200 || $status >= 300 || empty($state['pulled'])) {
    $state['phase'] = 'error';
    $state['error'] = 'pull_failed';
    $state['message'] = $errno !== 0 ? 'Die Verbindung zu Ollama ist während des Downloads abgerissen.'
      : 'Ollama hat den Download nicht abgeschlossen (HTTP ' . $status . ').';
  }
  return $state;
}

function coreui_core_install_post(string $endpoint, array $body, int $timeout): array {
  $ch = curl_init(coreui_ollama_api_url($endpoint));
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>$timeout, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  $json = is_string($raw) ? json_decode($raw, true) : null;
  return [$status, is_array($json) ? $json : []];
}

/** The whole job: pull, create from the template, check the result. */
function coreui_core_install_run(array $state): array {
  $state = coreui_core_install_pull($state);
  if ($state['phase'] !== 'error') {
    $state['phase'] = 'create';
    $state['step'] = 'Baut Maras Core aus dem Modelfile …';
    coreui_core_install_write($state);
    try {
      $request = coreui_core_create_request((string)@file_get_contents(dirname(__DIR__) . '/config/Modelfile.template'),
        (string)$state['core'], (string)$state['base']);
      [$status, $reply] = coreui_core_install_post('create', $request, 600);
      if ($status < 200 || $status >= 300 || isset($reply['error'])) {
        $state['phase'] = 'error';
        $state['error'] = 'create_failed';
        $state['message'] = 'Der Download ist da, aber Ollama konnte Maras Core nicht bauen: '
          . mb_substr((string)($reply['error'] ?? 'HTTP ' . $status), 0, 200);
      } else {
        $state['phase'] = 'verify';
        $state['step'] = 'Prüft Maras Core …';
        coreui_core_install_write($state);
        $show = coreui_ollama_read_json('show', ['model'=>$state['core']]);
        if (!is_array($show) || trim((string)($show['system'] ?? '')) !== trim((string)($request['system'] ?? ''))) {
          $state['phase'] = 'error';
          $state['error'] = 'verify_failed';
          $state['message'] = 'Maras Core wurde angelegt, trägt aber nicht ihren Modelfile-Text. Bitte den Admin prüfen lassen.';
        }
      }
    } catch (RuntimeException $e) {
      $state['phase'] = 'error';
      $state['error'] = $e->getMessage();
      $state['message'] = 'Das Modelfile ließ sich nicht lesen (' . $e->getMessage() . ').';
    }
  }
  if ($state['phase'] !== 'error') {
    $state['phase'] = 'done';
    $state['step'] = 'Fertig: ' . $state['label'] . ' ist bereit.';
    $state['percent'] = 100;
  }
  unset($state['layers'], $state['pulled']);
  coreui_core_install_write($state);
  return $state;
}
