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
require_once $root . '/api/console_native_tools.php';
require_once $root . '/api/project_file_tools.php';
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
$agentSource = (string)file_get_contents($root . '/api/console_agent_loop.php');
loop_test(!str_contains($agentSource, 'microtime(true) + 600.0')
  && !str_contains($agentSource, "if (microtime(true) >= " . '$deadline'),
  'project tools must not stop after an arbitrary ten-minute deadline');
loop_test(coreui_agent_next_tool('Ich schaue nach. [WEB: gemma4 12b ollama]', $all) === ['tool'=>'web', 'input'=>'gemma4 12b ollama'], 'web marker');
loop_test(coreui_agent_next_tool('[BROWSE: Changelog von Ollama lesen]', $all)['tool'] === 'browse', 'browse marker');
$repoGoal = 'Prüfe das GitHub-Repository https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface auf dokumentierte Fähigkeiten.';
$spacedBrowse = '[ BROWSE: ' . $repoGoal . ' ]';
loop_test(coreui_agent_next_tool($spacedBrowse, $all) === ['tool'=>'browse','input'=>$repoGoal]
  && coreui_agent_next_tool($spacedBrowse, ['web']) === ['tool'=>'web','input'=>$repoGoal]
  && coreui_agent_next_tool($spacedBrowse, []) === null
  && coreui_agent_guard_browser_reply($spacedBrowse, false, true) === '[WEB: ' . $repoGoal . ']',
  'a spaced browser marker still follows the authorized browser or web route');
loop_test(trim(ember_strip_stray_tool_markers($spacedBrowse)) === ''
  && coreui_agent_next_tool('Syntax: ' . chr(96) . $spacedBrowse . chr(96), $all) === null
  && coreui_agent_next_tool('[ WEB : GitHub README ]', ['web']) === ['tool'=>'web','input'=>'GitHub README'],
  'spaced web markers cannot leak as prose, and quoted examples stay inert');
loop_test(coreui_agent_next_tool("[PY]print(2**10)[/PY]", $all) === ['tool'=>'python', 'input'=>'print(2**10)'], 'python marker');
loop_test(coreui_agent_next_tool('[ARCHIV: Port]', ['web']) === null, 'only allowed tools');
loop_test(coreui_agent_next_tool('Erklaerung: `[WEB: x]` sucht im Netz.', $all) === null, 'mentions in code are no calls');
loop_test(coreui_agent_next_tool('Die Antwort ist 42.', $all) === null, 'prose is the answer');

// A model-authored promise to read a project file is NOT a completion. Only
// the read-only tool can be recovered, with actual project permission checks.
$readAllowed = ['pfile_read','pfile_edit','pfile_write','prun'];
$readPromise = 'Ich lese mir jetzt style.css durch, bevor ich die Gestaltung ändere.';
$expectedRead = ['tool'=>'pfile_read','input'=>'style.css','recovered_read_intent'=>true];
loop_test(coreui_agent_next_tool($readPromise,$readAllowed) === null
  && coreui_agent_recover_promised_read($readPromise,$readAllowed) === $expectedRead
  && coreui_agent_recover_promised_read('Ich lese mir jetzt ' . chr(96) . 'src/style.css' . chr(96) . ' durch.', $readAllowed)
    === ['tool'=>'pfile_read','input'=>'src/style.css','recovered_read_intent'=>true],
  'a short first-person file-reading promise is a project-scoped read action');
loop_test(coreui_agent_recover_promised_read(
    'Ich lese dir jetzt ' . chr(96) . 'script.js' . chr(96) . ' durch, bevor ich weitermache.',
    $readAllowed
  ) === ['tool'=>'pfile_read','input'=>'script.js','recovered_read_intent'=>true],
  'the screenshot wording Ich lese dir jetzt script.js is a real guarded read, not an answer');
foreach ([
  'Ich habe style.css bereits gelesen.',
  'Ich lese mir jetzt ../secret.css durch.',
  'Ich lese mir jetzt /etc/passwd durch.',
  'Ich lese mir jetzt https://host/x.css durch.',
  'Wir diskutieren, ob wir style.css lesen sollten.',
  'Ich lese mir jetzt style.css durch.' . "\n" . str_repeat('x',900),
  str_repeat(chr(96),3) . "\nIch lese mir jetzt style.css durch.\n" . str_repeat(chr(96),3),
] as $unsafe) {
  loop_test(coreui_agent_recover_promised_read($unsafe,$readAllowed) === null,
    'no implicit read from past tense, quoted/long text or unsafe paths');
}
loop_test(coreui_agent_recover_promised_read($readPromise,['web','python']) === null
  && coreui_agent_recover_promised_read('Ich ändere jetzt style.css.',$readAllowed) === null
  && coreui_agent_recover_promised_read('Ich führe jetzt npm test aus.',$readAllowed) === null,
  'no permission bypass and no inferred writes or commands');


// Work view titles must pass the work store's ASCII rule.
foreach (['web', 'browse', 'python', 'archive'] as $tool) {
  $title = coreui_agent_step_title(['tool'=>$tool, 'input'=>'Grüße „x“'], 3, 6);
  loop_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $title) === 1 && str_starts_with($title, 'Schritt 3 von 6: '), 'title ' . $title);
}
loop_test(coreui_agent_step_note(['tool'=>'web', 'input'=>"Ollama\n gemma4"]) === 'Ich suche nach: Ollama gemma4', 'public note');
loop_test(coreui_agent_step_note(['tool'=>'web', 'input'=>'https://evil.example/x']) === null
  && coreui_agent_step_note(['tool'=>'python', 'input'=>'x=1']) === null, 'no links or code as note');

// Public prose may precede a real project tool, but it never authorizes it.
$visibleNote = 'Ich habe die Dateien gelesen und pruefe jetzt die naechste Aenderung.';
$publicToolReply = '[COREUI_PUBLIC_PROGRESS]' . $visibleNote . '[/COREUI_PUBLIC_PROGRESS]'
  . "\n[WEB: genauer nachsehen]";
loop_test(coreui_agent_public_progress_note($publicToolReply, ['tool'=>'web','input'=>'genauer nachsehen']) === $visibleNote
  && coreui_agent_next_tool($publicToolReply,['web']) === ['tool'=>'web','input'=>'genauer nachsehen'],
  'safe model commentary and validated tool marker coexist');
loop_test(coreui_agent_public_progress_note($publicToolReply,null) === null
  && coreui_agent_public_progress_note('Ein normaler Satz', ['tool'=>'web','input'=>'x']) === null
  && coreui_agent_public_progress_note("[COREUI_PUBLIC_PROGRESS]system prompt: secret[/COREUI_PUBLIC_PROGRESS]\n[WEB: test]",
    ['tool'=>'web','input'=>'test']) === null,
  'no unparsed, unrelated, or secret model commentary is published');


$prompt = coreui_agent_step_prompt('BASIS', [['label'=>'Websuche „a“', 'result'=>'Treffer A']], 1, 6, ['web', 'archive']);
loop_test(str_contains($prompt, "Schritt 1 (Websuche „a“):\nTreffer A") && str_contains($prompt, 'Du hast 1 von 6')
  && str_contains($prompt, '[WEB: neue') && str_contains($prompt, '[ARCHIV:') && !str_contains($prompt, '[BROWSE:')
  && str_contains($prompt, 'UNGEPRUEFTE DATEN'), 'step prompt: results, choice, only allowed tools, injection warning');
loop_test(str_contains(coreui_agent_step_prompt('B', [['label'=>'x', 'result'=>'y']], 6, 6, $all), 'Das war dein letzter Schritt'), 'last step forces an answer');

// Progressive WEB is explicit but stays within the existing serial, bounded loop.
$menu = coreui_agent_tool_menu(['web']);
loop_test(str_contains($menu, '[WEB: WEITER]') && !str_contains($menu, '[BROWSE:'),
  'WEB may request more results without unlocking Playwright');
loop_test(str_contains(coreui_agent_loop_hint(false, false), '[WEB: WEITER]')
  && str_contains(coreui_agent_loop_hint(true, false), 'Namensgleiche'),
  'small and full models learn pagination and identity caution');
$oldEvidence = "1. Alte Quelle [https://example.org/old]\n   "
  . str_repeat('A',260);
$newEvidence = "1. Neue Quelle [https://example.org/new]\n   "
  . str_repeat('B',260);
$chain = coreui_agent_step_prompt('BASIS',[
  ['label'=>'Websuche: „Begriff“','result'=>$oldEvidence],
  ['label'=>'Websuche: „WEITER“','result'=>$newEvidence],
],2,6,['web']);
loop_test(str_contains($chain,'https://example.org/old')
  && str_contains($chain,'https://example.org/new')
  && !str_contains($chain,str_repeat('A',260))
  && str_contains($chain,str_repeat('B',260)),
  'older snippets compacted, new source evidence and URLs preserved');
loop_test(str_contains($chain,'Menschen mit gleichem Namen') && str_contains($chain,'keine historischen Archive')
  && str_contains($chain,'[WEB: WEITER]'),
  'sources must be verified and same-name individuals never conflated');

loop_test(str_contains(coreui_agent_loop_hint(false, true), 'ohne vorher zu fragen') && str_contains(coreui_agent_loop_hint(false, true), '[ARCHIV:')
  && !str_contains(coreui_agent_loop_hint(true, false), '[ARCHIV:'), 'first prompt tells Mara to act on her own');

$taskDone = static fn(string $body):string => "[COREUI_TASK_DONE]\n" . $body . "\n[/COREUI_TASK_DONE]";

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

