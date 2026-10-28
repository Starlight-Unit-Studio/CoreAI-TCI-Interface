<?php
declare(strict_types=1);
// CoreAI 0.5.70: private RAG-Lite search on large lore documents. Offline on
// SQLite: three "bibles" where almost every chunk says "Starlight Unit" and
// only a few name "Ayal’ren" or "Alt Khemet" (live report 28.10.2026).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/knowledge_store.php';

function ks_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Knowledge search FAIL: {$why}\n"); exit(1); }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE stu_user_knowledge_sources (uuid TEXT, user_id INT, title TEXT, status TEXT,
  updated_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE stu_user_knowledge_chunks (id INTEGER PRIMARY KEY AUTOINCREMENT, source_uuid TEXT,
  user_id INT, title TEXT, chunk_no INT, chunk_text TEXT)");
$filler = [
  'Die Starlight Unit Flotte operiert im Sektor der Starlight Unit Studios. Starlight Unit Kommandanten berichten.',
  'Das Kapitel beschreibt Schiffe, Crews und die Geschichte der Starlight Unit. Unit Protokolle und Unit Raenge.',
  'Zusammenhaenge zwischen den Fraktionen der Starlight Unit werden erklaert. Starlight Unit Starlight Unit.',
  'Waren, Lernen, Fahren, Kennen: Alltag an Bord der Starlight Unit Stationen und ihrer Laeufer.',
];
$special = [
  '1:217' => 'Die Ayal’ren Matrix ist das Navigationsnetz der Uralten. Sie verbindet die Sternentore.',
  '2:33' => 'Alt Khemet war die erste Heimatwelt der Ayal’ren, bevor das Reich zerfiel.',
  '0:350' => 'Khemet-Archive in der Starlight Unit Datenbank verweisen auf die Ayal’ren.',
];
foreach (['Bibel I', 'Bibel II', 'Bibel III'] as $b => $title) {
  $pdo->prepare("INSERT INTO stu_user_knowledge_sources VALUES (?,7,?,'ready','2026-10-2{$b}','2026-10-2{$b}')")
    ->execute(['src' . $b, $title]);
  for ($i = 0; $i < 400; $i++) {
    $pdo->prepare('INSERT INTO stu_user_knowledge_chunks (source_uuid,user_id,title,chunk_no,chunk_text) VALUES (?,7,?,?,?)')
      ->execute(['src' . $b, $title, $i, $special["{$b}:{$i}"] ?? ($filler[$i % 4] . ' Abschnitt ' . $i . '.')]);
  }
}
$pdo->exec("INSERT INTO stu_user_knowledge_sources VALUES ('other',8,'Fremd','ready','x','x')");
$pdo->exec("INSERT INTO stu_user_knowledge_chunks (source_uuid,user_id,title,chunk_no,chunk_text)
  VALUES ('other',8,'Fremd',0,'Ayal’ren gehoert hier einem anderen Konto.')");

$top = static fn(array $rows): array => array_map(static fn(array $r): string => $r['title'] . '#' . $r['chunk_no'], $rows);

// Query terms: name stems survive apostrophes, tails and filler do not.
ks_test(coreui_rag_query_terms("Was ist die Ayal'ren Matrix?", 10) === ['ayal', 'matrix'], 'apostrophe name');
ks_test(coreui_rag_query_terms('Was ist die Ayal’ren Matrix?', 10)[0] === 'ayal', 'typographic apostrophe');
ks_test(!in_array('ren', coreui_rag_query_terms("Ayal'ren", 10), true), 'the tail never becomes a term');
ks_test(coreui_rag_query_terms('Du haettest erst deinen internen Kontext durchsuchen sollen', 10) === ['kontext', 'internen'],
  'conversational filler is ignored');

$rows = coreui_private_knowledge_search($pdo, 7, "Was ist die Ayal'ren Matrix?", 4);
ks_test(($top($rows)[0] ?? '') === 'Bibel II#217', 'the Ayal’ren Matrix chunk ranks first: ' . implode(',', $top($rows)));
ks_test(!array_filter($rows, static fn(array $r): bool => str_starts_with($r['chunk_text'], 'Waren')),
  '"ren" never matches inside Waren, Lernen, Fahren');
$rows = coreui_private_knowledge_search($pdo, 7,
  "Was weisst du ueber Ayal'ren und Alt Khemet aus den Starlight Unit Bibeln? Erklaer mir die Zusammenhaenge", 4);
ks_test(array_slice($top($rows), 0, 3) === ['Bibel III#33', 'Bibel I#350', 'Bibel II#217'],
  'rare names outweigh "Starlight Unit" in every chunk: ' . implode(',', $top($rows)));
ks_test(($GLOBALS['COREUI_KNOWLEDGE_LAST']['sources'] ?? 0) === 3, 'hits from all three bibles counted');
$rows = coreui_private_knowledge_search($pdo, 7, 'Erzaehl mir von Alt Khemet', 4);
ks_test(($top($rows)[0] ?? '') === 'Bibel III#33', 'the exact phrase "Alt Khemet" wins');
$rows = coreui_private_knowledge_search($pdo, 7, 'Was steht in Bibel II zur Matrix?', 4);
ks_test(($top($rows)[0] ?? '') === 'Bibel II#217', 'title and term together');
ks_test(!array_filter(coreui_private_knowledge_search($pdo, 7, "Ayal'ren", 8),
  static fn(array $r): bool => $r['source_uuid'] === 'other'), 'never another account');

// A follow-up names nothing itself: the recent conversation is used.
$ctx = "Rick: Was weisst du ueber Ayal'ren und Alt Khemet?\nMara (du): Dazu finde ich nichts.\n"
  . "Rick: Du haettest erst deinen internen Kontext durchsuchen sollen";
$follow = ember_knowledge_followup_query($ctx);
ks_test(str_contains($follow, "Ayal'ren") && str_contains($follow, 'Dazu finde ich nichts'), 'follow-up query');
$rows = coreui_private_knowledge_search($pdo, 7, 'Du haettest erst deinen internen Kontext durchsuchen sollen', 6, $follow);
ks_test(count($rows) === 3 && !empty($GLOBALS['COREUI_KNOWLEDGE_LAST']['followup']),
  'the follow-up finds the passages through the conversation');
ks_test(coreui_private_knowledge_search($pdo, 7, 'Wie ist das Wetter in Paris?', 4) === []
  && ($GLOBALS['COREUI_KNOWLEDGE_LAST']['searched'] ?? false) === true
  && ($GLOBALS['COREUI_KNOWLEDGE_LAST']['hits'] ?? -1) === 0, 'an unrelated question finds nothing, honestly');
ks_test(coreui_private_knowledge_search($pdo, 9, "Ayal'ren", 4) === []
  && empty($GLOBALS['COREUI_KNOWLEDGE_LAST']['searched']), 'an account without documents is not searched');

// Budget: a large window gets more and longer passages.
$small = coreui_private_knowledge_block($pdo, 7, "Ayal'ren Alt Khemet Matrix", 4, '', 2800);
$large = coreui_private_knowledge_block($pdo, 7, "Ayal'ren Alt Khemet Matrix", 6, '', 7000);
ks_test(mb_strlen($small, 'UTF-8') <= 2900 && str_contains($large, 'Ayal’ren Matrix'), 'block budget');

// Wiring: both prompt builders, the visible step and its meta.
$chat = (string)file_get_contents($root . '/api/chat.php');
ks_test(substr_count($chat, 'ember_knowledge_followup_query($ctx)') === 2
  && substr_count($chat, "if (\$channel === 'console') ember_record_knowledge_lookup(\$pdo);") === 2,
  'SSE and sync prompt builders search with follow-up and record the step');
$GLOBALS['COREUI_WORK_CONTEXT'] = null;
require_once $root . '/api/console_work_store.php';
ks_test(coreui_work_safe_meta(['knowledge_hits'=>3, 'knowledge_sources'=>2]) === ['knowledge_hits'=>3, 'knowledge_sources'=>2],
  'hit counts pass the work event whitelist');
$js = (string)file_get_contents($root . '/js/coreui-work-events.js');
ks_test(str_contains($js, "case 'Privates Wissen durchsucht':") && str_contains($js, "knowledge_hits:'Treffer'"),
  'the work view explains the search');

echo "CoreAI RAG-Lite: names, rare terms, follow-ups and budgets OK\n";
