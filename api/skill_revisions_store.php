<?php
declare(strict_types=1);

/*
 * 0.7.90 (P13 A): fixed revisions of a skill (migration 054), and skills as packages.
 *
 * Every change of a skill records the whole skill as a new revision: its details, its instruction
 * and every companion file. A revision never changes afterwards; restoring one adds a new revision
 * on top, so nothing is lost. A change names the revision it started from; if someone saved in
 * between, it is refused ("skill_conflict") instead of overwriting unnoticed.
 *
 * A package is a ZIP with one folder: SKILL.md (front matter with name, title, description,
 * triggers, requires, priority, and the instruction below it) and the companion files in
 * references/, templates/, scripts/ and assets/. Importing checks everything before it changes
 * anything: no paths that leave the folder, no links, no binary files, the same limits as the editor.
 * The functions here are used by api/skill_packages_store.php, which loads this file.
 */

const COREUI_SKILLREV_KEEP = 100;
const COREUI_SKILLREV_KINDS = ['create' => 'Angelegt', 'save' => 'Gespeichert', 'file' => 'Begleitdatei', 'restore' => 'Wiederhergestellt',
  'import' => 'Importiert'];
const COREUI_SKILLREV_ZIP_BYTES = 2000000;
const COREUI_SKILLREV_ZIP_ENTRIES = 100;
const COREUI_SKILLREV_UNPACKED_BYTES = 1000000;
const COREUI_SKILLREV_FORMAT = 1;
/** Front matter keys a package may carry; others are named as ignored. */
const COREUI_SKILLREV_KEYS = ['name', 'title', 'description', 'triggers', 'requires', 'priority', 'coreai_skill_format'];

/** A package that cannot be imported; $path names the entry where that applies. */
final class CoreUISkillPackageError extends InvalidArgumentException {
  public string $path;
  public function __construct(string $code, string $path = '') {
    parent::__construct($code);
    $this->path = $path;
  }
}

/** Importing as a new skill when the account already has one with that short name. */
final class CoreUISkillImportExists extends RuntimeException {
  public string $slug;
  public string $title;
  public function __construct(string $slug, string $title) {
    parent::__construct('skill_exists');
    $this->slug = $slug;
    $this->title = $title;
  }
}

