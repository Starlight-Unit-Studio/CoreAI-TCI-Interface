<?php
declare(strict_types=1);
// CoreAI stabilize 0.8.33: verified bugs in Mara's work loop, each with a scripted, offline run.
//  F1  only the server-written status decides a step (file text or program output never does)
//  F3  a tool block sent together with DONE runs first; the repair says nothing ran
//  F4  a run that alternates between the same unchanged results stops by itself
//  F5  a web-only answer in a chat with a workspace keeps the six-step research bound
//  F6  building a file section by section is not counted as "changing the same file again"
//  F7  a rejected change no longer blocks DONE once the file shows it or was read again
//  F8  a change refused because the file changed meanwhile is reread; a missing file is correctable
//  F9  a SEARCH text with a Markdown heading line "=======" never splits at the wrong separator
//  F10 many failed results are shortened in the prompt (newest complete)
//  F11 a file block in a reply above 250 KB is still found
//  F12 conflicting merge, waiting server, crashing program: the right state
//  F13 every path the file store accepts gets a journal target
//  P1  a repeated unchanged read is shortened in the NEWEST step, never in the middle of the prompt
//  P3  every repair prompt starts with the step prompt just sent
//  P6  loading a skill in a project run leaves the start of the prompt unchanged
//  and the work event "Projektauftrag unterbrochen, Dateien bleiben erhalten" fits the work store.
// No Ollama, no network, no database.
//   php scripts/stabilize-loop-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/console_agent_loop.php';
require_once $root . '/api/console_native_tools.php';
require_once $root . '/api/project_file_tools.php';
require_once $root . '/api/skills.php';
$chatSource = (string)file_get_contents($root . '/api/chat.php');
foreach (['ember_without_inline_code', 'ember_extract_web_marker', 'ember_extract_browse_marker', 'ember_strip_stray_tool_markers'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $chatSource, $m) !== 1) { fwrite(STDERR, "missing {$fn}\n"); exit(1); }
  eval($m[0]);
}
function sl_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Stabilize loop FAIL: {$why}\n"); exit(1); }
}
const SL_PROJECT = ['pfile_read', 'pfile_edit', 'pfile_write', 'prun', 'pproc_start', 'pserver_start', 'pproc_output',
  'pproc_input', 'pproc_stop', 'pserver_fetch', 'pvcs_commit', 'pvcs_branch', 'pvcs_merge'];
$done = static fn(string $t): string => "[COREUI_TASK_DONE]\n" . $t . "\n[/COREUI_TASK_DONE]";

/** One scripted run: replies of the Core, results of the tools, every prompt, event and tool call. */
function sl_run(string $first, array $replies, callable $result, array $allowed = SL_PROJECT, ?callable $report = null): array {
  $prompts = []; $events = []; $tools = [];
  $loop = coreui_agent_loop_run($first, coreui_agent_next_tool($first, $allowed), 'BASIS', $allowed, 0,
    static function (string $p) use (&$replies, &$prompts): ?string { $prompts[] = $p; return array_shift($replies); },
    static function (array $t) use (&$tools, $result): string|array { $tools[] = $t; return $result($t, count($tools)); },
    static function (string $k, string $s, string $t, array $m) use (&$events): void { $events[] = ['kind'=>$k, 'state'=>$s, 'title'=>$t, 'meta'=>$m]; },
    static fn(): bool => false, null, null, $report);
  return ['loop'=>$loop, 'prompts'=>$prompts, 'events'=>$events, 'tools'=>$tools, 'left'=>$replies];
}
$reasons = static fn(array $r): array => array_map(static fn(array $e): string => (string)($e['meta']['reason'] ?? ''), $r['events']);
$saved = static fn(array $t, int $n): string => 'Datei ' . trim(explode("\n", (string)$t['input'], 2)[0])
  . ($t['tool'] === 'pfile_write' ? ' angelegt (Stand ' . $n . ', 40 Bytes).' : ' geaendert (jetzt Stand ' . $n . '): 1 Zeilen ersetzt durch 2.');
$stepsPart = static fn(string $p): string => substr($p, 0, (int)strpos($p, "\n--- ENDE ARBEITSSCHRITTE ---"));

// --- F1: the status, not the content -------------------------------------------------------------
$read = ['tool'=>'pfile_read', 'input'=>'notes.md'];
sl_test(coreui_agent_tool_result_state($read, "Datei notes.md (Stand 2, 40 Bytes):\n1 | Der Entwurf wurde nicht gespeichert.\n"
  . '(Die Zeilennummern und " | " gehoeren nicht zur Datei.)') === 'success',
  'F1: reading a file that contains "nicht gespeichert" is a successful read');
sl_test(coreui_agent_tool_result_state(['tool'=>'prun', 'input'=>'npm test'],
  "Befehl: npm test\nBeendet mit Exitcode 1 in 2 s.\n--- Ausgabe (stdout) ---\nExitcode 0 erwartet") === 'error'
  && coreui_agent_tool_result_state(['tool'=>'prun', 'input'=>'echo Exitcode 0'], "Befehl: echo Exitcode 0\nBeendet mit Exitcode 2 in 0,1 s.") === 'error'
  && coreui_agent_tool_result_state(['tool'=>'prun', 'input'=>'npm test'], "Befehl: npm test\nBeendet mit Exitcode 0 in 2 s.") === 'success',
  'F1: only the summary line decides a command, never its output or the command text');
