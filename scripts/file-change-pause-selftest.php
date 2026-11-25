<?php
declare(strict_types=1);
// CoreAI 0.8.33: a project run that changes the SAME file again and again without finishing.
// After six saved changes of one file Mara is asked once to check and finish; after ten the run
// pauses with an honest interim report. Many different files, failed changes and a finish after
// the check stay as before; there is still no limit on total steps.
// Stabilize 0.8.33: only changes that do not move the file forward count (a rewrite of existing text,
// a write that drops lines, a change back to an earlier version). Building a file section by section,
// as the protocol asks (skeleton, then one insertion per section), is not counted.
//   php scripts/file-change-pause-selftest.php
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
function fc_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "File change pause FAIL: {$why}\n"); exit(1); }
}
const FC_TOOLS = ['pfile_read', 'pfile_edit', 'pfile_write', 'prun'];
$edit = static fn(string $path, int $n): string => "[DATEI_AENDERN: {$path}]\n<<<<<<< SUCHEN\nv{$n}\n=======\nv" . ($n + 1) . "\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
$done = "[COREUI_TASK_DONE]\nDer Blog ist fertig und gespeichert.\n[/COREUI_TASK_DONE]";

/** Runs the loop with scripted replies; every change is saved. */
function fc_run(array $replies, ?callable $result = null): array {
  $first = array_shift($replies);
  $prompts = [];
  $events = [];
  $tools = [];
  $loop = coreui_agent_loop_run($first, coreui_agent_next_tool($first, FC_TOOLS), 'BASIS', FC_TOOLS, 0,
    static function (string $prompt) use (&$replies, &$prompts): ?string { $prompts[] = $prompt; return array_shift($replies) ?? 'LEER'; },
    static function (array $tool) use (&$tools, $result): string {
      $tools[] = $tool;
      if ($result !== null) return $result($tool, count($tools));
      $path = trim(explode("\n", (string)$tool['input'], 2)[0]);
      return 'Datei ' . $path . ' geaendert (jetzt Stand ' . count($tools) . '): 1 Zeilen ersetzt durch 1. Der vorige Stand bleibt im Tab Dateien wiederherstellbar.';
    },
    static function (string $kind, string $state, string $title, array $meta) use (&$events): void { $events[] = [$kind, $state, $title, $meta]; },
    static fn(): bool => false);
  return ['loop'=>$loop, 'prompts'=>$prompts, 'events'=>$events, 'tools'=>$tools];
}
$titles = static fn(array $r): array => array_column($r['events'], 2);
$checkPrompts = static fn(array $r): array => array_values(array_filter($r['prompts'], static fn(string $p): bool => str_contains($p, 'ABSCHLUSS PRUEFEN')));

// --- Ten changes of one file: one check after six, a pause after ten ------------------------------
$replies = [];
for ($i = 1; $i <= 14; $i++) $replies[] = $edit('blog.html', $i);
$run = fc_run($replies);
fc_test(count($run['tools']) === 10, 'the tenth saved change of blog.html is the last tool step: ' . count($run['tools']));
fc_test(count($run['prompts']) === 9, 'no model call after the pause: ' . count($run['prompts']));
$checks = $checkPrompts($run);
fc_test(count($checks) === 1 && str_contains($checks[0], 'Du hast blog.html in diesem Auftrag schon 6-mal geaendert')
  && str_contains($checks[0], 'schliesse dann mit [COREUI_TASK_DONE] und einer kurzen Zusammenfassung ab') && $run['prompts'][5] === $checks[0],
  'one check, in the prompt right after the sixth change, naming the file and the finish block');
fc_test(count(array_keys($titles($run), 'Abschluss nach sechs Aenderungen derselben Datei angefordert', true)) === 1
  && count(array_keys($titles($run), 'Lauf nach zehn Aenderungen derselben Datei pausiert', true)) === 1,
  'the check and the pause are in the work view once each');
$loop = $run['loop'];
fc_test(($loop['outcome'] ?? '') === 'interrupted' && ($loop['reason'] ?? '') === 'same_file_changed_repeatedly',
  'the run ends as interrupted, so the answer offers a retry: ' . json_encode([$loop['outcome'] ?? null, $loop['reason'] ?? null]));
$out = (string)$loop['out'];
fc_test(str_starts_with($out, 'Ich habe blog.html in diesem Auftrag 10-mal geändert, ohne ihn abzuschließen.')
  && str_contains($out, 'Zwischenstand, gespeichert: blog.html (10 Änderungen).') && str_contains($out, '„weiter“') && str_contains($out, '↻')
  && !str_contains($out, 'nicht gespeichert'), 'an honest interim report, not a failed-change message: ' . $out);
foreach (['Abschluss nach sechs Aenderungen derselben Datei angefordert', 'Lauf nach zehn Aenderungen derselben Datei pausiert'] as $t) {
  fc_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $t) === 1, 'the new work event titles fit the work store: ' . $t);
}

