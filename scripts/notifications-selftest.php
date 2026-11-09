<?php
declare(strict_types=1);
// CoreAI 0.6.81: Settings > Benachrichtigungen, offline.
//   php scripts/notifications-selftest.php
// Web Push encryption against the example of RFC 8291 Appendix A (byte for byte) and back,
// fresh keys round trip, the VAPID JWT (ES256, verified), only browser push services as
// targets, the VAPID key kept once in the database, the request a push service gets; with
// SQLite: categories and defaults, saving (planned and Inhaber-only rules), subscribing
// (keys checked, a browser moves to the signed-in account, at most ten), dispatch along the
// chosen channels (gone browsers removed, failures counted, E-Mail only to real addresses),
// the triggers (long answer, sign-in, password, a new version once) and the wiring.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/notifications_store.php';

function n_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Notifications FAIL: {$why}\n"); exit(1); }
}

// --- RFC 8291 Appendix A ----------------------------------------------------------------
$d = static fn(string $s): string => (string)coreui_b64url_decode($s);
$asPriv = $d('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPub = $d('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$uaPriv = $d('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94');
$uaPub = $d('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$auth = $d('BTBZMqHH6r4Tts7J_aSIgg');
$salt = $d('DGv6ra1nlYgDCS1FRnbzlw');
$expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';
$asKey = coreui_push_private_key($asPriv, $asPub);
n_test($asKey !== null && coreui_push_point($asKey) === $asPub, 'RFC key import');
$body = coreui_push_encrypt('When I grow up, I want to be a watermelon', $uaPub, $auth, $asKey, $salt);
n_test(coreui_b64url_encode($body) === $expected, 'RFC 8291 Appendix A: the encrypted message matches byte for byte');
$uaKey = coreui_push_private_key($uaPriv, $uaPub);
n_test(coreui_push_decrypt($d($expected), $uaKey, $auth) === 'When I grow up, I want to be a watermelon', 'RFC example decrypts');
n_test(coreui_push_decrypt($d($expected), $uaKey, str_repeat("\0", 16)) === null, 'a wrong auth secret cannot decrypt');

// Fresh keys, random salt: the browser side gets the message back.
$browser = coreui_push_new_key();
$browserPub = coreui_push_point($browser);
$secret = random_bytes(16);
$payload = json_encode(['title' => 'Mara hat geantwortet', 'body' => 'Ümläute und → Pfeile']);
$sealed = coreui_push_encrypt($payload, $browserPub, $secret);
n_test(coreui_push_decrypt($sealed, $browser, $secret) === $payload, 'round trip with fresh keys');
n_test(coreui_push_encrypt($payload, $browserPub, $secret) !== $sealed, 'every message has its own salt and key');
foreach ([['x', $secret], [$browserPub, 'short']] as [$k, $a]) {
  try { coreui_push_encrypt('x', $k, $a); n_test(false, 'bad keys rejected'); } catch (InvalidArgumentException $e) {}
}
try { coreui_push_encrypt(str_repeat('a', 4000), $browserPub, $secret); n_test(false, 'payload limit'); } catch (InvalidArgumentException $e) {}

// --- VAPID ----------------------------------------------------------------------------------
$vapidStored = coreui_push_vapid_create();
$vapid = coreui_push_vapid_from((string)json_encode($vapidStored));
n_test($vapid !== null && strlen((string)coreui_b64url_decode($vapid['public'])) === 65, 'VAPID key pair');
n_test(coreui_push_vapid_from('{"private_pem":"x","public":"y"}') === null && coreui_push_vapid_from(null) === null, 'broken key rejected');
$jwt = coreui_push_vapid_jwt('https://fcm.googleapis.com', 'mailto:owner@example.test', $vapid['key'], 1_800_000_000);
$parts = explode('.', $jwt);
n_test(count($parts) === 3, 'JWT has three parts');
n_test(json_decode((string)coreui_b64url_decode($parts[0]), true) === ['typ' => 'JWT', 'alg' => 'ES256'], 'JWT header');
n_test(json_decode((string)coreui_b64url_decode($parts[1]), true) === ['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 43200,
  'sub' => 'mailto:owner@example.test'], 'JWT claims: audience, 12 h, subject');
$raw = (string)coreui_b64url_decode($parts[2]);
n_test(strlen($raw) === 64, 'ES256 signature is raw r|s');
$pub = coreui_push_public_key((string)coreui_b64url_decode($vapid['public']));
n_test(openssl_verify($parts[0] . '.' . $parts[1], (string)coreui_push_raw_to_der($raw), $pub, OPENSSL_ALGO_SHA256) === 1, 'JWT signature verifies');
n_test(openssl_verify($parts[0] . '.x', (string)coreui_push_raw_to_der($raw), $pub, OPENSSL_ALGO_SHA256) === 0, 'a changed JWT does not');
for ($i = 0; $i < 20; $i++) {
  $sig = '';
  openssl_sign('m' . $i, $sig, $vapid['key'], OPENSSL_ALGO_SHA256);
  $r = coreui_push_der_to_raw($sig);
  n_test($r !== null && openssl_verify('m' . $i, (string)coreui_push_raw_to_der($r), $pub, OPENSSL_ALGO_SHA256) === 1, 'DER/raw round trip ' . $i);
}

// --- Only browser push services ---------------------------------------------------------------
foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/x',
  'https://web.push.apple.com/QK', 'https://db5p.notify.windows.com/w/?token=1', 'https://api.push.apple.com/3/device/x'] as $ok) {
  n_test(coreui_push_endpoint_allowed($ok), 'allowed: ' . $ok);
}
foreach (['http://fcm.googleapis.com/fcm/send/abc', 'https://fcm.googleapis.com:8443/x', 'https://user:pw@fcm.googleapis.com/x',
  'https://fcm.googleapis.com.evil.test/x', 'https://evilnotify.windows.com/x', 'https://127.0.0.1/x', 'https://localhost/x',
  'https://example.com/x', 'file:///etc/passwd', 'https://' . str_repeat('a', 1000) . '.push.apple.com/x', ''] as $bad) {
  n_test(!coreui_push_endpoint_allowed($bad), 'rejected: ' . substr($bad, 0, 60));
}
n_test(coreui_push_audience('https://fcm.googleapis.com/fcm/send/abc') === 'https://fcm.googleapis.com', 'audience is the origin');

