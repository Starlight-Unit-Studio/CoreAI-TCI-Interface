<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_native_tools.php';

function plan_auto_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "Autonomous Markdown plan FAIL: {$message}\n");
    exit(1);
  }
}

$initialBody = "# Projekt: Private Homepage\n\n"
  . "## Ziel\nSechs responsive Seiten mit lokaler Navigation.\n\n"
  . "## Dateien\nindex.html, about.html, gallery.html und style.css.\n\n"
  . "## Anforderungen\nKlare Startseite, valide Links, konsistentes Design.\n";
$draft = COREUI_PLAN_DRAFT_MARKER . "\n\n" . $initialBody;
plan_auto_assert(coreui_plan_is_draft_markdown($draft),
  'Draft marker must remain distinct from completed plans');
plan_auto_assert(coreui_plan_draft_text($draft) === trim($initialBody),
  'Draft content must be available without its internal marker');

$longText = $draft . str_repeat("\n## Alter Abschnitt\n" . str_repeat('Details ',100),40);
$prompt = coreui_plan_followup_prompt($longText,str_repeat('Benutzerwunsch ',1000));
plan_auto_assert(strlen($prompt) < 6800
  && str_contains($prompt,'BEREITS VORHANDENE UEBERSCHRIFTEN')
  && str_contains($prompt,'[COREUI_PLAN_COMPLETE]')
  && !str_contains($prompt,str_repeat('Benutzerwunsch ',1000)),
  'Followup prompt must use bounded project context and an explicit completion marker');

foreach ([
  '[COREUI_PROJECT_BUILD]' . "\n" . 'Launch',
  "[COREUI_PLAN_SAVE]\n# Wieder von vorne",
  "[COREUI_PLAN_APPEND]\n## Illegal\nMehr",
  "## A\n" . str_repeat('Satz ',35) . "\n[COREUI_PROJECT_BUILD]",
  "## A\n" . str_repeat('Satz ',35) . "\n[COREUI_PLAN_COMPLETE]\nUnerwarteter Nachsatz"
] as $unsafe) {
  plan_auto_assert(coreui_plan_followup_parse($unsafe) === null
    || (!str_contains($unsafe,'[COREUI_PLAN_COMPLETE]')
      && coreui_plan_followup_parse($unsafe)['done'] === false),
    'Native-tool confusion or untrusted completion must not be accepted');
}

$segment = "## Umsetzung\n- Seite 1: Navigation und Startseite.\n"
  . "- Seite 2: Ueber mich und Projektlinks.\n"
  . "- Seite 3: Galerie mit Alternativtexten.\n";
$last = "## Abnahme\n- Alle sechs Seiten sind verlinkt und mobil bedienbar.\n"
  . "- Die lokale Vorschau zeigt keine Konsolenfehler.\n"
  . "[COREUI_PLAN_COMPLETE]";
plan_auto_assert(coreui_plan_followup_parse($segment)['done'] === false,
  'Ordinary end of an answer is not project completion');
$wrappedPart = "[COREUI_PLAN_APPEND]\n" . trim($segment) . "\n[/COREUI_PLAN_APPEND]";
plan_auto_assert(coreui_plan_followup_parse($wrappedPart)['text'] === trim($segment),
  'A single fully closed Markdown APPEND envelope must be recoverable');
$prefaced = "Ich ergaenze den naechsten Teil.\n\n" . $segment;
plan_auto_assert(coreui_plan_followup_parse($prefaced)['text'] === trim($segment),
  'A short harmless model preface must not discard the actual Markdown plan');
plan_auto_assert(coreui_plan_followup_parse($last)['done'] === true
  && !str_contains(coreui_plan_followup_parse($last)['text'],'COREUI_PLAN_COMPLETE'),
  'Completion marker must only be recognized at the end and stripped from saved Markdown');
plan_auto_assert(coreui_plan_followup_parse("[/COREUI_PLAN_SAVE]")['done'] === true,
  'A continuation that only closes an earlier plan block can complete its draft');

$merged = coreui_plan_merge_chunk($initialBody,$segment);
plan_auto_assert(is_string($merged) && str_contains($merged,'## Umsetzung')
  && coreui_plan_merge_chunk($merged,$segment) === null,
  'A repeated section must never be added again');
plan_auto_assert(coreui_plan_has_outline($merged),
  'A project requires a heading and multiple planning sections');
plan_auto_assert(coreui_plan_merge_chunk($initialBody,$initialBody) === null,
  'Model restarts must not duplicate an entire plan');

$toolPrompt = coreui_native_tools_prompt();
$cont = "[COREUI_PLAN_CONTINUE]\nJetzt den gespeicherten Entwurf erweitern.\n[/COREUI_PLAN_CONTINUE]";
plan_auto_assert(str_contains($toolPrompt,'[COREUI_PLAN_CONTINUE]')
  && (coreui_native_tool_parse($cont)['type'] ?? '') === 'PLAN_CONTINUE',
  'Mara must be able to select a plan continuation without user-side keywords');
$slashCont = "[COREUI/PLANCONTINUE]\nDen bereits gespeicherten Plan ergaenzen.\n[/COREUI/PLANCONTINUE]";
$openCont = "Ich setze den Plan fort.\n[COREUI_PLAN_CONTINUE]\n"
  . "Bitte alle ausstehenden Seiten und Abnahmekriterien ergaenzen.";
plan_auto_assert((coreui_native_tool_parse($openCont)['type'] ?? '') === 'PLAN_CONTINUE',
  'A model-selected open resume with short preface must be executable safely');
