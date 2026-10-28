<?php
declare(strict_types=1);

/*
 * 0.5.70 (#113): memory guide values for the KI-Profil context slider.
 *
 * Read-only towards Ollama (/api/tags, /api/show, /api/ps) and the kernel
 * (/proc/meminfo). The calculation itself is a pure function so it can be
 * tested offline. Values are rough ranges, never a hardware promise: when
 * the model metadata is not sufficient the stop says "not estimable".
 */

require_once __DIR__ . '/model_catalog.php';

const COREUI_GIB = 1073741824;

/** Ollama URL next to the operator's chat URL, e.g. /api/show or /api/ps. */
function coreui_ollama_api_url(string $endpoint): string {
  $tags = coreui_ollama_tags_url();
  return (string)preg_replace('~/api/tags$~', '/api/' . $endpoint, $tags);
}

function coreui_ollama_read_json(string $endpoint, ?array $post = null): ?array {
  if (!function_exists('curl_init')) return null;
  try {
    $ch = curl_init(coreui_ollama_api_url($endpoint));
  } catch (Throwable $e) {
    return null;
  }
  if (!$ch) return null;
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
  ];
  if ($post !== null) {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = json_encode($post, JSON_UNESCAPED_SLASHES);
  }
  curl_setopt_array($ch, $opts);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if (!is_string($raw) || $status < 200 || $status >= 300) return null;
  $json = json_decode($raw, true);
  return is_array($json) ? $json : null;
}

/** Model name as /api/ps reports it: lower case, ":latest" when no tag is given. */
function coreui_ollama_model_key(string $name): string {
  $name = strtolower(trim($name));
  return ($name !== '' && !str_contains($name, ':')) ? $name . ':latest' : $name;
}

/** 0.5.75: Cores Ollama holds in memory right now (/api/ps), as model keys. */
function coreui_ollama_loaded_names(): array {
  $ps = coreui_ollama_read_json('ps') ?? [];
  $names = [];
  foreach ((is_array($ps['models'] ?? null) ? $ps['models'] : []) as $row) {
    if (!is_array($row)) continue;
    $key = coreui_ollama_model_key((string)($row['name'] ?? $row['model'] ?? ''));
    if ($key !== '') $names[] = $key;
  }
  return array_values(array_unique($names));
}

/** MemAvailable in bytes as the kernel reports it, null if unreadable. */
function coreui_mem_available_bytes(?string $meminfo = null): ?int {
  $meminfo ??= (string)@file_get_contents('/proc/meminfo');
  if (preg_match('~^MemAvailable:\s+(\d+)\s+kB~mi', $meminfo, $m) !== 1) return null;
  return (int)$m[1] * 1024;
}

/**
 * KV cache layout from GGUF model_info: bytes per token for full attention
 * layers and for sliding window layers. null when the metadata does not
 * allow a defensible calculation (then no number is invented).
 */
function coreui_ai_kv_layout(array $info): ?array {
  $arch = (string)($info['general.architecture'] ?? '');
  if (preg_match('~^[a-z0-9_]{2,40}$~', $arch) !== 1) return null;
  $get = static fn(string $key) => $info[$arch . '.' . $key] ?? null;
  $layers = (int)$get('block_count');
  if ($layers < 1 || $layers > 1024) return null;
  $heads = $get('attention.head_count');
  $headMax = is_array($heads) ? (int)max(array_map('intval', $heads ?: [0])) : (int)$heads;
  $kvHeads = $get('attention.head_count_kv') ?? $heads;
  $keyLen = (int)($get('attention.key_length') ?? 0);
  $embed = (int)($get('embedding_length') ?? 0);
  if ($keyLen <= 0 && $headMax > 0 && $embed > 0) $keyLen = intdiv($embed, $headMax);
  $valueLen = (int)($get('attention.value_length') ?? 0) ?: $keyLen;
  if ($keyLen <= 0 || $valueLen <= 0) return null;
  $keySwa = (int)($get('attention.key_length_swa') ?? 0) ?: $keyLen;
  $valueSwa = (int)($get('attention.value_length_swa') ?? 0) ?: $valueLen;

  // Which layers only keep a sliding window: an explicit per layer list, a
  // llama.cpp style "every n-th layer is global" number, or the documented
  // Gemma 3 rhythm (five local, one global). Anything else is unknown.
  $window = (int)($get('attention.sliding_window') ?? 0);
  $pattern = $get('attention.sliding_window_pattern');
  $sliding = array_fill(0, $layers, false);
  if ($window > 0) {
    if (is_array($pattern) && $pattern !== []) {
      $list = array_values($pattern);
      for ($i = 0; $i < $layers; $i++) $sliding[$i] = (bool)$list[$i % count($list)];
    } elseif (is_int($pattern) && $pattern > 1) {
      for ($i = 0; $i < $layers; $i++) $sliding[$i] = (($i + 1) % $pattern) !== 0;
    } elseif ($arch === 'gemma3') {
      for ($i = 0; $i < $layers; $i++) $sliding[$i] = (($i + 1) % 6) !== 0;
    } else {
      return null;
    }
  }

  $global = 0;
  $local = 0;
  for ($i = 0; $i < $layers; $i++) {
    $kv = is_array($kvHeads) ? (int)($kvHeads[$i] ?? 0) : (int)$kvHeads;
    if ($kv < 0) return null;
    // f16 cache: two bytes per value; K and V both stored.
    if ($sliding[$i]) $local += $kv * ($keySwa + $valueSwa) * 2;
    else $global += $kv * ($keyLen + $valueLen) * 2;
  }
  if ($global + $local <= 0) return null;
  $trained = (int)($get('context_length') ?? 0);
  return ['global_per_token'=>$global, 'local_per_token'=>$local, 'window'=>$window,
    'trained_context'=>$trained > 0 ? $trained : null];
}

