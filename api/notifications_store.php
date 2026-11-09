<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.81: Settings > Benachrichtigungen.
 *
 * Per account and category the chosen channels: '' (aus), 'push', 'email' or 'push,email'.
 * Real categories (they can fire today):
 *   answers   Mara's answer took a while (research, images, long work): default Push
 *   security  a new sign-in, a changed password: default E-Mail
 *   updates   a new CoreAI version is ready to install (Inhaber only): default Push
 * Planned categories are shown greyed in the settings ("In Vorbereitung") and never fire.
 *
 * Push goes to every browser of the account that switched it on (api/web_push.php); a browser
 * that is gone (404/410) is removed. E-Mail uses the existing SMTP mailer and only when it is
 * configured on this server. Everything runs after the response is sent, so no request waits
 * for a push service or a mail server.
 */

require_once __DIR__ . '/web_push.php';

const COREUI_NOTIFY_ANSWER_SECONDS = 45;
const COREUI_PUSH_MAX_DEVICES = 10;
const COREUI_PUSH_MAX_FAILURES = 10;

/** The categories (pure): default channels, whether planned, whether only for the Inhaber. */
function coreui_notify_categories(): array {
  return [
    'answers' => ['default' => 'push', 'planned' => false, 'owner' => false],
    'security' => ['default' => 'email', 'planned' => false, 'owner' => false],
    'updates' => ['default' => 'push', 'planned' => false, 'owner' => true],
    'tasks' => ['default' => 'push', 'planned' => true, 'owner' => false],
    'agents' => ['default' => 'email', 'planned' => true, 'owner' => false],
    'library' => ['default' => 'email', 'planned' => true, 'owner' => false],
    'projects' => ['default' => 'email', 'planned' => true, 'owner' => false],
    'workspace' => ['default' => 'email', 'planned' => true, 'owner' => false],
  ];
}

/** Channels in their one canonical spelling (pure): '', 'push', 'email' or 'push,email'. */
function coreui_notify_channels(string $value): string {
  $parts = array_map('trim', explode(',', strtolower($value)));
  $push = in_array('push', $parts, true);
  $email = in_array('email', $parts, true);
  return $push && $email ? 'push,email' : ($push ? 'push' : ($email ? 'email' : ''));
}

function coreui_notify_schema_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo->query('SELECT 1 FROM stu_notification_prefs LIMIT 1');
    $pdo->query('SELECT 1 FROM stu_push_subscriptions LIMIT 1');
    return $ready = true;
  } catch (Throwable $e) {
    return $ready = false;
  }
}