// --- A finish after the check completes the run -----------------------------------------------------
$replies = [];
for ($i = 1; $i <= 6; $i++) $replies[] = $edit('blog.html', $i);
$replies[] = $done;
$finished = fc_run($replies);
fc_test(count($finished['tools']) === 6 && ($finished['loop']['outcome'] ?? '') === 'completed'
  && $finished['loop']['out'] === 'Der Blog ist fertig und gespeichert.' && count($checkPrompts($finished)) === 1,
  'Mara can finish after the check: ' . json_encode($finished['loop']));

// --- Many files, or two files five times each, have no limit -----------------------------------------
$replies = [];
for ($i = 1; $i <= 14; $i++) $replies[] = $edit('seite' . $i . '.html', $i);
$replies[] = $done;
$many = fc_run($replies);
fc_test(count($many['tools']) === 14 && ($many['loop']['outcome'] ?? '') === 'completed' && $checkPrompts($many) === [],
  'fourteen different files: no check, no pause');
$replies = [];
for ($i = 1; $i <= 5; $i++) { $replies[] = $edit('blog.html', $i); $replies[] = $edit('style.css', $i); }
$replies[] = $done;
$two = fc_run($replies);
fc_test(count($two['tools']) === 10 && ($two['loop']['outcome'] ?? '') === 'completed' && $checkPrompts($two) === [],
  'changes are counted per file');

// --- Only saved changes count -----------------------------------------------------------------------
// Twelve attempts, three of them rejected: nine saved changes, so no pause (twelve attempts would pause).
$replies = [];
for ($i = 1; $i <= 12; $i++) $replies[] = $edit('blog.html', $i);
$replies[] = $done;
$attempt = 0;
$saved = fc_run($replies, static function (array $tool, int $n) use (&$attempt): string {
  if ($tool['tool'] === 'pfile_read') return "Datei blog.html (Stand 3):\nv1";
  $attempt++;
  return in_array($attempt, [2, 5, 8], true)
    ? 'Die Datei blog.html wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.'
    : 'Datei blog.html geaendert (jetzt Stand ' . $n . '): 1 Zeilen ersetzt durch 1.';
});
$edits = array_values(array_filter($saved['tools'], static fn(array $t): bool => $t['tool'] === 'pfile_edit'));
fc_test(count($edits) === 12 && !in_array('Lauf nach zehn Aenderungen derselben Datei pausiert', $titles($saved), true)
  && ($saved['loop']['outcome'] ?? '') === 'completed',
  'rejected changes are not counted as changes: ' . count($edits) . ' ' . json_encode($saved['loop']['reason'] ?? null));

// --- Stabilize 0.8.33: section by section is not "the same file again" ------------------------------
$insert = static fn(string $path, int $n): string => "[DATEI_AENDERN: {$path}]\n<<<<<<< SUCHEN\n<!-- weiter -->\n=======\n"
  . "<section id=\"s{$n}\">Teil {$n}</section>\n<!-- weiter -->\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
$replies = ["[DATEI_NEU: blog.html]\n<main>\n<!-- weiter -->\n</main>\n[/DATEI_NEU]"];
for ($i = 1; $i <= 14; $i++) $replies[] = $insert('blog.html', $i);
$replies[] = $done;
$sections = fc_run($replies);
fc_test(count($sections['tools']) === 15 && ($sections['loop']['outcome'] ?? '') === 'completed' && $checkPrompts($sections) === []
  && !in_array('Lauf nach zehn Aenderungen derselben Datei pausiert', $titles($sections), true),
  'a skeleton and fourteen section insertions: no check, no pause: ' . json_encode([count($sections['tools']), $sections['loop']['reason'] ?? null]));
// Rewrites between insertions still count: the tenth rewrite pauses the run, the insertions do not count.
$replies = [];
for ($i = 1; $i <= 12; $i++) { $replies[] = $insert('blog.html', $i); $replies[] = $edit('blog.html', $i); }
$mixed = fc_run($replies);
$mixedChecks = $checkPrompts($mixed);
fc_test(count($mixed['tools']) === 20 && ($mixed['loop']['reason'] ?? '') === 'same_file_changed_repeatedly'
  && count($mixedChecks) === 1 && $mixed['prompts'][11] === $mixedChecks[0]
  && str_starts_with((string)$mixed['loop']['out'], 'Ich habe blog.html in diesem Auftrag 20-mal geändert'),
  'ten rewrites between ten insertions: check after the sixth rewrite, pause after the tenth: ' . count($mixed['tools']));

// --- The interim report -------------------------------------------------------------------------
$report = coreui_agent_change_pause_report('blog.html', ['blog.html'=>10, 'style.css'=>1, 'guestbook.html'=>2]);
fc_test(str_contains($report, 'blog.html (10 Änderungen), style.css (1 Änderung), guestbook.html (2 Änderungen).')
  && str_contains($report, 'im Tab Dateien zurücknehmen'), 'the report names every saved file with its count: ' . $report);
$src = (string)file_get_contents($root . '/api/console_agent_loop.php');
fc_test(COREUI_AGENT_FILE_CHANGE_CHECK === 6 && COREUI_AGENT_FILE_CHANGE_PAUSE === 10
  && !str_contains($src, 'microtime(true) + 600.0'), 'six and ten, and still no wall-clock limit');

echo "CoreAI file change pause: one check after six changes of a file, a pause with an interim report after ten, other work unlimited OK\n";
