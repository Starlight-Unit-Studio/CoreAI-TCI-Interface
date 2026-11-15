<?php
declare(strict_types=1);

/**
 * Operator-only, non-destructive FLUX.2-klein 4B A/B diagnosis.
 * The normal worker /jobs API is reused with a fixed, harmless prompt,
 * identical seed and resolution, and explicit encoder selection via the
 * existing account-license flag. No global pGen profile, model, or user
 * setting is modified. Test images are checked, not saved to user media.
 *
 * The browser drives short POST polls; the server persists each step. Closing
 * the mobile browser does not cancel a worker or lose a completed comparison.
 */
require_once __DIR__ . '/image_work_diagnostic.php';

const COREUI_IMAGE_AB_MODEL = 'flux2-klein-4b';
const COREUI_IMAGE_AB_PROMPT = 'A small red rowboat on a calm lake at sunrise, gentle golden light, peaceful trees, realistic photograph, no people';
const COREUI_IMAGE_AB_SEED = 420561;
const COREUI_IMAGE_AB_SIZE = 'normal';
const COREUI_IMAGE_AB_TIMEOUT = 390;

function coreui_image_ab_dir(): string {
  if (defined('COREUI_IMAGE_AB_TEST_DIR')) return (string)COREUI_IMAGE_AB_TEST_DIR;
  $dir = defined('STU_EMBER_CACHE_DIR') ? trim((string)STU_EMBER_CACHE_DIR) : '';
  return $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/var/cache';
}

function coreui_image_ab_empty(): array {
  return ['state'=>'idle', 'active'=>'', 'created_at'=>'', 'updated_at'=>'',
    'cases'=>['qwen'=>['state'=>'not_started'], 'ponpoke'=>['state'=>'not_started']]];
}

/** A state lock across PHP workers prevents duplicate jobs from browser tabs. */
function coreui_image_ab_locked(callable $callback): array {
  $dir = coreui_image_ab_dir();
  if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0750, true))
      || !is_dir($dir)) throw new RuntimeException('diagnostic_storage_unavailable');
  $lockPath = $dir . '/image-ab-diagnostic.lock';
  $statePath = $dir . '/image-ab-diagnostic.json';
  if (is_link($lockPath) || is_link($statePath)) throw new RuntimeException('diagnostic_storage_unsafe');
  $lock = @fopen($lockPath, 'c+');
  if ($lock === false) throw new RuntimeException('diagnostic_storage_unavailable');
  try {
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('diagnostic_request_busy');
    $raw = is_file($statePath) && filesize($statePath) <= 25000 ? @file_get_contents($statePath) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $state = is_array($decoded) && isset($decoded['state'], $decoded['cases'])
      ? $decoded : coreui_image_ab_empty();
    $result = $callback($state);
    if (!is_array($result) || !isset($result['state']) || !is_array($result['state'])) {
      throw new RuntimeException('diagnostic_state_invalid');
    }
    $next = $result['state'];
    if ($next !== $state) {
      $next['updated_at'] = gmdate('c');
      $encoded = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if (!is_string($encoded) || strlen($encoded) > 25000) throw new RuntimeException('diagnostic_state_invalid');
      $tmp = @tempnam($dir, '.image-ab-');
      if (!is_string($tmp)) throw new RuntimeException('diagnostic_storage_unavailable');
      try {
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false
            || !@chmod($tmp, 0600) || !@rename($tmp, $statePath)) {
          throw new RuntimeException('diagnostic_storage_unavailable');
        }
      } finally {
        if (is_file($tmp)) @unlink($tmp);
      }
    }
    return coreui_image_ab_public($next);
  } finally {
    @flock($lock, LOCK_UN);
    fclose($lock);
  }
}

/** Strictly limited output: never send job IDs, filesystem paths or raw sd-cli logs. */
function coreui_image_ab_public(array $state): array {
  $cases = [];
  foreach (['qwen', 'ponpoke'] as $kind) {
    $raw = is_array($state['cases'][$kind] ?? null) ? $state['cases'][$kind] : [];
    $case = ['state'=>(string)($raw['state'] ?? 'not_started')];
    foreach (['phase','encoder','error_code','error_type'] as $key) {
      if (isset($raw[$key]) && is_string($raw[$key])
          && preg_match('/^[a-zA-Z0-9_-]{1,65}$/D', $raw[$key])) {
        $case[$key] = $raw[$key];
      }
    }
    foreach (['step','steps','exit_code'] as $key) {
      if (isset($raw[$key]) && is_int($raw[$key])) $case[$key] = max(0, min(1000, $raw[$key]));
    }
    foreach (['seconds','peak_rss_mb'] as $key) {
      if (isset($raw[$key]) && is_numeric($raw[$key])) {
        $case[$key] = round(max(0, min(200000, (float)$raw[$key])), 1);
      }
    }
    if (isset($raw['png_valid'])) $case['png_valid'] = $raw['png_valid'] === true;
    $cases[$kind] = $case;
  }
  $a = $cases['qwen']['state'];
  $b = $cases['ponpoke']['state'];
  $comparison = 'pending';
  if (in_array($state['state'] ?? '', ['completed','cancelled'], true)) {
    $comparison = match (true) {
      $a === 'success' && $b === 'success' => 'both_passed',
      $a === 'success' && $b === 'failed' => 'ponpoke_path_suspected',
      $a === 'failed' && $b === 'success' => 'qwen_path_failed',
      $a === 'failed' && $b === 'failed' => 'both_failed',
      default => 'incomplete',
    };
  }
  return ['ok'=>true, 'state'=>(string)($state['state'] ?? 'idle'),
    'active'=>(string)($state['active'] ?? ''),
    'created_at'=>(string)($state['created_at'] ?? ''),
    'updated_at'=>(string)($state['updated_at'] ?? ''),
    'model'=>COREUI_IMAGE_AB_MODEL, 'size'=>'512x512', 'seed'=>COREUI_IMAGE_AB_SEED,
    'cases'=>$cases, 'comparison'=>$comparison];
}

