<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.87: the chat menu and Settings > Archivierte Chats (like the ChatGPT reference).
 *   - pin and unpin a chat (migration 032), archiving releases the pin
 *   - the archived chats, searchable by title, filtered by files or images
 *   - the files the person uploaded in one chat ("Hochgeladene Dateien")
 *   - search inside one chat ("Im Chat suchen"), every message, not only the loaded ones
 *   - the chat as Markdown ("Teilen": the person decides where it goes; no public link)
 * Every function works on the signed-in account's own chats only.
 */

require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_composer_intent.php';
require_once __DIR__ . '/profile_store.php';

const COREUI_CHAT_GENERATED_PREFIX = 'var/console_media/images/';

/** The CoreAI's own character id (Mara's messages). */
function coreui_chat_assistant_id(): string {
  if (function_exists('ember_character_id')) return strtolower(ember_character_id());
  $cid = defined('STU_EMBER_CHARACTER_ID') ? trim((string)STU_EMBER_CHARACTER_ID) : '';
  return strtolower($cid !== '' ? $cid : 'ember');
}

/** A LIKE pattern for $text anywhere, with %, _ and ! taken literally (use with ESCAPE '!'). */
function coreui_chat_like(string $text): string {
  return '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

function coreui_chat_iso(?string $value): ?string {
  if ($value === null || $value === '') return null;
  $ts = strtotime($value);
  return $ts ? date('c', $ts) : $value;
}

// --- Pin ------------------------------------------------------------------------------------------

/** Pins or releases one active chat; returns whether it is pinned now. */
function coreui_chat_pin(PDO $pdo, int $uid, string $sessionId, bool $pinned): bool {
  if (!coreui_console_session_pin_ready($pdo)) throw new RuntimeException('missing_schema_032');
  coreui_console_session_require($pdo, $uid, $sessionId);
  $pdo->prepare('UPDATE stu_console_sessions SET pinned_at = ' . ($pinned ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ? AND user_id = ?')
    ->execute([$sessionId, $uid]);
  return $pinned;
}

/** Archiving releases the pin (an archived chat is not in the list). */
function coreui_chat_unpin_archived(PDO $pdo, int $uid, string $sessionId): void {
  if (!coreui_console_session_pin_ready($pdo)) return;
  $pdo->prepare('UPDATE stu_console_sessions SET pinned_at = NULL WHERE id = ? AND user_id = ? AND archived_at IS NOT NULL')
    ->execute([$sessionId, $uid]);
}

// --- Archived chats ---------------------------------------------------------------------------------

/**
 * The account's archived chats, newest archive first. $query searches the titles; $filter is
 * 'all', 'files' (the person uploaded files) or 'images' (Mara made images).
 */
function coreui_chat_archived(PDO $pdo, int $uid, string $query = '', string $filter = 'all', int $limit = 500): array {
  $assistant = coreui_chat_assistant_id();
  $files = "EXISTS (SELECT 1 FROM stu_chat_messages m
       WHERE m.channel = 'console' AND m.user_id = s.user_id AND m.session_id = s.id AND m.deleted_at IS NULL
         AND LOWER(m.character_id) <> ?
         AND ((m.file_uuid IS NOT NULL AND m.file_uuid <> '')
           OR EXISTS (SELECT 1 FROM stu_console_message_attachments a WHERE a.message_id = m.id)))";
  $images = "EXISTS (SELECT 1 FROM stu_chat_messages m
       JOIN stu_console_message_attachments a ON a.message_id = m.id
       JOIN stu_console_media cm ON cm.uuid = a.media_uuid
       WHERE m.channel = 'console' AND m.user_id = s.user_id AND m.session_id = s.id AND m.deleted_at IS NULL
         AND cm.rel_path LIKE '" . COREUI_CHAT_GENERATED_PREFIX . "%')";
  $sql = "SELECT s.id, s.title, s.created_at, s.updated_at, s.archived_at,
                 (SELECT COUNT(*) FROM stu_chat_messages m
                   WHERE m.channel = 'console' AND m.user_id = s.user_id AND m.session_id = s.id AND m.deleted_at IS NULL) AS message_count,
                 $files AS has_files, $images AS has_images
            FROM stu_console_sessions s
           WHERE s.user_id = ? AND s.archived_at IS NOT NULL";
  $params = [$assistant, $uid];
  $query = trim($query);
  if ($query !== '') {
    $sql .= " AND s.title LIKE ? ESCAPE '!'";
    $params[] = coreui_chat_like($query);
  }
  if ($filter === 'files') {
    $sql .= ' AND ' . $files;
    $params[] = $assistant;
  }
  if ($filter === 'images') $sql .= ' AND ' . $images;
  $sql .= ' ORDER BY s.archived_at DESC, s.updated_at DESC LIMIT ' . max(1, min(1000, $limit));
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $out[] = [
      'id' => (string)$row['id'],
      'title' => (string)($row['title'] ?? ''),
      'created_at' => coreui_chat_iso($row['created_at'] ?? null),
      'updated_at' => coreui_chat_iso($row['updated_at'] ?? null),
      'archived_at' => coreui_chat_iso($row['archived_at'] ?? null),
      'message_count' => (int)($row['message_count'] ?? 0),
      'has_files' => (bool)(int)($row['has_files'] ?? 0),
      'has_images' => (bool)(int)($row['has_images'] ?? 0),
      'project' => null,
    ];
  }
  return $out;
}

// --- Uploaded files of one chat ------------------------------------------------------------------------

