<?php
declare(strict_types=1);
// CoreAI 0.5.86: Mara's memories are stored and used whole. The old 240
// character cut (when she stored a memory and when it went into her prompt)
// lost the end of longer entries. Now: stored whole up to the guard of 8000
// characters (longer output is not stored at all), and in the prompt only
// whole memories within the window's budget; one that does not fit is left
// out, never cut. Offline: pure helpers loaded from chat.php, plus wiring.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';

function memory_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Memory length FAIL: {$why}\n"); exit(1); }
}

// Load only the pure helpers from chat.php, without booting the endpoint.
$chat = (string)file_get_contents($root . '/api/chat.php');
$tokens = token_get_all($chat);
$wanted = ['ember_mem_fit_facts', 'ember_console_prompt_profile'];
for ($i = 0; $i < count($tokens); $i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
  $j = $i + 1;
  while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
  if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], $wanted, true)) continue;
  $code = ''; $depth = 0; $opened = false;
  for (; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $code .= is_array($token) ? $token[1] : $token;
    if ($token === '{') { $depth++; $opened = true; }
    if ($token === '}') $depth--;
    if ($opened && $depth === 0) break;
  }
  eval($code);
}
memory_test(function_exists('ember_mem_fit_facts') && function_exists('ember_console_prompt_profile'), 'helpers found in chat.php');

// Whole memories, in their order, within the budget; a long one is not cut.
$long = str_repeat('Patrick baut CoreAI als private Alternative und testet jede Version auf dem eigenen Server. ', 12);
$long = trim($long);
memory_test(mb_strlen($long) > 1000, 'a memory longer than the old cut');
$fit = ember_mem_fit_facts(['Mag Tee.', $long, 'Arbeitet abends.'], 12000);
memory_test($fit === ['Mag Tee.', $long, 'Arbeitet abends.'], 'long memory kept whole');
$fit = ember_mem_fit_facts(['Mag Tee.', $long, 'Arbeitet abends.'], 200);
memory_test($fit === ['Mag Tee.', 'Arbeitet abends.'], 'one that does not fit is left out, the next still comes');
foreach ($fit as $fact) memory_test(in_array($fact, ['Mag Tee.', 'Arbeitet abends.', $long], true), 'never a cut fact');
memory_test(ember_mem_fit_facts(['  ', '', 'Ja.'], 100) === ['Ja.'], 'empty entries skipped');
memory_test(ember_mem_fit_facts([$long], 0) === [$long], 'no budget: everything');

// Budget per window: small windows get less, never a cut fact.
memory_test(ember_console_prompt_profile('console', null)['memory'] === 12000, 'large or unknown window');
memory_test(ember_console_prompt_profile('console', 32768)['memory'] === 12000, '32K window');
memory_test(ember_console_prompt_profile('console', 8192)['memory'] === 3000, '8K window');
memory_test(ember_console_prompt_profile('console', 4096)['memory'] === 1500, '4K window');
memory_test(ember_console_prompt_profile('global', 4096)['memory'] === 12000, 'global channel keeps the Core default');

// Wiring: nothing cuts a memory anymore.
memory_test(COREUI_MEMORY_FACT_MAX_CHARS === 8000, 'guard of 8000 characters');
memory_test(!str_contains($chat, 'ember_reflect_max_fact_len') && !preg_match('~\$fact\s*=\s*mb_substr\(\$fact~', $chat), 'no cut of a fact in chat.php');
memory_test(str_contains($chat, 'if ($factLength > COREUI_MEMORY_FACT_MAX_CHARS) {'), 'runaway output is not stored');
memory_test(str_contains($chat, "ember_mem_block(\$pdo, \$uid, \$cid, \$memLimit, \$userMsg, \$profile['memory'])")
  && str_contains($chat, "ember_mem_block(\$pdo, \$uid, \$cid, \$memLimit, \$userMsg, \$promptProfile['memory'])"), 'both prompt builders pass the budget');
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
memory_test(str_contains($read('api/memories.php'), '$length > COREUI_MEMORY_FACT_MAX_CHARS')
  && str_contains($read('api/admin.php'), '$length > COREUI_MEMORY_FACT_MAX_CHARS'), 'Memory tab and Admin Core accept long memories');
$settings = $read('settings.html');
memory_test(str_contains($settings, '<textarea id="memoryFact" rows="3" maxlength="8000"')
  && str_contains($settings, '<textarea id="adminMemoryFact" rows="4" maxlength="8000" required>'), 'the text fields allow long memories');
memory_test(!str_contains($read('api/config.php'), 'STU_EMBER_REFLECT_MAX_FACT_LEN'), 'the old 240 setting is gone');

echo "CoreAI memory length: memories stored and used whole, budget per window, guard of 8000 OK\n";