// --- The request a push service gets ----------------------------------------------------------
$sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dev1', 'p256dh' => coreui_b64url_encode($browserPub),
  'auth' => coreui_b64url_encode($secret)];
$seen = [];
$http = static function (string $url, array $headers, string $body) use (&$seen): array {
  $seen = ['url' => $url, 'headers' => $headers, 'body' => $body];
  return [201, ''];
};
$res = coreui_push_send($sub, ['title' => 'T', 'body' => 'B', 'urgency' => 'high'], 'mailto:o@example.test', $vapid, $http);
n_test($res['ok'] && $res['status'] === 201 && !$res['gone'], 'sent');
n_test($seen['url'] === $sub['endpoint'], 'to the endpoint');
n_test((bool)preg_grep('~^Authorization: vapid t=[^,]+, k=' . preg_quote($vapid['public'], '~') . '$~', $seen['headers']), 'VAPID authorization header');
foreach (['Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high'] as $h) {
  n_test(in_array($h, $seen['headers'], true), 'header ' . $h);
}
n_test(json_decode((string)coreui_push_decrypt($seen['body'], $browser, $secret), true) === ['title' => 'T', 'body' => 'B', 'urgency' => 'high'],
  'the browser reads exactly the message');
$gone = coreui_push_send($sub, ['title' => 'x'], 's', $vapid, static fn() => [410, '']);
n_test(!$gone['ok'] && $gone['gone'], '410: the browser dropped it');
$blocked = coreui_push_send(['endpoint' => 'https://evil.test/x'] + $sub, ['title' => 'x'], 's', $vapid,
  static function () { n_test(false, 'no request to a foreign host'); return [200, '']; });
n_test(!$blocked['ok'] && $blocked['error'] === 'endpoint_not_allowed', 'foreign endpoint never called');

// --- Pure helpers ---------------------------------------------------------------------------------
n_test(coreui_notify_channels('email, push') === 'push,email' && coreui_notify_channels('PUSH') === 'push'
  && coreui_notify_channels('sms') === '' && coreui_notify_channels('') === '', 'channels canonical');