function coreui_skillrev_ready(PDO $pdo): bool {
  // 0.7.90: a WeakMap, so a new connection never inherits the answer of a freed one with the same object id.
  static $memo = null;
  $memo ??= new WeakMap();
  $key = $pdo;
  if (!isset($memo[$key])) {
    try {
      $pdo->query('SELECT revision FROM stu_skill_packages LIMIT 0');
      $pdo->query('SELECT skill_id, rev, user_id, kind, note, meta, body_sha FROM stu_skill_revisions LIMIT 0');
      $pdo->query('SELECT skill_id, rev, path, kind, sha256 FROM stu_skill_revision_files LIMIT 0');
      $pdo->query('SELECT skill_id, sha256, content FROM stu_skill_blobs LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** Runs $fn in one transaction (or in the caller's). */
function coreui_skillpkg_atomic(PDO $pdo, callable $fn) {
  if ($pdo->inTransaction()) return $fn();
  $pdo->beginTransaction();
  try {
    $out = $fn();
    $pdo->commit();
    return $out;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** The details a revision keeps (pure), from a skill row. */
function coreui_skillrev_meta(array $row): array {
  return ['slug'=>(string)$row['slug'], 'title'=>(string)$row['title'], 'description'=>(string)$row['description'],
    'triggers'=>(string)$row['triggers'], 'requires_tools'=>(string)$row['requires_tools'],
    'project_id'=>$row['project_id'] === null ? null : (string)$row['project_id'], 'enabled'=>(int)$row['enabled'] === 1,
    'shared'=>(int)($row['shared'] ?? 0) === 1, 'priority'=>(int)($row['priority'] ?? 0)];
}

function coreui_skillrev_blob(PDO $pdo, string $skillId, string $content): string {
  $sha = hash('sha256', $content);
  $st = $pdo->prepare('SELECT 1 FROM stu_skill_blobs WHERE skill_id = ? AND sha256 = ?');
  $st->execute([$skillId, $sha]);
  if ($st->fetchColumn() === false) {
    $pdo->prepare('INSERT INTO stu_skill_blobs (skill_id, sha256, content, size_bytes, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
      ->execute([$skillId, $sha, $content, strlen($content)]);
  }
  return $sha;
}

/** The revision a change starts from must still be the current one. */
function coreui_skillrev_check(PDO $pdo, array $row, $base): void {
  if (!coreui_skillrev_ready($pdo) || $base === null || $base === '') return;
  if (!is_numeric($base) || (int)$base !== (int)($row['revision'] ?? 0)) throw new RuntimeException('skill_conflict');
}

/**
 * Records the skill as it is now as a new revision; the counter moves only from $expected (or from
 * what it is now). Returns the new revision, 0 without migration 054.
 */
function coreui_skillrev_record(PDO $pdo, string $skillId, int $uid, string $kind, string $note = '', ?int $expected = null): int {
  if (!coreui_skillrev_ready($pdo)) return 0;
  if (!isset(COREUI_SKILLREV_KINDS[$kind])) throw new InvalidArgumentException('invalid_skill_revision_kind');
  $st = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE id = ?');
  $st->execute([$skillId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('skill_not_found');
  if ($expected !== null && (int)$row['revision'] !== $expected) throw new RuntimeException('skill_conflict');
  $current = $expected ?? (int)$row['revision'];
  $metaJson = json_encode(coreui_skillrev_meta($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $files = coreui_skillpkg_files($pdo, $skillId, true);
  if ($current > 0) {
    // Nothing changed against the current revision: no new one.
    $last = $pdo->prepare('SELECT meta, body_sha FROM stu_skill_revisions WHERE skill_id = ? AND rev = ?');
    $last->execute([$skillId, $current]);
    $lastRow = $last->fetch(PDO::FETCH_ASSOC);
    $lastFiles = array_map(static fn(array $f): string => (string)$f['kind'] . ':' . (string)$f['sha256'],
      coreui_skillrev_file_rows($pdo, $skillId, $current)[$current] ?? []);
    $nowFiles = [];
    foreach ($files as $file) $nowFiles[$file['path']] = $file['kind'] . ':' . hash('sha256', (string)$file['content']);
    ksort($lastFiles); ksort($nowFiles);
    if ($lastRow && (string)$lastRow['meta'] === $metaJson && (string)$lastRow['body_sha'] === hash('sha256', (string)$row['body'])
        && $lastFiles === $nowFiles) {
      return $current;
    }
  }
  $rev = $current + 1;
  $move = $pdo->prepare('UPDATE stu_skill_packages SET revision = ? WHERE id = ? AND revision = ?');
  $move->execute([$rev, $skillId, $current]);
  if ($move->rowCount() !== 1) throw new RuntimeException('skill_conflict');
  $bodySha = coreui_skillrev_blob($pdo, $skillId, (string)$row['body']);
  $pdo->prepare('INSERT INTO stu_skill_revisions (skill_id, rev, user_id, kind, note, meta, body_sha, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')
    ->execute([$skillId, $rev, $uid > 0 ? $uid : null, $kind, mb_substr($note, 0, 160, 'UTF-8'), $metaJson, $bodySha]);
  $add = $pdo->prepare('INSERT INTO stu_skill_revision_files (skill_id, rev, path, kind, sha256, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($files as $file) {
    $add->execute([$skillId, $rev, $file['path'], $file['kind'], coreui_skillrev_blob($pdo, $skillId, (string)$file['content']), $file['size_bytes']]);
  }
  if ($rev > COREUI_SKILLREV_KEEP) {
    // The oldest go once there are more than 100; contents nothing refers to go with them.
    $pdo->prepare('DELETE FROM stu_skill_revision_files WHERE skill_id = ? AND rev <= ?')->execute([$skillId, $rev - COREUI_SKILLREV_KEEP]);
    $pdo->prepare('DELETE FROM stu_skill_revisions WHERE skill_id = ? AND rev <= ?')->execute([$skillId, $rev - COREUI_SKILLREV_KEEP]);
    $pdo->prepare('DELETE FROM stu_skill_blobs WHERE skill_id = ? AND sha256 NOT IN (SELECT sha256 FROM stu_skill_revision_files WHERE skill_id = ?)
      AND sha256 NOT IN (SELECT body_sha FROM stu_skill_revisions WHERE skill_id = ?)')->execute([$skillId, $skillId, $skillId]);
  }
  return $rev;
}

/** A skill from before migration 054 gets its current state as the first revision before it changes. */
function coreui_skillrev_baseline(PDO $pdo, string $skillId, int $uid, array $row): int {
  $current = (int)($row['revision'] ?? 0);
  if (!coreui_skillrev_ready($pdo) || $current > 0) return $current;
  return coreui_skillrev_record($pdo, $skillId, $uid, 'create', 'Stand vor den Revisionen', 0);
}

function coreui_skillrev_rows(PDO $pdo, string $skillId): array {
  $st = $pdo->prepare('SELECT rev, user_id, kind, note, meta, body_sha, created_at FROM stu_skill_revisions WHERE skill_id = ? ORDER BY rev DESC');
  $st->execute([$skillId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(int)$row['rev']] = $row;
  return $out;
}

function coreui_skillrev_file_rows(PDO $pdo, string $skillId, ?int $rev = null): array {
  $sql = 'SELECT rev, path, kind, sha256, size_bytes FROM stu_skill_revision_files WHERE skill_id = ?' . ($rev !== null ? ' AND rev = ?' : '') . ' ORDER BY rev, path';
  $st = $pdo->prepare($sql);
  $st->execute($rev !== null ? [$skillId, $rev] : [$skillId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(int)$row['rev']][(string)$row['path']] = $row;
  return $out;
}

/** What a revision changed against the one before (pure): fields, the instruction, files. */
function coreui_skillrev_changes(?array $before, array $after, array $filesBefore, array $filesAfter): array {
  $fields = [];
  $a = $before === null ? [] : (array)json_decode((string)$before['meta'], true);
  $b = (array)json_decode((string)$after['meta'], true);
  foreach (['slug', 'title', 'description', 'triggers', 'requires_tools', 'project_id', 'enabled', 'shared', 'priority'] as $key) {
    if ($before === null || ($a[$key] ?? null) !== ($b[$key] ?? null)) $fields[] = $key;
  }
  $added = array_values(array_diff(array_keys($filesAfter), array_keys($filesBefore)));
  $removed = array_values(array_diff(array_keys($filesBefore), array_keys($filesAfter)));
  $changed = [];
  foreach ($filesAfter as $path => $file) {
    if (isset($filesBefore[$path]) && (string)$filesBefore[$path]['sha256'] !== (string)$file['sha256']) $changed[] = (string)$path;
  }
  return ['fields'=>$before === null ? [] : $fields, 'body'=>$before !== null && (string)$before['body_sha'] !== (string)$after['body_sha'],
    'files'=>['added'=>$added, 'removed'=>$removed, 'changed'=>$changed]];
}

/** Who made a revision, as the viewer reads it. */
function coreui_skillrev_by(PDO $pdo, $userId, int $viewer): string {
  if ($userId === null) return 'ein gelöschtes Konto';
  if ((int)$userId === $viewer) return 'du';
  return coreui_skillpkg_owner_label($pdo, (int)$userId, coreui_skillpkg_level($pdo, (int)$userId));
}

/** The history of an own skill, newest first, each with what it changed. */
function coreui_skillrev_list(PDO $pdo, int $uid, string $id): array {
  if (!coreui_skillrev_ready($pdo)) throw new RuntimeException('skill_migration_required');
  $skill = coreui_skillpkg_row($pdo, $uid, $id);
  $rows = coreui_skillrev_rows($pdo, $id);
  $files = coreui_skillrev_file_rows($pdo, $id);
  $out = [];
  foreach ($rows as $rev => $row) {
    $prev = $rows[$rev - 1] ?? null;
    $out[] = ['rev'=>$rev, 'kind'=>(string)$row['kind'], 'label'=>COREUI_SKILLREV_KINDS[(string)$row['kind']] ?? (string)$row['kind'],
      'note'=>(string)$row['note'], 'by'=>coreui_skillrev_by($pdo, $row['user_id'], $uid), 'created_at'=>(string)$row['created_at'],
      'current'=>$rev === (int)$skill['revision'],
      'changes'=>coreui_skillrev_changes($prev, $row, $prev === null ? [] : ($files[$rev - 1] ?? []), $files[$rev] ?? [])];
  }
  return ['revision'=>(int)$skill['revision'], 'revisions'=>$out, 'keep'=>COREUI_SKILLREV_KEEP];
}

/** One revision with its instruction and files (raw, for restoring and exporting). */
function coreui_skillrev_snapshot(PDO $pdo, string $id, int $rev): array {
  $st = $pdo->prepare('SELECT rev, user_id, kind, note, meta, body_sha, created_at FROM stu_skill_revisions WHERE skill_id = ? AND rev = ?');
  $st->execute([$id, $rev]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('skill_revision_not_found');
  $blob = $pdo->prepare('SELECT content FROM stu_skill_blobs WHERE skill_id = ? AND sha256 = ?');
  $content = static function (string $sha) use ($blob, $id): string {
    $blob->execute([$id, $sha]);
    $text = $blob->fetchColumn();
    if ($text === false) throw new RuntimeException('skill_revision_damaged');
    return (string)$text;
  };
  $files = [];
  foreach ((coreui_skillrev_file_rows($pdo, $id, $rev)[$rev] ?? []) as $path => $file) {
    $files[(string)$path] = ['kind'=>(string)$file['kind'], 'content'=>$content((string)$file['sha256']), 'size_bytes'=>(int)$file['size_bytes'],
      'sha256'=>(string)$file['sha256']];
  }
  return ['rev'=>(int)$row['rev'], 'kind'=>(string)$row['kind'], 'note'=>(string)$row['note'], 'user_id'=>$row['user_id'],
    'created_at'=>(string)$row['created_at'], 'meta'=>(array)json_decode((string)$row['meta'], true), 'body'=>$content((string)$row['body_sha']),
    'files'=>$files];
}

/** One revision of an own skill, as the settings page shows it. */
function coreui_skillrev_get(PDO $pdo, int $uid, string $id, int $rev): array {
  if (!coreui_skillrev_ready($pdo)) throw new RuntimeException('skill_migration_required');
  coreui_skillpkg_row($pdo, $uid, $id);
  $snap = coreui_skillrev_snapshot($pdo, $id, $rev);
  $meta = $snap['meta'];
  $files = [];
  foreach ($snap['files'] as $path => $file) $files[] = ['path'=>$path] + $file;
  return ['rev'=>$snap['rev'], 'kind'=>$snap['kind'], 'label'=>COREUI_SKILLREV_KINDS[$snap['kind']] ?? $snap['kind'], 'note'=>$snap['note'],
    'by'=>coreui_skillrev_by($pdo, $snap['user_id'], $uid), 'created_at'=>$snap['created_at'],
    'slug'=>(string)($meta['slug'] ?? ''), 'title'=>(string)($meta['title'] ?? ''), 'description'=>(string)($meta['description'] ?? ''),
    'triggers'=>coreui_skillpkg_triggers((string)($meta['triggers'] ?? '')), 'requires'=>coreui_skillpkg_requires((string)($meta['requires_tools'] ?? '')),
    'priority'=>coreui_skillpkg_priority_name((int)($meta['priority'] ?? 0)), 'project_id'=>$meta['project_id'] ?? null,
    'enabled'=>!empty($meta['enabled']), 'shared'=>!empty($meta['shared']), 'body'=>$snap['body'], 'files'=>$files];
}

/** Brings an earlier revision back as a new revision on top; the ones in between stay. */
function coreui_skillrev_restore(PDO $pdo, int $uid, string $id, $rev, $base): array {
  if (!coreui_skillrev_ready($pdo)) throw new RuntimeException('skill_migration_required');
  if (!is_numeric($rev) || (int)$rev < 1) throw new InvalidArgumentException('invalid_skill_revision');
  $rev = (int)$rev;
  coreui_skillpkg_atomic($pdo, static function () use ($pdo, $uid, $id, $rev, $base): void {
    $row = coreui_skillpkg_row($pdo, $uid, $id);
    coreui_skillrev_check($pdo, $row, $base);
    $snap = coreui_skillrev_snapshot($pdo, $id, $rev);
    $meta = $snap['meta'];
    $slug = (string)$meta['slug'];
    $same = $pdo->prepare('SELECT id FROM stu_skill_packages WHERE user_id = ? AND slug = ? AND id <> ?');
    $same->execute([$uid, $slug, $id]);
    if ($same->fetchColumn() !== false) throw new RuntimeException('skill_exists');
    // A project that is gone meanwhile, or sharing without the right to share any more, is not brought back.
    $project = $meta['project_id'] ?? null;
    if ($project !== null) {
      $p = $pdo->prepare('SELECT id FROM stu_console_projects WHERE id = ? AND user_id = ?');
      $p->execute([(string)$project, $uid]);
      if ($p->fetchColumn() === false) $project = null;
    }
    $shared = !empty($meta['shared']) && $project === null && coreui_skillpkg_can_share($pdo, $uid) ? 1 : 0;
    $pdo->prepare('UPDATE stu_skill_packages SET slug = ?, title = ?, description = ?, body = ?, triggers = ?, requires_tools = ?, project_id = ?,
      enabled = ?, shared = ?, priority = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
      ->execute([$slug, (string)$meta['title'], (string)$meta['description'], $snap['body'], (string)$meta['triggers'], (string)$meta['requires_tools'],
        $project, !empty($meta['enabled']) ? 1 : 0, $shared, (int)($meta['priority'] ?? 0), $id, $uid]);
    $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ?')->execute([$id]);
    $add = $pdo->prepare('INSERT INTO stu_skill_package_files (skill_id, path, kind, content, size_bytes, sha256, updated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    foreach ($snap['files'] as $path => $file) $add->execute([$id, $path, $file['kind'], $file['content'], strlen($file['content']), hash('sha256', $file['content'])]);
    coreui_skillrev_record($pdo, $id, $uid, 'restore', 'Wiederhergestellt aus Revision ' . $rev, (int)$row['revision']);
  });
  return coreui_skillpkg_get($pdo, $uid, $id);
}

// --- Packages ----------------------------------------------------------------------------------------

/** SKILL.md of a package (pure): front matter in JSON notation, which YAML reads too, then the instruction. */
function coreui_skillrev_skill_md(array $meta, string $body): string {
  $json = static fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  return "---\nname: " . (string)$meta['slug'] . "\ntitle: " . $json((string)$meta['title']) . "\ndescription: " . $json((string)$meta['description'])
    . "\ntriggers: " . $json(coreui_skillpkg_triggers((string)$meta['triggers'])) . "\nrequires: " . $json(coreui_skillpkg_requires((string)$meta['requires_tools']))
    . "\npriority: " . coreui_skillpkg_priority_name((int)($meta['priority'] ?? 0)) . "\ncoreai_skill_format: " . COREUI_SKILLREV_FORMAT . "\n---\n\n"
    . rtrim($body) . "\n";
}

/** A front matter value (pure): JSON notation, a YAML list, or a plain scalar. */
function coreui_skillrev_value(string $raw, array $list) {
  $raw = trim($raw);
  if ($raw === '' && $list !== []) return $list;
  if ($raw !== '' && ($raw[0] === '"' || $raw[0] === '[')) {
    $decoded = json_decode($raw, true);
    if ($decoded === null && strtolower($raw) !== 'null') throw new CoreUISkillPackageError('skill_package_bad_skill_md', 'SKILL.md');
    return $decoded;
  }
  if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") return str_replace("''", "'", substr($raw, 1, -1));
  return $raw;
}

/** Front matter and instruction of a SKILL.md (pure): [values by key, instruction, ignored keys]. */
function coreui_skillrev_parse_skill_md(string $text): array {
  $text = str_replace("\r\n", "\n", $text);
  if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
  if (preg_match('~\A---\n(.*?)\n---[ \t]*(?:\n(.*))?\z~s', $text, $m) !== 1) throw new CoreUISkillPackageError('skill_package_bad_skill_md', 'SKILL.md');
  $values = [];
  $ignored = [];
  $lines = explode("\n", $m[1]);
  for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    if (trim($line) === '' || str_starts_with(ltrim($line), '#')) continue;
    if (preg_match('~^([A-Za-z0-9_-]+):(.*)$~', $line, $kv) !== 1) {
      if (preg_match('~^\s~', $line) === 1) continue; // a continuation of an ignored value
      throw new CoreUISkillPackageError('skill_package_bad_skill_md', 'SKILL.md');
    }
    $list = [];
    while (isset($lines[$i + 1]) && preg_match('~^\s+-\s+(.*)$~', $lines[$i + 1], $item) === 1) {
      $list[] = (string)coreui_skillrev_value($item[1], []);
      $i++;
    }
    $key = strtolower($kv[1]);
    if (!in_array($key, COREUI_SKILLREV_KEYS, true)) { $ignored[] = $kv[1]; continue; }
    $values[$key] = coreui_skillrev_value($kv[2], $list);
  }
  return [$values, trim((string)($m[2] ?? '')), $ignored];
}

/** The pieces of a package (pure over the ZIP): details, instruction, files, skipped entries, ignored keys. */
function coreui_skillrev_read_package(string $zipPath): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('zip_unavailable');
  if (!is_file($zipPath) || filesize($zipPath) > COREUI_SKILLREV_ZIP_BYTES) throw new CoreUISkillPackageError('skill_package_too_large');
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) throw new CoreUISkillPackageError('skill_package_invalid');
  try {
    if ($zip->numFiles > COREUI_SKILLREV_ZIP_ENTRIES) throw new CoreUISkillPackageError('skill_package_too_large');
    $entries = [];
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $stat = $zip->statIndex($i);
      if (!is_array($stat)) throw new CoreUISkillPackageError('skill_package_invalid');
      $name = (string)$stat['name'];
      // Paths that leave the package, absolute ones and links are never read.
      if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name) === 1
          || in_array('..', explode('/', $name), true)) {
        throw new CoreUISkillPackageError('skill_package_unsafe', $name);
      }
      if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
        throw new CoreUISkillPackageError('skill_package_unsafe', $name);
      }
      if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
      $total += (int)$stat['size'];
      if ($total > COREUI_SKILLREV_UNPACKED_BYTES) throw new CoreUISkillPackageError('skill_package_too_large');
      $entries[$i] = $name;
    }
    // SKILL.md at the top, or in the one folder that holds everything.
    $roots = array_values(array_filter($entries, static fn(string $n): bool => basename($n) === 'SKILL.md' && substr_count($n, '/') <= 1));
    if ($roots === []) throw new CoreUISkillPackageError('skill_package_no_skill_md');
    if (count($roots) > 1) throw new CoreUISkillPackageError('skill_package_invalid', implode(', ', $roots));
    $prefix = str_contains($roots[0], '/') ? substr($roots[0], 0, strrpos($roots[0], '/') + 1) : '';
    $files = [];
    $skipped = [];
    $skillMd = null;
    foreach ($entries as $i => $name) {
      if ($prefix !== '' && !str_starts_with($name, $prefix)) { $skipped[] = ['name'=>$name, 'reason'=>'outside_skill']; continue; }
      $relative = substr($name, strlen($prefix));
      $bytes = $zip->getFromIndex($i);
      if (!is_string($bytes)) throw new CoreUISkillPackageError('skill_package_invalid', $name);
      if ($relative === 'SKILL.md') { $skillMd = $bytes; continue; }
      if (preg_match('~^(references|templates|scripts|assets)/[^/]+$~', $relative) !== 1) { $skipped[] = ['name'=>$name, 'reason'=>'outside_folders']; continue; }
      try {
        [$path] = coreui_skillpkg_path($relative);
      } catch (InvalidArgumentException $e) {
        $skipped[] = ['name'=>$name, 'reason'=>'invalid_skill_path'];
        continue;
      }
      if (str_contains($bytes, "\0") || preg_match('//u', $bytes) !== 1) throw new CoreUISkillPackageError('invalid_skill_file', $name);
      $bytes = str_replace("\r\n", "\n", $bytes);
      if (strlen($bytes) > COREUI_SKILLPKG_FILE_BYTES) throw new CoreUISkillPackageError('skill_file_too_large', $name);
      $files[$path] = $bytes;
    }
    if (count($files) > COREUI_SKILLPKG_FILES) throw new CoreUISkillPackageError('skill_files_limit');
    if ($skillMd === null || str_contains($skillMd, "\0") || preg_match('//u', $skillMd) !== 1) throw new CoreUISkillPackageError('skill_package_bad_skill_md', 'SKILL.md');
    [$values, $body, $ignored] = coreui_skillrev_parse_skill_md($skillMd);
  } finally {
    $zip->close();
  }
  ksort($files, SORT_STRING);
  return ['values'=>$values, 'body'=>$body, 'files'=>$files, 'skipped'=>$skipped, 'ignored'=>$ignored];
}

/** A ZIP of an own skill (its current state, or one revision): ['path' => tmp, 'name' => download name]. */
function coreui_skillrev_export(PDO $pdo, int $uid, string $id, $rev = null): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('zip_unavailable');
  $row = coreui_skillpkg_row($pdo, $uid, $id);
  if ($rev !== null && $rev !== '') {
    if (!coreui_skillrev_ready($pdo) || !is_numeric($rev)) throw new RuntimeException('skill_revision_not_found');
    $snap = coreui_skillrev_snapshot($pdo, $id, (int)$rev);
    $meta = $snap['meta'];
    $body = $snap['body'];
    $files = array_map(static fn(array $f): string => $f['content'], $snap['files']);
  } else {
    $meta = coreui_skillrev_meta($row);
    $body = (string)$row['body'];
    $files = [];
    foreach (coreui_skillpkg_files($pdo, $id, true) as $file) $files[$file['path']] = (string)$file['content'];
  }
  $slug = (string)$meta['slug'];
  $tmp = tempnam(sys_get_temp_dir(), 'coreui-skill-');
  if ($tmp === false) throw new RuntimeException('zip_failed');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  $zip->addFromString($slug . '/SKILL.md', coreui_skillrev_skill_md($meta, $body));
  foreach ($files as $path => $content) $zip->addFromString($slug . '/' . $path, $content);
  if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  return ['path'=>$tmp, 'name'=>$slug . ($rev !== null && $rev !== '' ? '-revision-' . (int)$rev : '') . '.zip'];
}

/**
 * Imports a package: a new skill ($mode 'new'), or a new revision of the own skill with its short
 * name ($mode 'update'; project, on/off and sharing stay as they are). Nothing changes when anything
 * in the package is wrong. Returns ['skill', 'created', 'skipped', 'ignored'].
 */
function coreui_skillrev_import(PDO $pdo, int $uid, string $zipPath, string $mode = 'new', string $slug = '', $base = null, string $fileName = ''): array {
  if (!coreui_skillpkg_ready($pdo)) throw new RuntimeException('skill_migration_required');
  if (!in_array($mode, ['new', 'update'], true)) throw new InvalidArgumentException('invalid_skill_import_mode');
  $package = coreui_skillrev_read_package($zipPath);
  $values = $package['values'];
  if (isset($values['coreai_skill_format']) && (int)$values['coreai_skill_format'] > COREUI_SKILLREV_FORMAT) {
    throw new CoreUISkillPackageError('skill_package_format', 'SKILL.md');
  }
  $str = static fn($v): string => is_scalar($v) ? (string)$v : '';
  $name = coreui_skillpkg_slug($slug !== '' ? $slug : $str($values['name'] ?? ''));
  $data = ['slug'=>$name, 'title'=>$str($values['title'] ?? '') !== '' ? $str($values['title']) : $name, 'description'=>$str($values['description'] ?? ''),
    'body'=>$package['body'], 'triggers'=>is_array($values['triggers'] ?? null) ? $values['triggers'] : $str($values['triggers'] ?? ''),
    'requires'=>is_array($values['requires'] ?? null) ? $values['requires'] : $str($values['requires'] ?? ''),
    'priority'=>$str($values['priority'] ?? 'normal')];
  $clean = coreui_skillpkg_validated($pdo, $uid, $data);
  $note = 'Importiert' . ($fileName !== '' ? ' aus ' . mb_substr(basename($fileName), 0, 120, 'UTF-8') : '');
  $same = $pdo->prepare('SELECT * FROM stu_skill_packages WHERE user_id = ? AND slug = ?');
  $same->execute([$uid, $name]);
  $existing = $same->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($existing !== null && $mode === 'new') throw new CoreUISkillImportExists($name, (string)$existing['title']);
  if ($existing === null && $mode === 'update') throw new RuntimeException('skill_not_found');
  $sharing = coreui_skillpkg_sharing_ready($pdo);
  $id = coreui_skillpkg_atomic($pdo, static function () use ($pdo, $uid, $existing, $clean, $package, $note, $base, $sharing): string {
    if ($existing === null) {
      $count = $pdo->prepare('SELECT COUNT(*) FROM stu_skill_packages WHERE user_id = ?');
      $count->execute([$uid]);
      if ((int)$count->fetchColumn() >= COREUI_SKILLPKG_MAX) throw new RuntimeException('skill_limit');
      $id = bin2hex(random_bytes(16));
      $pdo->prepare('INSERT INTO stu_skill_packages (id, user_id, slug, title, description, body, triggers, requires_tools, project_id, enabled,
        created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
        ->execute([$id, $uid, $clean['slug'], $clean['title'], $clean['description'], $clean['body'], $clean['triggers'], $clean['requires']]);
      $expected = 0;
    } else {
      $id = (string)$existing['id'];
      coreui_skillrev_check($pdo, $existing, $base);
      $expected = coreui_skillrev_baseline($pdo, $id, $uid, $existing);
      $pdo->prepare('UPDATE stu_skill_packages SET title = ?, description = ?, body = ?, triggers = ?, requires_tools = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND user_id = ?')->execute([$clean['title'], $clean['description'], $clean['body'], $clean['triggers'], $clean['requires'], $id, $uid]);
      $pdo->prepare('DELETE FROM stu_skill_package_files WHERE skill_id = ?')->execute([$id]);
    }
    if ($sharing) $pdo->prepare('UPDATE stu_skill_packages SET priority = ? WHERE id = ?')->execute([$clean['priority'], $id]);
    $add = $pdo->prepare('INSERT INTO stu_skill_package_files (skill_id, path, kind, content, size_bytes, sha256, updated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    foreach ($package['files'] as $path => $content) {
      [, $kind] = coreui_skillpkg_path($path);
      $add->execute([$id, $path, $kind, $content, strlen($content), hash('sha256', $content)]);
    }
    coreui_skillrev_record($pdo, $id, $uid, 'import', $note, coreui_skillrev_ready($pdo) ? (int)$expected : null);
    return $id;
  });
  return ['skill'=>coreui_skillpkg_get($pdo, $uid, $id), 'created'=>$existing === null, 'skipped'=>$package['skipped'], 'ignored'=>$package['ignored']];
}