function coreui_ai_kv_bytes(array $layout, int $ctx): float {
  $local = $layout['window'] > 0 ? min($ctx, (int)$layout['window']) : $ctx;
  return (float)$layout['global_per_token'] * $ctx + (float)$layout['local_per_token'] * $local;
}

/** Rounded GB: half steps below 10 GB, whole numbers above. */
function coreui_ai_gb(float $bytes, bool $up): float {
  $gb = max(0.0, $bytes / COREUI_GIB);
  $step = $gb < 10 ? 0.5 : 1.0;
  $value = ($up ? ceil($gb / $step) : floor($gb / $step)) * $step;
  return max($step, $value);
}

/**
 * Pure estimate. $in: model, cloud, weights_bytes, model_info, parameters
 * (Modelfile text), loaded (this model's /api/ps row or null), loaded_total
 * (bytes of all loaded models), mem_available (bytes or null), choices and
 * chosen (the account's window or null).
 */
function coreui_ai_context_estimate_calc(array $in): array {
  $model = (string)($in['model'] ?? '');
  $choices = array_values(array_map('intval', (array)($in['choices'] ?? [])));
  if (!empty($in['cloud'])) {
    return ['model'=>$model, 'applicable'=>false, 'reason'=>'cloud', 'stops'=>[], 'default'=>null];
  }
  $weights = (int)($in['weights_bytes'] ?? 0);
  $layout = coreui_ai_kv_layout(is_array($in['model_info'] ?? null) ? $in['model_info'] : []);
  $loaded = is_array($in['loaded'] ?? null) ? $in['loaded'] : null;
  $loadedSize = $loaded ? max(0, (int)($loaded['size'] ?? 0)) : 0;
  $loadedVram = $loaded ? max(0, (int)($loaded['size_vram'] ?? 0)) : 0;
  $loadedCtx = $loaded ? max(0, (int)($loaded['context_length'] ?? 0)) : 0;
  $modelfileCtx = null;
  if (preg_match('~(?m)^\s*num_ctx\s+(\d{3,7})\s*$~', (string)($in['parameters'] ?? ''), $m) === 1) {
    $modelfileCtx = (int)$m[1];
  }

  $processor = null;
  if ($loadedSize > 0) {
    $processor = $loadedVram <= 0 ? 'cpu' : ($loadedVram >= $loadedSize ? 'gpu' : 'hybrid');
  }
  $label = ['cpu'=>'RAM', 'gpu'=>'VRAM', 'hybrid'=>'RAM + VRAM'][$processor ?? ''] ?? 'Arbeitsspeicher';

  // Ollama may unload what is loaded now, so that memory counts as usable.
  $memAvailable = isset($in['mem_available']) ? (int)$in['mem_available'] : 0;
  $budget = $memAvailable > 0 ? $memAvailable + max(0, (int)($in['loaded_total'] ?? 0)) : null;

  $overhead = 0.5 * COREUI_GIB;
  $method = null;
  $scale = 1.0;
  $perToken = 0.0;
  if ($weights > 0 && $layout !== null) {
    $method = 'metadata';
    // A loaded runner is a real measurement: it corrects cache type,
    // parallel slots and compute buffers the metadata cannot show.
    if ($loadedSize > $weights && $loadedCtx >= 1024) {
      $predicted = coreui_ai_kv_bytes($layout, $loadedCtx) + $overhead;
      if ($predicted > 0) $scale = max(0.5, min(6.0, ($loadedSize - $weights) / $predicted));
    }
  } elseif ($weights > 0 && $loadedSize > $weights + $overhead && $loadedCtx >= 1024) {
    $method = 'measured';
    $perToken = ($loadedSize - $weights - $overhead) / $loadedCtx;
  }

  $stop = static function (int $ctx) use ($method, $weights, $layout, $overhead, $scale, $perToken, $budget): array {
    $row = ['tokens'=>$ctx, 'low_gb'=>null, 'high_gb'=>null, 'warn'=>false];
    if ($method === 'metadata') {
      $mid = $weights + (coreui_ai_kv_bytes($layout, $ctx) + $overhead) * $scale;
      $low = $mid * 0.9;
      $high = $mid * 1.15;
    } elseif ($method === 'measured') {
      $mid = $weights + $overhead + $perToken * $ctx;
      $low = $mid * 0.85;
      $high = $mid * 1.25;
    } else {
      return $row;
    }
    $row['low_gb'] = coreui_ai_gb($low, false);
    $row['high_gb'] = max($row['low_gb'], coreui_ai_gb($high, true));
    // Only a clear excess warns; an uncertain estimate never blocks.
    $row['warn'] = $budget !== null && $low > $budget * 1.1;
    if ($layout !== null && !empty($layout['trained_context']) && $ctx > (int)$layout['trained_context']) {
      $row['beyond_model'] = true;
    }
    return $row;
  };

  // Core-Standard: the Modelfile value; without one, what Ollama loaded
  // while no profile window was sent.
  $chosen = isset($in['chosen']) ? (int)$in['chosen'] : null;
  $defaultCtx = $modelfileCtx ?? (($chosen === null && $loadedCtx > 0) ? $loadedCtx : null);
  return [
    'model'=>$model,
    'applicable'=>true,
    'reason'=>null,
    'method'=>$method,
    'memory_label'=>$label,
    'processor'=>$processor,
    'loaded_context'=>$loadedCtx > 0 ? $loadedCtx : null,
    'default_context'=>$defaultCtx,
    'trained_context'=>$layout['trained_context'] ?? null,
    'available_gb'=>$budget !== null ? coreui_ai_gb((float)$budget, false) : null,
    'stops'=>array_map($stop, $choices),
    'default'=>$defaultCtx !== null ? $stop($defaultCtx) : null,
  ];
}