n_test(coreui_notify_snippet("Hier ist es:\n```php\necho 1;\n```\n**Fertig** [BILD: a fox] und gut.") === 'Hier ist es: Fertig und gut.', 'snippet');
n_test(mb_strlen(coreui_notify_snippet(str_repeat('Wort ', 100), 50)) === 50, 'snippet length');
$cats = coreui_notify_categories();
n_test(array_keys(array_filter($cats, static fn($c) => !$c['planned'])) === ['answers', 'security', 'updates', 'news'], 'four real categories');
n_test($cats['answers']['default'] === 'push' && $cats['security']['default'] === 'email' && $cats['updates']['owner'] === true, 'defaults');
// 0.6.83: all ten categories of the reference page (plus Sicherheit and CoreAI-Updates), with its defaults.
n_test(count($cats) === 12 && array_keys(array_filter($cats, static fn($c) => $c['planned']))
  === ['tasks', 'agents', 'library', 'projects', 'workspace', 'code', 'tips', 'requests'], 'eight planned categories');
n_test($cats['news']['default'] === 'push' && !$cats['news']['owner'] && $cats['code']['default'] === 'push'
  && $cats['tips']['default'] === 'push,email' && $cats['requests']['default'] === 'email', 'defaults of the reference page');

// --- With a database (SQLite) -------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, username TEXT, permission_level INT, is_guest INT)');
$pdo->exec('CREATE TABLE stu_notification_prefs (user_id INT, category TEXT, channels TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, category))');
$pdo->exec('CREATE TABLE stu_push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, endpoint_hash TEXT UNIQUE, endpoint TEXT,
  p256dh TEXT, auth TEXT, label TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_success_at TEXT, failures INT DEFAULT 0)');
$pdo->exec('CREATE TABLE stu_app_settings (k TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, user_id INT, created_at TEXT)');
$pdo->exec("INSERT INTO stu_users VALUES (1, 'owner@example.test', 0, 0), (2, 'lena@example.test', 4, 0), (3, 'gast-7f3a', 4, 1)");

// The VAPID key: created once, then always the same, in the database.
$k1 = coreui_push_vapid($pdo);
$k2 = coreui_push_vapid($pdo);
n_test($k1['public'] === $k2['public'], 'one VAPID key per server');
n_test(str_contains((string)coreui_notify_setting($pdo, 'push_vapid'), 'BEGIN'), 'kept in stu_app_settings');

n_test(coreui_notify_prefs($pdo, 2) === ['answers' => 'push', 'security' => 'email', 'updates' => 'push', 'news' => 'push', 'tasks' => 'push',
  'agents' => 'email', 'library' => 'email', 'projects' => 'email', 'workspace' => 'email', 'code' => 'push', 'tips' => 'push,email',
  'requests' => 'email'], 'defaults without a choice');
$saved = coreui_notify_save($pdo, 2, ['answers' => 'push,email', 'security' => '', 'updates' => 'email', 'tasks' => 'email',
  'unknown' => 'push', 'agents' => ['x']], false);
n_test($saved['answers'] === 'push,email' && $saved['security'] === '', 'saved');
n_test($saved['updates'] === 'push' && $saved['tasks'] === 'push', 'Inhaber-only and planned categories are not saved for a Nutzer');
n_test((int)$pdo->query('SELECT COUNT(*) FROM stu_notification_prefs WHERE user_id = 2')->fetchColumn() === 2, 'only two rows');
n_test(coreui_notify_save($pdo, 1, ['updates' => 'push,email'], true)['updates'] === 'push,email', 'the Inhaber chooses updates');

// Subscriptions.
$ep = static fn(string $id): string => 'https://fcm.googleapis.com/fcm/send/' . $id;
$p = coreui_b64url_encode($browserPub);
$a = coreui_b64url_encode($secret);
$sub1 = coreui_push_subscribe($pdo, 2, $ep('lena-phone'), $p, $a, "Chrome\nAndroid");
n_test($sub1['key'] === coreui_push_endpoint_key($ep('lena-phone')), 'the browser key the settings use');
$devices = coreui_push_devices($pdo, 2);
n_test(count($devices) === 1 && $devices[0]['label'] === 'Chrome Android' && !isset($devices[0]['endpoint']) && !isset($devices[0]['auth']),
  'devices list without endpoint or keys');
