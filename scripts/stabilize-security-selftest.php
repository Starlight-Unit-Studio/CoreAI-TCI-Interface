<?php
declare(strict_types=1);
// CoreAI 0.8.34 stabilization: account limits for second-factor codes and password resets, capped text
// extraction from Office/PDF attachments, a log tail that does not load the whole log, and database
// error text that never reaches the browser. Offline (SQLite, generated ZIP files, temp files).
//   php scripts/stabilize-security-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';
require_once $root . '/api/captcha.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, 'Stabilize security FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1); });
function sec_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Stabilize security FAIL: {$why}\n"); exit(1); }
}
$auth = (string)file_get_contents($root . '/api/auth.php');
$pos = static function (string $needle, int $from = 0) use ($auth): int {
  $p = strpos($auth, $needle, $from);
  sec_test($p !== false, 'auth.php contains: ' . $needle);
  return (int)$p;
};

// --- Second factor: failures counted per account, checked before any code is tested ---------------
preg_match_all('~^const (COREUI_AUTH_[A-Z_]+) = (\d+);~m', $auth, $consts, PREG_SET_ORDER);
$c = [];
foreach ($consts as $m) $c[$m[1]] = (int)$m[2];
sec_test(($c['COREUI_AUTH_MFA_FAIL_MAX'] ?? 0) === 10 && ($c['COREUI_AUTH_MFA_FAIL_WINDOW'] ?? 0) === 900
  && ($c['COREUI_AUTH_RESET_CODES_PER_HOUR'] ?? 0) === 3 && ($c['COREUI_AUTH_RESET_TRIES'] ?? 0) === 5, 'account limits: ' . json_encode($c));
$mfa = $pos("if (\$action === 'mfa_verify' || \$action === 'mfa_email') {");
$gate = $pos("\$mfaFailKey = coreui_abuse_key(\$pdo, 'mfa_fail', (string)\$uid);", $mfa);
$block = $pos("COREUI_AUTH_MFA_FAIL_WINDOW, 0)['n'] >= COREUI_AUTH_MFA_FAIL_MAX", $gate);
$check = $pos('$passed = coreui_security_totp_check(', $mfa);
$count = $pos("coreui_abuse_count(\$pdo, \$mfaFailKey, COREUI_AUTH_MFA_FAIL_WINDOW, 1);", $check);
sec_test($gate < $check && $block < $check && $count > $check, 'the account gate comes before any code check, the count after a failure');

// The counter itself: ten failures of one account from ten sessions block the eleventh, another account is free.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_abuse_counters (k CHAR(64) NOT NULL PRIMARY KEY, window_start TEXT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL)');
coreui_abuse_secret($pdo, str_repeat('ab', 32));
$now = 1800000000;
$key = coreui_abuse_key($pdo, 'mfa_fail', '7');
for ($i = 0; $i < 10; $i++) coreui_abuse_count($pdo, $key, 900, 1, $now + $i);
sec_test(coreui_abuse_count($pdo, $key, 900, 0, $now + 20)['n'] >= 10, 'ten failures of one account are counted together');
sec_test(coreui_abuse_count($pdo, coreui_abuse_key($pdo, 'mfa_fail', '8'), 900, 0, $now + 20)['n'] === 0, 'another account is not affected');
sec_test(coreui_abuse_count($pdo, $key, 900, 0, $now + 901)['n'] === 0, 'the block ends after the window');

// --- Password reset: rate limit, type guard, per-account code budget, atomic attempts ------------------
$forgot = $pos("if (\$action === 'forgot') {");
sec_test($pos("stu_rate_limit(\$action, 5, 300);", $forgot) < $pos('$body = stu_read_json_body();', $forgot), 'forgot is rate limited');
$pos("\$email = strtolower(trim(is_string(\$body['email'] ?? null) ? \$body['email'] : ''));", $forgot);
$pos("'reset_send_hour', (string)\$uid), 3600, 0)['n'] >= COREUI_AUTH_RESET_CODES_PER_HOUR", $forgot);
sec_test(strpos($auth, "(time() - strtotime((string)\$last)) < 60") === false, 'the throttle no longer compares the database clock with the PHP clock');
$reset = $pos("if (\$action === 'reset') {");
$claim = $pos("SET attempts = attempts + 1 WHERE id = ? AND attempts < ?", $reset);
$verify = $pos('$ok = password_verify($code,', $reset);
sec_test($claim < $verify && $pos('if ($claim->rowCount() !== 1)', $claim) < $verify, 'an attempt is claimed atomically before the code is checked');
sec_test(strpos($auth, "\$email = strtolower(trim((string)(\$body['email'] ?? '')));") === false
  && strpos($auth, "\$kind = is_string(\$body['method'] ?? null) ? \$body['method'] : '';") !== false, 'JSON arrays in body fields are no TypeError');