/** The files the person uploaded in this chat (not Mara's images), newest first. */
function coreui_chat_files(PDO $pdo, int $uid, string $sessionId): array {
  coreui_console_session_require($pdo, $uid, $sessionId, true);
  $assistant = coreui_chat_assistant_id();
  $st = $pdo->prepare(
    "SELECT DISTINCT cm.uuid, cm.kind, cm.orig_name, cm.mime_type, cm.file_size, cm.created_at, cm.id
       FROM stu_console_media cm
       JOIN (
         SELECT m.file_uuid AS media_uuid FROM stu_chat_messages m
          WHERE m.channel = 'console' AND m.user_id = ? AND m.session_id = ? AND m.deleted_at IS NULL
            AND LOWER(m.character_id) <> ? AND m.file_uuid IS NOT NULL AND m.file_uuid <> ''
         UNION
         SELECT a.media_uuid FROM stu_console_message_attachments a
           JOIN stu_chat_messages m ON m.id = a.message_id
          WHERE m.channel = 'console' AND m.user_id = ? AND m.session_id = ? AND m.deleted_at IS NULL
            AND LOWER(m.character_id) <> ?
       ) refs ON refs.media_uuid = cm.uuid
      WHERE cm.user_id = ? AND cm.rel_path NOT LIKE '" . COREUI_CHAT_GENERATED_PREFIX . "%'
      ORDER BY cm.created_at DESC, cm.id DESC LIMIT 200"
  );
  $st->execute([$uid, $sessionId, $assistant, $uid, $sessionId, $assistant, $uid]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $out[] = [
      'uuid' => (string)$row['uuid'],
      'kind' => (string)($row['kind'] ?? 'document'),
      'name' => (string)($row['orig_name'] ?? 'Datei'),
      'mime' => (string)($row['mime_type'] ?? ''),
      'size' => (int)($row['file_size'] ?? 0),
      'created_at' => coreui_chat_iso($row['created_at'] ?? null),
    ];
  }
  return $out;
}

// --- Search inside one chat ------------------------------------------------------------------------------

/** A short piece of $text around the first hit of $query (pure). */
function coreui_chat_snippet(string $text, string $query, int $width = 140): string {
  $text = trim((string)preg_replace('~\s+~u', ' ', $text));
  $pos = mb_stripos($text, $query, 0, 'UTF-8');
  if ($pos === false) return mb_substr($text, 0, $width, 'UTF-8') . (mb_strlen($text, 'UTF-8') > $width ? '…' : '');
  $start = max(0, $pos - intdiv($width - mb_strlen($query, 'UTF-8'), 2));
  $piece = mb_substr($text, $start, $width, 'UTF-8');
  return ($start > 0 ? '…' : '') . $piece . ($start + $width < mb_strlen($text, 'UTF-8') ? '…' : '');
}

/** Every message of the chat that contains $query, oldest first (at most 100). */
function coreui_chat_search(PDO $pdo, int $uid, string $sessionId, string $query): array {
  coreui_console_session_require($pdo, $uid, $sessionId, true);
  $query = trim($query);
  if (mb_strlen($query, 'UTF-8') < 2) return [];
  $st = $pdo->prepare(
    "SELECT id, character_id, character_name, message, created_at FROM stu_chat_messages
      WHERE channel = 'console' AND user_id = ? AND session_id = ? AND deleted_at IS NULL
        AND message LIKE ? ESCAPE '!'
      ORDER BY id ASC LIMIT 100"
  );
  $st->execute([$uid, $sessionId, coreui_chat_like($query)]);
  $assistant = coreui_chat_assistant_id();
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $mara = strtolower((string)$row['character_id']) === $assistant;
    $text = $mara ? (string)$row['message'] : coreui_composer_intent_readable((string)$row['message']);
    if (mb_stripos($text, $query, 0, 'UTF-8') === false) continue;
    $out[] = ['id' => (int)$row['id'], 'mara' => $mara, 'created_at' => coreui_chat_iso($row['created_at'] ?? null),
      'snippet' => coreui_chat_snippet($text, $query)];
  }
  return $out;
}

// --- Export ("Teilen") -------------------------------------------------------------------------------------

/** A file name for the chat (pure): letters, digits and dashes, at most 60 characters. */
function coreui_chat_file_name(string $title): string {
  $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'];
  $slug = strtolower((string)preg_replace('~[^A-Za-z0-9]+~', '-', strtr($title, $map)));
  $slug = trim(substr(trim($slug, '-'), 0, 60), '-');
  return ($slug !== '' ? $slug : 'chat') . '.md';
}

/** The whole chat as Markdown: title, date, every message with name and time. */
function coreui_chat_export(PDO $pdo, int $uid, string $sessionId): array {
  $session = coreui_console_session_require($pdo, $uid, $sessionId, true);
  $title = trim((string)($session['title'] ?? '')) !== '' ? trim((string)$session['title']) : 'Chat';
  $assistant = function_exists('coreui_profile_assistant_name') ? coreui_profile_assistant_name($pdo, $uid) : 'Mara';
  $rows = coreui_compact_messages($pdo, $uid, $sessionId, 0);
  $markdown = '# ' . $title . "\n\n" . 'Aus CoreAI: TCI Interface, ' . date('d.m.Y H:i') . ', ' . count($rows)
    . (count($rows) === 1 ? ' Nachricht' : ' Nachrichten') . ".\n\n" . coreui_compact_markdown($rows, $assistant);
  return ['title' => $title, 'filename' => coreui_chat_file_name($title), 'markdown' => $markdown, 'messages' => count($rows)];
}