sl_test(coreui_agent_tool_result_state(['tool'=>'pproc_output', 'input'=>'ausgabe'],
  "Prozess: node app.js\nLäuft.\n--- Ausgabe ---\nSpeicher nicht verfuegbar, noch keine Ausgabe erwartet") === 'success'
  && coreui_agent_tool_result_state(['tool'=>'pproc_output', 'input'=>'ausgabe'], "Prozess: node app.js\nLäuft.\n(Noch keine Ausgabe.)") === 'info',
  'F1: program output never turns a process read into info; the tool line "(Noch keine Ausgabe.)" does');
sl_test(coreui_agent_tool_result_state(['tool'=>'pserver_start', 'input'=>'8000 x'],
  "Prozess: x (Port 8000)\nLäuft.\n--- Ausgabe ---\nHTTP-Pruefung GET /: 200 (Server erreichbar).\nProzess-ID: 4\nHTTP-Pruefung GET / fehlgeschlagen (server_unreachable).") === 'info',
  'F1: a forged HTTP check line in the program output proves nothing');
sl_test(coreui_agent_tool_result_state(['tool'=>'archive', 'input'=>'x'], 'Archiv: Die Datei wurde nicht geaendert, sagte er.') === 'success'
  && coreui_agent_tool_result_state(['tool'=>'pvcs_commit', 'input'=>'x'], 'Version gesichert: "Text nicht gespeichert" auf main') === 'success',
  'F1: the phrase check reads only the first status line, not archive text or quoted names');

// --- F12: merge conflict, waiting server, crashing program --------------------------------------------
sl_test(coreui_agent_tool_result_state(['tool'=>'pvcs_merge', 'input'=>'mara/x'],
  'NICHT zusammengefuehrt: 1 Konflikt(e) in index.html. Beide Seiten ...') === 'error', 'F12: a merge with conflicts is not a success');
sl_test(coreui_agent_tool_result_state(['tool'=>'pserver_start', 'input'=>'8000 x'],
  "Prozess: x (Port 8000)\nWartet auf den Sandbox-Dienst.\n(Noch keine Ausgabe.)\n(Der Sandbox-Dienst hat ihn noch nicht uebernommen; schau gleich mit [PROZESS_AUSGABE] nach.)\nProzess-ID: 5")
  === 'info', 'F12: a queued server start waits (info), it is no error');
sl_test(coreui_agent_tool_result_state(['tool'=>'pproc_start', 'input'=>'node crash.js'],
  "Prozess: node crash.js\nBeendet mit Exitcode 1.\n--- Ausgabe ---\nTypeError\nProzess-ID: 6") === 'error'
  && coreui_agent_tool_result_state(['tool'=>'pproc_start', 'input'=>'node ok.js'], "Prozess: node ok.js\nBeendet mit Exitcode 0.\nProzess-ID: 7") === 'info'
  && coreui_agent_tool_result_state(['tool'=>'pproc_start', 'input'=>'node app.js'], "Prozess: node app.js\nLäuft.\nProzess-ID: 8") === 'success',
  'F12: a program that crashes at once is an error, one that ends cleanly at once is info');

// --- F13: every path the file store accepts -------------------------------------------------------
sl_test(coreui_agent_file_target(['tool'=>'pfile_write', 'input'=>"a..b.js\nx"]) === 'a..b.js'
  && coreui_agent_file_target(['tool'=>'pfile_edit', 'input'=>"src\\app.js\nx"]) === 'src/app.js'
  && coreui_agent_file_target(['tool'=>'pfile_read', 'input'=>'./css//site.css#L20']) === 'css/site.css'
  && coreui_agent_file_target(['tool'=>'pfile_edit', 'input'=>"../geheim.js\nx"]) === ''
  && coreui_agent_file_target(['tool'=>'pfile_write', 'input'=>"bild.exe\nx"]) === '',
  'F13: the journal target is the path the file store saves, and only such a path');

// --- Title fix ---------------------------------------------------------------------------------------
$stopped = sl_run('[AUSFUEHREN: npm test]', ['[AUSFUEHREN: npm test]', '[AUSFUEHREN: npm test]'],
  static fn(array $t): string => "Befehl: npm test\nBeendet mit Exitcode 1 in 1 s.");
$stopTitles = array_column(array_filter($stopped['events'], static fn(array $e): bool => $e['kind'] === 'run'), 'title');
sl_test(in_array('Projektauftrag unterbrochen, Dateien bleiben erhalten', $stopTitles, true), 'title: the interruption event is recorded');
foreach (array_unique(array_column($stopped['events'], 'title')) as $title) {
  sl_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $title) === 1 || preg_match('/[äöüÄÖÜß]/u', $title) === 1,
    'title: no semicolon or other character the work store refuses: ' . $title);
}
$loopSource = (string)file_get_contents($root . '/api/console_agent_loop.php');
preg_match_all("~\\\$record\\('[a-z]+',\s*'[a-z]+',\s*'([^']+)'~", $loopSource, $titleHits);
foreach ($titleHits[1] as $title) {
  sl_test(!str_contains($title, ';') && !str_contains($title, "\u{2013}") && !str_contains($title, "\u{2014}"), 'title without ; or long dash: ' . $title);
}

