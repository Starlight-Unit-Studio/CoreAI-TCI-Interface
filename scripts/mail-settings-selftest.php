<?php
declare(strict_types=1);
// CoreAI 0.6.82: E-Mail-Versand (SMTP) in Admin Core, offline.
//   php scripts/mail-settings-selftest.php
// What the Inhaber may enter (server, port, encryption, sender, local-only plain mode), the
// password sealed with AES-256-GCM (other key or damage: unreadable, never plain text in the
// database), never sent back to the page, kept only for the same server and user; the server
// configuration always wins; with SQLite: saving, keeping, removing; a real SMTP session against
// a local test server (headers, dot-stuffing, no login without encryption, 550 and refused
// connections as codes, a self-signed certificate is refused); readable error texts; the wiring.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/mailer.php';

function m_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Mail settings FAIL: {$why}\n"); exit(1); }
}
function m_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// --- What may be entered ----------------------------------------------------------------------
$base = ['host' => 'smtp.example.com', 'security' => 'starttls', 'port' => 0, 'user' => 'coreai@example.com',
  'pass' => 'geheim-123', 'from' => 'coreai@example.com', 'from_name' => ''];
$ok = coreui_mail_validate($base, null);
m_test($ok['port'] === 587 && $ok['from_name'] === 'CoreAI: TCI Interface' && $ok['pass'] === 'geheim-123', 'defaults: port 587, sender name');
m_test(coreui_mail_validate(['security' => 'tls'] + $base, null)['port'] === 465, 'SSL/TLS defaults to 465');
m_test(coreui_mail_validate(['host' => ' SMTP.Example.COM '] + $base, null)['host'] === 'smtp.example.com', 'host trimmed and lower case');
m_test(coreui_mail_validate(['host' => '[2001:db8::1]'] + $base, null)['host'] === '2001:db8::1', 'IPv6 without brackets');
foreach ([
  [['host' => 'https://smtp.example.com'], 'mail_host_invalid'],
  [['host' => 'smtp.example.com:587'], 'mail_host_invalid'],
  [['host' => 'smtp example.com'], 'mail_host_invalid'],
  [['host' => ''], 'mail_host_invalid'],
  [['port' => 70000], 'mail_port_invalid'],
  [['port' => -1], 'mail_port_invalid'],
  [['security' => 'ssl3'], 'mail_security_invalid'],
  [['from' => 'kein-absender'], 'mail_from_invalid'],
  [['from' => "a@example.com\r\nBcc: x@example.com"], 'mail_from_invalid'],
  [['from_name' => "Mara\r\nBcc: x@example.com"], 'mail_from_name_invalid'],
  [['from_name' => 'Mara <x@example.com>'], 'mail_from_name_invalid'],
  [['from_name' => str_repeat('x', 101)], 'mail_from_name_invalid'],
  [['user' => "a\nb"], 'mail_user_invalid'],
  [['pass' => "a\r\nb"], 'mail_pass_invalid'],
  [['pass' => str_repeat('x', 301)], 'mail_pass_invalid'],
  [['pass' => ''], 'mail_pass_required'],
  [['user' => ''], 'mail_user_required'],
  [['security' => 'none'], 'mail_plain_not_local'],
  [['security' => 'none', 'host' => 'localhost'], 'mail_plain_no_login'],
] as [$change, $code]) {
  m_test(m_code(static fn() => coreui_mail_validate($change + $base, null)) === $code, 'rejected: ' . $code . ' ' . json_encode($change));
}
// Without encryption: only this machine or the private network, and then without any login.
foreach (['localhost', '127.0.0.1', '::1', '10.0.0.5', '192.168.1.20', '172.17.0.1', 'host.docker.internal', 'mailpit', 'postfix', 'mail.lan', 'nas.local'] as $h) {
  m_test(coreui_mail_host_local($h), 'local: ' . $h);
  m_test(coreui_mail_validate(['security' => 'none', 'host' => $h, 'user' => '', 'pass' => ''] + $base, null)['port'] === 25, 'plain to ' . $h);
}
foreach (['smtp.example.com', '8.8.8.8', '2001:db8::1', 'mail.example.lan.evil.com'] as $h) {
  m_test(!coreui_mail_host_local($h), 'not local: ' . $h);
}
// Without a login (a relay that trusts this server).
$open = coreui_mail_validate(['user' => '', 'pass' => ''] + $base, null);
m_test($open['user'] === '' && $open['pass'] === '', 'no login is allowed with encryption');

