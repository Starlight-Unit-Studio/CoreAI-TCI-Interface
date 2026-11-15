<?php
declare(strict_types=1);

/**
 * Safe, deterministic diagnostics for a failed image request.
 * Never copy a worker error, prompt, filesystem path or job log into a
 * public work event. Only recognized codes and bounded measurements survive.
 */
function coreui_image_failure_work_meta(array $result, array $progress = []): array {
  $error = (string)($result['error'] ?? 'image_failed');
  $detail = trim((string)($result['detail'] ?? ''));
  // The terminal worker response can include a newer phase, deadline and
  // RAM reading than the last displayed progress title.
  if (is_array($result['diagnostic'] ?? null)) {
    $progress = array_replace($progress, $result['diagnostic']);
  }
  $known = [
    'image_loading', 'image_unreachable', 'image_unavailable',
    'image_busy', 'model_queue_timeout', 'image_memory', 'image_timeout',
    'cancelled', 'bad_prompt', 'bad_size', 'bad_reference',
    'reference_unavailable', 'storage_full', 'storage_failed',
    'license_accept_required', 'pgen_unavailable',
    'guest_image_limit_reached', 'guest_image_disabled',
    'guest_image_quota_unavailable',
  ];
  $meta = [
    'source' => 'image',
    'reason' => in_array($error, $known, true) ? $error : 'image_failed',
  ];

  if ($error === 'image_failed') {
    if (preg_match('/^sd-cli Exit ([0-9]{1,3})$/D', $detail, $match) === 1) {
      $meta['reason'] = 'image_worker_exit';
      $meta['exit_code'] = (int)$match[1];
    } elseif (preg_match('/^Zeitlimit von ([1-9][0-9]{0,5}) s erreicht$/D', $detail) === 1) {
      $meta['reason'] = 'image_worker_timeout';
    } elseif ($detail === 'kein PNG erzeugt') {
      $meta['reason'] = 'image_png_missing';
    } elseif ($detail === 'kein gueltiges PNG') {
      $meta['reason'] = 'image_png_invalid';
    } elseif ($detail === 'Auftrag verloren') {
      $meta['reason'] = 'image_job_lost';
    } elseif (preg_match('/^([A-Za-z]+Error): /D', $detail, $match) === 1
        && in_array($match[1], [
          'MemoryError', 'OSError', 'PermissionError', 'FileNotFoundError',
          'ValueError', 'RuntimeError', 'TimeoutError',
        ], true)) {
      $meta['reason'] = 'image_worker_exception';
      $meta['error_type'] = $match[1];
    }
  }

  // The last confirmed phase is useful even when the worker crashes between
  // two polls. Never infer unseen steps from the browser's animated percentage.
  $phaseCode = (string)($progress['reason'] ?? '');
  if (preg_match('/^image_(load|text|sampling|decode|done)$/D', $phaseCode, $match) === 1) {
    $meta['image_phase'] = $match[1];
  }
  if (isset($progress['steps']) && is_numeric($progress['steps'])) {
    $steps = (int)$progress['steps'];
    if ($steps >= 1 && $steps <= 64) {
      $meta['steps'] = $steps;
      $meta['step'] = max(0, min($steps, (int)($progress['step'] ?? 0)));
    }
  }
  foreach (['reference_count'=>20, 'time_limit_s'=>7200,
            'elapsed_s'=>7200, 'peak_rss_mb'=>1048576] as $key=>$max) {
    if (isset($progress[$key]) && is_numeric($progress[$key])
        && is_finite((float)$progress[$key]) && (float)$progress[$key] >= 0) {
      $meta[$key] = min($max, (int)round((float)$progress[$key]));
    }
  }
  if (in_array(($progress['model'] ?? ''), ['flux2-klein-4b', 'flux2-klein-9b'], true)) {
    $meta['model'] = $progress['model'];
  }
  if (in_array(($progress['image_encoder'] ?? ''), ['original', 'ponpoke-uncensored-q8_0'], true)) {
    $meta['image_encoder'] = $progress['image_encoder'];
  }
  return $meta;
}
