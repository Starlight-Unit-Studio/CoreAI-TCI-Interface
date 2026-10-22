<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';

function local_file_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Local file tool FAIL: $message\n"); exit(1); }
}
$request = 'Erstelle index.html, style.css und game.js, packe sie in snake-game.zip und stelle einen Download bereit.';
$directive = coreui_console_multifile_source_directive($request);
local_file_test(is_string($directive), 'named ZIP project must be recognized');
local_file_test(str_contains($directive, 'index.html')
  && str_contains($directive, 'style.css')
  && str_contains($directive, 'game.js')
  && str_contains($directive, 'keine [PY]-Tags'), 'model must receive named source contract');
local_file_test(coreui_console_multifile_source_directive(
  'Schreibe mir eine einzige app.html als Download.') === null,
  'single-file requests must keep their existing tool path');
local_file_test(coreui_console_multifile_source_directive(
  'Erklaere mir index.html, style.css und game.js in einem ZIP.') === null,
  'plain explanation must not trigger file execution');
$reply = "### index.html\n```html\n<!doctype html><html><body><script src=\"game.js\"></script></body></html>\n```\n"
  . "### style.css\n```css\nbody { background: #000; }\n```\n"
  . "### game.js\n```javascript\nconsole.log('Snake');\n```";
$project = coreui_console_project_fences_python($reply, $request, 100000);
local_file_test(is_array($project), 'named HTML/CSS/JS fences not recovered');
local_file_test(($project['name'] ?? '') === 'snake-game.zip', 'wrong archive name');
local_file_test(($project['file_count'] ?? 0) === 4, 'must write three sources plus ZIP');
$script = (string)$project['code'];
foreach (['index.html','style.css','game.js','snake-game.zip'] as $name) {
  local_file_test(str_contains($script, $name), 'generated Python misses ' . $name);
}
local_file_test(str_contains($script, 'zipfile.ZipFile') && str_contains($script, 'testzip()')
  && str_contains($script, "Path('/work/output')"), 'sandbox ZIP validation missing');
local_file_test(!str_contains($script, "console.log('Snake')"),
  'model source must be inert encoded data, not executable Python');
// Regression for PHP JSON's default '/' escaping: JSON accepts it, but
// Python base64 strings retain the backslash and fail with
// binascii.Error: Only base64 data is allowed.
$slashHtml = '<!DOCTYPE html><html lang="de"><head><link rel="stylesheet" href="style.css"></head><body><script src="game.js"></script></body></html>';
// Generate a payload with '/' on every zlib variant. The old bug needs
// that character, so a fixed, platform-dependent compressed blob is unsafe.
$slashPayload = '';
for ($variant = 0; $variant < 128; $variant++) {
  $candidate = function_exists('gzcompress') ? gzcompress($slashHtml, 6) : false;
  $packed = is_string($candidate) && strlen($candidate) < strlen($slashHtml);
  $slashPayload = base64_encode($packed ? $candidate : $slashHtml);
  if (str_contains($slashPayload, '/')) break;
  $slashHtml .= '???';
}
local_file_test(str_contains($slashPayload, '/'), 'base64 slash fixture is not representative');
$slashReply = "### index.html\n```html\n" . $slashHtml . "\n```\n"
  . "### style.css\n```css\nbody{background:black;color:lime}\n```\n"
  . "### game.js\n```javascript\nconsole.log('snake')\n```\n";
$slashProject = coreui_console_project_fences_python($slashReply, $request, 100000);
local_file_test(is_array($slashProject), 'slash fixture must produce ZIP wrapper');
$slashCode = (string)$slashProject['code'];
local_file_test(str_contains($slashCode, $slashPayload),
  'bridge must preserve exact base64 content in Python literal');
local_file_test(!str_contains($slashCode, '\\' . '/'),
  'bridge must not inject JSON slash escapes into Python base64');
$firstHalf = "### index.html\n```html\n<html><body>Snake</body></html>\n```\n";
$secondHalf = "### style.css\n```css\nbody{color:lime}\n```\n"
  . "### game.js\n```javascript\nconsole.log(1)\n```\n";
$merged = coreui_console_merge_project_fences($firstHalf, $secondHalf, $request, 100000);
local_file_test(is_array($merged) && ($merged['file_count'] ?? 0) === 4,
  'two incomplete responses must produce one complete verified ZIP project');
local_file_test(coreui_console_merge_project_fences($firstHalf,
  "### style.css\n```css\nbody{color:lime}\n```\n", $request) === null,
  'missing JS must never create a partial ZIP');
