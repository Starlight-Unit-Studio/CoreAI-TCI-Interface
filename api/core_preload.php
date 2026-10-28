<?php
declare(strict_types=1);

/*
 * 0.5.75: load a Core into Ollama right after it was chosen in the chat,
 * so the first message does not wait for the cold start (about 11 s).
 *
 * Needs chat.php as library (queue, options, keep_alive). The request
 * waits in Mara's serial queue like a chat turn and sends exactly the
 * options a chat turn would send, so Ollama never has to reload the runner
 * with another window. An empty prompt only loads the model.
 */

function coreui_core_preload(PDO $pdo, int $uid, string $model): bool {
  if ($uid <= 0 || !coreui_model_name_valid($model) || ember_model_is_cloud($model)) return false;
  coreui_ai_runtime_apply($pdo, $uid);
  $body = ['model'=>$model, 'prompt'=>'', 'stream'=>false];
  $options = ember_ollama_known_good_options($model);
  if ($options !== []) $body['options'] = $options;
  $keepAlive = ember_keep_alive();
  if ($keepAlive !== null) $body['keep_alive'] = $keepAlive;
  // A busy queue is no error: the Core then loads with the next message.
  $lock = ember_global_lock_acquire(120);
  if ($lock === false) return false;
  try {
    $ch = curl_init(coreui_ollama_api_url('generate'));
    curl_setopt_array($ch, [
      CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
      CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>300, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES),
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status >= 200 && $status < 300;
  } finally {
    ember_generation_lock_release($lock);
  }
}
