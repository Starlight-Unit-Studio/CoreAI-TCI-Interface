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

function coreui_console_inline_link_present(string $reply): bool {
  return preg_match('~\bhref\s*=\s*["\']?\s*data:(?:text/html|application/zip|text/plain|application/json|text/css|image/svg\+xml)\b~i', $reply) === 1;
}

/**
 * Dedicated, bounded contract for a requested multi-file ZIP project. The
 * language model writes source files, not an additional Python program to
 * create those files. Our existing inert source bridge handles verification,
 * writing and ZIP packaging. Single-file/generic Python tasks are untouched.
 */
function coreui_console_multifile_source_directive(string $request): ?string {
  if (!coreui_console_download_requested($request)
      || preg_match('~\b(?:zip|archiv)\b~iu', $request) !== 1) return null;
  $pattern = '[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)';
  preg_match_all('~(?<![A-Za-z0-9._-])(' . $pattern . ')(?![A-Za-z0-9._-])~i', $request, $found);
  $names = array_values(array_unique(array_map('strtolower', $found[1] ?? [])));
  if (count($names) < 2 || count($names) > 9) return null;
  return "COREUI QUELLDATEIEN: Erzeuge fuer diesen Dateiauftrag die VOLLSTAENDIGEN "
    . "Quelltexte als separate Markdown-Codebloecke. Pflichtdateien: "
    . implode(', ', $names) . ". Fuer JEDE der genannten Dateien schreibe "
    . "eine Ueberschrift '### ' gefolgt vom jeweiligen echten Dateinamen, " 
    . "darunter einen Markdown-Codeblock in der passenden Sprache (html, css, "
    . "javascript, json, python oder text). Verwende mindestens drei Backticks; "
    . "enthaelt der Quelltext selbst Backticks, muss der aeussere Block laenger "
    . "sein. Zwischen zwei Dateien schliesse den vorherigen Block. Keine Platzhalter, "
    . "keine ausgelassenen Dateien, keine Erklaerung und KEINE Python-Skripte "
    . "zum Schreiben der Dateien, keine [PY]-Tags. Der CoreUI-Server uebernimmt "
    . "die Quelltexte danach unveraendert als Dateien und erstellt, prueft und "
    . "liefert das angeforderte ZIP AUTOMATISCH ueber sein eigenes Werkzeug. "
    . "Erfinde keine Downloadlinks. Antworte NUR mit den Dateibloecken.";
}

/** Parse the explicitly requested project files and optional ZIP target once. */
function coreui_console_project_request_spec(string $request): ?array {
  $namePattern = '[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)';
  preg_match_all('~(?<![A-Za-z0-9._-])(' . $namePattern . ')(?![A-Za-z0-9._-])~i',
    $request, $requestedMatches);
  $requested = [];
  foreach ($requestedMatches[1] ?? [] as $name) {
    $key = strtolower((string)$name);
    if (!isset($requested[$key])) $requested[$key] = (string)$name;
  }
  if (count($requested) > 10) return null;

  $zipRequested = preg_match('~\b(?:zip|archiv)\b~iu', $request) === 1;
  if ($zipRequested && count($requested) > 9) return null;
  $zipName = 'projekt.zip';
  if (preg_match('~(?<![A-Za-z0-9._-])([A-Za-z0-9][A-Za-z0-9._-]{0,90}\.zip)(?![A-Za-z0-9._-])~i',
      $request, $zipMatch)) $zipName = $zipMatch[1];

  return [
    'requested'=>$requested,
    'zip_requested'=>$zipRequested,
    'zip_name'=>$zipName,
  ];
}

