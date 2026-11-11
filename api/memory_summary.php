<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.0: Settings > Personalisierung > Erinnerungszusammenfassung.
 *   GET                         the stored overview, whether it is current, how many memories
 *   POST {action:'generate'}    Mara writes the overview with the account's Core (in the model queue
 *                               like a message); unchanged memories return the stored one
 *   POST {action:'wipe', confirm:true}
 *                               "Erinnerung loeschen und ausschalten": the account's memories and the
 *                               overview are deleted, the memory switch goes off
 * See api/memory_summary_store.php.
 */

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/memory_summary_store.php';

header('Cache-Control: private, no-store');
$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function coreui_memory_summary_payload(PDO $pdo, int $uid, array $result): array {
  return ['ok' => true, 'ready' => coreui_memory_summary_ready($pdo),
    'memory_enabled' => coreui_ai_settings_load($pdo, $uid)['memory_enabled'],
    'fact_count' => (int)$result['fact_count'], 'fresh' => (bool)$result['fresh'], 'summary' => $result['summary']];
}

try {
  if ($method === 'GET') {
    stu_json(coreui_memory_summary_payload($pdo, $uid, coreui_memory_summary_get($pdo, $uid, null)) + ['csrf_token' => stu_csrf_token()]);
  }
  if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  stu_require_csrf();
  $body = stu_read_json_body();
  $action = (string)($body['action'] ?? '');

  if ($action === 'generate') {
    if (!coreui_memory_summary_ready($pdo)) stu_json(['ok' => false, 'error' => 'missing_schema_033'], 503);
    // The Core may take a while; never hold the PHP session lock meanwhile.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(600);
    // Unchanged memories: the stored overview, without asking the Core anything.
    $current = coreui_memory_summary_get($pdo, $uid, null);
    if ($current['fresh']) stu_json(coreui_memory_summary_payload($pdo, $uid, $current));
    coreui_ai_runtime_apply($pdo, $uid);
    $model = ember_model();
    $window = ember_ollama_context_window($model);
    $budget = ($window !== null && $window <= 8192) ? 4000 : 9000;
    $busy = false;
    $write = static function (string $prompt) use ($model, &$busy): ?string {
      // Waits in Mara's model queue like a message; a full queue is no error, only "try later".
      $slot = ember_global_lock_acquire(300);
      if ($slot === false) { $busy = true; return null; }
      try {
        $text = ember_call_ollama($model, '', $prompt, max(120, ember_timeout_for_model($model)), [], null, false, false);
        return is_string($text) && !ember_last_call_was_truncated() ? $text : null;
      } finally {
        ember_generation_lock_release($slot);
      }
    };
    try {
      $result = coreui_memory_summary_get($pdo, $uid, $write, $budget);
    } catch (RuntimeException $e) {
      if ($e->getMessage() !== 'summary_failed') throw $e;
      stu_json(['ok' => false, 'error' => $busy ? 'core_busy' : 'summary_failed'], $busy ? 503 : 502);
    }
    stu_json(coreui_memory_summary_payload($pdo, $uid, $result));
  }

  if ($action === 'wipe') {
    if (($body['confirm'] ?? null) !== true) stu_json(['ok' => false, 'error' => 'confirmation_required'], 400);
    $deleted = coreui_memory_summary_wipe($pdo, $uid);
    // Only the memory switch changes; every other AI setting of the account stays.
    $old = coreui_ai_settings_load($pdo, $uid);
    $new = coreui_ai_settings_save($pdo, $uid, array_merge($old, ['memory_enabled' => false]));
    stu_json(['ok' => true, 'deleted' => $deleted, 'memory_enabled' => $new['memory_enabled']]);
  }
  stu_json(['ok' => false, 'error' => 'invalid_action'], 400);
} catch (Throwable $e) {
  error_log('[CoreAI] Memory summary failed: ' . get_class($e));
  stu_json(['ok' => false, 'error' => 'memory_summary_failed'], 500);
}
