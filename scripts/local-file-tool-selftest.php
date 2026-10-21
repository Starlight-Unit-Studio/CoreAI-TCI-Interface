<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';

function local_file_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Local file tool FAIL: $message\n"); exit(1); }
}
$request = 'Erstelle index.html, style.css und game.js, packe sie in snake-game.zip und stelle einen Download bereit.';
$directive = coreui_console_multifile_source_directive($request);
local_file_test(is_string($directive), 'local ZIP project must use source-file protocol');
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
$slashPayload = base64_encode(gzcompress($slashHtml, 6));
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
local_file_test(is_string($handler)
  && str_contains($handler, 'ember_console_file_tool_candidate(string $reply, string $request, bool $sourceOnly = false)')
  && str_contains($handler, 'coreui_console_multifile_source_directive($userMsg)')
  && str_contains($handler, 'ember_console_file_tool_candidate($out, $userMsg, $sourceProjectMode)')
  && str_contains($handler, 'if ($sourceProjectMode && $needsFileRepair)'),
  'local project sources must bypass model-generated Python and costly worker-failure retries');
echo "CoreUI local file tool selftest: OK\n";