/** Build the server-owned writer from already parsed, inert source bytes. */
function coreui_console_project_files_python(array $files, array $spec, int $maxCodeChars): ?array {
  if ($files === [] || $maxCodeChars < 500) return null;
  $requested = is_array($spec['requested'] ?? null) ? $spec['requested'] : [];
  $zipRequested = ($spec['zip_requested'] ?? false) === true;
  $zipName = is_string($spec['zip_name'] ?? null) ? $spec['zip_name'] : 'projekt.zip';
  $namePattern = '~^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)$~i';

  $selected = [];
  if ($requested !== []) {
    foreach ($requested as $key => $canonicalName) {
      if (!isset($files[$key]) || !is_array($files[$key])) return null;
      $selected[$key] = $files[$key];
      $selected[$key]['name'] = $canonicalName;
    }
  } else {
    $selected = $files;
  }
  if (count($selected) > ($zipRequested ? 9 : 10)) return null;

  $total = 0;
  foreach ($selected as $key => $file) {
    $name = $file['name'] ?? null;
    $bytes = $file['bytes'] ?? null;
    if (!is_string($key) || !is_string($name) || !is_string($bytes)
        || strtolower($name) !== $key || preg_match($namePattern, $name) !== 1
        || $bytes === '' || strlen($bytes) > 150000) return null;
    $total += strlen($bytes);
    if ($total > 512000) return null;
  }

  $code = "from pathlib import Path\nimport base64, zlib, zipfile\n"
    . "out = Path('/work/output')\nout.mkdir(parents=True, exist_ok=True)\nfiles = {\n";
  foreach ($selected as $file) {
    $bytes = $file['bytes'];
    $packed = function_exists('gzcompress') ? gzcompress($bytes, 6) : false;
    $compressed = is_string($packed) && strlen($packed) < strlen($bytes);
    $encoded = base64_encode($compressed ? $packed : $bytes);
    // JSON normally escapes '/' as '\/' which is not valid base64 in Python.
    $code .= '    ' . json_encode($file['name'], JSON_THROW_ON_ERROR)
      . ': (' . json_encode($compressed ? 'z' : 'b', JSON_THROW_ON_ERROR)
      . ', ' . json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "),\n";
  }
  $code .= "}\nfor name, (mode, data) in files.items():\n"
    . "    raw = base64.b64decode(data, validate=True)\n"
    . "    if mode == 'z': raw = zlib.decompress(raw)\n"
    . "    target = out / name\n"
    . "    target.write_bytes(raw)\n"
    . "    assert target.is_file() and target.stat().st_size == len(raw)\n";
  if ($zipRequested) {
    $code .= 'archive = out / ' . json_encode($zipName, JSON_THROW_ON_ERROR) . "\n"
      . "with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as bundle:\n"
      . "    for name in sorted(files): bundle.write(out / name, arcname=name)\n"
      . "with zipfile.ZipFile(archive) as check:\n"
      . "    assert check.namelist() == sorted(files) and check.testzip() is None\n"
      . "assert archive.is_file() and archive.stat().st_size > 0\n";
  }
  $code .= "print('Erzeugte Dateien:', ', '.join(sorted(p.name for p in out.iterdir() if p.is_file())))\n";
  if (strlen($code) > $maxCodeChars) return null;
  return [
    'code'=>$code,
    'name'=>$zipRequested ? $zipName : reset($selected)['name'],
    'size_bytes'=>$total,
    'file_count'=>count($selected) + ($zipRequested ? 1 : 0),
  ];
}

/**
 * Turn a model's complete, named fenced source files into inert sandbox
 * outputs. Small local models may provide HTML/CSS/JS source instead of a
 * [PY] tool call. Never execute the fenced source: the isolated Python worker
 * only writes its bytes and optionally creates/verifies a ZIP archive.
 */
