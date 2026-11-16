<?php
declare(strict_types=1);
// CoreAI 0.7.75 (P10 A): the preview of a web project.
//   php scripts/project-preview-selftest.php
// Offline: references resolved inside the project only, stylesheets and scripts put into the page
// (with "</style" and "</script" made harmless), "defer" kept as the browser runs it, modules and
// web addresses untouched, SVG pictures as data addresses, links to other pages, the data map
// for fetch() within its limit and safe inside a script, the shim on one line, the line map for
// errors, the error page, and the endpoint's headers (an origin of its own, no same-origin).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/project_preview_store.php';
function ppv_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project preview FAIL: {$why}\n"); exit(1); }
}

// --- References (pure) -------------------------------------------------------------------------
$cases = [
  ['index.html', 'css/style.css', 'css/style.css'],
  ['index.html', './js/game.js?v=3#top', 'js/game.js'],
  ['pages/about.html', '../css/style.css', 'css/style.css'],
  ['pages/about.html', 'img/a%20b.svg', 'pages/img/a b.svg'],
  ['pages/about.html', '/css/style.css', 'css/style.css'],
  ['index.html', '../secret.txt', null],
  ['pages/a.html', '../../x.css', null],
  ['index.html', 'https://cdn.example.test/lib.js', null],
  ['index.html', '//cdn.example.test/lib.js', null],
  ['index.html', 'data:text/css,body{}', null],
  ['index.html', 'javascript:alert(1)', null],
  ['index.html', '#anchor', null],
  ['index.html', '', null],
  ['index.html', 'css/a&amp;b.css', 'css/a&b.css'],
];
foreach ($cases as [$page, $ref, $want]) {
  ppv_test(coreui_preview_ref($page, $ref) === $want, "ref {$page} + {$ref} => " . var_export(coreui_preview_ref($page, $ref), true));
}
ppv_test(coreui_preview_is_page('a/b.HTML') && coreui_preview_is_page('x.htm') && !coreui_preview_is_page('x.html.txt') && !coreui_preview_is_page('x.js'), 'pages');
ppv_test(coreui_preview_attrs('<link rel="stylesheet" href=\'a.css\' media=print disabled>') === ['rel'=>'stylesheet', 'href'=>'a.css', 'media'=>'print', 'disabled'=>''],
  'attributes: ' . json_encode(coreui_preview_attrs('<link rel="stylesheet" href=\'a.css\' media=print disabled>')));
ppv_test(coreui_preview_drop_attr('<script data-src="k" src="a.js" defer>', 'src') === '<script data-src="k" defer>', 'drop one attribute, not data-src');

// --- Building a page (pure) --------------------------------------------------------------------
$files = [
  'index.html' => "<!doctype html>\n<html>\n<head>\n<link rel=\"stylesheet\" href=\"css/style.css\">\n<script src=\"js/game.js\" defer></script>\n"
    . "<script type=\"module\" src=\"js/mod.js\"></script>\n<script src=\"https://cdn.example.test/lib.js\"></script>\n<link rel=\"icon\" href=\"img/logo.svg\">\n"
    . "</head>\n<body>\n<img src=\"img/logo.svg\" alt=\"Logo\">\n<a href=\"about.html\">Über</a> <a href=\"missing.html\">x</a> <a href=\"https://example.test/\">y</a>\n"
    . "<script src=\"js/now.js\"></script>\n<script>\nbroken.call();\n</script>\n</body>\n</html>\n",
  'css/style.css' => "h1 { color: red; }\n/* </style><script>alert(1)</script> */\n",
  'js/game.js' => "var s = '</script><b>';\nnope();\n",
  'js/mod.js' => "export const x = 1;\n",
  'js/now.js' => "window.now = 1;\n",
  'img/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>',
  'about.html' => '<p>Über</p>',
  'data/level.json' => '{"level":1,"tag":"</script>","amp":"&"}',
];
$out = coreui_preview_build($files, 'index.html', './project_files.php?project_id=p&preview=');
ppv_test(str_contains($out, "<style data-coreai-src=\"css/style.css\">h1 { color: red; }\n/* <\\/style><script>alert(1)</script> */\n</style>"),
  'the stylesheet is in the page and cannot close its own tag');
