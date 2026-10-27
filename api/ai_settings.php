<?php
declare(strict_types=1);

/*
 * Per-user AI configuration for the isolated CoreAI: TCI Interface database.
 *
 * This file contains no HTTP dispatch. It is shared by user_settings.php,
 * chat.php and the administration API. All values are normalised again at
 * runtime so an old or manually modified database row cannot bypass limits.
 */

function coreui_ai_settings_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, thinking_enabled FROM stu_user_ai_settings LIMIT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** 0.5.70 (#113): column num_ctx exists (migration 017). */
function coreui_ai_num_ctx_schema_ready(PDO $pdo): bool {
  static $ready = [];
  $key = spl_object_id($pdo);
  if (array_key_exists($key, $ready)) return $ready[$key];
  try {
    $pdo->query('SELECT num_ctx FROM stu_user_ai_settings LIMIT 0');
    return $ready[$key] = true;
  } catch (Throwable $e) {
    return $ready[$key] = false;
  }
}

/**
 * Context windows the KI-Profil slider offers (4K to 128K). An operator may
 * lower the top with STU_EMBER_NUM_CTX_MAX; nothing outside this list is
 * ever sent to Ollama.
 */
function coreui_ai_num_ctx_choices(): array {
  $all = [4096, 8192, 16384, 32768, 65536, 131072];
  $max = defined('STU_EMBER_NUM_CTX_MAX') ? (int)STU_EMBER_NUM_CTX_MAX : 131072;
  $allowed = array_values(array_filter($all, static fn(int $n): bool => $n <= $max));
  return $allowed !== [] ? $allowed : [4096];
}

/** A whitelisted window, or null: no override, the Core keeps its default. */
function coreui_ai_num_ctx_normalise($value): ?int {
  if ($value === null || $value === '' || $value === false || is_array($value)) return null;
  if (is_string($value) && preg_match('~^\s*\d{1,7}\s*$~', $value) !== 1) return null;
  if (is_float($value) && floor($value) !== $value) return null;
  if (!is_int($value) && !is_string($value) && !is_float($value)) return null;
  $n = (int)$value;
  return in_array($n, coreui_ai_num_ctx_choices(), true) ? $n : null;
}

function coreui_ai_prompt_limit(PDO $pdo): int {
  $raw = function_exists('stu_app_setting_get')
    ? (int)stu_app_setting_get($pdo, 'user_system_prompt_max_chars', '6000')
    : 6000;
  return max(500, min(12000, $raw));
}

function coreui_ai_settings_defaults(PDO $pdo): array {
  $memoryDefault = function_exists('stu_app_setting_get')
    ? stu_app_setting_get($pdo, 'memory_default_enabled', '1') !== '0'
    : true;
  $memoryLimit = defined('STU_EMBER_MEMORY_LIMIT') ? (int)STU_EMBER_MEMORY_LIMIT : 16;
  $numPredict = defined('STU_EMBER_NUM_PREDICT') ? (int)STU_EMBER_NUM_PREDICT : 6500;
  if ($numPredict < 0) $numPredict = 8192;
  $temperature = defined('STU_EMBER_TEMPERATURE') ? (float)STU_EMBER_TEMPERATURE : 1.0;

  return [
    'system_prompt' => '',
    'memory_enabled' => $memoryDefault,
    'thinking_enabled' => true,
    'memory_limit' => max(0, min(60, $memoryLimit)),
    'num_predict' => max(256, min(16384, $numPredict)),
    // NULL: keep the Core's own Modelfile window (default for every account).
    'num_ctx' => null,
    'temperature' => max(0.1, min(1.5, $temperature)),
    'provider' => 'local',
    'model_override' => '',
  ];
}

function coreui_ai_external_capability(PDO $pdo): array {
  $enabled = function_exists('stu_app_setting_get')
    && stu_app_setting_get($pdo, 'external_api_enabled', '0') === '1';
  $label = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_label', 'OpenAI-kompatibel'))
    : 'OpenAI-kompatibel';
  $baseUrl = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_base_url', ''))
    : '';
  $model = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_model', ''))
    : '';
  $keyConfigured = defined('STU_EXTERNAL_API_KEY')
    && trim((string)constant('STU_EXTERNAL_API_KEY')) !== '';

  return [
    // 0.3.0-alpha stores the operator configuration but deliberately keeps the
    // selector locked until the provider adapter is released and regression-tested.
    'configured' => false,
    'configuration_complete' => $enabled && $baseUrl !== '' && $model !== '' && $keyConfigured,
    'enabled' => $enabled,
    'label' => $label !== '' ? $label : 'OpenAI-kompatibel',
    'base_url' => $baseUrl,
    'model' => $model,
    'key_configured' => $keyConfigured,
    'status' => 'adapter_reserved',
  ];
}

function coreui_ai_settings_normalise(PDO $pdo, array $input): array {
  $defaults = coreui_ai_settings_defaults($pdo);
  $prompt = trim((string)($input['system_prompt'] ?? $defaults['system_prompt']));
  $promptLimit = coreui_ai_prompt_limit($pdo);
  if (function_exists('mb_substr')) {
    $prompt = mb_substr($prompt, 0, $promptLimit, 'UTF-8');
  } else {
    $prompt = substr($prompt, 0, $promptLimit);
  }
  // Keep control bytes out of the model instruction block while retaining
  // line breaks, which are useful for structured prompts.
  $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);

  $provider = strtolower(trim((string)($input['provider'] ?? $defaults['provider'])));
  if (!in_array($provider, ['local', 'external'], true)) $provider = 'local';
  if ($provider === 'external' && empty(coreui_ai_external_capability($pdo)['configured'])) {
    $provider = 'local';
  }

  $model = trim((string)($input['model_override'] ?? $defaults['model_override']));
  $model = preg_replace('~[^A-Za-z0-9._:/-]+~', '', $model);
  if (strlen((string)$model) > 160) $model = substr((string)$model, 0, 160);

  return [
    'system_prompt' => trim((string)$prompt),
    'memory_enabled' => filter_var(
      $input['memory_enabled'] ?? $defaults['memory_enabled'],
      FILTER_VALIDATE_BOOLEAN,
      FILTER_NULL_ON_FAILURE
    ) ?? (bool)$defaults['memory_enabled'],
    'thinking_enabled' => filter_var(
      $input['thinking_enabled'] ?? $defaults['thinking_enabled'],
      FILTER_VALIDATE_BOOLEAN,
      FILTER_NULL_ON_FAILURE
    ) ?? (bool)$defaults['thinking_enabled'],
    'memory_limit' => max(0, min(60, (int)($input['memory_limit'] ?? $defaults['memory_limit']))),
    'num_predict' => max(256, min(16384, (int)($input['num_predict'] ?? $defaults['num_predict']))),
    'num_ctx' => coreui_ai_num_ctx_normalise($input['num_ctx'] ?? null),
    'temperature' => max(0.1, min(1.5, (float)($input['temperature'] ?? $defaults['temperature']))),
    'provider' => $provider,
    'model_override' => (string)$model,
  ];
}

