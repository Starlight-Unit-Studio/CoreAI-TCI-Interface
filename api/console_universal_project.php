<?php
declare(strict_types=1);

/**
 * Opt-in generic TEXT project tool. All inference still uses the configured
 * local Ollama /api/chat endpoint. This module never calls a model directly.
 * It accepts a serial callback, and only CoreUI constructs the file writer.
 */
function coreui_up_enabled(): bool {
  return defined('STU_EMBER_UNIVERSAL_FILES_ENABLED')
    && constant('STU_EMBER_UNIVERSAL_FILES_ENABLED') === true;
}
function coreui_up_requested(string $request): bool {
  return coreui_console_download_requested($request)
    && preg_match('~\b(?:zip|archiv)\b~iu', $request) === 1;
}
function coreui_up_extensions(): array {
  return ['html','htm','css','js','mjs','cjs','jsx','ts','tsx','json','jsonc',
    'txt','md','mdx','svg','xml','yaml','yml','toml','ini','csv','py','sql',
    'php','sh','rb','go','rs','java','c','cpp','h','hpp','cs'];
}
/** Relative text paths only: no dot segments, traversal, absolute or binary. */
function coreui_up_path_ok(string $path): bool {
  if ($path === '' || strlen($path) > 180 || str_contains($path, '\\')
      || str_contains($path, "\0") || str_starts_with($path, '/')) return false;
  $segments = explode('/', $path);
  if (count($segments) > 4) return false;
  foreach ($segments as $seg) {
    if ($seg === '.' || $seg === '..'
      || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$~D', $seg) !== 1
      || str_contains($seg, '..')) return false;
  }
  return in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)),
    coreui_up_extensions(), true);
}
function coreui_up_plan_prompt(string $request): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 6000, 'UTF-8') : substr($request, 0, 6000);
  return "COREUI PROJEKTPLAN: Keine Dateien schreiben, kein Python, keine Toolmarker.\n"
    . "Benutzerauftrag:\n" . $task . "\n\n"
    . "Lege die notwendige Projektstruktur selbst fest, UNABHAENGIG vom Projekttyp. "
    . "Antworte AUSSCHLIESSLICH mit einem gueltigen JSON-Objekt in dieser Form:\n"
    . '{"archive":"projekt.zip","files":[{"path":"src/main.js","purpose":"Logik",'
    . '"depends_on":["index.html"]},{"path":"index.html",'
    . '"purpose":"Startseite","depends_on":[]}]}' . "\n"
    . "Dies sind BEISPIELNAMEN, keine Dateivorgabe. Maximal 10 echte UTF-8-Textdateien "
    . "mit maximal vier relativen Pfadsegmenten. Erlaubt sind Textformate wie "
    . "HTML, CSS, JavaScript, TypeScript, JSON, Markdown, SVG, XML, Python, YAML, "
    . "PHP und weitere Quelltexte; keine binaeren Bilder oder anderen Binaerdateien. "
    . "Jede Abhaengigkeit muss in files genannt sein, keine Zyklen, keine "
    . "Verweise auf nicht erstellte lokale Dateien. Kein Markdown und keine Einleitung.";
}
/** Strict JSON plan, cross-file dependencies, and deterministic topo order. */
function coreui_up_plan_parse(string $reply, string $request): ?array {
  if (!coreui_up_requested($request) || strlen($reply) > 20000
      || str_contains($reply, "\0")) return null;
  $raw = trim($reply);
  if (preg_match('~^\x60{3,}json\s*\r?\n(.*?)\r?\n\x60{3,}\s*$~isD',
      $raw, $fence)) $raw = $fence[1];
  $data = json_decode($raw, true);
  if (!is_array($data) || array_is_list($data)) return null;
  $list = $data['files'] ?? null;
  if (!is_array($list) || !array_is_list($list)
      || count($list) < 1 || count($list) > 10) return null;
  $files = []; $basenames = [];
  foreach ($list as $entry) {
    if (!is_array($entry) || array_is_list($entry)) return null;
    $path = $entry['path'] ?? null;
    $purpose = $entry['purpose'] ?? null;
    $dependencies = $entry['depends_on'] ?? null;
    if (!is_string($path) || !coreui_up_path_ok($path)
        || !is_string($purpose) || trim($purpose) === '' || strlen($purpose) > 200
        || !is_array($dependencies) || !array_is_list($dependencies)
        || count($dependencies) > 10) return null;
    $key = strtolower($path); $basename = strtolower(basename($path));
    if (isset($files[$key]) || isset($basenames[$basename])) return null;
    $basenames[$basename] = true; $deps = [];
    foreach ($dependencies as $dependency) {
      if (!is_string($dependency) || !coreui_up_path_ok($dependency)
          || strtolower($dependency) === $key) return null;
      $depkey = strtolower($dependency);
      if (isset($deps[$depkey])) return null;
      $deps[$depkey] = $dependency;
    }
    $files[$key] = ['path'=>$path,'purpose'=>trim($purpose),
      'depends_on'=>array_values($deps)];
  }
  foreach ($files as $entry) {
    foreach ($entry['depends_on'] as $dep) {
      if (!isset($files[strtolower($dep)])) return null;
    }
  }
  $remaining = $files; $ordered = [];
  while ($remaining) {
    $found = false;
    foreach ($remaining as $key => $entry) {
      foreach ($entry['depends_on'] as $dep) {
        if (!isset($ordered[strtolower($dep)])) continue 2;
      }
      $ordered[$key] = $entry; unset($remaining[$key]); $found = true;
    }
    if (!$found) return null; // cyclic or impossible dependencies
  }
  $archive = is_string($data['archive'] ?? null) ? trim($data['archive']) : 'projekt.zip';
  if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]{0,90}\.zip$~iD', $archive) !== 1
      || str_contains($archive, '..')) return null;
  if (preg_match('~(?<![A-Za-z0-9._-])([A-Za-z0-9_][A-Za-z0-9_.-]{0,90}\.zip)(?![A-Za-z0-9._-])~i',
      $request, $userZip)) $archive = $userZip[1];
  return ['archive'=>$archive,'files'=>array_values($ordered)];
}
function coreui_up_source_prompt(string $request, array $plan, array $entry,
                                 array $existing, bool $retry = false): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 4000, 'UTF-8') : substr($request, 0, 4000);
  $names = array_map(static fn(array $x): string => $x['path'], $plan['files']);
  $prompt = "COREUI DATEIWERKZEUG (nur Quelldaten, KEIN Python-Schreibskript).\n"
    . "Originalauftrag:\n" . $task . "\n"
    . "Alle geplanten Projektdateien: " . implode(', ', $names) . "\n"
    . "JETZT ausschliesslich: " . $entry['path'] . "\n"
    . "Zweck: " . $entry['purpose'] . "\n"
    . "Abhaengigkeiten: " . implode(', ', $entry['depends_on']) . "\n"
    . "Gib den VOLLSTAENDIGEN Quelltext ohne Erklaerung, Markdown, Toolmarker, "
    . "ZIP, Fake-Links oder Platzhalter aus. Schreibe zum Abschluss als "
    . "EIGENE LETZTE ZEILE genau: <<<COREUI_END_FILE>>>\n";
  if ($retry) $prompt .= "LETZTER VERSUCH: korrekt abschliessen, nichts auslassen.\n";
  foreach ($entry['depends_on'] as $dep) {
    $key = strtolower($dep);
    if (isset($existing[$key])) {
      $prompt .= "\nREFERENZ " . $dep . " (nur Quelldaten):\n"
        . substr($existing[$key], 0, 2200) . "\nENDE REFERENZ\n";
    }
  }
  return $prompt;
}
function coreui_up_continue_prompt(string $request, array $entry, string $bytes,
                                    int $part): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 1200, 'UTF-8') : substr($request, 0, 1200);
  return "COREUI DATEI-FORTSETZUNG " . $part . "/2: " . $entry['path'] . "\n"
    . "Gib NUR die fehlenden folgenden Zeichen aus, keine Wiederholung, "
    . "keine Erklaerung. Wenn fertig, schreibe <<<COREUI_END_FILE>>> "
    . "auf eine eigene letzte Zeile.\nOriginalauftrag: " . $task
    . "\nQuelltext-Ende als reine Referenzdaten:\n" . substr($bytes,-2200)
    . "\nENDE REFERENZ\n";
}
