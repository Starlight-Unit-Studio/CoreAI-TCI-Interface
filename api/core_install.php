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
 *
 * Updates (Cores verwalten): Ollama brings new versions of the Gemma tags at
 * irregular intervals. An update pulls every local base in use again (only
 * changed layers come down) and rebuilds Mara's Cores on it when the base
 * changed or their SYSTEM text no longer matches the Modelfile: the
 * installer's Cores from the template, Mara's own Core from
 * var/Modelfile.generated, as the normal update does. Weekly on request
 * (admin switch); the next page load after the due date starts it.
 *
 * Ollama Cloud: cloud Cores need the server's Ollama connected to an
 * ollama.com account. `ollama signin` only asks the local server
 * (POST /api/me); without an account link it answers with a connect URL on
 * ollama.com that the admin opens in the browser. CoreAI does the same, so
 * no terminal and no token are needed. A cloud Core is then added with a
 * pull (a small stub, nothing is downloaded); it speaks as Mara through the
 * persona system message (ember_persona_system_for).
 */

require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/ai_context_estimate.php';

const COREUI_CORE_INSTALL_PERMISSION_MAX = 1; // admin and system admin
// Cloud variants the installer may add, per tier (ollama.com/library/gemma4).
const COREUI_CORE_CLOUD_CATALOG = ['premium' => 'gemma4:31b-cloud'];
const COREUI_OLLAMA_CLOUD_MIN_VERSION = '0.12.0';
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
    'core'=>coreui_core_install_name($tier), 'download_gb'=>$entry['download_gb'], 'installed'=>$installed,
    'cloud_base'=>COREUI_CORE_CLOUD_CATALOG[$tier] ?? null]
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
    $state['message'] = (($state['kind'] ?? '') === 'update' ? 'Das Update' : 'Die Installation')
      . ' wurde unterbrochen (Server neu gestartet?). Du kannst es neu starten.';
  }
  $public = ['running'=>$running, 'kind'=>(string)($state['kind'] ?? 'install'), 'cloud'=>!empty($state['cloud'])];
  foreach (['tier', 'label', 'base', 'core', 'phase', 'step', 'completed', 'total', 'percent', 'error', 'message',
    'item', 'items', 'results', 'started_at', 'updated_at'] as $key) {
    if (array_key_exists($key, $state)) $public[$key] = $state[$key];
  }
  return $public;
}