plan_auto_assert((coreui_native_tool_parse($slashCont)['type'] ?? '') === 'PLAN_CONTINUE',
  'Gemma slash alias must safely resume an existing private draft');

$chatSource = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
$streamSource = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
plan_auto_assert(
  substr_count($chatSource, "static function (string \$nextPrompt) use (\$primaryModel,\$sys)") === 2
  && str_contains($streamSource,"static function (string \$nextPrompt) use (\$model,\$sys)")
  && str_contains($streamSource,'console_stream_ollama($model,$sys,$nextPrompt,false,180)'),
  'Both sync and SSE pathways must wire the same bounded plan engine');

if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  class AutoPlanTestPDO extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
      return parent::prepare(str_replace('NOW()', 'CURRENT_TIMESTAMP', $query),$options);
    }
  }
  $pdo = new AutoPlanTestPDO('sqlite::memory:',null,null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE stu_chat_messages (
    id INTEGER PRIMARY KEY,user_id INTEGER,session_id TEXT,channel TEXT,
    deleted_at TEXT,reply_to_id INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_project_plans (
    id TEXT PRIMARY KEY,user_id INTEGER,session_id TEXT,source_turn_id INTEGER,
    response_message_id INTEGER,version INTEGER,title TEXT,markdown TEXT,
    byte_size INTEGER,sha256 TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
  $sid='cs_autoplan_1234567890';
  $pdo->exec("INSERT INTO stu_chat_messages(id,user_id,session_id,channel)
    VALUES(10,1,'$sid','console'),(11,1,'$sid','console')");
  $v1 = coreui_plan_save($pdo,1,$sid,10,$draft);
  $chunks = [
    ['text'=>$segment,'ok'=>true],
    ['text'=>$last,'ok'=>true],
  ];
  $calls=0;
  $model = static function (string $request) use (&$calls,&$chunks): array {
    $calls++;
    plan_auto_assert(str_contains($request,'Letzter') || str_contains($request,'LETZTER'),
      'Model did not receive the rolling saved plan');
    return array_shift($chunks) ?? ['text'=>'','ok'=>false];
  };
  $done = coreui_plan_autorun($pdo,1,$sid,10,$v1,$model,'Sechs Seiten',4);
  plan_auto_assert($done['complete'] && $done['segments']===2 && $calls===2,
    'Model-selected plan was not autonomously finished in serial steps');
  $saved = $done['saved'];
  plan_auto_assert((int)$saved['version']===3
    && !coreui_plan_is_draft_markdown($saved['markdown'])
    && str_contains($saved['markdown'],'## Abnahme')
    && !str_contains($saved['markdown'],'[COREUI_PLAN_COMPLETE]'),
    'Final Markdown revision must be complete, content-preserving and marker-free');
  plan_auto_assert(hash('sha256',$saved['markdown'])===$saved['sha256']
    && strlen($saved['markdown'])===$saved['byte_size'],
    'Download bytes and integrity hash must match');
  plan_auto_assert(coreui_plan_latest($pdo,2,$sid)===null,
    'A different account must not access saved plans');
  $count=$pdo->query('SELECT COUNT(*) FROM stu_console_project_plans')->fetchColumn();
  plan_auto_assert((int)$count===3,'Every model section must be an immutable checkpoint');
  $noBuild = coreui_native_tool_build_request($pdo,1,$sid,'React native app');
  plan_auto_assert($noBuild===null,'Autoplan must not bypass unsupported build guard');

  $draftV4=coreui_plan_save($pdo,1,$sid,11,$draft);
  $short = coreui_plan_autorun($pdo,1,$sid,11,$draftV4,
    static fn(string $prompt): array => ['text'=>$segment,'ok'=>true],
    'Homepage',1);
  plan_auto_assert(!$short['complete'] && $short['segments']===1
    && coreui_plan_is_draft_markdown($short['saved']['markdown']),
    'The round limit must preserve all progress but must NOT mark an incomplete plan finished');

  $noProgress=coreui_plan_autorun($pdo,1,$sid,11,$short['saved'],
    static fn(string $prompt): array => ['text'=>$segment,'ok'=>true],
    'Homepage',4);
  plan_auto_assert($noProgress['segments']===0 && !$noProgress['complete'],
    'Repeated model output must not create another duplicate revision');

  $unexpectedBuild = false;
  $resume = coreui_native_tool_apply($pdo,1,$sid,11,$openCont,
    static function (string $request) use (&$unexpectedBuild): string {
      $unexpectedBuild = true;
      return 'SHOULD NOT BUILD';
    },true,
    static fn(string $prompt): array => ['text'=>$last,'ok'=>true],'Homepage');
  plan_auto_assert(!$unexpectedBuild,
    'Truncated PLAN_CONTINUE must never call the project build worker');
  plan_auto_assert(is_string($resume)
    && str_contains($resume,'abgeschlossene Markdown-Version')
    && !coreui_plan_is_draft_markdown(coreui_plan_latest($pdo,1,$sid)['markdown']),
    'Existing private drafts must be resumable by Mara using PLAN_CONTINUE');

  $denied = coreui_plan_autorun($pdo,2,$sid,10,$v1,
    static fn(string $prompt): string => $segment,'Homepage',1);
  plan_auto_assert($denied['segments']===0
    && coreui_plan_latest($pdo,2,$sid)===null,
    'Unowned trigger must not permit autonomous plan revision');
} else {
  fwrite(STDOUT,"Autoplan SQLite fixture SKIP (pdo_sqlite missing)\n");
}
fwrite(STDOUT,"Autonomous Markdown planning selftest OK: bounded segments, private versions, finished/unfinished state and resume\n");
