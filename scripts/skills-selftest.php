<?php
declare(strict_types=1);
// CoreAI skills. 0.5.80: no per-account switches any more. Mara has every
// skill: the obvious ones are preloaded, the rest stand in a short library in
// her prompt, and she loads one herself with [SKILL: kennung] as a step of
// her work loop. A loaded skill joins the trusted skill block (instructions),
// never the step results (data she must not obey).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/skills.php';
require_once $root . '/api/console_agent_loop.php';
function skill_assert(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
$catalog = coreui_skills_catalog();
skill_assert(count($catalog) >= 33, 'Starter catalog incomplete');
skill_assert(coreui_skills_required_ids() === ['werkzeuge'], 'Exactly one mandatory Werkzeuge skill expected');
skill_assert(($catalog['werkzeuge']['required'] ?? false) === true, 'Required catalog metadata');
skill_assert(count(coreui_skills_optional_ids()) === count($catalog) - 1, 'Every other skill is in the library');
foreach ($catalog as $id=>$row) {
  $body = coreui_skills_body($id);
  skill_assert(strlen($body) > 120 && strlen($body) <= 2400, 'Invalid body: '.$id);
  skill_assert($row['triggers'] !== [] && $row['description'] !== '', 'Missing metadata');
  foreach ($row['sources'] as $source) skill_assert(str_starts_with($source,'https://'), 'Invalid source');
  if (($row['required'] ?? false) === true) {
    skill_assert(!in_array($id, coreui_skills_select('/skill '.$id), true), 'Required skill excluded from optional routing');
  } else {
    skill_assert(coreui_skills_select('/skill '.$id)[0] === $id, 'Explicit selection: '.$id);
  }
}

// Preselection: every skill is eligible, nothing for small talk, budget and priority.
skill_assert(coreui_skills_select('Wie geht es dir heute?') === [], 'Casual message activates optional skills');
skill_assert(!in_array('werkzeuge', coreui_skills_select('werkzeug tool agent'), true), 'Required skill consumes optional slots');
skill_assert(coreui_skills_select('Das ist typisch und keine Sprache.') === [], 'Substring triggers');
skill_assert(coreui_skills_select('Schreibe ein Python-Skript') === ['python'], 'Natural trigger without any switch');
skill_assert(count(coreui_skills_select('projekt html css python php sql git tests zip')) === 4, 'Budget');
skill_assert(count(coreui_skills_select('projekt html css python php sql git tests zip', 1)) === 1, 'Small window budget');
skill_assert(coreui_skills_select('projekt html css python php sql git tests zip /skill zip-delivery')[0] === 'zip-delivery', 'Explicit priority');
skill_assert(coreui_skills_body('../config') === '', 'Path traversal');

// Library: sized to the window, without loaded skills, with the marker explained.
$full = coreui_skills_index([], 'full');
skill_assert(str_contains($full, '[SKILL: kennung]') && str_contains($full, '- python: ' . $catalog['python']['description'])
  && !str_contains($full, 'werkzeuge:'), 'Full library lists every optional skill with its description');
skill_assert(str_contains(coreui_skills_index([], 'titles'), 'python (Python-Programme)'), 'Titles library');
$ids = coreui_skills_index(['python'], 'ids');
skill_assert(str_contains($ids, 'zip-delivery') && !str_contains($ids, ', python,') && str_contains($ids, 'Schon geladen: python.'), 'Loaded skills leave the library');
skill_assert(strlen(coreui_skills_index([], 'ids')) < strlen(coreui_skills_index([], 'titles'))
  && strlen(coreui_skills_index([], 'titles')) < strlen($full) && strlen($full) < 3600, 'Library sizes for the windows');
skill_assert(coreui_skills_index(coreui_skills_optional_ids()) === '', 'Nothing left to offer');

// Marker: plain or as sole inline code; explanations in code do not count.
skill_assert(coreui_skills_extract_marker('[SKILL: python]') === 'python', 'Marker');
skill_assert(coreui_skills_extract_marker('[skill: Python, zip-delivery]') === 'python, zip-delivery', 'Two skills, any case');
skill_assert(coreui_skills_extract_marker('`[SKILL: python]`') === 'python', 'Sole inline code marker');
skill_assert(coreui_skills_extract_marker('Mit `[SKILL: python]` lade ich Anleitungen.') === null, 'Explanation in code');
skill_assert(coreui_skills_extract_marker("```\n[SKILL: python]\n```") === null, 'Code block');
skill_assert(coreui_skills_extract_marker('[SKILL: ../etc]') === null, 'Only ids');

// Runtime with a fake PDO: console gets required + preselected + library;
// [SKILL:] adds instructions to the trusted block and updates the library.
$pdo = new PDO('sqlite::memory:');
coreui_skills_prepare($pdo, 'Hallo Mara');
$block = coreui_skills_augment('task');
skill_assert(str_contains($block, 'Werkzeuge: verbindlicher CoreAI-Kern-Skill') && str_contains($block, '[SKILL-BIBLIOTHEK:')
  && !str_contains($block, '[COREUI SKILLS:') && str_ends_with($block, 'task'), 'Small talk: core skill and library, nothing preloaded');
skill_assert(coreui_skills_index_active(), 'Library offered');
$result = coreui_skills_tool_result('python, gibtsnicht');
$after = coreui_skills_augment('task');
skill_assert(str_contains($result, 'Geladen: python (Python-Programme)') && str_contains($result, '"gibtsnicht" gibt es nicht')
  && !str_contains($result, 'Trenne Ein-/Ausgabe'), 'The step only reports');
skill_assert(str_contains($after, "[COREUI SKILLS:") && str_contains($after, 'Trenne Ein-/Ausgabe')
  && str_contains($after, 'Schon geladen: python.'), 'The skill joins the trusted block, the library updates');
skill_assert(str_contains(coreui_skills_tool_result('python'), 'schon geladen'), 'No double load');
skill_assert(str_contains(coreui_skills_tool_result('werkzeuge'), 'schon geladen'), 'The core skill is always there');
$focused = coreui_skills_augment('task', false);
skill_assert(str_contains($focused, 'Werkzeuge: verbindlicher') && !str_contains($focused, 'Trenne Ein-/Ausgabe')
  && !str_contains($focused, 'SKILL-BIBLIOTHEK'), 'Focused subcall: core skill only');
coreui_skills_prepare($pdo, '/skill zip-delivery');
$explicit = coreui_skills_augment('task');
skill_assert(str_contains($explicit, 'ZIP und Dateiauslieferung') && !str_contains($explicit, 'Trenne Ein-/Ausgabe'),
  'Every turn starts fresh; explicit choice preloads');
coreui_skills_drop_index();
skill_assert(!coreui_skills_index_active() && !str_contains(coreui_skills_augment('task'), 'SKILL-BIBLIOTHEK')
  && str_contains(coreui_skills_augment('task'), 'ZIP und Dateiauslieferung'), 'A file task keeps loaded skills but no library');
coreui_skills_prepare($pdo, 'Python', 'console', 1);
skill_assert(str_contains(coreui_skills_augment('task'), 'Schon geladen: python.') && !str_contains(coreui_skills_augment('task'), '- zip-delivery:'),
  'Small window: one skill, ids only');
coreui_skills_prepare($pdo, 'Hallo /skill python', 'global');
skill_assert(coreui_skills_augment('task') === 'task' && !coreui_skills_index_active(), 'Nothing leaks into the Game channel');
unset($GLOBALS['COREUI_SKILLS_BLOCK']);
skill_assert(coreui_skills_augment('Hallo') === 'Hallo', 'Disabled path changed prompt');

// Work loop: [SKILL:] is a step like the others.
$tool = coreui_agent_next_tool('[SKILL: python]', ['web', 'skill']);
skill_assert($tool === ['tool'=>'skill', 'input'=>'python'], 'Loop sees the skill marker');
skill_assert(coreui_agent_next_tool('[SKILL: python]', ['web']) === null, 'Only when the library is offered');
skill_assert(coreui_agent_step_title($tool, 1, 6) === 'Schritt 1 von 6: Skill geladen'
  && coreui_agent_step_note($tool) === 'Ich lade die Arbeitsanleitung: python', 'Work view');
skill_assert(str_contains(coreui_agent_tool_menu(['skill']), '[SKILL: kennung]'), 'The next step may load a skill');
coreui_skills_prepare($pdo, 'Kannst du mir heute helfen?');
$seen = [];
$records = [];
$loop = coreui_agent_loop_run('[SKILL: python]', $tool, 'BASIS', ['web', 'skill'], 0,
  static function (string $prompt) use (&$seen): ?string { $seen[] = coreui_skills_augment($prompt); return 'Hier ist dein sauberes Skript.'; },
  static fn(array $t): string => coreui_skills_tool_result((string)$t['input']),
  static function (string $kind, string $state, string $title, array $meta) use (&$records): void { $records[] = $title; },
  static fn(): bool => false);
skill_assert($loop['out'] === 'Hier ist dein sauberes Skript.' && $loop['steps'] === 1 && count($seen) === 1, 'The loop answers after loading');
skill_assert(strpos($seen[0], 'Trenne Ein-/Ausgabe') < strpos($seen[0], '--- DEINE ARBEITSSCHRITTE BISHER')
  && str_contains($seen[0], 'Geladen: python (Python-Programme)'), 'The guide sits in the trusted block, the step only reports');
skill_assert(in_array('Schritt 1 von 6: Skill geladen', $records, true), 'The work view shows the step');

// Wiring.
$chat = (string)file_get_contents($root . '/api/chat.php');
foreach (['chat.php', 'console_stream.php'] as $file) {
  $code = (string)file_get_contents($root . '/api/' . $file);
  skill_assert(str_contains($code, '$userPrompt = coreui_skills_augment($userPrompt, $augmentSkills);'), 'Skill prompt hook missing: ' . $file);
}
skill_assert(substr_count($chat, 'coreui_skills_prepare($pdo, $userMsg, $channel, ') === 2, 'Both prompt builders prepare skills');
skill_assert(str_contains($chat, "if (\$tool['tool'] === 'skill') return coreui_skills_tool_result((string)\$tool['input']);")
  && str_contains($chat, "if (coreui_skills_index_active()) \$tools[] = 'skill';")
  && str_contains($chat, "array_intersect(\$loopAllowed, ['web', 'archive', 'skill'])")
  && str_contains($chat, "if (\$fileTask) coreui_skills_drop_index();")
  && str_contains($chat, '|\[SKILL:\s*.*?\]|'), 'chat.php runs, offers, drops and strips the skill step');
skill_assert(substr_count((string)file_get_contents($root . '/api/console_stream.php'), '|| coreui_skills_extract_marker($reply) !== null') === 2,
  'The stream hands a skill request to the work loop');
skill_assert(!is_file($root . '/api/user_skills.php') && !is_file($root . '/js/skills-settings.js'), 'No skill switches any more');
foreach (['config/runtime/nginx.conf.template','config/nginx.conf.template'] as $nginxFile) {
  skill_assert(!str_contains((string)file_get_contents($root . '/' . $nginxFile), 'user_skills'), 'Old endpoint still allowed: ' . $nginxFile);
}
$settings = (string)file_get_contents($root . '/settings.html');
skill_assert(!str_contains($settings, 'view-skills') && !str_contains($settings, 'skills-settings'), 'No Skills tab');
echo "CoreAI skills: whole library for Mara, preselection, [SKILL:] in the work loop, trusted block, no switches OK\n";
