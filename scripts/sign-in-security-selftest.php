<?php
declare(strict_types=1);
// CoreAI 0.6.85: Settings > Sicherheit und Anmeldung and the sign-in, offline.
//   php scripts/sign-in-security-selftest.php
// One-time codes against RFC 6238 Appendix B and RFC 4226 Appendix D (drift, no replay), sealed
// secrets bound to their purpose, WebAuthn with a software authenticator (ES256, RS256, Ed25519:
// registration and sign-in; wrong origin, challenge, RP, signature, counter, missing user presence
// and bad CBOR refused), and the store with SQLite: lockdown, history with older sign-ins, the
// authenticator app (once per step), codes by mail (expiry, tries), recovery codes (once each),
// passkeys (limit, the last one kept for advanced security), advanced account security (needs a
// passkey, 24-hour sessions), the server name for passkeys; the wiring of the sign-in, the page and
// the Lockdown-Modus. With COREUI_SELFTEST_DB=1 also on MariaDB (migration 030).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/security_store.php';

function s_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Sign-in security FAIL: {$why}\n"); exit(1); }
}
function s_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// --- One-time codes -----------------------------------------------------------------------------------
$k1 = '12345678901234567890';
$k256 = '12345678901234567890123456789012';
$k512 = str_repeat('1234567890', 6) . '1234';
foreach ([[59, '94287082', '46119246', '90693936'], [1111111109, '07081804', '68084774', '25091201'], [1111111111, '14050471', '67062674', '99943326'],
  [1234567890, '89005924', '91819424', '93441116'], [2000000000, '69279037', '90698825', '38618901'], [20000000000, '65353130', '77737706', '47863826']] as [$t, $a, $b, $c]) {
  s_test(coreui_totp_at($k1, $t, 8, 'sha1') === $a && coreui_totp_at($k256, $t, 8, 'sha256') === $b && coreui_totp_at($k512, $t, 8, 'sha512') === $c, "RFC 6238 at $t");
}
foreach (['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'] as $i => $v) {
  s_test(coreui_hotp($k1, $i) === $v, "RFC 4226 count $i");
}
s_test(coreui_base32_encode('foobar') === 'MZXW6YTBOI' && coreui_base32_decode('mzxw 6ytb-oi') === 'foobar' && coreui_base32_decode('M1') === null, 'base32');
$key = random_bytes(20);
s_test(coreui_base32_decode(coreui_base32_encode($key)) === $key, 'base32 round trip');
$now = 1_800_000_000;
$code = coreui_totp_at($key, $now);
$step = intdiv($now, 30);
s_test(coreui_totp_verify($key, $code, $now) === $step && coreui_totp_verify($key, $code, $now + 30) === $step
  && coreui_totp_verify($key, $code, $now - 30) === $step, 'one step of drift either way');
s_test(coreui_totp_verify($key, $code, $now + 90) === null && coreui_totp_verify($key, $code, $now, $step) === null
  && coreui_totp_verify($key, 'abcdef', $now) === null && coreui_totp_verify($key, '12345', $now) === null, 'too late, replay, not a code');
$uri = coreui_totp_uri('JBSWY3DPEHPK3PXP', 'lena@example.test');
s_test($uri === 'otpauth://totp/CoreAI:lena%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=CoreAI&algorithm=SHA1&digits=6&period=30', 'otpauth link');

// --- Sealed secrets --------------------------------------------------------------------------------------
$sealed = coreui_secret_seal('geheim', 'totp');
s_test(coreui_secret_open($sealed, 'totp') === 'geheim' && coreui_secret_open($sealed, 'mail') === null
  && coreui_secret_open('klartext', 'totp') === null && !str_contains($sealed, 'geheim'), 'sealed, bound to the purpose');
s_test(coreui_secret_seal('geheim', 'totp') !== $sealed, 'own salt and nonce each time');