function coreui_console_project_fences_python(string $reply, string $request, int $maxCodeChars = 100000, bool $collectOnly = false): ?array {
  if (!coreui_console_download_requested($request) || strlen($reply) > 250000
      || $maxCodeChars < 500) return null;
  $spec = coreui_console_project_request_spec($request);
  if ($spec === null) return null;
  $namePattern = '[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)';
  $requested = $spec['requested'];

  if (!preg_match_all('~(?<ticks>`{3,})(?<info>[^\r\n`]{0,150})\r?\n(?<body>.*?)\r?\n\k<ticks>(?!`)~s',
      $reply, $fences, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return null;
  $files = [];
  $total = 0;
  foreach ($fences as $fence) {
    $info = trim($fence['info'][0]);
    // Never infer a safe basename from an explicit unsafe path.
    if (str_contains($info, '/') || str_contains($info, '\\')) return null;
    $body = (string)$fence['body'][0];
    $name = '';
    if (preg_match('~(?:^|[\s=:])["`]?(' . $namePattern . ')["`]?(?:\s|$)~i',
        $info, $match)) {
      $name = $match[1];
    } else {
      $offset = (int)$fence[0][1];
      $prefix = rtrim(substr($reply, max(0, $offset - 250), min(250, $offset)));
      $headLines = preg_split('~\r?\n~', $prefix);
      $heading = trim((string)end($headLines));
      $heading = preg_replace('~^(?:#{1,6}\s*|(?:Datei|File)\s*:\s*|[-*]\s+)~i',
        '', $heading);
      $heading = trim((string)$heading, " \t`*:");
      if (str_contains($heading, '/') || str_contains($heading, '\\')) return null;
      if (preg_match('~^' . $namePattern . '$~i', $heading)) $name = $heading;
    }
    // Small local models often omit file headings. Infer only when a language
    // fence maps to exactly one still-missing filename explicitly requested
    // by the user. Ambiguous or unnamed outputs remain untrusted.
    if ($name === '' && $requested !== []) {
      $languageParts = preg_split('~\s+~', strtolower($info));
      $language = (string)($languageParts[0] ?? '');
      $extensions = [
        'html'=>'html','htm'=>'html','css'=>'css','js'=>'js','javascript'=>'js',
        'json'=>'json','python'=>'py','py'=>'py','markdown'=>'md','md'=>'md',
        'svg'=>'svg','text'=>'txt','txt'=>'txt',
      ];
      $ext = $extensions[$language] ?? '';
      if ($ext !== '') {
        $matches = [];
        foreach (array_keys($requested) as $wanted) {
          $wantedExt = strtolower(pathinfo($wanted, PATHINFO_EXTENSION));
          if ($wantedExt === 'htm') $wantedExt = 'html';
          if ($wantedExt === $ext && !isset($files[$wanted])) $matches[] = $wanted;
        }
        if (count($matches) === 1) $name = $matches[0];
      }
    }
    if ($name === '') continue;
    $key = strtolower($name);
    // Model-supplied extras are inert but are not part of the user's project.
    if ($requested !== [] && !isset($requested[$key])) continue;
    if (isset($files[$key]) || $body === '' || strlen($body) > 150000) return null;
    $total += strlen($body);
    if ($total > 512000 || count($files) >= 10) return null;
    $files[$key] = ['name'=>$requested[$key] ?? $name,'bytes'=>$body];
  }
  if ($files === []) return null;
  // Parse trusted-inert fragments without prematurely executing an incomplete project.
  // Used only to combine at most two model responses during the bounded retry.
  if ($collectOnly) return ['source_files'=>$files];
  return coreui_console_project_files_python($files, $spec, $maxCodeChars);
}

/**
 * Combine safe, named source blocks from the initial response and the single
 * bounded repair response. Never run a partial project or trust model links.
 *
 * The second response wins a duplicate filename, while each file must be an
 * explicitly requested basename. The final result passes the exact same
 * validation and server-owned ZIP creation used for a one-shot response.
 */
function coreui_console_merge_project_fences(string $first, string $second, string $request, int $maxCodeChars = 100000): ?array {
  if (strlen($first) + strlen($second) > 400000) return null;
  $spec = coreui_console_project_request_spec($request);
  if ($spec === null) return null;
  $one = coreui_console_project_fences_python($first, $request, $maxCodeChars, true);
  $two = coreui_console_project_fences_python($second, $request, $maxCodeChars, true);
  if ($one === null || $two === null) return null;

  $wanted = array_keys($spec['requested']);
  if (count($wanted) < 2 || count($wanted) > 9) return null;

  $merged = [];
  foreach ([$one['source_files'], $two['source_files']] as $batch) {
    foreach ($batch as $key => $file) {
      if (in_array($key, $wanted, true) && is_string($file['bytes'] ?? null)) {
        $merged[$key] = $file;
      }
    }
  }
  if (count($merged) !== count($wanted)) return null;
  return coreui_console_project_files_python($merged, $spec, $maxCodeChars);
}

/**
 * A local model may output a single Python script in a Markdown fence instead
 * of [PY]. Execute it only for an explicit requested download, and only in the
 * existing isolated Python worker. A prose-only answer is never executable.
 */
function coreui_console_python_fence_code(string $reply, string $request, int $maxCodeChars = 100000): ?string {
  if (!coreui_console_download_requested($request) || strlen($reply) > 220000
      || $maxCodeChars < 500) return null;
  if (!preg_match('~(?:^|\r?\n)```(?:python|py)[ \t]*\r?\n(.*?)\r?\n```\s*$~is',
      $reply, $match)) return null;
  $code = trim((string)$match[1]);
  return $code !== '' && strlen($code) <= $maxCodeChars ? $code : null;
}

/** A successful worker run is not a complete project if named outputs are missing. */
function coreui_console_missing_requested_artifacts(string $request, array $files): array {
  $pattern = '[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py|zip)';
  preg_match_all('~(?<![A-Za-z0-9._-])(' . $pattern . ')(?![A-Za-z0-9._-])~i',
    $request, $matches);
  $wanted = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
  if ($wanted === []) return [];
  $present = [];
  foreach ($files as $file) {
    if (is_array($file) && is_string($file['name'] ?? null)) {
      $present[strtolower($file['name'])] = true;
    }
  }
  return array_values(array_filter($wanted, static fn(string $name): bool => !isset($present[$name])));
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
