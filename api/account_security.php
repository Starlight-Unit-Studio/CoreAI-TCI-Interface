<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications_store.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/security_store.php';
require_once __DIR__ . '/profile_store.php';
require_once __DIR__ . '/console_chat_lock_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!coreui_auth_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'missing_schema_006'], 503);
}

function coreui_security_rate_limit(string $bucket, int $max, int $window): void {
  stu_start_session();
  $key = 'coreui_security_rl_' . preg_replace('~[^a-z0-9_-]~i', '_', $bucket);
  $now = time();
  $row = $_SESSION[$key] ?? ['time'=>$now, 'count'=>0];
  if (!is_array($row) || ($now - (int)($row['time'] ?? 0)) > $window) {
    $row = ['time'=>$now, 'count'=>0];
  }
  $row['count'] = (int)($row['count'] ?? 0) + 1;
  $_SESSION[$key] = $row;
  if ($row['count'] > $max) stu_json(['ok'=>false, 'error'=>'rate_limited'], 429);
}

function coreui_security_audit(PDO $pdo, int $uid, string $action, array $detail = []): void {
  try {
    $json = $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare(
      'INSERT INTO stu_admin_audit (actor_user_id,action_name,target_type,target_id,detail_json,created_at) '
      . "VALUES (?,?,'account',?,?,NOW())"
    );
    $st->execute([$uid, $action, (string)$uid, $json]);
  } catch (Throwable $e) {}
}

/** 0.6.85: everything the page Sicherheit und Anmeldung shows (no secrets, no key material). */
function coreui_account_security_state(PDO $pdo, int $uid): array {
  $st = $pdo->prepare('SELECT username FROM stu_users WHERE id=? LIMIT 1');
  $st->execute([$uid]);
  $email = (string)($st->fetchColumn() ?: '');
  $methods = coreui_security_methods($pdo, $uid);
  return [
    'ready' => $methods['ready'],
    'methods' => $methods,
    'passkeys' => coreui_security_passkeys($pdo, $uid),
    'passkey_rp_id' => coreui_webauthn_rp_id(),
    'email_available' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false && coreui_notify_mail_available($pdo),
    'email_hint' => coreui_security_mask_email($email),
    'chat_pin_ready' => coreui_chat_lock_schema_ready($pdo),
    'chat_pin_set' => coreui_chat_lock_schema_ready($pdo) ? coreui_chat_lock_has_pin($pdo,$uid) : false,
    'history' => coreui_security_history($pdo, $uid, 60),
  ];
}

/** Sensitive changes need the current password again. */
function coreui_account_security_reauth(PDO $pdo, int $uid, string $password): void {
  coreui_security_rate_limit('reauth', 10, 900);
  $st = $pdo->prepare('SELECT password_hash FROM stu_users WHERE id=? AND is_guest=0 LIMIT 1');
  $st->execute([$uid]);
  $hash = (string)($st->fetchColumn() ?: '');
  if ($password === '' || strlen($password) > 1024 || $hash === '' || !password_verify($password, $hash)) {
    stu_json(['ok'=>false, 'error'=>'invalid_current_password'], 403);
  }
}