function coreui_image_ab_safe_error(string $error): string {
  $known = ['image_busy','image_loading','image_unavailable','image_unreachable',
    'image_memory','image_timeout','pgen_unavailable','pgen_loading',
    'bad_request','bad_prompt','bad_size','bad_model','license_accept_required',
    'diagnostic_start_failed','diagnostic_worker_unreachable','diagnostic_job_lost',
    'diagnostic_timeout','diagnostic_png_invalid','diagnostic_encoder_mismatch',
    'diagnostic_model_mismatch','ponpoke_not_ready','cancelled'];
  return in_array($error, $known, true) ? $error : 'diagnostic_start_failed';
}

function coreui_image_ab_launch(string $encoder, callable $http): array {
  $expect = $encoder === 'qwen' ? 'original' : 'ponpoke-uncensored-q8_0';
  [$status, $body] = $http('POST', '/jobs', [
    'prompt'=>COREUI_IMAGE_AB_PROMPT, 'size'=>COREUI_IMAGE_AB_SIZE,
    'seed'=>COREUI_IMAGE_AB_SEED, 'model'=>COREUI_IMAGE_AB_MODEL,
    'user_license_accepted'=>$encoder === 'ponpoke',
  ], 10);
  if ($status !== 202 || !is_array($body['job'] ?? null)) {
    return ['state'=>'failed', 'error_code'=>coreui_image_ab_safe_error(
      (string)($body['error'] ?? 'diagnostic_start_failed'))];
  }
  $job = $body['job'];
  $id = (string)($job['id'] ?? '');
  if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
    return ['state'=>'failed', 'error_code'=>'diagnostic_start_failed'];
  }
  if (($job['model'] ?? '') !== COREUI_IMAGE_AB_MODEL
      || ($job['text_encoder'] ?? '') !== $expect) {
    $http('DELETE', '/jobs/' . $id, null, 5);
    return ['state'=>'failed', 'error_code'=>($job['model'] ?? '') !== COREUI_IMAGE_AB_MODEL
      ? 'diagnostic_model_mismatch' : 'diagnostic_encoder_mismatch'];
  }
  return ['state'=>'running', 'job_id'=>$id, 'started_at'=>time(),
    'encoder'=>$expect, 'phase'=>'load', 'step'=>0, 'steps'=>4];
}

/** Start Qwen; do not touch the globally selected pGen profile. */
function coreui_image_ab_start(array $state, callable $http): array {
  if (($state['state'] ?? '') === 'running') throw new RuntimeException('diagnostic_already_running');
  [$status, $health] = $http('GET', '/health', null, 4);
  if ($status !== 200 || !is_array($health) || empty($health['ready'])) {
    throw new RuntimeException('image_unavailable');
  }
  if (!empty($health['busy'])) throw new RuntimeException('image_busy');
  $first = coreui_image_ab_launch('qwen', $http);
  $state = coreui_image_ab_empty();
  $state['state'] = 'running';
  $state['active'] = 'qwen';
  $state['created_at'] = gmdate('c');
  $state['cases']['qwen'] = $first;
  if ($first['state'] !== 'running') {
    $state['state'] = 'completed';
    $state['active'] = '';
    $state['cases']['ponpoke'] = ['state'=>'not_started'];
  }
  return $state;
}

function coreui_image_ab_after_qwen(array $state, bool $licensed, callable $http): array {
  $state['active'] = 'ponpoke';
  if (!$licensed) {
    $state['cases']['ponpoke'] = ['state'=>'unavailable', 'error_code'=>'license_accept_required'];
  } else {
    [$status, $health] = $http('GET', '/health', null, 4);
    $ready = $status === 200 && is_array($health) && !empty($health['ready']);
    $ponpoke = is_array($health['ponpoke'][COREUI_IMAGE_AB_MODEL] ?? null)
      ? $health['ponpoke'][COREUI_IMAGE_AB_MODEL] : [];
    if (!$ready || empty($ponpoke['ready'])) {
      $state['cases']['ponpoke'] = ['state'=>'unavailable',
        'error_code'=>$ready ? 'ponpoke_not_ready' : 'image_unavailable'];
    } else {
      $state['cases']['ponpoke'] = coreui_image_ab_launch('ponpoke', $http);
    }
  }
  if ($state['cases']['ponpoke']['state'] !== 'running') {
    $state['state'] = 'completed';
    $state['active'] = '';
  }
  return $state;
}