// The stored password is kept only for the same server and the same user.
$current = ['host' => 'smtp.example.com', 'user' => 'coreai@example.com', 'pass' => 'alt-geheim'];
m_test(coreui_mail_validate(['pass' => ''] + $base, $current)['pass'] === 'alt-geheim', 'empty password keeps the stored one');
m_test(coreui_mail_validate(['pass' => 'neu'] + $base, $current)['pass'] === 'neu', 'a new password replaces it');
m_test(m_code(static fn() => coreui_mail_validate(['pass' => '', 'host' => 'smtp.other.example'] + $base, $current)) === 'mail_pass_required',
  'another server needs the password again');
m_test(m_code(static fn() => coreui_mail_validate(['pass' => '', 'user' => 'other@example.com'] + $base, $current)) === 'mail_pass_required',
  'another user needs the password again');

// --- The password at rest -----------------------------------------------------------------------
$salt = bin2hex(random_bytes(16));
$sealed = coreui_mail_seal('geheim-123 äöü', $salt);
m_test(str_starts_with($sealed, 'v1:') && !str_contains($sealed, 'geheim') && !str_contains(base64_decode(substr($sealed, 3)), 'geheim'),
  'sealed: no plain text');
m_test(coreui_mail_open($sealed, $salt) === 'geheim-123 äöü', 'sealed password opens again');
m_test(coreui_mail_seal('geheim-123 äöü', $salt) !== $sealed, 'every seal has its own nonce');
m_test(coreui_mail_open($sealed, bin2hex(random_bytes(16))) === null, 'another key cannot open it');
$raw = base64_decode(substr($sealed, 3));
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
m_test(coreui_mail_open('v1:' . base64_encode($raw), $salt) === null, 'a changed byte is noticed (GCM tag)');
m_test(coreui_mail_open('klartext', $salt) === null && coreui_mail_open('', $salt) === '', 'no plain text accepted; empty stays empty');
m_test(coreui_mail_key('a') !== coreui_mail_key('b') && strlen(coreui_mail_key('a')) === 32, 'key: 32 bytes per salt');

// --- What the page gets ---------------------------------------------------------------------------
$public = coreui_mail_public(['source' => 'db', 'host' => 'smtp.example.com', 'port' => 587, 'security' => 'starttls',
  'user' => 'coreai@example.com', 'pass' => 'geheim-123', 'pass_readable' => true, 'from' => 'coreai@example.com',
  'from_name' => 'CoreAI: TCI Interface', 'updated_at' => '2026-11-09T10:00:00+00:00']);
m_test(!array_key_exists('pass', $public) && !str_contains((string)json_encode($public), 'geheim'), 'the password never goes to the page');
m_test($public['has_password'] === true && $public['ready'] === true && $public['password_unreadable'] === false, 'public flags');
m_test(coreui_mail_public(null) === ['source' => 'none', 'ready' => false], 'nothing set up');
m_test(!coreui_mail_ready(['pass_readable' => false] + ['source' => 'db', 'host' => 'h', 'port' => 587, 'security' => 'starttls', 'user' => 'u', 'pass' => '', 'from' => 'a@b.de']),
  'unreadable password: not ready');
m_test(!coreui_mail_ready(['source' => 'db', 'host' => 'h', 'port' => 587, 'security' => 'starttls', 'user' => 'u', 'pass' => '', 'pass_readable' => true, 'from' => 'a@b.de']),
  'user without password: not ready');
