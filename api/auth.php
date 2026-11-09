<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/profile_store.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/captcha.php';
require_once __DIR__ . '/notifications_store.php';
require_once __DIR__ . '/security_store.php';

// Simple username/password auth for Alpha.
// - Session cookie stores stu_uid when logged in.

$action = $_GET['action'] ?? 'me';

function stu_rate_limit_key(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  return 'rl_' . preg_replace('/[^0-9a-f:.]/i', '_', $ip);
}

function stu_rate_limit(string $bucket, int $max, int $windowSec): void {
  stu_start_session();
  $k = stu_rate_limit_key() . '_' . $bucket;
  $now = time();
  $row = $_SESSION[$k] ?? ['t' => $now, 'n' => 0];
  if (!is_array($row) || !isset($row['t'], $row['n'])) $row = ['t' => $now, 'n' => 0];
  if (($now - (int)$row['t']) > $windowSec) {
    $row = ['t' => $now, 'n' => 0];
  }
  $row['n'] = (int)$row['n'] + 1;
  $_SESSION[$k] = $row;
  if ($row['n'] > $max) stu_json(['ok' => false, 'error' => 'rate_limited'], 429);
  // 0.5.78: the session counter alone starts at zero without a cookie; the
  // server counts per network as well (abuse_guard.php).
  try {
    $pdoLimit = stu_pdo();
  } catch (Throwable $e) {
    return;
  }
  coreui_abuse_rate_limit($pdoLimit, $bucket, $max, $windowSec);
}

function stu_clean_username(string $u): string {
  return coreui_account_clean_email($u);
}

function stu_valid_username(string $u): bool {
  return coreui_account_valid_email($u);
}

/*
 * 0.6.85: one way to finish a sign-in (password, password plus second factor, passkey): the
 * account is checked again (guest, ban, maintenance), the session token is issued (24 hours
 * with advanced account security), the history and the sign-in notification follow.
 */
function coreui_auth_login_finish(PDO $pdo, int $uid, string $method): void {
  $st = $pdo->prepare('SELECT id, username, permission_level, is_guest, banned_until, banned_reason FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row) || (int)($row['is_guest'] ?? 0) === 1) stu_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
  if (!empty($row['banned_until'])) {
    $ts = strtotime((string)$row['banned_until']);
    if ($ts !== false && $ts > time()) {
      stu_json(['ok' => false, 'error' => 'banned', 'until' => $row['banned_until'], 'reason' => (string)($row['banned_reason'] ?? '')], 403);
    }
  }
  $lvl = (int)($row['permission_level'] ?? 4);
  stu_enforce_maintenance($pdo, $uid, $lvl);
  try {
    coreui_account_ensure($pdo, $uid, (string)$row['username']);
  } catch (Throwable $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage() === 'missing_schema_004' ? 'missing_schema_004' : 'account_provision_failed'], 503);
  }
  unset($_SESSION['coreui_mfa_pending']);
  session_regenerate_id(true);
  stu_set_user_id($uid);
  $_SESSION['stu_perm'] = $lvl;
  $device = coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  try {
    $pdo->prepare('UPDATE stu_users SET last_login_at=NOW() WHERE id=?')->execute([$uid]);
    if (coreui_auth_session_schema_ready($pdo)) coreui_auth_session_issue($pdo, $uid, coreui_security_session_hours($pdo, $uid));
  } catch (Throwable $e) {
    stu_logout(false);
    stu_json(['ok' => false, 'error' => 'auth_session_failed'], 500);
  }
  coreui_security_event($pdo, $uid, 'login', $device, $method);
  // 0.6.81: Settings > Benachrichtigungen > Sicherheit (after the response).
  coreui_notify_security($pdo, $uid, 'login', $device);
  stu_json(['ok' => true, 'user_id' => $uid, 'permission_level' => $lvl]);
}