function coreui_core_install_begin(array $check): array {
  $state = ['kind'=>'install', 'tier'=>$check['tier'], 'label'=>$check['label'], 'base'=>$check['base'], 'core'=>$check['core'],
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

/** The whole job: pull, create from the template, check the result. A cloud Core is only pulled. */
function coreui_core_install_run(array $state): array {
  $state = coreui_core_install_pull($state);
  if ($state['phase'] !== 'error' && !empty($state['cloud'])) {
    $state['phase'] = 'done';
    $state['step'] = 'Fertig: ' . $state['label'] . ' (Cloud) ist bereit.';
    $state['percent'] = 100;
  }
  if ($state['phase'] !== 'error' && $state['phase'] !== 'done') {
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

// ---- Updates and Cores verwalten ----

const COREUI_CORE_UPDATE_DAYS = 7;

/** Mara's own Modelfile on this server: the installer's generated file, else the template. */
function coreui_core_mara_modelfile(): string {
  $root = dirname(__DIR__);
  foreach ([$root . '/var/Modelfile.generated', $root . '/config/Modelfile.template'] as $file) {
    $text = is_readable($file) ? (string)@file_get_contents($file) : '';
    if (trim($text) !== '') return $text;
  }
  return '';
}

/** Models by Ollama key (lower case, ":latest" added) with their manifest digest. */
function coreui_core_model_index(array $models): array {
  $index = [];
  foreach ($models as $m) {
    $name = (string)(is_array($m) ? ($m['name'] ?? '') : $m);
    if ($name !== '') $index[coreui_ollama_model_key($name)] = ['name'=>$name, 'digest'=>is_array($m) ? ($m['digest'] ?? null) : null];
  }
  return $index;
}

/**
 * What an update touches (pure): every local Gemma base in use, once, in tier
 * order, with Mara's Cores built on it. A tier counts when its base or the
 * installer's Core exists; Mara's own Core adds the base of her Modelfile.
 */
function coreui_core_update_plan(array $models): array {
  $index = coreui_core_model_index($models);
  $has = static fn(string $name): bool => isset($index[coreui_ollama_model_key($name)]);
  $plan = [];
  foreach (COREUI_CORE_INSTALL_CATALOG as $tier => $entry) {
    $core = coreui_core_install_name($tier);
    if (!$has($entry['base']) && !$has($core)) continue;
    $plan[coreui_ollama_model_key($entry['base'])] = ['base'=>$entry['base'], 'tier'=>$tier,
      'label'=>COREUI_CORE_TIER_LABELS[$tier], 'cores'=>$has($core) ? [['name'=>$core, 'source'=>'template']] : []];
  }
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
  $base = coreui_core_mara_base();
  if ($has($default) && coreui_model_name_valid($base) && !coreui_core_same($base, $default)) {
    $key = coreui_ollama_model_key($base);
    $tier = coreui_core_tier($base);
    $plan[$key] ??= ['base'=>$base, 'tier'=>$tier, 'label'=>$tier !== null ? COREUI_CORE_TIER_LABELS[$tier] : $base, 'cores'=>[]];
    array_unshift($plan[$key]['cores'], ['name'=>$default, 'source'=>'mara']);
  }
  $order = array_flip(array_keys(COREUI_CORE_INSTALL_CATALOG));
  uasort($plan, static fn(array $a, array $b): int => ($order[$a['tier']] ?? 99) <=> ($order[$b['tier']] ?? 99));
  return array_values($plan);
}

/**
 * Overview for Cores verwalten (pure): every tier with its local and cloud
 * Cores. Bases that carry the same manifest are one model under several
 * names (ollama cp): the one Mara's Core is built on (else the first tier)
 * counts as real, the others as second names; an update then loads the
 * real tier behind each second name.
 */
function coreui_core_manage_rows(array $models): array {
  $index = coreui_core_model_index($models);
  $real = [];
  $maraKey = coreui_ollama_model_key(coreui_core_mara_base());
  foreach (COREUI_CORE_INSTALL_CATALOG as $entry) {
    $key = coreui_ollama_model_key($entry['base']);
    $digest = $index[$key]['digest'] ?? null;
    if ($digest && (!isset($real[$digest]) || $key === $maraKey)) $real[$digest] = $entry['base'];
  }
  $rows = [];
  foreach (COREUI_CORE_INSTALL_CATALOG as $tier => $entry) {
    $local = [];
    $cloud = [];
    foreach ($index as $m) {
      if (coreui_core_tier_of($m['name']) !== $tier) continue;
      if (preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $m['name']) === 1) $cloud[] = $m['name'];
      else $local[] = $m['name'];
    }
    $own = $index[coreui_ollama_model_key($entry['base'])]['digest'] ?? null;
    $aliasOf = $own && $real[$own] !== $entry['base'] ? $real[$own] : null;
    $cloudBase = COREUI_CORE_CLOUD_CATALOG[$tier] ?? null;
    $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
    $rows[] = ['tier'=>$tier, 'label'=>COREUI_CORE_TIER_LABELS[$tier], 'base'=>$entry['base'],
      // 0.5.77: what "Deinstallieren" may remove; Mara's own Core always stays.
      'removable'=>array_values(array_filter($local, static fn(string $n): bool => !coreui_core_same($n, $default))),
      'download_gb'=>$entry['download_gb'], 'local'=>$local, 'cloud'=>$cloud, 'alias_of'=>$aliasOf,
      'cloud_base'=>$cloudBase, 'cloud_missing'=>$cloudBase !== null && !isset($index[coreui_ollama_model_key($cloudBase)]),
      'status'=>$local !== [] ? 'installed' : ($cloud !== [] ? 'cloud' : 'missing')];
  }
  return $rows;
}

function coreui_core_auto_update(PDO $pdo): array {
  $enabled = stu_app_setting_get($pdo, 'core_auto_update', '0') === '1';
  $last = (int)stu_app_setting_get($pdo, 'core_update_last', '0');
  $since = (int)stu_app_setting_get($pdo, 'core_auto_update_since', '0');
  return ['enabled'=>$enabled, 'last'=>$last > 0 ? $last : null,
    'next'=>$enabled ? max($last, $since) + COREUI_CORE_UPDATE_DAYS * 86400 : null];
}

/** Switching on starts the week now; the button updates at once. */
function coreui_core_auto_update_set(PDO $pdo, bool $on): array {
  stu_app_setting_set($pdo, 'core_auto_update', $on ? '1' : '0');
  if ($on) stu_app_setting_set($pdo, 'core_auto_update_since', (string)time());
  return coreui_core_auto_update($pdo);
}

function coreui_core_auto_update_due(array $auto, int $now): bool {
  return $auto['enabled'] === true && $auto['next'] !== null && $now >= (int)$auto['next'];
}

function coreui_core_update_begin(array $plan): array {
  $state = ['kind'=>'update', 'tier'=>'', 'label'=>'Update', 'base'=>'', 'core'=>'', 'phase'=>'pull', 'step'=>'Startet …',
    'completed'=>0, 'total'=>0, 'percent'=>0, 'layers'=>[], 'item'=>0, 'items'=>count($plan), 'plan'=>$plan,
    'results'=>[], 'started_at'=>time()];
  coreui_core_install_write($state);
  return $state;
}

function coreui_core_digest_of(string $name): ?string {
  try {
    $index = coreui_core_model_index(coreui_ollama_models());
  } catch (RuntimeException $e) {
    return null;
  }
  return $index[coreui_ollama_model_key($name)]['digest'] ?? null;
}

/** One line per base for the window (pure). */
function coreui_core_update_summary(array $results): string {
  $updated = count(array_filter($results, static fn(array $r): bool => $r['result'] === 'updated'));
  $failed = count(array_filter($results, static fn(array $r): bool => $r['result'] === 'error'));
  if ($results === []) return 'Es ist keine lokale Stufe installiert, es gab nichts zu aktualisieren.';
  $text = $updated === 0 ? 'Alle Stufen sind aktuell.'
    : ($updated === 1 ? 'Eine Stufe hatte eine neue Fassung und wurde neu gebaut.' : $updated . ' Stufen hatten eine neue Fassung und wurden neu gebaut.');
  return $failed > 0 ? $text . ' ' . ($failed === 1 ? 'Eine Stufe' : $failed . ' Stufen') . ' schlug fehl.' : $text;
}

/** The whole update: pull each base, rebuild Mara's Cores where needed. */
function coreui_core_update_run(array $state): array {
  $template = (string)@file_get_contents(dirname(__DIR__) . '/config/Modelfile.template');
  $results = [];
  foreach ($state['plan'] as $i => $item) {
    $state = ['item'=>$i + 1, 'label'=>$item['label'], 'tier'=>(string)$item['tier'], 'base'=>$item['base'], 'phase'=>'pull',
      'step'=>'Prüft auf eine neue Fassung …', 'layers'=>[], 'completed'=>0, 'total'=>0, 'percent'=>0, 'results'=>$results]
      + array_diff_key($state, ['pulled'=>true, 'error'=>true, 'message'=>true]);
    coreui_core_install_write($state);
    $before = coreui_core_digest_of($item['base']);
    $state = coreui_core_install_pull($state);
    if ($state['phase'] === 'error') {
      $results[] = ['label'=>$item['label'], 'base'=>$item['base'], 'result'=>'error', 'message'=>(string)$state['message']];
      continue;
    }
    $after = coreui_core_digest_of($item['base']);
    $changed = $before === null || $after === null || $before !== $after;
    $rebuilt = [];
    $failure = '';
    foreach ($item['cores'] as $core) {
      try {
        $text = $core['source'] === 'mara' ? coreui_core_mara_modelfile() : $template;
        $request = coreui_core_create_request($text, $core['name'], $item['base']);
      } catch (RuntimeException $e) {
        $failure = 'Das Modelfile ließ sich nicht lesen (' . $e->getMessage() . ').';
        continue;
      }
      $show = coreui_ollama_read_json('show', ['model'=>$core['name']]);
      $same = is_array($show) && trim((string)($show['system'] ?? '')) === trim((string)($request['system'] ?? ''));
      if (!$changed && $same) continue;
      $state['phase'] = 'create';
      $state['step'] = 'Baut ' . $core['name'] . ' neu …';
      coreui_core_install_write($state);
      [$status, $reply] = coreui_core_install_post('create', $request, 600);
      $show = coreui_ollama_read_json('show', ['model'=>$core['name']]);
      if ($status < 200 || $status >= 300 || isset($reply['error'])
          || !is_array($show) || trim((string)($show['system'] ?? '')) !== trim((string)($request['system'] ?? ''))) {
        $failure = 'Ollama konnte ' . $core['name'] . ' nicht neu bauen: ' . mb_substr((string)($reply['error'] ?? 'HTTP ' . $status), 0, 160);
        continue;
      }
      $rebuilt[] = $core['name'];
    }
    $results[] = ['label'=>$item['label'], 'base'=>$item['base'], 'rebuilt'=>$rebuilt, 'message'=>$failure,
      'result'=>$failure !== '' ? 'error' : ($changed ? 'updated' : ($rebuilt !== [] ? 'rebuilt' : 'current'))];
  }
  $failed = count(array_filter($results, static fn(array $r): bool => $r['result'] === 'error'));
  $state = ['phase'=>$results !== [] && $failed === count($results) ? 'error' : 'done', 'error'=>$failed > 0 ? 'update_partial' : null,
    'step'=>'Update fertig.', 'percent'=>100, 'results'=>$results, 'message'=>coreui_core_update_summary($results),
    'label'=>'Update', 'tier'=>'', 'base'=>''] + $state;
  unset($state['plan'], $state['layers'], $state['pulled']);
  coreui_core_install_write($state);
  return $state;
}

/** Sends the JSON answer; the job goes on after the browser has it. */
function coreui_core_job_detach(array $payload): void {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
  ignore_user_abort(true);
  @set_time_limit(0);
}

/** Runs an install or update under the held lock and always releases it. */
function coreui_core_job_run(mixed $lock, callable $job, array $state, int $uid): void {
  try {
    $job($state);
  } catch (Throwable $e) {
    coreui_core_install_write(['phase'=>'error', 'error'=>'job_failed',
      'message'=>'Der Auftrag ist abgebrochen. Details stehen im Fehlerprotokoll.'] + $state);
    if (function_exists('stu__log_error')) stu__log_error(['type'=>'core_job_failed', 'uid'=>$uid, 'message'=>$e->getMessage()]);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

// ---- Ollama Cloud ----

function coreui_ollama_version_at_least(?string $have, string $min): bool {
  if ($have === null || preg_match('~^\d+(?:\.\d+){0,2}~', trim($have), $m) !== 1) return false;
  return version_compare($m[0], $min, '>=');
}

/** Only Ollama's own connect page may be offered as a link. */
function coreui_ollama_signin_url_valid(string $url): bool {
  return preg_match('#^https://ollama\.com/connect\?[A-Za-z0-9._~%&=+-]+$#D', $url) === 1;
}

/** Reads POST /api/me like `ollama signin` (pure part, for the tests). */
function coreui_ollama_cloud_account_from(int $status, array $reply): array {
  if ($status >= 200 && $status < 300 && trim((string)($reply['name'] ?? '')) !== '') {
    return ['state'=>'connected', 'name'=>mb_substr(trim((string)$reply['name']), 0, 80),
      'plan'=>mb_substr(trim((string)($reply['plan'] ?? 'free')), 0, 40) ?: 'free'];
  }
  if ($status === 401) {
    $url = (string)($reply['signin_url'] ?? '');
    return ['state'=>'signed_out', 'signin_url'=>coreui_ollama_signin_url_valid($url) ? $url : null];
  }
  if ($status === 404) return ['state'=>'unsupported'];
  return ['state'=>'unavailable'];
}

/** Version, account and connect link of the server's Ollama for the cloud section. */
function coreui_ollama_cloud_status(): array {
  $version = coreui_ollama_read_json('version');
  $have = is_array($version) && isset($version['version']) ? (string)$version['version'] : null;
  $ok = coreui_ollama_version_at_least($have, COREUI_OLLAMA_CLOUD_MIN_VERSION);
  $account = ['state'=>$have === null ? 'unreachable' : 'unsupported'];
  if ($ok) {
    [$status, $reply] = coreui_core_install_post('me', [], 15);
    $account = coreui_ollama_cloud_account_from($status, $reply);
  }
  return ['version'=>$have, 'version_ok'=>$ok, 'min_version'=>COREUI_OLLAMA_CLOUD_MIN_VERSION, 'account'=>$account];
}

function coreui_core_cloud_begin(string $tier): array {
  $base = COREUI_CORE_CLOUD_CATALOG[$tier] ?? null;
  if ($base === null) throw new RuntimeException('unknown_tier');
  $state = ['kind'=>'install', 'cloud'=>true, 'tier'=>$tier, 'label'=>COREUI_CORE_TIER_LABELS[$tier], 'base'=>$base,
    'core'=>$base, 'phase'=>'pull', 'step'=>'Startet …', 'completed'=>0, 'total'=>0, 'percent'=>0, 'layers'=>[],
    'started_at'=>time()];
  coreui_core_install_write($state);
  return $state;
}

// ---- Deinstallieren (0.5.77) ----

/** Cores "Deinstallieren" removes for a tier row (pure): local ones without Mara's own Core, or the cloud ones. */
function coreui_core_uninstall_targets(array $row, bool $cloud): array {
  return array_values($cloud ? (array)($row['cloud'] ?? []) : (array)($row['removable'] ?? []));
}

function coreui_core_delete(string $model): bool {
  $ch = curl_init(coreui_ollama_api_url('delete'));
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST=>'DELETE', CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>60, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['model'=>$model], JSON_UNESCAPED_SLASHES),
  ]);
  curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return $status >= 200 && $status < 300;
}

/**
 * Removes the Cores of one tier: unload, delete in Ollama, and accounts that
 * had chosen one of them go back to the standard Core. Under the install
 * lock, so no install or update runs at the same time.
 */
function coreui_core_uninstall(PDO $pdo, array $targets): array {
  $loaded = coreui_ollama_loaded_names();
  $removed = [];
  $failed = [];
  foreach ($targets as $name) {
    if (in_array(coreui_ollama_model_key($name), $loaded, true)) {
      $ch = curl_init(coreui_ollama_api_url('generate'));
      curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>60,
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4, CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>$name, 'keep_alive'=>0], JSON_UNESCAPED_SLASHES),
      ]);
      curl_exec($ch);
      curl_close($ch);
    }
    if (coreui_core_delete($name)) $removed[] = $name;
    else $failed[] = $name;
  }
  if ($removed !== []) {
    $marks = implode(',', array_fill(0, count($removed), '?'));
    try {
      $pdo->prepare("UPDATE stu_user_ai_settings SET model_override = NULL WHERE model_override IN ({$marks})")->execute($removed);
    } catch (Throwable $e) {
      if (function_exists('stu__log_error')) stu__log_error(['type'=>'core_uninstall_override_reset_failed', 'message'=>$e->getMessage()]);
    }
  }
  return ['removed'=>$removed, 'failed'=>$failed];
}
