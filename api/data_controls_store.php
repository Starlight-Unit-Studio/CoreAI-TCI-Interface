<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.32: Settings > Datenkontrollen after the reference screenshot.
 *
 *   coreui_data_controls_summary   how many chats are active, archived and locked
 *   coreui_data_controls_archive_all   archives every active chat (not the locked ones)
 *   coreui_data_controls_delete_all    deletes every chat for good, the same way the chat menu does
 *
 * Locked chats (Chat sperren, PIN) are never touched here: they stay hidden until unlocked with the PIN.
 * A chat in which Mara is still working is skipped (session_busy) and stays as it was.
 * Only the signed-in account's own chats.
 */

require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_chat_tools.php';
require_once __DIR__ . '/console_chat_lock_store.php';

const COREUI_DATA_CONTROLS_ARCHIVE_CONFIRM = 'ARCHIVE_ALL_CHATS';
const COREUI_DATA_CONTROLS_DELETE_CONFIRM = 'DELETE_ALL_CHATS';

/** SQL for chats that are not locked (empty without migration 038). */
function coreui_data_controls_unlocked(PDO $pdo): string {
  return coreui_console_chat_lock_ready($pdo) ? ' AND locked_at IS NULL' : '';
}

/** Counts of the account's chats: active, archived (both without the locked ones) and locked. */
function coreui_data_controls_summary(PDO $pdo, int $uid): array {
  $free = coreui_data_controls_unlocked($pdo);
  $count = static function (string $where) use ($pdo, $uid): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_sessions WHERE user_id = ? AND ' . $where);
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
  };
  return [
    'active' => $count('archived_at IS NULL' . $free),
    'archived' => $count('archived_at IS NOT NULL' . $free),
    'locked' => coreui_console_chat_lock_ready($pdo) ? $count('locked_at IS NOT NULL') : 0,
  ];
}

/** Archives every active chat that is not locked; returns how many. Pins go, like for one chat. */
function coreui_data_controls_archive_all(PDO $pdo, int $uid, string $confirm): int {
  if (!hash_equals(COREUI_DATA_CONTROLS_ARCHIVE_CONFIRM, $confirm)) throw new InvalidArgumentException('archive_all_confirmation_required');
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $free = coreui_data_controls_unlocked($pdo);
  $st = $pdo->prepare('UPDATE stu_console_sessions SET archived_at = NOW() WHERE user_id = ? AND archived_at IS NULL' . $free);
  $st->execute([$uid]);
  $n = $st->rowCount();
  if (coreui_console_session_pin_ready($pdo)) {
    $pdo->prepare('UPDATE stu_console_sessions SET pinned_at = NULL WHERE user_id = ? AND archived_at IS NOT NULL' . $free)->execute([$uid]);
  }
  return $n;
}

/**
 * Deletes every chat of the account that is not locked, active or archived, with its messages and files
 * (coreui_console_session_delete, like "Löschen" in the chat menu). A chat Mara is still working in stays.
 * Returns ['deleted' => n, 'busy' => n, 'failed' => n, 'locked' => n, 'files' => n].
 */
function coreui_data_controls_delete_all(PDO $pdo, int $uid, string $confirm): array {
  if (!hash_equals(COREUI_DATA_CONTROLS_DELETE_CONFIRM, $confirm)) throw new InvalidArgumentException('delete_all_confirmation_required');
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $free = coreui_data_controls_unlocked($pdo);
  $st = $pdo->prepare('SELECT id, archived_at FROM stu_console_sessions WHERE user_id = ?' . $free . ' ORDER BY updated_at');
  $st->execute([$uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = ['deleted' => 0, 'busy' => 0, 'failed' => 0, 'locked' => coreui_data_controls_summary($pdo, $uid)['locked'], 'files' => 0];
  if (function_exists('set_time_limit')) @set_time_limit(max(60, count($rows) * 5));
  foreach ($rows as $row) {
    $id = (string)$row['id'];
    $archivedNow = false;
    try {
      if (empty($row['archived_at'])) {
        coreui_console_session_archive($pdo, $uid, $id);
        $archivedNow = true;
      }
      $result = coreui_console_session_delete($pdo, $uid, $id);
      $out['deleted']++;
      $out['files'] += (int)($result['files_deleted'] ?? 0);
    } catch (Throwable $e) {
      // Like one chat: if deleting fails, an active chat comes back from the archive.
      if ($archivedNow) {
        try { coreui_console_session_restore($pdo, $uid, $id); } catch (Throwable $ignored) {}
      }
      if ($e->getMessage() === 'session_busy') $out['busy']++;
      else $out['failed']++;
    }
  }
  return $out;
}