function coreui_account_device(): string {
  return coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

if ($method === 'GET') {
  $st = $pdo->prepare('SELECT username,password_changed_at,last_login_at,created_at FROM stu_users WHERE id=? LIMIT 1');
  $st->execute([$uid]);
  $account = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  stu_json([
    'ok'=>true,
    'account'=>[
      'email'=>(string)($account['username'] ?? ''),
      'created_at'=>$account['created_at'] ?? null,
      'password_changed_at'=>$account['password_changed_at'] ?? null,
      'last_login_at'=>$account['last_login_at'] ?? null,
    ],
    'sessions'=>coreui_auth_session_list($pdo, $uid),
    'security'=>coreui_account_security_state($pdo, (int)$uid),
    'csrf_token'=>stu_csrf_token(),
  ]);
}

if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();
$action = strtolower(trim((string)($body['action'] ?? '')));

if ($action === 'password_change') {
  coreui_security_rate_limit('password_change', 5, 900);
  $current = (string)($body['current_password'] ?? '');
  $next = (string)($body['new_password'] ?? '');
  $confirm = (string)($body['confirm_password'] ?? '');
  if (strlen($current) < 1 || strlen($current) > 1024) stu_json(['ok'=>false, 'error'=>'invalid_current_password'], 400);
  if (strlen($next) < 12 || strlen($next) > 1024) stu_json(['ok'=>false, 'error'=>'invalid_new_password'], 400);
  if (!hash_equals($next, $confirm)) stu_json(['ok'=>false, 'error'=>'password_confirmation_mismatch'], 400);

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT password_hash FROM stu_users WHERE id=? AND is_guest=0 LIMIT 1 FOR UPDATE');
    $st->execute([$uid]);
    $oldHash = (string)($st->fetchColumn() ?: '');
    if ($oldHash === '' || !password_verify($current, $oldHash)) {
      $pdo->rollBack();
      stu_json(['ok'=>false, 'error'=>'invalid_current_password'], 403);
    }
    if (password_verify($next, $oldHash)) {
      $pdo->rollBack();
      stu_json(['ok'=>false, 'error'=>'password_unchanged'], 400);
    }
    $newHash = password_hash($next, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') throw new RuntimeException('hash_failed');
    $pdo->prepare('UPDATE stu_users SET password_hash=?,password_changed_at=NOW() WHERE id=? LIMIT 1')
      ->execute([$newHash, $uid]);
    coreui_auth_session_revoke_all($pdo, $uid, 'password_changed');
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException && $e->getMessage() === 'hash_failed') {
      stu_json(['ok'=>false, 'error'=>'hash_failed'], 500);
    }
    throw $e;
  }

  session_regenerate_id(true);
  try {
    coreui_auth_session_issue($pdo, $uid, coreui_security_session_hours($pdo, (int)$uid));
  } catch (Throwable $sessionError) {
    // Das Passwort wurde bereits sicher geaendert und alle alten Tokens sind
    // widerrufen. Ohne neues SQL-Token muss sich der Benutzer neu anmelden.
    stu_logout(false);
    stu_json(['ok'=>false, 'error'=>'auth_session_failed', 'reauth_required'=>true], 503);
  }
  coreui_security_audit($pdo, $uid, 'account.password_changed');
  coreui_security_event($pdo, (int)$uid, 'password_changed', coreui_account_device());
  coreui_notify_security($pdo, $uid, 'password', ''); // 0.6.81: Benachrichtigungen > Sicherheit
  stu_json([
    'ok'=>true,
    'sessions'=>coreui_auth_session_list($pdo, $uid),
    'csrf_token'=>stu_csrf_token(),
  ]);
}

if ($action === 'session_revoke') {
  coreui_security_rate_limit('session_revoke', 30, 300);
  $sessionId = (int)($body['session_id'] ?? 0);
  $currentId = (int)($_SESSION['coreui_auth_session_id'] ?? 0);
  if ($sessionId <= 0) stu_json(['ok'=>false, 'error'=>'invalid_session_id'], 400);
  if ($sessionId === $currentId) stu_json(['ok'=>false, 'error'=>'cannot_revoke_current'], 409);
  $deleted = coreui_auth_session_revoke($pdo, $uid, $sessionId, 'user_revoked');
  coreui_security_audit($pdo, $uid, 'account.session_revoked', ['session_id'=>$sessionId]);
  if ($deleted) coreui_security_event($pdo, (int)$uid, 'session_revoked', coreui_account_device());
  stu_json(['ok'=>true, 'revoked'=>$deleted, 'sessions'=>coreui_auth_session_list($pdo, $uid)]);
}

if ($action === 'sessions_revoke_others') {
  coreui_security_rate_limit('session_revoke_others', 10, 300);
  $count = coreui_auth_session_revoke_others($pdo, $uid);
  coreui_security_audit($pdo, $uid, 'account.sessions_revoked_others', ['count'=>$count]);
  coreui_security_event($pdo, (int)$uid, 'sessions_others', coreui_account_device(), (string)$count);
  stu_json(['ok'=>true, 'revoked_count'=>$count, 'sessions'=>coreui_auth_session_list($pdo, $uid)]);
}

// --- 0.6.85: Sicherheit und Anmeldung --------------------------------------------------------------
if (!coreui_security_ready($pdo)) stu_json(['ok'=>false, 'error'=>'security_migration_required'], 503);
$uid = (int)$uid;
$guestCheck = $pdo->prepare('SELECT is_guest FROM stu_users WHERE id=? LIMIT 1');
$guestCheck->execute([$uid]);
$guestFlag = $guestCheck->fetchColumn();
if ($guestFlag === false || (int)$guestFlag === 1) stu_json(['ok'=>false, 'error'=>'guest_not_allowed'], 403);
$device = coreui_account_device();
$reply = static function (array $extra = []) use ($pdo, $uid): void {
  stu_json(['ok'=>true, 'security'=>coreui_account_security_state($pdo, $uid), 'sessions'=>coreui_auth_session_list($pdo, $uid),
    'csrf_token'=>stu_csrf_token()] + $extra);
};