ppv_test(!str_contains($out, 'href="css/style.css"'), 'the stylesheet link is gone');
ppv_test(str_contains($out, "<script data-coreai-src=\"js/game.js\">var s = '<\\/script><b>';\nnope();\n\n//# sourceURL=js/game.js</script></body>"),
  'a deferred script runs from the end of <body>, line 1 is line 1 of the file, it cannot close its own tag');
ppv_test(strpos($out, 'data-coreai-src="js/game.js"') > strpos($out, 'data-coreai-src="js/now.js"'), 'deferred after the scripts of the page');
ppv_test(str_contains($out, '<script type="module" data-coreai-src="js/mod.js">export const x = 1;'), 'a module stays a module (modules are deferred anyway)');
ppv_test(str_contains($out, '<script src="https://cdn.example.test/lib.js"></script>'), 'web addresses stay as they are');
ppv_test(str_contains($out, '<img src="data:image/svg+xml;base64,' . base64_encode($files['img/logo.svg']) . '" alt="Logo">'), 'an SVG picture as a data address');
ppv_test(str_contains($out, '<link rel="icon" href="img/logo.svg">'), 'an icon link is left alone');
ppv_test(str_contains($out, '<a href="about.html" data-coreai-page="about.html">Über</a>') && str_contains($out, '<a href="missing.html">x</a>')
  && str_contains($out, '<a href="https://example.test/">y</a>'), 'only existing project pages are marked as preview links');
ppv_test(str_contains($out, "<script data-coreai-src=\"js/now.js\">window.now = 1;\n\n//# sourceURL=js/now.js</script>"), 'a plain script in place');

// The shim: at the start of <head>, on one line, the data map safe inside a script.
preg_match('~<script data-coreai-preview>(.*?)</script>~s', $out, $m);
ppv_test(isset($m[1]) && strpos($out, '<head><script data-coreai-preview>') !== false, 'shim right after <head>');
$shim = $m[1] ?? '';
ppv_test(!str_contains($shim, "\n") && !str_contains($shim, '</') && !str_contains($shim, '<!--'), 'shim on one line, nothing that ends or comments the script');
ppv_test(str_contains($shim, '"data/level.json":["{\u0022level\u0022:1,\u0022tag\u0022:\u0022\u003C/script\u003E\u0022,\u0022amp\u0022:\u0022\u0026\u0022}","application/json"]'),
  'the data map carries other text files with their type, escaped');
preg_match('~var FILES = (\{.*?\}), PAGE = ~', $shim, $fm);
$map = json_decode($fm[1] ?? 'null', true);
$keys = is_array($map) ? array_keys($map) : [];
sort($keys);
ppv_test($keys === ['about.html', 'data/level.json', 'img/logo.svg']
  && $map['data/level.json'] === [$files['data/level.json'], 'application/json'],
  'what is already in the page is not in the data map again: ' . json_encode(array_keys((array)$map)));
ppv_test(str_contains($shim, 'var FILES = {') && str_contains($shim, 'window.fetch = function') && str_contains($shim, 'XMLHttpRequest.prototype.open')
  && str_contains($shim, "coreaiPreview: 'open'") && str_contains($shim, "coreaiPreview: 'error'") && str_contains($shim, 'PAGE_URL + encodeURIComponent(next)'),
  'shim: fetch, XHR, page links, errors');
preg_match('~LINES = (\[[^;]*?\]), blobs~', $shim, $lm);
ppv_test(isset($lm[1]) && json_decode($lm[1], true) === [[4, 0, 2], [6, 0, 2], [13, 0, 2]], 'line map: ' . ($lm[1] ?? 'none'));

