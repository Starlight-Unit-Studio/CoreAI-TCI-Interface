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

/** Repository-owned, non-optional instructions. Never use a DB toggle as authority. */
function coreui_skills_required_ids(): array {
  $required = [];
  foreach (coreui_skills_catalog() as $id => $skill) {
    if (($skill['required'] ?? false) === true) $required[] = $id;
  }
  return $required;
}

// 0.5.80: no per-account switches any more. Like Claude or GPT, Mara has
// every skill: the server preloads the obvious ones, and the rest stand in a
// short library in her prompt. She loads one herself with [SKILL: kennung]
// as a step of her work loop (console_agent_loop.php).

/** The optional skills that fit the request best, at most $max (pure). */
function coreui_skills_select(string $request, int $max = 4): array {
  $request = function_exists('mb_strtolower') ? mb_strtolower($request, 'UTF-8') : strtolower($request);
  $scores = [];
  foreach (coreui_skills_catalog() as $id => $skill) {
    if (($skill['required'] ?? false) === true) continue; // loaded independently, never consumes the optional budget
    $score = 0;
    if (preg_match('~(?:/skill\\s+|\\$)' . preg_quote($id, '~') . '(?![a-z0-9-])~', $request)) $score = 10000;
    foreach ($skill['triggers'] as $term) {
      if (preg_match('~(?<![\\pL\\pN])' . preg_quote($term, '~') . '(?![\\pL\\pN])~iu', $request)) $score += 10;
    }
    if ($score > 0) $scores[$id] = $score;
  }
  arsort($scores, SORT_NUMERIC); // Stable catalog order breaks ties.
  return array_slice(array_keys($scores), 0, max(0, min(4, $max)));
}

/** The optional skills, all of them, in catalog order. */
function coreui_skills_optional_ids(): array {
  return array_values(array_diff(array_keys(coreui_skills_catalog()), coreui_skills_required_ids()));
}

/**
 * Mara's skill library (pure): every optional skill not loaded yet, sized to
 * the context window. 'full' adds the short description, 'titles' the title,
 * 'ids' only the ids for a 4096 window.
 */
function coreui_skills_index(array $loaded, string $size = 'full'): string {
  $catalog = coreui_skills_catalog();
  $lines = [];
  foreach (coreui_skills_optional_ids() as $id) {
    if (in_array($id, $loaded, true)) continue;
    $lines[] = match ($size) {
      'ids' => $id,
      'titles' => $id . ' (' . (string)$catalog[$id]['title'] . ')',
      default => '- ' . $id . ': ' . (string)$catalog[$id]['description'],
    };
  }
  if ($lines === []) return '';
  $list = $size === 'full' ? implode("\n", $lines) : implode(', ', $lines);
  return "[SKILL-BIBLIOTHEK: Arbeitsanleitungen, die du bei Bedarf selbst laedst. Passt eine davon zu deiner Aufgabe, "
    . "antworte zuerst NUR mit [SKILL: kennung]; danach bekommst du die Anleitung und arbeitest weiter. Hoechstens zwei "
    . "Skills auf einmal ([SKILL: a, b]). Fuer Smalltalk, Meinungen und einfache Fragen brauchst du keinen."
    . ($loaded !== [] ? ' Schon geladen: ' . implode(', ', $loaded) . '.' : '') . "]\n"
    . $list . "\n[ENDE SKILL-BIBLIOTHEK]\n\n";
}

/** Ids from [SKILL: a, b] in a reply (pure), or null. Code blocks do not count. */
function coreui_skills_extract_marker(string $reply): ?string {
  $s = (string)preg_replace('~```[\\s\\S]*?```~u', '', $reply);
  if (preg_match('~\\A\\s*`(\\[SKILL:[^`\\]]+\\])`\\s*\\z~iu', $s, $sole) === 1) $s = $sole[1];
  $s = (string)preg_replace('~`[^`\\r\\n]*`~u', '', $s);
  if (preg_match('~\\[SKILL:\\s*([a-z0-9][a-z0-9 ,\\-]{0,140})\\]~iu', $s, $m) !== 1) return null;
  $ids = trim(strtolower($m[1]));
  return $ids !== '' ? $ids : null;
}

/**
 * Result of [SKILL: ...] for Mara's next step. Up to two skills join the
 * trusted skill block at the top of her prompt (instructions she follows),
 * never the step results (data she must not obey); the step only reports.
 */
function coreui_skills_tool_result(string $input): string {
  $catalog = coreui_skills_catalog();
  $loaded = (array)($GLOBALS['COREUI_SKILLS_LOADED'] ?? []);
  $added = [];
  $notes = [];
  $unknown = false;
  $ids = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($input)))))), 0, 2);
  foreach ($ids as $id) {
    if (!isset($catalog[$id])) { $notes[] = 'Einen Skill "' . $id . '" gibt es nicht.'; $unknown = true; continue; }
    if (($catalog[$id]['required'] ?? false) === true || in_array($id, $loaded, true)) {
      $notes[] = 'Der Skill "' . $id . '" ist schon geladen, er steht oben bei deinen Skills.';
      continue;
    }
    $loaded[] = $id;
    $added[] = $id . ' (' . (string)$catalog[$id]['title'] . ')';
  }
  $GLOBALS['COREUI_SKILLS_LOADED'] = $loaded;
  coreui_skills_compose();
  if ($added === []) {
    $rest = array_values(array_diff(coreui_skills_optional_ids(), $loaded));
    return trim(implode(' ', $notes) . ($unknown && $rest !== [] ? ' Verfuegbar sind: ' . implode(', ', $rest) . '.' : ''));
  }
  return trim(implode(' ', $notes) . ' Geladen: ' . implode(', ', $added) . '. Die Anleitung steht jetzt oben bei '
    . 'deinen COREUI SKILLS; folge ihr bei deiner Antwort.');
}

