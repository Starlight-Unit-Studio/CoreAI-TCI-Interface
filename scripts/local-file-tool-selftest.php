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

// 0.5.31: The original user request did not name source filenames. On
// device-local Gemma, a genuine multi-source homepage ZIP must use the
// EXISTING safe source-file/ZIP route rather than a long generated [PY] writer.
$homepageOriginal = 'Heyhoo, alles gut? Ich brauche eine private homepage für ein Videospiel :D '
  . 'es soll die persönliche Homepage eines fabel Mädchens sein das total in anime '
  . 'vernarrt ist und auch in solch einer welt lebt. Kriegen wir das hin? '
  . 'Erstelle alle dafür benötigten HTML Dateien css usw. '
  . 'Und Gib sie hier bitte im chat als zip zum Download aus. Danke :D';
local_file_test(coreui_console_multifile_source_directive($homepageOriginal) === null,
  'historical unnamed homepage request must expose the old route gap');
$homepageRequest = coreui_console_local_homepage_zip_request($homepageOriginal);
$homepageSpec = coreui_console_project_request_spec($homepageRequest);
$homepageDirective = coreui_console_multifile_source_directive($homepageRequest);
local_file_test($homepageRequest !== $homepageOriginal
  && is_array($homepageSpec)
  && $homepageSpec['zip_requested'] === true
  && array_values($homepageSpec['requested']) === ['index.html','style.css','main.js']
  && $homepageSpec['zip_name'] === 'projekt.zip',
  'unnamed downloadable homepage ZIP must infer just three real web sources');
local_file_test(is_string($homepageDirective)
  && str_contains($homepageDirective, 'index.html')
  && str_contains($homepageDirective, 'style.css')
  && str_contains($homepageDirective, 'main.js')
  && str_contains($homepageDirective, 'KEINE Python-Skripte'),
  'local homepage must route into existing inert multi-source ZIP protocol');
$homeHtml = '<!DOCTYPE html><html><head><link rel="stylesheet" href="style.css">'
  . '</head><body><main id="app">Anime world</main><script src="main.js"></script></body></html>';
$homeCSS = 'body { color: #fff; background: #303; }';
$homeJS = 'document.querySelector("#app").classList.add("ready");';
$homeFiles = [
  'index.html'=>coreui_console_local_source_reply($homeHtml, $homepageRequest, 'index.html'),
  'style.css'=>coreui_console_local_source_reply($homeCSS, $homepageRequest, 'style.css'),
  'main.js'=>coreui_console_local_source_reply($homeJS, $homepageRequest, 'main.js'),
];
local_file_test(!in_array(null, array_values($homeFiles), true),
  'original homepage request must accept separate real HTML/CSS/JS sources');
$homeWriter = coreui_console_project_files_python(
  $homeFiles, $homepageSpec, 100000);
local_file_test(is_array($homeWriter) && $homeWriter['file_count'] === 4
  && $homeWriter['name'] === 'projekt.zip'
  && str_contains($homeWriter['code'], "zipfile.ZipFile")
  && !str_contains($homeWriter['code'], $homeHtml),
  'existing deterministic sandbox writer must create and verify real homepage ZIP');
local_file_test(coreui_console_local_homepage_zip_request(
  'Erkläre mir, wie man eine Homepage mit HTML und CSS als ZIP erstellt.') ===
  'Erkläre mir, wie man eine Homepage mit HTML und CSS als ZIP erstellt.',
  'explanation without requested download must not be intercepted');
// Regression 0.5.41: a sentence-final full stop after a filename must not
// make the secure manifest silently lose its last JavaScript source.
$sentenceFiles = 'Erstelle index.html, style.css, main.js. Erstelle webseite.zip zum Download.';
$sentenceSpec = coreui_console_project_request_spec($sentenceFiles);
local_file_test(is_array($sentenceSpec)
  && $sentenceSpec['zip_requested'] === true
  && array_values($sentenceSpec['requested']) === ['index.html','style.css','main.js']
  && $sentenceSpec['zip_name'] === 'webseite.zip'
  && coreui_console_multifile_source_directive($sentenceFiles) !== null,
  'Punctuation after a source filename or ZIP name changed the validated manifest');
$notSource = coreui_console_project_request_spec(
  'Erstelle index.html, style.css, main.js.backup und webseite.zip.tmp zum Download.');
local_file_test(is_array($notSource)
  && !in_array('main.js', array_values($notSource['requested']), true)
  && $notSource['zip_name'] === 'projekt.zip',
  'Unsafe extension suffix may not be parsed as a complete source or ZIP filename');

