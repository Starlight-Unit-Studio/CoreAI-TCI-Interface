<?php
declare(strict_types=1);

/*
 * 0.7.28: Settings > Speicher after the reference screenshots.
 *
 * What an account keeps on the server:
 *   Bilder   stu_console_media kind 'image': Mara's images (var/console_media/images/) and uploads
 *   Dateien  every other upload (documents, audio, video) and the files Mara made (stu_console_artifacts)
 * Items are addressed as "m:<media uuid>" or "a:<artifact id>"; only the account's own.
 *
 * The limit: the account's own value (Admin Core > Benutzer > Limits, 0 = no limit), else the limit
 * for all accounts (Admin Core > Betrieb, app setting storage_limit_mb, '' = none). The Inhaber has
 * no limit. Uploads and Mara's images stop when it is reached; deleting makes room again.
 */

require_once __DIR__ . '/user_policy.php';

const COREUI_FILES_MIB = 1048576;
const COREUI_FILES_PAGE = 60;
const COREUI_FILES_KEY_RE = '~^(m|a):([a-f0-9]{32})$~';
/** Type filter of the Dateien view => media kinds (artifacts are "mara"). */
const COREUI_FILES_TYPES = ['document' => ['document'], 'audio' => ['audio'], 'video' => ['video'], 'mara' => []];

function coreui_files_favorites_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, item_key FROM stu_user_file_favorites LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_files_artifacts_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready === null) {
    try {
      $pdo->query('SELECT id, user_id, session_id, orig_name, file_size FROM stu_console_artifacts LIMIT 0');
      $ready = true;
    } catch (Throwable $e) {
      $ready = false;
    }
  }
  return $ready;
}

function coreui_files_projects_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready === null) {
    try {
      $pdo->query('SELECT p.id, p.name, s.project_id FROM stu_console_projects p JOIN stu_console_sessions s ON s.project_id = p.id LIMIT 0');
      $ready = true;
    } catch (Throwable $e) {
      $ready = false;
    }
  }
  return $ready;
}

/** '' (no limit) or the limit for all accounts in MiB as a string; anything else becomes ''. */
function coreui_files_limit_normalise($value): string {
  $text = trim((string)$value);
  if ($text === '' || !preg_match('~^\d{1,8}$~', $text)) return '';
  $mb = (int)$text;
  return $mb >= 1 && $mb <= COREUI_USER_POLICY_STORAGE_MAX_MB ? (string)$mb : '';
}

/** The limit for all accounts in MiB, null when there is none. */
function coreui_files_global_limit_mb(PDO $pdo): ?int {
  $value = function_exists('stu_app_setting_get') ? coreui_files_limit_normalise(stu_app_setting_get($pdo, 'storage_limit_mb', '')) : '';
  return $value === '' ? null : (int)$value;
}

