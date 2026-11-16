<?php
declare(strict_types=1);

require_once __DIR__ . '/project_files_store.php';

/*
 * 0.7.75 (P10 A): the preview of a web project.
 *
 * An HTML file of the project is shown as it would open from a folder: its stylesheets and
 * scripts from the project are put into the page, SVG pictures become data addresses, and a
 * small script lets fetch() and XMLHttpRequest read the project's other text files. Links to
 * other HTML pages of the project open them in the same preview.
 *
 * The page is served with "Content-Security-Policy: sandbox allow-scripts ...": it runs in an
 * origin of its own, so it never reads the account's cookies, the chat or the API, even when it
 * is opened in a new tab. Only saved files are shown; nothing runs on the server.
 */

const COREUI_PREVIEW_DATA_BYTES = 2097152;
const COREUI_PREVIEW_CSP = 'sandbox allow-scripts allow-forms allow-modals allow-popups';

function coreui_preview_is_page(string $path): bool {
  return preg_match('~\.html?$~i', $path) === 1;
}

/**
 * A reference in a page of the project, resolved to a project path (pure), or null for
 * anything outside the project: web addresses, data:, blob:, mailto:, javascript:, anchors,
 * or a path that leaves the project with "..".
 */
function coreui_preview_ref(string $page, string $ref): ?string {
  $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, '//') || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $ref) === 1) return null;
  $ref = (string)preg_replace('~[?#].*$~s', '', $ref);
  if ($ref === '') return null;
  $ref = rawurldecode($ref);
  $dir = str_contains($page, '/') ? substr($page, 0, (int)strrpos($page, '/') + 1) : '';
  $joined = $ref[0] === '/' ? substr($ref, 1) : $dir . $ref;
  $parts = [];
  foreach (explode('/', $joined) as $part) {
    if ($part === '' || $part === '.') continue;
    if ($part === '..') {
      if ($parts === []) return null;
      array_pop($parts);
      continue;
    }
    $parts[] = $part;
  }
  return $parts === [] ? null : implode('/', $parts);
}

