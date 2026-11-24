<?php
declare(strict_types=1);
// CoreAI 0.8.32: a file change whose SUCHEN text differs from the file only by copied line numbers,
// a code fence, trailing spaces or indentation is applied where those lines occur exactly once.
// Ambiguous, partial or invented text is still refused. A rejected change is told once, and the
// note promises a reread only when the work loop really reads the file again.
//   php scripts/edit-tolerance-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/console_agent_loop.php';
require_once $root . '/api/console_native_tools.php';
require_once $root . '/api/project_file_tools.php';
$chatSource = (string)file_get_contents($root . '/api/chat.php');
foreach (['ember_without_inline_code', 'ember_extract_web_marker', 'ember_extract_browse_marker', 'ember_strip_stray_tool_markers'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $chatSource, $m) !== 1) { fwrite(STDERR, "missing {$fn}\n"); exit(1); }
  eval($m[0]);
}
function et_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Edit tolerance FAIL: {$why}\n"); exit(1); }
}

// --- Markers ----------------------------------------------------------------------------------
et_test(coreui_pftools_parse_edits("  <<<<<<< SEARCH\nfoo\n  =======\nbar\n  >>>>>>> REPLACE") === [['foo', 'bar']],
  'English SEARCH/REPLACE and indented markers are read');
et_test(coreui_pftools_parse_edits("<<<<<<< SUCHEN:\na\n=======\nb\n>>>>>>> ERSETZEN:") === [['a', 'b']], 'a colon after the marker word');
et_test(coreui_pftools_parse_edits("<<<<<<< FINDEN\na\n=======\nb\n>>>>>>> ERSETZEN") === [], 'other marker words stay unknown');

// --- The same lines, written differently ---------------------------------------------------------
$file = "function add(entry) {\n    const list = load();\n    list.push(entry);\n    save(list);\n}\n\nfunction load() {\n  return [];\n}\n";
$numbered = coreui_pftools_apply_edits($file, [[" 2 |     const list = load();\n 3 |     list.push(entry);",
  " 2 |     const list = load();\n    list.unshift(entry);"]]);
et_test($numbered['ok'] && $numbered['tolerant'] === ['ohne Zeilennummern']
  && $numbered['content'] === str_replace('list.push(entry)', 'list.unshift(entry)', $file),
  'copied line numbers leave search and replacement: ' . json_encode($numbered));
$indent = coreui_pftools_apply_edits($file, [["const list = load();\nlist.push(entry);", "const list = load();\nif (entry) {\n  list.push(entry);\n}"]]);
et_test($indent['ok'] && $indent['tolerant'] === ['abweichende Einrueckung']
  && str_contains($indent['content'], "    const list = load();\n    if (entry) {\n      list.push(entry);\n    }\n    save(list);"),
  'the replacement takes over the file\'s indentation, nested lines keep theirs: ' . json_encode($indent));
$nav = "<main>\r\n    <nav><a href=\"blog.html\">Blog</a></nav>\r\n</main>\r\n";
$absolute = coreui_pftools_apply_edits($nav, [["7 | <nav><a href=\"blog.html\">Blog</a></nav>", "    <nav><a href=\"guestbook.html\">Gästebuch</a></nav>"]]);
et_test($absolute['ok'] && $absolute['content'] === "<main>\r\n    <nav><a href=\"guestbook.html\">Gästebuch</a></nav>\r\n</main>\r\n",
  'a replacement already in the file\'s indentation is not indented twice, CRLF stays: ' . json_encode($absolute));
$midLine = coreui_pftools_apply_edits("<p>Hallo <b>Welt</b></p>\n", [["1 | <b>Welt</b>", '<i>Welt</i>']]);
et_test(!$midLine['ok'], 'without its line number a fragment of a line is still no whole line');
$trailing = coreui_pftools_apply_edits($file, [["function load() {   \n  return [];  ", "function load() {\n  return JSON.parse('[]');"]]);
et_test($trailing['ok'] && $trailing['tolerant'] === ['abweichende Leerzeichen am Zeilenende']
  && str_contains($trailing['content'], "  return JSON.parse('[]');\n}\n"), 'trailing spaces');
$fenced = coreui_pftools_apply_edits($file, [["```js\n    save(list);\n```", "```js\n    save(list);\n    render(list);\n```"]]);
et_test($fenced['ok'] && $fenced['tolerant'] === ['ohne Codeblock'] && str_contains($fenced['content'], "    save(list);\n    render(list);\n}")
  && !str_contains($fenced['content'], '```'), 'a code fence around the lines goes on both sides');
$both = coreui_pftools_apply_edits($file, [["1 | function add(entry) {\n2 | const list = load();", "function add(entry, at) {\nconst list = load();"]]);
et_test($both['ok'] && $both['tolerant'] === ['ohne Zeilennummern, abweichende Einrueckung']
  && str_starts_with($both['content'], "function add(entry, at) {\n    const list = load();\n"), 'line numbers and indentation together');
$mixed = coreui_pftools_apply_edits($file, [['save(list);', 'store(list);'], ["  return []; ", '  return [1];']]);
et_test($mixed['ok'] && $mixed['tolerant'] === ['abweichende Leerzeichen am Zeilenende'] && $mixed['removed'] === 2 && $mixed['added'] === 2
  && str_contains($mixed['content'], 'store(list);') && str_contains($mixed['content'], '  return [1];'), 'an exact and a tolerant block together');
