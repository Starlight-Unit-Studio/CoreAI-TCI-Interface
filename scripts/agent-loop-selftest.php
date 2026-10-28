<?php
declare(strict_types=1);
// CoreAI 0.5.77: Maras Arbeitsschleife. Offline with a scripted Core: Mara
// chains steps on her own (search, search again, calculate, archive),
// answers when she has enough, hands over to the browser, stops after six
// steps or on cancel, and every step shows in the work view.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/console_agent_loop.php';
// The marker readers of chat.php, extracted like the other selftests do.
$chatSource = (string)file_get_contents($root . '/api/chat.php');
foreach (['ember_without_inline_code', 'ember_extract_web_marker', 'ember_extract_browse_marker', 'ember_strip_stray_tool_markers'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $chatSource, $m) !== 1) { fwrite(STDERR, "missing {$fn}\n"); exit(1); }
  eval($m[0]);
}
function ember_py_extract_marker(string $s): ?string {
  return preg_match('~\[PY\](.*?)\[/PY\]~is', $s, $m) === 1 ? trim($m[1]) : null;
}

function loop_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Agent loop FAIL: {$why}\n"); exit(1); }
}

$all = ['web', 'browse', 'python', 'archive'];
loop_test(COREUI_AGENT_MAX_STEPS === 6, 'six steps per answer');
loop_test(coreui_agent_next_tool('Ich schaue nach. [WEB: gemma4 12b ollama]', $all) === ['tool'=>'web', 'input'=>'gemma4 12b ollama'], 'web marker');
loop_test(coreui_agent_next_tool('[BROWSE: Changelog von Ollama lesen]', $all)['tool'] === 'browse', 'browse marker');
loop_test(coreui_agent_next_tool("[PY]print(2**10)[/PY]", $all) === ['tool'=>'python', 'input'=>'print(2**10)'], 'python marker');
loop_test(coreui_agent_next_tool('[ARCHIV: Port]', ['web']) === null, 'only allowed tools');
loop_test(coreui_agent_next_tool('Erklaerung: `[WEB: x]` sucht im Netz.', $all) === null, 'mentions in code are no calls');
loop_test(coreui_agent_next_tool('Die Antwort ist 42.', $all) === null, 'prose is the answer');

// Work view titles must pass the work store's ASCII rule.
foreach (['web', 'browse', 'python', 'archive'] as $tool) {
  $title = coreui_agent_step_title(['tool'=>$tool, 'input'=>'Grüße „x“'], 3, 6);
  loop_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $title) === 1 && str_starts_with($title, 'Schritt 3 von 6: '), 'title ' . $title);
}
loop_test(coreui_agent_step_note(['tool'=>'web', 'input'=>"Ollama\n gemma4"]) === 'Ich suche nach: Ollama gemma4', 'public note');
loop_test(coreui_agent_step_note(['tool'=>'web', 'input'=>'https://evil.example/x']) === null
  && coreui_agent_step_note(['tool'=>'python', 'input'=>'x=1']) === null, 'no links or code as note');

$prompt = coreui_agent_step_prompt('BASIS', [['label'=>'Websuche „a“', 'result'=>'Treffer A']], 1, 6, ['web', 'archive']);
loop_test(str_contains($prompt, "Schritt 1 (Websuche „a“):\nTreffer A") && str_contains($prompt, 'Du hast 1 von 6')
  && str_contains($prompt, '[WEB: neue') && str_contains($prompt, '[ARCHIV:') && !str_contains($prompt, '[BROWSE:')
  && str_contains($prompt, 'UNGEPRUEFTE DATEN'), 'step prompt: results, choice, only allowed tools, injection warning');
loop_test(str_contains(coreui_agent_step_prompt('B', [['label'=>'x', 'result'=>'y']], 6, 6, $all), 'Das war dein letzter Schritt'), 'last step forces an answer');
loop_test(str_contains(coreui_agent_loop_hint(false, true), 'ohne vorher zu fragen') && str_contains(coreui_agent_loop_hint(false, true), '[ARCHIV:')
  && !str_contains(coreui_agent_loop_hint(true, false), '[ARCHIV:'), 'first prompt tells Mara to act on her own');