// The atomic claim on SQLite: five claims succeed, the sixth does not.
$pdo->exec('CREATE TABLE stu_password_resets (id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('INSERT INTO stu_password_resets (id, attempts) VALUES (1, 0)');
$granted = 0;
for ($i = 0; $i < 7; $i++) {
  $st = $pdo->prepare('UPDATE stu_password_resets SET attempts = attempts + 1 WHERE id = ? AND attempts < ?');
  $st->execute([1, 5]);
  $granted += $st->rowCount();
}
sec_test($granted === 5, 'exactly five attempts per code: ' . $granted);

// --- Attachments: an Office zip bomb stays within memory -------------------------------------------
$probe = <<<'PHP'
<?php
require $argv[1] . '/api/ember_attachments.php';
$zipPath = $argv[2];
$t = ember_attach_read_ooxml($zipPath, 'docx', 4000);
echo is_string($t) ? 'len=' . mb_strlen($t, 'UTF-8') : 'null';
echo ' peak=' . memory_get_peak_usage(true);
PHP;
$tmp = sys_get_temp_dir() . '/coreui-sec-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/probe.php', $probe);
$zip = new ZipArchive();
$zip->open($tmp . '/bomb.docx', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$para = '<w:p><w:r><w:t>' . str_repeat('Lorem ipsum dolor sit amet. ', 40) . '</w:t></w:r></w:p>';
$zip->addFromString('word/document.xml', '<w:document><w:body>' . str_repeat($para, 70000) . '</w:body></w:document>');
$zip->close();
sec_test(filesize($tmp . '/bomb.docx') < 5 * 1024 * 1024, 'the test file is small on disk');
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg($tmp . '/probe.php') . ' '
  . escapeshellarg($root) . ' ' . escapeshellarg($tmp . '/bomb.docx') . ' 2>&1');
sec_test(preg_match('~^len=(\d+) peak=(\d+)$~', trim($out), $m) === 1 && (int)$m[1] > 0 && (int)$m[1] <= 4200,
  'about 78 MB of XML unpacked from a small docx gives capped text under 128M: ' . trim($out));
$zip->open($tmp . '/small.docx', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Hallo Welt</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$small = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/probe.php') . ' '
  . escapeshellarg($root) . ' ' . escapeshellarg($tmp . '/small.docx') . ' 2>&1');
sec_test(str_starts_with(trim($small), 'len=10 '), 'a normal document is read completely: ' . trim($small));
$att = (string)file_get_contents($root . '/api/ember_attachments.php');
sec_test(str_contains($att, "stream_get_contents(\$pipe, EMBER_ATTACH_EXTRACT_BYTES)") && str_contains($att, "escapeshellarg(\$timeout) . ' 60 '"),
  'pdftotext output is capped and time limited');
// Review 0.8.34: with popen disabled (the header of ember_attachments.php names such a server) a PDF is still read,
// through shell_exec and head -c; before, the call to the disabled popen() crashed every PDF turn.
if (trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '') {
  $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
    "<< /Length 44 >>\nstream\nBT /F1 18 Tf 20 100 Td (Hallo PDF) Tj ET\nendstream", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
  $pdf = "%PDF-1.4\n"; $offsets = [];
  foreach ($objs as $i => $o) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
  $xref = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
  foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
  $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
  file_put_contents($tmp . '/probe.pdf', $pdf);
  file_put_contents($tmp . '/pdfprobe.php', '<?php require $argv[1] . "/api/ember_attachments.php"; echo json_encode(ember_attach_read_pdf($argv[2], 1000));');
  foreach (['' => 'popen', '-d disable_functions=popen,proc_open' => 'shell_exec'] as $flag => $way) {
    $got = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $flag . ' ' . escapeshellarg($tmp . '/pdfprobe.php') . ' '
      . escapeshellarg($root) . ' ' . escapeshellarg($tmp . '/probe.pdf') . ' 2>&1');
    sec_test(str_contains($got, 'Hallo PDF'), 'a PDF is read through ' . $way . ': ' . trim($got));
  }
}
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