$firstDuplicate = <<<'COREUI_FIRST'
### index.html
```html
<html><body>first</body></html>
```
### style.css
```css
body{color:lime}
```
COREUI_FIRST;
$secondDuplicate = <<<'COREUI_SECOND'
### INDEX.HTML
```html
<html><body>second</body></html>
```
### game.js
```javascript
console.log('second')
```
COREUI_SECOND;
$duplicateMerged = coreui_console_merge_project_fences(
  $firstDuplicate, $secondDuplicate, $request, 100000);
$duplicateExpectedReply = <<<'COREUI_EXPECTED'
### index.html
```html
<html><body>second</body></html>
```
### style.css
```css
body{color:lime}
```
### game.js
```javascript
console.log('second')
```
COREUI_EXPECTED;
$duplicateExpected = coreui_console_project_fences_python(
  $duplicateExpectedReply, $request, 100000);
local_file_test(is_array($duplicateMerged) && is_array($duplicateExpected)
  && $duplicateMerged['code'] === $duplicateExpected['code'],
  'second response must win duplicate filenames case-insensitively');
$extraReply = $reply . "\n### debug.py\n```python\nprint('never package me')\n```";
$extraProject = coreui_console_project_fences_python($extraReply, $request, 100000);
local_file_test(is_array($extraProject) && ($extraProject['file_count'] ?? 0) === 4
  && !str_contains((string)$extraProject['code'], 'debug.py'),
  'unrequested extra source files must not enter the project or ZIP');
$upperReply = <<<'COREUI_UPPER'
### INDEX.HTML
```html
<html><body>case</body></html>
```
### STYLE.CSS
```css
body{color:white}
```
### GAME.JS
```javascript
console.log('case')
```
COREUI_UPPER;
$upperProject = coreui_console_project_fences_python($upperReply, $request, 100000);
local_file_test(is_array($upperProject)
  && str_contains((string)$upperProject['code'], '"index.html"')
  && !str_contains((string)$upperProject['code'], '"INDEX.HTML"'),
  'requested filename spelling must be canonical and case-insensitive');
$markdownRequest = 'Erstelle README.md und app.js, packe sie in docs.zip und stelle einen Download bereit.';
$markdownBody = "# Demo\n```javascript\nconsole.log('inside')\n```";
$markdownFirst = "### README.md\n````markdown\n" . $markdownBody . "\n````\n";
$markdownSecond = "### app.js\n```javascript\nconsole.log('outside')\n```\n";
$markdownMerged = coreui_console_merge_project_fences(
  $markdownFirst, $markdownSecond, $markdownRequest, 100000);
$markdownPacked = function_exists('gzcompress') ? gzcompress($markdownBody, 6) : false;
$markdownPayload = base64_encode(is_string($markdownPacked)
  && strlen($markdownPacked) < strlen($markdownBody) ? $markdownPacked : $markdownBody);
local_file_test(is_array($markdownMerged) && ($markdownMerged['file_count'] ?? 0) === 3
  && str_contains((string)$markdownMerged['code'], $markdownPayload),
  'longer outer fences must preserve Markdown source containing backticks');
local_file_test(coreui_console_project_fences_python(
  "### index.html\n```html\n<html></html>", $request) === null,
  'unclosed source fences must be rejected');
local_file_test(coreui_console_project_fences_python(
  $reply . "\n### index.html\n```html\n<html>duplicate</html>\n```",
  $request) === null, 'duplicate files in one response must be rejected');
$tooManyRequest = 'Erstelle a1.txt, a2.txt, a3.txt, a4.txt, a5.txt, a6.txt, a7.txt, a8.txt, a9.txt und a10.txt, packe sie in all.zip und stelle einen Download bereit.';
local_file_test(coreui_console_multifile_source_directive($tooManyRequest) === null
  && coreui_console_project_request_spec($tooManyRequest) === null,
  'ZIP jobs must reserve the tenth artifact slot for the archive');
local_file_test(coreui_console_project_fences_python($reply, $request, 500) === null,
  'generated server writer must respect the configured code-size limit');
$oversizeSpec = coreui_console_project_request_spec($request);
local_file_test(is_array($oversizeSpec) && coreui_console_project_files_python([
  'index.html'=>['name'=>'index.html','bytes'=>str_repeat('x', 150001)],
  'style.css'=>['name'=>'style.css','bytes'=>'body{}'],
  'game.js'=>['name'=>'game.js','bytes'=>'console.log(1)'],
], $oversizeSpec, 1000000) === null, 'single-source size limit must be enforced');
$totalRequest = 'Erstelle a.txt, b.txt, c.txt und d.txt, packe sie in all.zip und stelle einen Download bereit.';
$totalSpec = coreui_console_project_request_spec($totalRequest);
$largeFiles = [];
foreach (['a.txt','b.txt','c.txt','d.txt'] as $largeName) {
  $largeFiles[$largeName] = ['name'=>$largeName,'bytes'=>str_repeat($largeName[0], 130000)];
}
local_file_test(is_array($totalSpec)
  && coreui_console_project_files_python($largeFiles, $totalSpec, 1000000) === null,
  'combined source-size limit must be enforced');