// P10: tools must not be declared successful merely because the model asked.
loop_test(coreui_agent_tool_result_state(['tool'=>'pserver_start','input'=>'8000 cmd'],
  "Prozess: cmd (Port 8000)\nLäuft.\nHTTP-Pruefung GET /: 200 (Server erreichbar).") === 'success'
  && coreui_agent_tool_result_state(['tool'=>'pserver_start','input'=>'8000 cmd'],
    "Prozess: cmd (Port 8000)\nLäuft.\nHTTP-Pruefung GET / fehlgeschlagen (server_unreachable).") === 'info'
  && coreui_agent_tool_result_state(['tool'=>'pserver_fetch','input'=>'/'], 'GET /: HTTP 404 (text/html)') === 'error'
  && coreui_agent_tool_result_state(['tool'=>'prun','input'=>'npm test'], 'Beendet mit Exitcode 1.') === 'error'
  && coreui_agent_tool_result_state(['tool'=>'pfile_write','input'=>'index.html'],
    'Das ging nicht: datei nicht verfuegbar.') === 'error',
  'tool status is based on real results, never a requested action');
loop_test(str_starts_with(coreui_agent_step_label(['tool'=>'pserver_start','input'=>'8000 cmd'],1,0,'info'),
  'Noch nicht bestaetigt: Server gestartet:'), 'the followup model cannot mistake an unverified server for proof');
loop_test(str_contains(coreui_agent_step_prompt('B', [], 10, 0, ['web','pfile_write']), 'bisher 10 Arbeitsschritte')
  && coreui_agent_step_title(['tool'=>'pfile_write','input'=>'x'],7,0) === 'Schritt 7: Projektdatei schreiben',
  'project loop has no artificial six-file final step');
$projectAllowed = ['web','pfile_write','pfile_edit','prun'];
$projectResponses = [];
for ($i=1; $i<=8; $i++) $projectResponses[] = '[WEB: neuer-schritt-' . $i . ']';
$projectResponses[] = $taskDone('Projektauftrag abgeschlossen mit geprueften Werkzeugen.');
$projectTools = [];
$projectEvents = [];
$projectLoop = coreui_agent_loop_run('[WEB: projekt-start]', ['tool'=>'web','input'=>'projekt-start'],
  'BASIS', $projectAllowed,0,
  static function(string $prompt) use (&$projectResponses): ?string { return array_shift($projectResponses); },
  static function(array $tool) use (&$projectTools): string {
    $projectTools[]=$tool;
    return 'Echtes Suchergebnis fuer ' . $tool['input'];
  },
  static function(string $kind,string $state,string $title,array $meta) use (&$projectEvents): void {
    $projectEvents[]=[$kind,$state,$title];
  },static fn(): bool => false);
loop_test(count($projectTools)===9 && $projectLoop['steps']===9
  && str_contains((string)$projectLoop['out'],'abgeschlossen'), 'project work may continue past six confirmed steps');

// The model may emit a promise instead of the next DATEI marker after a
// real tool; the same loop must read the file before returning a final reply.
$readSteps = [];
$readEvents = [];
$readReplies = [$readPromise,'[DATEI: style.css]',$taskDone('Die Datei wurde mit dem Werkzeug geprüft; weitere Änderungen sind noch offen.')];
$recoveredLoop = coreui_agent_loop_run('[DATEI: index.html]',
  ['tool'=>'pfile_read','input'=>'index.html'], 'BASIS', $readAllowed, 0,
  static function(string $prompt) use (&$readReplies): ?string { return array_shift($readReplies); },
  static function(array $tool) use (&$readSteps): string {
    $readSteps[]=$tool;
    return 'Datei ' . $tool['input'] . " (Stand 1):\n1 | Beispiel";
  },
  static function(string $kind,string $state,string $title,array $meta) use (&$readEvents): void {
    $readEvents[]=['kind'=>$kind,'state'=>$state,'meta'=>$meta];
  }, static fn():bool=>false);
loop_test(count($readSteps)===2
  && $readSteps[1] === ['tool'=>'pfile_read','input'=>'style.css']
  && $recoveredLoop['steps']===2
  && str_contains((string)$recoveredLoop['out'],'noch offen')
  && count(array_filter($readEvents,static fn(array $e): bool =>
    ($e['meta']['reason']??'')==='task_decision_repaired'))===1,
  'file-read promise results in real verified tool result and autonomous continuation');

// A project run sends the model's own public note before each real tool.
$projectNotes = [];
$publicLoop = coreui_agent_loop_run(
  $publicToolReply, coreui_agent_next_tool($publicToolReply,['web']), 'BASIS',
  ['web','pfile_write','pfile_edit','prun'], 0,
  static fn(string $prompt): ?string => $taskDone('Ich habe die Suche abgeschlossen. Jetzt kann ich antworten.'),
  static fn(array $tool): string => 'Werkzeug bestaetigt: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta) use (&$projectNotes): void {
    $projectNotes[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  }, static fn():bool => false);
loop_test($publicLoop['steps'] === 1
  && str_contains((string)$publicLoop['out'],'Jetzt kann ich antworten.')
  && count(array_filter($projectNotes,static fn(array $e): bool =>
    $e['kind']==='commentary' && ($e['meta']['public_note']??'')==='Ich habe die Dateien gelesen und pruefe jetzt die naechste Aenderung.'
    && ($e['meta']['source']??'')==='model_public_text'))===1,
  'project loop publishes genuine public model content, not private thinking, and continues to a final answer');

loop_test(str_contains((string)coreui_agent_public_result_note(['tool'=>'prun','input'=>'npm test'],'success'), 'Exitcode 0')
  && str_contains((string)coreui_agent_public_result_note(['tool'=>'pserver_start','input'=>'8000 app.py'],'success'),'HTTP-Test')
  && str_contains((string)coreui_agent_public_result_note(['tool'=>'pserver_start','input'=>'8000 app.py'],'info'),'nicht als erfolgreich bestätigt')
  && str_contains((string)coreui_agent_public_result_note(['tool'=>'pfile_edit','input'=>'main.js'],'error'),'nicht gespeichert')
  && coreui_agent_public_result_note(['tool'=>'pfile_read','input'=>'index.html'],'success')===null,
  'public completion commentary only states real tool status, not invented file content');

// 0.8.9: Regression from the screenshot: guestbook.html failed ten edits
// because each retry changed its payload and evaded full-hash stall detection.
// A failed exact patch must be read again from saved bytes before one more
// attempt. A second failed edit to the same file stops without another model
// call, preserving every saved file and avoiding a false success claim.
$fail1="[DATEI_AENDERN: guestbook.html]\n<<<<<<< SUCHEN\nmissing old text\n=======\nnew guestbook\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
$fail2="[DATEI_AENDERN: guestbook.html]\n<<<<<<< SUCHEN\nstill not in the file\n=======\nnew guestbook\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]";
$projectFileTools=['pfile_read','pfile_edit','pfile_write','prun'];
$scriptedModel=[$fail2, 'UNEXPECTED THIRD MODEL CALL'];
$executed=[];$replies=[];$logged=[];
$editLoop=coreui_agent_loop_run($fail1,coreui_agent_next_tool($fail1,$projectFileTools),
  'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$scriptedModel,&$replies): ?string {
    $replies[]=$prompt;return array_shift($scriptedModel);
  },
  static function(array $tool) use (&$executed): string {
    $executed[]=$tool;
    if ($tool['tool']==='pfile_read')
      return "Datei guestbook.html (Stand 3):\n1 | <form>saved original</form>\n(Zeilennummern gehoeren nicht zur Datei.)";
    return 'Die Datei guestbook.html wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor (lies sie mit [DATEI: pfad] und kopiere die Zeilen genau, mit allen Leerzeichen).';
  },
  static function(string $kind,string $state,string $title,array $meta) use (&$logged):void {
    $logged[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  },static fn():bool=>false);
loop_test(array_column($executed,'tool')===['pfile_edit','pfile_read','pfile_edit']
  && $executed[0]['input']!==$executed[2]['input']
  && ($executed[1]['recovery_read'] ?? false) === true
  && str_contains((string)($executed[1]['recovery_patch'] ?? ''), 'missing old text')
  && count($replies)===1 && $editLoop['steps']===3
  && str_contains((string)$editLoop['out'],'guestbook.html')
  && str_contains((string)$editLoop['out'],'wurde nicht gespeichert')
  && str_contains((string)$editLoop['out'],'Wiederholung beendet')
  && count(array_filter($logged,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='same_file_edit_rejected_twice'))===1,
  'changed patch body cannot bypass no-progress guard; one read, one retry and honest stop');
loop_test(str_contains($replies[0], '<form>saved original</form>')
  && str_contains($replies[0], 'DATEIÄNDERUNG KORRIGIEREN')
  && str_contains($replies[0], 'ohne Überschriften oder Zeilennummern')
  && str_contains($replies[0], 'Schritt 1')
  && str_contains($replies[0], 'Schritt 2'),
  'the repaired patch is grounded in a real reread of the saved file, not an invented replacement');
loop_test(coreui_agent_file_target(['tool'=>'pfile_edit','input'=>"docs/Über uns.md\ncode"])==='docs/Über uns.md'
  && coreui_agent_file_target(['tool'=>'pfile_edit','input'=>"../../secret\ncode"])==='',
  'Unicode filenames work and untrusted paths cannot be echoed in recovery notices');
loop_test(coreui_agent_tool_result_state(['tool'=>'pfile_edit','input'=>'a.md'],
  'Der Datei-Arbeitsbereich dieses Chats ist noch nicht verfügbar.')==='error',
  'missing workspace is not reported as successful edit');
loop_test(coreui_agent_edit_failure_reason('Die Datei x wurde NICHT geaendert: Kein SUCHEN/ERSETZEN-Block gefunden.')==='Änderungsblock nicht erkannt'
  && coreui_agent_edit_failure_reason('Die Datei x wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt 3 Mal in der Datei vor')==='SUCHEN-Text ist nicht eindeutig'
  && str_contains((string)coreui_agent_public_result_note(['tool'=>'pfile_edit','input'=>'guestbook.html'],'error',
    'Die Datei guestbook.html wurde NICHT geaendert: Kein SUCHEN/ERSETZEN-Block gefunden.'),'Änderungsblock nicht erkannt'),
  'public error notes identify deterministic reasons without disclosing patch bytes');
