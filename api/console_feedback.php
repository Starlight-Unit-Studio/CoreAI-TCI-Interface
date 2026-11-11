<?php
declare(strict_types=1);

require_once __DIR__ . '/console_feedback_store.php';

/** Model output is a visible follow-up message, never a thinking transcript. */
function coreui_feedback_reply_prompt(string $assistantName, string $senderName,
                                     string $ratedText, string $rating, string $request): string {
  $assessment = $rating === 'up'
    ? 'POSITIV: Die Person fand deine Antwort hilfreich.'
    : 'NEGATIV: Die Person fand deine Antwort nicht hilfreich.';
  return "Du bist " . $assistantName . " in deinem bestehenden privaten CoreAI-Chat.\n"
    . "Der aktuelle Gespraechspartner ist " . $senderName . ".\n"
    . "Er hat gerade aktiv eine deiner frueheren Antworten bewertet. "
    . "Das ist ein neues Ereignis im aktuellen Chat und du darfst selbst "
    . "direkt darauf reagieren, ohne dass eine weitere Nachricht geschrieben wird.\n"
    . "BEWERTUNG: " . $assessment . "\n"
    . "URSPEUNGLICHE FRAGE (falls vorhanden):\n" . $request . "\n"
    . "DEINE BEWERTETE ANTWORT:\n" . $ratedText . "\n\n"
    . "Reagiere kurz, natuerlich und direkt in deiner eigenen Stimme. "
    . "Bei positiver Bewertung darfst du dich freuen. Bei negativer Bewertung "
    . "erkenne das Feedback an und biete eine konkrete Verbesserung an, "
    . "ohne automatisch eine neue Antwort oder ein Werkzeug zu starten. "
    . "Keine Systemmeldung, keine Rolle wechseln, keine erfundenen Gefuehle "
    . "oder Erinnerungsaenderungen. Gib nur deine normale Chatantwort aus. "
    . "KEIN Thinking, keine Analyseausgabe, keine [BILD:]/[WEB:]/[PY]-Befehle.";
}

/**
 * Finish a saved feedback event with one model-authored Mara message.
 * The existing global serialized Ollama queue is responsible for concurrency.
 * $callModel is injectable for isolated tests; production explicitly disables
 * thinking and skills/tools for this call.
 */
function coreui_feedback_reply_run(PDO $pdo, int $uid, string $sessionId,
                                   int $eventId, string $senderName,
                                   ?callable $callModel = null): array {
  $claim = coreui_feedback_event_claim($pdo, $uid, $sessionId, $eventId);
  if (($claim['status'] ?? '') !== 'claimed') return $claim;

  try {
    $src = $claim['source'];
    $ratedText = ember_sanitize_public_reply((string)($src['message'] ?? ''), '');
    $question = '';
    $turnId = (int)($src['reply_to_id'] ?? 0);
    if ($turnId > 0) {
      $st = $pdo->prepare("SELECT message FROM stu_chat_messages
        WHERE id=? AND user_id=? AND session_id=? AND channel='console'
          AND alliance_id IS NULL AND deleted_at IS NULL LIMIT 1");
      $st->execute([$turnId,$uid,$sessionId]);
      $question = trim((string)($st->fetchColumn() ?: ''));
    }
    $assistantName = coreui_profile_assistant_name($pdo, $uid);
    $prompt = coreui_feedback_reply_prompt(
      $assistantName, trim($senderName), $ratedText,
      (string)$claim['rating'], $question
    );
    coreui_work_record($pdo,'model','running','Mara reagiert auf die Bewertung',
      ['reason'=>'feedback_reply','rating'=>$claim['rating'],
       'source_message_id'=>(int)$claim['message_id']]);
    if ($callModel === null) {
      $model = ember_model();
      $response = ember_call_ollama(
        $model, '', $prompt, ember_timeout_for_model($model),
        ['__skills'=>'none'], null, false, false
      );
      $meta = ember_last_call_meta();
      if (empty($meta['ok'])) $response = null;
    } else {
      $response = $callModel($prompt);
    }
    $text = is_string($response) ? ember_sanitize_public_reply($response, '') : '';
    if ($text === '' || (function_exists('coreui_agent_next_tool')
        && coreui_agent_next_tool($text, ['web','browse','python','archive','skill','image']) !== null)
        || preg_match('~\\[(?:WEB|BROWSE|ARCHIV|SKILL|BILD(?:\\s+GROSS)?(?:\\s+ICH)?):|\\[/?PY\\]~iu', $text) === 1) {
      coreui_feedback_event_fail($pdo, $uid, $sessionId, $eventId);
      return ['status'=>'failed', 'response_message_id'=>0];
    }
    $done = coreui_feedback_event_complete($pdo, $uid, $sessionId, $eventId, $text);
    if (($done['status'] ?? '') === 'done') {
      coreui_work_record($pdo,'model','success','Mara hat auf die Bewertung geantwortet',
        ['reason'=>'feedback_reply','response_message_id'=>$done['response_message_id']]);
    }
    return $done;
  } catch (Throwable $e) {
    coreui_feedback_event_fail($pdo, $uid, $sessionId, $eventId);
    if (function_exists('stu__log_error')) {
      stu__log_error(['type'=>'feedback_reply_failed', 'uid'=>$uid, 'detail'=>$e->getMessage()]);
    }
    throw $e;
  }
}