$unnamed = "```html\n<html><body>Snake</body></html>\n```\n"
  . "```css\nbody{color:white}\n```\n"
  . "```javascript\nconsole.log(1)\n```";
$inferred = coreui_console_project_fences_python($unnamed, $request, 100000);
local_file_test(is_array($inferred) && ($inferred['file_count'] ?? 0) === 4,
  'unique requested filenames must be inferred from fence languages');
local_file_test(coreui_console_project_fences_python(
  "### index.html\n```html\n<html></html>\n```", $request) === null,
  'must reject incomplete project without CSS/JS');
local_file_test(coreui_console_project_fences_python($reply,
  'Erklaere mir HTML und CSS ohne einen Download.') === null,
  'no file bridge without explicit creation and download');
local_file_test(coreui_console_project_fences_python(
  "### ../index.html\n```html\n<html></html>\n```", 'Erstelle index.html zum Download') === null,
  'must reject traversal names');
$python = "```python\nfrom pathlib import Path\nPath('/work/output/a.txt').write_text('ok')\n```";
local_file_test(str_contains((string)coreui_console_python_fence_code(
  $python, 'Erstelle a.txt als Download'), 'write_text'),
  'fenced Python must be passed to the isolated worker');
local_file_test(coreui_console_python_fence_code(
  $python, 'Erklaere Python.') === null,
  'Python fence must not run outside a requested download');
$missing = coreui_console_missing_requested_artifacts($request, [
  ['name'=>'index.html'],['name'=>'style.css'],['name'=>'game.js'],
]);
local_file_test($missing === ['snake-game.zip'], 'must detect absent requested ZIP');
local_file_test(coreui_console_missing_requested_artifacts($request, [
  ['name'=>'index.html'],['name'=>'style.css'],['name'=>'game.js'],
  ['name'=>'snake-game.zip'],
]) === [], 'complete project rejected');
$handler = file_get_contents(dirname(__DIR__) . '/api/chat.php');
// Load only pure production helpers, without booting the HTTP/DB endpoint.
function ember_cfg(string $key, mixed $default = null): mixed { return $default; }
function ember_debug_log(string $event, array $meta): void {}
$tokens = token_get_all($handler);
$wantedFunctions = ['ember_py_max_code_chars','ember_py_extract_marker',
  'ember_console_file_tool_candidate','ember_console_file_tool_directive'];
for ($i = 0; $i < count($tokens); $i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
  $j = $i + 1;
  while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
  if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], $wantedFunctions, true)) continue;
  $code = ''; $depth = 0; $opened = false;
  for (; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $code .= is_array($token) ? $token[1] : $token;
    if ($token === '{') { $depth++; $opened = true; }
    if ($token === '}') $depth--;
    if ($opened && $depth === 0) break;
  }
  eval($code);
}
$toolCode = "from pathlib import Path\nPath('/work/output/index.html').write_text('Snake')";
$marked = ember_console_file_tool_candidate('[PY]' . $toolCode . '[/PY]', $request);
local_file_test(($marked['kind'] ?? '') === 'marker' && $marked['code'] === $toolCode,
  'named ZIP requests must accept the established Python marker protocol');
$fenced = ember_console_file_tool_candidate("```python\n" . $toolCode . "\n```", $request);
local_file_test(($fenced['kind'] ?? '') === 'python_fence',
  'named ZIP requests must also accept a Python tool fence');
local_file_test((ember_console_file_tool_candidate($reply, $request)['kind'] ?? '') === 'project',
  'complete inert sources must remain a fallback');
local_file_test(ember_console_file_tool_candidate($firstHalf, $request) === null,
  'incomplete source text must not become executable code');
local_file_test(ember_console_file_tool_candidate('Hier ist dein Download: snake-game.zip', $request) === null,
  'prose must never be treated as a tool result');
local_file_test(str_contains(ember_console_file_tool_directive(), '[PY]')
  && !str_contains($handler, '$sourceProjectMode')
  && !str_contains($handler, '$sourceOnly'),
  'source-only routing must not suppress the established sandbox tool');
echo "CoreUI local file tool selftest: OK\n";