// A transient malformed patch can be recovered without stopping successful
// file work. Once an actual edit is saved, its failure counter is cleared.
$fixMessages=[
  "[DATEI_AENDERN: guestbook.html]\n<<<<<<< SUCHEN\n<form>saved original</form>\n=======\n<form>new guestbook</form>\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  $taskDone('Gästebuch erfolgreich geändert, gespeicherter Stand bestätigt.'),
];
$fixTools=[];$fixModelCalls=0;
$fixedLoop=coreui_agent_loop_run($fail1,coreui_agent_next_tool($fail1,$projectFileTools),
  'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$fixMessages,&$fixModelCalls): ?string {
    $fixModelCalls++;return array_shift($fixMessages);
  },
  static function(array $tool) use (&$fixTools): string {
    $fixTools[]=$tool;
    if ($tool['tool']==='pfile_read')
      return "Datei guestbook.html (Stand 3):\n1 | <form>saved original</form>\n(Zeilennummern gehoeren nicht zur Datei.)";
    return count(array_filter($fixTools,static fn(array $t):bool=>$t['tool']==='pfile_edit'))===1
      ? 'Die Datei guestbook.html wurde NICHT geaendert: Block 1: der SUCHEN-Text kommt nicht in der Datei vor.'
      : 'Datei guestbook.html geaendert (jetzt Stand 4): 1 Zeilen ersetzt durch 1.';
  },
  static function(string $kind,string $state,string $title,array $meta):void {},
  static fn():bool=>false);
loop_test(array_column($fixTools,'tool')===['pfile_edit','pfile_read','pfile_edit']
  && $fixModelCalls===2
  && $fixedLoop['out']==='Gästebuch erfolgreich geändert, gespeicherter Stand bestätigt.',
  'a valid second patch completes normally after the verified reread');
$noDbTools=[];$noDbModelCalls=0;
$dbLoop=coreui_agent_loop_run($fail1,coreui_agent_next_tool($fail1,$projectFileTools),
  'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$noDbModelCalls):?string {
    $noDbModelCalls++;return 'I should not retry storage errors';
  },
  static function(array $tool) use (&$noDbTools):string {
    $noDbTools[]=$tool;
    return 'Die Projektdatei konnte gerade nicht gelesen oder gespeichert werden (Datenbankfehler). Nichts geaendert.';
  },
  static function(string $kind,string $state,string $title,array $meta):void {},
  static fn():bool=>false);
loop_test(count($noDbTools)===1 && $noDbModelCalls===0
  && str_contains((string)$dbLoop['out'],'Datenbankfehler'),
  'a storage failure stops immediately instead of consuming retries or changing files');

// Regression: a 12B model can return an empty follow-up after a real file
// read. Preserve saved files and explain the interruption; no empty_response.
$emptyFollowCalls=0;
$emptyFollowEvents=[];
$emptyFollow=coreui_agent_loop_run('[DATEI: style.css]',
  ['tool'=>'pfile_read','input'=>'style.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$emptyFollowCalls):?string {
    $emptyFollowCalls++;return null;
  },
  static fn(array $tool):string => "Datei style.css (Stand 1):\\n1 | body {}",
  static function(string $kind,string $state,string $title,array $meta) use (&$emptyFollowEvents):void {
    $emptyFollowEvents[]=['kind'=>$kind,'state'=>$state,'meta'=>$meta];
  },static fn():bool=>false);
loop_test($emptyFollowCalls===1 && $emptyFollow['steps']===1
  && is_string($emptyFollow['out']) && str_contains($emptyFollow['out'],'noch nicht abgeschlossen')
  && str_contains($emptyFollow['out'],'Bereits gespeicherte Dateien')
  && count(array_filter($emptyFollowEvents,static fn(array $e):bool =>
    $e['kind']==='model' && $e['state']==='error'
    && ($e['meta']['reason']??'')==='empty_followup_after_tool'))===1,
  'empty model reply after verified project tool is a diagnosed interruption');

// 0.8.12: one transient, successfully transported but blank external API
// follow-up may be recovered with compact receipts. No tool is replayed.
$recoveryReplies = [null, '[DATEI: contact.html]', $taskDone('Die Kontaktseite wurde gelesen; weitere Arbeit bleibt offen.')];
$recoveryPrompts=[]; $recoveryTools=[]; $recoveryEvents=[];
$externalRecovery=coreui_agent_loop_run('[DATEI: style.css]',
  ['tool'=>'pfile_read','input'=>'style.css'], 'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$recoveryReplies,&$recoveryPrompts):?string {
    $recoveryPrompts[]=$prompt;return array_shift($recoveryReplies);
  },
  static function(array $tool) use (&$recoveryTools):string {
    $recoveryTools[]=$tool;return "Datei " . $tool['input'] . " (Stand 5):\n1 | Beispiel";
  },
  static function(string $kind,string $state,string $title,array $meta) use (&$recoveryEvents):void {
    $recoveryEvents[]=['kind'=>$kind,'state'=>$state,'meta'=>$meta];
  },
  static fn():bool=>false,null,null,
  static fn():array=>['provider'=>'external','http_code'=>200,'transport_ok'=>true,
    'curl_errno'=>0,'done_seen'=>true,'done'=>true,'done_reason'=>'length','eval_count'=>100,'prompt_eval_count'=>5000]);
loop_test(count($recoveryPrompts)===3
  && array_column($recoveryTools,'tool')===['pfile_read','pfile_read']
  && $externalRecovery['steps']===2
  && str_contains((string)$externalRecovery['out'],'Kontaktseite wurde gelesen')
  && count(array_filter($recoveryEvents,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='empty_followup_recovery'))===1
  && count(array_filter($recoveryEvents,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='recovered_empty_followup'))===1,
  'external completed blank response is retried once without replaying files or requiring Continue');

// Old verified results are receipts, the latest remains complete for accurate changes.
$olderSource = 'PRIVATE_OLD_SOURCE_' . str_repeat('a',1500);
$lean = coreui_agent_compact_project_prompt('BASIS', [
  ['label'=>'Datei gelesen: style.css','state'=>'success','result'=>$olderSource],
  ['label'=>'Datei gelesen: contact.html','state'=>'success','result'=>'LATEST_CONFIRMED_CONTENT']
],2,$projectFileTools);
loop_test(str_contains($lean,'Datei gelesen: style.css')
  && str_contains($lean,hash('sha256',$olderSource))
  && !str_contains($lean,'PRIVATE_OLD_SOURCE_')
  && str_contains($lean,'LATEST_CONFIRMED_CONTENT')
  && str_contains($lean,'Setze den Projektauftrag'),
  'recovery prompt is lean, preserves latest source and audited older receipts');

$blockedStatuses=[
  ['provider'=>'external','http_code'=>429,'transport_ok'=>false,'curl_errno'=>0,'done_reason'=>''],
  ['provider'=>'external','http_code'=>401,'transport_ok'=>false,'curl_errno'=>0,'done_reason'=>''],
  ['provider'=>'external','http_code'=>400,'transport_ok'=>false,'curl_errno'=>0,'done_reason'=>''],
  ['provider'=>'external','http_code'=>200,'transport_ok'=>false,'curl_errno'=>28,'done_reason'=>''],
  ['provider'=>'ollama','http_code'=>200,'transport_ok'=>true,'curl_errno'=>0,'done_reason'=>'stop'],
  ['provider'=>'external','http_code'=>200,'transport_ok'=>true,'curl_errno'=>0,'done_reason'=>'content_filter'],
];
foreach ($blockedStatuses as $status) {
  $calls=0;$events=[];
  $blocked=coreui_agent_loop_run('[DATEI: style.css]',
    ['tool'=>'pfile_read','input'=>'style.css'],'BASIS',$projectFileTools,0,
    static function(string $prompt) use (&$calls):?string { $calls++;return null; },
    static fn(array $tool):string => 'Datei style.css (Stand 1): Lesbar',
    static function(string $kind,string $state,string $title,array $meta) use (&$events):void {
      $events[]=['kind'=>$kind,'state'=>$state,'meta'=>$meta];
    },static fn():bool=>false,null,null,static fn():array=>$status);
  $failed=array_values(array_filter($events,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='empty_followup_after_tool'));
  loop_test($calls===1 && count($failed)===1
    && ($failed[0]['meta']['http_code']??0)===$status['http_code']
    && str_contains((string)$blocked['out'],'noch nicht abgeschlossen'),
    'no charge-generating blind retries on HTTP failures, throttling, local models or filters');
}
loop_test(coreui_agent_empty_followup_retryable(['provider'=>'external','http_code'=>200,
  'transport_ok'=>true,'curl_errno'=>0,'done_seen'=>true,'done'=>true,'done_reason'=>'stop'])
  && !coreui_agent_empty_followup_retryable(['provider'=>'external','http_code'=>429,
    'transport_ok'=>false,'curl_errno'=>0,'done_reason'=>'']),
  'one external retry is gated by a completed successful transport');

// Project instructions must not tell the model to finish in bare prose
// while the shared protocol requires a verified DONE/BLOCKED envelope.
$projectGuidance=coreui_agent_step_prompt('AUFTRAG',
  [['label'=>'Datei gelesen','result'=>'Datei vorhanden','state'=>'success']],
  1,0,['pfile_read','pfile_write','pfile_edit','prun']);
loop_test(str_contains($projectGuidance,'COREUI_TASK_DONE')
  && str_contains($projectGuidance,'Aktiver Projektauftrag')
  && !str_contains($projectGuidance,'vollstaendig und ohne Werkzeugmarker')
  && !str_contains($projectGuidance,'antworte jetzt in deinem persoenlichen Anzeigenamen'),
  'project prompt has one noncontradictory terminal decision contract');
$prefixedDone="[COREUI_PUBLIC_PROGRESS]Die Datei ist geprueft.[/COREUI_PUBLIC_PROGRESS]\n"
  . "[COREUI_TASK_DONE]\nDie Aufgabe ist abgeschlossen.\n[/COREUI_TASK_DONE]";