/** The attributes of one start tag (pure): lower-case name => decoded value. */
function coreui_preview_attrs(string $tag): array {
  $out = [];
  $inner = (string)preg_replace('~^<[a-z0-9]+|/?>$~i', '', $tag);
  if (preg_match_all('~([^\s"\'<>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~', $inner, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) {
      $value = $a[2] ?? '';
      if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) $value = substr($value, 1, -1);
      $out[strtolower($a[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
  }
  return $out;
}

/** A start tag without one attribute (pure). */
function coreui_preview_drop_attr(string $tag, string $name): string {
  return (string)preg_replace('~\s' . preg_quote($name, '~') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)~i', '', $tag, 1);
}

function coreui_preview_type(string $path): string {
  $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
  return [
    'html'=>'text/html', 'htm'=>'text/html', 'css'=>'text/css', 'js'=>'text/javascript', 'mjs'=>'text/javascript',
    'json'=>'application/json', 'svg'=>'image/svg+xml', 'xml'=>'application/xml', 'csv'=>'text/csv',
    'md'=>'text/markdown', 'txt'=>'text/plain',
  ][$ext] ?? 'text/plain';
}

/**
 * The page as the preview shows it (pure). $files: project path => content (saved state).
 * $pageUrl: the address of a preview page without the path, for links when the preview is
 * open in a tab of its own (the path is appended URL-encoded). Scripts that come into the page
 * keep their own line numbers (sourceURL); for the page's own scripts the shim maps a line of
 * the shown page back to the line in the file.
 */
function coreui_preview_build(array $files, string $page, string $pageUrl): string {
  $html = (string)($files[$page] ?? '');
  $inlined = [];
  $lines = [];
  $deferred = [];
  $pattern = '~<link\b[^>]*>|(<script\b[^>]*>)\s*</script\s*>|<(img|image|source|use|a)\b[^>]*>~i';
  $html = (string)preg_replace_callback($pattern, static function (array $m) use ($files, $page, $html, &$inlined, &$lines, &$deferred): string {
    $whole = $m[0][0];
    $out = $whole;
    $a = coreui_preview_attrs(isset($m[1]) && $m[1][1] >= 0 ? $m[1][0] : $whole);
    if (stripos($whole, '<link') === 0) {
      $path = coreui_preview_ref($page, $a['href'] ?? '');
      if (preg_match('~(^|\s)stylesheet(\s|$)~i', $a['rel'] ?? '') && $path !== null && isset($files[$path]) && preg_match('~\.css$~i', $path)) {
        $inlined[$path] = true;
        $media = isset($a['media']) ? ' media="' . htmlspecialchars($a['media'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $out = '<style data-coreai-src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"' . $media . '>'
          . str_ireplace('</style', '<\/style', (string)$files[$path]) . '</style>';
      }
    } elseif (isset($m[1]) && $m[1][1] >= 0) {
      $path = coreui_preview_ref($page, $a['src'] ?? '');
      if ($path !== null && isset($files[$path]) && preg_match('~\.m?js$~i', $path)) {
        $inlined[$path] = true;
        $tag = coreui_preview_drop_attr($m[1][0], 'src');
        $tag = coreui_preview_drop_attr($tag, 'integrity');
        $tag = coreui_preview_drop_attr($tag, 'crossorigin');
        $tag = substr($tag, 0, -1) . ' data-coreai-src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';
        // No newline before the code: line 1 of the file is line 1 of the script.
        $out = $tag . str_ireplace('</script', '<\/script', (string)$files[$path])
          . "\n//# sourceURL=" . str_replace(["\r", "\n"], '', $path) . '</script>';
        // "defer" means nothing for a script inside the page. Such scripts move to the end of
        // <body>: they run after the page is parsed and before DOMContentLoaded, in order, as
        // the browser runs a deferred file.
        if (array_key_exists('defer', $a) && strtolower(trim($a['type'] ?? '')) !== 'module') {
          $deferred[] = (string)preg_replace('~\sdefer(?=[\s>/=])(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~i', '', $out, 1);
          $out = '';
        }
      }
    } elseif (strtolower($m[2][0]) === 'a') {
      $path = coreui_preview_ref($page, $a['href'] ?? '');
      if ($path !== null && isset($files[$path]) && coreui_preview_is_page($path)) {
        $out = substr($whole, 0, -1) . ' data-coreai-page="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';
      }
    } else {
      foreach (['src', 'href', 'xlink:href'] as $name) {
        $path = coreui_preview_ref($page, $a[$name] ?? '');
        if ($path === null || !isset($files[$path]) || !preg_match('~\.svg$~i', $path)) continue;
        $data = 'data:image/svg+xml;base64,' . base64_encode((string)$files[$path]);
        $out = (string)preg_replace('~(\s' . preg_quote($name, '~') . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)~i', '$1"' . $data . '"', $out, 1);
      }
    }
    $delta = substr_count($out, "\n") - substr_count($whole, "\n");
    if ($delta !== 0) {
      // [line of the tag in the file, lines it spans in the file, lines added in the page]
      $lines[] = [substr_count($html, "\n", 0, $m[0][1]) + 1, substr_count($whole, "\n"), $delta];
    }
    return $out;
  }, $html, -1, $count, PREG_OFFSET_CAPTURE);
  if ($deferred !== []) {
    $end = strripos($html, '</body');
    $html = $end === false ? $html . implode('', $deferred) : substr($html, 0, $end) . implode('', $deferred) . substr($html, $end);
  }

  // The other text files for fetch() and XMLHttpRequest, within a size limit.
  $data = [];
  $bytes = 0;
  foreach ($files as $path => $content) {
    if ($path === $page || isset($inlined[$path])) continue;
    $size = strlen((string)$content);
    if ($bytes + $size > COREUI_PREVIEW_DATA_BYTES) continue;
    $bytes += $size;
    $data[$path] = [(string)$content, coreui_preview_type((string)$path)];
  }
  $json = static fn($value): string => (string)json_encode($value,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  // One line only: the page's own lines stay where they are.
  $shim = '<script data-coreai-preview>'
    . str_replace(["\r\n", "\n"], ' ', coreui_preview_shim($json($data), $json($page), $json($pageUrl), $json($lines)))
    . '</script>';

  if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
    $at = $m[0][1] + strlen($m[0][0]);
  } elseif (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
    $at = $m[0][1] + strlen($m[0][0]);
  } elseif (preg_match('~^\s*<!doctype[^>]*>~i', $html, $m) === 1) {
    $at = strlen($m[0]);
  } else {
    $at = 0;
  }
  return substr($html, 0, $at) . $shim . substr($html, $at);
}

/** The script at the start of every preview page (pure; the arguments are JSON). */
function coreui_preview_shim(string $files, string $page, string $pageUrl, string $lines = '[]'): string {
  return <<<JS
(function () {
  'use strict';
  var FILES = $files, PAGE = $page, PAGE_URL = $pageUrl, LINES = $lines, blobs = {};
  var dir = PAGE.indexOf('/') >= 0 ? PAGE.slice(0, PAGE.lastIndexOf('/') + 1) : '';
  function resolve(ref) {
    ref = String(ref || '').trim();
    if (!ref || ref.charAt(0) === '#' || ref.slice(0, 2) === '//' || /^[a-z][a-z0-9+.\-]*:/i.test(ref)) return null;
    ref = ref.replace(/[?#][\s\S]*$/, '');
    try { ref = decodeURIComponent(ref); } catch (e) { return null; }
    var parts = [], joined = ref.charAt(0) === '/' ? ref.slice(1) : dir + ref;
    var list = joined.split('/');
    for (var i = 0; i < list.length; i++) {
      if (list[i] === '' || list[i] === '.') continue;
      if (list[i] === '..') { if (!parts.length) return null; parts.pop(); continue; }
      parts.push(list[i]);
    }
    var path = parts.join('/');
    return Object.prototype.hasOwnProperty.call(FILES, path) ? path : null;
  }
  function blobUrl(path) {
    if (!blobs[path]) blobs[path] = URL.createObjectURL(new Blob([FILES[path][0]], { type: FILES[path][1] }));
    return blobs[path];
  }
  var realFetch = window.fetch;
  if (realFetch) {
    window.fetch = function (input, init) {
      var path = resolve(typeof input === 'string' ? input : (input && input.url) || '');
      if (path === null) return realFetch.apply(this, arguments);
      return Promise.resolve(new Response(FILES[path][0], { status: 200, headers: { 'Content-Type': FILES[path][1] } }));
    };
  }
  var realOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, address) {
    var path = resolve(address);
    var args = Array.prototype.slice.call(arguments);
    if (path !== null) args[1] = blobUrl(path);
    return realOpen.apply(this, args);
  };
  function tell(message) {
    if (window.parent && window.parent !== window) window.parent.postMessage(message, '*');
  }
  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('a[data-coreai-page]') : null;
    if (!link || event.defaultPrevented || link.target === '_blank') return;
    event.preventDefault();
    var next = link.getAttribute('data-coreai-page');
    if (window.parent && window.parent !== window) tell({ coreaiPreview: 'open', path: next });
    else window.location.href = PAGE_URL + encodeURIComponent(next);
  }, false);
  function pageLine(line) {
    var shift = 0;
    for (var i = 0; i < LINES.length; i++) {
      var start = LINES[i][0] + shift;
      if (line < start) break;
      if (line <= start + LINES[i][1] + LINES[i][2]) return LINES[i][0];
      shift += LINES[i][2];
    }
    return line - shift;
  }
  window.addEventListener('error', function (event) {
    var file = String(event.filename || ''), line = Number(event.lineno) || 0;
    var own = !file || file === String(window.location.href) || /[?&]preview=/.test(file);
    tell({ coreaiPreview: 'error', message: String(event.message || 'Fehler').slice(0, 300),
      source: own ? PAGE : file.slice(0, 200), line: own && line ? pageLine(line) : line });
  });
  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Fehler');
    tell({ coreaiPreview: 'error', message: reason.slice(0, 300), source: '', line: 0 });
  });
})();
JS;
}

/** The project's saved files for a preview: path => content. */
function coreui_preview_files(PDO $pdo, int $uid, string $projectId): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $st = $pdo->prepare('SELECT path, content FROM stu_console_project_files WHERE project_id=? AND user_id=? ORDER BY path');
  $st->execute([$project['id'], $uid]);
  $files = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $files[(string)$row['path']] = (string)$row['content'];
  return $files;
}

/** The headers of every preview answer, the page or its error. */
function coreui_preview_headers(): void {
  header('Content-Type: text/html; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Content-Security-Policy: ' . COREUI_PREVIEW_CSP);
  header('Cache-Control: private, no-store');
  header('Referrer-Policy: no-referrer');
}

/** A short page instead of the preview (pure). */
function coreui_preview_error_page(string $text): string {
  return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vorschau</title></head>'
    . '<body style="font:15px/1.5 system-ui,sans-serif;margin:24px;color:#333">'
    . '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}