/** What the sign-in page offers for the second step (no secrets). */
function coreui_auth_mfa_offer(PDO $pdo, int $uid, array $methods): array {
  $st = $pdo->prepare('SELECT username FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  $email = (string)($st->fetchColumn() ?: '');
  $mail = !empty($methods['email']) && function_exists('coreui_notify_mail_available') && coreui_notify_mail_available($pdo);
  return ['totp' => !empty($methods['totp']), 'email' => $mail, 'recovery' => (int)($methods['recovery_left'] ?? 0) > 0,
    'email_hint' => $mail ? coreui_security_mask_email($email) : ''];
}

/** Public sign-up: the admin switch (Runtime), else the installer's default. */
function coreui_registration_enabled(?PDO $pdo): bool {
  $enabled = defined('STU_ALLOW_REGISTRATION') && STU_ALLOW_REGISTRATION;
  if (!$pdo) return $enabled;
  try {
    return stu_app_setting_get($pdo, 'registration_enabled', $enabled ? '1' : '0') === '1';
  } catch (Throwable $e) {
    return $enabled;
  }
}

if ($action === 'me') {
  $uid = stu_get_user_id();
  if ($uid) {
    // Session may outlive DB rows (DB reset/migration). Verify user exists.
    try {
      $pdo = stu_pdo();
      stu_enforce_account_access($pdo, (int)$uid);
      $st = $pdo->prepare('SELECT id, username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
      $st->execute([$uid]);
      $urow = $st->fetch();
      if ($urow) {
        coreui_account_ensure($pdo, (int)$uid, (string)($urow['username'] ?? ''));
        $guest = (int)($urow['is_guest'] ?? 0) === 1 ? coreui_guest_quota($pdo, (int)$uid) : null;
        $lvl = isset($urow['permission_level']) ? (int)$urow['permission_level'] : 4;
        $_SESSION['stu_perm'] = $lvl;
        $maintenance = stu_maintenance_status($pdo);
        if (!empty($maintenance['enabled']) && !stu_is_staff_level($lvl)) {
          // Keep HTTP 200 here so the login page can stay interactive for Staff users.
          stu_json(stu_maintenance_payload($pdo), 200);
        }
        $profile = null;
        try {
          if (coreui_profile_schema_ready($pdo)) $profile = coreui_profile_load($pdo, (int)$uid);
        } catch (Throwable $e) {}
        stu_json([
          'ok' => true,
          'user_id' => $uid,
          'username' => $urow['username'] ?? null,
          'display_name' => is_array($profile) ? ($profile['display_name'] ?? null) : null,
          'assistant_name' => is_array($profile) ? ($profile['assistant_name'] ?? 'Mara') : 'Mara',
          'avatars' => is_array($profile) ? ($profile['avatars'] ?? ['user' => null, 'assistant' => null]) : ['user' => null, 'assistant' => null],
          'permission_level' => $lvl,
          'permission_label' => stu_permission_label($lvl),
          // 0.5.79: the visible role (Inhaber, Admin, Nutzer, Gast).
          'role' => coreui_role_name($lvl, $guest !== null),
          // 0.5.77: guests see how many messages are left.
          'is_guest' => $guest !== null,
          'guest' => $guest,
          'csrf_token' => stu_csrf_token(),
        ]);
      }
    } catch (Throwable $e) {
      // DB unavailable -> treat as not authenticated
    }
    // Invalid/stale session: force logout
    stu_logout();
  }
  // 0.5.77: the login page shows "Registrieren" and "Als Gast chatten" only while they are open.
  $guestOpen = false;
  $captchaOn = true;
  try {
    $pdoPublic = stu_pdo();
    $registrationOpen = coreui_registration_enabled($pdoPublic);
    $guestOpen = coreui_guest_enabled($pdoPublic);
    $captchaOn = coreui_captcha_enabled($pdoPublic);
  } catch (Throwable $e) {
    $registrationOpen = coreui_registration_enabled(null);
  }
  stu_json(['ok' => false, 'error' => 'not_authenticated', 'registration_enabled' => $registrationOpen,
    'guest_enabled' => $guestOpen, 'captcha_enabled' => $captchaOn], 200);
}

// 0.5.79: a signed task for the captcha (guest access and sign-up).
if ($action === 'captcha') {
  stu_rate_limit('captcha', 30, 600);
  $pdo = stu_pdo();
  if (!coreui_captcha_enabled($pdo)) stu_json(['ok' => true, 'required' => false]);
  stu_json(['ok' => true, 'required' => true] + coreui_captcha_challenge($pdo));
}

// 0.5.77: "Als Gast chatten": a guest account, logged in at once, limited
// to the admin's message count (guest_access.php).
if ($action === 'guest') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }
  stu_rate_limit('guest', 3, 600);
  $pdo = stu_pdo();
  if (!coreui_guest_enabled($pdo)) stu_json(['ok' => false, 'error' => 'guest_disabled'], 403);
  if (!coreui_auth_session_schema_ready($pdo)) stu_json(['ok' => false, 'error' => 'missing_schema_006'], 503);
  stu_enforce_maintenance($pdo, null, 4);
  // 0.5.78: a device that was a guest before gets its guest account back
  // (with what is left of it) instead of a fresh one.
  $deviceToken = coreui_guest_device_cookie();
  $known = $deviceToken !== null ? coreui_guest_find_device($pdo, $deviceToken) : null;
  if ($known !== null && $known['banned']) stu_json(['ok' => false, 'error' => 'banned'], 403);
  $uid = $known !== null ? (int)$known['id'] : null;
  try {
    if ($uid === null) {
      // 0.5.79: a new guest account only with a solved captcha (a known device needs none).
      coreui_captcha_require($pdo, stu_read_json_body());
      $start = coreui_guest_start_allowed($pdo);
      if (!$start['ok']) {
        stu_json(['ok' => false, 'error' => $start['error'], 'retry_at' => date('c', (int)$start['retry_at'])], 429);
      }
      $deviceToken = bin2hex(random_bytes(32));
      $uid = coreui_guest_create($pdo, $deviceToken);
    }
    coreui_guest_device_cookie_set($deviceToken);
  } catch (RuntimeException $e) {
    if ($e->getMessage() === 'guest_capacity') stu_json(['ok' => false, 'error' => 'guest_capacity'], 429);
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'guest_create_failed', 'message' => $e->getMessage()]);
    stu_json(['ok' => false, 'error' => 'guest_failed'], 500);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'guest_create_failed', 'message' => $e->getMessage()]);
    stu_json(['ok' => false, 'error' => 'guest_failed'], 500);
  }
  session_regenerate_id(true);
  stu_set_user_id($uid);
  $_SESSION['stu_perm'] = 4;
  try {
    $pdo->prepare('UPDATE stu_users SET last_login_at=NOW() WHERE id=?')->execute([$uid]);
    coreui_auth_session_issue($pdo, $uid);
  } catch (Throwable $e) {
    stu_logout(false);
    stu_json(['ok' => false, 'error' => 'auth_session_failed'], 500);
  }
  stu_json(['ok' => true, 'user_id' => $uid, 'guest' => coreui_guest_quota($pdo, $uid)]);
}