foreach ([['https://evil.test/x', $p, $a, 'push_endpoint_not_allowed'], [$ep('x'), 'AAAA', $a, 'push_keys_invalid'],
  [$ep('x'), $p, 'AAAA', 'push_keys_invalid'], [$ep('x'), coreui_b64url_encode("\x04" . str_repeat("\x01", 64)), $a, 'push_keys_invalid']] as [$e, $kp, $ka, $err]) {
  try { coreui_push_subscribe($pdo, 2, $e, $kp, $ka, 'x'); n_test(false, 'rejected ' . $err); }
  catch (InvalidArgumentException $ex) { n_test($ex->getMessage() === $err, 'error ' . $err . ' got ' . $ex->getMessage()); }
}
coreui_push_subscribe($pdo, 1, $ep('lena-phone'), $p, $a, 'Chrome');
n_test(coreui_push_devices($pdo, 2) === [] && count(coreui_push_devices($pdo, 1)) === 1, 'a browser belongs to the account signed in on it');
for ($i = 0; $i < 9; $i++) coreui_push_subscribe($pdo, 1, $ep('o' . $i), $p, $a, 'B' . $i);
try { coreui_push_subscribe($pdo, 1, $ep('eleventh'), $p, $a, 'x'); n_test(false, 'device limit'); }
catch (RuntimeException $e) { n_test($e->getMessage() === 'push_too_many_devices', 'at most ten devices'); }
$someId = coreui_push_devices($pdo, 1)[3]['id'];
n_test(coreui_push_unsubscribe($pdo, 2, $someId) === 0, 'another account cannot remove a device');
n_test(coreui_push_unsubscribe($pdo, 1, $someId) === 1 && coreui_push_unsubscribe($pdo, 1, 0, $ep('o0')) === 1, 'remove by id and by endpoint');
$pdo->exec('DELETE FROM stu_push_subscriptions');

// Dispatch along the chosen channels.
coreui_push_subscribe($pdo, 2, $ep('l1'), $p, $a, 'Phone');
coreui_push_subscribe($pdo, 2, $ep('l2'), $p, $a, 'Laptop');
coreui_push_subscribe($pdo, 2, $ep('l3'), $p, $a, 'Old');
$pdo->exec("UPDATE stu_push_subscriptions SET failures = 9 WHERE endpoint_hash = '" . hash('sha256', $ep('l3')) . "'");
$pushed = [];
$mails = [];
$push = static function (array $s, array $msg) use (&$pushed, $ep): array {
  $pushed[] = [$s['endpoint'], $msg];
  if ($s['endpoint'] === $ep('l2')) return ['ok' => false, 'gone' => true];
  if ($s['endpoint'] === $ep('l3')) return ['ok' => false, 'gone' => false];
  return ['ok' => true, 'gone' => false];
};
$mail = static function (string $to, string $subject, string $text) use (&$mails): void { $mails[] = [$to, $subject, $text]; };
$r = coreui_notify_dispatch($pdo, 2, 'answers', ['title' => 'Mara hat geantwortet', 'body' => 'Hallo', 'url' => 'javascript:alert(1)',
  'tag' => 'cs_1<x>', 'skip_if_visible' => true], $push, $mail);
n_test($r['push'] === 1 && $r['push_removed'] === 2 && $r['email'] === true, 'push to all, gone and tenth failure removed, mail sent: ' . json_encode($r));
n_test(count(coreui_push_devices($pdo, 2)) === 1, 'only the working browser stays');
n_test($pushed[0][1]['url'] === './app.html' && $pushed[0][1]['tag'] === 'coreai-answers-cs_1x' && $pushed[0][1]['skipIfVisible'] === true,
  'payload: safe link, safe tag, skip while watching');
n_test($mails[0][0] === 'lena@example.test' && $mails[0][1] === 'Mara hat geantwortet' && str_contains($mails[0][2], 'Einstellungen > Benachrichtigungen'),
  'mail to the account address with a note where to change it');
