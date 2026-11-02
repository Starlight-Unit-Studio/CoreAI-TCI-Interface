<?php
declare(strict_types=1);

/**
 * Per-account Mara presentation settings.  No settings are global, no
 * ModelFile is modified and the client never supplies executable code.
 */
function coreui_personalization_defaults(): array {
  return [
    'tone' => 'standard',
    'warmth' => 'mehr',
    'enthusiasm' => 'standard',
    'emoji' => 'mehr',
    'visual_answers' => 'mehr',
    'headings_lists' => 'mehr',
    'adapt_style' => false,
    'quick_answers' => false,
    'suggested_prompts' => true,
    'web_search' => true,
    'browse_search' => true,
    'knowledge_search' => true,
    'custom_instructions' => ''
  ];
}

function coreui_personalization_normalise(array $input): array {
  $values = coreui_personalization_defaults();
  $choices = [
    'tone' => ['standard','freundlich','professionell','locker','kreativ','praezise'],
    'warmth' => ['weniger','standard','mehr'],
    'enthusiasm' => ['weniger','standard','mehr'],
    'emoji' => ['keine','weniger','standard','mehr'],
    'visual_answers' => ['weniger','standard','mehr'],
    'headings_lists' => ['weniger','standard','mehr']
  ];
  foreach ($choices as $key => $allowed) {
    $candidate = $input[$key] ?? $values[$key];
    if (is_string($candidate) && in_array($candidate,$allowed,true)) $values[$key] = $candidate;
  }
  foreach (['adapt_style','quick_answers','suggested_prompts','web_search','browse_search','knowledge_search'] as $key) {
    if (array_key_exists($key,$input) && is_bool($input[$key])) $values[$key] = $input[$key];
  }
  $instructions = (string)($input['custom_instructions'] ?? '');
  $instructions = str_replace(["\r\n","\r"],"\n",$instructions);
  $instructions = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$instructions) ?? '';
  $values['custom_instructions'] = function_exists('mb_substr')
    ? mb_substr(trim($instructions),0,2400,'UTF-8')
    : substr(trim($instructions),0,2400);
  return $values;
}

function coreui_personalization_schema_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo->query('SELECT user_id,preferences_json FROM stu_user_personalization LIMIT 0');
    return $ready = true;
  } catch (Throwable $error) {
    return $ready = false;
  }
}

function coreui_personalization_load(PDO $pdo,int $uid): array {
  $defaults = coreui_personalization_defaults();
  if ($uid <= 0 || !coreui_personalization_schema_ready($pdo)) return $defaults;
  try {
    $st = $pdo->prepare('SELECT preferences_json FROM stu_user_personalization WHERE user_id=? LIMIT 1');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    $decoded = is_string($raw) ? json_decode($raw,true) : null;
    return coreui_personalization_normalise(is_array($decoded) ? $decoded : $defaults);
  } catch (Throwable $error) {
    return $defaults;
  }
}

function coreui_personalization_save(PDO $pdo,int $uid,array $values): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_personalization_schema_ready($pdo)) throw new RuntimeException('missing_schema_022');
  $clean = coreui_personalization_normalise($values);
  $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  $st = $pdo->prepare(
    'INSERT INTO stu_user_personalization (user_id,preferences_json,updated_at) VALUES (?,?,NOW()) '
    . 'ON DUPLICATE KEY UPDATE preferences_json=VALUES(preferences_json),updated_at=NOW()'
  );
  $st->execute([$uid,$json]);
  return $clean;
}

/** A server-authored preference can opt out of a tool, never grant a forbidden tool. */
function coreui_personalization_tool_allowed(string $tool): bool {
  $prefs = $GLOBALS['COREUI_PERSONALIZATION_CONTEXT'] ?? null;
  if (!is_array($prefs)) return true;
  $key = $tool === 'web' ? 'web_search' : ($tool === 'browse' ? 'browse_search' : null);
  return $key === null || ($prefs[$key] ?? true) === true;
}