if ($action === 'logout') {
  // POST preferred but tolerate GET in alpha tools.
  $logoutUid = stu_get_user_id();
  if ($logoutUid) {
    try { coreui_security_event(stu_pdo(), (int)$logoutUid, 'logout', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))); } catch (Throwable $e) {}
  }
  stu_logout();
  stu_json(['ok' => true]);
}

if ($action === 'register' || $action === 'login') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  // Rate limit per IP bucket
  stu_rate_limit($action, 10, 60);

  $body = stu_read_json_body();
  $username = stu_clean_username((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  $displayName = coreui_account_clean_display_name(
    (string)($body['display_name'] ?? ''),
    coreui_account_default_display_name($username)
  );

  if (!stu_valid_username($username)) {
    stu_json(['ok' => false, 'error' => 'invalid_username'], 400);
  }
  if (strlen($password) < 8) {
    stu_json(['ok' => false, 'error' => 'invalid_password'], 400);
  }

  $pdo = stu_pdo();
  if (!coreui_auth_session_schema_ready($pdo)) {
    stu_json(['ok' => false, 'error' => 'missing_schema_006'], 503);
  }

  if ($action === 'register') {
    if (!coreui_registration_enabled($pdo)) {
      stu_json(['ok' => false, 'error' => 'registration_disabled'], 403);
    }
    // 0.5.79: sign-up only with a solved captcha.
    coreui_captcha_require($pdo, $body);
    // During maintenance, only existing Staff accounts may pass. New accounts are normal users.
    stu_enforce_maintenance($pdo, null, 4);
    // Ensure columns exist (graceful message if schema not migrated)
    try {
      $pdo->query("SELECT username FROM stu_users LIMIT 1");
    } catch (Throwable $e) {
      stu_json(['ok' => false, 'error' => 'schema_missing_accounts'], 500);
    }

    try {
      $created = coreui_account_create($pdo, $username, $password, $displayName, 4);
      $uid = (int)$created['user_id'];
    } catch (RuntimeException $e) {
      if ($e->getMessage() === 'username_taken') stu_json(['ok' => false, 'error' => 'username_taken'], 409);
      if ($e->getMessage() === 'hash_failed') stu_json(['ok' => false, 'error' => 'hash_failed'], 500);
      if ($e->getMessage() === 'missing_schema_004') stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
      if (function_exists('stu__log_error')) stu__log_error(['type' => 'registration_failed', 'message' => $e->getMessage()]);
      stu_json(['ok' => false, 'error' => 'registration_failed'], 500);
    } catch (Throwable $e) {
      if (function_exists('stu__log_error')) stu__log_error(['type' => 'registration_failed', 'message' => $e->getMessage()]);
      stu_json(['ok' => false, 'error' => 'registration_failed'], 500);
    }

    session_regenerate_id(true);
    stu_set_user_id($uid);
    $_SESSION['stu_perm'] = 4;
    try {
      $pdo->prepare('UPDATE stu_users SET password_changed_at=NOW(), last_login_at=NOW() WHERE id=?')->execute([$uid]);
      if (coreui_auth_session_schema_ready($pdo)) coreui_auth_session_issue($pdo, $uid);
    } catch (Throwable $e) {
      stu_logout(false);
      stu_json(['ok' => false, 'error' => 'auth_session_failed'], 500);
    }
    stu_json(['ok' => true, 'user_id' => $uid, 'permission_level' => 4]);
  }

  // login
// Fetch permission_level if migrated; fall back gracefully on older schema.
try {
  $stmt = $pdo->prepare('SELECT id, password_hash, permission_level, banned_until, banned_reason FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
  $stmt->execute([$username]);
  $row = $stmt->fetch();
} catch (Throwable $e) {
  $stmt = $pdo->prepare('SELECT id, password_hash FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
  $stmt->execute([$username]);
  $row = $stmt->fetch();
  if (is_array($row) && !isset($row['permission_level'])) $row['permission_level'] = 4;
  if (is_array($row) && !isset($row['banned_until'])) $row['banned_until'] = null;
  if (is_array($row) && !isset($row['banned_reason'])) $row['banned_reason'] = '';
}

  // 0.5.78: failed logins count on the server per account (and network), so
  // guessing a password does not restart with every new cookie.
  $loginNetwork = coreui_client_network();
  $blockedFor = coreui_login_blocked_for($pdo, $username, $loginNetwork);
  if ($blockedFor > 0) {
    stu_json(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $blockedFor], 429);
  }
  if (!$row || empty($row['password_hash'])) {
    coreui_login_failed($pdo, $username, $loginNetwork);
    stu_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
  }
  // 0.6.85: with advanced account security this account signs in with a passkey only.
  if (coreui_security_advanced($pdo, (int)$row['id'])) {
    stu_json(['ok' => false, 'error' => 'passkey_required'], 403);
  }
  if (!password_verify($password, (string)$row['password_hash'])) {
    coreui_login_failed($pdo, $username, $loginNetwork);
    coreui_security_event($pdo, (int)$row['id'], 'login_failed', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 'Passwort');
    stu_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
  }
  coreui_login_succeeded($pdo, $username, $loginNetwork);
  // Ban check (optional schema extension)
  if (isset($row['banned_until']) && !empty($row['banned_until'])) {
    $ts = strtotime((string)$row['banned_until']);
    if ($ts !== false && $ts > time()) {
      $reason = isset($row['banned_reason']) ? (string)$row['banned_reason'] : '';
      stu_json(['ok'=>false,'error'=>'banned','until'=>$row['banned_until'],'reason'=>$reason], 403);
    }
  }


  $lvl = isset($row['permission_level']) ? (int)$row['permission_level'] : 4;
  stu_enforce_maintenance($pdo, (int)$row['id'], $lvl);
  try {
    coreui_account_ensure($pdo, (int)$row['id'], $username);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'account_provision_failed', 'uid' => (int)$row['id'], 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => $e->getMessage() === 'missing_schema_004' ? 'missing_schema_004' : 'account_provision_failed'], 503);
  }

  // Rehash on login if algo changed
  if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    if ($newHash) {
      $up = $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1');
      $up->execute([$newHash, (int)$row['id']]);
    }
  }

  // 0.6.85: a second factor (authenticator app or E-Mail code) before the session exists.
  $methods = coreui_security_methods($pdo, (int)$row['id']);
  if (coreui_security_mfa_required($methods)) {
    $_SESSION['coreui_mfa_pending'] = ['uid' => (int)$row['id'], 'at' => time(), 'tries' => 0, 'email' => null];
    stu_json(['ok' => false, 'error' => 'mfa_required', 'mfa_required' => true, 'methods' => coreui_auth_mfa_offer($pdo, (int)$row['id'], $methods)]);
  }
  coreui_auth_login_finish($pdo, (int)$row['id'], 'Passwort');
}