$pushed = []; $mails = [];
$r = coreui_notify_dispatch($pdo, 2, 'security', ['title' => 'x'], $push, $mail);
n_test($pushed === [] && $mails === [] && $r['email'] === false, 'security switched off: nothing');
n_test(coreui_notify_dispatch($pdo, 2, 'tasks', ['title' => 'x'], $push, $mail)['push'] === 0 && $pushed === [], 'planned category never sends');
coreui_notify_save($pdo, 3, ['answers' => 'email'], false);
coreui_notify_dispatch($pdo, 3, 'answers', ['title' => 'x'], $push, $mail);
n_test($mails === [], 'no mail to a guest name');
$pdo->exec("UPDATE stu_push_subscriptions SET failures = 3");
coreui_notify_dispatch($pdo, 2, 'answers', ['title' => 'x'], static fn() => ['ok' => false, 'gone' => false], $mail);
n_test((int)$pdo->query('SELECT failures FROM stu_push_subscriptions')->fetchColumn() === 4, 'a failure is counted');
coreui_notify_dispatch($pdo, 2, 'answers', ['title' => 'x'], static fn() => ['ok' => true], $mail);
n_test((int)$pdo->query('SELECT failures FROM stu_push_subscriptions')->fetchColumn() === 0
  && $pdo->query('SELECT last_success_at FROM stu_push_subscriptions')->fetchColumn() !== null, 'a success resets the count');

// Triggers (run inline, with the stand-in senders).
$GLOBALS['COREUI_NOTIFY_INLINE'] = true;
$GLOBALS['COREUI_NOTIFY_TEST_PUSH'] = $push;
$GLOBALS['COREUI_NOTIFY_TEST_MAIL'] = $mail;
$pushed = []; $mails = [];
$pdo->exec("INSERT INTO stu_chat_messages VALUES (10, 2, datetime('now', '-10 seconds')), (11, 2, datetime('now', '-90 seconds'))");
coreui_notify_answer($pdo, 2, 'cs_0123456789abcdef01234567', 10, 'Kurz', 'Mara');
n_test($pushed === [], 'a quick answer is not announced');
coreui_notify_answer($pdo, 2, 'cs_0123456789abcdef01234567', 11, "Das Bild ist fertig. [BILD: a fox]", 'Nova');
n_test(count($pushed) === 1 && $pushed[0][1]['title'] === 'Nova hat geantwortet' && $pushed[0][1]['body'] === 'Das Bild ist fertig.'
  && $pushed[0][1]['url'] === './app.html?session=cs_0123456789abcdef01234567', 'a long answer: the account\'s name, text, link to the chat');
coreui_notify_answer($pdo, 1, 'cs_x', 11, 'x', 'Mara');
n_test(count($pushed) === 1, 'not for someone else\'s message');
$pushed = []; $mails = [];
coreui_notify_save($pdo, 2, ['security' => 'email'], false);
coreui_notify_security($pdo, 2, 'login', 'Chrome auf Android');
n_test(count($mails) === 1 && $mails[0][1] === 'Neue Anmeldung bei CoreAI' && str_contains($mails[0][2], 'Chrome auf Android'), 'sign-in mail');
coreui_notify_security($pdo, 2, 'password', '');
n_test($mails[1][1] === 'Dein CoreAI-Passwort wurde geändert', 'password mail');
$pushed = []; $mails = [];
coreui_push_subscribe($pdo, 1, $ep('owner'), $p, $a, 'Owner');
n_test(coreui_notify_update_available($pdo, ['available' => false, 'latest_version' => '0.6.82']) === false, 'nothing without an update');
n_test(coreui_notify_update_available($pdo, ['available' => true, 'latest_version' => '0.6.82', 'current_version' => '0.6.81']) === true, 'announced');
n_test(count($pushed) === 1 && $pushed[0][1]['title'] === 'CoreAI 0.6.82 ist bereit' && $mails[0][0] === 'owner@example.test', 'to the Inhaber by push and mail');
n_test(coreui_notify_update_available($pdo, ['available' => true, 'latest_version' => '0.6.82']) === false && count($pushed) === 1, 'once per version');
n_test(coreui_notify_update_available($pdo, ['available' => true, 'latest_version' => '0.6.83']) === true, 'the next version again');

// Neue Funktionen (0.6.83): the first run only remembers; a new version tells every account once; a rollback stays quiet.
$pushed = []; $mails = [];
n_test(coreui_notify_news_installed($pdo, '0.6.82') === false && $pushed === [] && coreui_notify_setting($pdo, 'notify_news_version') === '0.6.82',
  'news: first run only remembers the version');
n_test(coreui_notify_news_installed($pdo, '0.6.82') === false && coreui_notify_news_installed($pdo, "0.6.83'; --") === false
  && coreui_notify_news_installed($pdo, '') === false && $pushed === [], 'news: same version or no real version number: nothing');