m_test(!coreui_mail_ready(['source' => 'db', 'host' => 'h', 'port' => 587, 'security' => 'starttls', 'user' => '', 'pass' => '', 'pass_readable' => true, 'from' => 'kein']),
  'no valid sender: not ready');

// --- Readable errors ------------------------------------------------------------------------------
foreach ([
  'smtp_unexpected_535' => 'Anmeldung abgelehnt', 'smtp_unexpected_530' => 'Anmeldung abgelehnt',
  'smtp_unexpected_550' => 'Absender oder Empfänger abgelehnt', 'smtp_unexpected_421' => '(421)',
  'smtp_connect_failed' => 'Keine Verbindung', 'smtp_tls_failed' => 'verschlüsselte Verbindung',
  'smtp_no_response' => 'nicht geantwortet', 'mail_password_unreadable' => 'nicht mehr lesbar', 'irgendwas' => 'konnte nicht gesendet',
] as $code => $part) {
  m_test(str_contains(coreui_mail_error_text($code), $part), 'error text for ' . $code);
}

// --- SQLite: saving, keeping, removing --------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$envLocked = coreui_mail_env_config() !== null;
if ($envLocked) {
  // This server sets SMTP itself (api/config.local.php or COREUI_SMTP_*): Admin Core may not change it.
  m_test(m_code(static fn() => coreui_mail_save($pdo, $base)) === 'mail_env_locked', 'server configuration: saving refused');
  m_test(m_code(static fn() => coreui_mail_delete($pdo)) === 'mail_env_locked', 'server configuration: removing refused');
  m_test(coreui_mail_effective($pdo)['source'] === 'env', 'server configuration is used');
} else {
  m_test(coreui_mail_effective($pdo) === null && !stu_mail_enabled($pdo), 'nothing stored: no mail');
  $saved = coreui_mail_save($pdo, $base);
  m_test($saved['source'] === 'db' && $saved['pass'] === 'geheim-123' && $saved['pass_readable'], 'saved and read back');
  $rawRow = (string)$pdo->query("SELECT value FROM stu_app_settings WHERE k = 'mail_smtp'")->fetchColumn();
  m_test(!str_contains($rawRow, 'geheim') && str_contains($rawRow, '"pass":"v1:'), 'database row: password sealed');
  m_test(stu_mail_enabled($pdo) && coreui_mail_effective($pdo)['host'] === 'smtp.example.com', 'mail available from Admin Core');
  $kept = coreui_mail_save($pdo, ['pass' => '', 'from_name' => 'Mara'] + $base);
  m_test($kept['pass'] === 'geheim-123' && $kept['from_name'] === 'Mara', 'saving again without password keeps it');
  m_test(m_code(static fn() => coreui_mail_save($pdo, ['pass' => '', 'host' => 'smtp.other.example'] + $base)) === 'mail_pass_required',
    'moving to another server needs the password');
  m_test(coreui_mail_effective($pdo)['host'] === 'smtp.example.com', 'a refused save changes nothing');
  // The database password of the server changed: the stored password cannot be opened any more.
  $row = json_decode($rawRow, true);
  $row['salt'] = bin2hex(random_bytes(16));
  coreui_mail_db_put($pdo, 'mail_smtp', (string)json_encode($row));
  $broken = coreui_mail_effective($pdo);
  m_test($broken['pass_readable'] === false && !coreui_mail_ready($broken) && coreui_mail_public($broken)['password_unreadable'] === true,
    'unreadable password is reported');
  m_test(m_code(static fn() => stu_send_mail('owner@example.test', 'x', 'x', $broken)) === 'mail_password_unreadable', 'and not sent');
  m_test(m_code(static fn() => coreui_mail_save($pdo, ['pass' => ''] + $base)) === 'mail_pass_required', 'it must be entered again');
  m_test(coreui_mail_save($pdo, ['pass' => 'neu-456'] + $base)['pass'] === 'neu-456', 'entered again');
  coreui_mail_delete($pdo);
  m_test(coreui_mail_effective($pdo) === null && !stu_mail_enabled($pdo), 'removed');
  coreui_mail_db_put($pdo, 'mail_smtp', '{kaputt');
  m_test(coreui_mail_effective($pdo) === null, 'a damaged row counts as nothing');
}

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database): the MySQL statements, on a key of its own --
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  $key = 'mail_selftest_' . bin2hex(random_bytes(4));
  try {
    coreui_mail_db_put($my, $key, 'eins');
    coreui_mail_db_put($my, $key, 'zwei');
    m_test(coreui_mail_db_get($my, $key) === 'zwei', 'MariaDB: ON DUPLICATE KEY UPDATE');
    $sealedMy = coreui_mail_seal(str_repeat('ä', 150), $salt);
    coreui_mail_db_put($my, $key, (string)json_encode(['pass' => $sealedMy, 'salt' => $salt]));
    $back = json_decode((string)coreui_mail_db_get($my, $key), true);
    m_test(coreui_mail_open((string)$back['pass'], (string)$back['salt']) === str_repeat('ä', 150), 'MariaDB: sealed password survives the column');
    coreui_mail_db_put($my, $key, null);
    m_test(coreui_mail_db_get($my, $key) === null, 'MariaDB: removed');
    $dbChecked = true;
  } finally {
    $my->prepare('DELETE FROM stu_app_settings WHERE `k` = ?')->execute([$key]);
  }
}