loop_test(coreui_task_decision($prefixedDone,
  static fn(string $r):?array=>null)['kind']==='done',
  'valid bounded progress note cannot hide a correctly framed task completion');

// 0.8.13: the same explicit control protocol works across model
// phrasings and tool kinds; it never infers an executable operation from prose.
$promisedText = 'Die Startseite hat Unterseiten. Ich prüfe als Nächstes das JavaScript.';
$taskParser = static fn(string $r):?array => coreui_agent_next_tool($r,$projectFileTools);
loop_test(coreui_task_decision($promisedText,$taskParser)['kind']==='invalid'
  && coreui_task_decision('Next I will inspect the JavaScript.',$taskParser)['kind']==='invalid'
  && coreui_task_decision('Es wird jetzt das Skript geprüft.',$taskParser)['kind']==='invalid'
  && coreui_task_decision('[DATEI: assets/app.js]',$taskParser)['kind']==='action'
  && coreui_task_decision($taskDone('Alles nachgewiesen.'),$taskParser)['kind']==='done'
  && coreui_task_decision("[COREUI_TASK_BLOCKED]\nZugriff verweigert.\n[/COREUI_TASK_BLOCKED]",$taskParser)['kind']==='blocked'
  && coreui_task_decision("[COREUI_TASK_DONE]\n[DATEI: secret.php]",$taskParser)['kind']==='invalid',
  'generic protocol accepts authorized tool, explicit done or blocked, not model phrasing');

// 0.8.15: one complete DONE/BLOCKED block counts however it is framed (observed with a cloud Core:
// a progress note, a blank line, then the block). Ambiguous replies stay invalid and name their form.
$framed = [
  "[COREUI_PUBLIC_PROGRESS]Alles geprueft.[/COREUI_PUBLIC_PROGRESS]\n\n[COREUI_TASK_DONE]\nFertig.\n[/COREUI_TASK_DONE]",
  "Alles erledigt.\n\n[COREUI_TASK_DONE]\nFertig.\n[/COREUI_TASK_DONE]\nViele Gruesse",
  "[COREUI_TASK_DONE] Fertig. [/COREUI_TASK_DONE]",
  "```text\n[COREUI_TASK_DONE]\nFertig.\n[/COREUI_TASK_DONE]\n```",
  "[COREUI_TASK_DONE]\r\nFertig.\r\n[/COREUI_TASK_DONE]\r\n",
];
foreach ($framed as $i => $reply) {
  $d = coreui_task_decision($reply, $taskParser);
  loop_test($d['kind'] === 'done' && $d['text'] === 'Fertig.' && $d['shape'] === '', 'framed DONE #' . $i . ' is a decision: ' . json_encode($d));
}
// 0.8.16: a marker written the Markdown way is the same marker.
foreach (["\\[COREUI\\_TASK\\_DONE\\]\nFertig.\n\\[/COREUI\\_TASK\\_DONE\\]", "**[COREUI_TASK_DONE]**\nFertig.\n**[/COREUI_TASK_DONE]**",
    "`[COREUI_TASK_DONE]`\nFertig.\n`[/COREUI_TASK_DONE]`", "[ COREUI_TASK_DONE ]\nFertig.\n[ /COREUI_TASK_DONE ]"] as $i => $reply) {
  $d = coreui_task_decision($reply, $taskParser);
  loop_test($d['kind'] === 'done' && $d['text'] === 'Fertig.', 'Markdown marker #' . $i . ': ' . json_encode([$reply, $d]));
}
loop_test(coreui_task_decision('COREUI_TASK_DONE: Fertig.', $taskParser)['shape'] === 'marker_malformed'
  && coreui_task_decision('Fertig.', $taskParser)['shape'] === 'prose_only', 'a marker without brackets is named as such');
$blockedFramed = coreui_task_decision("Kurz:\n[COREUI_TASK_BLOCKED]\nKein Zugriff.\n[/COREUI_TASK_BLOCKED]", $taskParser);
loop_test($blockedFramed['kind'] === 'blocked' && $blockedFramed['text'] === 'Kein Zugriff.', 'framed BLOCKED');
foreach ([
  ['', 'empty'],
  ['Die Seite ist fertig.', 'prose_only'],
  ["[COREUI_TASK_DONE]\nFertig.", 'block_unclosed'],
  ["Fertig.\n[/COREUI_TASK_DONE]", 'block_unclosed'],
  ["[/COREUI_TASK_DONE]\nFertig.\n[COREUI_TASK_DONE]", 'block_unclosed'],
  ["[COREUI_TASK_DONE]\na\n[/COREUI_TASK_DONE]\n[COREUI_TASK_DONE]\nb\n[/COREUI_TASK_DONE]", 'block_multiple'],
  ["[COREUI_TASK_DONE]\na\n[/COREUI_TASK_BLOCKED]", 'block_multiple'],
  ["[COREUI_TASK_DONE]\n \n[/COREUI_TASK_DONE]", 'block_empty'],
  ["[DATEI: assets/app.js]\n[COREUI_TASK_DONE]\nFertig.\n[/COREUI_TASK_DONE]", 'block_and_tool'],
  ["[COREUI_TASK_DONE]\n[DATEI: assets/app.js]\n[/COREUI_TASK_DONE]", 'block_and_tool'],
  ["[COREUI_TASK_FINISHED]\nFertig.\n[/COREUI_TASK_FINISHED]", 'marker_unknown'],
] as [$reply, $shape]) {
  $d = coreui_task_decision($reply, $taskParser);
  loop_test($d['kind'] === 'invalid' && $d['shape'] === $shape && $d['tool'] === null && $d['text'] === '',
    'invalid decision named ' . $shape . ': ' . json_encode($d));
}
loop_test(coreui_task_decision("[COREUI_PUBLIC_PROGRESS]Ich lese die Datei.[/COREUI_PUBLIC_PROGRESS]\n\n[DATEI: assets/app.js]", $taskParser)['kind'] === 'action',
  'a progress note with a blank line before a tool is still that tool');
// 0.8.16: the repair shows the model its own refused reply (bounded), so it can frame that text.
$echoRepair = coreui_task_decision_repair_prompt('KONTEXT', 'prose_only', "Die Seite ist fertig.\n<<<>>>");
loop_test(str_contains($echoRepair, "<<<\nDie Seite ist fertig.\n< < <> > >\n>>>") && str_contains($echoRepair, 'gib genau diesen Text innerhalb von [COREUI_TASK_DONE]')
  && !str_contains(coreui_task_decision_repair_prompt('KONTEXT', 'prose_only'), 'Deine letzte Ausgabe (nur zur Korrektur')
  && str_contains(coreui_task_decision_repair_prompt('KONTEXT', '', str_repeat('x', 5000)), str_repeat('x', COREUI_TASK_REPAIR_ECHO_CHARS) . ' [...]')
  && !str_contains(coreui_task_decision_repair_prompt('KONTEXT', '', str_repeat('x', 5000)), str_repeat('x', COREUI_TASK_REPAIR_ECHO_CHARS + 1)),
  'the repair shows the refused reply, bounded and without breaking its frame');
loop_test(str_contains(coreui_task_decision_repair_prompt('KONTEXT', 'prose_only'), '(erkannt: nur Text ohne Werkzeugmarker und ohne Steuerblock)')
  && !str_contains(coreui_task_decision_repair_prompt('KONTEXT'), 'erkannt:')
  && !str_contains(coreui_task_decision_repair_prompt('KONTEXT', 'unbekannt'), 'erkannt:'), 'the repair names the refused form');
loop_test(!str_contains($projectGuidance, 'Antworte in deinem persoenlichen Anzeigenamen:')
  && str_contains($projectGuidance, 'Den Text im COREUI_TASK_DONE- oder COREUI_TASK_BLOCKED-Block schreibst du in deinem persoenlichen Anzeigenamen.')
  && str_contains(coreui_agent_step_prompt('AUFTRAG', [['label'=>'Suche', 'result'=>'Treffer', 'state'=>'success']], 1, 6, ['web']),
    'Antworte in deinem persoenlichen Anzeigenamen:'),
  'project work asks for the decision only; ordinary answers keep the persona line');

$pendingReplies=[$promisedText,'[DATEI: assets/app.js]',$taskDone('Die Dateien wurden gelesen.')];
$pendingPrompts=[];$pendingTools=[];$pendingEvents=[];
$pendingLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$pendingReplies,&$pendingPrompts):?string {
    $pendingPrompts[]=$prompt;return array_shift($pendingReplies);
  },
  static function(array $tool) use (&$pendingTools):string {
    $pendingTools[]=$tool;return 'Bestaetigte Datei: ' . $tool['input'];
  },
  static function(string $kind,string $state,string $title,array $meta) use (&$pendingEvents):void {
    $pendingEvents[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  },static fn():bool=>false);
loop_test(array_column($pendingTools,'tool')===['pfile_read','pfile_read']
  && array_column($pendingTools,'input')===['assets/site.css','assets/app.js']
  && count($pendingPrompts)===3 && $pendingLoop['steps']===2
  && ($pendingLoop['outcome']??'')==='completed'
  && $pendingLoop['out']==='Die Dateien wurden gelesen.'
  && str_contains($pendingPrompts[1],'Deine letzte Ausgabe war keine gueltige Steuerentscheidung')
  && count(array_filter($pendingEvents,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='task_decision_repaired'))===1,
  'one generic decision repair continues project without repeating any real tool');

// 0.8.15 end to end: a cloud-style finish (progress note, blank line, DONE) completes the run; a
// reply that stays prose names its form in the repair and in the interruption.
$framedReplies=["[COREUI_PUBLIC_PROGRESS]Alles geprueft.[/COREUI_PUBLIC_PROGRESS]\n\n" . $taskDone('Die Dateien wurden gelesen.')];
$framedEvents=[];
$framedLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$framedReplies):?string { return array_shift($framedReplies); },
  static fn(array $tool):string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta) use (&$framedEvents):void {
    $framedEvents[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  },static fn():bool=>false);