function coreui_ai_settings_load(PDO $pdo, int $uid): array {
  $defaults = coreui_ai_settings_defaults($pdo);
  if ($uid <= 0 || !coreui_ai_settings_schema_ready($pdo)) return $defaults;

  try {
    $ctxColumn = coreui_ai_num_ctx_schema_ready($pdo) ? ', num_ctx' : '';
    $st = $pdo->prepare(
      'SELECT system_prompt, memory_enabled, thinking_enabled, memory_limit, num_predict, temperature, provider, model_override'
      . $ctxColumn . ' FROM stu_user_ai_settings WHERE user_id = ? LIMIT 1'
    );
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return $defaults;
    return coreui_ai_settings_normalise($pdo, array_merge($defaults, $row));
  } catch (Throwable $e) {
    return $defaults;
  }
}

function coreui_ai_settings_save(PDO $pdo, int $uid, array $input): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_ai_settings_schema_ready($pdo)) {
    throw new RuntimeException('missing_schema_005');
  }

  $value = coreui_ai_settings_normalise($pdo, $input);
  $withCtx = coreui_ai_num_ctx_schema_ready($pdo);
  // A chosen window must never be dropped silently: without migration 017
  // only "Core-Standard" (NULL) can be stored.
  if (!$withCtx && $value['num_ctx'] !== null) throw new RuntimeException('missing_schema_017');
  $st = $pdo->prepare(
    'INSERT INTO stu_user_ai_settings '
    . '(user_id, system_prompt, memory_enabled, thinking_enabled, memory_limit, num_predict, temperature, provider, model_override'
    . ($withCtx ? ', num_ctx' : '') . ', updated_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($withCtx ? ', ?' : '') . ', NOW()) '
    . 'ON DUPLICATE KEY UPDATE system_prompt = VALUES(system_prompt), memory_enabled = VALUES(memory_enabled), '
    . 'thinking_enabled = VALUES(thinking_enabled), memory_limit = VALUES(memory_limit), '
    . 'num_predict = VALUES(num_predict), temperature = VALUES(temperature), '
    . 'provider = VALUES(provider), model_override = VALUES(model_override), '
    . ($withCtx ? 'num_ctx = VALUES(num_ctx), ' : '') . 'updated_at = NOW()'
  );
  $params = [
    $uid,
    $value['system_prompt'],
    $value['memory_enabled'] ? 1 : 0,
    $value['thinking_enabled'] ? 1 : 0,
    $value['memory_limit'],
    $value['num_predict'],
    $value['temperature'],
    $value['provider'],
    $value['model_override'] !== '' ? $value['model_override'] : null,
  ];
  if ($withCtx) $params[] = $value['num_ctx'];
  $st->execute($params);
  return $value;
}