/** The account's limit in bytes, null when it has none (Inhaber, own value 0, or no limit for all). */
function coreui_files_limit_bytes(PDO $pdo, int $uid): ?int {
  if ($uid <= 0) return null;
  try {
    $st = $pdo->prepare('SELECT permission_level FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $level = $st->fetchColumn();
  } catch (Throwable $e) {
    $level = false;
  }
  if ($level !== false && (int)$level === 0) return null;
  $own = coreui_user_policy_load($pdo, $uid)['storage_mb'] ?? null;
  if ($own !== null) return (int)$own === 0 ? null : (int)$own * COREUI_FILES_MIB;
  $global = coreui_files_global_limit_mb($pdo);
  return $global === null ? null : $global * COREUI_FILES_MIB;
}

/** Bytes and counts: ['images' => [count, bytes], 'files' => [count, bytes], 'used' => bytes]. */
function coreui_files_usage(PDO $pdo, int $uid): array {
  $out = ['images' => ['count' => 0, 'bytes' => 0], 'files' => ['count' => 0, 'bytes' => 0], 'used' => 0];
  if ($uid <= 0) return $out;
  $st = $pdo->prepare("SELECT kind = 'image' AS image, COUNT(*) AS n, COALESCE(SUM(file_size), 0) AS bytes FROM stu_console_media WHERE user_id = ? GROUP BY kind = 'image'");
  $st->execute([$uid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $bucket = (int)$row['image'] === 1 ? 'images' : 'files';
    $out[$bucket]['count'] += (int)$row['n'];
    $out[$bucket]['bytes'] += (int)$row['bytes'];
  }
  if (coreui_files_artifacts_ready($pdo)) {
    $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(file_size), 0) AS bytes FROM stu_console_artifacts WHERE user_id = ?');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'bytes' => 0];
    $out['files']['count'] += (int)$row['n'];
    $out['files']['bytes'] += (int)$row['bytes'];
  }
  $out['used'] = $out['images']['bytes'] + $out['files']['bytes'];
  return $out;
}

/** Bytes per account (Admin Core > Benutzer): user id => bytes. */
function coreui_files_usage_all(PDO $pdo): array {
  $out = [];
  foreach ($pdo->query('SELECT user_id, COALESCE(SUM(file_size), 0) AS bytes FROM stu_console_media GROUP BY user_id') ?: [] as $row) {
    $out[(string)$row['user_id']] = (int)$row['bytes'];
  }
  if (coreui_files_artifacts_ready($pdo)) {
    foreach ($pdo->query('SELECT user_id, COALESCE(SUM(file_size), 0) AS bytes FROM stu_console_artifacts GROUP BY user_id') ?: [] as $row) {
      $out[(string)$row['user_id']] = ($out[(string)$row['user_id']] ?? 0) + (int)$row['bytes'];
    }
  }
  return $out;
}

/** null when $adding more bytes fit, else ['used' => bytes, 'limit' => bytes]. */
function coreui_files_room(PDO $pdo, int $uid, int $adding): ?array {
  $limit = coreui_files_limit_bytes($pdo, $uid);
  if ($limit === null) return null;
  $used = coreui_files_usage($pdo, $uid)['used'];
  return $used + max(0, $adding) <= $limit ? null : ['used' => $used, 'limit' => $limit];
}

/** Free space where the files live, null when unknown. */
function coreui_files_disk_free(): ?int {
  $free = @disk_free_space(dirname(__DIR__) . '/var');
  return is_float($free) || is_int($free) ? (int)$free : null;
}

function coreui_files_favorites(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_files_favorites_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT item_key FROM stu_user_file_favorites WHERE user_id = ?');
  $st->execute([$uid]);
  return array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

function coreui_files_favorite(PDO $pdo, int $uid, string $key, bool $on): bool {
  if (!preg_match(COREUI_FILES_KEY_RE, $key) || coreui_files_item($pdo, $uid, $key) === null) throw new InvalidArgumentException('not_found');
  if (!coreui_files_favorites_ready($pdo)) throw new RuntimeException('missing_schema_039');
  if ($on) $pdo->prepare('INSERT IGNORE INTO stu_user_file_favorites (user_id, item_key) VALUES (?, ?)')->execute([$uid, $key]);
  else $pdo->prepare('DELETE FROM stu_user_file_favorites WHERE user_id = ? AND item_key = ?')->execute([$uid, $key]);
  return $on;
}

/** The chat (and its project) a media item first appeared in: uuid => [session_id, title, project_id, project]. */
function coreui_files_media_chats(PDO $pdo, int $uid, array $uuids): array {
  if ($uuids === []) return [];
  $projects = coreui_files_projects_ready($pdo);
  $place = implode(',', array_fill(0, count($uuids), '?'));
  $sql = 'SELECT refs.uuid, s.id AS session_id, s.title' . ($projects ? ', s.project_id, p.name AS project' : '') . ' FROM ('
    . 'SELECT a.media_uuid AS uuid, MIN(a.message_id) AS message_id FROM stu_console_message_attachments a '
    . 'WHERE a.user_id = ? AND a.media_uuid IN (' . $place . ') GROUP BY a.media_uuid '
    . 'UNION ALL SELECT m.file_uuid AS uuid, MIN(m.id) AS message_id FROM stu_chat_messages m '
    . "WHERE m.user_id = ? AND m.channel = 'console' AND m.file_uuid IN (" . $place . ') GROUP BY m.file_uuid'
    . ') refs JOIN stu_chat_messages msg ON msg.id = refs.message_id AND msg.deleted_at IS NULL '
    . 'JOIN stu_console_sessions s ON s.id = msg.session_id AND s.user_id = ? '
    . ($projects ? 'LEFT JOIN stu_console_projects p ON p.id = s.project_id AND p.user_id = s.user_id ' : '');
  $out = [];
  try {
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$uid], $uuids, [$uid], $uuids, [$uid]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $uuid = (string)$row['uuid'];
      if (isset($out[$uuid])) continue;
      $out[$uuid] = ['id' => (string)$row['session_id'], 'title' => (string)$row['title'],
        'project_id' => (string)($row['project_id'] ?? ''), 'project' => (string)($row['project'] ?? '')];
    }
  } catch (Throwable $e) {
    return [];
  }
  return $out;
}

