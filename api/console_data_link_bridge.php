<?php
declare(strict_types=1);

/** Recover model-produced inline file links through the existing sandbox worker.
 *  A model's data: URL is never a server URL. Only recognized, bounded user-file
 *  requests are accepted; the returned Python merely writes decoded bytes.
 */
function coreui_console_file_requested(string $request): bool {
  return preg_match('~\b(?:html|htm|zip|minigame|mini-game|download(?:en)?|datei(?:en)?|webseite|webapp|spiel(?:chen)?|erstell(?:en|e|st|t)?|generier(?:en|e|st|t)?|programmier(?:en|e|st|t)?)\b~iu', $request) === 1;
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