n_test(coreui_notify_news_installed($pdo, '0.6.83') === true, 'news: a new version is announced');
n_test(count($pushed) === 2 && $pushed[0][1]['title'] === 'CoreAI 0.6.83 ist da' && $pushed[0][1]['url'] === './app.html'
  && $pushed[0][0] === $ep('owner') && $pushed[1][0] === $ep('l1') && $mails === [], 'news: every account by Push (default), not the guest');
n_test(coreui_notify_news_installed($pdo, '0.6.83') === false && count($pushed) === 2, 'news: once per version');
coreui_notify_save($pdo, 2, ['news' => 'email'], false);
$pushed = []; $mails = [];
n_test(coreui_notify_news_installed($pdo, '0.6.84') === true && count($pushed) === 1 && $pushed[0][0] === $ep('owner')
  && count($mails) === 1 && $mails[0][0] === 'lena@example.test' && $mails[0][1] === 'CoreAI 0.6.84 ist da', 'news: each account\'s own choice');
$pushed = []; $mails = [];
n_test(coreui_notify_news_installed($pdo, '0.6.80') === false && $pushed === [] && $mails === []
  && coreui_notify_setting($pdo, 'notify_news_version') === '0.6.80', 'news: a rollback is remembered silently');
unset($GLOBALS['COREUI_NOTIFY_INLINE'], $GLOBALS['COREUI_NOTIFY_TEST_PUSH'], $GLOBALS['COREUI_NOTIFY_TEST_MAIL']);

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database): the MySQL statements ------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/helpers.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  n_test(coreui_notify_schema_ready($my), 'migration 028 applied');
  $name = 'notify-selftest-' . bin2hex(random_bytes(4)) . '@example.test';
  $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)")->execute([$name]);
  $tu = (int)$my->lastInsertId();
  try {
    n_test(coreui_notify_save($my, $tu, ['answers' => 'email', 'security' => 'push'], false)['answers'] === 'email', 'MariaDB: save');
    n_test(coreui_notify_save($my, $tu, ['answers' => 'push,email'], false)['answers'] === 'push,email', 'MariaDB: ON DUPLICATE KEY');
    $my->prepare('DELETE FROM stu_push_subscriptions WHERE endpoint_hash = ?')->execute([hash('sha256', $ep('mariadb-' . $tu))]);
    coreui_push_subscribe($my, $tu, $ep('mariadb-' . $tu), $p, $a, 'MariaDB');
    n_test(count(coreui_push_devices($my, $tu)) === 1, 'MariaDB: subscribe');
    $vk = coreui_push_vapid($my);
    n_test(coreui_push_vapid($my)['public'] === $vk['public'], 'MariaDB: one VAPID key (INSERT IGNORE)');
    $my->prepare('INSERT INTO stu_chat_messages (channel, user_id, character_id, character_name, message, created_at) '
      . "VALUES ('console', ?, 'c', 'n', 'x', NOW() - INTERVAL 2 MINUTE)")->execute([$tu]);
    $msgId = (int)$my->lastInsertId();
    $GLOBALS['COREUI_NOTIFY_INLINE'] = true;
    $sentMy = [];
    $GLOBALS['COREUI_NOTIFY_TEST_PUSH'] = static function (array $s, array $m) use (&$sentMy): array { $sentMy[] = $m; return ['ok' => true]; };
    $GLOBALS['COREUI_NOTIFY_TEST_MAIL'] = static function (): void {};
    coreui_notify_answer($my, $tu, 'cs_0123456789abcdef01234567', $msgId, 'Fertig.', 'Mara');
    n_test(count($sentMy) === 1 && $sentMy[0]['title'] === 'Mara hat geantwortet', 'MariaDB: TIMESTAMPDIFF finds the long answer');
    // 0.6.83: the compare and swap of Neue Funktionen on MariaDB (UPDATE ... WHERE value = old, rowCount).
    $newsBefore = coreui_notify_setting($my, 'notify_news_version');
    try {
      coreui_notify_setting_put($my, 'notify_news_version', '0.0.1');
      $sentMy = [];
      n_test(coreui_notify_news_installed($my, '0.0.2') === true && coreui_notify_news_installed($my, '0.0.2') === false, 'MariaDB: news once');
    } finally {
      if ($newsBefore === null) $my->prepare("DELETE FROM stu_app_settings WHERE `k` = 'notify_news_version'")->execute();
      else coreui_notify_setting_put($my, 'notify_news_version', $newsBefore);
    }
    unset($GLOBALS['COREUI_NOTIFY_INLINE'], $GLOBALS['COREUI_NOTIFY_TEST_PUSH'], $GLOBALS['COREUI_NOTIFY_TEST_MAIL']);
    $my->prepare('DELETE FROM stu_chat_messages WHERE id = ?')->execute([$msgId]);
    $dbChecked = true;
  } finally {
    $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$tu]);
  }
  n_test(coreui_push_devices($my, $tu) === [] && coreui_notify_prefs($my, $tu)['answers'] === 'push', 'MariaDB: deleting the account removes both (CASCADE)');
}

