<?php
declare(strict_types=1);

// Trusted, repository-owned instructions only. No remote imports or executable skills.
function coreui_skills_catalog(): array {
  static $catalog = null;
  if (is_array($catalog)) return $catalog;
  $catalog = [];
  $rows = json_decode((string)file_get_contents(__DIR__ . '/../skills/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
  foreach ($rows as $row) {
    $id = $row['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || strlen($id) > 64) {
      throw new RuntimeException('invalid_skill_catalog');
    }
    if (isset($catalog[$id])) throw new RuntimeException('duplicate_skill');
    $catalog[$id] = $row;
  }
  return $catalog;
}

function coreui_skills_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id,skill_id,enabled FROM stu_user_skills LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_skills_load(PDO $pdo, int $uid): array {
  // Opt-in, including when migration is absent. Never silently enable new skills.
  $values = array_fill_keys(array_keys(coreui_skills_catalog()), false);
  if ($uid <= 0 || !coreui_skills_schema_ready($pdo)) return $values;
  $stmt = $pdo->prepare('SELECT skill_id,enabled FROM stu_user_skills WHERE user_id=?');
  $stmt->execute([$uid]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (array_key_exists($row['skill_id'], $values)) $values[$row['skill_id']] = (int)$row['enabled'] === 1;
  }
  return $values;
}

function coreui_skills_validate(array $values): array {
  $catalog = coreui_skills_catalog();
  foreach ($values as $id => $enabled) {
    if (!isset($catalog[$id]) || !is_bool($enabled)) throw new InvalidArgumentException('invalid_skill_preferences');
  }
  return $values;
}

function coreui_skills_save(PDO $pdo, int $uid, array $values): array {
  $values = coreui_skills_validate($values);
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_skills_schema_ready($pdo)) throw new RuntimeException('skills_migration_required');
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO stu_user_skills (user_id,skill_id,enabled) VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)');
    foreach ($values as $id => $enabled) $stmt->execute([$uid,$id,$enabled ? 1 : 0]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return coreui_skills_load($pdo, $uid);
}

function coreui_skills_select(string $request, array $enabled): array {
  $request = function_exists('mb_strtolower') ? mb_strtolower($request, 'UTF-8') : strtolower($request);
  $scores = [];
  foreach (coreui_skills_catalog() as $id => $skill) {
    if (($enabled[$id] ?? false) !== true) continue;
    $score = 0;
    if (preg_match('~(?:/skill\\s+|\\$)' . preg_quote($id, '~') . '(?![a-z0-9-])~', $request)) $score = 10000;
    foreach ($skill['triggers'] as $term) {
      if (preg_match('~(?<![\\pL\\pN])' . preg_quote($term, '~') . '(?![\\pL\\pN])~iu', $request)) $score += 10;
    }
    if ($score > 0) $scores[$id] = $score;
  }
  arsort($scores, SORT_NUMERIC); // Stable catalog order breaks ties.
  return array_slice(array_keys($scores), 0, 4);
}

function coreui_skills_body(string $id): string {
  if (!isset(coreui_skills_catalog()[$id])) return '';
  $raw = (string)file_get_contents(__DIR__ . '/../skills/' . $id . '/SKILL.md');
  if (!preg_match('/\\A---\n.*?\n---\n(.*)\\z/s', $raw, $match)) throw new RuntimeException('invalid_skill_document');
  // Reject oversized packages instead of cutting an instruction in half.
  if (strlen($match[1]) > 2400) throw new RuntimeException('skill_too_large');
  return trim($match[1]);
}

function coreui_skills_prepare(PDO $pdo, int $uid, string $request): void {
  $GLOBALS['COREUI_SKILLS_BLOCK'] = '';
  $selected = coreui_skills_select($request, coreui_skills_load($pdo, $uid));
  if (!$selected) return;
  $block = "\n\n[COREUI SKILLS: aufgabenbezogene Arbeitsanleitungen. Nutzerauftrag und vorhandenes Werkzeugprotokoll gelten weiterhin. Keine neuen Werkzeuge oder Berechtigungen. Tests und Dateien nur als erstellt bestaetigen, wenn tatsaechlich bestaetigt.]\n";
  foreach ($selected as $id) {
    $block .= coreui_skills_body($id) . "\n\n";
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo, 'tool', 'info', 'Skill geladen: ' . $id, ['source'=>$id]);
    }
  }
  $GLOBALS['COREUI_SKILLS_BLOCK'] = $block . "[ENDE COREUI SKILLS]\n\n";
}

function coreui_skills_augment(string $prompt): string {
  // Add to user context, never replace the Ollama Modelfile SYSTEM or its options.
  return (string)($GLOBALS['COREUI_SKILLS_BLOCK'] ?? '') . $prompt;
}