// 0.6.85: the second step of a sign-in: a code from the authenticator app, by mail, or a recovery code.
if ($action === 'mfa_verify' || $action === 'mfa_email') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  stu_rate_limit($action, 20, 300);
  $pdo = stu_pdo();
  $pending = $_SESSION['coreui_mfa_pending'] ?? null;
  if (!is_array($pending) || (int)($pending['uid'] ?? 0) <= 0 || time() - (int)($pending['at'] ?? 0) > COREUI_SECURITY_CODE_MINUTES * 60) {
    unset($_SESSION['coreui_mfa_pending']);
    stu_json(['ok' => false, 'error' => 'mfa_expired'], 401);
  }
  $uid = (int)$pending['uid'];
  $methods = coreui_security_methods($pdo, $uid);
  $offer = coreui_auth_mfa_offer($pdo, $uid, $methods);
  $body = stu_read_json_body();
  if ($action === 'mfa_email') {
    if (!$offer['email']) stu_json(['ok' => false, 'error' => 'mfa_email_unavailable'], 400);
    $held = is_array($pending['email'] ?? null) ? $pending['email'] : null;
    if ($held !== null && time() - (int)($held['sent'] ?? 0) < 60) {
      stu_json(['ok' => false, 'error' => 'mfa_email_wait', 'retry_after' => 60 - (time() - (int)$held['sent'])], 429);
    }
    $code = coreui_security_code_new();
    $st = $pdo->prepare('SELECT username FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    try {
      stu_send_mail((string)$st->fetchColumn(), 'CoreAI: dein Anmeldecode ' . $code,
        "Dein Code für die Anmeldung bei CoreAI: " . $code . "\n\nEr gilt " . COREUI_SECURITY_CODE_MINUTES . " Minuten.\n"
        . "Hast du dich nicht gerade angemeldet? Dann kennt jemand dein Passwort: ändere es sofort.\n", coreui_mail_effective($pdo));
    } catch (Throwable $e) {
      stu_json(['ok' => false, 'error' => 'mfa_email_failed'], 502);
    }
    $pending['email'] = coreui_security_code_hold($code, time());
    $_SESSION['coreui_mfa_pending'] = $pending;
    stu_json(['ok' => true, 'sent_to' => $offer['email_hint']]);
  }
  $kind = (string)($body['method'] ?? '');
  $code = trim((string)($body['code'] ?? ''));
  $passed = false;
  if ($kind === 'totp' && $offer['totp']) {
    $passed = coreui_security_totp_check($pdo, $uid, $code, time());
  } elseif ($kind === 'email' && $offer['email']) {
    $held = is_array($pending['email'] ?? null) ? $pending['email'] : null;
    $result = coreui_security_code_check($held, $code, time());
    $pending['email'] = $held;
    $passed = $result === 'ok';
  } elseif ($kind === 'recovery') {
    $passed = coreui_security_recovery_use($pdo, $uid, $code);
    if ($passed) coreui_security_event($pdo, $uid, 'recovery_used', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
  }
  if (!$passed) {
    $pending['tries'] = (int)($pending['tries'] ?? 0) + 1;
    coreui_security_event($pdo, $uid, 'mfa_failed', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), $kind);
    if ($pending['tries'] >= COREUI_SECURITY_MFA_TRIES) {
      unset($_SESSION['coreui_mfa_pending']);
      stu_json(['ok' => false, 'error' => 'mfa_expired'], 401);
    }
    $_SESSION['coreui_mfa_pending'] = $pending;
    stu_json(['ok' => false, 'error' => 'mfa_invalid', 'tries_left' => COREUI_SECURITY_MFA_TRIES - $pending['tries']], 401);
  }
  $label = ['totp' => 'Passwort und Authenticator-App', 'email' => 'Passwort und E-Mail-Code', 'recovery' => 'Passwort und Wiederherstellungscode'][$kind];
  coreui_auth_login_finish($pdo, $uid, $label);
}