$exact = coreui_pftools_apply_edits($file, [['list.push(entry);', 'list.push(entry, 1);']]);
et_test($exact['ok'] && $exact['tolerant'] === [], 'an exact block reports no tolerance');
$crlf = coreui_pftools_apply_edits("a {\r\n  b;\r\n}\r\n", [["  b;  ", '  c;']]);
et_test($crlf['ok'] && $crlf['content'] === "a {\r\n  c;\r\n}\r\n" && $crlf['tolerant'] === ['abweichende Leerzeichen am Zeilenende'],
  'a CRLF file keeps CRLF: ' . json_encode($crlf));
$deleted = coreui_pftools_apply_edits($file, [["    save(list); ", '']]);
et_test($deleted['ok'] && !str_contains($deleted['content'], 'save(list)') && $deleted['added'] === 0, 'an empty replacement deletes the line');

// --- Still refused --------------------------------------------------------------------------------
$twice = "a {\n  x = 1;\n}\nb {\n    x = 1;\n}\n";
$ambiguous = coreui_pftools_apply_edits($twice, [["x = 1; ", 'x = 2;']]);
et_test(!$ambiguous['ok'] && $ambiguous['content'] === $twice && str_contains($ambiguous['error'], '2 Mal'),
  'lines that occur twice once whitespace is ignored change nothing: ' . json_encode($ambiguous));
$partial = coreui_pftools_apply_edits($file, [['const list = lod();', 'x']]);
et_test(!$partial['ok'] && str_contains($partial['error'], 'nicht in der Datei vor'), 'a misspelled line is still refused');
$fragment = coreui_pftools_apply_edits($file, [["list  =  load()", 'x']]);
et_test(!$fragment['ok'], 'a fragment of a line is only taken verbatim, never loosely');
$someNumbers = coreui_pftools_apply_edits("x\ny\n", [["x\n2 | y", 'z']]);
et_test(!$someNumbers['ok'], 'line numbers on only some lines are content, not copied numbering');
$dupLines = "<li>Eintrag</li>\n<p>x</p>\n<li>Eintrag</li>\n";
$dupNumbered = coreui_pftools_apply_edits($dupLines, [["1 | <li>Eintrag</li>", '<li>Neu</li>']]);
et_test(!$dupNumbered['ok'] && $dupNumbered['content'] === $dupLines && str_contains($dupNumbered['error'], '2 Mal'),
  'without line numbers the text occurs twice: nothing changes: ' . json_encode($dupNumbered));
et_test(!coreui_pftools_apply_edits($file, [["```\n\n```", 'x']])['ok'], 'an empty fence is no search text');
$second = coreui_pftools_apply_edits($file, [['save(list);', 'store(list);'], ['nicht da', 'x']]);
et_test(!$second['ok'] && $second['content'] === $file && str_contains($second['error'], 'Block 2'), 'all or nothing stays');

// --- The tool result tells Mara how her text was matched ------------------------------------------
$src = (string)file_get_contents($root . '/api/project_file_tools.php');
et_test(str_contains($src, "(SUCHEN-Text zugeordnet: ' . implode('; ', \$applied['tolerant'])")
  && coreui_agent_tool_result_state(['tool'=>'pfile_edit', 'input'=>'a.js'],
    'Datei a.js geaendert (jetzt Stand 4): 2 Zeilen ersetzt durch 2. Der vorige Stand bleibt im Tab Dateien wiederherstellbar.'
    . ' (SUCHEN-Text zugeordnet: ohne Zeilennummern. Kopiere SUCHEN beim naechsten Mal genau aus der Datei, ohne Zeilennummern.)') === 'success',
  'a tolerant change is a saved change, and Mara learns how it was matched');

// --- One honest note; the reread is promised only when it follows ----------------------------------
$miss = 'Die Datei guestbook.html wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.';
et_test(str_ends_with((string)coreui_agent_public_result_note(['tool'=>'pfile_edit', 'input'=>'guestbook.html'], 'error', $miss, true),
  'Ich lese die Datei neu und setze die Änderung genau am gespeicherten Text an.')
  && !str_contains((string)coreui_agent_public_result_note(['tool'=>'pfile_edit', 'input'=>'guestbook.html'], 'error', $miss), 'lese die Datei neu'),
  'the reread sentence only on request');
$edit = static fn(string $search): string => "[DATEI_AENDERN: guestbook.html]\n<<<<<<< SUCHEN\n{$search}\n=======\nneu\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
$model = [$edit('immer noch falsch'), 'UNEXPECTED'];
$notes = [];
$tools = ['pfile_read', 'pfile_edit', 'pfile_write', 'prun'];
coreui_agent_loop_run($edit('falsch'), coreui_agent_next_tool($edit('falsch'), $tools), 'BASIS', $tools, 0,
  static function (string $prompt) use (&$model): ?string { return array_shift($model); },
  static function (array $tool) use ($miss): string {
    return $tool['tool'] === 'pfile_read' ? "Datei guestbook.html (Stand 3):\n<form>alt</form>" : $miss;
  },
  static function (string $kind, string $state, string $title, array $meta) use (&$notes): void {
    if ($kind === 'commentary' && ($meta['source'] ?? '') === 'verified_tool_result') $notes[] = (string)$meta['public_note'];
  }, static fn(): bool => false);
et_test(count($notes) === 2 && str_contains($notes[0], 'Ich lese die Datei neu') && !str_contains($notes[1], 'Ich lese die Datei neu')
  && str_starts_with($notes[1], 'Dateiänderung nicht gespeichert'),
  'first rejection: reread announced; second rejection stops without the promise: ' . json_encode($notes));

echo "CoreAI edit tolerance: line numbers, fence, spaces and indentation matched once, ambiguity refused, one honest note OK\n";