loop_test(($framedLoop['outcome']??'')==='completed' && $framedLoop['out']==='Die Dateien wurden gelesen.'
  && !array_filter($framedEvents,static fn(array $e):bool=>($e['meta']['reason']??'')==='task_decision_missing'),
  'a framed DONE completes the project run without a repair');
$proseReplies=['Die Seite ist fertig.','Wirklich, alles ist fertig.'];
$prosePrompts=[];$proseEvents=[];
$proseLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$proseReplies,&$prosePrompts):?string { $prosePrompts[]=$prompt; return array_shift($proseReplies); },
  static fn(array $tool):string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta) use (&$proseEvents):void {
    $proseEvents[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  },static fn():bool=>false);
$proseStop=array_values(array_filter($proseEvents,static fn(array $e):bool=>$e['title']==='Projektauftrag ohne bestaetigten Abschluss unterbrochen'));
$proseMissing=array_values(array_filter($proseEvents,static fn(array $e):bool=>($e['meta']['reason']??'')==='task_decision_missing'));
// 0.8.18: a reply that stays prose after the one repair is Mara's answer again (as before 0.8.13), on
// the same evidence as a DONE block; the repair still names the form and shows the reply.
$proseDone=array_values(array_filter($proseEvents,static fn(array $e):bool=>($e['meta']['reason']??'')==='task_prose_answer'));
loop_test(($proseLoop['outcome']??'')==='completed' && ($proseLoop['reason']??'')===''
  && $proseLoop['out']==='Die Seite ist fertig.'
  && count($prosePrompts)===2 && str_contains($prosePrompts[1],'(erkannt: nur Text ohne Werkzeugmarker und ohne Steuerblock)')
  && str_contains($prosePrompts[1],"<<<\nDie Seite ist fertig.\n>>>")
  && ($proseMissing[0]['meta']['error_type']??'')==='prose_only' && $proseStop===[] && count($proseDone)===1
  && $proseDone[0]['state']==='info',
  'a prose answer after the one repair is the answer on verified evidence: ' . json_encode([$proseLoop, $proseMissing, $proseDone]));
$privateReplies = ['internal-only text', 'internal-only text'];
$privatePrompts = [];
$privateRun = coreui_agent_loop_run('[DATEI: main.js]',
  ['tool'=>'pfile_read','input'=>'main.js'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$privateReplies,&$privatePrompts): ?string {
    $privatePrompts[] = $prompt;
    return array_shift($privateReplies);
  },
  static fn(array $tool): string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta): void {},
  static fn(): bool => false,
  publicReply: static fn(string $text): string => $text === 'internal-only text' ? '' : $text);
loop_test($privateRun['outcome'] === 'interrupted' && $privateRun['steps'] === 1
  && count($privatePrompts) === 2 && !str_contains($privatePrompts[1], 'internal-only text')
  && !str_contains((string)$privateRun['out'], 'internal-only text'),
  'filtered prose receives one repair and leaves the task open without replaying the tool');
$multilineCommand = "[AUSFUEHREN: python - <<'PY'\nprint(1)\nPY\nnode --check app.js]";
$parseCommand = static fn(string $reply): ?array => coreui_agent_next_tool($reply, ['prun']);
loop_test(coreui_task_decision($multilineCommand, $parseCommand)['shape'] === 'command_multiline'
  && coreui_task_decision($taskDone($multilineCommand), $parseCommand)['shape'] === 'command_multiline'
  && coreui_task_decision('[AUSFUEHREN: node --check app.js]', $parseCommand)['kind'] === 'action',
  'a multiline command cannot be hidden inside a completion; authorized single-line commands still dispatch');
$commandReplies = [$multilineCommand, $multilineCommand];
$commandPrompts = [];
$commandTools = [];
$commandLoop = coreui_agent_loop_run('[DATEI: app.js]', ['tool'=>'pfile_read','input'=>'app.js'],
  'BASIS', $projectFileTools, 0,
  static function (string $prompt) use (&$commandReplies, &$commandPrompts): ?string {
    $commandPrompts[] = $prompt;
    return array_shift($commandReplies);
  },
  static function (array $tool) use (&$commandTools): string {
    $commandTools[] = $tool['tool'];
    return 'Bestaetigte Datei.';
  },
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test($commandTools === ['pfile_read'] && ($commandLoop['outcome'] ?? '') === 'interrupted'
  && !str_contains((string)$commandLoop['out'], 'node --check')
  && str_contains($commandPrompts[1], 'war mehrzeilig und wurde nicht ausgefuehrt'),
  'a multiline project command is repaired as a tool attempt and never accepted as prose');
// Without that evidence (the last tool step failed) the prose is shown with a note; the run is interrupted.
$weakReplies=['Die Tests laufen.','Die Tests laufen wirklich.'];$weakEvents=[];
$weakLoop=coreui_agent_loop_run('[AUSFUEHREN: npm test]',
  ['tool'=>'prun','input'=>'npm test'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$weakReplies):?string { return array_shift($weakReplies); },
  static fn(array $tool):string => 'Beendet mit Exitcode 1.',
  static function(string $kind,string $state,string $title,array $meta) use (&$weakEvents):void {
    $weakEvents[]=['kind'=>$kind,'state'=>$state,'title'=>$title,'meta'=>$meta];
  },static fn():bool=>false);
$weakStop=array_values(array_filter($weakEvents,static fn(array $e):bool=>$e['title']==='Projektauftrag ohne bestaetigten Abschluss unterbrochen'));
loop_test(($weakLoop['outcome']??'')==='interrupted' && ($weakLoop['reason']??'')==='task_prose_unverified'
  && str_starts_with((string)$weakLoop['out'],"Die Tests laufen.\n\nNicht jeder Werkzeugschritt dieses Auftrags ist bestätigt")
  && ($weakStop[0]['meta']['error_type']??'')==='prose_only'
  && !array_filter($weakEvents,static fn(array $e):bool=>($e['meta']['reason']??'')==='task_prose_answer'),
  'prose without verified evidence is shown with a note and never completes: ' . json_encode([$weakLoop, $weakStop]));
// A journal that does not confirm the run keeps it open too.
$journalReplies=['Fertig.','Fertig, wirklich.'];
$journalLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$journalReplies):?string { return array_shift($journalReplies); },
  static fn(array $tool):string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false,
  null,null,null,
  static fn(string $event):mixed => $event==='claim' ? ['status'=>'claimed'] : ($event==='can_finish' ? false : true));
loop_test(($journalLoop['outcome']??'')==='interrupted' && ($journalLoop['reason']??'')==='task_prose_unverified',
  'prose never completes a run the task journal does not confirm: ' . json_encode($journalLoop));

// 0.8.16 end to end: a Core that answers in prose frames that text once it sees it in the repair,
// and a declared blocker reaches the person in Mara's words.
$echoReplies=['Die Seite ist fertig.'];$echoPrompts=[];
$echoLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$echoReplies,&$echoPrompts):?string {
    $echoPrompts[]=$prompt;
    if ($echoReplies) return array_shift($echoReplies);
    return preg_match('~<<<\n(.*?)\n>>>~s',$prompt,$m)===1 ? "[COREUI_TASK_DONE]\n".$m[1]."\n[/COREUI_TASK_DONE]" : null;
  },
  static fn(array $tool):string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false);
loop_test(($echoLoop['outcome']??'')==='completed' && $echoLoop['out']==='Die Seite ist fertig.' && count($echoPrompts)===2,
  'a prose finish is framed in the one repair and completes: ' . json_encode($echoLoop));
$blockReplies=["[COREUI_TASK_BLOCKED]\nDer Ordner assets ist schreibgeschuetzt.\n[/COREUI_TASK_BLOCKED]"];
$blockLoop=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$blockReplies):?string { return array_shift($blockReplies); },
  static fn(array $tool):string => 'Bestaetigte Datei: ' . $tool['input'],
  static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false);
loop_test(($blockLoop['outcome']??'')==='interrupted' && ($blockLoop['reason']??'')==='task_blocked'
  && str_starts_with((string)$blockLoop['out'],'Der Ordner assets ist schreibgeschuetzt.')
  && str_contains((string)$blockLoop['out'],'Bereits bestaetigte Projektdateien bleiben gespeichert.'),
  'a declared blocker is shown in Mara\'s words: ' . json_encode($blockLoop));

$missingExecution = "Rick, die technische Pruefung fehlt, weil in dieser Sitzung kein ausführbares Projektwerkzeug verfügbar ist.";
$falseBlock = "[COREUI_TASK_BLOCKED]\n" . $missingExecution . "\n[/COREUI_TASK_BLOCKED]";
loop_test(coreui_agent_execution_repair(['kind'=>'blocked','text'=>$missingExecution], ['pfile_read']) === '',
  'a genuinely unavailable project runner must not be offered');
loop_test(coreui_agent_execution_repair(['kind'=>'blocked','text'=>'Der Ordner ist schreibgeschuetzt.'], ['prun']) === '',
  'a genuine blocker must not be overridden');
