<?php
declare(strict_types=1);

/**
 * CoreAI 0.6.71 General controls.
 * This is a PHP library, never an independent HTTP endpoint.
 * Account authorization remains in user_settings.php and model_access.php.
 */
require_once __DIR__ . '/model_access.php';
require_once __DIR__ . '/ai_context_estimate.php';

function coreui_general_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id FROM stu_user_general_settings LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_general_defaults(): array {
  return ['auto_higher_core' => false, 'thinking_level' => ''];
}

function coreui_general_load(PDO $pdo, int $uid): array {
  $defaults = coreui_general_defaults();
  if ($uid <= 0 || !coreui_general_schema_ready($pdo)) return $defaults;
  try {
    $st = $pdo->prepare('SELECT auto_higher_core, thinking_level FROM stu_user_general_settings WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return $defaults;
    $level = is_string($row['thinking_level'] ?? null) ? trim($row['thinking_level']) : '';
    return [
      'auto_higher_core' => (int)($row['auto_higher_core'] ?? 0) === 1,
      'thinking_level' => in_array($level, ['low', 'medium', 'high', 'max'], true) ? $level : '',
    ];
  } catch (Throwable $e) {
    return $defaults;
  }
}

/** Only the values actually advertised for this exact installed Core. */
function coreui_general_thinking_capabilities(string $model, ?array $show = null): array {
  if (!coreui_model_name_valid($model)) {
    return ['levels' => [], 'supports_on_off' => false, 'metadata_available' => false];
  }
  if ($show === null) $show = coreui_ollama_read_json('show', ['model' => $model]) ?? [];
  $thinking = $show['thinking'] ?? null;
  $values = is_array($thinking) && is_array($thinking['values'] ?? null) ? $thinking['values'] : [];
  $names = [];
  foreach ($values as $value) {
    if (is_string($value) && in_array($value, ['low', 'medium', 'high', 'max'], true)
      && !in_array($value, $names, true)) $names[] = $value;
  }
  return [
    'levels' => $names,
    'supports_on_off' => in_array(true, $values, true) && in_array(false, $values, true),
    'metadata_available' => is_array($thinking) && is_array($thinking['values'] ?? null),
  ];
}

function coreui_general_effective_model(PDO $pdo, int $uid): string {
  $settings = coreui_ai_settings_load($pdo, $uid);
  $model = trim((string)($settings['model_override'] ?? ''));
  if ($model === '' || !coreui_model_access_allowed($pdo, $uid, $model)) {
    $model = defined('STU_EMBER_MODEL') ? trim((string)STU_EMBER_MODEL) : '';
  }
  return $model !== '' ? $model : 'ember-coreui:latest';
}

function coreui_general_save(PDO $pdo, int $uid, array $input, bool $isGuest = false): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_general_schema_ready($pdo)) throw new RuntimeException('missing_schema_027');
  foreach (array_keys($input) as $field) {
    if (!in_array($field, ['auto_higher_core', 'thinking_level'], true)) {
      throw new InvalidArgumentException('invalid_general_field');
    }
  }
  $auto = filter_var($input['auto_higher_core'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($auto === null) throw new InvalidArgumentException('invalid_auto_core');
  if ($isGuest && $auto) throw new RuntimeException('guest_core_locked');
  $level = $input['thinking_level'] ?? '';
  if (!is_string($level) || !in_array($level, ['', 'low', 'medium', 'high', 'max'], true)) {
    throw new InvalidArgumentException('invalid_thinking_level');
  }
  if ($level !== '') {
    $model = coreui_general_effective_model($pdo, $uid);
    $caps = coreui_general_thinking_capabilities($model);
    if (!in_array($level, $caps['levels'], true)) {
      throw new InvalidArgumentException('thinking_level_not_supported');
    }
  }
  $st = $pdo->prepare('INSERT INTO stu_user_general_settings (user_id, auto_higher_core, thinking_level) VALUES (?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE auto_higher_core = VALUES(auto_higher_core), '
    . 'thinking_level = VALUES(thinking_level), updated_at = CURRENT_TIMESTAMP');
  $st->execute([$uid, $auto ? 1 : 0, $level]);
  return ['auto_higher_core' => $auto, 'thinking_level' => $level];
}

/**
 * Optional model-defined reasoning effort, never a made-up numeric budget.
 * An unsupported or unavailable capability falls back to the existing bool.
 * The explicitly disabled thinking switch always has priority.
 */
function coreui_general_think_value(string $model, bool $enabled): bool|string {
  if (!$enabled) return false;
  $general = $GLOBALS['COREUI_GENERAL_SETTINGS'] ?? [];
  $level = is_array($general) ? (string)($general['thinking_level'] ?? '') : '';
  if (!in_array($level, ['low', 'medium', 'high', 'max'], true)) return true;
  $caps = coreui_general_thinking_capabilities($model);
  return in_array($level, $caps['levels'], true) ? $level : true;
}

/** Conservative routing. Only explicit complex textual assignments qualify. */
function coreui_general_complex_text(string $message): bool {
  $text = trim($message);
  if ($text === '') return false;
  $hasCode = preg_match('~```|(?:^|\n)\s*(?:function |class |CREATE TABLE |SELECT .+ FROM )~i', $text) === 1;
  $requestsWork = preg_match('~\b(?:analysier\w*|analys[ei]s|refactor\w*|debug\w*|implement\w*|architektur|architecture|komplex\w*|mehrstufig\w*|research|recherchier\w*|beweis\w*|mathematisch\w*)\b~iu', $text) === 1;
  return ($hasCode && $requestsWork) || ($requestsWork && mb_strlen($text, 'UTF-8') >= 240);
}

/**
 * Route ONE private, text-only turn to the next available installed Mara tier.
 * Nothing is saved to the account's model_override. No Cloud, guest, non-Mara
 * or vision/audio turn is switched. All calls still use the global FIFO queue.
 */
function coreui_general_select_auto_core(PDO $pdo, int $uid, string $message, bool $hasMedia): ?string {
  unset($GLOBALS['COREUI_AUTO_SELECTED_CORE']);
  if ($uid <= 0 || $hasMedia || !coreui_general_complex_text($message)) return null;
  $general = coreui_general_load($pdo, $uid);
  if (!$general['auto_higher_core'] || coreui_ai_account_is_guest($pdo, $uid)) return null;
  $current = coreui_general_effective_model($pdo, $uid);
  if (!coreui_core_is_mara($current)) return null;
  $rank = ['schnell'=>0, 'normal'=>1, 'plus'=>2, 'pro'=>3, 'premium'=>4];
  $curTier = coreui_core_tier_of($current);
  if (!is_string($curTier) || !isset($rank[$curTier]) || $rank[$curTier] >= 4) return null;
  try {
    $models = coreui_ollama_models();
  } catch (Throwable $e) {
    return null; // The current Core remains valid if Ollama catalog is down.
  }
  $candidate = null;
  $candidateRank = 100;
  foreach ($models as $row) {
    $name = (string)($row['name'] ?? '');
    // Generated Mara Modelfiles preserve her personality. Never silently
    // switch to raw Gemma, another model family or a Cloud provider.
    if (!str_starts_with(strtolower($name), 'ember-core:') || stripos($name, 'cloud') !== false) continue;
    $tier = coreui_core_tier_of($name);
    if (!is_string($tier) || !isset($rank[$tier]) || $rank[$tier] <= $rank[$curTier]) continue;
    if ($rank[$tier] >= $candidateRank || !coreui_model_access_allowed($pdo, $uid, $name)) continue;
    $candidate = $name;
    $candidateRank = $rank[$tier];
  }
  if ($candidate === null) return null;
  $GLOBALS['COREUI_AUTO_SELECTED_CORE'] = $candidate;
  return $candidate;
}
