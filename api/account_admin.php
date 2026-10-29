<?php
declare(strict_types=1);

/*
 * 0.5.85: settings per account under Admin Core > Benutzer.
 *
 * Who may change whom follows the same rules as deleting: the Inhaber
 * manages Admins, Nutzer and Gaeste, an Admin only Nutzer and Gaeste; nobody
 * touches another Inhaber or Mara's system account. On one's own account only
 * name and e-mail can be changed here; the own password and devices stay in
 * the Konto tab, where the current password protects them.
 */

require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/auth_session_store.php';

const COREUI_ACCOUNT_PASSWORD_MIN = 12;

/** Why the actor may not change the target ('' = allowed). Pure. */
function coreui_account_manage_refusal(int $actorId, int $actorLevel, array $target, bool $selfAllowed = false): string {
  $targetId = (int)($target['id'] ?? 0);
  $level = (int)($target['permission_level'] ?? 4);
  if ($targetId <= 0) return 'invalid_user';
  if ($targetId === (int)(defined('STU_EMBER_USER_ID') ? STU_EMBER_USER_ID : 1)) return 'protected_account';
  if ($targetId === $actorId) return $selfAllowed ? '' : 'use_account_settings';
  if ($actorLevel > 1) return 'insufficient_permissions';
  if ($level === 0) return 'protected_account';
  if ($actorLevel > 0 && $level <= $actorLevel) return 'protected_account';
  return '';
}

function coreui_account_admin_target(PDO $pdo, int $uid): ?array {
  $st = $pdo->prepare('SELECT id, username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/** Active logins of an account (not revoked, not expired). */
function coreui_account_login_count(PDO $pdo, int $uid): int {
  if (!coreui_auth_session_schema_ready($pdo)) return 0;
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_auth_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()');
  $st->execute([$uid]);
  return (int)$st->fetchColumn();
}

/**
 * Sets a new password and signs the account out everywhere (its old logins
 * would otherwise stay valid). Returns how many logins were ended.
 */
function coreui_account_set_password(PDO $pdo, int $uid, string $password): int {
  if (strlen($password) < COREUI_ACCOUNT_PASSWORD_MIN || strlen($password) > 1024) throw new InvalidArgumentException('invalid_password');
  $target = coreui_account_admin_target($pdo, $uid);
  if ($target === null) throw new RuntimeException('user_not_found');
  if ((int)$target['is_guest'] === 1) throw new RuntimeException('guest_account');
  $hash = password_hash($password, PASSWORD_DEFAULT);
  if (!is_string($hash) || $hash === '') throw new RuntimeException('hash_failed');
  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE stu_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ? AND is_guest = 0 LIMIT 1')->execute([$hash, $uid]);
    $ended = coreui_auth_session_revoke_all($pdo, $uid, 'admin_password_set');
    if ($started) $pdo->commit();
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return $ended;
}

/** Ends every login of the account; it has to sign in again. */
function coreui_account_logout_all(PDO $pdo, int $uid): int {
  if (coreui_account_admin_target($pdo, $uid) === null) throw new RuntimeException('user_not_found');
  return coreui_auth_session_revoke_all($pdo, $uid, 'admin_logout_all');
}

/**
 * Display name and login e-mail. Guests keep their generated login, only the
 * name changes. Returns what is stored now.
 */
function coreui_account_set_identity(PDO $pdo, int $uid, string $displayName, string $email): array {
  $target = coreui_account_admin_target($pdo, $uid);
  if ($target === null) throw new RuntimeException('user_not_found');
  $guest = (int)$target['is_guest'] === 1;
  $oldEmail = (string)($target['username'] ?? '');
  $email = $guest ? $oldEmail : coreui_account_clean_email($email);
  if (!$guest && !coreui_account_valid_email($email)) throw new InvalidArgumentException('invalid_username');
  $name = coreui_account_clean_display_name($displayName, '');
  if ($name === '') throw new InvalidArgumentException('invalid_display_name');

  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    if ($email !== $oldEmail) {
      $taken = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? AND id <> ? LIMIT 1');
      $taken->execute([$email, $uid]);
      if ($taken->fetchColumn()) throw new RuntimeException('username_taken');
      $pdo->prepare('UPDATE stu_users SET username = ? WHERE id = ? LIMIT 1')->execute([$email, $uid]);
    }
    $profile = $pdo->prepare('UPDATE stu_coreui_profiles SET display_name = ?, updated_at = NOW() WHERE user_id = ?');
    $profile->execute([$name, $uid]);
    if ($profile->rowCount() === 0) {
      $exists = $pdo->prepare('SELECT COUNT(*) FROM stu_coreui_profiles WHERE user_id = ?');
      $exists->execute([$uid]);
      if ((int)$exists->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name, created_at, updated_at) VALUES (?, ?, 'Mara', NOW(), NOW())")
          ->execute([$uid, $name]);
      }
    }
    if ($started) $pdo->commit();
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return ['username' => $email, 'display_name' => $name, 'email_changed' => $email !== $oldEmail];
}