$explicitHome = 'Erstelle index.html, style.css, main.js und sende das ZIP zum Download.';
local_file_test(coreui_console_local_homepage_zip_request($explicitHome) === $explicitHome,
  'explicit filenames must retain existing route and naming');
$singleHome = 'Erstelle mir nur eine HTML-Datei als Homepage und schicke sie als ZIP zum Download.';
local_file_test(coreui_console_local_homepage_zip_request($singleHome) === $singleHome,
  'single-file ZIP must not be converted to an automatic multi-source project');
$unrelatedZip = 'Erstelle mir einen Bericht mit txt Dateien als ZIP zum Download.';
local_file_test(coreui_console_local_homepage_zip_request($unrelatedZip) === $unrelatedZip,
  'non-homepage ZIPs must not infer HTML/CSS/JS files');

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
// Source-first browser game ZIPs do not require an LLM-generated Python writer.
// The parser must infer the standard filenames only for an explicit ZIP game.
$implicitRequest = 'Erstelle ein vollstaendig spielbares Snake-Spiel und liefere es als ZIP-Download.';
$implicitSpec = coreui_console_project_request_spec($implicitRequest);
local_file_test(is_array($implicitSpec) && array_keys($implicitSpec['requested'])
  === ['index.html','style.css','game.js'], 'unnamed Snake ZIP must infer three sources');
local_file_test(coreui_console_multifile_source_directive($implicitRequest) !== null,
  'unnamed Snake ZIP must use source-first route');
local_file_test(coreui_console_multifile_source_directive(
  'Erstelle ein Archiv zum Download.') === null,
  'unrelated ZIP must not infer a browser game');
$firstSource = "### index.html\n" . chr(96).chr(96).chr(96)
  . "html\n<!DOCTYPE html><script src=\"game.js\"></script>\n"
  . chr(96).chr(96).chr(96);
$cssSource = "### style.css\n" . chr(96).chr(96).chr(96)
  . "css\nbody{margin:0}\n" . chr(96).chr(96).chr(96);
$jsSource = "### game.js\n" . chr(96).chr(96).chr(96)
  . "javascript\nconsole.log('snake')\n" . chr(96).chr(96).chr(96);
$progress = coreui_console_project_source_progress([$firstSource], $implicitRequest);
local_file_test(is_array($progress) && $progress['missing'] === ['style.css','game.js']
  && $progress['project'] === null, 'incomplete source project must remain inert');
$cssPrompt = coreui_console_project_single_source_directive(
  $implicitRequest,'style.css',$progress['source_files']);
local_file_test(is_string($cssPrompt) && str_contains($cssPrompt, '### style.css')
  && !str_contains($cssPrompt, '[PY]-Block'),
  'missing-source retry must request an inert named file');
$progress = coreui_console_project_source_progress(
  [$firstSource, '[PY]' . "print('ignore')" . '[/PY]', $cssSource, $jsSource],
  $implicitRequest);
local_file_test(is_array($progress) && $progress['missing'] === []
  && is_array($progress['project'])
  && $progress['project']['file_count'] === 4
  && str_contains($progress['project']['code'], 'zipfile.ZipFile'),
  'complete inert sources must create deterministic ZIP wrapper');
$incomplete = coreui_console_project_source_progress(
  [$firstSource, "### game.js\n".chr(96).chr(96).chr(96)."javascript\nconsole.log(1)"],
  $implicitRequest);
local_file_test(is_array($incomplete) && $incomplete['project'] === null,
  'unclosed source fence must never be executed');
// Local Gemma ZIP export uses one raw source per model call and must not
// execute model-authored [PY] code or depend on closing Markdown fences.
$localHtml = "<!DOCTYPE html><html><head><link rel=\"stylesheet\" href=\"style.css\"></head>"
  . "<body><canvas id=\"game\"></canvas><script src=\"game.js\"></script></body></html>";
$localCss = "body { margin:0; color:white; }";
$localJs = "const canvas = document.getElementById('game');\n"
  . "function tick(){ canvas.width = 400; }\ntick();";
