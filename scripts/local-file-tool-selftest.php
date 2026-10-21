<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';

function local_file_assert(bool $ok, string $message): void {
  if (!$ok) {
    fwrite(STDERR, "Local file tool regression FAILED: $message\n");
    exit(1);
  }
}

$request = 'Erstelle ein Snake-Spiel mit index.html, style.css und game.js. '
  . 'Packe alle drei Dateien in snake-game.zip und stelle es als Download bereit.';
$answer = "### index.html\n```html\n<!doctype html><html><body>Snake</body></html>\n```\n\n"
  . "### style.css\n```css\nbody { background: #111; }\n```\n\n"
  . "### game.js\n```javascript\nconsole.log('snake');\n```";
$multi = coreui_console_multifile_python($answer, $request, 100000);
local_file_assert(is_array($multi), 'complete multi-file code must be recoverable');
local_file_assert($multi['files'] === ['index.html','style.css','game.js'], 'file names and order');
local_file_assert($multi['zip'] === 'snake-game.zip', 'requested ZIP filename');
local_file_assert(str_contains($multi['code'], "zipfile.ZipFile")
  && str_contains($multi['code'], 'archive.testzip()')
  && str_contains($multi['code'], 'archive.namelist()'), 'ZIP verification in worker code');
local_file_assert(coreui_console_multifile_python(str_replace("### game.js", "### extra.js", $answer),
  $request, 100000) === null, 'missing requested file must not be invented');
local_file_assert(coreui_console_multifile_python($answer,
  'Erklaere mir die drei Dateitypen ohne Download', 100000) === null,
  'ordinary discussion must never trigger a tool');
local_file_assert(coreui_console_multifile_python($answer,
  $request, 500) === null, 'bounded tool code size');
$python = "Hier ist der Code:\n```python\n"
  . "from pathlib import Path\nimport os\n"
  . "Path(os.environ['EMBER_OUTPUT_DIR'], 'index.html').write_text('ok')\n```";
$code = coreui_console_fenced_python($python, $request, 100000);
local_file_assert(is_string($code) && str_contains($code, 'EMBER_OUTPUT_DIR'),
  'single complete Python fence recovered');
local_file_assert(coreui_console_fenced_python($python . "\n```python\nprint(2)\n```",
  $request, 100000) === null, 'ambiguous multiple Python fences rejected');
local_file_assert(coreui_console_fenced_python("```python\nprint(1)\n```",
  $request, 100000) === null, 'Python without output target rejected');
local_file_assert(str_contains(coreui_console_file_tool_prompt($request), '[PY]'),
  'local-model tool-only prompt');
local_file_assert(coreui_console_artifact_matches_request([
  ['name'=>'index.html'],['name'=>'style.css'],['name'=>'game.js'],['name'=>'snake-game.zip']
], $request), 'all requested files and ZIP present');
local_file_assert(!coreui_console_artifact_matches_request([
  ['name'=>'index.html'],['name'=>'snake-game.zip']
], $request), 'partial outputs must not be called complete');
local_file_assert(!coreui_console_artifact_matches_request([], $request),
  'no fake download without worker bytes');
$stream = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
$chat = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
local_file_assert(str_contains($stream, 'elseif ($fileToolTurn)')
  && str_contains($stream, 'Dateiwerkzeug vorbereitet')
  && str_contains($chat, 'coreui_console_file_tool_prompt($userMsg)')
  && str_contains($chat, 'coreui_console_multifile_python($answer, $userMsg')
  && str_contains($chat, 'coreui_console_artifact_matches_request($confirmedFile'),
  'local file workflow and completeness checks integrated');
echo "CoreUI local-model file tool selftest: OK\n";