/** Live estimate for one installed model; never throws. */
function coreui_ai_context_estimate(string $model, array $choices, ?int $chosen = null): array {
  $cloud = preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $model) === 1;
  if ($cloud) return coreui_ai_context_estimate_calc(['model'=>$model, 'cloud'=>true, 'choices'=>$choices]);
  $weights = 0;
  try {
    foreach (coreui_ollama_models() as $row) {
      if (hash_equals((string)$row['name'], $model)) $weights = (int)$row['size'];
    }
  } catch (Throwable $e) {
    $weights = 0;
  }
  $show = coreui_ollama_read_json('show', ['model'=>$model]) ?? [];
  $ps = coreui_ollama_read_json('ps') ?? [];
  $wanted = str_contains($model, ':') ? strtolower($model) : strtolower($model) . ':latest';
  $loaded = null;
  $loadedTotal = 0;
  foreach ((is_array($ps['models'] ?? null) ? $ps['models'] : []) as $row) {
    if (!is_array($row)) continue;
    $loadedTotal += max(0, (int)($row['size'] ?? 0));
    $name = strtolower(trim((string)($row['name'] ?? $row['model'] ?? '')));
    if ($name !== '' && !str_contains($name, ':')) $name .= ':latest';
    if ($name === $wanted) $loaded = $row;
  }
  return coreui_ai_context_estimate_calc([
    'model'=>$model,
    'weights_bytes'=>$weights,
    'model_info'=>is_array($show['model_info'] ?? null) ? $show['model_info'] : [],
    'parameters'=>is_string($show['parameters'] ?? null) ? $show['parameters'] : '',
    'loaded'=>$loaded,
    'loaded_total'=>$loadedTotal,
    'mem_available'=>coreui_mem_available_bytes(),
    'choices'=>$choices,
    'chosen'=>$chosen,
  ]);
}
