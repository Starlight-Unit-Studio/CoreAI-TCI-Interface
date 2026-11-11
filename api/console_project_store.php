<?php
declare(strict_types=1);

/**
 * Chat-native projects. This store owns the scope boundary and never relies
 * on client-supplied mode flags during an AI request.
 */
function coreui_project_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id, user_id, name, context_mode, instructions_md, pinned_at FROM stu_console_projects LIMIT 0');
    $pdo->query('SELECT project_id FROM stu_console_sessions LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_project_id($value): string {
  $id = strtolower(trim((string)$value));
  return preg_match('~^[a-f0-9]{32}$~D', $id) ? $id : '';
}

function coreui_project_name($value): string {
  $name = trim((string)$value);
  $name = preg_replace('~\\s+~u', ' ', $name) ?? '';
  if (function_exists('mb_substr')) $name = mb_substr($name, 0, 80, 'UTF-8');
  else $name = substr($name, 0, 80);
  if ($name === '') throw new InvalidArgumentException('project_name_required');
  return $name;
}

function coreui_project_require(PDO $pdo, int $uid, string $projectId): array {
  if (!coreui_project_schema_ready($pdo)) throw new RuntimeException('project_migration_required');
  $projectId = coreui_project_id($projectId);
  if ($projectId === '' || $uid <= 0) throw new InvalidArgumentException('invalid_project');
  $st = $pdo->prepare('SELECT id, name, context_mode, instructions_md, pinned_at, created_at FROM stu_console_projects WHERE user_id=? AND id=? LIMIT 1');
  $st->execute([$uid, $projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) throw new RuntimeException('project_not_found');
  return $row;
}

function coreui_project_list(PDO $pdo, int $uid): array {
  if (!coreui_project_schema_ready($pdo)) throw new RuntimeException('project_migration_required');
  $st = $pdo->prepare('SELECT p.id, p.name, p.context_mode, p.instructions_md, p.pinned_at, p.created_at, COUNT(s.id) AS chat_count '
    . 'FROM stu_console_projects p LEFT JOIN stu_console_sessions s '
    . 'ON s.project_id=p.id AND s.user_id=p.user_id AND s.archived_at IS NULL '
    . 'WHERE p.user_id=? GROUP BY p.id, p.name, p.context_mode, p.instructions_md, p.pinned_at, p.created_at ORDER BY (p.pinned_at IS NULL), p.pinned_at DESC, p.created_at DESC');
  $st->execute([$uid]);
  return array_map(static function (array $row): array {
    $row['chat_count'] = (int)$row['chat_count'];
    $row['pinned'] = $row['pinned_at'] !== null;
    unset($row['pinned_at']);
    return $row;
  }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function coreui_project_create(PDO $pdo, int $uid, string $name, string $mode): array {
  if (!coreui_project_schema_ready($pdo)) throw new RuntimeException('project_migration_required');
  if ($uid <= 0) throw new InvalidArgumentException('invalid_project');
  if (!in_array($mode, ['open', 'isolated'], true)) throw new InvalidArgumentException('invalid_project_mode');
  $name = coreui_project_name($name);
  $id = bin2hex(random_bytes(16));
  $st = $pdo->prepare('INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?,?,?,?)');
  $st->execute([$id,$uid,$name,$mode]);
  return coreui_project_require($pdo,$uid,$id);
}

function coreui_project_update(PDO $pdo, int $uid, string $projectId, string $name, string $instructions): array {
  coreui_project_require($pdo,$uid,$projectId);
  $name = coreui_project_name($name);
  if (mb_strlen($instructions,'UTF-8') > 12000) throw new InvalidArgumentException('project_instructions_too_long');
  $st = $pdo->prepare('UPDATE stu_console_projects SET name=?,instructions_md=? WHERE id=? AND user_id=?');
  $st->execute([$name,$instructions,$projectId,$uid]);
  return coreui_project_require($pdo,$uid,$projectId);
}

function coreui_project_pin(PDO $pdo, int $uid, string $projectId, bool $pinned): array {
  coreui_project_require($pdo,$uid,$projectId);
  $st=$pdo->prepare('UPDATE stu_console_projects SET pinned_at=' . ($pinned?'NOW()':'NULL') . ' WHERE id=? AND user_id=?');
  $st->execute([$projectId,$uid]);
  return coreui_project_require($pdo,$uid,$projectId);
}

function coreui_project_delete(PDO $pdo, int $uid, string $projectId): array {
  coreui_project_require($pdo,$uid,$projectId);
  // Conversations survive project deletion and return to the standalone list.
  $st=$pdo->prepare('UPDATE stu_console_sessions SET project_id=NULL WHERE user_id=? AND project_id=?');
  $st->execute([$uid,$projectId]);
  // RAG sources remain owned by the account but cannot leak into global knowledge.
  // Delete through the existing verified store so files and chunks are removed.
  if (function_exists('coreui_knowledge_delete')) {
    $s=$pdo->prepare('SELECT uuid FROM stu_user_knowledge_sources WHERE user_id=? AND project_id=?');
    $s->execute([$uid,$projectId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uuid) coreui_knowledge_delete($pdo,$uid,(string)$uuid);
  }
  $del=$pdo->prepare('DELETE FROM stu_console_projects WHERE id=? AND user_id=?');
  $del->execute([$projectId,$uid]);
  return ['deleted'=>($del->rowCount()===1),'chats_kept'=>(int)$st->rowCount()];
}


function coreui_project_assign(PDO $pdo, int $uid, string $sessionId, string $projectId): ?array {
  if (!coreui_project_schema_ready($pdo)) throw new RuntimeException('project_migration_required');
  coreui_console_session_require($pdo, $uid, $sessionId, true);
  $project = $projectId !== '' ? coreui_project_require($pdo,$uid,$projectId) : null;
  // Moving a whole chat into an isolated project is intentional; existing
  // global memories are not retroactively purged or exported into the project.
  $st = $pdo->prepare('UPDATE stu_console_sessions SET project_id=? WHERE id=? AND user_id=?');
  $st->execute([$project['id'] ?? null,$sessionId,$uid]);
  return $project;
}

/** Fail closed if a linked project is missing, corrupt or cannot be read. */
function coreui_project_session_scope(PDO $pdo, int $uid, string $sessionId): array {
  if ($uid <= 0 || $sessionId === '' || !coreui_project_schema_ready($pdo)) {
    return ['mode'=>'isolated','id'=>'','name'=>'','available'=>false];
  }
  $st = $pdo->prepare('SELECT s.project_id,p.name,p.context_mode FROM stu_console_sessions s '
    . 'LEFT JOIN stu_console_projects p ON p.id=s.project_id AND p.user_id=s.user_id '
    . 'WHERE s.id=? AND s.user_id=? LIMIT 1');
  $st->execute([$sessionId,$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) return ['mode'=>'isolated','id'=>'','name'=>'','available'=>false];
  if ($row['project_id'] === null) return ['mode'=>'none','id'=>'','name'=>'','available'=>true];
  if (!is_string($row['context_mode']) || !in_array($row['context_mode'], ['open','isolated'], true)) {
    return ['mode'=>'isolated','id'=>'','name'=>'','available'=>false];
  }
  return ['mode'=>$row['context_mode'], 'id'=>(string)$row['project_id'],
    'name'=>(string)$row['name'],'available'=>true];
}

function coreui_project_context_block(PDO $pdo, int $uid, string $sessionId, int $budget = 2000): string {
  $scope = coreui_project_session_scope($pdo,$uid,$sessionId);
  if (empty($scope['available']) || $scope['id'] === '') return '';
  $budget = max(300,min(3000,$budget));
  $st = $pdo->prepare(
    "SELECT m.character_name,m.character_id,m.message,s.title FROM stu_chat_messages m
    JOIN stu_console_sessions s ON s.id=m.session_id AND s.user_id=m.user_id
    WHERE s.project_id=? AND s.user_id=? AND s.id<>?
      AND s.archived_at IS NULL AND m.channel='console'
      AND m.deleted_at IS NULL AND m.alliance_id IS NULL
    ORDER BY m.id DESC LIMIT 24"
  );
  $st->execute([$scope['id'],$uid,$sessionId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $entries = [];
  $used = 0;
  foreach ($rows as $row) {
    if (strtolower((string)$row['character_id']) === 'system') continue;
    $body = trim((string)$row['message']);
    $body = preg_replace('~\\[(?:file|img):[^\\]]+\\]~i', '[Anhang]', $body) ?? '';
    if ($body === '') continue;
    if (function_exists('mb_substr')) $body = mb_substr($body,0,260,'UTF-8');
    else $body = substr($body,0,260);
    $line = (string)$row['title'] . ' / ' . (string)$row['character_name'] . ': ' . $body;
    if ($used+strlen($line)>$budget) break;
    $entries[]=$line;
    $used+=strlen($line);
  }
  if ($entries===[]) return '';
  return "\n[PROJEKTKONTEXT: ausschliesslich andere Chats dieses Projekts, kein fremder Chat]\n"
    . implode("\n",array_reverse($entries)) . "\n[/PROJEKTKONTEXT]\n";
}