// --- WebAuthn with a software authenticator ------------------------------------------------------------------
function ce_head(int $major, int $n): string {
  if ($n < 24) return chr(($major << 5) | $n);
  if ($n < 256) return chr(($major << 5) | 24) . chr($n);
  if ($n < 65536) return chr(($major << 5) | 25) . pack('n', $n);
  return chr(($major << 5) | 26) . pack('N', $n);
}
function ce_int(int $v): string { return $v >= 0 ? ce_head(0, $v) : ce_head(1, -1 - $v); }
function ce_bytes(string $b): string { return ce_head(2, strlen($b)) . $b; }
function ce_text(string $s): string { return ce_head(3, strlen($s)) . $s; }
function ce_map(array $pairs): string { $o = ce_head(5, count($pairs)); foreach ($pairs as [$k, $v]) $o .= $k . $v; return $o; }

$rp = 'coreai.example';
$origin = 'https://coreai.example';
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$ecd = openssl_pkey_get_details($ec);
$cose = ce_map([[ce_int(1), ce_int(2)], [ce_int(3), ce_int(-7)], [ce_int(-1), ce_int(1)],
  [ce_int(-2), ce_bytes(str_pad($ecd['ec']['x'], 32, "\0", STR_PAD_LEFT))], [ce_int(-3), ce_bytes(str_pad($ecd['ec']['y'], 32, "\0", STR_PAD_LEFT))]]);
$credId = random_bytes(32);
$registration = static function (string $challenge, string $flags = "\x45", string $originUsed = 'https://coreai.example', string $rpUsed = 'coreai.example') use ($cose, $credId): array {
  $authData = hash('sha256', $rpUsed, true) . $flags . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cose;
  $att = ce_map([[ce_text('fmt'), ce_text('none')], [ce_text('attStmt'), ce_map([])], [ce_text('authData'), ce_bytes($authData)]]);
  $cd = json_encode(['type' => 'webauthn.create', 'challenge' => coreui_b64u($challenge), 'origin' => $originUsed, 'crossOrigin' => false]);
  return ['clientDataJSON' => coreui_b64u($cd), 'attestationObject' => coreui_b64u($att)];
};
$chal = random_bytes(32);
$reg = coreui_webauthn_register_verify($registration($chal), $chal, $rp);
s_test($reg['credential_id'] === coreui_b64u($credId) && json_decode($reg['public_key'], true)['alg'] === -7, 'registration (ES256)');
foreach ([
  [fn() => coreui_webauthn_register_verify($registration($chal, "\x45", 'https://evil.example'), $chal, $rp), 'webauthn_origin'],
  [fn() => coreui_webauthn_register_verify($registration($chal, "\x45", 'http://coreai.example'), $chal, $rp), 'webauthn_origin'],
  [fn() => coreui_webauthn_register_verify($registration($chal), random_bytes(32), $rp), 'webauthn_challenge'],
  [fn() => coreui_webauthn_register_verify($registration($chal, "\x45", 'https://coreai.example', 'evil.example'), $chal, $rp), 'webauthn_rp_id'],
  [fn() => coreui_webauthn_register_verify($registration($chal, "\x44"), $chal, $rp), 'webauthn_user_presence'],
  [fn() => coreui_webauthn_register_verify(['clientDataJSON' => 'x', 'attestationObject' => 'not base64!'], $chal, $rp), 'webauthn_input'],
] as $i => [$fn, $err]) {
  s_test(s_code($fn) === $err, "registration refused ($i): $err got " . s_code($fn));
}
s_test(s_code(fn() => coreui_cbor_decode("\x9f\x01\xff")) === 'cbor_length', 'indefinite CBOR refused');
s_test(s_code(fn() => coreui_cbor_decode(str_repeat("\x81", 40) . "\x01")) === 'cbor_too_deep', 'CBOR depth limit');
s_test(coreui_webauthn_origin_ok('http://localhost:8080', 'localhost') && !coreui_webauthn_origin_ok('https://x.coreai.example', $rp)
  && !coreui_webauthn_origin_ok('https://coreai.example/path', $rp), 'origin rules');