function coreui_files_media_item(array $row, array $chat, bool $favorite): array {
  $uuid = (string)$row['uuid'];
  $url = 'api/console_media.php?uuid=' . $uuid;
  $generated = str_starts_with((string)$row['rel_path'], 'var/console_media/images/');
  return ['id' => 'm:' . $uuid, 'name' => (string)$row['orig_name'], 'kind' => (string)$row['kind'],
    'mime' => (string)$row['mime_type'], 'size' => (int)$row['file_size'], 'created' => (string)$row['created_at'],
    'source' => $generated ? 'creation' : 'upload', 'url' => $url, 'thumb' => (string)$row['kind'] === 'image' ? $url : '',
    'chat' => $chat ?: null, 'favorite' => $favorite];
}

function coreui_files_artifact_item(array $row, bool $favorite): array {
  $chat = ['id' => (string)$row['session_id'], 'title' => (string)($row['title'] ?? ''),
    'project_id' => (string)($row['project_id'] ?? ''), 'project' => (string)($row['project'] ?? '')];
  return ['id' => 'a:' . (string)$row['id'], 'name' => (string)$row['orig_name'], 'kind' => 'mara',
    'mime' => (string)$row['mime_type'], 'size' => (int)$row['file_size'], 'created' => (string)$row['created_at'],
    'source' => 'mara', 'url' => 'api/console_artifact.php?id=' . (string)$row['id'], 'thumb' => '',
    'chat' => $chat['id'] !== '' ? $chat : null, 'favorite' => $favorite];
}

