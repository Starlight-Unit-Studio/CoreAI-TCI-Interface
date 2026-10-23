<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_work_store.php';

function syntax_test(bool $passed, string $name): void {
  if (!$passed) { fwrite(STDERR, "Python syntax gate FAIL: " . $name . "\n"); exit(1); }
}
$source = file_get_contents(dirname(__DIR__) . '/api/chat.php');
syntax_test(is_string($source) && $source !== '', 'production PHP available');

// Evaluate only isolated pure helpers, never the HTTP/controller/database path.
$wanted = ['ember_py_syntax_preflight_issue','ember_py_syntax_repair_prompt'];
$tokens = token_get_all($source);
$loaded = [];
for ($i = 0; $i < count($tokens); $i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
  $j = $i + 1;
  while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
  if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], $wanted, true)) continue;
  $name = $tokens[$j][1];
  $code = '';
  $depth = 0; $opened = false;
  for (; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $code .= is_array($token) ? $token[1] : $token;
    if ($token === '{') { $depth++; $opened = true; }
    if ($token === '}') $depth--;
    if ($opened && $depth === 0) break;
  }
  eval($code);
  $loaded[$name] = true;
}
syntax_test(count($loaded) === count($wanted), 'helpers extracted');

$first = [
  'id'=>69, 'error'=>'syntax_preflight_failed', 'exit_code'=>88,
  'duration_ms'=>482,
  'stderr'=>"COREUI_PY_SYNTAX_ERROR:SyntaxError:17:3\n",
];
$issue = ember_py_syntax_preflight_issue($first);
syntax_test(is_array($issue) && $issue['error_type'] === 'SyntaxError'
  && $issue['line_number'] === 17 && $issue['column_number'] === 3,
  'safe trusted preflight issue');
$visible = coreui_work_python_diagnostic($first);
syntax_test(($visible['error_type'] ?? '') === 'SyntaxError'
  && ($visible['line_number'] ?? 0) === 17,
  'work events expose safe line number');
syntax_test(!str_contains(json_encode($visible), 'COREUI_PY_SYNTAX_ERROR')
  && !str_contains(json_encode($visible), 'main.py')
  && !isset($visible['stderr']) && !isset($visible['code']),
  'raw diagnostics never exposed');

syntax_test(ember_py_syntax_preflight_issue(
  ['error'=>'', 'exit_code'=>88, 'stderr'=>$first['stderr']]) === null,
  'untrusted exit code is not syntax proof');
syntax_test(ember_py_syntax_preflight_issue(
  ['error'=>'syntax_preflight_failed', 'exit_code'=>88,
   'stderr'=>"COREUI_PY_SYNTAX_ERROR:SyntaxError:17:3\nprivate code"]) === null,
  'malformed structured error rejected');
syntax_test(ember_py_syntax_preflight_issue(
  ['error'=>'syntax_preflight_failed', 'exit_code'=>1, 'stderr'=>$first['stderr']]) === null,
  'wrong exit code rejected');
syntax_test(ember_py_syntax_preflight_issue([
  'error'=>'syntax_preflight_failed', 'exit_code'=>88,
  'stderr'=>"COREUI_PY_SYNTAX_ERROR:IndentationError:2:1\n",
])['error_type'] === 'IndentationError', 'indentation errors supported');
$code = "x =\n";
$prompt = ember_py_syntax_repair_prompt(
  'Schreibe Python-Code, berechne 2+2.', $code, $issue);
syntax_test(is_string($prompt) && str_contains($prompt, 'Zeile: 17')
  && str_contains($prompt, $code) && str_contains($prompt, '[PY]'),
  'bounded repair prompt carries complete failed code and location');
syntax_test(ember_py_syntax_repair_prompt('a', str_repeat('x', 24001), $issue) === null,
  'oversized code never enters a costly clipped repair');
syntax_test(ember_py_syntax_repair_prompt('a', $code,
  ['error_type'=>'worker_timeout']) === null, 'only syntax failures are repairable');

// Ensure the real controller has a single bounded repair path.
syntax_test(str_contains($source, '$syntaxIssue = ember_py_syntax_preflight_issue($pyRes);')
  && str_contains($source, '$retryResult = ember_py_run($pdo, $correctedCode, $channel,')
  && str_contains($source, 'if ($needsFileRepair && $syntaxRepairAttempted)'),
  'live generic Python path uses the syntax gate, not one file-type special-case');
echo "CoreUI general Python syntax PHP selftest: OK\n";