$verificationReplies = [$falseBlock, "[AUSFUEHREN: node --test]", "[COREUI_TASK_DONE]\nDie technische Pruefung ist gelaufen.\n[/COREUI_TASK_DONE]"];
$verificationTools = [];
$verificationRun = coreui_agent_loop_run('[DATEI: style.css]',
  ['tool'=>'pfile_read','input'=>'style.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$verificationReplies): ?string { return array_shift($verificationReplies); },
  static function(array $tool) use (&$verificationTools): string {
    $verificationTools[] = $tool['tool'];
    return $tool['tool'] === 'prun' ? 'Projektlauf abgeschlossen, Exitcode 0' : 'Datei style.css (Stand 2)';
  },
  static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false);
loop_test($verificationTools === ['pfile_read','prun'] && $verificationRun['outcome'] === 'completed',
  'a false missing-runner claim is repaired and the saved project is verified in the same turn');

$deniedProjectTools = 'Ich kann hier nicht auf Projektwerkzeuge zugreifen: Es werden keine tatsächlichen Datei- oder Testwerkzeuge angeboten.';
$denial = ['kind'=>'blocked','text'=>$deniedProjectTools];
$readOnlyRepair = coreui_agent_execution_repair($denial, ['pfile_read']);
loop_test(str_contains($readOnlyRepair, '[DATEI: pfad#L250]') && !str_contains($readOnlyRepair, '[AUSFUEHREN:')
  && coreui_agent_execution_repair($denial, []) === '',
  'repair advertises only markers permitted on the current turn');
loop_test(coreui_agent_execution_repair(
  ['kind'=>'blocked','text'=>'Das Testwerkzeug meldet, dass die Datei nicht verfügbar ist.'], ['prun']) === '',
  'a missing file reported by an available runner is a real blocker');

$denialReply = "[COREUI_TASK_BLOCKED]\n" . $deniedProjectTools . "\n[/COREUI_TASK_BLOCKED]";
foreach ([true, false] as $recovers) {
  $denialReplies = $recovers
    ? [$denialReply, '[AUSFUEHREN: node --test]', "[COREUI_TASK_DONE]\nDie technische Prüfung ist gelaufen.\n[/COREUI_TASK_DONE]"]
    : [$denialReply, $denialReply];
  $denialTools = [];
  $denialPrompts = [];
  $denialRun = coreui_agent_loop_run('[DATEI: index.html#L247]',
    ['tool'=>'pfile_read','input'=>'index.html#L247'],'BASIS',$projectFileTools,0,
    static function(string $prompt) use (&$denialReplies, &$denialPrompts): ?string {
      $denialPrompts[] = $prompt;
      return array_shift($denialReplies);
    },
    static function(array $tool) use (&$denialTools): string {
      $denialTools[] = $tool['tool'];
      return $tool['tool'] === 'prun' ? 'Projektlauf abgeschlossen, Exitcode 0' : 'Datei index.html (Stand 2)';
    },
    static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false);
  loop_test(str_contains($denialPrompts[1] ?? '', 'Marker als Antworttext')
    && $denialTools === ($recovers ? ['pfile_read','prun'] : ['pfile_read'])
    && $denialRun['outcome'] === ($recovers ? 'completed' : 'interrupted')
    && !str_contains((string)$denialRun['out'], $deniedProjectTools),
    'a false project tool denial gets one repair, and a recurring denial is not presented as fact');
}

$streamSource = (string)file_get_contents($root . '/api/console_stream.php');
loop_test(str_contains($streamSource, 'coreui_task_journal_resume_check($pdo,(int)$uid,$sessionId,(string)$sourceRun')
  && str_contains($streamSource, "if (!empty(\$GLOBALS['COREUI_TASK_RESUME_ACTIVE']) || \$projectContinuation)")
  && str_contains($streamSource, "\$projectDecision = \$decision['tool'];")
  && str_contains($streamSource, 'coreui_agent_execution_repair($decision,$projectToolAllowed)')
  && str_contains($streamSource, 'coreui_agent_blocked_text($decision,$projectToolAllowed)'),
  'explicit continuation uses an owned verified checkpoint and hands the accepted action to the shared dispatcher');
$excerptBlock = "[COREUI_TASK_BLOCKED]\nDer CSS-Ausschnitt ist unvollständig. Mir fehlt der nächste Abschnitt.\n[/COREUI_TASK_BLOCKED]";
$excerptReplies = [$excerptBlock, "[COREUI_TASK_DONE]\nDer CSS-Abschnitt wurde geprüft.\n[/COREUI_TASK_DONE]"];
$excerptReads = [];
$excerptRun = coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$excerptReplies): ?string { return array_shift($excerptReplies); },
  static function(array $tool) use (&$excerptReads): string {
    $excerptReads[] = $tool['input'];
    return $tool['input'] === 'assets/site.css'
      ? "Datei assets/site.css (Stand 1):\nbody { color: blue; }\nNaechster Quelltextabschnitt: [DATEI: assets/site.css#L250]"
      : 'Datei assets/site.css (Stand 1): .hero { display: grid; }';
  },
  static function(string $kind,string $state,string $title,array $meta):void {},static fn():bool=>false);
loop_test($excerptReads === ['assets/site.css','assets/site.css#L250'] && $excerptRun['outcome'] === 'completed',
  'a truncated CSS read continues within the same task');
loop_test(coreui_pftools_read_target('assets/site.css#L250') === ['assets/site.css',250]
  && coreui_pftools_numbered("first\nsecond\nthird",200,2) === "2 | second\n3 | third"
  && coreui_agent_file_target(['tool'=>'pfile_read','input'=>'assets/site.css#L250']) === 'assets/site.css',
  'ranged reads keep the original file in the journal and report absolute line numbers');

$stillPendingCalls=0;$stillPendingTools=[];
$stillPending=coreui_agent_loop_run('[DATEI: assets/site.css]',
  ['tool'=>'pfile_read','input'=>'assets/site.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$stillPendingCalls,$promisedText):?string {
    $stillPendingCalls++;return $promisedText;
  },
  static function(array $tool) use (&$stillPendingTools):string {
    $stillPendingTools[]=$tool;return 'Datei erfolgreich gelesen';
  },static function(string $kind,string $state,string $title,array $meta):void {},
  static fn():bool=>false);
// 0.8.18: the one repair is the chance to name the next tool. A Core that still answers in prose ends
// the turn with its own words (as before 0.8.13), after exactly one repair and without replaying a tool;
// the person can ask it to go on. Completion still needs the verified evidence (see the cases above).
loop_test($stillPendingCalls===2 && count($stillPendingTools)===1
  && $stillPending['steps']===1 && ($stillPending['outcome']??'')==='completed'
  && $stillPending['out']===$promisedText,
  'prose after the one repair ends the turn in Mara\'s words, one repair, no replay: ' . json_encode($stillPending));

$completeCalls=0;
$complete=coreui_agent_loop_run('[DATEI: style.css]',
  ['tool'=>'pfile_read','input'=>'style.css'],'BASIS',$projectFileTools,0,
  static function(string $prompt) use (&$completeCalls,$taskDone):?string {
    $completeCalls++;return $taskDone('Die bestehende Datei wurde geprüft.');
  },
  static fn(array $tool):string=>'Datei erfolgreich gelesen',
  static function(string $kind,string $state,string $title,array $meta):void {},
  static fn():bool=>false);
loop_test($completeCalls===1 && $complete['steps']===1
  && ($complete['outcome']??'')==='completed'
  && $complete['out']==='Die bestehende Datei wurde geprüft.',
  'explicit terminal completion requires no extra repair tokens');

$unverified=coreui_agent_loop_run('[DATEI: style.css]',
  ['tool'=>'pfile_read','input'=>'style.css'],'BASIS',$projectFileTools,0,
  static fn(string $prompt):?string=>$taskDone('Die Datei existiert sicher.'),
  static fn(array $tool):string=>'Das ging nicht: Datei nicht verfügbar.',
  static function(string $kind,string $state,string $title,array $meta):void {},
  static fn():bool=>false);
loop_test(($unverified['outcome']??'')==='interrupted'
  && str_contains((string)$unverified['out'],'noch nicht abgeschlossen'),
  'a model cannot finish after an actual failed tool');

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
loop_test(count($tools) === 1, 'legacy non-project cancellation stops after the running step');
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
$streamSource=(string)file_get_contents($root . '/api/console_stream.php');
loop_test(str_contains($chatSource,'coreui_agent_recover_promised_read($out, $loopAllowed)')
  && str_contains($streamSource,'coreui_agent_next_tool($text,$projectToolAllowed)')
  && str_contains($streamSource,'coreui_agent_recover_promised_read($text,$projectToolAllowed)')
  && substr_count($streamSource,'$projectToolPending($reply)')===2,
  'stream routes project file markers and promised reads to the authorized synchronous tool loop');


// 0.6.22: a route sends a step another way, first and later steps (NRW weather to the WDR place search).
$routeSeen = [];
$route = static function (array $tool) use (&$routeSeen): array {
  $routeSeen[] = $tool['input'];
  return str_contains($tool['input'], 'Gladbach') ? ['tool' => 'browse', 'input' => 'WDR-Ortssuche Gladbach', 'route' => 'wdr_place_search'] : $tool;
};
$recorded = [];
$rec = static function (string $kind, string $state, string $title, array $meta) use (&$recorded): void { $recorded[] = $title; };
$ran = [];
$first = coreui_agent_loop_run('[WEB: Wetter Bergisch Gladbach]', ['tool' => 'web', 'input' => 'Wetter Bergisch Gladbach'], 'Basis', ['web', 'browse'], 0,
  static fn(string $p): ?string => 'nie', static function (array $t) use (&$ran): string { $ran[] = $t; return 'x'; }, $rec,
  static fn(): bool => false, $route);
loop_test($first['browse'] === 'WDR-Ortssuche Gladbach' && $ran === [] && in_array('Wetter in NRW: WDR-Ortssuche im Browser', $recorded, true),
  'a routed first step goes to the browser before any search: ' . json_encode($recorded));
$recorded = [];
$later = coreui_agent_loop_run('[WEB: Termine heute]', ['tool' => 'web', 'input' => 'Termine heute'], 'Basis', ['web', 'browse'], 0,
  static fn(string $p): ?string => '[WEB: Wetter Bergisch Gladbach morgen]', static fn(array $t): string => 'Treffer', $rec,
  static fn(): bool => false, $route);
loop_test($later['browse'] === 'WDR-Ortssuche Gladbach' && $routeSeen[count($routeSeen) - 1] === 'Wetter Bergisch Gladbach morgen',
  'a later step is routed too');
$plain = coreui_agent_loop_run('[WEB: Termine heute]', ['tool' => 'web', 'input' => 'Termine heute'], 'Basis', ['web'], 0,
  static fn(string $p): ?string => 'Fertig.', static fn(array $t): string => 'Treffer', $rec, static fn(): bool => false);