// Scripted runs.
$run = static function (array $replies, string $first, ?callable $cancel = null) use ($all): array {
  $events = [];
  $tools = [];
  $prompts = [];
  $result = coreui_agent_loop_run($first, coreui_agent_next_tool($first, $all), 'BASIS', $all, 0,
    static function (string $p) use (&$replies, &$prompts): ?string { $prompts[] = $p; return array_shift($replies); },
    static function (array $t) use (&$tools): string { $tools[] = $t; return 'Ergebnis ' . count($tools) . ' fuer ' . $t['input']; },
    static function (string $k, string $s, string $t, array $m) use (&$events): void { $events[] = [$k, $s, $t]; },
    $cancel ?? static fn(): bool => false);
  return [$result, $tools, $events, $prompts];
};

// Search, search sharper, calculate, then answer.
[$r, $tools, $events, $prompts] = $run(['[WEB: gemma4 12b Groesse GB]', "[PY]print(8*1.2)[/PY]", 'Gemma 4 12B braucht etwa 10 GB. Quellen: ollama.com.'],
  '[WEB: gemma4 12b]');
loop_test(array_column($tools, 'tool') === ['web', 'web', 'python'] && $r['steps'] === 3, 'Mara chains three steps on her own');
loop_test($r['out'] === 'Gemma 4 12B braucht etwa 10 GB. Quellen: ollama.com.' && $r['browse'] === null, 'and answers');
loop_test(count(array_filter($events, static fn(array $e): bool => $e[0] === 'tool' && $e[1] === 'success')) === 3
  && in_array(['commentary', 'info', 'Mara meldet ihren naechsten Schritt'], $events, true), 'every step in the work view');
loop_test(str_contains($prompts[2], "Schritt 1 (Websuche: „gemma4 12b“):\nErgebnis 1") && str_contains($prompts[2], "Schritt 3 (Python-Rechnung):")
  && str_contains($prompts[2], 'Ergebnis 2 fuer gemma4 12b Groesse GB'), 'later prompts carry all results');

// Escalation to the browser worker mid-way.
[$r, $tools] = $run(['[BROWSE: Ollama Bibliothek gemma4 Tags lesen]'], '[WEB: gemma4 tags]');
loop_test($r['browse'] === 'Ollama Bibliothek gemma4 Tags lesen' && $r['out'] === null && count($tools) === 1, 'hand over to the browser');

// Never more than six steps; a seventh wish ends as prose.
[$r, $tools, , $prompts] = $run(array_fill(0, 10, 'Ich suche weiter. [WEB: noch mehr]'), '[WEB: start]');
loop_test(count($tools) === 6 && $r['steps'] === 6 && $r['out'] === 'Ich suche weiter.', 'stops after six steps, keeps the prose');
loop_test(str_contains(end($prompts), 'Das war dein letzter Schritt'), 'the sixth prompt says so');
[$r] = $run(array_fill(0, 10, '[WEB: noch mehr]'), '[WEB: start]');
loop_test(str_contains((string)$r['out'], 'alle 6 Arbeitsschritte genutzt'), 'only markers left: an honest sentence');

// Cancel and an empty model answer stop the loop.
[$r, $tools] = $run(['[WEB: b]', 'fertig'], '[WEB: a]', static fn(): bool => true);
loop_test(count($tools) === 1, 'cancel stops after the running step');
[$r] = $run([null], '[WEB: a]');
loop_test($r['out'] === null, 'no answer from the Core ends the loop');

// Wiring in chat.php: private chat only, before the old single step, Python only calculates.
loop_test(strpos($chatSource, 'coreui_agent_loop_run($out, $loopTool') < strpos($chatSource, "if (!\$agentLoopHandled && ember_web_enabled() && \$imageUrl === null"),
  'loop runs before the old single web step, which stays for the global channel');
loop_test(str_contains($chatSource, "if (\$channel === 'console' && \$imageUrl === null && empty(\$fileTask) && is_string(\$out)) {"), 'private chat only');
loop_test(str_contains($chatSource, "if (ember_py_code_writes_output(\$code)) {") && str_contains($chatSource, "coreui_compact_archive_block(\$uid, \$sessionId,"),
  'python only calculates, archive search wired');
loop_test(str_contains($chatSource, '\[ARCHIV:\s*.*?\]') && substr_count((string)file_get_contents($root . '/api/console_stream.php'),
  'coreui_extract_archive_marker($reply) !== null') === 2, 'ARCHIV recognised in the stream and never shown');
loop_test(substr_count($chatSource, 'coreui_agent_loop_hint(') === 2, 'both prompt builders tell Mara how to work');

echo "CoreAI agent loop: chaining, answer, browser hand-over, six steps, cancel, work view OK\n";