$assertion = static function (string $challenge, int $count, $signer, int $algo = OPENSSL_ALGO_SHA256, string $flags = "\x05") use ($rp): array {
  $ad = hash('sha256', $rp, true) . $flags . pack('N', $count);
  $cd = json_encode(['type' => 'webauthn.get', 'challenge' => coreui_b64u($challenge), 'origin' => 'https://coreai.example']);
  if (is_callable($signer)) $sig = $signer($ad . hash('sha256', $cd, true));
  else openssl_sign($ad . hash('sha256', $cd, true), $sig, $signer, $algo);
  return ['clientDataJSON' => coreui_b64u($cd), 'authenticatorData' => coreui_b64u($ad), 'signature' => coreui_b64u($sig)];
};
$chal2 = random_bytes(32);
$stored = ['public_key' => $reg['public_key'], 'sign_count' => 0];
s_test(coreui_webauthn_assert_verify($assertion($chal2, 5, $ec), $chal2, $rp, $stored) === 5, 'sign-in (ES256)');
s_test(coreui_webauthn_assert_verify($assertion($chal2, 0, $ec), $chal2, $rp, $stored) === 0, 'passkey without counter (0/0)');
s_test(s_code(fn() => coreui_webauthn_assert_verify($assertion($chal2, 5, $ec), $chal2, $rp, ['sign_count' => 5] + $stored)) === 'webauthn_counter', 'copied authenticator (counter)');
$other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
s_test(s_code(fn() => coreui_webauthn_assert_verify($assertion($chal2, 6, $other), $chal2, $rp, $stored)) === 'webauthn_signature', 'another key refused');
s_test(s_code(fn() => coreui_webauthn_assert_verify($assertion($chal2, 6, $ec, OPENSSL_ALGO_SHA256, "\x04"), $chal2, $rp, $stored)) === 'webauthn_user_presence', 'user presence required');
s_test(s_code(fn() => coreui_webauthn_assert_verify($assertion(random_bytes(32), 6, $ec), $chal2, $rp, $stored)) === 'webauthn_challenge', 'old challenge refused');
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$rsad = openssl_pkey_get_details($rsa);
$rsaKey = json_encode(coreui_cose_key([1 => 3, 3 => -257, -1 => $rsad['rsa']['n'], -2 => $rsad['rsa']['e']]));
s_test(coreui_webauthn_assert_verify($assertion($chal2, 1, $rsa), $chal2, $rp, ['public_key' => $rsaKey, 'sign_count' => 0]) === 1, 'sign-in (RS256)');
$edChecked = false;
if (function_exists('sodium_crypto_sign_keypair')) {
  $kp = sodium_crypto_sign_keypair();
  $edKey = json_encode(coreui_cose_key([1 => 1, 3 => -8, -1 => 6, -2 => sodium_crypto_sign_publickey($kp)]));
  $signer = static fn(string $data): string => sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($kp));
  s_test(coreui_webauthn_assert_verify($assertion($chal2, 3, $signer), $chal2, $rp, ['public_key' => $edKey, 'sign_count' => 0]) === 3, 'sign-in (Ed25519)');
  $edChecked = true;
}
s_test(s_code(fn() => coreui_cose_key([1 => 2, 3 => -36])) === 'webauthn_alg_unsupported', 'unknown algorithm refused');

// --- Store with SQLite --------------------------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
  'CREATE TABLE stu_users (id INTEGER PRIMARY KEY, username TEXT, is_guest INT DEFAULT 0)',
  'CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT)',
  'CREATE TABLE stu_auth_sessions (id INTEGER PRIMARY KEY, user_id INT, device_label TEXT, created_at TEXT, revoked_at TEXT, revoked_reason TEXT)',
  'CREATE TABLE stu_user_security (user_id INTEGER PRIMARY KEY, lockdown INT DEFAULT 0, advanced_at TEXT, totp_secret TEXT, totp_enabled_at TEXT, '
    . 'totp_last_step INT DEFAULT 0, email_mfa_at TEXT, recovery_codes TEXT, webauthn_handle TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
  'CREATE TABLE stu_passkeys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, credential_id TEXT UNIQUE, rp_id TEXT, public_key TEXT, '
    . 'sign_count INT DEFAULT 0, label TEXT, created_at TEXT, last_used_at TEXT)',
  'CREATE TABLE stu_security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, device_label TEXT, detail TEXT, created_at TEXT)',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO stu_users (id, username) VALUES (2, 'lena@example.test'), (3, 'tom@example.test')");