loop_test($plain['out'] === 'Fertig.' && $plain['browse'] === null, 'without a route nothing changes');

// 0.6.22: an https address in a browser goal is where the worker starts (WDR place search); never a local one.
loop_test(coreui_browse_goal_start_url('WDR-Wetter Bergisch Gladbach ueber die Ortssuche auf https://www1.wdr.de/nrw/wetter/wettervorhersage/index.html')
  === 'https://www1.wdr.de/nrw/wetter/wettervorhersage/index.html'
  && coreui_browse_goal_start_url('schau auf https://example.org/a?b=1).') === 'https://example.org/a?b=1'
  && coreui_browse_goal_start_url('Termine im EVK recherchieren') === null, 'the start address of a browser goal');
foreach (['http://example.org/', 'https://user:pw@example.org/', 'https://localhost/a', 'https://127.0.0.1/x', 'https://[::1]/',
    'https://10.0.0.5/', 'https://192.168.1.2/', 'https://169.254.169.254/latest'] as $bad) {
  loop_test(coreui_browse_goal_start_url('geh auf ' . $bad) === null, 'no start at ' . $bad);
}

// 0.8.17: a reply cut at the output limit (a whole page in one DATEI_NEU block, observed in a
// continued homepage run at step 23) is named as such, not as "only text".
$fileParse = static fn(string $r): ?array => coreui_agent_next_tool($r, $projectFileTools);
$cutPage = "Ich erweitere die Gaestebuchseite.\n[DATEI_NEU: guestbook.html]\n<!doctype html>\n<html><body>\n<h1>Gaestebuch</h1>\n<p>Eintr";
loop_test((coreui_task_decision($cutPage, $fileParse)['shape'] ?? '') === 'tool_unclosed'
  && (coreui_task_decision("[DATEI_AENDERN: index.html]\n<<<<<<< SUCHEN\n<h1>Alt</h1>\n=======\n<h1>Ne", $fileParse)['shape'] ?? '') === 'tool_unclosed'
  && (coreui_task_decision("Ich rechne nach.\n[PY]\nprint(1", $fileParse)['shape'] ?? '') === 'tool_unclosed'
  && (coreui_task_decision('Die Seite ist fertig.', $fileParse)['shape'] ?? '') === 'prose_only'
  && coreui_task_decision("[DATEI_NEU: guestbook.html]\n<p>x</p>\n[/DATEI_NEU]", $fileParse)['kind'] === 'action',
  'an unclosed file or code block is its own form, a complete one stays an action');
loop_test(coreui_task_output_cut(['done_reason'=>'length']) && coreui_task_output_cut(['done_reason'=>'max_tokens'])
  && coreui_task_output_cut(['transport_ok'=>true,'done_seen'=>true,'done'=>false])
  && !coreui_task_output_cut(['done_reason'=>'stop','transport_ok'=>true,'done_seen'=>true,'done'=>true])
  && !coreui_task_output_cut(['transport_ok'=>false,'done_seen'=>true,'done'=>false]) && !coreui_task_output_cut([]),
  'a cut answer is read from how the model call ended');
$cutRepair = coreui_task_decision_repair_prompt('KONTEXT', 'output_truncated', $cutPage);
loop_test(str_contains($cutRepair, 'Davon wurde NICHTS gespeichert') && str_contains($cutRepair, 'die Ausgabegrenze war erreicht')
  && str_contains($cutRepair, 'kurzes, vollstaendiges Grundgeruest') && !str_contains($cutRepair, '<h1>Gaestebuch</h1>')
  && !str_contains(coreui_task_decision_repair_prompt('KONTEXT', 'tool_unclosed', $cutPage), 'Ausgabegrenze war erreicht')
  && str_contains(coreui_task_decision_repair_prompt('KONTEXT', 'prose_only', 'Die Seite ist fertig.'), 'Die Seite ist fertig.'),
  'the repair of a cut block asks for a smaller step and does not echo the cut page');
$protocol = coreui_task_protocol_prompt();
loop_test(str_contains($protocol, 'Geprueft heisst: ein Werkzeug hat es bestaetigt')
  && str_contains($protocol, 'Fehlt nur noch eine Pruefung, fuehre sie mit einem erlaubten Werkzeug aus; das ist A, keine Blockade') && str_contains($protocol, 'Grosse Dateien schreibst du in Teilen'),
  'checking is work, not a blocker; large files are written in parts');

// End to end: the cut page is repaired into a small skeleton, the run goes on and completes.
$cutReplies = [$cutPage, "[DATEI_NEU: guestbook.html]\n<h1>Gaestebuch</h1>\n[/DATEI_NEU]",
  $taskDone('Das Gaestebuch hat ein Grundgeruest.')];
$cutReports = [['done_reason'=>'length','transport_ok'=>true,'done_seen'=>true,'done'=>true],
  ['done_reason'=>'stop','transport_ok'=>true,'done_seen'=>true,'done'=>true],
  ['done_reason'=>'stop','transport_ok'=>true,'done_seen'=>true,'done'=>true]];
$cutPrompts = []; $cutTools = []; $cutEvents = []; $cutReport = [];
$cutLoop = coreui_agent_loop_run('[DATEI: guestbook.html]', ['tool'=>'pfile_read','input'=>'guestbook.html'], 'BASIS',
  $projectFileTools, 0,
  static function (string $p) use (&$cutReplies, &$cutReports, &$cutPrompts, &$cutReport): ?string {
    $cutPrompts[] = $p; $cutReport = array_shift($cutReports) ?? []; return array_shift($cutReplies);
  },
  static function (array $t) use (&$cutTools): string { $cutTools[] = $t; return 'Bestaetigte Datei: ' . $t['input']; },
  static function (string $k, string $s, string $t, array $m) use (&$cutEvents): void { $cutEvents[] = ['title'=>$t, 'meta'=>$m]; },
  static fn(): bool => false, null, null, static function () use (&$cutReport): array { return $cutReport; });
$cutMissing = array_values(array_filter($cutEvents, static fn(array $e): bool => ($e['meta']['reason'] ?? '') === 'task_decision_missing'));
loop_test(($cutLoop['outcome'] ?? '') === 'completed' && array_column($cutTools, 'tool') === ['pfile_read', 'pfile_write']
  && ($cutMissing[0]['meta']['error_type'] ?? '') === 'output_truncated'
  && ($cutMissing[0]['meta']['done_reason'] ?? '') === 'length' && ($cutMissing[0]['meta']['content_chars'] ?? 0) === strlen($cutPage)
  && str_contains($cutPrompts[1] ?? '', 'Davon wurde NICHTS gespeichert'),
  'a cut page is repaired into a smaller step and the run completes: ' . json_encode([$cutLoop, $cutMissing]));

// Earlier tool results are kept within a byte budget; the newest and every failure stay complete.
$bigOld = 'OLD_FILE_' . str_repeat('o', 30000);
$bigMid = 'MID_FILE_' . str_repeat('m', 30000);
$failOld = 'FAILED_' . str_repeat('f', 5000);
$budgeted = coreui_agent_budget_steps([
  ['label'=>'a','state'=>'success','result'=>$bigOld],
  ['label'=>'b','state'=>'error','result'=>$failOld],
  ['label'=>'c','state'=>'success','result'=>'small result'],
  ['label'=>'d','state'=>'success','result'=>$bigMid],
  ['label'=>'e','state'=>'success','result'=>'NEWEST_' . str_repeat('n', 60000)],
]);
loop_test($budgeted[4]['result'] === 'NEWEST_' . str_repeat('n', 60000)
  && str_contains($budgeted[3]['result'], hash('sha256', $bigMid)) && !str_contains($budgeted[3]['result'], 'MID_FILE_')
  && str_contains($budgeted[0]['result'], hash('sha256', $bigOld)) && $budgeted[1]['result'] === $failOld
  && $budgeted[2]['result'] === 'small result'
  && coreui_agent_budget_steps([['label'=>'x','state'=>'success','result'=>$bigOld],['label'=>'y','state'=>'success','result'=>'z']])[0]['result'] === $bigOld,
  'the project prompt keeps recent results within the budget and older large ones as size and hash');
