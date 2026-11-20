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
$projectResponses[] = 'Projektauftrag abgeschlossen mit geprueften Werkzeugen.';
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
$readReplies = [$readPromise,'Die Datei wurde mit dem Werkzeug geprüft; weitere Änderungen sind noch offen.'];
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
  && $readSteps[1] === $expectedRead
  && $recoveredLoop['steps']===2
  && str_contains((string)$recoveredLoop['out'],'noch offen')
  && count(array_filter($readEvents,static fn(array $e): bool =>
    ($e['meta']['reason']??'')==='read_intent_without_marker'))===1,
  'file-read promise results in real verified tool result and autonomous continuation');

// A project run sends the model's own public note before each real tool.
$projectNotes = [];
$publicLoop = coreui_agent_loop_run(
  $publicToolReply, coreui_agent_next_tool($publicToolReply,['web']), 'BASIS',
  ['web','pfile_write','pfile_edit','prun'], 0,
  static fn(string $prompt): ?string => 'Ich habe die Suche abgeschlossen. Jetzt kann ich antworten.',
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
  && count($replies)===1 && $editLoop['steps']===3
  && str_contains((string)$editLoop['out'],'guestbook.html')
  && str_contains((string)$editLoop['out'],'wurde nicht gespeichert')
  && str_contains((string)$editLoop['out'],'Wiederholung beendet')
  && count(array_filter($logged,static fn(array $e):bool =>
    ($e['meta']['reason']??'')==='same_file_edit_rejected_twice'))===1,
  'changed patch body cannot bypass no-progress guard; one read, one retry and honest stop');
loop_test(str_contains($replies[0], '<form>saved original</form>')
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
  'Gästebuch erfolgreich geändert, gespeicherter Stand bestätigt.',
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
$recoveryReplies = [null, '[DATEI: contact.html]', 'Die Kontaktseite wurde gelesen; weitere Arbeit bleibt offen.'];
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

echo "CoreAI agent loop: chaining, answer, browser hand-over, six steps, cancel, work view OK\n";