/** One item of the account, or null. */
function coreui_files_item(PDO $pdo, int $uid, string $key): ?array {
  if ($uid <= 0 || !preg_match(COREUI_FILES_KEY_RE, $key, $m)) return null;
  if ($m[1] === 'm') {
    $st = $pdo->prepare('SELECT uuid, kind, orig_name, mime_type, file_size, rel_path, created_at FROM stu_console_media WHERE uuid = ? AND user_id = ? LIMIT 1');
    $st->execute([$m[2], $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row + ['_type' => 'm'] : null;
  }
  if (!coreui_files_artifacts_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT id, storage_key, orig_name, mime_type, file_size, session_id, created_at FROM stu_console_artifacts WHERE id = ? AND user_id = ? LIMIT 1');
  $st->execute([$m[2], $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row + ['_type' => 'a'] : null;
}

/**
 * A page of the account's items.
 *   view 'files':  tab 'all' (Deine Elemente), 'suggested' (used last), 'favorites'; type: '', document, audio, video, mara;
 *                  project: only the files of the chats in that project
 *   view 'images': tab 'creations' (Mara's) or 'uploads'
 * Returns ['items' => [...], 'has_more' => bool, 'folders' => [...]] (folders: projects with files, files view only).
 */
function coreui_files_list(PDO $pdo, int $uid, array $q): array {
  $view = ($q['view'] ?? '') === 'images' ? 'images' : 'files';
  $tab = (string)($q['tab'] ?? '');
  $search = trim((string)($q['q'] ?? ''));
  $type = (string)($q['type'] ?? '');
  $project = preg_match('~^[a-f0-9]{32}$~', (string)($q['project'] ?? '')) ? (string)$q['project'] : '';
  $offset = max(0, min(100000, (int)($q['offset'] ?? 0)));
  $limit = COREUI_FILES_PAGE;
  $favorites = coreui_files_favorites($pdo, $uid);
  $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 120)) . '%';
  $rows = [];

  // Media (uploads and Mara's images).
  $wantMedia = $view === 'images' || $type === '' || isset(COREUI_FILES_TYPES[$type]) && COREUI_FILES_TYPES[$type] !== [];
  if ($wantMedia) {
    $where = ['user_id = ?'];
    $args = [$uid];
    if ($view === 'images') {
      $where[] = "kind = 'image'";
      $where[] = $tab === 'uploads' ? "rel_path NOT LIKE 'var/console\\_media/images/%'" : "rel_path LIKE 'var/console\\_media/images/%'";
    } else {
      $where[] = "kind <> 'image'";
      if ($type !== '' && isset(COREUI_FILES_TYPES[$type])) {
        $where[] = 'kind IN (' . implode(',', array_fill(0, count(COREUI_FILES_TYPES[$type]), '?')) . ')';
        $args = array_merge($args, COREUI_FILES_TYPES[$type]);
      }
    }
    if ($search !== '') { $where[] = 'orig_name LIKE ?'; $args[] = $like; }
    $st = $pdo->prepare('SELECT uuid, kind, orig_name, mime_type, file_size, rel_path, created_at FROM stu_console_media WHERE '
      . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 2000');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $rows[] = $row + ['_type' => 'm'];
  }
  // Files Mara made.
  if ($view === 'files' && ($type === '' || $type === 'mara') && coreui_files_artifacts_ready($pdo)) {
    $projects = coreui_files_projects_ready($pdo);
    $sql = 'SELECT a.id, a.orig_name, a.mime_type, a.file_size, a.session_id, a.created_at, s.title'
      . ($projects ? ', s.project_id, p.name AS project' : '')
      . ' FROM stu_console_artifacts a LEFT JOIN stu_console_sessions s ON s.id = a.session_id AND s.user_id = a.user_id '
      . ($projects ? 'LEFT JOIN stu_console_projects p ON p.id = s.project_id AND p.user_id = a.user_id ' : '')
      . 'WHERE a.user_id = ?' . ($search !== '' ? ' AND a.orig_name LIKE ?' : '') . ' ORDER BY a.created_at DESC LIMIT 2000';
    $st = $pdo->prepare($sql);
    $st->execute($search !== '' ? [$uid, $like] : [$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $rows[] = $row + ['_type' => 'a'];
  }
  usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));

  $chats = coreui_files_media_chats($pdo, $uid, array_values(array_map(static fn(array $r): string => (string)$r['uuid'],
    array_filter($rows, static fn(array $r): bool => $r['_type'] === 'm'))));
  $items = [];
  foreach ($rows as $row) {
    $key = $row['_type'] === 'm' ? 'm:' . $row['uuid'] : 'a:' . $row['id'];
    $item = $row['_type'] === 'm'
      ? coreui_files_media_item($row, $chats[(string)$row['uuid']] ?? [], isset($favorites[$key]))
      : coreui_files_artifact_item($row, isset($favorites[$key]));
    if ($view === 'files' && $tab === 'favorites' && !$item['favorite']) continue;
    if ($project !== '' && (($item['chat']['project_id'] ?? '') !== $project)) continue;
    $items[] = $item;
  }

  // Folders: the projects whose chats hold files (Deine Elemente, top level, no search).
  $folders = [];
  if ($view === 'files' && $tab !== 'suggested' && $tab !== 'favorites' && $project === '' && $search === '') {
    foreach ($items as $item) {
      $pid = (string)($item['chat']['project_id'] ?? '');
      if ($pid === '') continue;
      if (!isset($folders[$pid])) $folders[$pid] = ['id' => $pid, 'name' => (string)$item['chat']['project'], 'count' => 0, 'size' => 0];
      $folders[$pid]['count']++;
      $folders[$pid]['size'] += $item['size'];
    }
    $folders = array_values($folders);
    usort($folders, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
  }
  if ($view === 'files' && $tab === 'suggested') $items = array_slice($items, 0, 12);
  return ['items' => array_slice($items, $offset, $limit), 'has_more' => count($items) > $offset + $limit, 'total' => count($items),
    'folders' => $folders];
}

/**
 * Deletes an item for good: the file, its entry and the favourite. A chat that used it no longer
 * shows it. Returns the bytes freed.
 */
function coreui_files_delete(PDO $pdo, int $uid, string $key): int {
  $row = coreui_files_item($pdo, $uid, $key);
  if ($row === null) throw new InvalidArgumentException('not_found');
  if ($row['_type'] === 'm') {
    $del = $pdo->prepare('DELETE FROM stu_console_media WHERE uuid = ? AND user_id = ? LIMIT 1');
    $del->execute([(string)$row['uuid'], $uid]);
    if ($del->rowCount() !== 1) throw new InvalidArgumentException('not_found');
    require_once __DIR__ . '/console_session_store.php';
    coreui_console_session_delete_media_files([(string)$row['rel_path']]);
    // Mara's images keep their description next to them.
    if (str_starts_with((string)$row['rel_path'], 'var/console_media/images/')) {
      @unlink(dirname(__DIR__) . '/var/console_media/images/' . (string)$row['uuid'] . '.json');
    }
  } else {
    $del = $pdo->prepare('DELETE FROM stu_console_artifacts WHERE id = ? AND user_id = ? LIMIT 1');
    $del->execute([(string)$row['id'], $uid]);
    if ($del->rowCount() !== 1) throw new InvalidArgumentException('not_found');
    require_once __DIR__ . '/console_artifact_store.php';
    coreui_console_artifact_delete_files([(string)$row['storage_key']]);
  }
  if (coreui_files_favorites_ready($pdo)) {
    $pdo->prepare('DELETE FROM stu_user_file_favorites WHERE user_id = ? AND item_key = ?')->execute([$uid, $key]);
  }
  return (int)$row['file_size'];
}

/** "1,2 GB" for messages (German decimal comma). */
function coreui_files_size_label(int $bytes): string {
  if ($bytes >= 1073741824) return str_replace('.', ',', (string)round($bytes / 1073741824, 2)) . ' GB';
  if ($bytes >= COREUI_FILES_MIB) return str_replace('.', ',', (string)round($bytes / COREUI_FILES_MIB, 1)) . ' MB';
  return (string)max(0, (int)ceil($bytes / 1024)) . ' KB';
}
