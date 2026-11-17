<?php
declare(strict_types=1);

/*
 * 0.7.83 (P12 A): skills of an account (migration 052).
 *
 * A skill has a main instruction Mara follows, a short description and trigger words (how she and
 * the account find it), the tools it needs, and where it applies: every chat of the account, or the
 * chats of one project. Companion files are addressed by path in four folders:
 *   references/  plans and background Mara reads
 *   templates/   texts she fills in
 *   scripts/     code; loading one gives Mara its text, nothing runs by itself
 *   assets/      data such as a CSV or a list
 * The library and the loading in a chat live in api/skills.php; this file stores and checks.
 */
require_once __DIR__ . '/console_project_store.php';
require_once __DIR__ . '/skills.php';

const COREUI_SKILLPKG_MAX = 50;
const COREUI_SKILLPKG_FILES = 20;
const COREUI_SKILLPKG_BODY_BYTES = 4000;
const COREUI_SKILLPKG_FILE_BYTES = 8000;
const COREUI_SKILLPKG_TRIGGERS = 12;
const COREUI_SKILLPKG_FOLDERS = ['references' => 'reference', 'templates' => 'template', 'scripts' => 'script', 'assets' => 'asset'];
/** The tools a skill can need, as Mara has them in a chat. */
const COREUI_SKILLPKG_TOOLS = ['web' => 'Websuche', 'browse' => 'Browser-Recherche', 'python' => 'Python', 'image' => 'Bilder',
  'files' => 'Projektdateien', 'run' => 'Ausführen im Projekt'];
// 0.7.84 (P12 B): who may share (Inhaber 0, Admin 1), how many skills a chat fixes, the precedence.
const COREUI_SKILLPKG_SHARE_LEVEL = 1;
const COREUI_SKILLPKG_CHAT_MAX = 3;
const COREUI_SKILLPKG_PRIORITY = ['high' => 1, 'normal' => 0, 'low' => -1];
/** Which variant counts when two skills share a short name: lower first (after a chat's own choice). */
const COREUI_SKILLPKG_VARIANT_RANK = ['project' => 0, 'own' => 1, 'shared' => 2];