s_test(coreui_security_ready($pdo), 'migration 030 seen');
s_test(coreui_security_methods($pdo, 2) === ['ready' => true, 'totp' => false, 'email' => false, 'passkeys' => 0, 'recovery_left' => 0,
  'advanced' => false, 'lockdown' => false], 'nothing set up');
s_test(!coreui_security_mfa_required(coreui_security_methods($pdo, 2)), 'no second step without a factor');

// Lockdown and the history.
coreui_security_set_lockdown($pdo, 2, true, 'Chrome auf Android');
s_test(coreui_security_lockdown($pdo, 2) && !coreui_security_lockdown($pdo, 3), 'lockdown per account');
coreui_security_set_lockdown($pdo, 2, false);
$pdo->exec("INSERT INTO stu_app_settings VALUES ('security_events_since', '2026-11-09 12:00:00', '')");
$pdo->exec("INSERT INTO stu_auth_sessions (user_id, device_label, created_at, revoked_at, revoked_reason) VALUES "
  . "(2, 'Firefox auf Linux', '2026-11-01 08:00:00', '2026-11-02 09:00:00', 'user_revoked'), "
  . "(2, 'Safari auf iPhone', '2026-11-03 08:00:00', '2026-11-03 09:00:00', 'token_rotated'), "
  . "(2, 'Neu', '2026-11-09 13:00:00', NULL, ''), (3, 'Fremd', '2026-11-01 08:00:00', NULL, '')");
coreui_security_event($pdo, 2, 'login_failed', "Chrome\nauf <b>Android</b>", 'Passwort');
coreui_security_event($pdo, 2, 'not_a_kind');
$history = coreui_security_history($pdo, 2);
$kinds = array_column($history, 'kind');
s_test(count($history) === 6 && in_array('lockdown_on', $kinds, true) && in_array('lockdown_off', $kinds, true)
  && count(array_filter($kinds, fn($k) => $k === 'login')) === 2 && count(array_filter($kinds, fn($k) => $k === 'logout')) === 1,
  'history: own events and older sign-ins (token rotation and newer sessions not doubled): ' . json_encode($kinds));
$failed = array_values(array_filter($history, fn($e) => $e['kind'] === 'login_failed'))[0];
s_test($failed['label'] === 'Fehlgeschlagene Anmeldung' && $failed['device'] === 'Chromeauf bAndroid/b' && $failed['detail'] === 'Passwort',
  'history text cleaned: ' . json_encode($failed));
s_test(!str_contains((string)json_encode($history), 'Fremd'), 'no other account in the history');

// Authenticator app.
$totpKey = random_bytes(20);
s_test(!coreui_security_totp_enable($pdo, 2, $totpKey, '000000', $now) || coreui_totp_at($totpKey, $now) === '000000', 'wrong first code refused');
s_test(coreui_security_totp_enable($pdo, 2, $totpKey, coreui_totp_at($totpKey, $now), $now), 'enabled with the first code');
$row = coreui_security_row($pdo, 2);
s_test(!str_contains((string)$row['totp_secret'], coreui_base32_encode($totpKey)) && coreui_security_totp_key($pdo, 2) === $totpKey, 'secret sealed at rest');
s_test(!coreui_security_totp_check($pdo, 2, coreui_totp_at($totpKey, $now), $now), 'the setup code is not accepted again');
s_test(coreui_security_totp_check($pdo, 2, coreui_totp_at($totpKey, $now + 30), $now + 30), 'next code accepted');
s_test(!coreui_security_totp_check($pdo, 2, coreui_totp_at($totpKey, $now + 30), $now + 31), 'each code once');
s_test(coreui_security_mfa_required(coreui_security_methods($pdo, 2)), 'second step required');

// Codes by mail.
$held = coreui_security_code_hold('123456', $now);
s_test(!str_contains((string)json_encode($held), '123456'), 'only the hash is held');
s_test(coreui_security_code_check($held, '654321', $now) === 'wrong' && coreui_security_code_check($held, '123456', $now) === 'ok', 'code checked');
$held = coreui_security_code_hold('123456', $now);
s_test(coreui_security_code_check($held, '123456', $now + 601) === 'expired', 'after 10 minutes expired');
$held = coreui_security_code_hold('123456', $now);
for ($i = 0; $i < 5; $i++) coreui_security_code_check($held, '000000', $now);
s_test(coreui_security_code_check($held, '123456', $now) === 'locked', 'five wrong tries lock the code');
s_test(coreui_security_mask_email('lena@example.test') === 'l•••@example.test', 'masked address');