if ($action === 'chat_pin_setup') {
  coreui_security_rate_limit('chat_pin_setup',8,900);
  try {
    coreui_chat_lock_setup($pdo,(int)$uid,(string)($body['pin']??''),(string)($body['confirmation']??''));
    coreui_security_audit($pdo,(int)$uid,'account.chat_pin_setup');
    $reply();
  } catch (InvalidArgumentException $e) {
    stu_json(['ok'=>false,'error'=>$e->getMessage()],400);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false,'error'=>$e->getMessage()],409);
  }
}
if ($action === 'chat_pin_change') {
  coreui_security_rate_limit('chat_pin_change',8,900);
  try {
    coreui_chat_lock_change($pdo,(int)$uid,(string)($body['old_pin']??''),
      (string)($body['pin']??''),(string)($body['confirmation']??''));
    coreui_security_audit($pdo,(int)$uid,'account.chat_pin_change');
    $reply();
  } catch (InvalidArgumentException $e) {
    stu_json(['ok'=>false,'error'=>$e->getMessage()],400);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false,'error'=>$e->getMessage()],
      $e->getMessage()==='chat_pin_temporarily_blocked'?429:409);
  }
}

if ($action === 'sessions_revoke_all') {
  coreui_security_rate_limit('session_revoke_all', 10, 300);
  $count = coreui_auth_session_revoke_all($pdo, $uid, 'user_revoked_all');
  coreui_security_event($pdo, $uid, 'sessions_all', $device, (string)$count);
  coreui_security_audit($pdo, $uid, 'account.sessions_revoked_all', ['count'=>$count]);
  stu_logout(false);
  stu_json(['ok'=>true, 'signed_out'=>true, 'revoked_count'=>$count]);
}

if ($action === 'lockdown') {
  coreui_security_set_lockdown($pdo, $uid, !empty($body['on']), $device);
  $reply();
}