// --- Admin log tail: read from the end, never the whole file -----------------------------------------
$admin = (string)file_get_contents($root . '/api/admin.php');
sec_test(preg_match('~^function coreui_admin_tail\(.*?^}~ms', $admin, $fn) === 1, 'coreui_admin_tail exists');
eval($fn[0]);
$log = tempnam(sys_get_temp_dir(), 'coreui-log');
$lines = [];
for ($i = 1; $i <= 40000; $i++) $lines[] = 'Zeile ' . $i . ' ' . str_repeat('x', 120);
file_put_contents($log, implode("\n", $lines) . "\n");
$tail = coreui_admin_tail($log, 2000);
sec_test($tail === array_slice($lines, -2000) && coreui_admin_tail($log, 3) === array_slice($lines, -3), 'the newest lines, in order');
// A 40 MB log under a 32 MB memory limit: the old file() read died here.
$fh = fopen($log, 'wb');
for ($i = 1; $i <= 320000; $i++) fwrite($fh, 'Zeile ' . $i . ' ' . str_repeat('y', 120) . "\n");
fclose($fh);
$tailProbe = sys_get_temp_dir() . '/coreui-tail-' . getmypid() . '.php';
file_put_contents($tailProbe, "<?php\n" . $fn[0] . "\n\$t = coreui_admin_tail(\$argv[1], 2000);\necho count(\$t), ' ', end(\$t);\n");
$tailOut = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M ' . escapeshellarg($tailProbe) . ' ' . escapeshellarg($log) . ' 2>&1'));
unlink($tailProbe);
sec_test($tailOut === '2000 Zeile 320000 ' . str_repeat('y', 120), 'a 40 MB log is tailed under 32 MB of memory: ' . substr($tailOut, 0, 200));
// Very long lines: the byte cap stops the backwards read inside the first line, which is then dropped.
file_put_contents($log, str_repeat('A', 3 * 1024 * 1024) . "\n" . str_repeat('B', 3 * 1024 * 1024) . "\nC\n");
sec_test(coreui_admin_tail($log, 10) === [str_repeat('B', 3 * 1024 * 1024), 'C'], 'a line cut by the byte cap is not shown');
file_put_contents($log, "eins\nzwei");
sec_test(coreui_admin_tail($log, 10) === ['eins', 'zwei'], 'a short log without a final newline');
file_put_contents($log, '');
sec_test(coreui_admin_tail($log, 10) === [], 'an empty log');
unlink($log);

// --- Database error text is not sent to the browser ---------------------------------------------------
sec_test(stu_json_public_error(['ok' => false, 'error' => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'])['error'] === 'server_error'
  && stu_json_public_error(['ok' => false, 'error' => 'SQLSTATE[42S22]: Column not found: 1054 Unknown column'])['error'] === 'server_error',
  'SQLSTATE text becomes server_error');
sec_test(stu_json_public_error(['ok' => false, 'error' => 'missing_schema_004'])['error'] === 'missing_schema_004'
  && stu_json_public_error(['ok' => true, 'lines' => ['SQLSTATE[x]']]) === ['ok' => true, 'lines' => ['SQLSTATE[x]']]
  && stu_json_public_error('text') === 'text', 'error codes and other data stay unchanged');

$jsonOut = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($root . '/api/helpers.php', true)
  . '; stu_json(["ok" => false, "error" => "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"], 500);') . ' 2>/dev/null');
sec_test(str_contains($jsonOut, '"error":"server_error"') && !str_contains($jsonOut, 'SQLSTATE'), 'stu_json itself hides the text: ' . $jsonOut);

echo "CoreAI stabilization security: account limits for codes and resets, capped attachments, log tail, no database text in answers OK\n";