// Recovery codes.
$codes = coreui_security_recovery_new($pdo, 2);
s_test(count($codes) === 10 && count(array_unique($codes)) === 10 && preg_match('~^[a-z2-9]{5}-[a-z2-9]{5}$~', $codes[0]) === 1, 'ten codes');
s_test(!str_contains((string)coreui_security_row($pdo, 2)['recovery_codes'], $codes[0]), 'stored as hashes');
s_test(coreui_security_recovery_use($pdo, 2, strtoupper(str_replace('-', '', $codes[3]))) && !coreui_security_recovery_use($pdo, 2, $codes[3])
  && coreui_security_methods($pdo, 2)['recovery_left'] === 9, 'each code once, with or without dash');
s_test(!coreui_security_recovery_use($pdo, 3, $codes[4]), 'not for another account');

// Passkeys and advanced account security.
s_test(s_code(fn() => coreui_security_advanced_enable($pdo, 2, $now)) === 'advanced_needs_passkey', 'advanced needs a passkey');
$pkId = coreui_security_passkey_add($pdo, 2, $reg, $rp, "Pixel\x01 <Passkey>");
s_test(s_code(fn() => coreui_security_passkey_add($pdo, 3, $reg, $rp, 'x')) === 'passkey_exists', 'a credential belongs to one account');
$list = coreui_security_passkeys($pdo, 2);
s_test(count($list) === 1 && $list[0]['label'] === 'Pixel Passkey' && !isset($list[0]['public_key']) && !isset($list[0]['credential_id']), 'list without keys');
$found = coreui_security_passkey_find($pdo, $reg['credential_id']);
s_test($found !== null && (int)$found['user_id'] === 2 && $found['rp_id'] === $rp && coreui_security_passkey_find($pdo, '../x') === null, 'found by credential id');
coreui_security_passkey_used($pdo, $pkId, 9);
s_test((int)coreui_security_passkey_find($pdo, $reg['credential_id'])['sign_count'] === 9, 'counter stored');
s_test(coreui_security_passkey_rename($pdo, 2, $pkId, 'Laptop') && !coreui_security_passkey_rename($pdo, 3, $pkId, 'x'), 'renamed by its owner only');
$h1 = coreui_security_webauthn_handle($pdo, 2);
s_test(preg_match('~^[A-Za-z0-9_-]{43}$~', $h1) === 1 && coreui_security_webauthn_handle($pdo, 2) === $h1 && coreui_security_webauthn_handle($pdo, 3) !== $h1, 'user handle stable');
$opts = coreui_security_passkey_create_options($pdo, 2, 'lena@example.test', 'Lena', $chal, $rp);
s_test($opts['rp'] === ['name' => 'CoreAI', 'id' => $rp] && $opts['attestation'] === 'none' && $opts['excludeCredentials'][0]['id'] === $reg['credential_id']
  && array_column($opts['pubKeyCredParams'], 'alg') === [-7, -257, -8], 'creation options');
$codesAdv = coreui_security_advanced_enable($pdo, 2, $now);
s_test($codesAdv === [] && coreui_security_advanced($pdo, 2), 'advanced on (recovery codes already there)');
s_test(coreui_security_session_hours($pdo, 2) === 24 && coreui_security_session_hours($pdo, 3) === 720, '24-hour sessions');
s_test(s_code(fn() => coreui_security_passkey_remove($pdo, 2, $pkId)) === 'advanced_needs_passkey', 'the last passkey stays');
coreui_security_advanced_disable($pdo, 2);
s_test(!s_code(fn() => coreui_security_passkey_remove($pdo, 2, $pkId)) && coreui_security_passkey_count($pdo, 2) === 0, 'removed after advanced is off');
$pdo->exec("INSERT INTO stu_passkeys (user_id, credential_id, rp_id, public_key, label) VALUES (3, 'AAAAAAAAAAAAAAAAAAAAAA', 'x', '{}', 'x')");
$codes3 = coreui_security_advanced_enable($pdo, 3, $now);
s_test(count($codes3) === 10 && coreui_security_methods($pdo, 3)['recovery_left'] === 10, 'advanced without recovery codes creates them');
coreui_security_totp_disable($pdo, 2);
s_test(!coreui_security_methods($pdo, 2)['totp'] && coreui_security_totp_key($pdo, 2) === null, 'authenticator app removed');

