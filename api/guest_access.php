<?php
declare(strict_types=1);

/*
 * 0.5.77: guest access from the login page ("Als Gast chatten").
 *
 * An admin switches it on in /admin/ (Runtime) and sets how many messages a
 * guest may send (default 5). The button creates a guest account (is_guest,
 * no e-mail, no password), provisions it like a normal account and logs it
 * in, so the chat starts at once. A guest only uses the private console.
 *
 * Every accepted turn counts: a sent message, "Neu generieren", "Weiter"
 * and an edited message. The counter lives per account in stu_kv, so
 * deleting a chat does not give the messages back. It is reserved
 * atomically before the turn is stored; the turn past the limit is refused.
 * New guest accounts are capped server wide per hour against abuse.
 */

require_once __DIR__ . '/account_store.php';

const COREUI_GUEST_LIMIT_DEFAULT = 5;
const COREUI_GUEST_LIMIT_MAX = 100;
const COREUI_GUEST_HOURLY_CAP = 30;
const COREUI_GUEST_KEY_PREFIX = 'chat_';
const COREUI_GUEST_COUNTER = 'coreui_guest_used';

function coreui_guest_enabled(PDO $pdo): bool {
  try {
    return stu_app_setting_get($pdo, 'guest_enabled', '0') === '1';
  } catch (Throwable $e) {
    return false;
  }
}

/** Admin value to a limit: whole messages from 1 to 100, anything else the default. */
function coreui_guest_limit_normalise(mixed $value): int {
  $text = trim((string)$value);
  if (preg_match('~^\d{1,4}$~', $text) !== 1) return COREUI_GUEST_LIMIT_DEFAULT;
  return max(1, min(COREUI_GUEST_LIMIT_MAX, (int)$text));
}

function coreui_guest_limit(PDO $pdo): int {
  try {
    return coreui_guest_limit_normalise(stu_app_setting_get($pdo, 'guest_message_limit', (string)COREUI_GUEST_LIMIT_DEFAULT));
  } catch (Throwable $e) {
    return COREUI_GUEST_LIMIT_DEFAULT;
  }
}

function coreui_guest_is(PDO $pdo, int $uid): bool {
  if ($uid <= 0) return false;
  $st = $pdo->prepare('SELECT is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  return (int)$st->fetchColumn() === 1;
}

function coreui_guest_used(PDO $pdo, int $uid): int {
  $st = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id = ? AND k = ? LIMIT 1');
  $st->execute([$uid, COREUI_GUEST_COUNTER]);
  return max(0, (int)$st->fetchColumn());
}

/** What the chat shows a guest: null for normal accounts. */
function coreui_guest_quota(PDO $pdo, int $uid): ?array {
  if (!coreui_guest_is($pdo, $uid)) return null;
  $limit = coreui_guest_limit($pdo);
  $used = min($limit, coreui_guest_used($pdo, $uid));
  return ['limit'=>$limit, 'used'=>$used, 'left'=>max(0, $limit - $used)];
}

/**
 * Counts one turn of a guest, or refuses it (pure decision, for the tests):
 * the counter after the atomic increment decides.
 */
function coreui_guest_turn_allowed(int $countAfter, int $limit): bool {
  return $countAfter <= $limit;
}

/**
 * Reserves one turn for a guest before it is stored, or answers
 * guest_limit_reached. Normal accounts pass untouched. Guests chat only in
 * the private console.
 */
function coreui_guest_reserve_turn(PDO $pdo, int $uid, string $channel = 'console'): ?array {
  if (!coreui_guest_is($pdo, $uid)) return null;
  if ($channel !== 'console') stu_json(['ok'=>false, 'error'=>'guest_console_only'], 403);
  $limit = coreui_guest_limit($pdo);
  $pdo->prepare(
    'INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, \'1\', NOW()) '
    . 'ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1, updated_at = NOW()'
  )->execute([$uid, COREUI_GUEST_COUNTER]);
  $count = coreui_guest_used($pdo, $uid);
  if (!coreui_guest_turn_allowed($count, $limit)) {
    stu_json(['ok'=>false, 'error'=>'guest_limit_reached', 'guest'=>['limit'=>$limit, 'used'=>$limit, 'left'=>0]], 403);
  }
  return ['limit'=>$limit, 'used'=>$count, 'left'=>max(0, $limit - $count)];
}

/** Creates and provisions a guest account; the caller logs it in. */
function coreui_guest_create(PDO $pdo): int {
  $recent = $pdo->prepare(
    "SELECT COUNT(*) FROM stu_users WHERE is_guest = 1 AND guest_key LIKE ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
  );
  $recent->execute([COREUI_GUEST_KEY_PREFIX . '%']);
  if ((int)$recent->fetchColumn() >= COREUI_GUEST_HOURLY_CAP) throw new RuntimeException('guest_capacity');

  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    $pdo->prepare(
      'INSERT INTO stu_users (guest_key, created_at, username, password_hash, is_guest, permission_level) '
      . 'VALUES (?, NOW(), NULL, NULL, 1, 4)'
    )->execute([COREUI_GUEST_KEY_PREFIX . bin2hex(random_bytes(16))]);
    $uid = (int)$pdo->lastInsertId();
    coreui_account_provision($pdo, $uid, '', 'Gast');
    if ($started) $pdo->commit();
    return $uid;
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
