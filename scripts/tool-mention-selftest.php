<?php
declare(strict_types=1);
// CoreAI 0.5.76: when Mara explains her tools without thinking, the
// explanation must stay an ordinary answer. A mention in inline code never
// starts a search or runs Python, a long or multi-line progress block in
// front of prose never starts a correction round, the safety net keeps
// mentions in code, and compound analysis blocks are removed.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/console_native_tools.php';

function mention_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Tool mention FAIL: {$why}\n"); exit(1); }
}

// The answer from 28.10.2026 that ran four tools and one error.
$explain = "Im Grunde kann ich:\n"
  . "1. **Externe Recherche:** dazu nutze ich den `[WEB: Suchanfrage]`-Befehl.\n"
  . "2. **Tiefgehende Untersuchungen:** dazu nutze ich den `[BROWSE: Ziel]`-Befehl.\n"
  . "3. **Berechnungen:** dazu nutze ich den `[PY]`-Block.\n"
  . "4. **Projekte:** mit `[COREUI_PLAN_SAVE]` und `[COREUI_PROJECT_BUILD]`.";
mention_test(ember_extract_web_marker($explain) === null, 'a WEB mention in code starts no search');
mention_test(ember_extract_browse_marker($explain) === null, 'a BROWSE mention in code starts no browser run');
mention_test(ember_py_extract_marker($explain) === null, 'a PY mention in code runs no Python');
mention_test(!coreui_native_tool_attempt($explain) && coreui_native_tool_parse($explain) === null,
  'native tool mentions in code are no tool attempt');
mention_test(ember_strip_stray_tool_markers($explain) === $explain, 'the safety net keeps mentions in code');
mention_test(!str_contains(ember_sanitize_public_reply($explain), '``'), 'no empty code spans after cleanup');

// Real calls keep working.
mention_test(ember_extract_web_marker('[WEB: wetter berlin heute]') === 'wetter berlin heute', 'real WEB call');
mention_test(ember_extract_browse_marker("Ich schaue nach.\n[BROWSE: Preise vergleichen]") === 'Preise vergleichen', 'real BROWSE call');
mention_test(ember_py_extract_marker("[PY]\n```python\nprint(1+1)\n```\n[/PY]") === 'print(1+1)', 'real PY call with a fenced block');
mention_test(ember_py_extract_marker('[PY]print(2)[/PY]') === 'print(2)', 'real PY call in one line');
mention_test(ember_extract_web_marker("Beispiel:\n```\n[WEB: suche]\n```") === null, 'a WEB example in a code block is no call');
mention_test(ember_extract_web_marker('`[WEB: wetter berlin]`') === 'wetter berlin'
  && ember_py_extract_marker(' `[PY]print(3)[/PY]` ') === 'print(3)', 'a reply that is only one marker in backticks is still a call');
mention_test(ember_strip_stray_tool_markers('Ich suche das. [WEB: wetter] Moment.') === 'Ich suche das.  Moment.',
  'an unexecuted marker in prose is still removed');

// Progress block in front of prose (no thinking): ordinary answer.
$note = '[COREUI_PUBLIC_PROGRESS]Ich pruefe den gespeicherten Plan und beginne.[/COREUI_PUBLIC_PROGRESS]';
foreach ([
  'multi-line block' => "[COREUI_PUBLIC_PROGRESS]Ich schaue,\nwas ich kann.[/COREUI_PUBLIC_PROGRESS]\nIch kann dir helfen.",
  'answer inside block' => "[COREUI_PUBLIC_PROGRESS]Ich kann dir helfen.\n1. Texte\n2. Recherche[/COREUI_PUBLIC_PROGRESS]",
  'unclosed block' => "[COREUI_PUBLIC_PROGRESS]Ich kann dir helfen.\nZum Beispiel mit Recherche.",
  'mention in code after note' => $note . "\n" . $explain,
] as $case => $reply) {
  $prose = coreui_native_public_progress_only($reply);
  mention_test(is_array($prose) && $prose['reply'] !== '' && !str_contains($prose['reply'], 'COREUI_PUBLIC_PROGRESS'),
    'progress block before prose is an answer: ' . $case);
  mention_test(!coreui_native_tool_attempt($prose['reply']), 'no correction round: ' . $case);
}
mention_test(coreui_native_public_progress_only($note) === null, 'a lone short note stays strict');
mention_test(coreui_native_public_progress_only($note . "\n[COREUI_PROJECT_BUILD]\nx\n[/COREUI_PROJECT_BUILD]") === null,
  'a real tool block after the note keeps the strict path');

// Compound analysis labels (live reply of run 4).
mention_test(ember_sanitize_public_reply('[Systemanalyse: Die Anfrage verlangt, eine Sprachnachricht als direkten Dialog '
  . 'zu parodieren.] Na klar, gern!') === 'Na klar, gern!', 'Systemanalyse block removed');
mention_test(ember_sanitize_public_reply('[Bauplan: Seite 1] bleibt') === '[Bauplan: Seite 1] bleibt', 'other compounds stay');

echo "CoreAI tool mentions: explanations run no tool, no correction round, code mentions kept OK\n";