try {
  if ($action === 'totp_begin') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    $key = random_bytes(20);
    $_SESSION['coreui_totp_setup'] = ['key'=>base64_encode($key), 'exp'=>time() + 900];
    $st = $pdo->prepare('SELECT username FROM stu_users WHERE id=? LIMIT 1');
    $st->execute([$uid]);
    $secret = coreui_base32_encode($key);
    stu_json(['ok'=>true, 'secret'=>$secret, 'uri'=>coreui_totp_uri($secret, (string)$st->fetchColumn()), 'csrf_token'=>stu_csrf_token()]);
  }
  if ($action === 'totp_enable') {
    coreui_security_rate_limit('totp_enable', 10, 300);
    $setup = $_SESSION['coreui_totp_setup'] ?? null;
    if (!is_array($setup) || time() > (int)($setup['exp'] ?? 0)) stu_json(['ok'=>false, 'error'=>'setup_expired'], 400);
    if (!coreui_security_totp_enable($pdo, $uid, (string)base64_decode((string)$setup['key']), (string)($body['code'] ?? ''), time())) {
      stu_json(['ok'=>false, 'error'=>'code_invalid'], 400);
    }
    unset($_SESSION['coreui_totp_setup']);
    $codes = coreui_security_recovery_left(coreui_security_row($pdo, $uid)) > 0 ? [] : coreui_security_recovery_new($pdo, $uid);
    coreui_security_event($pdo, $uid, 'totp_on', $device);
    $reply(['recovery_codes'=>$codes]);
  }
  if ($action === 'totp_disable') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    coreui_security_totp_disable($pdo, $uid);
    coreui_security_event($pdo, $uid, 'totp_off', $device);
    $reply();
  }
  if ($action === 'email_begin') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    $st = $pdo->prepare('SELECT username FROM stu_users WHERE id=? LIMIT 1');
    $st->execute([$uid]);
    $email = (string)$st->fetchColumn();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !coreui_notify_mail_available($pdo)) stu_json(['ok'=>false, 'error'=>'mail_not_configured'], 400);
    $code = coreui_security_code_new();
    try {
      stu_send_mail($email, 'CoreAI: Code für die Anmeldung per E-Mail ' . $code,
        "Mit diesem Code schaltest du den E-Mail-Code als zweiten Faktor ein: " . $code . "\n\nEr gilt "
        . COREUI_SECURITY_CODE_MINUTES . " Minuten. Warst du das nicht? Ändere dein Passwort.\n", coreui_mail_effective($pdo));
    } catch (Throwable $e) {
      stu_json(['ok'=>false, 'error'=>'mail_failed'], 502);
    }
    $_SESSION['coreui_email_setup'] = coreui_security_code_hold($code, time());
    stu_json(['ok'=>true, 'sent_to'=>coreui_security_mask_email($email), 'csrf_token'=>stu_csrf_token()]);
  }
  if ($action === 'email_enable') {
    $held = $_SESSION['coreui_email_setup'] ?? null;
    $result = coreui_security_code_check($held, (string)($body['code'] ?? ''), time());
    $_SESSION['coreui_email_setup'] = $held;
    if ($result !== 'ok') stu_json(['ok'=>false, 'error'=>$result === 'wrong' ? 'code_invalid' : 'setup_expired'], 400);
    unset($_SESSION['coreui_email_setup']);
    coreui_security_put($pdo, $uid, ['email_mfa_at'=>date('Y-m-d H:i:s')]);
    $codes = coreui_security_recovery_left(coreui_security_row($pdo, $uid)) > 0 ? [] : coreui_security_recovery_new($pdo, $uid);
    coreui_security_event($pdo, $uid, 'email_on', $device);
    $reply(['recovery_codes'=>$codes]);
  }
  if ($action === 'email_disable') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    coreui_security_put($pdo, $uid, ['email_mfa_at'=>null]);
    coreui_security_event($pdo, $uid, 'email_off', $device);
    $reply();
  }
  if ($action === 'recovery_new') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    $codes = coreui_security_recovery_new($pdo, $uid);
    coreui_security_event($pdo, $uid, 'recovery_new', $device);
    $reply(['recovery_codes'=>$codes]);
  }
  if ($action === 'passkey_options') {
    coreui_security_rate_limit('passkey_options', 20, 300);
    $rpId = coreui_webauthn_rp_id();
    if ($rpId === '') stu_json(['ok'=>false, 'error'=>'passkey_unavailable'], 400);
    $challenge = random_bytes(32);
    $_SESSION['coreui_webauthn_register'] = ['chal'=>base64_encode($challenge), 'exp'=>time() + 300, 'rp'=>$rpId];
    $st = $pdo->prepare('SELECT username FROM stu_users WHERE id=? LIMIT 1');
    $st->execute([$uid]);
    $username = (string)$st->fetchColumn();
    $display = function_exists('coreui_profile_load') ? (string)(coreui_profile_load($pdo, $uid)['display_name'] ?? '') : '';
    stu_json(['ok'=>true, 'publicKey'=>coreui_security_passkey_create_options($pdo, $uid, $username, $display, $challenge, $rpId),
      'csrf_token'=>stu_csrf_token()]);
  }
  if ($action === 'passkey_add') {
    coreui_security_rate_limit('passkey_add', 10, 300);
    $held = $_SESSION['coreui_webauthn_register'] ?? null;
    unset($_SESSION['coreui_webauthn_register']);
    $rpId = coreui_webauthn_rp_id();
    if (!is_array($held) || time() > (int)($held['exp'] ?? 0) || ($held['rp'] ?? '') !== $rpId) stu_json(['ok'=>false, 'error'=>'passkey_expired'], 400);
    $cred = is_array($body['credential'] ?? null) ? $body['credential'] : [];
    $reg = coreui_webauthn_register_verify(is_array($cred['response'] ?? null) ? $cred['response'] : [], (string)base64_decode((string)$held['chal']), $rpId);
    $label = trim((string)($body['label'] ?? ''));
    coreui_security_passkey_add($pdo, $uid, $reg, $rpId, $label !== '' ? $label : $device);
    coreui_security_event($pdo, $uid, 'passkey_added', $device, $label !== '' ? $label : $device);
    $reply();
  }
  if ($action === 'passkey_rename') {
    $label = coreui_security_passkey_label((string)($body['label'] ?? ''));
    if (!coreui_security_passkey_rename($pdo, $uid, (int)($body['id'] ?? 0), $label)) stu_json(['ok'=>false, 'error'=>'passkey_not_found'], 404);
    coreui_security_event($pdo, $uid, 'passkey_renamed', $device, $label);
    $reply();
  }
  if ($action === 'passkey_remove') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    if (!coreui_security_passkey_remove($pdo, $uid, (int)($body['id'] ?? 0))) stu_json(['ok'=>false, 'error'=>'passkey_not_found'], 404);
    coreui_security_event($pdo, $uid, 'passkey_removed', $device);
    $reply();
  }
  if ($action === 'advanced_enable') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    $codes = coreui_security_advanced_enable($pdo, $uid, time());
    coreui_security_event($pdo, $uid, 'advanced_on', $device);
    coreui_security_audit($pdo, $uid, 'account.advanced_security_on');
    // Like the reference: switching it on signs out everywhere, this device included.
    coreui_auth_session_revoke_all($pdo, $uid, 'advanced_security');
    stu_logout(false);
    stu_json(['ok'=>true, 'signed_out'=>true, 'recovery_codes'=>$codes]);
  }
  if ($action === 'advanced_disable') {
    coreui_account_security_reauth($pdo, $uid, (string)($body['password'] ?? ''));
    coreui_security_advanced_disable($pdo, $uid);
    coreui_security_event($pdo, $uid, 'advanced_off', $device);
    coreui_security_audit($pdo, $uid, 'account.advanced_security_off');
    $reply();
  }
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>str_starts_with($e->getMessage(), 'webauthn_') || str_starts_with($e->getMessage(), 'cbor_') ? 'passkey_invalid' : $e->getMessage(),
    'detail'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 409);
}

stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