$localSpec = coreui_console_project_request_spec($implicitRequest);
local_file_test(coreui_console_local_source_prompt($implicitRequest, 'game.js', [
  'index.html'=>['name'=>'index.html','bytes'=>$localHtml],
], false) !== null, 'local raw one-file prompt must be available');
$localPrompt = coreui_console_local_source_prompt($implicitRequest, 'game.js', [
  'index.html'=>['name'=>'index.html','bytes'=>$localHtml],
], true);
local_file_test(is_string($localPrompt) && str_contains($localPrompt, 'KEINE Python-Ausfuehrung')
  && str_contains($localPrompt, 'game.js') && str_contains($localPrompt, 'id="game"')
  && str_contains($localPrompt, 'ohne Markdown'), 'Gemma file prompt must be compact and raw-only');
$jsCompactPrompt = coreui_console_local_source_prompt($implicitRequest, 'game.js', [
  'index.html'=>['name'=>'index.html','bytes'=>$localHtml],
  'style.css'=>['name'=>'style.css','bytes'=>$localCss],
], false);
local_file_test(is_string($jsCompactPrompt)
  && str_contains($jsCompactPrompt, 'id="game"')
  && !str_contains($jsCompactPrompt, 'color:white'),
  'Gemma JavaScript prompt must retain DOM references but avoid CSS source bulk');

foreach (['index.html'=>$localHtml,'style.css'=>$localCss,'game.js'=>$localJs] as $localName=>$localCode) {
  $file = coreui_console_local_source_reply($localCode, $implicitRequest, $localName);
  local_file_test(is_array($file) && $file['name'] === $localName
    && $file['bytes'] === $localCode, 'local raw source rejected: ' . $localName);
}
$localFence = chr(96).chr(96).chr(96);
local_file_test(
  coreui_console_local_source_reply($localFence . "javascript\n" . $localJs,
    $implicitRequest, 'game.js')['bytes'] === $localJs,
  'an unclosed sole JavaScript fence must be recoverable as inert source');
local_file_test(
  coreui_console_local_source_reply("### game.js\n" . $localFence
    . "javascript\n" . $localJs . "\n" . $localFence,
    $implicitRequest, 'game.js')['bytes'] === $localJs,
  'original Markdown fenced source must remain supported');
foreach ([
  ["[PY]print('invalid')[/PY]",'game.js'],
  ['Hier ist deine fertige Datei, viel Spass!','game.js'],
  ['const x = 1;','index.html'],
  ["<!DOCTYPE html><html><body>unfinished",'index.html'],
  ["body { color:red;",'style.css'],
] as [$badSource,$badFilename]) {
  local_file_test(coreui_console_local_source_reply(
    $badSource,$implicitRequest,$badFilename) === null,
    'invalid local source must not be packaged: ' . $badFilename);
}
$localReady = [];
foreach (['index.html'=>$localHtml,'style.css'=>$localCss,'game.js'=>$localJs] as $name=>$bytes) {
  $localReady[$name] = coreui_console_local_source_reply($bytes, $implicitRequest, $name);
}
$localWriter = coreui_console_project_files_python($localReady,$localSpec,100000);
local_file_test(is_array($localWriter) && $localWriter['file_count'] === 4
  && str_contains($localWriter['code'],'zipfile.ZipFile'),
  'three local raw sources must form complete deterministic ZIP job');
// Continuation regression: do not lose an expensive local JavaScript answer
// when Ollama stops on done_reason=length. Compose bounded inert fragments.
$cutJs = "const canvas = document.getElementById('game');\n"
  . "function tick(){ canvas";
$jsRest = ".width=400; }\ntick();";
$jsResume = coreui_console_local_js_resume_prompt(
  $implicitRequest, 'game.js', $cutJs, 1);
local_file_test(is_string($jsResume) && str_contains($jsResume, $cutJs)
  && str_contains($jsResume, 'TEIL 2')
  && !str_contains($jsResume, 'num_ctx')
  && !str_contains($jsResume, 'num_predict')
  && strlen($jsResume) < 6000,
  'local JS must resume a short suffix without changing Ollama parameters');
local_file_test(coreui_console_local_js_resume_prompt(
  $implicitRequest, 'index.html', $cutJs, 1) === null,
  'only JavaScript gets the dedicated continuation');
local_file_test(coreui_console_local_js_resume_prompt(
  $implicitRequest, 'game.js', $cutJs, 3) === null,
  'continuations must remain strictly bounded');
$cutRaw = coreui_console_local_js_fragment($cutJs, 'game.js', true);
local_file_test($cutRaw === $cutJs, 'valid truncated raw JS must be retained');
local_file_test(coreui_console_local_js_fragment(
  "### game.js\n" . $localFence . "javascript\n" . $cutJs,
  'game.js', true) === $cutJs,
  'one unclosed outer JS fence should retain inert code for continuation');