// 0.6.85: sign-in with a passkey or security key (WebAuthn). Counts as both factors.
if ($action === 'passkey_options' || $action === 'passkey_login') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  stu_rate_limit($action, 30, 300);
  $pdo = stu_pdo();
  if (!coreui_security_ready($pdo)) stu_json(['ok' => false, 'error' => 'security_migration_required'], 503);
  $rpId = coreui_webauthn_rp_id();
  if ($rpId === '') stu_json(['ok' => false, 'error' => 'passkey_unavailable'], 400);
  $body = stu_read_json_body();
  if ($action === 'passkey_options') {
    $challenge = random_bytes(32);
    $_SESSION['coreui_webauthn_login'] = ['chal' => base64_encode($challenge), 'exp' => time() + 300, 'rp' => $rpId];
    $allow = [];
    $name = stu_clean_username((string)($body['username'] ?? ''));
    if ($name !== '' && stu_valid_username($name)) {
      $st = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
      $st->execute([$name]);
      $found = (int)($st->fetchColumn() ?: 0);
      if ($found > 0) foreach (coreui_security_passkey_ids($pdo, $found) as $id) $allow[] = ['type' => 'public-key', 'id' => $id];
    }
    stu_json(['ok' => true, 'publicKey' => ['challenge' => coreui_b64u($challenge), 'rpId' => $rpId, 'timeout' => 120000,
      'userVerification' => 'preferred', 'allowCredentials' => $allow]]);
  }
  $held = $_SESSION['coreui_webauthn_login'] ?? null;
  unset($_SESSION['coreui_webauthn_login']);
  if (!is_array($held) || time() > (int)($held['exp'] ?? 0) || ($held['rp'] ?? '') !== $rpId) stu_json(['ok' => false, 'error' => 'passkey_expired'], 400);
  $cred = is_array($body['credential'] ?? null) ? $body['credential'] : [];
  $response = is_array($cred['response'] ?? null) ? $cred['response'] : [];
  $stored = coreui_security_passkey_find($pdo, (string)($cred['id'] ?? ''));
  if ($stored === null || (string)$stored['rp_id'] !== $rpId) stu_json(['ok' => false, 'error' => 'passkey_unknown'], 401);
  $uid = (int)$stored['user_id'];
  $handle = (string)($response['userHandle'] ?? '');
  if ($handle !== '' && !hash_equals(coreui_security_webauthn_handle($pdo, $uid), $handle)) stu_json(['ok' => false, 'error' => 'passkey_unknown'], 401);
  try {
    $count = coreui_webauthn_assert_verify($response, (string)base64_decode((string)$held['chal']), $rpId, $stored);
  } catch (InvalidArgumentException $e) {
    coreui_security_event($pdo, $uid, 'login_failed', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 'Passkey');
    stu_json(['ok' => false, 'error' => 'passkey_invalid'], 401);
  }
  coreui_security_passkey_used($pdo, (int)$stored['id'], $count);
  coreui_auth_login_finish($pdo, $uid, 'Passkey: ' . (string)$stored['label']);
}