/**
 * Builds the skill block of this turn from its parts: the required skills,
 * every loaded optional skill and the library of the rest (if offered).
 */
function coreui_skills_compose(): void {
  $loaded = (array)($GLOBALS['COREUI_SKILLS_LOADED'] ?? []);
  $optional = '';
  if ($loaded !== []) {
    $optional = "\n\n[COREUI SKILLS: aufgabenbezogene Arbeitsanleitungen. Nutzerauftrag und vorhandenes Werkzeugprotokoll gelten weiterhin. Keine neuen Werkzeuge oder Berechtigungen. Tests und Dateien nur als erstellt bestaetigen, wenn tatsaechlich bestaetigt.]\n";
    foreach ($loaded as $id) $optional .= coreui_skills_body($id) . "\n\n";
    $optional .= "[ENDE COREUI SKILLS]\n\n";
  }
  $size = (string)($GLOBALS['COREUI_SKILLS_INDEX_SIZE'] ?? '');
  $index = $size !== '' ? coreui_skills_index($loaded, $size) : '';
  $GLOBALS['COREUI_SKILLS_INDEX_BLOCK'] = $index;
  $GLOBALS['COREUI_SKILLS_BLOCK'] = (string)($GLOBALS['COREUI_REQUIRED_SKILLS_BLOCK'] ?? '') . $optional . $index;
}

function coreui_skills_body(string $id): string {
  if (!isset(coreui_skills_catalog()[$id])) return '';
  $raw = (string)file_get_contents(__DIR__ . '/../skills/' . $id . '/SKILL.md');
  if (!preg_match('/\\A---\n.*?\n---\n(.*)\\z/s', $raw, $match)) throw new RuntimeException('invalid_skill_document');
  // Reject oversized packages instead of cutting an instruction in half.
  if (strlen($match[1]) > 2400) throw new RuntimeException('skill_too_large');
  return trim($match[1]);
}

function coreui_skills_prepare(PDO $pdo, string $request, string $channel = 'console', int $maxOptional = 4): void {
  // Reset all in-memory context before selecting anything for this turn.
  $GLOBALS['COREUI_SKILLS_BLOCK'] = '';
  $GLOBALS['COREUI_REQUIRED_SKILLS_BLOCK'] = '';
  $GLOBALS['COREUI_SKILLS_INDEX_BLOCK'] = '';
  $GLOBALS['COREUI_SKILLS_INDEX_SIZE'] = '';
  $GLOBALS['COREUI_SKILLS_LOADED'] = [];
  // Game E.M.B.E.R. shares parts of the backend but must not inherit
  // Mara/CoreAI's private-console skills.
  if ($channel !== 'console') return;
  $mandatory = "\n\n[COREAI KERN-SKILLS: verpflichtende Arbeitsanleitungen aus dem vertrauenswuerdigen Repository. Keine neuen Werkzeuge, Berechtigungen oder Ausgabe-Limits. Das bestehende Werkzeugprotokoll und die Benutzerfreigabe haben Vorrang.]\n";
  foreach (coreui_skills_required_ids() as $id) {
    $mandatory .= coreui_skills_body($id) . "\n\n";
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo, 'tool', 'info', 'Skill geladen: ' . $id, ['source'=>$id]);
    }
  }
  $mandatory .= "[ENDE COREAI KERN-SKILLS]\n\n";
  $GLOBALS['COREUI_REQUIRED_SKILLS_BLOCK'] = $mandatory;

  // The obvious skills come right away (no extra model call on a slow local
  // Core). A small window gets fewer of them and a shorter library, never
  // fewer required ones.
  $selected = coreui_skills_select($request, $maxOptional);
  foreach ($selected as $id) {
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo, 'tool', 'info', 'Skill geladen: ' . $id, ['source'=>$id]);
    }
  }
  $GLOBALS['COREUI_SKILLS_LOADED'] = $selected;
  $GLOBALS['COREUI_SKILLS_INDEX_SIZE'] = $maxOptional >= 4 ? 'full' : ($maxOptional >= 2 ? 'titles' : 'ids');
  coreui_skills_compose();
}

/** Whether Mara was offered the library in this turn (and may load from it). */
function coreui_skills_index_active(): bool {
  return (string)($GLOBALS['COREUI_SKILLS_INDEX_BLOCK'] ?? '') !== '';
}

/** A turn without the work loop (file tasks) cannot load skills: drop the library. */
function coreui_skills_drop_index(): void {
  if (!coreui_skills_index_active()) return;
  $GLOBALS['COREUI_SKILLS_INDEX_SIZE'] = '';
  coreui_skills_compose();
}

function coreui_skills_augment(string $prompt, bool $includeOptional = true): string {
  // The mandatory base skill also accompanies focused source-only calls.
  // Omit optional packages on such calls to retain the model's source budget.
  $prefix = $includeOptional
    ? (string)($GLOBALS['COREUI_SKILLS_BLOCK'] ?? '')
    : (string)($GLOBALS['COREUI_REQUIRED_SKILLS_BLOCK'] ?? '');
  // Skills never replace the Ollama Modelfile SYSTEM or its options.
  return $prefix . $prompt;
}