function coreui_operator_runtime_load(PDO $pdo): array {
  $defaults = [
    'web' => defined('STU_EMBER_WEB_ENABLED') ? (bool)STU_EMBER_WEB_ENABLED : true,
    'browse' => defined('STU_EMBER_BROWSE_ENABLED') ? (bool)STU_EMBER_BROWSE_ENABLED : true,
    'python' => defined('STU_EMBER_PY_ENABLED') ? (bool)STU_EMBER_PY_ENABLED : true,
  ];
  if (!function_exists('stu_app_setting_get')) return $defaults;
  return [
    'web' => stu_app_setting_get($pdo, 'tool_web_enabled', $defaults['web'] ? '1' : '0') === '1',
    'browse' => stu_app_setting_get($pdo, 'tool_browse_enabled', $defaults['browse'] ? '1' : '0') === '1',
    'python' => stu_app_setting_get($pdo, 'tool_python_enabled', $defaults['python'] ? '1' : '0') === '1',
  ];
}

function coreui_ai_runtime_apply(PDO $pdo, int $uid): array {
  unset($GLOBALS['COREUI_SKILLS_BLOCK']);
  $settings = coreui_ai_settings_load($pdo, $uid);
  // #113: the context window is fixed once per request, which is one run. A
  // value saved while an agent job works applies from the next job on, so a
  // running job never reloads the Core half way through.
  $fixed = $GLOBALS['COREUI_AI_NUM_CTX_FIXED'] ?? null;
  if (is_array($fixed) && (int)($fixed['uid'] ?? 0) === $uid) {
    $settings['num_ctx'] = $fixed['value'];
  } else {
    $GLOBALS['COREUI_AI_NUM_CTX_FIXED'] = ['uid'=>$uid, 'value'=>$settings['num_ctx']];
  }
  $GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = $settings;
  $GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] = coreui_operator_runtime_load($pdo);
  return $settings;
}

function coreui_ai_runtime_settings(): array {
  $value = $GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] ?? [];
  return is_array($value) ? $value : [];
}

/** The window the account chose for this run, null for the Core default. */
function coreui_ai_num_ctx_choice(): ?int {
  return coreui_ai_num_ctx_normalise(coreui_ai_runtime_settings()['num_ctx'] ?? null);
}

function coreui_runtime_tool_enabled(string $tool, bool $fallback): bool {
  $settings = $GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] ?? [];
  if (!is_array($settings) || !array_key_exists($tool, $settings)) return $fallback;
  return (bool)$settings[$tool];
}

function coreui_ai_user_prompt_block(): string {
  $settings = coreui_ai_runtime_settings();
  $prompt = trim((string)($settings['system_prompt'] ?? ''));
  if ($prompt === '') return '';
  return "\n\n--- BENUTZERKONFIGURATION (Stil und Arbeitsweise) ---\n"
    . $prompt
    . "\n--- ENDE BENUTZERKONFIGURATION ---";
}