/** One bounded status request per poll. A failed Qwen job does not suppress B. */
function coreui_image_ab_poll(array $state, bool $licensed, callable $http): array {
  if (($state['state'] ?? '') !== 'running') return $state;
  $which = (string)($state['active'] ?? '');
  if (!in_array($which, ['qwen','ponpoke'], true)) return $state;
  $case = $state['cases'][$which] ?? [];
  if (($case['state'] ?? '') !== 'running') return $state;
  $id = (string)($case['job_id'] ?? '');
  if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return $state;
  [$status, $body] = $http('GET', '/jobs/' . $id, null, 5);
  if ($status !== 200 || !is_array($body['job'] ?? null)) {
    if ($status === 404 || time() - (int)($case['started_at'] ?? 0) > COREUI_IMAGE_AB_TIMEOUT) {
      $case['state'] = 'failed';
      $case['error_code'] = $status === 404 ? 'diagnostic_job_lost' : 'diagnostic_worker_unreachable';
    } else {
      return $state;
    }
  } else {
    $job = $body['job'];
    foreach (['phase','text_encoder'] as $key) {
      $value = (string)($job[$key] ?? '');
      if ($key === 'phase' && in_array($value, ['load','text','sampling','decode','save','done'], true)) {
        $case['phase'] = $value;
      }
      if ($key === 'text_encoder' && in_array($value, ['original','ponpoke-uncensored-q8_0'], true)) {
        $case['encoder'] = $value;
      }
    }
    foreach (['step','steps'] as $key) if (is_numeric($job[$key] ?? null)) {
      $case[$key] = max(0, min(50, (int)$job[$key]));
    }
    foreach (['seconds','peak_rss_mb'] as $key) if (is_numeric($job[$key] ?? null)) {
      $case[$key] = round(max(0, min(200000, (float)$job[$key])), 1);
    }
    $jobState = (string)($job['state'] ?? '');
    if ($jobState === 'done') {
      [$code, , $png] = $http('GET', '/jobs/' . $id . '/image', null, 30);
      $size = $code === 200 && is_string($png) && strlen($png) < 25 * 1024 * 1024
        && function_exists('coreui_image_png_size') ? coreui_image_png_size($png) : null;
      if ($size === [512,512]) {
        $case['state'] = 'success';
        $case['png_valid'] = true;
      } else {
        $case['state'] = 'failed';
        $case['error_code'] = 'diagnostic_png_invalid';
        $case['png_valid'] = false;
      }
    } elseif ($jobState === 'failed' || $jobState === 'cancelled') {
      $case['state'] = $jobState === 'cancelled' ? 'cancelled' : 'failed';
      $info = coreui_image_failure_work_meta([
        'error'=>$jobState === 'cancelled' ? 'cancelled' : 'image_failed',
        'detail'=>(string)($job['error'] ?? ''),
      ], ['reason'=>'image_' . ($case['phase'] ?? 'load'),
        'step'=>(int)($case['step'] ?? 0), 'steps'=>(int)($case['steps'] ?? 4)]);
      $case['error_code'] = (string)($info['reason'] ?? 'image_failed');
      if (isset($info['exit_code'])) $case['exit_code'] = (int)$info['exit_code'];
      if (isset($info['error_type'])) $case['error_type'] = (string)$info['error_type'];
    } elseif (time() - (int)($case['started_at'] ?? 0) > COREUI_IMAGE_AB_TIMEOUT) {
      $http('DELETE', '/jobs/' . $id, null, 5);
      $case['state'] = 'failed';
      $case['error_code'] = 'diagnostic_timeout';
    }
  }
  $state['cases'][$which] = $case;
  if ($case['state'] !== 'running') {
    if ($which === 'qwen') return coreui_image_ab_after_qwen($state, $licensed, $http);
    $state['state'] = 'completed';
    $state['active'] = '';
  }
  return $state;
}

function coreui_image_ab_cancel(array $state, callable $http): array {
  if (($state['state'] ?? '') !== 'running') return $state;
  $which = (string)($state['active'] ?? '');
  $case = $state['cases'][$which] ?? [];
  $id = (string)($case['job_id'] ?? '');
  if (preg_match('/^[0-9a-f]{32}$/D', $id)) $http('DELETE', '/jobs/' . $id, null, 5);
  $state['cases'][$which]['state'] = 'cancelled';
  $state['cases'][$which]['error_code'] = 'cancelled';
  $state['state'] = 'cancelled';
  $state['active'] = '';
  return $state;
}
