<?php
declare(strict_types=1);
// CoreAI: Feedback UI contract, also on production hosts without Node.js.
// php scripts/feedback-ui-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
  $content = @file_get_contents($root . '/' . $path);
  if (!is_string($content)) throw new RuntimeException('Datei fehlt: ' . $path);
  return $content;
};
$assert = static function (bool $ok, string $reason): void {
  if (!$ok) throw new RuntimeException('Feedback-Vertrag: ' . $reason);
};
try {
  $client = $read('js/console-app.js');
  $style = $read('css/coreui-feedback.css');
  $endpoint = $read('api/console_actions.php');
  $model = $read('api/console_feedback.php');
  $store = $read('api/console_feedback_store.php');
  $migration = $read('database/migrations/035_feedback_followups.sql');
  $assert(str_contains($client, "up: 'M1 21h4V9H1v12z")
    && str_contains($client, "down: 'M1 3h4v12H1V3z"),
    'Daumen-Symbole statt Pfeile');
  $assert(str_contains($client, "up.classList.add('is-feedback-up')")
    && str_contains($client, "down.classList.add('is-feedback-down')"),
    'Feedback-Klassen');
  $assert(str_contains($style, '.msg-action.is-feedback-up.active')
    && str_contains($style, 'color: #188c49;')
    && str_contains($style, '.msg-action.is-feedback-down.active')
    && str_contains($style, 'color: #c43a40;'),
    'persistente Daumenfarben');
  $assert(str_contains($client, 'record.feedback = data.feedback || null')
    && str_contains($client, "record && record.feedback === 'up'")
    && str_contains($client, "record && record.feedback === 'down'"),
    'gespeicherte Reaktionen beim Nachladen');
  $assert(str_contains($client, 'eventId > 0')
    && str_contains($client, "action: 'feedback_reply'"),
    'eine Mara-Folgeantwort pro Event');
  $assert(str_contains($endpoint, 'stu_require_csrf()')
    && str_contains($endpoint, 'coreui_feedback_reply_run('),
    'authentifizierter Backend-Endpunkt');
  $assert(str_contains($model, "['__skills'=>'none'], null, false, false")
    && str_contains($model, 'coreui_feedback_reply_prompt(')
    && str_contains($model, 'DAUMEN HOCH / LIKE')
    && str_contains($model, 'DAUMEN RUNTER / DISLIKE'),
    'Mara bekommt die Button-Bewertung, ohne Thinking und Werkzeuge');
  $assert(str_contains($store, 'reply_to_id,created_at')
    && str_contains($store, 'NULL,NULL,NOW()'),
    'Folgeantwort ist keine alternative Antwort und enthaelt kein Thinking');
  $assert(str_contains($migration, 'stu_console_feedback_events')
    && str_contains($migration, 'response_message_id BIGINT UNSIGNED NULL'),
    'dauerhafte, idempotente Feedback-Events');
  echo "CoreAI Feedback: serverseitiger UI-/Backend-Vertrag ohne Node.js geprueft.\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'Feedback FAIL: ' . $e->getMessage() . "\n");
  exit(1);
}