// --- F3: a tool block sent together with DONE -----------------------------------------------------
$bat = sl_run("[DATEI_NEU: a.html]\n<h1>A</h1>\n[/DATEI_NEU]",
  ["[DATEI_NEU: b.html]\n<h1>B</h1>\n[/DATEI_NEU]\n" . $done('a.html und b.html sind angelegt.'), $done('a.html und b.html sind angelegt.')],
  $saved);
$written = array_map(static fn(array $t): string => trim(explode("\n", (string)$t['input'], 2)[0]), $bat['tools']);
sl_test($written === ['a.html', 'b.html'] && ($bat['loop']['outcome'] ?? '') === 'completed'
  && in_array('task_done_before_tool', $reasons($bat), true),
  'F3: write a.html, then [write b.html + DONE], then DONE: b.html is really written before the run completes: ' . json_encode([$written, $bat['loop']['outcome']]));
$inside = sl_run("[DATEI_NEU: a.html]\n<h1>A</h1>\n[/DATEI_NEU]",
  ["[COREUI_TASK_DONE]\nFertig.\n[DATEI_NEU: b.html]\n<h1>B</h1>\n[/DATEI_NEU]\n[/COREUI_TASK_DONE]", "[DATEI_NEU: b.html]\n<h1>B</h1>\n[/DATEI_NEU]",
    $done('Fertig.')], $saved);
$repair = (string)($inside['prompts'][1] ?? '');
sl_test(str_contains($repair, 'Davon wurde NICHTS gespeichert oder ausgefuehrt') && str_contains($repair, 'sende NUR den Werkzeugmarker')
  && !str_contains($repair, 'nicht erneut ausfuehren)') && count($inside['tools']) === 2 && ($inside['loop']['outcome'] ?? '') === 'completed',
  'F3: a tool inside the DONE block is not run; the repair says nothing ran and the tool must come alone');
$proseRepair = coreui_task_decision_repair_prompt('KONTEXT', 'prose_only', "Hier die Seite:\n[DATEI NEU: c.html]\n<p>x</p>");
sl_test(str_contains($proseRepair, 'nichts daraus wurde gespeichert oder ausgefuehrt') && !str_contains($proseRepair, 'nicht erneut ausfuehren)')
  && str_contains($proseRepair, 'wurde er NICHT ausgefuehrt'),
  'F3: the echo of a refused reply says that nothing of it ran');

// --- F4: alternating unchanged results stop by themselves -----------------------------------------
$calls = 0;
$osc = coreui_agent_loop_run('[SERVER_ABRUF: /]', ['tool'=>'pserver_fetch', 'input'=>'/'], 'BASIS', SL_PROJECT, 0,
  static function (string $p) use (&$calls): ?string { $calls++; return $calls % 2 ? '[PROZESS_AUSGABE]' : '[SERVER_ABRUF: /]'; },
  static fn(array $t): string => $t['tool'] === 'pserver_fetch' ? "GET /: HTTP 500 (text/html)\n--- Inhalt ---\nError"
    : "Prozess: npm run dev (Port 5173)\nLäuft.\n--- Ausgabe ---\nready",
  static function (string $k, string $s, string $t, array $m): void {},
  static function () use (&$calls): bool { return $calls >= 200; });
sl_test(($osc['reason'] ?? '') === 'no_progress' && $calls <= 6 && ($osc['outcome'] ?? '') === 'interrupted',
  'F4: SERVER_ABRUF (HTTP 500) and PROZESS_AUSGABE alternating stop on their own: ' . $calls . ' model calls, ' . ($osc['reason'] ?? ''));
$fixRun = sl_run('[AUSFUEHREN: npm test]', ["[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nalt\n=======\nneu\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
    '[AUSFUEHREN: npm test]', "[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nneu\n=======\nneuer\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
    '[AUSFUEHREN: npm test]', $done('Tests laufen.')],
  static fn(array $t, int $n): string => $t['tool'] === 'prun'
    ? ($n < 5 ? "Befehl: npm test\nBeendet mit Exitcode 1 in 1 s." : "Befehl: npm test\nBeendet mit Exitcode 0 in 1 s.")
    : 'Datei app.js geaendert (jetzt Stand ' . $n . '): 1 Zeilen ersetzt durch 1.');
sl_test(($fixRun['loop']['outcome'] ?? '') === 'completed' && count($fixRun['tools']) === 5,
  'F4: the same failing test between saved changes is progress, not a stop');

