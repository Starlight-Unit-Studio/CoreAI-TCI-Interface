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
  return coreui_skillpkg_get($pdo, $uid, $id);
}

function coreui_skillpkg_delete(PDO $pdo, int $uid, string $id): void {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_row($pdo, $uid, $id);
  $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ?')->execute([$id]);
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
 * The account's skills for one chat (enabled; every chat, or this chat's project), with their
 * files, as api/skills.php uses them: slug => [title, description, triggers, requires, body, files].
 */
function coreui_skillpkg_for_chat(PDO $pdo, int $uid, string $projectId): array {
  if ($uid <= 0 || !coreui_skillpkg_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE user_id = ? AND enabled = 1 AND (project_id IS NULL OR project_id = ?) ORDER BY slug');
  $st->execute([$uid, $projectId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $files = [];
    foreach (coreui_skillpkg_files($pdo, (string)$row['id'], true) as $file) $files[$file['path']] = $file;
    $out[(string)$row['slug']] = ['title'=>(string)$row['title'], 'description'=>(string)$row['description'],
      'triggers'=>coreui_skillpkg_triggers((string)$row['triggers']), 'requires'=>coreui_skillpkg_requires((string)$row['requires_tools']),
      'body'=>(string)$row['body'], 'files'=>$files, 'project'=>$row['project_id'] !== null];
  }
  return $out;
}