if ($action === 'forgot') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  // Nicht verraten, ob ein Konto existiert (anti-account-enumeration).
  $body = stu_read_json_body();
  $email = strtolower(trim($body['email'] ?? ''));

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    stu_json(['ok' => false, 'error' => 'invalid_email']);
  }

  $debugMail = (defined('STU_MAIL_DEBUG') && STU_MAIL_DEBUG);
  if (!(defined('STU_MAIL_ENABLED') && STU_MAIL_ENABLED)) {
    // In Produktion trotzdem ok=true zurückgeben.
    if ($debugMail) stu_json(['ok' => false, 'error' => 'mail_disabled']);
    stu_json(['ok' => true]);
  }

  $pdo = db();

  // In diesem Projekt ist "username" die Email.
  $stmt = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
  $stmt->execute([$email]);
  $uid = $stmt->fetchColumn();

  if (!$uid) {
    stu_json(['ok' => true]);
  }
  // 0.6.85: advanced account security: no password reset by mail (same answer, no hint).
  if (coreui_security_advanced($pdo, (int)$uid)) {
    stu_json(['ok' => true]);
  }

  // Schema-Check (damit es nicht die Seite zerlegt, wenn Migrations fehlen)
  try {
    $pdo->query('SELECT 1 FROM stu_password_resets LIMIT 1');
  } catch (Throwable $e) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'missing_schema_password_resets']);
    stu_json(['ok' => true]);
  }

  // --- Reset-Code (App-only friendly)
  // Generates a short code (6 digits) instead of sending a clickable URL.
  // DB requirements: stu_password_resets.token_hash must be NULLABLE + column code_hash must exist.

  // Best-effort check if code_hash column exists (so we don't fatal on older schema)
  $hasCode = false;
  try {
    $st = $pdo->query("SHOW COLUMNS FROM stu_password_resets LIKE 'code_hash'");
    $hasCode = (bool)$st->fetch();
  } catch (Throwable $e) { $hasCode = false; }

  if (!$hasCode) {
    // If migrations are not yet applied, we return ok=true (no enumeration),
    // but in debug mode we reveal what is missing.
    if ($debugMail) stu_json(['ok' => false, 'error' => 'missing_schema_reset_code', 'hint' => 'run migrations/06_stu_mysql_schema_password_reset_codes.sql']);
    stu_json(['ok' => true]);
  }

  // Light rate-limit per account: do not send more than once per 60s.
  try {
    $st = $pdo->prepare('SELECT created_at FROM stu_password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([(int)$uid]);
    $last = $st->fetchColumn();
    if ($last && (time() - strtotime((string)$last)) < 60) {
      stu_json(['ok' => true]);
    }
  } catch (Throwable $e) {
    // ignore
  }

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $codeHash = password_hash($code, PASSWORD_DEFAULT);
  if (!$codeHash) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'hash_failed']);
    stu_json(['ok' => true]);
  }

  $expires = date('Y-m-d H:i:s', time() + 15 * 60); // 15 min

  // Keep only one active reset per user.
  $pdo->prepare('DELETE FROM stu_password_resets WHERE user_id = ?')->execute([(int)$uid]);
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

  $pdo->prepare('INSERT INTO stu_password_resets (user_id, token_hash, code_hash, expires_at, created_at, used_at, attempts, ip, user_agent, last_sent_at) VALUES (?,?,?,?,NOW(),NULL,0,?,?,NOW())')
      ->execute([(int)$uid, null, $codeHash, $expires, $ip, $ua]);

  require_once __DIR__ . '/mailer.php';
  try {
    // Prefer a configured public base URL to avoid Host header injection.
    // Fallback to the canonical production domain if not configured.
    $base = (defined('STU_PUBLIC_BASE_URL') && STU_PUBLIC_BASE_URL) ? rtrim(STU_PUBLIC_BASE_URL, '/') : '';
    $resetUrl = $base . '/reset_password.html';
    stu_send_mail(
      $email,
      'Starlight Unit – Reset-Code',
      "Hallo!\n\n" .
      "Du hast einen Reset-Code für dein Starlight Unit Passwort angefordert.\n\n" .
      "Dein Code: " . $code . "\n" .
      "Gültig für 15 Minuten.\n\n" .
      "Passwort zurücksetzen: " . $resetUrl . "\n" .
      "(Dort E-Mail + Code eingeben und neues Passwort setzen.)\n\n" .
      "Wichtig: Gib den Code in der App oder auf der Reset-Seite ein.\n" .
      "Wenn du das nicht warst, kannst du diese Mail ignorieren.\n"
    );
  } catch (Throwable $e) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'email_send_failed', 'detail' => $e->getMessage()]);
    stu_json(['ok' => true]);
  }

  stu_json(['ok' => true]);
}