// --- F5: web-only answers keep the research bound ---------------------------------------------------
$webAllowed = array_merge(['web', 'python'], ['pfile_read', 'pfile_edit', 'pfile_write', 'prun']);
$noHit = sl_run('[WEB: Kinoprogramm Bochum heute]', ['Dazu habe ich leider keine aktuellen Treffer gefunden.'],
  static fn(array $t): array => ['text'=>'In dieser Suchrunde gab es keine weiteren neuen, verwertbaren Treffer.', 'state'=>'error',
    'reason'=>'research_no_search_results'], $webAllowed);
sl_test(($noHit['loop']['outcome'] ?? '') === 'completed' && $noHit['loop']['out'] === 'Dazu habe ich leider keine aktuellen Treffer gefunden.'
  && !str_contains((string)$noHit['loop']['out'], 'bestätigt') && !str_contains($noHit['prompts'][0], 'Aktiver Projektauftrag'),
  'F5: a no-hit search and an honest prose answer complete as a research answer: ' . json_encode($noHit['loop']));
$long = sl_run('[WEB: a0]', array_map(static fn(int $i): string => '[WEB: a' . $i . ']', range(1, 12)),
  static fn(array $t): string => '1. Treffer ' . $t['input'] . "\n   https://example.org/" . $t['input'], $webAllowed);
sl_test(count($long['tools']) === 6 && ($long['loop']['outcome'] ?? '') === 'completed'
  && str_contains(end($long['prompts']), 'Das war dein letzter Schritt'),
  'F5: a web-only run with project tools allowed stops after six steps: ' . count($long['tools']));
$framed = sl_run('[WEB: x]', [$done('Die Antwort steht hier.')], static fn(array $t): string => '1. Treffer', $webAllowed);
sl_test($framed['loop']['out'] === 'Die Antwort steht hier.', 'F5: a research answer in a DONE block shows its text only');
$switch = sl_run('[WEB: Farben]', ["[DATEI_NEU: farben.md]\n# Farben\n[/DATEI_NEU]", 'Fertig, die Datei ist da.', 'Fertig, die Datei ist da.'],
  static fn(array $t, int $n): string => $t['tool'] === 'web' ? '1. Treffer' : 'Datei farben.md angelegt (Stand 1, 9 Bytes).', $webAllowed);
sl_test(!str_contains($switch['prompts'][0], 'Aktiver Projektauftrag') && str_contains($switch['prompts'][1] ?? '', 'Aktiver Projektauftrag')
  && str_contains($switch['prompts'][2] ?? '', 'keine gueltige Steuerentscheidung') && ($switch['loop']['outcome'] ?? '') === 'completed',
  'F5: the first project tool turns the run into a project run with the DONE protocol (prose gets the one repair)');

// Review 0.8.34: research first, then the file the person asked for. The file after the sixth research step
// still runs, turns the run into a project run and the DONE finishes it (it was dropped without a word).
$reportReplies = array_map(static fn(int $i): string => '[WEB: quelle ' . $i . ']', range(1, 5));
$reportReplies[] = "[DATEI_NEU: report.md]\n# Bericht\nInhalt\n[/DATEI_NEU]";
$reportReplies[] = $done('Bericht gespeichert.');
$report = sl_run('[WEB: quelle 0]', $reportReplies,
  static fn(array $t, int $n): string => $t['tool'] === 'web' ? '1. Treffer ' . $n . "\n   https://example.org/" . $n
    : 'Datei report.md angelegt (Stand 1, 18 Bytes).', $webAllowed);
sl_test(array_column($report['tools'], 'tool') === ['web', 'web', 'web', 'web', 'web', 'web', 'pfile_write']
  && ($report['loop']['outcome'] ?? '') === 'completed' && $report['loop']['out'] === 'Bericht gespeichert.' && $report['left'] === []
  && str_contains($report['prompts'][5], 'Verlangt der Auftrag eine Projektdatei, schreibe sie stattdessen jetzt mit genau einem Dateimarker.'),
  'research, then the file after the sixth step: written and finished: ' . json_encode([array_column($report['tools'], 'tool'), $report['loop']['out'] ?? null]));
$plain = sl_run('[WEB: a0]', array_merge(array_map(static fn(int $i): string => '[WEB: a' . $i . ']', range(1, 5)), ['[WEB: noch eins]']),
  static fn(array $t): string => '1. Treffer', $webAllowed);
sl_test(count($plain['tools']) === 6, 'after six research steps a seventh search still does not run: ' . count($plain['tools']));

// --- F6: section by section is not "the same file again" ------------------------------------------------
$sections = [];
for ($i = 1; $i <= 12; $i++) {
  $sections[] = "[DATEI_AENDERN: index.html]\n<<<<<<< SUCHEN\n<!-- weiter -->\n=======\n<section id=\"s{$i}\">Abschnitt {$i}</section>\n<!-- weiter -->\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
}
$sections[] = $done('Seite fertig.');
$build = sl_run("[DATEI_NEU: index.html]\n<main><!-- weiter --></main>\n[/DATEI_NEU]", $sections, $saved);
sl_test(count($build['tools']) === 13 && ($build['loop']['outcome'] ?? '') === 'completed'
  && !array_filter($build['prompts'], static fn(string $p): bool => str_contains($p, 'ABSCHLUSS PRUEFEN')),
  'F6: a skeleton and twelve section insertions neither ask for a check nor pause: ' . json_encode([count($build['tools']), $build['loop']['reason']]));
