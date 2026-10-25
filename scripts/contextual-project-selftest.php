<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_project_handoff.php';

function handoff_assert(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Project handoff FAIL: $message\n"); exit(1); }
}
$plan = [
  ['message'=>'Wir haben das Design abgeschlossen. Bitte eine private Homepage fuer das Spiel: '
    . 'dunkelblaues Farbschema, Menue, Steckbrief, Kontakt und responsive Mobilansicht.'],
  ['message'=>'Mara: Der Plan fuer die Website umfasst eine Einleitung, drei Karten, '
    . 'eine Galerie sowie HTML und CSS. Die Umsetzung koennen wir als naechstes starten.'],
  ['message'=>'Wir nehmen ein einfaches statisches Projekt mit CSS und JavaScript.'],
];

foreach ([
  'Dann setz unseren ausgearbeiteten Plan jetzt um!',
  'Okay, dann legen wir los.',
  'Mach bitte das Projekt fertig.',
  'Erstelle jetzt unsere Homepage.',
  'Kannst du die Website jetzt umsetzen?',
] as $approval) {
  $req = coreui_console_web_project_from_history($approval, $plan);
  handoff_assert(is_string($req) && $req !== $approval,
    'Explicit approved plan not recovered: ' . $approval);
  handoff_assert(coreui_console_download_requested($req),
    'Handoff must activate the existing download route');
  $spec = coreui_console_project_request_spec($req);
  handoff_assert(is_array($spec) && $spec['zip_requested']
    && array_values($spec['requested']) === ['index.html','style.css','main.js'],
    'Static website must have exactly three source files');
  handoff_assert(is_string(coreui_console_multifile_source_directive($req)),
    'Approved project must get validated ZIP source protocol');
  handoff_assert(str_contains($req,'dunkelblaues Farbschema'),
    'Agreed requirements missing from task payload');
}
$game = [
  ['message'=>'Plan fuer unser Browsergame: Raumschiff, Punkte, Tastatursteuerung.'],
  ['message'=>'Wir bauen ein Web-Spiel mit HTML und CSS.'],
];
$gameReq = coreui_console_web_project_from_history('Dann setz das um.', $game);
$gameSpec = is_string($gameReq) ? coreui_console_project_request_spec($gameReq) : null;
handoff_assert(is_array($gameSpec)
  && array_values($gameSpec['requested']) === ['index.html','style.css','game.js'],
  'Browser game should use game.js instead of main.js');

foreach ([
  'Kannst du erklaeren, wie du das spaeter umsetzen wuerdest?',
  'Warte, noch nicht umsetzen!',
  'Lass uns zuerst die Seiten besprechen.',
  'Wie wird die Homepage aussehen?',
  'Erstelle nur eine HTML-Datei.',
  'Wieso funktionieren die Dateien nicht?',
  'Wie geht es dir?',
] as $noAction) {
  handoff_assert(coreui_console_web_project_from_history($noAction, $plan) === null,
    'Planning/explanation/negation accidentally started an agent: ' . $noAction);
}
handoff_assert(coreui_console_web_project_from_history('Dann leg los.', []) === null,
  'Unrelated short message must not activate file generation');
handoff_assert(coreui_console_web_project_from_history('Mach das Projekt fertig.', [
  ['message'=>'Wir planen einen Finanzbericht und eine Tabelle.']
]) === null, 'Other artifact may not become a website');
handoff_assert(coreui_console_web_project_from_history('Dann bau die Homepage.', [
  ['message'=>'Das React-Vite-Projekt mit Node Backend ist spezifiziert.']
]) === null, 'Do not mispackage frameworks into a static three-file ZIP');

$explicit = 'Erstelle index.html, style.css und main.js, liefere ein ZIP zum Download.';
handoff_assert(coreui_console_web_project_from_history($explicit, $plan) === null,
  'Explicit file order must stay on the existing path');
handoff_assert(coreui_console_download_requested($explicit), 'Old explicit path broken');
handoff_assert(coreui_console_multifile_source_directive($explicit) !== null,
  'Old explicit ZIP path broken');

// The SQL query is scoped by user and session, bounded to prior messages and
// excludes deleted/private other-channel rows. Do not accept cross-chat plans.
$source = (string)file_get_contents(dirname(__DIR__) . '/api/console_project_handoff.php');
foreach ([
  "channel='console' AND user_id=? AND session_id=?",
  "id<? AND deleted_at IS NULL AND alliance_id IS NULL",
  'ORDER BY id DESC LIMIT 12',
  'coreui_console_web_project_execution_requested($message)',
] as $required) handoff_assert(str_contains($source, $required),
  'Session/turn ownership guard missing: ' . $required);
$stream = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
$chat = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
handoff_assert(str_contains($stream, '$fileTaskPrompt = $resolvedProject;')
  && str_contains($stream, 'coreui_console_download_requested($fileTaskPrompt)')
  && str_contains($stream, '$pdo, $char, $fileTaskPrompt, null'),
  'Streaming route did not use the resolved, agreed project');
handoff_assert(str_contains($chat, 'coreui_console_web_project_resolve(')
  && str_contains($chat, "require_once __DIR__ . '/console_project_handoff.php';"),
  'Synchronous route did not use the resolver');

echo "CoreUI project handoff: contextual site/game ZIP, explicit execution, negative intentions, session isolation OK\n";
