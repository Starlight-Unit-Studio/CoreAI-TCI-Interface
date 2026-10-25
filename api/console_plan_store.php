<?php
declare(strict_types=1);

/** Protected, immutable Markdown versions belonging to one account + chat. */
const COREUI_PLAN_DRAFT_MARKER = '<!-- COREUI_PLAN_DRAFT -->';

function coreui_plan_is_draft_markdown(string $markdown): bool {
  return str_starts_with($markdown, COREUI_PLAN_DRAFT_MARKER);
}

function coreui_plan_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,user_id,session_id,source_turn_id,response_message_id,version,title,markdown,byte_size,sha256 FROM stu_console_project_plans LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_plan_latest(PDO $pdo, int $uid, string $sessionId): ?array {
  if ($uid <= 0 || !preg_match('~^[A-Za-z0-9_-]{8,40}$~D', $sessionId)
      || !coreui_plan_schema_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT id,version,title,markdown,byte_size,sha256 FROM stu_console_project_plans
    WHERE user_id=? AND session_id=? ORDER BY created_at DESC,version DESC LIMIT 1');
  $st->execute([$uid,$sessionId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function coreui_plan_save(
  PDO $pdo, int $uid, string $sessionId, int $turnId,
  string $markdown, bool $append = false
): array {
  if ($uid <= 0 || $turnId <= 0 || !preg_match('~^[A-Za-z0-9_-]{8,40}$~D', $sessionId)
      || !coreui_plan_schema_ready($pdo)) throw new RuntimeException('plan_schema_missing');
  if (preg_match('//u', $markdown) !== 1 || str_contains($markdown, "\0"))
    throw new InvalidArgumentException('plan_invalid_utf8');
  $markdown = trim($markdown);
  if ($markdown === '' || strlen($markdown) > 262144) throw new InvalidArgumentException('plan_size_invalid');
  // The trigger must really be this user's private message in this chat.
  $check = $pdo->prepare("SELECT id FROM stu_chat_messages WHERE id=? AND user_id=? AND session_id=?
    AND channel='console' AND deleted_at IS NULL LIMIT 1");
  $check->execute([$turnId,$uid,$sessionId]);
  if (!$check->fetchColumn()) throw new RuntimeException('plan_turn_unowned');

  $previous = coreui_plan_latest($pdo,$uid,$sessionId);
  if ($append && !$previous) throw new RuntimeException('plan_not_found');
  if ($append) {
    $markdown = rtrim((string)$previous['markdown']) . "\n\n" . $markdown;
    if (strlen($markdown) > 262144) throw new InvalidArgumentException('plan_size_invalid');
  }
  $version = $previous === null ? 1 : ((int)$previous['version'] + 1);
  $title = 'Projektplan';
  $titleSource = coreui_plan_is_draft_markdown($markdown)
    ? ltrim(substr($markdown, strlen(COREUI_PLAN_DRAFT_MARKER))) : $markdown;
  if (preg_match('~^#\s+([^\r\n]{1,160})~u', $titleSource, $m)) {
    $title = trim((string)$m[1]);
  } elseif ($previous !== null) {
    $title = (string)$previous['title'];
  }
  if (mb_strlen($title, 'UTF-8') > 160) $title = mb_substr($title,0,160,'UTF-8');
  $id = bin2hex(random_bytes(16));
  $sha = hash('sha256',$markdown);
  $stmt = $pdo->prepare('INSERT INTO stu_console_project_plans
    (id,user_id,session_id,source_turn_id,response_message_id,version,title,markdown,byte_size,sha256,created_at)
    VALUES (?,?,?,?,NULL,?,?,?,?,?,NOW())');
  $stmt->execute([$id,$uid,$sessionId,$turnId,$version,$title,$markdown,strlen($markdown),$sha]);
  return ['id'=>$id,'version'=>$version,'title'=>$title,'markdown'=>$markdown,'sha256'=>$sha,
    'byte_size'=>strlen($markdown)];
}

/** Link plan versions created during this turn to the actual Mara response. */
function coreui_plan_bind_response(PDO $pdo, int $uid, string $sessionId,
                                    int $turnId, int $responseId): void {
  if ($uid <= 0 || $turnId <= 0 || $responseId <= 0 || !coreui_plan_schema_ready($pdo)) return;
  $stmt = $pdo->prepare("UPDATE stu_console_project_plans SET response_message_id=?
    WHERE user_id=? AND session_id=? AND source_turn_id=? AND response_message_id IS NULL
      AND EXISTS (SELECT 1 FROM stu_chat_messages m WHERE m.id=? AND m.user_id=?
        AND m.session_id=? AND m.channel='console' AND m.reply_to_id=?)");
  $stmt->execute([$responseId,$uid,$sessionId,$turnId,$responseId,$uid,$sessionId,$turnId]);
}

/** Download cards use existing artifact renderer but their own guarded endpoint. */
function coreui_plan_artifact_map(PDO $pdo, int $uid, array $responseIds): array {
  if ($uid <= 0 || $responseIds === [] || !coreui_plan_schema_ready($pdo)) return [];
  $ids = array_values(array_unique(array_filter(array_map('intval',$responseIds),
    static fn(int $n): bool => $n > 0)));
  if (!$ids) return [];
  $out = [];
  foreach (array_chunk($ids,100) as $chunk) {
    $st = $pdo->prepare('SELECT id,response_message_id,version,title,byte_size,sha256
      FROM stu_console_project_plans WHERE user_id=? AND response_message_id IN ('
      . implode(',',array_fill(0,count($chunk),'?')) . ') ORDER BY created_at,id');
    $st->execute(array_merge([$uid],$chunk));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $m = (int)$row['response_message_id'];
      $name = 'projektplan-v' . (int)$row['version'] . '.md';
      $out[$m][] = ['id'=>(string)$row['id'], 'name'=>$name,
        'mime_type'=>'text/markdown', 'size_bytes'=>(int)$row['byte_size'],
        'sha256'=>(string)$row['sha256'],'partial'=>false,'available'=>true,'kind'=>'plan'];
    }
  }
  return $out;
}

/** Keep the prompt bounded but preserve complete bytes in the download store. */
function coreui_plan_prompt_block(PDO $pdo, int $uid, string $sessionId): string {
  $plan = coreui_plan_latest($pdo,$uid,$sessionId);
  if (!$plan) return '';
  $body = (string)$plan['markdown'];
  // Do not turn every ordinary chat exchange into a giant plan re-prompt.
  // The original Markdown remains complete in persistent storage/downloads.
  $isDraft = coreui_plan_is_draft_markdown($body);
  $cut = strlen($body) > 3200;
  if ($cut) $body = substr($body,0,2200) . "\n[MITTELTEIL NICHT IM KONTEXT]\n" . substr($body,-700);
  return "\n\n[DAUERHAFT GESPEICHERTER PROJEKTPLAN v".(int)$plan['version']."]\n"
    . "Titel: ".(string)$plan['title']."\n"
    . "Datei bytegenau gespeichert: ".(int)$plan['byte_size']." Bytes. "
    . ($isDraft ? "STATUS: UNVOLLSTAENDIGER ENTWURF, noch nicht zum Bau freigegeben. "
      . "Nicht behaupten, dass der Plan abgeschlossen ist.\n" : "")
    . ($cut ? "Der folgende Text ist NUR EIN AUSZUG; fehlende Abschnitte nicht erfinden.\n"
      : "Vollstaendiger Inhalt:\n")
    . $body . "\n[ENDE PROJEKTPLAN]\n";
}