$grow = ["<h1>Blog</h1>"];
$growReplies = [];
for ($i = 1; $i <= 11; $i++) { $grow[] = '<p>Beitrag ' . $i . '</p>'; $growReplies[] = "[DATEI_NEU: blog.html]\n" . implode("\n", $grow) . "\n[/DATEI_NEU]"; }
$growReplies[] = $done('Blog fertig.');
$grown = sl_run("[DATEI_NEU: blog.html]\n<h1>Blog</h1>\n[/DATEI_NEU]", $growReplies,
  static fn(array $t, int $n): string => 'Datei blog.html ' . ($n === 1 ? 'angelegt' : 'ganz ersetzt') . ' (Stand ' . $n . ', 99 Bytes).');
sl_test(count($grown['tools']) === 12 && ($grown['loop']['outcome'] ?? '') === 'completed',
  'F6: whole-file writes that only add lines are not counted');
$flip = [];
for ($i = 1; $i <= 12; $i++) $flip[] = "[DATEI_NEU: style.css]\n" . ($i % 2 ? 'body { color: red; }' : 'body { color: blue; }') . "\n[/DATEI_NEU]";
$flipped = sl_run("[DATEI_NEU: style.css]\nbody { color: blue; }\n[/DATEI_NEU]", $flip,
  static fn(array $t, int $n): string => 'Datei style.css ' . ($n === 1 ? 'angelegt' : 'ganz ersetzt') . ' (Stand ' . $n . ', 22 Bytes).');
sl_test(($flipped['loop']['reason'] ?? '') === 'same_file_changed_repeatedly' && count($flipped['tools']) === 11,
  'F6: rewriting a file back and forth still pauses after ten counted changes: ' . count($flipped['tools']));
// An insertion that brings back an earlier version is counted.
$back = coreui_agent_change_moves_forward(['tool'=>'pfile_edit', 'input'=>"a.txt\n<<<<<<< SUCHEN\nx\n=======\nx\ny\n>>>>>>> ERSETZEN"],
  'Datei a.txt geaendert', "x", [hash('sha256', "x\ny")=>true]);
$ahead = coreui_agent_change_moves_forward(['tool'=>'pfile_edit', 'input'=>"a.txt\n<<<<<<< SUCHEN\nx\n=======\nx\ny\n>>>>>>> ERSETZEN"],
  'Datei a.txt geaendert', "x", []);
sl_test($back['forward'] === false && $ahead['forward'] === true && $ahead['content'] === "x\ny",
  'F6: an insertion that restores an earlier content hash counts, a new one does not');

// --- F7: a rejected change that is already in the file ---------------------------------------------
$present = sl_run("[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 1;\n=======\nlet a = 2;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  [$done('app.js setzt a auf 2.')],
  static fn(array $t): string => $t['tool'] === 'pfile_read'
    ? "Datei app.js (Stand 4, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 2;\n"
    : 'Die Datei app.js wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor (lies sie mit [DATEI: pfad] ...).');
sl_test(($present['loop']['outcome'] ?? '') === 'completed' && in_array('edit_already_present', $reasons($present), true)
  && !str_contains($present['prompts'][0], 'DATEIÄNDERUNG KORRIGIEREN') && str_contains($present['prompts'][0], 'steht bereits in der Datei'),
  'F7: the reread shows the replacement text already in the file; DONE is accepted: ' . json_encode($present['loop']));
$reread = sl_run("[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 1;\n=======\nlet a = 3;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  ['[DATEI: app.js]', $done('app.js geprueft, die Aenderung ist nicht noetig.')],
  static fn(array $t): string => $t['tool'] === 'pfile_read'
    ? (empty($t['recovery_read']) ? "Datei app.js (Stand 4, 11 Bytes):\n1 | let a = 2;\n(Die Zeilennummern und \" | \" gehoeren nicht zur Datei.)"
      : "Datei app.js (Stand 4, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 2;\n")
    : 'Die Datei app.js wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.');
sl_test(($reread['loop']['outcome'] ?? '') === 'completed' && count($reread['tools']) === 3,
  'F7: a later successful read of the file clears the rejected change: ' . json_encode($reread['loop']));
$ranAfter = sl_run("[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 1;\n=======\nlet a = 3;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  ['[AUSFUEHREN: node --check app.js]', $done('app.js laeuft.')],
  static fn(array $t): string => match ($t['tool']) {
    'pfile_read' => "Datei app.js (Stand 4, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 2;\n",
    'prun' => "Befehl: node --check app.js\nBeendet mit Exitcode 0 in 0,1 s.",
    default => 'Die Datei app.js wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.'});
sl_test(($ranAfter['loop']['outcome'] ?? '') === 'completed', 'F7: a later successful run of the file clears the rejected change');
$stillOpen = sl_run("[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 1;\n=======\nlet a = 3;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  ['[AUSFUEHREN: node --check main.js]', $done('Fertig.')],
  static fn(array $t): string => match ($t['tool']) {
    'pfile_read' => "Datei app.js (Stand 4, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 2;\n",
    'prun' => "Befehl: node --check main.js\nBeendet mit Exitcode 0 in 0,1 s.",
    default => 'Die Datei app.js wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.'});
