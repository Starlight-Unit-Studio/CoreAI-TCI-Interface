<?php
declare(strict_types=1);
// CoreAI 0.5.76: chat installer for missing TCI tiers. Offline: the Core is
// built from Mara's Modelfile with only FROM swapped, the hardware check
// gives a clear verdict, Ollama's pull stream becomes progress, one job
// runs at a time and only admins may start one. Updates rebuild Mara's
// Cores, weekly on request; Ollama Cloud connects like `ollama signin`.
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
install_test(strpos($post, 'coreui_core_job_detach(') < strpos($post, "coreui_core_job_run(\$lock, 'coreui_core_install_run'"),
  'the answer goes out first, then the job runs');
install_test(str_contains($settings, "\$admin = \$level <= COREUI_CORE_INSTALL_PERMISSION_MAX;")
  && str_contains($settings, "coreui_core_install_check(trim((string)\$_GET['core_install']), \$admin)")
  && str_contains($settings, "'can_install'=>\$admin"), 'hardware details and the button only for admins');
$models = (string)file_get_contents($root . '/api/models.php');
install_test(str_contains($models, "'missing_tiers'=>coreui_core_missing_tiers(\$models)") && str_contains($models, "'install'=>coreui_core_install_status()")
  && str_contains($models, "'mara'=>coreui_core_is_mara("), 'the Core list carries missing tiers and the install');
$lib = (string)file_get_contents($root . '/api/core_install.php');
install_test(!str_contains($lib, 'ember_global_lock_acquire') && !str_contains($lib, 'file_put_contents($root')
  && !preg_match('~fopen\([^)]*Modelfile~', $lib), 'no serial queue, no Modelfile write');
$detach = substr($lib, (int)strpos($lib, 'function coreui_core_job_detach'));
install_test(strpos($detach, 'echo json_encode') < strpos($detach, 'fastcgi_finish_request') && str_contains($lib, "finally {\n    flock(\$lock, LOCK_UN);"),
  'answer first, the lock is always released');

// Update plan: every local base in use once, in tier order, with Mara's Cores on it.
$default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
$maraBase = coreui_core_mara_base();
$listing = [['name'=>$default, 'digest'=>'d0'], ['name'=>'gemma4:e4b', 'digest'=>'d1'], ['name'=>'gemma4:e2b', 'digest'=>'d1'],
  ['name'=>'gemma4:26b', 'digest'=>'d1'], ['name'=>$family . ':12b', 'digest'=>'d5'], ['name'=>'gemma4:31b-cloud', 'digest'=>'d6'],
  ['name'=>'gpt-oss:120b-cloud', 'digest'=>'d7']];
$plan = coreui_core_update_plan($listing);
install_test(array_column($plan, 'base') === ['gemma4:e2b', 'gemma4:e4b', 'gemma4:12b', 'gemma4:26b'], 'plan: local bases in tier order, no cloud');
$byBase = array_column($plan, 'cores', 'base');
install_test($byBase['gemma4:12b'] === [['name'=>$family . ':12b', 'source'=>'template']], 'installer Core rebuilt from the template');
install_test(($byBase[$maraBase][0] ?? null) === ['name'=>$default, 'source'=>'mara'], 'Mara\'s own Core rebuilt from her Modelfile');
install_test(coreui_core_update_plan([['name'=>'gpt-oss:120b-cloud']]) === [], 'nothing local, nothing to update');
install_test(str_contains(coreui_core_mara_modelfile(), 'Du bist Mara Thorne'), 'Mara\'s Modelfile is readable');

// Overview: status per tier, aliases (same manifest as another tier), cloud variants.
$rows = array_column(coreui_core_manage_rows($listing), null, 'tier');
install_test($rows['schnell']['alias_of'] === $maraBase && $rows['pro']['alias_of'] === $maraBase && $rows['normal']['alias_of'] === null,
  'second names for the same weights point to Mara\'s base');
$twins = array_column(coreui_core_manage_rows([['name'=>'gemma4:12b', 'digest'=>'x'], ['name'=>'gemma4:31b', 'digest'=>'x']]), null, 'tier');
install_test($twins['plus']['alias_of'] === null && $twins['premium']['alias_of'] === 'gemma4:12b', 'without Mara\'s base the first tier is real');
install_test($rows['plus']['status'] === 'installed' && $rows['plus']['alias_of'] === null, 'installer Core counts as installed');
install_test($rows['premium']['status'] === 'cloud' && $rows['premium']['cloud'] === ['gemma4:31b-cloud'] && $rows['premium']['cloud_missing'] === false,
  'cloud only Premium');
$bare = array_column(coreui_core_manage_rows([['name'=>$default]]), null, 'tier');
install_test($bare['premium']['status'] === 'missing' && $bare['premium']['cloud_missing'] === true && $bare['plus']['cloud_base'] === null,
  'missing Premium can be added from the cloud, Plus has no cloud variant');