local_file_test(coreui_console_local_js_fragment(
  "[PY]print(1)[/PY]", 'game.js', true) === null,
  'Python tools must never become JS continuations');
$combinedJs = coreui_console_local_js_append($cutJs, $jsRest);
local_file_test($combinedJs === $cutJs . $jsRest
  && coreui_console_local_source_reply(
    $combinedJs, $implicitRequest, 'game.js')['bytes'] === $combinedJs,
  'an Ollama-length-stopped JS body must become a full requested source after continuation');
$echoedRest = substr($cutJs, -32) . $jsRest;
local_file_test(coreui_console_local_js_append($cutJs, $echoedRest) === $combinedJs,
  'echoed overlap from Gemma must not duplicate source code');
$commentCut = "const x=1;\n// previous line comment\n";
$commentNext = "\nfunction boot(){return x;}\nboot();";
local_file_test(coreui_console_local_js_fragment($commentCut, 'game.js', true) === $commentCut,
  'truncated source must preserve its trailing newline after a line comment');
local_file_test(coreui_console_local_js_fragment($commentNext, 'game.js', false) === $commentNext,
  'a continuation must preserve leading newlines in raw source');
local_file_test(coreui_console_local_js_append($commentCut, $commentNext)
  === $commentCut . $commentNext,
  'line-comment boundaries must not swallow appended JavaScript');

local_file_test(coreui_console_local_js_append($cutJs, $cutJs) === null,
  'a pure restart without new content is not progress');
local_file_test(coreui_console_local_js_append($cutJs, '') === null
  && coreui_console_local_js_append(str_repeat('x', 149999), $jsRest) === null,
  'empty or oversized continuations must be rejected');
$handler = file_get_contents(dirname(__DIR__) . '/api/chat.php');
local_file_test(str_contains($handler, 'ember_console_file_tool_is_device_local($primaryModel)')
  && str_contains($handler, 'coreui_console_local_homepage_zip_request($userMsg)')
  && str_contains($handler, 'coreui_console_local_source_reply($modelSource, $sourceProjectRequest,')
  && str_contains($handler, 'coreui_console_local_source_reply($combined, $sourceProjectRequest,'),
  'local Gemma only normalization must reach initial/continued sources without changing Cloud');

// Load only pure production helpers, without booting the HTTP/DB endpoint.
function ember_cfg(string $key, mixed $default = null): mixed { return $default; }
function ember_debug_log(string $event, array $meta): void {}
$tokens = token_get_all($handler);
$wantedFunctions = ['ember_py_max_code_chars','ember_py_extract_marker',
  'ember_console_file_tool_candidate','ember_console_file_tool_directive',
  'ember_console_file_tool_is_local','ember_console_file_tool_is_device_local','ember_console_file_tool_is_local_qwen_coder',
  'ember_console_file_model_status','ember_console_local_gemma_file_incomplete'];
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
local_file_test(ember_console_file_tool_is_device_local('ember-coreui:latest')
  && ember_console_file_tool_is_device_local('gemma4:e4b'),
  'device-local Gemma and CoreUI models must use resumable local source mode');
local_file_test(!ember_console_file_tool_is_device_local('gemma4:26b-cloud')
  && !ember_console_file_tool_is_device_local('gemma4:31b-cloud')
  && !ember_console_file_tool_is_device_local('gpt-oss:120b-cloud'),
  'Ollama Cloud models must keep the already working Cloud ZIP route');

local_file_test(ember_console_file_tool_is_local_qwen_coder('qwen3.6:35b-a3e-q4_K_M')
  && ember_console_file_tool_is_local_qwen_coder('qwen3.6:35b-a3b-q4_K_M'),
  'local quantized Qwen 3.6 must use the previously working Python sandbox route');
local_file_test(!ember_console_file_tool_is_local_qwen_coder('qwen3.6:35b-cloud')
  && !ember_console_file_tool_is_local_qwen_coder('gemma4:e4b'),
  'cloud and Gemma models must retain their existing ZIP routing');
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
// 0.5.30: diagnostics stay metadata-only; prevent truncated/open Gemma [PY]
// from reaching the sandbox without affecting Ollama Cloud or local Qwen.
$completePy = "[PY]\nprint('ok')\n[/PY]";
$openPy = "[PY]\nhtml = \"\"\"\n<html><body>incomplete";
$stopMeta = ['done_seen'=>true,'done'=>true,'done_reason'=>'stop',
  'eval_count'=>308,'prompt_eval_count'=>84];