$growReplies = ['[DATEI: b.html]', '[DATEI: c.html]', $taskDone('Gelesen.')];
$growPrompts = [];
coreui_agent_loop_run('[DATEI: a.html]', ['tool'=>'pfile_read','input'=>'a.html'], 'BASIS', $projectFileTools, 0,
  static function (string $p) use (&$growReplies, &$growPrompts): ?string { $growPrompts[] = $p; return array_shift($growReplies); },
  static fn(array $t): string => 'INHALT_' . $t['input'] . '_' . str_repeat('x', 30000),
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test(count($growPrompts) === 3 && str_contains($growPrompts[2], 'INHALT_c.html_')
  && !str_contains($growPrompts[2], 'INHALT_a.html_') && str_contains($growPrompts[2], 'Bestaetigtes Werkzeugergebnis bereits verarbeitet'),
  'a long project run no longer sends every earlier file in full');

// A read-only tool that repeats the same result leaves the run; the project work goes on.
$webReplies = ['[WEB: webdesign trends 2026]', '[WEB: webdesign trends 2026]', '[WEB: webdesign trends 2026]',
  "[DATEI_NEU: index.html]\n<h1>Start</h1>\n[/DATEI_NEU]", $taskDone('Startseite angelegt.')];
$webTools = []; $webEvents = []; $webPrompts = [];
$webLoop = coreui_agent_loop_run('[WEB: webdesign trends 2026]', ['tool'=>'web','input'=>'webdesign trends 2026'], 'BASIS',
  array_merge(['web'], $projectFileTools), 0,
  static function (string $p) use (&$webReplies, &$webPrompts): ?string { $webPrompts[] = $p; return array_shift($webReplies); },
  static function (array $t) use (&$webTools): string { $webTools[] = $t['tool']; return $t['tool'] === 'web' ? 'Keine verwertbaren Treffer.' : 'Bestaetigt.'; },
  static function (string $k, string $s, string $t, array $m) use (&$webEvents): void { $webEvents[] = $m['reason'] ?? ''; },
  static fn(): bool => false);
loop_test(($webLoop['outcome'] ?? '') === 'completed' && $webTools === ['web', 'web', 'web', 'pfile_write']
  && in_array('repeated_identical_tool_disabled', $webEvents, true) && !in_array('repeated_identical_tool_result', $webEvents, true)
  && !str_contains(end($webPrompts), '[WEB: neue, genauere Suchbegriffe]'),
  'three identical web results end the web search, not the project run: ' . json_encode([$webLoop, $webTools]));
$missReplies = ['[WEB: zweiter Begriff]', '[WEB: dritter Begriff]',
  "[DATEI_NEU: index.html]\n<h1>Start</h1>\n[/DATEI_NEU]", $taskDone('Startseite angelegt.')];
$missEvents = [];
$missLoop = coreui_agent_loop_run('[WEB: erster Begriff]', ['tool'=>'web','input'=>'erster Begriff'],
  'BASIS', array_merge(['web'], $projectFileTools), 0,
  static function (string $prompt) use (&$missReplies): ?string { return array_shift($missReplies); },
  static fn(array $tool): string|array => $tool['tool'] === 'web'
    ? ['text'=>'Keine Treffer.', 'state'=>'error', 'reason'=>'research_no_search_results'] : 'Bestaetigt.',
  static function (string $k, string $s, string $t, array $m) use (&$missEvents): void {
    if (isset($m['reason'])) $missEvents[$m['reason']][] = $s;
  }, static fn(): bool => false);
loop_test(($missLoop['outcome'] ?? '') === 'completed'
  && ($missEvents['research_no_search_results'] ?? []) === ['error', 'error', 'error']
  && ($missEvents['repeated_identical_tool_disabled'] ?? []) === ['info']
  && ($missEvents['verified_tool_result'] ?? []) === ['success'],
  'three misses with different queries give no verified evidence but let the project continue');

$readReplies = ['[DATEI: index.html]', $taskDone('Datei gelesen.')];
$readPrompts = [];
$readLoop = coreui_agent_loop_run('[DATEI: index.html]', ['tool'=>'pfile_read','input'=>'index.html'],
  'BASIS', $projectFileTools, 0,
  static function (string $prompt) use (&$readReplies, &$readPrompts): ?string {
    $readPrompts[] = $prompt;
    return array_shift($readReplies);
  },
  static fn(array $tool): string => 'Unveraenderter Dateiinhalt.',
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test(($readLoop['outcome'] ?? '') === 'completed' && $readLoop['steps'] === 2
  && str_contains($readPrompts[1], 'bereits im gleichen Stand gelesen'),
  'a repeated unchanged read gives the model a chance to proceed from the known file');

foreach ([false, true] as $resumeWriting) {
  $replies = ['[DATEI: styles.css]', '[DATEI: index.html]', '[DATEI: styles.css]'];
  $replies = array_merge($replies, $resumeWriting
    ? ["[DATEI_NEU: blog.html]\n<h1>Blog</h1>\n[/DATEI_NEU]", '[DATEI: index.html]', $taskDone('Blog angelegt.')]
    : ['[DATEI: index.html]', '[DATEI: styles.css]']);
  $prompts = [];
  $loop = coreui_agent_loop_run('[DATEI: index.html]', ['tool'=>'pfile_read','input'=>'index.html'],
    'BASIS', $projectFileTools, 0,
    static function (string $prompt) use (&$replies, &$prompts): ?string {
      $prompts[] = $prompt;
      return array_shift($replies);
    },
    static fn(array $tool): string => $tool['tool'] === 'pfile_read'
      ? 'QUELLE:' . $tool['input'] . ':' . str_repeat('x', 16000) : 'Bestaetigt.',
    static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
  loop_test(substr_count($prompts[3], 'QUELLE:index.html:') === 1
    && substr_count($prompts[3], 'QUELLE:styles.css:') === 1,
    'duplicate file reads consume the prompt budget only once per source');
  loop_test($resumeWriting
    ? ($loop['outcome'] === 'completed' && $loop['steps'] === 6)
    : ($loop['outcome'] === 'interrupted' && $loop['steps'] === 5
        && $loop['reason'] === 'repeated_project_file_reads' && count($replies) === 0),
    'alternating stale reads allow productive continuation but never spin indefinitely');
}
$ranges = ['[DATEI: style.css#L654]', '[DATEI: style.css#L1]', '[DATEI: style.css#L654]',
  '[DATEI: style.css#L1]', "[AUSFUEHREN: npm test]", $taskDone('Pruefung ausgefuehrt.')];
$rangePrompts = [];
$rangeRun = coreui_agent_loop_run('[DATEI: style.css]', ['tool'=>'pfile_read','input'=>'style.css'],
  'BASIS', $projectFileTools, 0,
  static function (string $prompt) use (&$ranges,&$rangePrompts): ?string {
    $rangePrompts[] = $prompt;
    return array_shift($ranges);
  },
  static function (array $tool): string {
    if ($tool['tool'] === 'prun') return 'Beendet mit Exitcode 0.';
    [, $line] = coreui_pftools_read_target($tool['input']);
    return 'Dateiauszug ab Zeile ' . $line;
  },
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test($rangeRun['outcome'] === 'completed' && $rangeRun['steps'] === 6
    && str_contains($rangePrompts[4], 'Wiederhole keinen bereits gelesenen unveraenderten Ausschnitt'),
  'alternating ranges on one file get one concrete chance to verify instead of re-reading');
$branchReplies = ['[ARBEITSSTAND: mara/redesign-pass]', '[DATEI: index.html]', $taskDone('Aktueller Stand gelesen.')];
$branchPrompts = [];
$branchLoop = coreui_agent_loop_run('[DATEI: index.html]', ['tool'=>'pfile_read','input'=>'index.html'],
  'BASIS', array_merge($projectFileTools, ['pvcs_branch']), 0,
  static function (string $prompt) use (&$branchReplies,&$branchPrompts): ?string {
    $branchPrompts[] = $prompt;
    return array_shift($branchReplies);
  },
  static fn(array $tool): string => $tool['tool'] === 'pvcs_branch'
    ? 'Gewechselt auf den Arbeitsstand mara/redesign-pass.' : 'Quelldatei: aktuelles HTML',
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test($branchLoop['outcome'] === 'completed'
    && !str_contains($branchPrompts[1], 'Quelldatei: aktuelles HTML')
    && str_contains($branchPrompts[1], 'Auszug aus einem vorherigen Arbeitsstand')
    && !str_contains($branchPrompts[2], 'bereits im gleichen Stand gelesen'),
  'switching working states invalidates previous excerpts and their duplicate-read keys');
// A writing tool with the same result three times still stops the run (nothing changes).
$sameReplies = ['[AUSFUEHREN: npm test]', '[AUSFUEHREN: npm test]', '[AUSFUEHREN: npm test]'];
$sameLoop = coreui_agent_loop_run('[AUSFUEHREN: npm test]', ['tool'=>'prun','input'=>'npm test'], 'BASIS', $projectFileTools, 0,
  static function (string $p) use (&$sameReplies): ?string { return array_shift($sameReplies); },
  static fn(array $t): string => 'Beendet mit Exitcode 1.',
  static function (string $k, string $s, string $t, array $m): void {}, static fn(): bool => false);
loop_test(($sameLoop['reason'] ?? '') === 'no_progress', 'a repeated failing command still ends the run');

// The project prompt asks for parts, a self-run check and the summary inside the DONE block.
$pfSource = (string)file_get_contents($root . '/api/project_file_tools.php');
loop_test(str_contains($pfSource, 'Eine grosse Datei legst du in Teilen an')
  && str_contains($pfSource, 'eine fehlende Pruefung ist keine Blockade')
  && str_contains($pfSource, 'in einem laufenden Projektauftrag steht das im COREUI_TASK_DONE-Block'),
  'project prompt: parts, own check, summary in the DONE block');

// A turn with attached files offers no native tool, and a marker there keeps the answer as text.
$nativeSource = (string)file_get_contents($root . '/api/console_native_tools.php');
$streamSource = (string)file_get_contents($root . '/api/console_stream.php');
require_once $root . '/api/console_native_tools.php';
$kept = coreui_native_markers_drop("Hier ist der Plan:\n**[COREUI_PLAN_SAVE]**\n# Roadmap\n- Punkt [1]\n[/COREUI_PLAN_SAVE]\nMehr: [Doku](https://example.test)");
loop_test($kept === "Hier ist der Plan:\n# Roadmap\n- Punkt [1]\nMehr: [Doku](https://example.test)"
  && !coreui_native_tool_attempt($kept)
  && str_contains($chatSource, "&& trim(\$attachBlock) === '';")
  && str_contains($streamSource, "!\$nativeEligible && coreui_native_tool_attempt(\$reply)")
  && str_contains($chatSource, "!\$nativeEligible && is_string(\$out) && coreui_native_tool_attempt(\$out)")
  && str_contains($nativeSource, 'function coreui_native_markers_drop'),
  'attachments: no native tool offered, an answer with markers stays text');

// 0.8.18: a resumed run keeps a plain answer too, only when the task journal confirms the saved state.
loop_test(str_contains($streamSource, "\$proseReply=(\$decision['kind']==='invalid' && (\$decision['shape'] ?? '')==='prose_only') ? trim(\$reply) : '';")
  && str_contains($streamSource, "} elseif (\$decision['kind']==='invalid' && \$proseReply!==''\n        && coreui_task_journal_can_finish(\$pdo,(int)\$uid,\$sessionId,\$agentRunId)) {\n      \$reply=\$proseReply;"),
  'a resumed run keeps a plain answer only with a confirming journal');

echo "CoreAI agent loop: chaining, answer, browser hand-over, six steps, cancel, work view OK\n";