sl_test(($stillOpen['loop']['outcome'] ?? '') === 'interrupted' && ($stillOpen['loop']['reason'] ?? '') === 'task_completion_unverified',
  'F7: a run of another file does not clear the rejected change of app.js');

// --- F8: file changed meanwhile, missing file ----------------------------------------------------
$changed = sl_run("[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 1;\n=======\nlet a = 2;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  ["[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 5;\n=======\nlet a = 2;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]", $done('a ist 2.')],
  static function (array $t, int $n): string {
    if ($t['tool'] === 'pfile_read') return "Datei app.js (Stand 5, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 5;\n";
    return $n === 1 ? 'Das ging nicht: die datei wurde gerade anders geaendert; lies sie neu.' : 'Datei app.js geaendert (jetzt Stand 6): 1 Zeilen ersetzt durch 1.';
  });
sl_test(array_column($changed['tools'], 'tool') === ['pfile_edit', 'pfile_read', 'pfile_edit'] && !empty($changed['tools'][1]['recovery_read'])
  && ($changed['loop']['outcome'] ?? '') === 'completed',
  'F8: a change refused because the file changed meanwhile gets the one reread: ' . json_encode([array_column($changed['tools'], 'tool'), $changed['loop']['reason']]));
$missing = sl_run("[DATEI_AENDERN: about.html]\n<<<<<<< SUCHEN\n<h1>x</h1>\n=======\n<h1>y</h1>\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  ["[DATEI_NEU: about.html]\n<h1>y</h1>\n[/DATEI_NEU]", $done('about.html angelegt.')],
  static fn(array $t): string => $t['tool'] === 'pfile_edit'
    ? "Das ging nicht: diese Datei gibt es im Projekt nicht.\nVorhandene Dateien im gebundenen Arbeitsbereich:\nindex.html\nAendere einen vorhandenen Pfad oder lege eine neue Datei mit [DATEI_NEU: pfad] an."
    : 'Datei about.html angelegt (Stand 1, 12 Bytes).');
sl_test(($missing['loop']['outcome'] ?? '') === 'completed' && count($missing['tools']) === 2
  && str_contains($missing['prompts'][0], 'Vorhandene Dateien im gebundenen Arbeitsbereich'),
  'F8: a change of a missing file goes back to the Core with the file list and the run continues: ' . json_encode($missing['loop']));
sl_test(coreui_agent_edit_failure_reason('Das ging nicht: diese Datei gibt es im Projekt nicht.') === 'Datei gibt es im Projekt nicht',
  'F8: the public reason of a missing file');
$pftSource = (string)file_get_contents($root . '/api/project_file_tools.php');
sl_test(str_contains($pftSource, "if (\$kind==='pfile_edit' && \$e->getMessage()==='file_not_found') {"),
  'F8: the file tool lists the existing files for a change of a missing file');

// --- F9: a Markdown heading line inside SEARCH ------------------------------------------------------
// A heading underline in SEARCH: the first separator line used to cut SEARCH short and wrote the rest
// of the block into the file. Two splits fit here, so nothing changes and the Core is told why.
$readme = "Install\n=======\n\nRun setup.sh.\n";
$body = "<<<<<<< SUCHEN\nInstall\n=======\n\nRun setup.sh.\n=======\nInstall\n=======\n\nRun ./setup.sh first.\n>>>>>>> ERSETZEN";
$applied = coreui_pftools_apply_edits($readme, coreui_pftools_parse_edit_blocks($body));
sl_test(!$applied['ok'] && str_contains($applied['error'], 'nicht eindeutig') && $applied['content'] === $readme
  && coreui_agent_edit_failure_reason('Die Datei README.md wurde NICHT geaendert: ' . $applied['error']) === 'Trennzeile im Änderungsblock ist nicht eindeutig',
  'F9: an ambiguous separator changes nothing and names the reason: ' . json_encode($applied));
// The section title occurs twice in the file, so only the full SEARCH with its underline fits once.
$doc = "Install\n=======\nA\n\nSiehe Install.\nInstall\n";
$unique = coreui_pftools_apply_edits($doc, coreui_pftools_parse_edit_blocks(
  "<<<<<<< SUCHEN\nInstall\n=======\nA\n=======\nInstall\n=======\nB\n>>>>>>> ERSETZEN"));
sl_test($unique['ok'] && $unique['content'] === "Install\n=======\nB\n\nSiehe Install.\nInstall\n",
  'F9: of several splits the one whose SEARCH occurs exactly once is used: ' . json_encode($unique));
$heading = coreui_pftools_apply_edits("Intro text.\n", coreui_pftools_parse_edit_blocks(
  "<<<<<<< SUCHEN\nIntro text.\n=======\nInstall\n=======\n\nIntro text.\n>>>>>>> ERSETZEN"));