/** The account's channels for every category, defaults where nothing is saved. */
function coreui_notify_prefs(PDO $pdo, int $uid): array {
  $out = [];
  foreach (coreui_notify_categories() as $id => $cat) $out[$id] = $cat['default'];
  if ($uid <= 0 || !coreui_notify_schema_ready($pdo)) return $out;
  $st = $pdo->prepare('SELECT category, channels FROM stu_notification_prefs WHERE user_id = ?');
  $st->execute([$uid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $id = (string)$row['category'];
    if (isset($out[$id]) && !coreui_notify_categories()[$id]['planned']) $out[$id] = coreui_notify_channels((string)$row['channels']);
  }
  return $out;
}

/** Saves the account's choice for the real categories it may use; planned ones are ignored. */
function coreui_notify_save(PDO $pdo, int $uid, array $prefs, bool $owner): array {
  if (!coreui_notify_schema_ready($pdo)) throw new RuntimeException('notifications_migration_required');
  $cats = coreui_notify_categories();
  foreach ($prefs as $id => $value) {
    $id = (string)$id;
    if (!isset($cats[$id]) || $cats[$id]['planned'] || ($cats[$id]['owner'] && !$owner) || !is_string($value)) continue;
    $channels = coreui_notify_channels($value);
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
      ? 'INSERT INTO stu_notification_prefs (user_id, category, channels) VALUES (?, ?, ?) '
        . 'ON CONFLICT(user_id, category) DO UPDATE SET channels = excluded.channels'
      : 'INSERT INTO stu_notification_prefs (user_id, category, channels) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE channels = VALUES(channels)';
    $pdo->prepare($sql)->execute([$uid, $id, $channels]);
  }
  return coreui_notify_prefs($pdo, $uid);
}

/** The account's browsers with Push (no keys, no endpoint: only what the settings show). */
function coreui_push_devices(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_notify_schema_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT id, endpoint_hash, label, created_at, last_success_at FROM stu_push_subscriptions WHERE user_id = ? ORDER BY id');
  $st->execute([$uid]);
  return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'key' => substr((string)$r['endpoint_hash'], 0, 16),
    'label' => (string)$r['label'], 'created_at' => (string)$r['created_at'], 'last_success_at' => $r['last_success_at']],
    $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** The short key the settings use to recognise "this browser" (pure). */
function coreui_push_endpoint_key(string $endpoint): string {
  return substr(hash('sha256', $endpoint), 0, 16);
}

/** Stores (or moves to this account) a browser's push subscription. */
function coreui_push_subscribe(PDO $pdo, int $uid, string $endpoint, string $p256dh, string $auth, string $label): array {
  if (!coreui_notify_schema_ready($pdo)) throw new RuntimeException('notifications_migration_required');
  if (!coreui_push_endpoint_allowed($endpoint)) throw new InvalidArgumentException('push_endpoint_not_allowed');
  $ua = coreui_b64url_decode($p256dh);
  $secret = coreui_b64url_decode($auth);
  if ($ua === null || strlen($ua) !== 65 || $ua[0] !== "\x04" || coreui_push_public_key($ua) === null
      || $secret === null || strlen($secret) !== 16) {
    throw new InvalidArgumentException('push_keys_invalid');
  }
  $hash = hash('sha256', $endpoint);
  $label = mb_substr(trim(preg_replace('~[\x00-\x1F\x7F]+~u', ' ', $label) ?? ''), 0, 80);
  $pdo->prepare('DELETE FROM stu_push_subscriptions WHERE endpoint_hash = ?')->execute([$hash]);
  $count = $pdo->prepare('SELECT COUNT(*) FROM stu_push_subscriptions WHERE user_id = ?');
  $count->execute([$uid]);
  if ((int)$count->fetchColumn() >= COREUI_PUSH_MAX_DEVICES) throw new RuntimeException('push_too_many_devices');
  $pdo->prepare('INSERT INTO stu_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, label) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$uid, $hash, $endpoint, $p256dh, $auth, $label]);
  return ['id' => (int)$pdo->lastInsertId(), 'key' => substr($hash, 0, 16)];
}

/** Removes one of the account's browsers (by id or by its endpoint). */
function coreui_push_unsubscribe(PDO $pdo, int $uid, int $id, string $endpoint = ''): int {
  if (!coreui_notify_schema_ready($pdo)) return 0;
  if ($endpoint !== '') {
    $st = $pdo->prepare('DELETE FROM stu_push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
    $st->execute([$uid, hash('sha256', $endpoint)]);
  } else {
    $st = $pdo->prepare('DELETE FROM stu_push_subscriptions WHERE user_id = ? AND id = ?');
    $st->execute([$uid, $id]);
  }
  return $st->rowCount();
}

/** A value of stu_app_settings (no file fallback: the VAPID key never leaves the database). */
function coreui_notify_setting(PDO $pdo, string $key): ?string {
  try {
    $st = $pdo->prepare('SELECT `value` FROM stu_app_settings WHERE `k` = ? LIMIT 1');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value === false || $value === null ? null : (string)$value;
  } catch (Throwable $e) {
    return null;
  }
}

/** Writes a value of stu_app_settings; $onlyNew keeps an existing one (first writer wins). */
function coreui_notify_setting_put(PDO $pdo, string $key, string $value, bool $onlyNew = false): void {
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  if ($onlyNew) {
    $sql = $sqlite ? 'INSERT OR IGNORE INTO stu_app_settings (`k`, `value`) VALUES (?, ?)'
      : 'INSERT IGNORE INTO stu_app_settings (`k`, `value`) VALUES (?, ?)';
  } else {
    $sql = $sqlite ? 'INSERT INTO stu_app_settings (`k`, `value`) VALUES (?, ?) ON CONFLICT(`k`) DO UPDATE SET `value` = excluded.`value`'
      : 'INSERT INTO stu_app_settings (`k`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
  }
  $pdo->prepare($sql)->execute([$key, $value]);
}

/** This server's VAPID key pair: created once, kept in the database. */
function coreui_push_vapid(PDO $pdo): array {
  $found = coreui_push_vapid_from(coreui_notify_setting($pdo, 'push_vapid'));
  if ($found !== null) return $found;
  $new = coreui_push_vapid_create();
  coreui_notify_setting_put($pdo, 'push_vapid', (string)json_encode($new + ['created' => gmdate('c')]), true);
  // Two first requests at once: both read back the one that was stored first.
  $found = coreui_push_vapid_from(coreui_notify_setting($pdo, 'push_vapid'));
  if ($found === null) throw new RuntimeException('push_key_store_failed');
  return $found;
}

/** Whether E-Mail can be sent on this server (SMTP from the server or from Admin Core, 0.6.82). */
function coreui_notify_mail_available(?PDO $pdo = null): bool {
  if (!function_exists('stu_mail_enabled')) {
    $file = __DIR__ . '/mailer.php';
    if (!is_file($file)) return false;
    require_once $file;
  }
  try { return stu_mail_enabled($pdo); } catch (Throwable $e) { return false; }
}

/** The VAPID subject: the configured sender, the public URL or a neutral fallback (pure). */
function coreui_push_subject(): string {
  if (defined('STU_MAIL_FROM') && filter_var((string)STU_MAIL_FROM, FILTER_VALIDATE_EMAIL)) return 'mailto:' . STU_MAIL_FROM;
  if (defined('STU_PUBLIC_BASE_URL') && preg_match('~^https://~', (string)STU_PUBLIC_BASE_URL) === 1) return (string)STU_PUBLIC_BASE_URL;
  return 'mailto:coreai@localhost.invalid';
}

/** An absolute link for E-Mails when the public URL is known (pure). */
function coreui_notify_link(string $path): string {
  $base = defined('STU_PUBLIC_BASE_URL') ? (string)STU_PUBLIC_BASE_URL : '';
  return $base !== '' ? $base . '/' . ltrim($path, './') : '';
}

/**
 * Sends one notification to the account along its chosen channels. $message: title, body,
 * url (relative, for example ./app.html), tag. $push and $mail replace the real senders in
 * tests. Returns what was sent.
 */
function coreui_notify_dispatch(PDO $pdo, int $uid, string $category, array $message, ?callable $push = null, ?callable $mail = null): array {
  // Offline selftests replace the senders (scripts/notifications-selftest.php); never set in production.
  $push = $push ?? ($GLOBALS['COREUI_NOTIFY_TEST_PUSH'] ?? null);
  $mail = $mail ?? ($GLOBALS['COREUI_NOTIFY_TEST_MAIL'] ?? null);
  $result = ['push' => 0, 'push_failed' => 0, 'push_removed' => 0, 'email' => false];
  $cats = coreui_notify_categories();
  if ($uid <= 0 || !isset($cats[$category]) || $cats[$category]['planned'] || !coreui_notify_schema_ready($pdo)) return $result;
  $channels = explode(',', coreui_notify_prefs($pdo, $uid)[$category]);
  $payload = [
    'title' => mb_substr((string)($message['title'] ?? 'CoreAI'), 0, 120),
    'body' => mb_substr((string)($message['body'] ?? ''), 0, 400),
    'url' => preg_match('~^\./[A-Za-z0-9_./?#=&%-]*$~', (string)($message['url'] ?? '')) === 1 ? (string)$message['url'] : './app.html',
    'tag' => 'coreai-' . $category . (isset($message['tag']) ? '-' . preg_replace('~[^a-z0-9_-]~i', '', (string)$message['tag']) : ''),
    'category' => $category,
    'skipIfVisible' => !empty($message['skip_if_visible']),
  ];
  if (in_array('push', $channels, true)) {
    $st = $pdo->prepare('SELECT id, endpoint, p256dh, auth, failures FROM stu_push_subscriptions WHERE user_id = ?');
    $st->execute([$uid]);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $vapid = null;
    if ($subs !== [] && $push === null) {
      try { $vapid = coreui_push_vapid($pdo); } catch (Throwable $e) { $subs = []; }
    }
    foreach ($subs as $sub) {
      $sent = $push !== null ? $push($sub, $payload) : coreui_push_send($sub, $payload, coreui_push_subject(), $vapid);
      if (!empty($sent['ok'])) {
        $result['push']++;
        $pdo->prepare('UPDATE stu_push_subscriptions SET failures = 0, last_success_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$sub['id']]);
      } elseif (!empty($sent['gone']) || (int)$sub['failures'] + 1 >= COREUI_PUSH_MAX_FAILURES) {
        $result['push_removed']++;
        $pdo->prepare('DELETE FROM stu_push_subscriptions WHERE id = ?')->execute([(int)$sub['id']]);
      } else {
        $result['push_failed']++;
        $pdo->prepare('UPDATE stu_push_subscriptions SET failures = failures + 1 WHERE id = ?')->execute([(int)$sub['id']]);
      }
    }
  }
  if (in_array('email', $channels, true)) {
    $st = $pdo->prepare('SELECT username, is_guest FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $address = (string)($user['username'] ?? '');
    if ((int)($user['is_guest'] ?? 1) === 0 && filter_var($address, FILTER_VALIDATE_EMAIL)) {
      $link = coreui_notify_link($payload['url']);
      $text = $payload['body'] . ($link !== '' ? "\n\n" . $link : '')
        . "\n\n-- \nCoreAI: TCI Interface. Diese Benachrichtigung kannst du unter Einstellungen > Benachrichtigungen ändern.";
      try {
        if ($mail !== null) $mail($address, $payload['title'], $text);
        elseif (coreui_notify_mail_available($pdo)) stu_send_mail($address, $payload['title'], $text, coreui_mail_effective($pdo));
        else throw new RuntimeException('mail_not_configured');
        $result['email'] = true;
      } catch (Throwable $e) {
        if (function_exists('stu__log_error')) stu__log_error(['type' => 'notify_mail_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
      }
    }
  }
  return $result;
}

/**
 * Runs $job after the response has gone out (fastcgi_finish_request), so no request waits for
 * a push service or a mail server. The session is written first, so the next request of the
 * same browser is not held by its lock. Errors are logged, never shown.
 */
function coreui_notify_later(callable $job): void {
  if (!empty($GLOBALS['COREUI_NOTIFY_INLINE'])) { $job(); return; } // offline selftests only
  static $jobs = null;
  if ($jobs === null) {
    $jobs = [];
    register_shutdown_function(static function () use (&$jobs): void {
      if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
      if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
      foreach ($jobs as $run) {
        try { $run(); } catch (Throwable $e) {
          if (function_exists('stu__log_error')) stu__log_error(['type' => 'notify_failed', 'message' => $e->getMessage()]);
        }
      }
    });
  }
  $jobs[] = $job;
}

/** The text of an answer for a notification: no Markdown, no markers, at most $max characters (pure). */
function coreui_notify_snippet(string $text, int $max = 160): string {
  $text = (string)preg_replace('~```.*?```~su', ' ', $text);
  $text = (string)preg_replace('~\[(?:BILD|WEB|BROWSE|ARCHIV|SKILL)[^\]]*\]|\[/?PY\]~iu', ' ', $text);
  $text = (string)preg_replace('~[*_`#>|]+~u', '', $text);
  $text = trim((string)preg_replace('~\s+~u', ' ', $text));
  return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

/** Answers: Mara's reply to message $replyToId is saved; notify when the turn took long. */
function coreui_notify_answer(PDO $pdo, int $uid, string $sessionId, int $replyToId, string $text, string $assistant): void {
  if ($uid <= 0 || $replyToId <= 0 || !coreui_notify_schema_ready($pdo)) return;
  try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
      ? "SELECT CAST(strftime('%s','now') AS INTEGER) - CAST(strftime('%s', created_at) AS INTEGER) FROM stu_chat_messages WHERE id = ? AND user_id = ?"
      : 'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM stu_chat_messages WHERE id = ? AND user_id = ?';
    $st = $pdo->prepare($sql);
    $st->execute([$replyToId, $uid]);
    $seconds = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($seconds < COREUI_NOTIFY_ANSWER_SECONDS) return;
  $snippet = coreui_notify_snippet($text);
  $message = [
    'title' => $assistant . ' hat geantwortet',
    'body' => $snippet !== '' ? $snippet : 'Deine Antwort ist fertig.',
    'url' => './app.html?session=' . rawurlencode($sessionId),
    'tag' => substr(preg_replace('~[^a-z0-9_]~i', '', $sessionId), 0, 40),
    'skip_if_visible' => true,
  ];
  coreui_notify_later(static fn() => coreui_notify_dispatch($pdo, $uid, 'answers', $message));
}

/** Security: a sign-in or a password change of the account. */
function coreui_notify_security(PDO $pdo, int $uid, string $what, string $device): void {
  if ($uid <= 0 || !coreui_notify_schema_ready($pdo)) return;
  $when = date('d.m.Y H:i');
  $message = $what === 'password'
    ? ['title' => 'Dein CoreAI-Passwort wurde geändert',
      'body' => 'Am ' . $when . ' wurde das Passwort deines Kontos geändert. Warst du das nicht? Setze es sofort zurück.',
      'url' => './settings.html#sicherheit', 'tag' => 'password']
    : ['title' => 'Neue Anmeldung bei CoreAI',
      'body' => 'Am ' . $when . ' hat sich ' . ($device !== '' ? $device : 'ein Gerät') . ' bei deinem Konto angemeldet. '
        . 'Warst du das nicht? Ändere dein Passwort und melde alle Geräte ab.',
      'url' => './settings.html#sicherheit', 'tag' => 'login'];
  coreui_notify_later(static fn() => coreui_notify_dispatch($pdo, $uid, 'security', $message));
}

/**
 * Updates: once per new version, the Inhaber hears that it is ready to install (the last
 * announced version is kept in stu_app_settings).
 */
function coreui_notify_update_available(PDO $pdo, array $state): bool {
  $version = (string)($state['latest_version'] ?? '');
  if (empty($state['available']) || $version === '' || !coreui_notify_schema_ready($pdo)) return false;
  if (coreui_notify_setting($pdo, 'notify_update_announced') === $version) return false;
  try { coreui_notify_setting_put($pdo, 'notify_update_announced', $version); } catch (Throwable $e) { return false; }
  $owners = $pdo->query('SELECT id FROM stu_users WHERE permission_level = 0 AND is_guest = 0')->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $message = ['title' => 'CoreAI ' . $version . ' ist bereit',
    'body' => 'Eine neue CoreAI-Version kann installiert werden (installiert: ' . (string)($state['current_version'] ?? '?') . ').',
    'url' => './settings.html#system', 'tag' => 'update'];
  foreach ($owners as $owner) {
    coreui_notify_later(static fn() => coreui_notify_dispatch($pdo, (int)$owner, 'updates', $message));
  }
  return true;
}