function coreui_skillpkg_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT id, user_id, slug, body, requires_tools, project_id, enabled FROM stu_skill_packages LIMIT 0');
      $pdo->query('SELECT skill_id, path, kind, content FROM stu_skill_package_files LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** 0.7.84 (P12 B): migration 053 (shared skills, precedence, opt-outs, skills fixed for a chat). */
function coreui_skillpkg_sharing_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT shared, priority FROM stu_skill_packages LIMIT 0');
      $pdo->query('SELECT user_id, skill_id FROM stu_skill_package_optouts LIMIT 0');
      $pdo->query('SELECT session_id, user_id, ref, position FROM stu_console_session_skills LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** The precedence of a skill (pure): 'high', 'normal', 'low' (or 1, 0, -1) as a number. */
function coreui_skillpkg_priority($value): int {
  if (is_int($value) && in_array($value, COREUI_SKILLPKG_PRIORITY, true)) return $value;
  $name = strtolower(trim((string)$value));
  if ($name === '') return 0;
  if (!isset(COREUI_SKILLPKG_PRIORITY[$name])) throw new InvalidArgumentException('invalid_skill_priority');
  return COREUI_SKILLPKG_PRIORITY[$name];
}

function coreui_skillpkg_priority_name(int $value): string {
  return $value > 0 ? 'high' : ($value < 0 ? 'low' : 'normal');
}

/** The role of an account (0 Inhaber, 1 Admin, 4 Nutzer); a guest counts as Nutzer. */
function coreui_skillpkg_level(PDO $pdo, int $uid): int {
  if ($uid <= 0) return 4;
  try {
    $st = $pdo->prepare('SELECT permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return 4;
  }
  if (!$row || (int)($row['is_guest'] ?? 0) === 1) return 4;
  return (int)$row['permission_level'];
}

/** Only the Inhaber and Admins share skills with every account. */
function coreui_skillpkg_can_share(PDO $pdo, int $uid): bool {
  return coreui_skillpkg_sharing_ready($pdo) && coreui_skillpkg_level($pdo, $uid) <= COREUI_SKILLPKG_SHARE_LEVEL;
}

/** Who shared a skill, as other accounts see it: the profile name, else the role. */
function coreui_skillpkg_owner_label(PDO $pdo, int $ownerUid, int $level): string {
  try {
    $st = $pdo->prepare('SELECT display_name FROM stu_coreui_profiles WHERE user_id = ? LIMIT 1');
    $st->execute([$ownerUid]);
    $name = trim((string)($st->fetchColumn() ?: ''));
    if ($name !== '') return mb_substr($name, 0, 64, 'UTF-8');
  } catch (Throwable $e) {
    // No profiles table: the role says enough.
  }
  return $level === 0 ? 'Inhaber' : 'Admin';
}

function coreui_skillpkg_text(string $value, int $max, string $error, bool $oneLine = true): string {
  $text = $oneLine ? trim((string)preg_replace('~\s+~u', ' ', $value)) : trim(str_replace("\r\n", "\n", $value));
  if ($text === '' || preg_match('//u', $text) !== 1 || str_contains($text, "\0") || mb_strlen($text, 'UTF-8') > $max) {
    throw new InvalidArgumentException($error);
  }
  return $text;
}

/** The short name of a skill (pure): what Mara writes in [SKILL: ...]. No name of a built-in skill. */
function coreui_skillpkg_slug($value): string {
  $slug = strtolower(trim((string)$value));
  if (strlen($slug) < 2 || strlen($slug) > 64 || preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) !== 1) {
    throw new InvalidArgumentException('invalid_skill_slug');
  }
  if (isset(coreui_skills_catalog()[$slug])) throw new InvalidArgumentException('skill_builtin_name');
  return $slug;
}

/** Trigger words (pure): comma or line separated, at most 12 of at most 40 characters. */
function coreui_skillpkg_triggers($value): array {
  $parts = is_array($value) ? $value : preg_split('~[,\n]~u', (string)$value);
  $out = [];
  foreach ($parts ?: [] as $part) {
    $term = mb_strtolower(trim((string)preg_replace('~\s+~u', ' ', (string)$part)), 'UTF-8');
    if ($term === '') continue;
    if (mb_strlen($term, 'UTF-8') > 40 || preg_match('//u', $term) !== 1) throw new InvalidArgumentException('invalid_skill_triggers');
    if (!in_array($term, $out, true)) $out[] = $term;
  }
  if (count($out) > COREUI_SKILLPKG_TRIGGERS) throw new InvalidArgumentException('invalid_skill_triggers');
  return $out;
}

/** The tools a skill needs (pure), only known ones, in a fixed order. */
function coreui_skillpkg_requires($value): array {
  $given = is_array($value) ? $value : explode(',', (string)$value);
  $given = array_map(static fn($t): string => strtolower(trim((string)$t)), $given);
  foreach ($given as $tool) {
    if ($tool !== '' && !isset(COREUI_SKILLPKG_TOOLS[$tool])) throw new InvalidArgumentException('invalid_skill_tool');
  }
  return array_values(array_filter(array_keys(COREUI_SKILLPKG_TOOLS), static fn(string $t): bool => in_array($t, $given, true)));
}

/** A companion file's path (pure): folder/name.ext; returns [path, kind]. */
function coreui_skillpkg_path($value): array {
  $path = trim(str_replace('\\', '/', (string)$value));
  if (preg_match('~^(references|templates|scripts|assets)/([A-Za-z0-9][A-Za-z0-9._-]{0,80})$~D', $path, $m) !== 1
      || str_contains($m[2], '..') || !str_contains($m[2], '.')) {
    throw new InvalidArgumentException('invalid_skill_path');
  }
  return [$path, COREUI_SKILLPKG_FOLDERS[$m[1]]];
}

/** Companion files the instruction names but the skill does not have (pure). */
function coreui_skillpkg_missing(string $body, array $paths): array {
  preg_match_all('~(?<![A-Za-z0-9._/-])((?:references|templates|scripts|assets)/[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.[A-Za-z0-9]+)~', $body, $m);
  $missing = [];
  foreach (array_unique($m[1]) as $path) {
    if (!in_array($path, $paths, true)) $missing[] = $path;
  }
  sort($missing, SORT_STRING);
  return $missing;
}

function coreui_skillpkg_row(PDO $pdo, int $uid, string $id): array {
  if (preg_match('~^[a-f0-9]{32}$~D', $id) !== 1) throw new RuntimeException('skill_not_found');
  $st = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE id = ? AND user_id = ?');
  $st->execute([$id, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('skill_not_found');
  return $row;
}

function coreui_skillpkg_files(PDO $pdo, string $skillId, bool $content = false): array {
  $st = $pdo->prepare('SELECT path, kind, size_bytes, sha256, updated_at' . ($content ? ', content' : '')
    . ' FROM stu_skill_package_files WHERE skill_id = ? ORDER BY path');
  $st->execute([$skillId]);
  return array_map(static function (array $row) use ($content): array {
    $out = ['path'=>(string)$row['path'], 'kind'=>(string)$row['kind'], 'size_bytes'=>(int)$row['size_bytes'],
      'sha256'=>(string)$row['sha256'], 'updated_at'=>(string)$row['updated_at']];
    if ($content) $out['content'] = (string)$row['content'];
    return $out;
  }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** A skill as the settings page shows it; with files and their content when $full. */
function coreui_skillpkg_public(PDO $pdo, array $row, bool $full = false): array {
  $files = coreui_skillpkg_files($pdo, (string)$row['id'], $full);
  $project = null;
  if ($row['project_id'] !== null) {
    $st = $pdo->prepare('SELECT name FROM stu_console_projects WHERE id = ? AND user_id = ?');
    $st->execute([(string)$row['project_id'], (int)$row['user_id']]);
    $project = ['id'=>(string)$row['project_id'], 'name'=>(string)($st->fetchColumn() ?: '')];
  }
  $out = ['id'=>(string)$row['id'], 'slug'=>(string)$row['slug'], 'title'=>(string)$row['title'], 'description'=>(string)$row['description'],
    'triggers'=>coreui_skillpkg_triggers((string)$row['triggers']), 'requires'=>coreui_skillpkg_requires((string)$row['requires_tools']),
    'project'=>$project, 'enabled'=>(int)$row['enabled'] === 1, 'files'=>$files,
    'shared'=>(int)($row['shared'] ?? 0) === 1, 'priority'=>coreui_skillpkg_priority_name((int)($row['priority'] ?? 0)),
    'missing'=>coreui_skillpkg_missing((string)$row['body'], array_column($files, 'path')),
    'created_at'=>(string)$row['created_at'], 'updated_at'=>(string)$row['updated_at']];
  if ($full) $out['body'] = (string)$row['body'];
  return $out;
}

/** The account's skills, optionally only those matching $query (title, name, description, triggers). */
function coreui_skillpkg_list(PDO $pdo, int $uid, string $query = ''): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  $st = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE user_id = ? ORDER BY title, slug');
  $st->execute([$uid]);
  $query = mb_strtolower(trim($query), 'UTF-8');
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $hay = mb_strtolower($row['title'] . ' ' . $row['slug'] . ' ' . $row['description'] . ' ' . $row['triggers'], 'UTF-8');
    if ($query !== '' && !str_contains($hay, $query)) continue;
    $out[] = coreui_skillpkg_public($pdo, $row);
  }
  return $out;
}

function coreui_skillpkg_get(PDO $pdo, int $uid, string $id): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  return coreui_skillpkg_public($pdo, coreui_skillpkg_row($pdo, $uid, $id), true);
}

/** Creates ($id '') or changes a skill. $data: slug, title, description, body, triggers, requires, project_id, enabled. */
function coreui_skillpkg_save(PDO $pdo, int $uid, string $id, array $data): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  $slug = coreui_skillpkg_slug($data['slug'] ?? '');
  $title = coreui_skillpkg_text((string)($data['title'] ?? ''), 80, 'invalid_skill_title');
  $description = coreui_skillpkg_text((string)($data['description'] ?? ''), 300, 'invalid_skill_description');
  $body = coreui_skillpkg_text((string)($data['body'] ?? ''), 100000, 'invalid_skill_body', false);
  if (strlen($body) > COREUI_SKILLPKG_BODY_BYTES) throw new InvalidArgumentException('skill_body_too_large');
  $triggers = implode(', ', coreui_skillpkg_triggers($data['triggers'] ?? ''));
  $requires = implode(',', coreui_skillpkg_requires($data['requires'] ?? []));
  $project = trim((string)($data['project_id'] ?? ''));
  $project = $project === '' ? null : (string)coreui_project_require($pdo, $uid, $project)['id'];
  $enabled = !empty($data['enabled']) ? 1 : 0;
  // 0.7.84 (P12 B): precedence and sharing (migration 053); a project skill is never shared.
  $sharing = coreui_skillpkg_sharing_ready($pdo);
  $priority = coreui_skillpkg_priority($data['priority'] ?? 'normal');
  $shared = !empty($data['shared']) ? 1 : 0;
  if ($shared === 1) {
    if (!$sharing) throw new RuntimeException('skill_migration_required');
    if ($project !== null) throw new InvalidArgumentException('skill_share_project');
    if (!coreui_skillpkg_can_share($pdo, $uid)) throw new RuntimeException('skill_share_forbidden');
  }
  $same = $pdo->prepare('SELECT id FROM stu_skill_packages WHERE user_id = ? AND slug = ?');
  $same->execute([$uid, $slug]);
  $other = $same->fetchColumn();
  if ($other !== false && (string)$other !== $id) throw new RuntimeException('skill_exists');
  if ($id === '') {
    $count = $pdo->prepare('SELECT COUNT(*) FROM stu_skill_packages WHERE user_id = ?');
    $count->execute([$uid]);
    if ((int)$count->fetchColumn() >= COREUI_SKILLPKG_MAX) throw new RuntimeException('skill_limit');
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO stu_skill_packages (id, user_id, slug, title, description, body, triggers, requires_tools, project_id, enabled,
      created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
      ->execute([$id, $uid, $slug, $title, $description, $body, $triggers, $requires, $project, $enabled]);
  } else {
    coreui_skillpkg_row($pdo, $uid, $id);
    $pdo->prepare('UPDATE stu_skill_packages SET slug = ?, title = ?, description = ?, body = ?, triggers = ?, requires_tools = ?, project_id = ?,
      enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
      ->execute([$slug, $title, $description, $body, $triggers, $requires, $project, $enabled, $id, $uid]);
  }
  if ($sharing) {
    $pdo->prepare('UPDATE stu_skill_packages SET shared = ?, priority = ? WHERE id = ? AND user_id = ?')->execute([$shared, $priority, $id, $uid]);
  }
  return coreui_skillpkg_get($pdo, $uid, $id);
}

function coreui_skillpkg_delete(PDO $pdo, int $uid, string $id): void {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_row($pdo, $uid, $id);
  $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ?')->execute([$id]);
  if (coreui_skillpkg_sharing_ready($pdo)) {
    // 0.7.84: chats that fixed it name it as gone; nobody keeps an opt-out of nothing.
    $pdo->prepare('DELETE FROM stu_skill_package_optouts WHERE skill_id = ?')->execute([$id]);
  }
  $pdo->prepare('DELETE FROM stu_skill_packages WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}

/** Writes a companion file (text, at most 8 KB); $oldPath renames it. */
function coreui_skillpkg_file_save(PDO $pdo, int $uid, string $id, $path, $content, $oldPath = ''): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_row($pdo, $uid, $id);
  [$path, $kind] = coreui_skillpkg_path($path);
  if (!is_string($content) || str_contains($content, "\0") || preg_match('//u', $content) !== 1) throw new InvalidArgumentException('invalid_skill_file');
  $content = str_replace("\r\n", "\n", $content);
  if (strlen($content) > COREUI_SKILLPKG_FILE_BYTES) throw new InvalidArgumentException('skill_file_too_large');
  $oldPath = trim((string)$oldPath);
  $files = array_column(coreui_skillpkg_files($pdo, $id), 'path');
  if ($oldPath !== '' && $oldPath !== $path) {
    if (!in_array($oldPath, $files, true)) throw new RuntimeException('skill_file_not_found');
    if (in_array($path, $files, true)) throw new RuntimeException('skill_file_exists');
    $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ? AND path = ?')->execute([$id, $oldPath]);
    $files = array_values(array_diff($files, [$oldPath]));
  }
  if (!in_array($path, $files, true) && count($files) >= COREUI_SKILLPKG_FILES) throw new RuntimeException('skill_files_limit');
  $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ? AND path = ?')->execute([$id, $path]);
  $pdo->prepare('INSERT INTO stu_skill_package_files (skill_id, path, kind, content, size_bytes, sha256, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')->execute([$id, $path, $kind, $content, strlen($content), hash('sha256', $content)]);
  $pdo->prepare('UPDATE stu_skill_packages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
  return coreui_skillpkg_get($pdo, $uid, $id);
}

function coreui_skillpkg_file_delete(PDO $pdo, int $uid, string $id, $path): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_row($pdo, $uid, $id);
  [$path] = coreui_skillpkg_path($path);
  $st = $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ? AND path = ?');
  $st->execute([$id, $path]);
  if ($st->rowCount() !== 1) throw new RuntimeException('skill_file_not_found');
  return coreui_skillpkg_get($pdo, $uid, $id);
}

/**
 * 0.7.84 (P12 B): every skill package that can serve a chat of this account: its own (every chat,
 * or this project), and the shared ones of the Inhaber and Admins it has not switched off. Each
 * row carries 'variant' (project, own, shared) and, for a shared one, 'owner'.
 */
function coreui_skillpkg_candidates(PDO $pdo, int $uid, string $projectId): array {
  if ($uid <= 0 || !coreui_skillpkg_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE user_id = ? AND enabled = 1 AND (project_id IS NULL OR project_id = ?) ORDER BY slug');
  $st->execute([$uid, $projectId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $row['variant'] = $row['project_id'] !== null ? 'project' : 'own';
    $out[] = $row;
  }
  // Guests never get shared skills.
  if (!coreui_skillpkg_sharing_ready($pdo) || coreui_skillpkg_is_guest($pdo, $uid)) return $out;
  foreach (coreui_skillpkg_shared_rows($pdo, $uid) as $row) {
    if (!$row['opted_out']) $out[] = $row;
  }
  return $out;
}

function coreui_skillpkg_is_guest(PDO $pdo, int $uid): bool {
  try {
    $st = $pdo->prepare('SELECT is_guest FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    return (int)$st->fetchColumn() === 1;
  } catch (Throwable $e) {
    return true;
  }
}

/** The shared skills other accounts offer this account (only while their owner is Inhaber or Admin). */
function coreui_skillpkg_shared_rows(PDO $pdo, int $uid): array {
  if (!coreui_skillpkg_sharing_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT p.*, u.permission_level AS owner_level FROM stu_skill_packages p JOIN stu_users u ON u.id = p.user_id
    WHERE p.shared = 1 AND p.enabled = 1 AND p.project_id IS NULL AND p.user_id <> ? AND u.permission_level <= ? AND u.is_guest = 0
    ORDER BY p.slug, p.priority DESC, p.created_at, p.id');
  $st->execute([$uid, COREUI_SKILLPKG_SHARE_LEVEL]);
  $off = $pdo->prepare('SELECT skill_id FROM stu_skill_package_optouts WHERE user_id = ?');
  $off->execute([$uid]);
  $optouts = array_map('strval', $off->fetchAll(PDO::FETCH_COLUMN) ?: []);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $row['variant'] = 'shared';
    $row['owner'] = coreui_skillpkg_owner_label($pdo, (int)$row['user_id'], (int)$row['owner_level']);
    $row['opted_out'] = in_array((string)$row['id'], $optouts, true);
    $out[] = $row;
  }
  return $out;
}

/**
 * Which variant serves each short name (pure). A chat's own choice comes first, then a project
 * skill, the account's own, a shared one; then the higher precedence, then the older one.
 * Returns ['use' => slug => row (with 'pinned'), 'shadowed' => id => the row that serves instead].
 */
function coreui_skillpkg_resolve(array $rows, array $pinnedIds): array {
  $groups = [];
  foreach ($rows as $row) $groups[(string)$row['slug']][] = $row;
  $use = [];
  $shadowed = [];
  foreach ($groups as $slug => $group) {
    usort($group, static function (array $a, array $b) use ($pinnedIds): int {
      $pa = in_array((string)$a['id'], $pinnedIds, true) ? 0 : 1;
      $pb = in_array((string)$b['id'], $pinnedIds, true) ? 0 : 1;
      return [$pa, COREUI_SKILLPKG_VARIANT_RANK[$a['variant']] ?? 9, -(int)($a['priority'] ?? 0), (string)$a['created_at'], (string)$a['id']]
        <=> [$pb, COREUI_SKILLPKG_VARIANT_RANK[$b['variant']] ?? 9, -(int)($b['priority'] ?? 0), (string)$b['created_at'], (string)$b['id']];
    });
    $winner = $group[0];
    $winner['pinned'] = in_array((string)$winner['id'], $pinnedIds, true);
    $use[(string)$slug] = $winner;
    foreach (array_slice($group, 1) as $other) $shadowed[(string)$other['id']] = $winner;
  }
  return ['use'=>$use, 'shadowed'=>$shadowed];
}

/** The skills a chat fixed, in their order (package ids and 'b:' built-in names). */
function coreui_skillpkg_chat_refs(PDO $pdo, int $uid, string $sessionId): array {
  if ($uid <= 0 || $sessionId === '' || !coreui_skillpkg_sharing_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT ref FROM stu_console_session_skills WHERE session_id = ? AND user_id = ? ORDER BY position, ref');
  $st->execute([$sessionId, $uid]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** A fixed skill (pure): a package id, or 'b:' and the name of a built-in skill. */
function coreui_skillpkg_ref($value): string {
  $ref = strtolower(trim((string)$value));
  if (preg_match('~^[a-f0-9]{32}$~D', $ref) === 1 || preg_match('~^b:[a-z0-9]+(?:-[a-z0-9]+)*$~D', $ref) === 1) return $ref;
  throw new InvalidArgumentException('invalid_skill_ref');
}

/**
 * The account's skills for one chat (enabled; every chat or this chat's project; the shared ones it
 * uses), one variant per short name, with their files, as api/skills.php uses them:
 * slug => [title, description, triggers, requires, body, files, project, variant, owner, priority, pinned, id].
 */
function coreui_skillpkg_for_chat(PDO $pdo, int $uid, string $projectId, string $sessionId = ''): array {
  if ($uid <= 0 || !coreui_skillpkg_ready($pdo)) return [];
  $resolved = coreui_skillpkg_resolve(coreui_skillpkg_candidates($pdo, $uid, $projectId), coreui_skillpkg_chat_refs($pdo, $uid, $sessionId));
  $out = [];
  foreach ($resolved['use'] as $slug => $row) {
    $files = [];
    foreach (coreui_skillpkg_files($pdo, (string)$row['id'], true) as $file) $files[$file['path']] = $file;
    $out[(string)$slug] = ['title'=>(string)$row['title'], 'description'=>(string)$row['description'],
      'triggers'=>coreui_skillpkg_triggers((string)$row['triggers']), 'requires'=>coreui_skillpkg_requires((string)$row['requires_tools']),
      'body'=>(string)$row['body'], 'files'=>$files, 'project'=>$row['project_id'] !== null, 'variant'=>(string)$row['variant'],
      'owner'=>(string)($row['owner'] ?? ''), 'priority'=>(int)($row['priority'] ?? 0), 'pinned'=>!empty($row['pinned']), 'id'=>(string)$row['id']];
  }
  ksort($out, SORT_STRING);
  return $out;
}

/** Built-in skills a chat fixed (their names), only optional ones that exist. */
function coreui_skillpkg_builtin_pins(PDO $pdo, int $uid, string $sessionId): array {
  $catalog = coreui_skills_catalog();
  $out = [];
  foreach (coreui_skillpkg_chat_refs($pdo, $uid, $sessionId) as $ref) {
    if (!str_starts_with($ref, 'b:')) continue;
    $slug = substr($ref, 2);
    if (isset($catalog[$slug]) && ($catalog[$slug]['required'] ?? false) !== true) $out[] = $slug;
  }
  return $out;
}

/** The chat must be this account's (and not someone else's). */
function coreui_skillpkg_session(PDO $pdo, int $uid, string $sessionId): string {
  if ($uid <= 0 || preg_match('~^[A-Za-z0-9_-]{8,40}$~D', $sessionId) !== 1) throw new InvalidArgumentException('invalid_session');
  $st = $pdo->prepare('SELECT id FROM stu_console_sessions WHERE id = ? AND user_id = ? LIMIT 1');
  $st->execute([$sessionId, $uid]);
  if ($st->fetchColumn() === false) throw new RuntimeException('session_not_found');
  return $sessionId;
}

/**
 * Settings > Skills, Gemeinsam: the shared skills of others, whether this account uses each, and
 * which skill serves instead when another has the same short name.
 */
function coreui_skillpkg_shared_list(PDO $pdo, int $uid, string $query = ''): array {
  if (!coreui_skillpkg_sharing_ready($pdo) || coreui_skillpkg_is_guest($pdo, $uid)) return [];
  $rows = coreui_skillpkg_shared_rows($pdo, $uid);
  $own = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE user_id = ? AND enabled = 1 AND project_id IS NULL');
  $own->execute([$uid]);
  $candidates = [];
  foreach ($own->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $candidates[] = $row + ['variant'=>'own'];
  foreach ($rows as $row) if (!$row['opted_out']) $candidates[] = $row;
  $resolved = coreui_skillpkg_resolve($candidates, []);
  $query = mb_strtolower(trim($query), 'UTF-8');
  $out = [];
  foreach ($rows as $row) {
    $hay = mb_strtolower($row['title'] . ' ' . $row['slug'] . ' ' . $row['description'] . ' ' . $row['triggers'] . ' ' . $row['owner'], 'UTF-8');
    if ($query !== '' && !str_contains($hay, $query)) continue;
    $item = coreui_skillpkg_public($pdo, $row);
    $item['owner'] = (string)$row['owner'];
    $item['used'] = !$row['opted_out'];
    $winner = $resolved['shadowed'][(string)$row['id']] ?? null;
    $item['shadowed_by'] = $winner === null ? null
      : ($winner['variant'] === 'own' ? ['variant'=>'own', 'title'=>(string)$winner['title']]
        : ['variant'=>'shared', 'title'=>(string)$winner['title'], 'owner'=>(string)$winner['owner']]);
    unset($item['project']);
    $out[] = $item;
  }
  return $out;
}

/** One shared skill of another account, read only, with its instruction and files. */
function coreui_skillpkg_shared_get(PDO $pdo, int $uid, string $id): array {
  foreach (coreui_skillpkg_shared_rows($pdo, $uid) as $row) {
    if ((string)$row['id'] !== $id || coreui_skillpkg_is_guest($pdo, $uid)) continue;
    $item = coreui_skillpkg_public($pdo, $row, true);
    $item['owner'] = (string)$row['owner'];
    $item['used'] = !$row['opted_out'];
    unset($item['project']);
    return $item;
  }
  throw new RuntimeException('skill_not_found');
}

/** Whether this account uses a shared skill (on by default). */
function coreui_skillpkg_use_shared(PDO $pdo, int $uid, string $id, bool $use): void {
  if (!coreui_skillpkg_sharing_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_shared_get($pdo, $uid, $id);
  $pdo->prepare('DELETE FROM stu_skill_package_optouts WHERE user_id = ? AND skill_id = ?')->execute([$uid, $id]);
  if (!$use) $pdo->prepare('INSERT INTO stu_skill_package_optouts (user_id, skill_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)')->execute([$uid, $id]);
}

/**
 * The skills a chat can fix: every variant of the account's own, project and shared skills and the
 * optional built-in ones, which of them the chat fixed, which variant serves, and fixed skills that
 * are gone.
 */
function coreui_skillpkg_chat_options(PDO $pdo, int $uid, string $sessionId): array {
  if (!coreui_skillpkg_sharing_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_session($pdo, $uid, $sessionId);
  $project = (string)(coreui_project_session_scope($pdo, $uid, $sessionId)['id'] ?? '');
  $refs = coreui_skillpkg_chat_refs($pdo, $uid, $sessionId);
  $rows = coreui_skillpkg_candidates($pdo, $uid, $project);
  $resolved = coreui_skillpkg_resolve($rows, $refs);
  $options = [];
  foreach ($rows as $row) {
    $winner = $resolved['use'][(string)$row['slug']] ?? null;
    $options[] = ['ref'=>(string)$row['id'], 'slug'=>(string)$row['slug'], 'title'=>(string)$row['title'], 'description'=>(string)$row['description'],
      'variant'=>(string)$row['variant'], 'owner'=>(string)($row['owner'] ?? ''), 'priority'=>coreui_skillpkg_priority_name((int)($row['priority'] ?? 0)),
      'requires'=>coreui_skillpkg_requires((string)$row['requires_tools']), 'chosen'=>in_array((string)$row['id'], $refs, true),
      'used'=>$winner !== null && (string)$winner['id'] === (string)$row['id']];
  }
  $catalog = coreui_skills_catalog();
  foreach ($catalog as $slug => $skill) {
    if (($skill['required'] ?? false) === true) continue;
    $slug = (string)$slug;
    $options[] = ['ref'=>'b:' . $slug, 'slug'=>$slug, 'title'=>(string)$skill['title'], 'description'=>(string)$skill['description'],
      'variant'=>'builtin', 'owner'=>'', 'priority'=>'normal', 'requires'=>[], 'chosen'=>in_array('b:' . $slug, $refs, true),
      'used'=>!isset($resolved['use'][$slug])];
  }
  $known = array_column($options, 'ref');
  $gone = array_values(array_filter($refs, static fn(string $r): bool => !in_array($r, $known, true)));
  return ['options'=>$options, 'chosen'=>$refs, 'gone'=>$gone, 'max'=>COREUI_SKILLPKG_CHAT_MAX, 'project'=>$project !== ''];
}

/** Fixes up to three skills for a chat, at most one variant per short name; [] lets Mara choose again. */
function coreui_skillpkg_chat_set(PDO $pdo, int $uid, string $sessionId, $refs): array {
  if (!coreui_skillpkg_sharing_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_session($pdo, $uid, $sessionId);
  if (!is_array($refs)) throw new InvalidArgumentException('invalid_skill_ref');
  $refs = array_values(array_unique(array_map('coreui_skillpkg_ref', $refs)));
  if (count($refs) > COREUI_SKILLPKG_CHAT_MAX) throw new InvalidArgumentException('skill_chat_limit');
  $options = [];
  foreach (coreui_skillpkg_chat_options($pdo, $uid, $sessionId)['options'] as $option) $options[$option['ref']] = $option;
  $slugs = [];
  foreach ($refs as $ref) {
    if (!isset($options[$ref])) throw new RuntimeException('skill_not_found');
    if (in_array($options[$ref]['slug'], $slugs, true)) throw new InvalidArgumentException('skill_variant_twice');
    $slugs[] = $options[$ref]['slug'];
  }
  $pdo->prepare('DELETE FROM stu_console_session_skills WHERE session_id = ? AND user_id = ?')->execute([$sessionId, $uid]);
  $add = $pdo->prepare('INSERT INTO stu_console_session_skills (session_id, user_id, ref, position, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
  foreach ($refs as $i => $ref) $add->execute([$sessionId, $uid, $ref, $i]);
  return coreui_skillpkg_chat_options($pdo, $uid, $sessionId);
}