$lengthMeta = ['done_seen'=>true,'done'=>true,'done_reason'=>'length',
  'eval_count'=>6500,'prompt_eval_count'=>90];
$okStatus = ember_console_file_model_status($completePy, $stopMeta, false);
local_file_test(($okStatus['reason'] ?? '') === 'ollama_stop_py_closed'
  && ($okStatus['source_chars'] ?? 0) === strlen($completePy)
  && ($okStatus['eval_count'] ?? 0) === 308
  && ($okStatus['prompt_eval_count'] ?? 0) === 84,
  'complete Ollama stop response must log safe diagnostic counters');
$lengthStatus = ember_console_file_model_status($openPy, $lengthMeta, true);
local_file_test(($lengthStatus['reason'] ?? '') === 'ollama_truncated_py_open',
  'unfinished local model response must retain truncated and open-marker state');
$unknownStatus = ember_console_file_model_status($openPy, [], false);
local_file_test(($unknownStatus['reason'] ?? '') === 'ollama_unknown_py_open',
  'missing Ollama done_reason must be recorded as unknown, not assumed truncated');
local_file_test(ember_console_local_gemma_file_incomplete('gemma4:e4b', $openPy, false)
  && ember_console_local_gemma_file_incomplete('gemma4:26b', $openPy, false)
  && ember_console_local_gemma_file_incomplete('gemma4:e4b', $completePy, true)
  && ember_console_local_gemma_file_incomplete('gemma4:26b', $completePy, true),
  'local Gemma must reject any open marker or confirmed truncated reply');
local_file_test(!ember_console_local_gemma_file_incomplete('gemma4:e4b', $completePy, false)
  && !ember_console_local_gemma_file_incomplete('gemma4:26b', $completePy, false),
  'complete local Gemma Python reply must retain original execution path');
local_file_test(!ember_console_local_gemma_file_incomplete('gemma4:31b-cloud', $openPy, true)
  && !ember_console_local_gemma_file_incomplete('gpt-oss:120b-cloud', $openPy, true)
  && !ember_console_local_gemma_file_incomplete('qwen3.6:35b-a3e-q4_K_M', $openPy, true),
  'Cloud models selected through Ollama and local Qwen must remain unchanged');
local_file_test(str_contains($handler, '$fileModelTruncated = $fileTask && ember_last_call_was_truncated()')
  && str_contains($handler, '$retryTruncated = ember_last_call_was_truncated()')
  && str_contains($handler, '$followTruncated = $fileTask && ember_last_call_was_truncated()')
  && str_contains($handler, '$recoveryCandidate = is_string($out) && !$followIncomplete'),
  'the initial, protocol-retry and post-worker repair paths must all be covered');

local_file_test(ember_console_local_source_failure(null,
  ['code'=>0,'err'=>'Operation timed out after 600000 milliseconds','curl_errno'=>28], false) === 'ollama_timeout',
  'Ollama transport timeout must not be logged as generic missing response');
local_file_test(ember_console_local_source_failure(null,
  ['code'=>503,'err'=>'','curl_errno'=>0],false) === 'ollama_http_error',
  'HTTP failure must be distinguishable from no-content result');
local_file_test(ember_console_local_source_failure(null,
  ['code'=>200,'ok'=>true,'done'=>true,'eval_count'=>4], false) === 'ollama_empty_content',
  'HTTP 200 without content must be recognized for one Gemma thinking retry');
local_file_test(ember_console_local_source_failure(null,
  ['err'=>'model_queue_timeout'],false) === 'model_queue_timeout',
  'model queue errors must never trigger another 600s source retry');
local_file_test(ember_console_local_source_failure('<!DOCTYPE html>',[],false) === 'source_format_unrecognized'
  && ember_console_local_source_failure('partial source',[],true) === 'model_output_truncated',
  'source format errors and token truncation must stay separate');
local_file_test(str_contains($handler, '$sourceThink = $retryWithThink ? true : $fileToolThink;')
  && str_contains($handler, '$fileToolThink, false);')
  && str_contains($handler, 'http_code')
  && str_contains($handler, 'ollama_transport_error')
  && str_contains($handler, 'if ($augmentSkills) $userPrompt = coreui_skills_augment($userPrompt);'),
  'local file calls must use focused prompts, safe diagnostics and one fallback');

local_file_test(array_keys($okStatus) === ['reason','source_chars','eval_count','prompt_eval_count'],
  'the diagnostic must contain only approved non-content fields');

echo "CoreUI local file tool selftest: OK\n";
