<?php
declare(strict_types=1);
// CoreAI 0.5.76: chat installer for missing TCI tiers. Offline: the Core is
// built from Mara's Modelfile with only FROM swapped, the hardware check
// gives a clear verdict, Ollama's pull stream becomes progress, one install
// runs at a time and only admins may start one.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$cache = sys_get_temp_dir() . '/coreui-install-test-' . getmypid();
@mkdir($cache, 0775, true);
define('STU_EMBER_CACHE_DIR', $cache);
require_once $root . '/api/core_install.php';

function install_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Core install FAIL: {$why}\n"); exit(1); }
}
function install_throws(callable $fn, string $code): bool {
  try { $fn(); } catch (RuntimeException $e) { return $e->getMessage() === $code; }
  return false;
}

// Names: Mara's family with the size as tag; only catalog tiers.
$family = coreui_core_mara_family();
install_test(coreui_core_install_name('plus') === $family . ':12b' && coreui_core_install_name('schnell') === $family . ':e2b',
  'installed Cores carry Mara\'s name and the size');
install_test(install_throws(fn() => coreui_core_install_name('gemma4:evil'), 'unknown_tier'), 'unknown tier is refused');
install_test(install_throws(fn() => coreui_core_install_check('../x'), 'unknown_tier'), 'the check takes catalog tiers only');
foreach (COREUI_CORE_INSTALL_CATALOG as $tier => $entry) {
  install_test(preg_match('~^gemma4:(e2b|e4b|12b|26b|31b)$~', $entry['base']) === 1 && coreui_core_tier($entry['base']) === $tier,
    "catalog base of {$tier} is its local Gemma 4 tier");
}

// The create request carries the template as written; only FROM changes.
$template = (string)file_get_contents($root . '/config/Modelfile.template');
$before = sha1($template);
$request = coreui_core_create_request($template, $family . ':12b', 'gemma4:12b');
preg_match('~^SYSTEM[ \t]+"""(.*?)^"""~ms', $template, $sys);
install_test($request['model'] === $family . ':12b' && $request['from'] === 'gemma4:12b', 'name and base');
install_test(($request['system'] ?? null) === $sys[1] && str_starts_with($request['system'], 'Du bist Mara Thorne'), 'SYSTEM text unchanged');
install_test(($request['template'] ?? null) === '{{ .Prompt }}', 'TEMPLATE unchanged');
install_test(($request['renderer'] ?? '') === 'gemma4' && ($request['parser'] ?? '') === 'gemma4', 'renderer and parser kept');
preg_match_all('~^PARAMETER[ \t]+(\S+)[ \t]+(\S+)~m', $template, $params, PREG_SET_ORDER);
install_test(count($params) > 0 && count($request['parameters']) === count($params), 'every PARAMETER is sent');
foreach ($params as [, $key, $value]) {
  install_test(isset($request['parameters'][$key]) && (string)$request['parameters'][$key] === $value, "PARAMETER {$key} as written");
}
install_test(is_int($request['parameters']['num_ctx'] ?? null) && is_float($request['parameters']['top_p'] ?? null), 'parameters typed like Ollama');
install_test($request['stream'] === false && !isset($request['modelfile']) && !isset($request['quantize']), 'plain create, nothing added');
install_test(sha1((string)file_get_contents($root . '/config/Modelfile.template')) === $before, 'the Modelfile itself is never changed');

// Parser: quotes, comments, messages, several stops; anything unknown stops the install.
$mf = "# comment\nFROM gemma4:e4b\nPARAMETER stop \"<end>\"\nPARAMETER stop <eot>\nPARAMETER use_mmap true\n"
  . "MESSAGE user \"Hallo\"\nSYSTEM \"\"\"Zeile 1\nZeile 2\n\"\"\"\nLICENSE \"\"\"Lizenz\"\"\"\n";
$r = coreui_core_create_request($mf, 'x:12b', 'gemma4:12b');
install_test($r['parameters']['stop'] === ['<end>', '<eot>'] && $r['parameters']['use_mmap'] === true, 'stops and booleans');
install_test($r['messages'] === [['role'=>'user', 'content'=>'Hallo']] && $r['system'] === "Zeile 1\nZeile 2\n" && $r['license'] === ['Lizenz'],
  'messages, multi-line SYSTEM and license');