// --- Wiring ---------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$chat = $read('api/chat.php');
n_test(str_contains($chat, "require_once __DIR__ . '/notifications_store.php';")
  && str_contains($chat, "coreui_notify_answer(\$pdo, \$uid, (string)\$sessionId, (int)\$replyToId, (string)\$text, coreui_profile_assistant_name(\$pdo, \$uid));"),
  'chat: Mara\'s saved reply');
$auth = $read('api/auth.php');
n_test(str_contains($auth, "coreui_notify_security(\$pdo, (int)\$row['id'], 'login', coreui_auth_device_label((string)(\$_SERVER['HTTP_USER_AGENT'] ?? '')));")
  && substr_count($auth, "'password', '');") === 2, 'auth: sign-in and both password resets');
n_test(str_contains($read('api/account_security.php'), "coreui_notify_security(\$pdo, \$uid, 'password', '');"), 'password change');
n_test(str_contains($read('api/update.php'), 'coreui_notify_update_available(stu_pdo(), $state);')
  && str_contains($read('api/update.php'), "coreui_notify_news_installed(stu_pdo(), (string)(\$state['current_version'] ?? ''));"), 'update status');
// 0.6.83: the settings page shows every category, in the order of the reference page.
preg_match_all('~<div class="notify-row[^"]*" data-category="([a-z]+)"~', $read('settings.html'), $rowsFound);
n_test($rowsFound[1] === ['agents', 'tasks', 'library', 'code', 'updates', 'news', 'tips', 'projects', 'answers', 'security', 'requests', 'workspace'],
  'settings: all categories in order: ' . implode(',', $rowsFound[1]));
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $f) {
  $conf = $read($f);
  n_test(str_contains($conf, '|composer_tools|notifications|voice_input|') && str_contains($conf, 'location = /sw.js {')
    && str_contains($conf, 'add_header Service-Worker-Allowed "/" always;'), $f);
}
$endpoint = $read('api/notifications.php');
n_test(str_contains($endpoint, 'stu_require_csrf();') && str_contains($endpoint, "'guest_not_allowed'"), 'endpoint: CSRF, no guests');
$migration = $read('database/migrations/028_notifications.sql');
n_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_notification_prefs') && str_contains($migration, 'ON DELETE CASCADE')
  && str_contains($migration, "('028_notifications')"), 'migration 028');
$sw = $read('sw.js');
n_test(str_contains($sw, "self.addEventListener('push'") && str_contains($sw, "self.addEventListener('notificationclick'")
  && !str_contains($sw, "addEventListener('fetch'"), 'service worker: push and click, no fetch caching');
foreach (['app.html' => 'coreui-notify.js', 'settings.html' => 'coreui-notify-settings.js'] as $page => $script) {
  n_test((bool)preg_match('~<script src="\./js/' . preg_quote($script, '~') . '\?v=\d{25}"></script>~', $read($page)), $page . ' loads ' . $script);
}
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $f) {
  $pre = $read($f);
  foreach (['api/web_push.php', 'api/notifications_store.php', 'api/notifications.php', 'sw.js', 'database/migrations/028_notifications.sql'] as $need) {
    n_test(str_contains($pre, $need), $f . ' lists ' . $need);
  }
}

echo "CoreAI notifications: RFC 8291 example byte for byte, round trip, VAPID ES256, only browser push services, key once in the database, "
  . "push request, categories and saving, devices, dispatch with removal and failures, mail only to real addresses, triggers "
  . "(long answer, sign-in, password, update once per version, news once per installed version), wiring OK" . ($dbChecked ? ', MariaDB statements OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
