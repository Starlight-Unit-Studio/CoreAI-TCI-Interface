<?php
declare(strict_types=1);

/** Recover model-produced inline file links through the existing sandbox worker.
 *  A model's data: URL is never a server URL. Only recognized, bounded user-file
 *  requests are accepted; the returned Python merely writes decoded bytes.
 */
function coreui_console_file_requested(string $request): bool {
  return preg_match('~\b(?:html|htm|zip|minigame|mini-game|download(?:en)?|datei(?:en)?|webseite|webapp|spiel(?:chen)?|erstell(?:en|e|st|t)?|generier(?:en|e|st|t)?|programmier(?:en|e|st|t)?)\b~iu', $request) === 1;
}

/** An explicit creation+download request, not ordinary discussion of files. */
function coreui_console_download_requested(string $request): bool {
  return preg_match('~\\b(?:download\\w*|herunterlad\\w*)\\b~iu', $request) === 1
    && preg_match('~\\b(?:erstell\\w*|generier\\w*|erzeug\\w*|programmier\\w*|schreib\\w*|speicher\\w*|liefer\\w*|bekomm\\w*|mach\\w*|bereitstell\\w*)\\b~iu', $request) === 1;
}

/** Local models may omit [PY]. Request one explicit tool-only response. */
function coreui_console_file_tool_prompt(string $request): string {
  $task = trim($request);
  if (strlen($task) > 8000) $task = substr($task, 0, 8000);
  return "DU BIST EIN CODE-GENERATOR FUER EIN ISOLIERTES PYTHON-WERKZEUG.\n"
    . "AUFGABE:\n" . $task . "\nENDE AUFGABE\n\n"
    . "Gib AUSSCHLIESSLICH einen vollstaendigen [PY]...[/PY]-Block mit ausfuehrbarem Python 3 aus. "
    . "Keine Erklaerung, kein Markdown, keine erfundenen Links. "
    . "Benutze pathlib.Path(os.environ['EMBER_OUTPUT_DIR']) fuer ALLE Ergebnisdateien. "
    . "Erzeuge alle geforderten Dateien selbst. Fuer ZIP: erst Quelldateien schreiben, "
    . "dann zipfile.ZipFile benutzen und ZIP mit testzip() und namelist() pruefen. "
    . "Lies keine angeblich vorhandenen Dateien ein. Keine Platzhalter. "
    . "Antworte nur mit [PY] gefolgt vom Python-Code und [/PY].";
}

/** Only recover one complete Python fence from an actual file request. */
function coreui_console_fenced_python(string $reply, string $request, int $maxCodeChars): ?string {
  if (!coreui_console_download_requested($request) || strlen($reply) > 220000) return null;
  if (preg_match_all('~(?:^|\r?\n)[ \t]*```(?:python|py)[ \t]*\r?\n(.*?)\r?\n```[ \t]*(?=\r?\n|$)~siu', $reply, $matches) !== 1) return null;
  $code = trim((string)$matches[1][0]);
  if ($code === '' || strlen($code) > $maxCodeChars) return null;
  if (!str_contains($code, 'EMBER_OUTPUT_DIR')
      && !str_contains($code, '/work/output')
      && !str_contains($code, '/mnt/data')) return null;
  return $code;
}

/**
 * Recover complete, explicitly labelled multi-file source as inert bytes.
 * A worker writes the files and checks the ZIP. Missing files are never invented.
 */