install_test(install_throws(fn() => coreui_core_create_request("FROM a\nADAPTER ./x.gguf\n", 'x', 'y'), 'modelfile_command_unsupported'), 'ADAPTER refused');
install_test(install_throws(fn() => coreui_core_create_request("SYSTEM hi\n", 'x', 'y'), 'modelfile_from_missing'), 'FROM required');
install_test(install_throws(fn() => coreui_core_create_request("FROM a\nFROM b\n", 'x', 'y'), 'modelfile_from_missing'), 'one FROM only');
install_test(install_throws(fn() => coreui_core_create_request("FROM a\nSYSTEM \"\"\"offen\n", 'x', 'y'), 'modelfile_unclosed'), 'unclosed quotes');

// Hardware check: rows and verdicts.
$gib = COREUI_GIB;
$big = ['mem_total'=>94 * $gib, 'mem_available'=>61 * $gib, 'threads'=>20, 'avx2'=>true, 'avx512'=>true,
  'disk_free'=>800 * $gib, 'ollama'=>'0.40.6', 'gpu'=>null];
$plus = COREUI_CORE_INSTALL_CATALOG['plus'];
$v = coreui_core_install_verdict($big, $plus);
install_test($v['verdict'] === 'gut' && !$v['blocked'] && array_column($v['rows'], 'key') === ['ollama', 'ram', 'cpu', 'disk', 'speed'], 'big server: gut');
install_test(str_contains($v['rows'][2]['value'], 'AVX-512') && str_contains($v['rows'][1]['value'], '94 GB gesamt'), 'rows read like the server');
$v = coreui_core_install_verdict($big, COREUI_CORE_INSTALL_CATALOG['premium']);
install_test($v['verdict'] === 'knapp' && $v['rows'][4]['state'] === 'warn', 'dense 31b without a graphics card: knapp');
install_test(coreui_core_install_verdict(['gpu'=>true] + $big, COREUI_CORE_INSTALL_CATALOG['premium'])['verdict'] === 'gut', 'with a graphics card: gut');
install_test(coreui_core_install_verdict(['mem_available'=>5 * $gib] + $big, $plus)['verdict'] === 'knapp', 'little free memory: knapp');
$v = coreui_core_install_verdict(['mem_total'=>8 * $gib, 'mem_available'=>6 * $gib] + $big, $plus);
install_test($v['verdict'] === 'abraten' && $v['rows'][1]['state'] === 'bad' && !$v['blocked'], 'too little memory: abraten, still possible');
install_test(coreui_core_install_verdict(['disk_free'=>5 * $gib] + $big, $plus)['verdict'] === 'abraten', 'too little disk: abraten');
install_test(coreui_core_install_verdict(['disk_free'=>15 * $gib] + $big, $plus)['verdict'] === 'knapp', 'disk tight afterwards: knapp');
install_test(coreui_core_install_verdict(['avx2'=>false, 'avx512'=>false] + $big, $plus)['verdict'] === 'knapp', 'no AVX2: knapp');
$v = coreui_core_install_verdict(['ollama'=>null] + $big, $plus);
install_test($v['blocked'] && $v['verdict'] === 'abraten', 'Ollama unreachable blocks');
$v = coreui_core_install_verdict(['mem_total'=>null, 'mem_available'=>null, 'disk_free'=>null, 'threads'=>null, 'avx2'=>null, 'avx512'=>null] + $big, $plus);
install_test($v['verdict'] === 'knapp' && str_contains($v['rows'][1]['value'], 'nicht lesbar'), 'unreadable values: knapp, never guessed');

// Pull stream to progress, Ollama errors in plain words.
$state = ['base'=>'gemma4:12b', 'phase'=>'pull', 'layers'=>[]];
foreach ([
  ['status'=>'pulling manifest'],
  ['status'=>'pulling aaa', 'digest'=>'sha256:aaa', 'total'=>8000, 'completed'=>2000],
  ['status'=>'pulling bbb', 'digest'=>'sha256:bbb', 'total'=>2000, 'completed'=>2000],
] as $line) $state = coreui_core_pull_fold($state, $line);
install_test($state['total'] === 10000 && $state['completed'] === 4000 && $state['percent'] === 40 && $state['step'] === 'Lädt herunter …', 'progress over all layers');
$state = coreui_core_pull_fold($state, ['status'=>'pulling aaa', 'digest'=>'sha256:aaa', 'total'=>8000, 'completed'=>8000]);
$state = coreui_core_pull_fold($state, ['status'=>'verifying sha256 digest']);
install_test($state['percent'] === 100 && $state['step'] === 'Prüft den Download …' && empty($state['pulled']), 'verifying is not done yet');
$state = coreui_core_pull_fold($state, ['status'=>'success']);
install_test(!empty($state['pulled']) && $state['phase'] === 'pull', 'success marks the download');
$err = coreui_core_pull_fold(['base'=>'gemma4:12b', 'phase'=>'pull'], ['error'=>'pull model manifest: file does not exist']);
install_test($err['phase'] === 'error' && str_starts_with($err['message'], 'Ollama kennt gemma4:12b nicht'), 'unknown tag in plain words');
install_test(str_contains(coreui_core_pull_error_text('dial tcp: lookup registry.ollama.ai: no such host', 'x'), 'Internet'), 'offline server');
install_test(str_contains(coreui_core_pull_error_text('write /root/.ollama: no space left on device', 'x'), 'kein Platz'), 'full disk');