// The server name for passkeys.
s_test(coreui_webauthn_rp_id(['HTTP_HOST' => 'CoreAI.Example:8443']) === (defined('STU_PUBLIC_BASE_URL') && STU_PUBLIC_BASE_URL !== ''
  ? (string)parse_url((string)STU_PUBLIC_BASE_URL, PHP_URL_HOST) : 'coreai.example'), 'RP ID from the host, without port');
if (!defined('STU_PUBLIC_BASE_URL') || STU_PUBLIC_BASE_URL === '') {
  s_test(coreui_webauthn_rp_id(['HTTP_HOST' => '[::1]:80']) === '' && coreui_webauthn_rp_id(['HTTP_HOST' => 'a b']) === '', 'no RP ID for odd hosts');
}

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) -------------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  s_test(coreui_security_ready($my), 'MariaDB: migration 030 applied');
  $name = 'security-selftest-' . bin2hex(random_bytes(4)) . '@example.test';
  $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)")->execute([$name]);
  $tu = (int)$my->lastInsertId();
  try {
    coreui_security_set_lockdown($my, $tu, true, 'Test');
    coreui_security_put($my, $tu, ['email_mfa_at' => date('Y-m-d H:i:s')]);
    s_test(coreui_security_lockdown($my, $tu) && coreui_security_methods($my, $tu)['email'], 'MariaDB: ON DUPLICATE KEY');
    $my->prepare('DELETE FROM stu_passkeys WHERE credential_id = ?')->execute([$reg['credential_id']]);
    $id = coreui_security_passkey_add($my, $tu, $reg, $rp, 'Test');
    s_test($id > 0 && s_code(fn() => coreui_security_passkey_add($my, $tu, $reg, $rp, 'x')) === 'passkey_exists', 'MariaDB: unique credential');
    coreui_security_passkey_used($my, $id, 4);
    $hist = coreui_security_history($my, $tu);
    s_test(count($hist) >= 1 && $hist[0]['kind'] === 'lockdown_on', 'MariaDB: history');
    $dbChecked = true;
  } finally {
    $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$tu]);
  }
  $left = $my->prepare('SELECT (SELECT COUNT(*) FROM stu_user_security WHERE user_id = ?) + (SELECT COUNT(*) FROM stu_passkeys WHERE user_id = ?) '
    . '+ (SELECT COUNT(*) FROM stu_security_events WHERE user_id = ?)');
  $left->execute([$tu, $tu, $tu]);
  s_test((int)$left->fetchColumn() === 0, 'MariaDB: everything deleted with the account (CASCADE)');
}

// --- Wiring ----------------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$auth = $read('api/auth.php');
$advancedAt = strpos($auth, "if (coreui_security_advanced(\$pdo, (int)\$row['id'])) {");
$verifyAt = strpos($auth, "if (!password_verify(\$password, (string)\$row['password_hash'])) {");
s_test($advancedAt !== false && $verifyAt !== false && $advancedAt < $verifyAt && str_contains($auth, "'passkey_required'"), 'auth: advanced refuses the password first');
s_test(str_contains($auth, "\$_SESSION['coreui_mfa_pending'] = ['uid' => (int)\$row['id']") && str_contains($auth, "coreui_auth_login_finish(\$pdo, (int)\$row['id'], 'Passwort');")
  && substr_count($auth, 'coreui_auth_login_finish(') === 4, 'auth: one finish for password, second step and passkey');
s_test(str_contains($auth, "unset(\$_SESSION['coreui_webauthn_login']);") && str_contains($auth, "(string)\$stored['rp_id'] !== \$rpId"), 'auth: challenge once, passkey bound to the server name');
s_test(substr_count($auth, 'coreui_security_advanced($pdo, (int)$uid)') === 2 && str_contains($auth, "if (coreui_security_advanced(\$pdo, (int)\$row['user_id'])) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);"),
  'auth: no password reset by mail with advanced security (code and old link)');