// The line map points an error of the page back to its own line.
$doc = explode("\n", $out);
$at = 0;
foreach ($doc as $i => $line) if (trim($line) === 'broken.call();') $at = $i + 1;
$orig = 0;
foreach (explode("\n", $files['index.html']) as $i => $line) if (trim($line) === 'broken.call();') $orig = $i + 1;
$map = json_decode($lm[1] ?? '[]', true);
$shift = 0; $mapped = $at;
foreach ($map as [$line, $span, $delta]) {
  $start = $line + $shift;
  if ($at < $start) break;
  if ($at <= $start + $span + $delta) { $mapped = $line; $shift = null; break; }
  $shift += $delta;
}
if ($shift !== null) $mapped = $at - $shift;
ppv_test($at > $orig && $mapped === $orig, "the page line {$at} maps back to line {$orig}: {$mapped}");

// Size limit of the data map, pages without <head>, invalid UTF-8.
$big = ['p.html' => '<p>x</p>', 'a.txt' => str_repeat('a', COREUI_PREVIEW_DATA_BYTES - 10), 'b.txt' => str_repeat('b', 20), 'c.txt' => 'c'];
$bigOut = coreui_preview_build($big, 'p.html', './x?preview=');
ppv_test(str_contains($bigOut, '"a.txt"') && !str_contains($bigOut, '"b.txt"') && str_contains($bigOut, '"c.txt"'), 'the data map stays within its limit');
ppv_test(str_starts_with(coreui_preview_build(['p.html' => '<p>x</p>'], 'p.html', '?'), '<script data-coreai-preview>'), 'a page without <head>');
ppv_test(str_starts_with(coreui_preview_build(['p.html' => "<!DOCTYPE html>\n<p>x</p>"], 'p.html', '?'), "<!DOCTYPE html><script data-coreai-preview>"), 'after the doctype');
ppv_test(str_contains(coreui_preview_build(['p.html' => '<html lang="de"><p>x</p></html>'], 'p.html', '?'), '<html lang="de"><script data-coreai-preview>'), 'after <html>');

// --- Error page and endpoint (static) ----------------------------------------------------------
ppv_test(str_contains(coreui_preview_error_page('<b>&'), '&lt;b&gt;&amp;') && !str_contains(coreui_preview_error_page('<b>'), '<b>'), 'the error page escapes its text');
ppv_test(str_starts_with(COREUI_PREVIEW_CSP, 'sandbox ') && str_contains(COREUI_PREVIEW_CSP, 'allow-scripts')
  && !str_contains(COREUI_PREVIEW_CSP, 'allow-same-origin') && !str_contains(COREUI_PREVIEW_CSP, 'allow-top-navigation'), 'CSP: an origin of its own');
$endpoint = (string)file_get_contents($root . '/api/project_files.php');
$branch = substr($endpoint, (int)strpos($endpoint, "if (isset(\$_GET['preview'])) {"), 1600);
ppv_test(str_contains($endpoint, "require_once __DIR__ . '/project_preview_store.php';") && str_contains($branch, 'coreui_preview_headers();')
  && strpos($branch, 'coreui_preview_headers();') < strpos($branch, 'echo coreui_preview_build(') && str_contains($branch, 'exit;')
  && str_contains($branch, 'coreui_pfiles_path((string)$_GET[\'preview\'])') && str_contains($branch, 'coreui_preview_files($pdo, $uid, $projectId)'),
  'endpoint: headers first, the project of this account, a checked path');
$store = (string)file_get_contents($root . '/api/project_preview_store.php');
ppv_test(str_contains($store, "header('X-Content-Type-Options: nosniff');") && str_contains($store, "header('Cache-Control: private, no-store');")
  && str_contains($store, "header('Referrer-Policy: no-referrer');"), 'preview headers');
$ui = (string)file_get_contents($root . '/js/coreui-project-files.js');
ppv_test(str_contains($ui, "frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-modals allow-popups');")
  && str_contains($ui, 'event.source !== frame.contentWindow') && !str_contains($ui, 'allow-same-origin'), 'the frame: sandboxed, messages only from it');

echo "CoreAI Projektvorschau: Pfade, Stylesheets und Skripte in der Seite, defer, Module, SVG, Seitenlinks, Daten fuer fetch, Zeilen bei Fehlern, eigene Herkunft OK\n";