// Without the right to install there are no server details.
$plain = coreui_core_install_check('plus', false);
install_test($plain['rows'] === [] && $plain['verdict'] === null && $plain['ram_need'] === null && $plain['label'] === 'TCI: Mara Plus',
  'no hardware rows for other accounts');

// Missing tiers: local only, Mara's own Core counts for her base's tier.
$default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
$missing = array_column(coreui_core_missing_tiers([['name'=>$default], ['name'=>'gemma4:e2b'], ['name'=>'gemma4:31b-cloud'],
  ['name'=>$family . ':12b'], ['name'=>'gpt-oss:120b-cloud']]), 'tier');
$baseTier = coreui_core_tier(coreui_core_mara_base());
$expected = array_values(array_diff(['pro', 'premium'], [$baseTier]));
install_test($missing === $expected, 'missing: local tiers without a Core, cloud does not count');

// One install at a time; a job that died reads as interrupted.
install_test(coreui_core_install_status() === null, 'no install yet');
$lock = coreui_core_install_lock();
install_test($lock !== false, 'lock taken');
install_test(coreui_core_install_lock() === false && coreui_core_install_running(), 'a second install is refused');
$job = coreui_core_install_begin(['tier'=>'plus', 'label'=>'TCI: Mara Plus', 'base'=>'gemma4:12b', 'core'=>$family . ':12b']);
$status = coreui_core_install_status();
install_test($status['running'] === true && $status['phase'] === 'pull' && $status['tier'] === 'plus' && !isset($status['layers']), 'running job is public without internals');
flock($lock, LOCK_UN);
fclose($lock);
$status = coreui_core_install_status();
install_test($status['running'] === false && $status['phase'] === 'error' && $status['error'] === 'install_interrupted', 'dead job reads as interrupted');
array_map('unlink', glob($cache . '/*') ?: []);
@rmdir($cache);

// Wiring: admins only, confirmation when advised against, answer first, lock released.
$settings = (string)file_get_contents($root . '/api/user_settings.php');
$post = substr($settings, (int)strpos($settings, "=== 'core_install') {"));
install_test(COREUI_CORE_INSTALL_PERMISSION_MAX === 1
  && strpos($post, 'COREUI_CORE_INSTALL_PERMISSION_MAX') < strpos($post, 'coreui_core_install_lock()'), 'permission is checked before anything runs');
install_test(str_contains($post, "\$check['verdict'] === 'abraten' && (\$body['confirm'] ?? false) !== true"), 'advised against needs a confirmation');
install_test(str_contains($post, "if (\$check['blocked'])") && str_contains($post, "if (\$check['installed'])"), 'blocked or installed tiers are refused');
install_test(strpos($post, 'fastcgi_finish_request') < strpos($post, 'coreui_core_install_run(') && str_contains($post, 'flock($lock, LOCK_UN)'),
  'the answer goes out first, the lock is released');
install_test(str_contains($settings, "\$admin = \$level <= COREUI_CORE_INSTALL_PERMISSION_MAX;")
  && str_contains($settings, "coreui_core_install_check(trim((string)\$_GET['core_install']), \$admin)")
  && str_contains($settings, "'can_install'=>\$admin"), 'hardware details and the button only for admins');
$models = (string)file_get_contents($root . '/api/models.php');
install_test(str_contains($models, "'missing_tiers'=>coreui_core_missing_tiers(\$models)") && str_contains($models, "'install'=>coreui_core_install_status()")
  && str_contains($models, "'mara'=>coreui_core_is_mara("), 'the Core list carries missing tiers and the install');
$lib = (string)file_get_contents($root . '/api/core_install.php');
install_test(!str_contains($lib, 'ember_global_lock_acquire') && !str_contains($lib, 'file_put_contents($root')
  && !preg_match('~fopen\([^)]*Modelfile~', $lib), 'no serial queue, no Modelfile write');

echo "CoreAI Core install: Modelfile create, hardware check, pull progress, one job, admins only OK\n";