if ($action === 'reset') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  stu_rate_limit($action, 10, 300);

  $body = stu_read_json_body();
  $token = (string)($body['token'] ?? ''); // legacy link-token flow
  $email = strtolower(trim((string)($body['email'] ?? '')));
  $code  = trim((string)($body['code'] ?? ''));
  $newPass = (string)($body['new_password'] ?? '');

  if (strlen($newPass) < 8) stu_json(['ok' => false, 'error' => 'invalid_password'], 400);

  $pdo = stu_pdo();

  // --- Preferred flow: email + 6-digit reset code
  if ($email && $code) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) stu_json(['ok' => false, 'error' => 'invalid_email'], 400);
    if (!preg_match('/^[0-9]{6}$/', $code)) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);

    $stmt = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
    $stmt->execute([$email]);
    $uid = $stmt->fetchColumn();
    if (!$uid) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);
    if (coreui_security_advanced($pdo, (int)$uid)) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);

    // Ensure schema has code_hash
    $hasCode = false;
    try {
      $st = $pdo->query("SHOW COLUMNS FROM stu_password_resets LIKE 'code_hash'");
      $hasCode = (bool)$st->fetch();
    } catch (Throwable $e) { $hasCode = false; }
    if (!$hasCode) stu_json(['ok' => false, 'error' => 'missing_schema_reset_code'], 500);

    $stmt = $pdo->prepare('SELECT id, code_hash, expires_at, used_at, attempts FROM stu_password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([(int)$uid]);
    $row = $stmt->fetch();
    if (!$row) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);
    if (!empty($row['used_at'])) stu_json(['ok' => false, 'error' => 'code_used'], 400);
    if (strtotime((string)$row['expires_at']) < time()) stu_json(['ok' => false, 'error' => 'code_expired'], 400);
    if ((int)($row['attempts'] ?? 0) >= 5) stu_json(['ok' => false, 'error' => 'too_many_attempts'], 429);

    $ok = false;
    try { $ok = password_verify($code, (string)($row['code_hash'] ?? '')); } catch (Throwable $e) { $ok = false; }
    if (!$ok) {
      $pdo->prepare('UPDATE stu_password_resets SET attempts = attempts + 1 WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
      stu_json(['ok' => false, 'error' => 'invalid_code'], 400);
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    if (!$hash) stu_json(['ok' => false, 'error' => 'hash_failed'], 500);

    try {
      $pdo->prepare('UPDATE stu_users SET password_hash = ?, password_changed_at=NOW() WHERE id = ? LIMIT 1')->execute([$hash, (int)$uid]);
    } catch (Throwable $e) {
      $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1')->execute([$hash, (int)$uid]);
    }
    $pdo->prepare('UPDATE stu_password_resets SET used_at = NOW() WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
    coreui_auth_session_revoke_all($pdo, (int)$uid, 'password_reset');
    coreui_security_event($pdo, (int)$uid, 'password_reset', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    coreui_notify_security($pdo, (int)$uid, 'password', '');
    stu_json(['ok' => true]);
  }

  // --- Legacy fallback: token link (kept so old mails don't break)
  if (strlen($token) < 20) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);

  try {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM stu_password_resets WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
  } catch (Throwable $e) {
    stu_json(['ok' => false, 'error' => 'invalid_token'], 400);
  }

  if (!$row) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);
  if (!empty($row['used_at'])) stu_json(['ok' => false, 'error' => 'token_used'], 400);
  if (strtotime((string)$row['expires_at']) < time()) stu_json(['ok' => false, 'error' => 'token_expired'], 400);
  if (coreui_security_advanced($pdo, (int)$row['user_id'])) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);

  $hash = password_hash($newPass, PASSWORD_DEFAULT);
  if (!$hash) stu_json(['ok' => false, 'error' => 'hash_failed'], 500);

  try {
    $pdo->prepare('UPDATE stu_users SET password_hash = ?, password_changed_at=NOW() WHERE id = ? LIMIT 1')->execute([$hash, (int)$row['user_id']]);
  } catch (Throwable $e) {
    $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1')->execute([$hash, (int)$row['user_id']]);
  }
  $pdo->prepare('UPDATE stu_password_resets SET used_at = NOW() WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
  coreui_auth_session_revoke_all($pdo, (int)$row['user_id'], 'password_reset');
  coreui_security_event($pdo, (int)$row['user_id'], 'password_reset', coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
  coreui_notify_security($pdo, (int)$row['user_id'], 'password', '');

  stu_json(['ok' => true]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