s_test(str_contains($auth, 'coreui_auth_session_issue($pdo, $uid, coreui_security_session_hours($pdo, $uid))'), 'auth: session hours');
$page = $read('api/account_security.php');
foreach (['totp_begin', 'totp_enable', 'totp_disable', 'email_begin', 'email_enable', 'email_disable', 'recovery_new', 'passkey_options', 'passkey_add',
  'passkey_rename', 'passkey_remove', 'advanced_enable', 'advanced_disable', 'sessions_revoke_all', 'lockdown'] as $action) {
  s_test(str_contains($page, "if (\$action === '$action')"), 'account_security: ' . $action);
}
s_test(substr_count($page, 'coreui_account_security_reauth($pdo, $uid, (string)($body[\'password\'] ?? \'\'));') === 8, 'account_security: password for every sensitive change');
s_test(str_contains($page, "'guest_not_allowed'") && str_contains($page, "coreui_auth_session_revoke_all(\$pdo, \$uid, 'advanced_security');"), 'account_security: no guests, advanced signs out everywhere');
s_test(str_contains($read('api/ai_settings.php'), "\$operator['web'] = false;\n    \$operator['browse'] = false;"), 'Lockdown-Modus switches web and browser off');
s_test(str_contains($read('api/notifications_store.php'), "\$category === 'security' && \$channels === ['']"), 'advanced: sign-in notifications cannot be switched off');
s_test(str_contains($read('api/helpers.php'), "require_once __DIR__ . '/security_store.php';"), 'helpers load the store');
s_test(str_contains($read('api/auth_session_store.php'), "INTERVAL ' . \$hours . ' HOUR"), 'session lifetime per account');
$migration = $read('database/migrations/030_sign_in_security.sql');
foreach (['stu_user_security', 'stu_passkeys', 'stu_security_events'] as $table) {
  s_test(str_contains($migration, "CREATE TABLE IF NOT EXISTS $table") , 'migration 030: ' . $table);
}
s_test(substr_count($migration, 'ON DELETE CASCADE') === 3 && str_contains($migration, "('030_sign_in_security')"), 'migration 030: cascade, version');
$login = $read('login.html');
s_test(str_contains($login, 'id="passkeyBtn"') && str_contains($login, 'id="mfaStep"') && str_contains($login, 'window.CoreAILoginSecurity.mfa(d.methods);')
  && (bool)preg_match('~<script src="\./js/coreui-login-security\.js\?v=\d{25}" defer></script>~', $login), 'login page: passkey and second step');
$settings = $read('settings.html');
s_test((bool)preg_match('~<script src="\./js/coreui-qr\.js\?v=\d{25}"></script>\s*<script src="\./js/coreui-security-settings\.js\?v=\d{25}"></script>~', $settings)
  && str_contains($settings, 'id="secSub-advanced"') && str_contains($settings, 'id="secLockdown"'), 'settings page');
s_test(str_contains($read('scripts/security-reset.php'), "if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }"), 'emergency reset is CLI only');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $f) {
  $pre = $read($f);
  foreach (['database/migrations/030_sign_in_security.sql', 'api/security_store.php', 'api/webauthn.php', 'api/totp.php', 'api/secret_box.php',
    'js/coreui-qr.js', 'js/coreui-security-settings.js', 'js/coreui-login-security.js', 'scripts/sign-in-security-selftest.php',
    'scripts/security-ui-selftest.cjs', 'scripts/security-reset.php'] as $need) {
    s_test(str_contains($pre, $need), $f . ' lists ' . $need);
  }
}

echo "CoreAI sign-in security: RFC 6238/4226 codes (drift, no replay), sealed secrets, WebAuthn registration and sign-in (ES256, RS256"
  . ($edChecked ? ', Ed25519' : '') . ", refusals), lockdown, history, authenticator app, codes by mail, recovery codes, passkeys, advanced "
  . "account security, wiring OK" . ($dbChecked ? ', MariaDB statements OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