sl_test($heading['ok'] && $heading['content'] === "Install\n=======\n\nIntro text.\n", 'F9: a new heading in REPLACE is written as it is');
sl_test(coreui_pftools_parse_edits("<<<<<<< SUCHEN\na\n=======\nb\n>>>>>>> ERSETZEN\n<<<<<<< SUCHEN\nc\n=======\n>>>>>>> ERSETZEN") === [['a', 'b'], ['c', '']],
  'F9: ordinary blocks parse as before');

// --- F11: a file block in a very large reply ---------------------------------------------------------
$page = str_repeat("<p>Absatz mit Text, der die Seite lang macht.</p>\n", 6000);
$big = "[DATEI_NEU: gross.html]\n" . $page . "[/DATEI_NEU]";
sl_test(strlen($big) > 250000 && coreui_pftools_extract_write($big) === "gross.html\n" . rtrim($page, "\n")
  && coreui_agent_next_tool($big, SL_PROJECT)['tool'] === 'pfile_write',
  'F11: a complete file block above 250 KB is found: ' . strlen($big));
sl_test(coreui_pftools_extract_write("[DATEI_NEU: a.html]\n<p>x</p>\n[/DATEI_NEU]") === "a.html\n<p>x</p>"
  && coreui_pftools_extract_write("[DATEI_NEU: a.html] \r\n  <p>x</p>\r\n  [/DATEI_NEU]") === "a.html\n  <p>x</p>"
  && coreui_pftools_extract_write("[DATEI_NEU: a.html]\n<p>x</p>") === null,
  'F11: line ends around the body are handled as before; an open block is no block');

// --- F10: many failed results ----------------------------------------------------------------------
$fails = array_fill(0, 13, '[AUSFUEHREN: npm test]');
$fails[] = "[COREUI_TASK_BLOCKED]\nDie Tests scheitern weiter.\n[/COREUI_TASK_BLOCKED]";
$failRun = sl_run('[AUSFUEHREN: npm test]', $fails,
  static fn(array $t, int $n): string => "Befehl: npm test\nBeendet mit Exitcode 1 in 3 s.\n--- Fehlerausgabe (stderr) ---\nLAUF_" . $n . '_'
    . str_repeat('e', 8000) . '_ENDE_' . $n);
$maxPart = max(array_map(static fn(string $p): int => strlen($stepsPart($p)), $failRun['prompts']));
$lastPrompt = (string)end($failRun['prompts']);
sl_test(count($failRun['tools']) === 14 && $maxPart <= COREUI_AGENT_PROJECT_RESULT_HIGH + 4000
  && str_contains($lastPrompt, 'LAUF_14_' . str_repeat('e', 8000) . '_ENDE_14') && str_contains($lastPrompt, 'aelteres Fehlerergebnis gekuerzt')
  && !str_contains($lastPrompt, 'LAUF_2_' . str_repeat('e', 8000)) && str_contains($lastPrompt, '_ENDE_2'),
  'F10: fourteen failed test runs stay under the high mark, newest complete, older shortened: ' . $maxPart);

// --- P1: an unchanged reread is shortened in the newest step -------------------------------------------
$file = 'QUELLE_' . str_repeat('q', 9000);
$dup = sl_run('[DATEI: index.html]', ['[DATEI: style.css]', '[DATEI: index.html]', $done('Gelesen.')],
  static fn(array $t): string => "Datei {$t['input']} (Stand 1, 9 KB):\n" . $t['input'] . '_' . $file);
sl_test(substr_count($dup['prompts'][2], 'index.html_QUELLE_') === 1 && str_contains($dup['prompts'][2], '[Unveraendert wie in Schritt 1:')
  && str_starts_with($stepsPart($dup['prompts'][2]), $stepsPart($dup['prompts'][1])),
  'P1: the newer copy of an unchanged read refers to the older one; earlier steps stay byte for byte');

// --- P3: repairs start with the step prompt just sent ------------------------------------------------
$bigRead = static fn(array $t): string => 'Datei ' . $t['input'] . ' (Stand 1): ' . str_repeat('w', 5000);
$prose = sl_run('[DATEI: index.html]', ['[DATEI: style.css]', 'Ich bin fertig.', $done('Fertig.')], $bigRead);
sl_test(count($prose['prompts']) === 3 && str_starts_with($prose['prompts'][2], $prose['prompts'][1])
  && substr_count($prose['prompts'][2], 'AUFTRAGSSTEUERUNG (verbindlich') === 1,
  'P3: the decision repair is the step prompt plus the correction, the protocol once');
$denied = "[COREUI_TASK_BLOCKED]\nIch habe keine Testwerkzeuge in dieser Sitzung.\n[/COREUI_TASK_BLOCKED]";
$exec = sl_run('[DATEI: index.html]', ['[DATEI: style.css]', $denied, '[AUSFUEHREN: npm test]', $done('Getestet.')],
  static fn(array $t): string => $t['tool'] === 'prun' ? "Befehl: npm test\nBeendet mit Exitcode 0 in 1 s." : $bigRead($t));