// Summary and weekly due date.
install_test(coreui_core_update_summary([]) !== '' && coreui_core_update_summary([['result'=>'current'], ['result'=>'rebuilt']]) === 'Alle Stufen sind aktuell.',
  'summary when nothing changed');
install_test(coreui_core_update_summary([['result'=>'updated'], ['result'=>'error']]) === 'Eine Stufe hatte eine neue Fassung und wurde neu gebaut. Eine Stufe schlug fehl.',
  'summary with a failure');
$week = COREUI_CORE_UPDATE_DAYS * 86400;
install_test(COREUI_CORE_UPDATE_DAYS === 7 && coreui_core_auto_update_due(['enabled'=>true, 'last'=>1000, 'next'=>1000 + $week], 1000 + $week)
  && !coreui_core_auto_update_due(['enabled'=>true, 'last'=>1000, 'next'=>1000 + $week], 999 + $week)
  && !coreui_core_auto_update_due(['enabled'=>false, 'last'=>null, 'next'=>null], PHP_INT_MAX), 'weekly, only when switched on');
$autoFn = substr($lib, (int)strpos($lib, 'function coreui_core_auto_update_set'), 400);
install_test(str_contains($autoFn, "if (\$on) stu_app_setting_set(\$pdo, 'core_auto_update_since', (string)time());"), 'switching on starts the week, no surprise download');

// Ollama Cloud: like `ollama signin`, only Ollama's own connect page as link.
$url = 'https://ollama.com/connect?name=coreai&key=c3NoLWVkMjU1MTkgQUFBQUMzTnphQzFsWkRJMU5URTVBQUFBSUJ';
install_test(coreui_ollama_cloud_account_from(401, ['error'=>'unauthorized', 'signin_url'=>$url]) === ['state'=>'signed_out', 'signin_url'=>$url],
  'signed out with connect link');
foreach (['javascript:alert(1)', 'https://ollama.com.evil.io/connect?x=1', 'http://ollama.com/connect?x=1', 'https://ollama.com/connect?x="><b>'] as $bad) {
  install_test(coreui_ollama_cloud_account_from(401, ['signin_url'=>$bad])['signin_url'] === null, 'refused link ' . $bad);
}
install_test(coreui_ollama_cloud_account_from(200, ['name'=>'mara', 'plan'=>'']) === ['state'=>'connected', 'name'=>'mara', 'plan'=>'free'], 'connected');
install_test(coreui_ollama_cloud_account_from(200, [])['state'] === 'unavailable' && coreui_ollama_cloud_account_from(503, ['error'=>'account unavailable'])['state'] === 'unavailable'
  && coreui_ollama_cloud_account_from(404, [])['state'] === 'unsupported', 'other answers');
install_test(coreui_ollama_version_at_least('0.40.6', '0.12.0') && coreui_ollama_version_at_least('0.12.0-rc1', '0.12.0')
  && !coreui_ollama_version_at_least('0.11.10', '0.12.0') && !coreui_ollama_version_at_least(null, '0.12.0'), 'cloud needs Ollama 0.12');
install_test(COREUI_CORE_CLOUD_CATALOG === ['premium'=>'gemma4:31b-cloud'] && coreui_core_tier('gemma4:31b-cloud') === 'premium', 'cloud catalog');
install_test(str_contains($lib, "coreui_core_install_post('me', [], 15)"), 'account read like ollama signin (POST /api/me)');
$cloudBegin = coreui_core_cloud_begin('premium');
install_test($cloudBegin['cloud'] === true && $cloudBegin['base'] === 'gemma4:31b-cloud' && $cloudBegin['core'] === 'gemma4:31b-cloud', 'cloud job pulls the tag only');
install_test(str_contains($lib, "if (\$state['phase'] !== 'error' && !empty(\$state['cloud'])) {"), 'no Modelfile build for cloud Cores');

// Endpoints: admins only for the overview, cloud, switch, update and cloud add.
foreach (["array_key_exists('core_manage', \$_GET)", "array_key_exists('core_cloud', \$_GET)", "=== 'core_auto_update')",
  "=== 'core_update')", "=== 'core_cloud_add')"] as $marker) {
  $part = substr($settings, (int)strpos($settings, $marker), 500);
  install_test(str_contains($part, 'stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);'), 'admins only: ' . $marker);
}
install_test(str_contains($settings, "if ((coreui_ollama_cloud_status()['account']['state'] ?? '') !== 'connected')"), 'cloud Core only when connected');
$get = substr($settings, (int)strpos($settings, "\$payload = ["));
install_test(strpos($get, 'coreui_core_auto_update_due(') < strpos($get, "stu_app_setting_set(\$pdo, 'core_update_last'")
  && strpos($get, "stu_app_setting_set(\$pdo, 'core_update_last'") < strpos($get, 'coreui_core_install_lock()')
  && str_contains($get, "coreui_core_job_run(\$lock, 'coreui_core_update_run'"), 'weekly update starts from a page load, marked first');

echo "CoreAI Core install: Modelfile create, hardware check, pull progress, updates, Ollama Cloud, one job, admins only OK\n";
