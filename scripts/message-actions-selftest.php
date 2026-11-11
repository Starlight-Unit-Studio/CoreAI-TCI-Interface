<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';
require_once dirname(__DIR__) . '/api/console_action_store.php';
require_once dirname(__DIR__) . '/api/console_feedback.php';

function actions_test_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrations/008_message_actions.sql');
$editMigration = (string)file_get_contents($root . '/database/migrations/009_message_editing.sql');
$endpoint = (string)file_get_contents($root . '/api/console_actions.php');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
$client = (string)file_get_contents($root . '/js/console-app.js');
$markdown = (string)file_get_contents($root . '/js/coreui-markdown.js');

actions_test_expect(str_contains($migration, 'stu_console_generation_requests'), 'Migration 008 besitzt keine Generierungsanforderungen.');
actions_test_expect(str_contains($migration, "ENUM('regenerate','continue')"), 'Neugenerierung und Fortsetzung sind nicht getrennt.');
actions_test_expect(str_contains($migration, 'response_floor_id'), 'Exakte Antwortgrenze fuer Wiederaufnahme fehlt.');
actions_test_expect(str_contains($migration, 'browse_job_id'), 'Exakte Browserjob-Zuordnung fehlt.');
actions_test_expect(str_contains($editMigration, 'stu_console_message_revisions'), 'Migration 009 besitzt keine Revisionshistorie.');
actions_test_expect(str_contains($editMigration, "'edit'"), 'Migration 009 erweitert den Generierungsmodus nicht um Bearbeitung.');
actions_test_expect(str_contains($endpoint, 'stu_require_csrf()'), 'Schreibende Nachrichtenaktionen besitzen keinen CSRF-Schutz.');
actions_test_expect(str_contains($endpoint, "action === 'feedback_reply'")
  && str_contains($endpoint, "'event_id'=>$eventId")
  && str_contains($client, "action: 'feedback_reply'"),
  'Persistentes Feedback startet eine eigene, authentifizierte Mara-Reaktion.');
actions_test_expect(str_contains($client, "is-feedback-up")
  && str_contains($client, "is-feedback-down")
  && str_contains($client, "Mara"), 'Beide Bewertungsdaumen haben eigene UI-Zustaende.');
actions_test_expect(str_contains($stream, '$generationRequestId'), 'Stream kann keine bestaetigte Nachrichtenaktion ausfuehren.');
actions_test_expect(str_contains($stream, '$excludeReplyToId = $afterId'), 'Alternative Antwort schliesst die alte Antwort nicht aus dem Kontext aus.');
actions_test_expect(str_contains($stream, 'coreui_console_generation_attach_browse'), 'Browserjob wird nicht exakt mit der Aktion verbunden.');
actions_test_expect(str_contains($client, "startGenerationAction(record, 'regenerate'"), 'Neugenerierungsaktion fehlt im Client.');
actions_test_expect(str_contains($client, "startGenerationAction(record, 'continue'"), 'Fortsetzungsaktion fehlt im Client.');
actions_test_expect(str_contains($client, "action: 'edit_message'"), 'Echte Nachrichtenbearbeitung fehlt im Client.');
actions_test_expect(str_contains($client, 'queuedTurnsBySession'), 'Nachrichtenwarteschlange waehrend einer Antwort fehlt.');
actions_test_expect(str_contains($client, 'msg-variant-nav'), 'Persistente Variantennavigation fehlt im Client.');
actions_test_expect(str_contains($client, "window.CoreUIMarkdown.render(bubble, text)"), 'Sichere Markdown-Darstellung ist nicht am Nachrichtenpfad aktiv.');
actions_test_expect(!str_contains($markdown, '.innerHTML'), 'Markdown-Renderer verwendet innerHTML.');

$pdo = stu_pdo();
actions_test_expect(coreui_console_actions_schema_ready($pdo), 'Migration 008 ist nicht aktiv.');
actions_test_expect(coreui_console_edits_schema_ready($pdo), 'Migration 009 ist nicht aktiv.');
actions_test_expect(coreui_feedback_events_ready($pdo), 'Migration 035 fuer Maras Feedback-Reaktionen fehlt.');