sl_test(count($exec['prompts']) === 4 && str_starts_with($exec['prompts'][2], $exec['prompts'][1])
  && str_contains($exec['prompts'][2], 'Marker als Antworttext') && ($exec['loop']['outcome'] ?? '') === 'completed',
  'P3: the execution repair is the step prompt plus the offer');
$recover = sl_run('[DATEI: app.js]', ["[DATEI_AENDERN: app.js]\n<<<<<<< SUCHEN\nlet a = 9;\n=======\nlet a = 2;\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
    $done('Abgebrochen.')],
  static fn(array $t): string => $t['tool'] === 'pfile_read'
    ? (empty($t['recovery_read']) ? 'Datei app.js (Stand 1): ' . str_repeat('z', 5000)
      : "Datei app.js (Stand 1, 11 Bytes):\nGespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\nlet a = 1;\n")
    : 'Die Datei app.js wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.');
sl_test(count($recover['prompts']) === 2 && str_contains($recover['prompts'][1], 'DATEIÄNDERUNG KORRIGIEREN')
  && str_starts_with($recover['prompts'][1], $stepsPart($recover['prompts'][0])) && str_contains($recover['prompts'][1], str_repeat('z', 5000)),
  'P3: the edit repair after the reread starts with the steps of the previous prompt (no compacted prompt)');

// --- P6: a skill loaded in a loop leaves the prompt start unchanged --------------------------------------
$GLOBALS['COREUI_SKILLS_OWN'] = [];
$GLOBALS['COREUI_SKILLS_TOOLS'] = null;
$GLOBALS['COREUI_REQUIRED_SKILLS_BLOCK'] = "[COREAI KERN-SKILLS]\nKern\n[ENDE COREAI KERN-SKILLS]\n\n";
$GLOBALS['COREUI_SKILLS_LOADED'] = [];
$GLOBALS['COREUI_SKILLS_INDEX_SIZE'] = 'ids';
$GLOBALS['COREUI_SKILLS_BUDGET'] = 'ids';
coreui_skills_compose();
$skillId = (string)array_key_first(array_filter(coreui_skills_catalog(), static fn(array $s): bool => ($s['required'] ?? false) !== true));
$sent = [];
$skillReplies = ['[SKILL: ' . $skillId . ']', $done('Gelesen und nach der Anleitung geprueft.')];
$skillRun = coreui_agent_loop_run('[DATEI: index.html]', ['tool'=>'pfile_read', 'input'=>'index.html'], 'BASIS',
  array_merge(SL_PROJECT, ['skill']), 0,
  static function (string $p) use (&$skillReplies, &$sent): ?string { $sent[] = coreui_skills_augment($p); return array_shift($skillReplies); },
  static fn(array $t): string => $t['tool'] === 'skill' ? coreui_skills_tool_result($t['input']) : 'Datei index.html (Stand 1): <h1>x</h1>',
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
$skillBlock = (string)$GLOBALS['COREUI_SKILLS_BLOCK'];
sl_test(count($sent) === 2 && str_starts_with($sent[1], $skillBlock . 'BASIS') && str_starts_with($sent[0], $skillBlock . 'BASIS')
  && str_starts_with($stepsPart($sent[1]), $stepsPart($sent[0])) && str_contains($sent[1], coreui_skills_body($skillId))
  && !str_contains($skillBlock, coreui_skills_body($skillId)) && ($skillRun['outcome'] ?? '') === 'completed',
  'P6: in a project run the skill comes with its step, the skill block and the earlier steps stay unchanged');
sl_test(!array_key_exists('COREUI_SKILLS_FROZEN', $GLOBALS) && in_array($skillId, (array)$GLOBALS['COREUI_SKILLS_LOADED'], true),
  'P6: after the loop the skill block is no longer frozen and the skill counts as loaded');
$outside = coreui_skills_tool_result($skillId);
sl_test(str_contains($outside, 'schon geladen'), 'P6: outside a loop a loaded skill is known as loaded');
// A short research answer keeps the trusted block for its guide (as before).
$GLOBALS['COREUI_SKILLS_LOADED'] = [];
coreui_skills_compose();
$researchSent = [];
$researchReplies = ['[SKILL: ' . $skillId . ']', 'Antwort.'];
coreui_agent_loop_run('[WEB: x]', ['tool'=>'web', 'input'=>'x'], 'BASIS', ['web', 'skill'], 0,
  static function (string $p) use (&$researchReplies, &$researchSent): ?string { $researchSent[] = coreui_skills_augment($p); return array_shift($researchReplies); },
  static fn(array $t): string => $t['tool'] === 'skill' ? coreui_skills_tool_result($t['input']) : '1. Treffer',
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
sl_test(count($researchSent) === 2 && strpos($researchSent[1], coreui_skills_body($skillId)) < strpos($researchSent[1], '--- DEINE ARBEITSSCHRITTE BISHER'),
  'P6: a research loop still puts a loaded guide into the trusted block');

echo "CoreAI stabilize loop: status not content, DONE after the tool, no oscillation, research bound, section building, "
  . "edit recovery, separators, large replies, failures shortened, stable prompt start OK\n";