// The server configuration wins (a separate PHP process with COREUI_SMTP_* set).
$probe = 'require ' . var_export($root . '/api/mail_settings.php', true) . '; echo json_encode(coreui_mail_public(coreui_mail_env_config()));';
$env = ['COREUI_SMTP_HOST' => 'smtp.server.example', 'COREUI_SMTP_PORT' => '465', 'COREUI_SMTP_USER' => 'srv',
  'COREUI_SMTP_PASS' => 'server-geheim', 'COREUI_MAIL_FROM' => 'srv@example.com', 'PATH' => (string)getenv('PATH')];
$proc = proc_open([PHP_BINARY, '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
m_test(is_resource($proc), 'probe process');
$out = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
$probeResult = json_decode($out, true);
if (!$envLocked || (is_array($probeResult) && ($probeResult['host'] ?? '') === 'smtp.server.example')) {
  m_test(is_array($probeResult) && $probeResult['source'] === 'env' && $probeResult['host'] === 'smtp.server.example'
    && $probeResult['security'] === 'tls' && $probeResult['ready'] === true && !str_contains($out, 'server-geheim'),
    'COREUI_SMTP_*: used, 465 means SSL/TLS, password not shown');
}
$env['COREUI_SMTP_SECURITY'] = 'none';
$env['COREUI_SMTP_PORT'] = '25';
$proc = proc_open([PHP_BINARY, '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
$out = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
$probeResult = json_decode($out, true);
if (!$envLocked || (is_array($probeResult) && ($probeResult['host'] ?? '') === 'smtp.server.example')) {
  m_test(is_array($probeResult) && $probeResult['security'] === 'starttls', 'COREUI_SMTP_SECURITY=none to a public server becomes STARTTLS');
}

// --- A real SMTP session against a local test server --------------------------------------------------
// The server answers one session and writes what it received to a file. $mode: ok, reject_rcpt, tls.
function m_smtp_server(string $mode, string $log): array {
  $code = <<<'PHP'
[$mode, $log] = [$argv[1], $argv[2]];
$ctx = stream_context_create();
$scheme = 'tcp';
if ($mode === 'tls') {
  $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
  $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
  $crt = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
  openssl_x509_export($crt, $pemCrt); openssl_pkey_export($key, $pemKey);
  $pem = sys_get_temp_dir() . '/coreai-mail-selftest-' . getmypid() . '.pem';
  file_put_contents($pem, $pemCrt . $pemKey);
  $ctx = stream_context_create(['ssl' => ['local_cert' => $pem]]);
  $scheme = 'ssl';
}
$srv = stream_socket_server("$scheme://127.0.0.1:0", $no, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
$name = stream_socket_get_name($srv, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);
$c = @stream_socket_accept($srv, 15);
if (isset($pem)) @unlink($pem);
if (!$c) exit(0);
stream_set_timeout($c, 10);
$seen = '';
$w = static function (string $line) use ($c): void { fwrite($c, $line . "\r\n"); };
$w('220 coreai-selftest ESMTP');
$inData = false;
while (($line = fgets($c, 8192)) !== false) {
  $seen .= $line;
  if ($inData) {
    if ($line === ".\r\n") { $inData = false; $w('250 queued'); }
    continue;
  }
  $cmd = strtoupper(substr(trim($line), 0, 4));
  if ($cmd === 'EHLO') { $w('250-coreai-selftest'); $w('250 8BITMIME'); }
  elseif ($cmd === 'MAIL') $w('250 ok');
  elseif ($cmd === 'RCPT') $w($mode === 'reject_rcpt' ? '550 no such user' : '250 ok');
  elseif ($cmd === 'DATA') { $w('354 go'); $inData = true; }
  elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
  else $w('502 not here');
}
file_put_contents($log, $seen);
PHP;
  $proc = proc_open([PHP_BINARY, '-r', $code, '--', $mode, $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  m_test(is_resource($proc), 'test SMTP server starts');
  $port = (int)trim((string)fgets($pipes[1]));
  m_test($port > 0, 'test SMTP server listens');
  return [$proc, $pipes, $port];
}
function m_smtp_stop(array $server): void {
  [$proc, $pipes] = $server;
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($proc);
}

$log = sys_get_temp_dir() . '/coreai-mail-selftest-' . bin2hex(random_bytes(4)) . '.log';
$cfg = ['source' => 'db', 'host' => '127.0.0.1', 'port' => 0, 'security' => 'none', 'user' => '', 'pass' => '',
  'pass_readable' => true, 'from' => 'coreai@example.test', 'from_name' => 'CoreAI: TCI Interface'];
$server = m_smtp_server('ok', $log);
stu_send_mail('owner@example.test', 'Mara hat geantwortet ✓', "Erste Zeile\n.Zeile mit Punkt\nLetzte Zeile", ['port' => $server[2]] + $cfg);
m_smtp_stop($server);
$seen = (string)@file_get_contents($log);
@unlink($log);
m_test(str_starts_with($seen, "EHLO stu\r\nMAIL FROM:<coreai@example.test>\r\nRCPT TO:<owner@example.test>\r\nDATA\r\n"), 'SMTP dialogue: EHLO, MAIL, RCPT, DATA');
m_test(!str_contains($seen, 'AUTH') && !str_contains($seen, 'STARTTLS'), 'no login and no STARTTLS without encryption');
m_test(str_contains($seen, "From: \"CoreAI: TCI Interface\" <coreai@example.test>\r\n") && str_contains($seen, "To: <owner@example.test>\r\n"), 'From and To');
m_test(str_contains($seen, 'Subject: =?UTF-8?B?' . base64_encode('Mara hat geantwortet ✓') . "?=\r\n"), 'subject encoded (RFC 2047)');
m_test((bool)preg_match('~\r\nDate: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} \+0000\r\n~', $seen)
  && (bool)preg_match('~\r\nMessage-ID: <[0-9a-f]{24}@example\.test>\r\n~', $seen), 'Date and Message-ID');
m_test(str_contains($seen, "\r\n\r\nErste Zeile\r\n..Zeile mit Punkt\r\nLetzte Zeile\r\n.\r\nQUIT\r\n"), 'CRLF, dot-stuffing, end of data');
m_test(!preg_match("~[^\r]\n~", $seen), 'only CRLF line endings');

$server = m_smtp_server('reject_rcpt', $log);
m_test(m_code(static fn() => stu_send_mail('nobody@example.test', 'x', 'x', ['port' => $server[2]] + $cfg)) === 'smtp_unexpected_550', '550 becomes a code');
m_smtp_stop($server);
@unlink($log);
m_test(m_code(static fn() => stu_send_mail('owner@example.test', 'x', 'x', ['port' => $port = (static function (): int {
  $s = stream_socket_server('tcp://127.0.0.1:0'); $n = stream_socket_get_name($s, false); fclose($s); return (int)substr($n, strrpos($n, ':') + 1);
})()] + $cfg)) === 'smtp_connect_failed', 'nobody listening: smtp_connect_failed');
m_test(m_code(static fn() => stu_send_mail("owner@example.test\r\nBcc: x@example.test", 'x', 'x', ['port' => 25] + $cfg)) === 'mail_recipient_invalid', 'recipient checked');
m_test(m_code(static fn() => stu_send_mail('owner@example.test', 'x', 'x', ['from' => 'kein'] + $cfg)) === 'mail_not_configured', 'incomplete: not sent');

$tlsChecked = false;
if (function_exists('openssl_pkey_new')) {
  $server = m_smtp_server('tls', $log);
  $code = m_code(static fn() => stu_send_mail('owner@example.test', 'x', 'x', ['security' => 'tls', 'port' => $server[2]] + $cfg));
  m_smtp_stop($server);
  @unlink($log);
  m_test($code === 'smtp_tls_failed', 'SSL/TLS: a self-signed certificate is refused (got ' . $code . ')');
  $tlsChecked = true;
}

// --- Wiring ---------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$admin = $read('api/admin.php');
m_test(str_contains($admin, "require_once __DIR__ . '/mailer.php';") && str_contains($admin, "if (\$action === 'mail_settings') {")
  && str_contains($admin, "if (\$action === 'mail_settings_save' || \$action === 'mail_settings_delete' || \$action === 'mail_test') {"), 'admin.php actions');
m_test(substr_count($admin, "if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);\n    stu_json(coreui_admin_mail_state(") === 1
  && str_contains($admin, "|| \$action === 'mail_test') {\n  if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);"), 'admin.php: Inhaber only');
m_test(!preg_match("~'mail_settings_save'[^\n]*'pass'~", $admin) && str_contains($admin, "'login' => (\$saved['user'] ?? '') !== ''"), 'audit without the password');
$store = $read('api/notifications_store.php');
m_test(str_contains($store, 'stu_mail_enabled($pdo)') && str_contains($store, 'coreui_mail_effective($pdo)'), 'notifications use the Admin Core configuration');
$page = $read('admin/index.html');
m_test(str_contains($page, 'id="mailSmtpCard"') && (bool)preg_match('~<script src="\.\./js/coreui-admin-mail\.js\?v=\d{25}"></script>~', $page), 'Admin Core card and script');
m_test(str_contains($read('settings.html'), 'href="admin/index.html#admin-betrieb"'), 'Benachrichtigungen links to Admin Core');
m_test(str_contains($read('api/config.php'), "coreui_define('STU_SMTP_SECURITY', coreui_env('COREUI_SMTP_SECURITY'));"), 'COREUI_SMTP_SECURITY');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $f) {
  $pre = $read($f);
  foreach (['api/mail_settings.php', 'js/coreui-admin-mail.js', 'scripts/mail-settings-selftest.php', 'scripts/admin-mail-ui-selftest.cjs'] as $need) {
    m_test(str_contains($pre, $need), $f . ' lists ' . $need);
  }
}

echo "CoreAI mail settings: input checks (local-only plain mode), password sealed (AES-256-GCM) and never shown, kept only for the same "
  . "server and user, server configuration wins, " . ($envLocked ? 'server SMTP set: Admin Core locked, ' : 'saving and removing, ')
  . "real SMTP session (headers, dot-stuffing, 550, refused connection)" . ($tlsChecked ? ', self-signed certificate refused' : '')
  . ", error texts, wiring OK" . ($dbChecked ? ', MariaDB statements OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