$suffix = bin2hex(random_bytes(6));
$sessionId = 'actions_' . $suffix;
$characterId = 'actions-char-' . $suffix;
$username = 'actions-' . $suffix . '@coreui.invalid';
$pdo->beginTransaction();
try {
  $pdo->prepare(
    'INSERT INTO stu_users (guest_key,username,password_hash,is_guest,permission_level,created_at) '
    . 'VALUES (?,?,?,?,?,NOW())'
  )->execute(['actions-' . $suffix, $username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 0, 4]);
  $uid = (int)$pdo->lastInsertId();
  actions_test_expect($uid > 0, 'Testkonto wurde nicht angelegt.');

  $pdo->prepare(
    'INSERT INTO stu_characters (id,user_id,alliance_id,name,name_norm,world_id,portrait_index,portrait_path,created_at) '
    . 'VALUES (?,?,NULL,?,?,1,0,\'\',NOW())'
  )->execute([$characterId, $uid, 'Action Test', strtolower($characterId)]);
  $pdo->prepare(
    'INSERT INTO stu_console_sessions '
    . '(id,user_id,title,since_id,last_message_id,last_read_message_id,created_at,updated_at,archived_at) '
    . 'VALUES (?,?,\'ACTION SELFTEST\',0,NULL,NULL,NOW(),NOW(),NULL)'
  )->execute([$sessionId, $uid]);

  $pdo->prepare(
    "INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,message,created_at)
     VALUES ('console',NULL,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, $characterId, 'Action Test', 'Bitte antworte.']);
  $turnId = (int)$pdo->lastInsertId();
  $pdo->prepare(
    "INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,message,reply_to_id,created_at)
     VALUES ('console',NULL,?,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, ember_character_id(), ember_character_name(), 'Erste Antwort.', $turnId]);
  $responseId = (int)$pdo->lastInsertId();

  $isolated = false;
  try {
    coreui_console_action_message($pdo, $uid + 1, $sessionId, $responseId, true);
  } catch (Throwable $eIsolation) {
    $isolated = true;
  }
  actions_test_expect($isolated, 'Fremdes Konto konnte die Ember-Antwort aufloesen.');

  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'up', $characterId);
  actions_test_expect($feedback === 'up', 'Positives Feedback wurde nicht gespeichert.');
  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'down', $characterId);
  actions_test_expect($feedback === 'down', 'Gegenteiliges Feedback wurde nicht atomar ersetzt.');
  $map = coreui_console_feedback_map($pdo, $uid, [$responseId]);
  actions_test_expect(($map[$responseId] ?? null) === 'down', 'Feedback wird nicht kontogebunden gelesen.');
  $stFeedbackToken = $pdo->prepare('SELECT emoji FROM stu_chat_reactions WHERE message_id=? AND user_id=? LIMIT 1');
  $stFeedbackToken->execute([$responseId, $uid]);
  actions_test_expect((string)$stFeedbackToken->fetchColumn() === 'coreui_down', 'Feedback verwendet nicht den altkompatiblen ASCII-Token.');

  // 0.7.7: Pressing the selected thumb again keeps the DB vote and the color.
  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'down', $characterId);
  actions_test_expect($feedback === 'down'
    && (coreui_console_feedback_map($pdo, $uid, [$responseId])[$responseId] ?? null) === 'down',
    'Eine wiederholte negative Bewertung muss dauerhaft aktiv bleiben.');

  // A changed rating atomically creates an event to be delivered to Mara.
  $feedbackEvent = null;
  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId,
    'up', $characterId, $feedbackEvent);
  actions_test_expect($feedback === 'up' && (int)$feedbackEvent > 0,
    'Der positive Daumen bleibt gespeichert und erzeugt ein Feedback-Event.');

  $modelCalls = 0;
  $reply = coreui_feedback_reply_run($pdo, $uid, $sessionId, (int)$feedbackEvent,
    'Action Test', static function (string $prompt) use (&$modelCalls): string {
      $modelCalls++;
      actions_test_expect(str_contains($prompt, 'POSITIV:')
        && str_contains($prompt, 'Erste Antwort.')
        && str_contains($prompt, 'Action Test'),
        'Mara bekommt die konkrete Antwort, den Absender und das positive Feedback.');
      return 'Danke, das freut mich! Ich mache so weiter.';
    });
  $newId = (int)($reply['response_message_id'] ?? 0);
  actions_test_expect($reply['status'] === 'done' && $newId > 0 && $modelCalls === 1,
    'Mara antwortet einmal nach einer positiven Bewertung.');
  $stResponse = $pdo->prepare('SELECT message,reply_to_id,thinking_content FROM stu_chat_messages WHERE id=?');
  $stResponse->execute([$newId]);
  $saved = $stResponse->fetch(PDO::FETCH_ASSOC);
  actions_test_expect(is_array($saved) && $saved['message'] === 'Danke, das freut mich! Ich mache so weiter.'
    && $saved['reply_to_id'] === null && $saved['thinking_content'] === null,
    'Die Reaktion ist eine echte, eigenstaendige Chat-Nachricht OHNE Thinking.');

  $again = coreui_feedback_reply_run($pdo, $uid, $sessionId, (int)$feedbackEvent,
    'Action Test', static function () use (&$modelCalls): string {
      $modelCalls++; return 'Zweite unerlaubte Antwort.';
    });
  actions_test_expect($again['status'] === 'done' && $modelCalls === 1
    && (int)$again['response_message_id'] === $newId,
    'Doppelte HTTP-Anfragen koennen keine zweite Reaktion erzeugen.');

  $secondEvent = null;
  coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'down',
    $characterId, $secondEvent);
  actions_test_expect((int)$secondEvent > (int)$feedbackEvent,
    'Wechsel von gruen zu rot loest eine neue Bewertung aus.');
  $negative = coreui_feedback_reply_run($pdo, $uid, $sessionId, (int)$secondEvent,
    'Action Test', static function (string $prompt): string {
      actions_test_expect(str_contains($prompt, 'NEGATIV:'),
        'Mara erkennt eine negative Bewertung als negativ.');
      return 'Danke fuer die Rueckmeldung. Ich kann das besser erklaeren.';
    });
  actions_test_expect($negative['status'] === 'done' && (int)$negative['response_message_id'] > $newId,
    'Negativer Daumen bewirkt eine neue persoenliche Rueckmeldung.');
  $sameEvent = null;
  coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'down',
    $characterId, $sameEvent);
  actions_test_expect($sameEvent === null, 'Derselbe Daumen erzeugt kein drittes Event.');

  $foreignEventDenied = false;
  try {
    coreui_feedback_event_claim($pdo, $uid + 1, $sessionId, (int)$secondEvent);
  } catch (Throwable $e) {
    $foreignEventDenied = true;
  }
  actions_test_expect($foreignEventDenied, 'Ein fremder Account darf den Event nicht lesen.');

  $edit = coreui_console_edit_prepare(
    $pdo,
    $uid,
    $sessionId,
    $turnId,
    'Bitte antworte mit der korrigierten Vorgabe.'
  );
  actions_test_expect((int)($edit['message_id'] ?? 0) === $turnId, 'Bearbeitung behaelt nicht dieselbe Nachrichten-ID.');
  actions_test_expect((int)($edit['revision_no'] ?? 0) === 1, 'Erste Revision wurde nicht protokolliert.');
  actions_test_expect((int)($edit['superseded_message_count'] ?? 0) >= 1, 'Abhaengige Antwort wurde nicht ausgeblendet.');
  actions_test_expect((string)($edit['request']['mode'] ?? '') === 'edit', 'Bearbeitung startet keine eigene Neugenerierung.');
  $stEdited = $pdo->prepare('SELECT message FROM stu_chat_messages WHERE id=?');
  $stEdited->execute([$turnId]);
  actions_test_expect((string)$stEdited->fetchColumn() === 'Bitte antworte mit der korrigierten Vorgabe.', 'Bearbeitete Blase wurde nicht in-place aktualisiert.');
  $stHidden = $pdo->prepare('SELECT deleted_at FROM stu_chat_messages WHERE id=?');
  $stHidden->execute([$responseId]);
  actions_test_expect((string)$stHidden->fetchColumn() !== '', 'Alte Antwort blieb nach der Bearbeitung im aktiven Zweig.');

  $pdo->prepare(
    "INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,message,reply_to_id,created_at)
     VALUES ('console',NULL,?,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, ember_character_id(), ember_character_name(), 'Antwort nach Bearbeitung.', $turnId]);
  $editedResponseId = (int)$pdo->lastInsertId();
  coreui_console_generation_finish($pdo, $uid, (string)$edit['request']['id'], $editedResponseId);

  $request = coreui_console_generation_prepare($pdo, $uid, $sessionId, $editedResponseId, 'regenerate');
  actions_test_expect(($request['trigger_message_id'] ?? 0) === $turnId, 'Neugenerierung ist nicht an den exakten Turn gebunden.');
  actions_test_expect(($request['source_response_id'] ?? 0) === $editedResponseId, 'Quellantwort der Neugenerierung fehlt.');
  $stRequest = $pdo->prepare('SELECT response_floor_id FROM stu_console_generation_requests WHERE id=?');
  $stRequest->execute([(string)$request['id']]);
  actions_test_expect((int)$stRequest->fetchColumn() >= $editedResponseId, 'Wiederaufnahme besitzt keine exakte Antwortgrenze.');

  coreui_console_session_archive($pdo, $uid, $sessionId);
  $busyDeleteRejected = false;
  try {
    coreui_console_session_delete_tx($pdo, $uid, $sessionId);
  } catch (RuntimeException $eDelete) {
    $busyDeleteRejected = $eDelete->getMessage() === 'session_busy';
  }
  actions_test_expect($busyDeleteRejected, 'Laufende Nachrichtenaktion blockiert die Sitzungsloeschung nicht.');

  $pdo->rollBack();
  fwrite(STDOUT, "Nachrichtenaktionen-Selftest OK: Isolation, persistentes Feedback, echte Bearbeitung, Varianten, Wiederaufnahme und Loeschschutz geprueft.\n");
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Nachrichtenaktionen-Selftest FEHLER: ' . $e->getMessage() . "\n");
  exit(2);
}