function coreui_console_multifile_python(string $reply, string $request, int $maxCodeChars): ?array {
  if (!coreui_console_download_requested($request)
      || preg_match('~\bzip\b~iu', $request) !== 1
      || strlen($reply) > 220000 || $maxCodeChars < 500) return null;
  $pattern = '~(?:^|\r?\n)[ \t]*(?:#{1,6}[ \t]*)?(?:\*\*|`)?'
    . '([A-Za-z0-9][A-Za-z0-9._-]{0,79}\.(?:html?|css|js|json|txt|md))'
    . '(?:\*\*|`)?[ \t]*:?[ \t]*\r?\n[ \t]*```(?:html?|css|javascript|js|json|text|txt|markdown|md)?[ \t]*\r?\n'
    . '(.*?)\r?\n```[ \t]*(?=\r?\n|$)~siu';
  if (preg_match_all($pattern, $reply, $matches, PREG_SET_ORDER) < 2) return null;
  $files = [];
  $total = 0;
  foreach ($matches as $m) {
    $name = (string)$m[1];
    $body = (string)$m[2];
    if ($body === '' || strlen($body) > 120000 || isset($files[$name])) return null;
    $total += strlen($body);
    if ($total > 180000 || count($files) >= 8) return null;
    $files[$name] = base64_encode($body);
  }
  preg_match_all('~\b[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.(?:html?|css|js|json|txt|md)\b~iu', $request, $names);
  foreach (array_unique($names[0] ?? []) as $name) {
    if (!array_key_exists($name, $files)) return null;
  }
  $zipName = 'projekt.zip';
  if (preg_match('~\b([A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip)\b~i', $request, $zipMatch)) {
    $zipName = (string)$zipMatch[1];
  }
  $json = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $encodedJson = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $encodedZip = json_encode($zipName, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $code = "from pathlib import Path\nimport os, json, base64, zipfile\n"
    . "out = Path(os.environ['EMBER_OUTPUT_DIR'])\nout.mkdir(parents=True, exist_ok=True)\n"
    . "files = json.loads(" . $encodedJson . ")\n"
    . "for name, encoded in files.items():\n"
    . "    (out / name).write_bytes(base64.b64decode(encoded, validate=True))\n"
    . "zip_path = out / " . $encodedZip . "\n"
    . "with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as archive:\n"
    . "    for name in files:\n"
    . "        archive.write(out / name, arcname=name)\n"
    . "with zipfile.ZipFile(zip_path, 'r') as archive:\n"
    . "    assert archive.testzip() is None\n"
    . "    assert sorted(archive.namelist()) == sorted(files)\n"
    . "print('ZIP geprueft:', zip_path.name, len(files), 'Dateien')\n";
  if (strlen($code) > $maxCodeChars) return null;
  return ['code'=>$code,'files'=>array_keys($files),'zip'=>$zipName];
}

/** A file is complete only when every explicitly requested output is present. */
function coreui_console_expected_output_names(string $request): array {
  preg_match_all('~\b[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.(?:html?|css|js|json|txt|md|zip)\b~iu', $request, $matches);
  return array_values(array_unique($matches[0] ?? []));
}

function coreui_console_artifact_matches_request(array $files, string $request): bool {
  if ($files === []) return false;
  $present = [];
  foreach ($files as $file) {
    if (is_array($file) && is_string($file['name'] ?? null)) {
      $present[strtolower((string)$file['name'])] = true;
    }
  }
  foreach (coreui_console_expected_output_names($request) as $name) {
    if (!isset($present[strtolower($name)])) return false;
  }
  if (preg_match('~\bzip\b~iu', $request) === 1) {
    foreach (array_keys($present) as $name) {
      if (str_ends_with($name, '.zip')) return true;
    }
    return false;
  }
  return true;
}

function coreui_console_inline_link_present(string $reply): bool {
  return preg_match('~\bhref\s*=\s*["\']?\s*data:(?:text/html|application/zip|text/plain|application/json|text/css|image/svg\+xml)\b~i', $reply) === 1;
}

/** Decodes a single complete inline download, or a complete fenced HTML page. */
function coreui_console_inline_file_python(string $reply, string $request, int $maxCodeChars = 20000): ?array {
  if (!coreui_console_file_requested($request) || strlen($reply) > 220000 || $maxCodeChars < 500) return null;
  $mime = '';
  $bytes = null;
  $filename = '';
  if (preg_match('~<a\b[^>]+>~is', $reply, $anchorMatch)) {
    $anchor = $anchorMatch[0];
    if (preg_match('~\bhref\s*=\s*(["\'])(data:[^\r\n]+?)\1~is', $anchor, $match)) {
      $uri = $match[2];
      if (str_starts_with(strtolower($uri), 'data:') && str_contains($uri, ',')) {
        [$header, $payload] = explode(',', substr($uri, 5), 2);
        $fields = explode(';', strtolower($header));
        $mime = array_shift($fields);
        $allowed = [
          'text/html'=>'html', 'application/zip'=>'zip', 'text/plain'=>'txt',
          'application/json'=>'json', 'text/css'=>'css', 'image/svg+xml'=>'svg',
        ];
        if (!isset($allowed[$mime]) || strlen($payload) > 190000) return null;
        $base64 = false;
        foreach ($fields as $field) {
          if ($field === 'base64') { if ($base64) return null; $base64 = true; }
          elseif ($field !== 'charset=utf-8' && $field !== 'charset=us-ascii') return null;
        }
        if ($base64) {
          if (preg_match('~^[A-Za-z0-9+/]*={0,2}$~D', $payload) !== 1) return null;
          $bytes = base64_decode($payload, true);
        } else {
          if (preg_match('~%(?![0-9a-f]{2})~i', $payload)) return null;
          $bytes = rawurldecode($payload);
        }
        if (!is_string($bytes)) return null;
        $filename = ($mime === 'text/html' && stripos($request, 'minigame') !== false)
          ? 'minigame.html' : 'ergebnis.' . $allowed[$mime];
        if (preg_match('~\bdownload\s*=\s*(["\'])([^"\']{1,180})\1~i', $anchor, $download)) {
          $candidate = html_entity_decode($download[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
          if (preg_match('//u', $candidate) === 1 && strlen($candidate) <= 180
              && !preg_match('~[/\\\\\x00-\x1f\x7f]~', $candidate)
              && preg_match('~\.(' . preg_quote($allowed[$mime], '~') . ')$~i', $candidate)) {
            $filename = $candidate;
          }
        }
      }
    }
  }
  // Some smaller models choose a fenced HTML document instead of the [PY] tool.
  if ($bytes === null && preg_match('~\b(?:html|htm|minigame|webseite)\b~iu', $request)
      && preg_match('~```(?:html|htm)[ \t]*\r?\n(.*?)\r?\n```~isu', $reply, $fence)) {
    $mime = 'text/html';
    $bytes = trim($fence[1]);
    $filename = 'minigame.html';
  }
  // Cloud coding models sometimes return complete standalone HTML with prose
  // instead of a fenced block. Recover ONLY a closed document, not partial code.
  // The original chat response is never interpreted as a trusted download URL.
  if ($bytes === null && preg_match('~\\b(?:html|htm|minigame|mini.game|webseite)\\b~iu', $request)
      && preg_match('~\\b(?:erstell\\w*|generier\\w*|erzeug\\w*|programmier\\w*|schreib\\w*|speicher\\w*|liefer\\w*|mach\\w*|bau\\w*|bereitstell\\w*)\\b~iu', $request)) {
    $start = false;
    if (preg_match('~<!doctype\\s+html\\b~i', $reply, $doctype, PREG_OFFSET_CAPTURE)) {
      $start = $doctype[0][1];
    } elseif (preg_match('~(?:^|[\\r\\n])\\s*<html(?:\\s|>)~i', $reply, $html, PREG_OFFSET_CAPTURE)) {
      $rawMatch = $html[0][0];
      $localStart = stripos($rawMatch, '<html');
      $start = $html[0][1] + $localStart;
    }
    $end = strripos($reply, '</html>');
    if ($start !== false && $end !== false && $end > $start) {
      $candidate = trim(substr($reply, $start, $end + strlen('</html>') - $start));
      if ($candidate !== '' && strlen($candidate) <= 1024 * 1024
          && preg_match('~<html(?:\\s|>)~i', substr($candidate, 0, 3000))) {
        $mime = 'text/html';
        $bytes = $candidate;
        $filename = 'minigame.html';
        if (preg_match('~\\b([A-Za-z0-9][A-Za-z0-9._-]{0,100}\\.html?)\\b~i', $request, $requestedName)) {
          $filename = $requestedName[1];
        }
      }
    }
  }
  if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 1024 * 1024) return null;
  if ($mime === 'text/html' && (!preg_match('~<(!doctype\s+html|html\b)~i', substr($bytes, 0, 3000))
      || !preg_match('~</html\s*>\s*$~i', $bytes))) return null;
  if ($mime === 'application/zip' && substr($bytes, 0, 4) !== "PK\x03\x04") return null;
  $packed = function_exists('gzcompress') ? gzcompress($bytes, 6) : false;
  $isCompressed = is_string($packed) && strlen($packed) < strlen($bytes);
  $encoded = base64_encode($isCompressed ? $packed : $bytes);
  $decode = $isCompressed ? "zlib.decompress(base64.b64decode('" . $encoded . "'))" : "base64.b64decode('" . $encoded . "')";
  $safeName = json_encode($filename, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  $code = "from pathlib import Path\nimport base64\nimport zlib\nout = Path('/work/output') / " . $safeName
    . "\nout.write_bytes(" . $decode . ")\nprint('Datei erstellt:', out.name)\n";
  if (strlen($code) > $maxCodeChars) return null;
  return ['code'=>$code, 'name'=>$filename, 'mime_type'=>$mime, 'size_bytes'=>strlen($bytes)];
}
