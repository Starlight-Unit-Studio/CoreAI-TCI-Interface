<?php
declare(strict_types=1);

/**
 * Per-account Mara presentation settings.  No settings are global, no
 * ModelFile is modified and the client never supplies executable code.
 */
function coreui_personalization_defaults(): array {
  return [
    'tone' => 'standard',
    'warmth' => 'mehr',
    'enthusiasm' => 'standard',
    'emoji' => 'mehr',
    'visual_answers' => 'mehr',
    'headings_lists' => 'mehr',
    'adapt_style' => false,
    'quick_answers' => false,
    'suggested_prompts' => true,
    'web_search' => true,
    'browse_search' => true,
    'knowledge_search' => true,
    // 0.7.0: Settings > Personalisierung like the reference.
    'voice_history' => true,   // earlier Stimmsequenzen stay in the chat history Mara reads
    'live_voice' => true,      // the live conversation button in the chat
    'custom_instructions' => '',
    // 0.7.0: "Benutzerdefinierte Anweisungen" also asks for a nickname, the job, more about the person and a role.
    'nickname' => '',
    'occupation' => '',
    'about' => '',
    'role' => '',
    // Reference photos of the person for images of themselves (stu_console_media uuids, set by the server only).
    'reference_portrait' => '',
    'reference_body' => ''
  ];
}

/** 0.7.0: the base styles of the reference; older stored values move to the closest one. */
const COREUI_PERSONALIZATION_TONES = ['standard','professionell','freundlich','aufrichtig','skurril','effizient','zynisch'];
const COREUI_PERSONALIZATION_TONE_LEGACY = ['locker' => 'freundlich', 'kreativ' => 'skurril', 'praezise' => 'effizient'];

/** The roles the person can pick under "Benutzerdefinierte Anweisungen" (key => label). */
const COREUI_PERSONALIZATION_ROLES = [
  'entwicklung' => 'Softwareentwicklung', 'design' => 'Design', 'produkt' => 'Produktmanagement',
  'marketing' => 'Marketing', 'vertrieb' => 'Vertrieb', 'forschung' => 'Forschung und Wissenschaft',
  'bildung' => 'Bildung und Lehre', 'studium' => 'Studium oder Schule', 'medien' => 'Schreiben und Medien',
  'gesundheit' => 'Gesundheit und Pflege', 'recht' => 'Recht', 'finanzen' => 'Finanzen',
  'handwerk' => 'Handwerk und Technik', 'sonstiges' => 'Sonstiges'
];

/** One cleaned text: no control characters, single line when $lines is false, at most $max characters. */
function coreui_personalization_text($value,int $max,bool $lines): string {
  $text = str_replace(["\r\n","\r"],"\n",(string)$value);
  $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text) ?? '';
  if (!$lines) $text = preg_replace('/\s+/u',' ',$text) ?? '';
  return function_exists('mb_substr') ? mb_substr(trim($text),0,$max,'UTF-8') : substr(trim($text),0,$max);
}

/** The two reference photo slots: a face portrait and a full-body photo. */
const COREUI_PERSONALIZATION_REFERENCE_SLOTS = ['portrait' => 'reference_portrait', 'body' => 'reference_body'];

/** Keys the settings page may change with "save"; the reference photos change only through "reference". */
function coreui_personalization_client_keys(): array {
  return array_values(array_diff(array_keys(coreui_personalization_defaults()), COREUI_PERSONALIZATION_REFERENCE_SLOTS));
}

function coreui_personalization_normalise(array $input): array {
  $values = coreui_personalization_defaults();
  if (is_string($input['tone'] ?? null) && isset(COREUI_PERSONALIZATION_TONE_LEGACY[$input['tone']])) {
    $input['tone'] = COREUI_PERSONALIZATION_TONE_LEGACY[$input['tone']];
  }
  $choices = [
    'tone' => COREUI_PERSONALIZATION_TONES,
    'warmth' => ['weniger','standard','mehr'],
    'enthusiasm' => ['weniger','standard','mehr'],
    'emoji' => ['keine','weniger','standard','mehr'],
    'visual_answers' => ['weniger','standard','mehr'],
    'headings_lists' => ['weniger','standard','mehr']
  ];
  foreach ($choices as $key => $allowed) {
    $candidate = $input[$key] ?? $values[$key];
    if (is_string($candidate) && in_array($candidate,$allowed,true)) $values[$key] = $candidate;
  }
  foreach (['adapt_style','quick_answers','suggested_prompts','web_search','browse_search','knowledge_search',
      'voice_history','live_voice'] as $key) {
    if (array_key_exists($key,$input) && is_bool($input[$key])) $values[$key] = $input[$key];
  }
  foreach (COREUI_PERSONALIZATION_REFERENCE_SLOTS as $key) {
    $uuid = strtolower(trim((string)($input[$key] ?? '')));
    $values[$key] = preg_match('~^[a-f0-9]{32}$~D', $uuid) === 1 ? $uuid : '';
  }
  $values['custom_instructions'] = coreui_personalization_text($input['custom_instructions'] ?? '',2400,true);
  $values['nickname'] = coreui_personalization_text($input['nickname'] ?? '',60,false);
  $values['occupation'] = coreui_personalization_text($input['occupation'] ?? '',160,false);
  $values['about'] = coreui_personalization_text($input['about'] ?? '',1500,true);
  $role = (string)($input['role'] ?? '');
  $values['role'] = isset(COREUI_PERSONALIZATION_ROLES[$role]) ? $role : '';
  return $values;
}

function coreui_personalization_schema_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo->query('SELECT user_id,preferences_json FROM stu_user_personalization LIMIT 0');
    return $ready = true;
  } catch (Throwable $error) {
    return $ready = false;
  }
}

function coreui_personalization_load(PDO $pdo,int $uid): array {
  $defaults = coreui_personalization_defaults();
  if ($uid <= 0 || !coreui_personalization_schema_ready($pdo)) return $defaults;
  try {
    $st = $pdo->prepare('SELECT preferences_json FROM stu_user_personalization WHERE user_id=? LIMIT 1');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    $decoded = is_string($raw) ? json_decode($raw,true) : null;
    return coreui_personalization_normalise(is_array($decoded) ? $decoded : $defaults);
  } catch (Throwable $error) {
    return $defaults;
  }
}

function coreui_personalization_save(PDO $pdo,int $uid,array $values): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_personalization_schema_ready($pdo)) throw new RuntimeException('missing_schema_022');
  $clean = coreui_personalization_normalise($values);
  $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  $st = $pdo->prepare(
    'INSERT INTO stu_user_personalization (user_id,preferences_json,updated_at) VALUES (?,?,NOW()) '
    . 'ON DUPLICATE KEY UPDATE preferences_json=VALUES(preferences_json),updated_at=NOW()'
  );
  $st->execute([$uid,$json]);
  return $clean;
}

/**
 * 0.7.0: the settings page saves every switch and menu on its own (no save button, like the
 * reference). Only the keys in $changes the page may set change; the rest, the reference
 * photos included, stays as stored.
 */
function coreui_personalization_update(PDO $pdo,int $uid,array $changes): array {
  $current = coreui_personalization_load($pdo,$uid);
  foreach (coreui_personalization_client_keys() as $key) {
    if (array_key_exists($key,$changes)) $current[$key] = $changes[$key];
  }
  return coreui_personalization_save($pdo,$uid,$current);
}

/** The person's uploaded image $uuid when it can be an image reference (own, png/jpeg/webp/gif, at most 8 MB). */
function coreui_personalization_reference_media(PDO $pdo,int $uid,string $uuid): ?array {
  if ($uid <= 0 || preg_match('~^[a-f0-9]{32}$~D',$uuid) !== 1) return null;
  try {
    $st = $pdo->prepare('SELECT uuid,user_id,kind,stored_name,rel_path,public_url,mime_type,file_size FROM stu_console_media '
      . 'WHERE uuid=? AND user_id=? LIMIT 1');
    $st->execute([$uuid,$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $error) {
    return null;
  }
  if (!is_array($row)) return null;
  require_once __DIR__ . '/image_gen.php';
  return coreui_image_media_reference_token($row,$uid) !== null ? $row : null;
}

/** An old reference photo is deleted when no message and no other slot uses it (like a removed upload). */
function coreui_personalization_reference_release(PDO $pdo,int $uid,string $uuid,array $keep): void {
  if ($uuid === '' || in_array($uuid,$keep,true)) return;
  $row = coreui_personalization_reference_media($pdo,$uid,$uuid);
  if ($row === null) return;
  try {
    $used = $pdo->prepare('SELECT COUNT(*) FROM stu_chat_messages WHERE file_uuid=?');
    $used->execute([$uuid]);
    $count = (int)$used->fetchColumn();
    try {
      $att = $pdo->prepare('SELECT COUNT(*) FROM stu_console_message_attachments WHERE media_uuid=?');
      $att->execute([$uuid]);
      $count += (int)$att->fetchColumn();
    } catch (Throwable $missing) { /* attachments table not installed */ }
    if ($count > 0) return;
    $del = $pdo->prepare('DELETE FROM stu_console_media WHERE uuid=? AND user_id=? LIMIT 1');
    $del->execute([$uuid,$uid]);
    if ($del->rowCount() === 1) {
      require_once __DIR__ . '/console_session_store.php';
      coreui_console_session_delete_media_files([(string)$row['rel_path']]);
    }
  } catch (Throwable $error) {
    error_log('[CoreAI] Reference photo cleanup failed: ' . get_class($error));
  }
}

/** Sets ($uuid) or removes (null) the reference photo of $slot ('portrait' or 'body'). */
function coreui_personalization_reference_set(PDO $pdo,int $uid,string $slot,?string $uuid): array {
  $key = COREUI_PERSONALIZATION_REFERENCE_SLOTS[$slot] ?? null;
  if ($key === null) throw new InvalidArgumentException('invalid_slot');
  $current = coreui_personalization_load($pdo,$uid);
  $uuid = $uuid === null ? '' : strtolower(trim($uuid));
  if ($uuid !== '' && coreui_personalization_reference_media($pdo,$uid,$uuid) === null) {
    throw new InvalidArgumentException('invalid_reference');
  }
  $old = (string)$current[$key];
  $current[$key] = $uuid;
  $saved = coreui_personalization_save($pdo,$uid,$current);
  if ($old !== $uuid) {
    coreui_personalization_reference_release($pdo,$uid,$old,
      array_values(array_filter(array_map(static fn(string $k): string => (string)$saved[$k], COREUI_PERSONALIZATION_REFERENCE_SLOTS))));
  }
  return $saved;
}

/** The reference photos for the settings page: per slot null or ['uuid', 'url']. */
function coreui_personalization_references_view(PDO $pdo,int $uid,array $prefs): array {
  $out = [];
  foreach (COREUI_PERSONALIZATION_REFERENCE_SLOTS as $slot => $key) {
    $uuid = (string)($prefs[$key] ?? '');
    $row = $uuid !== '' ? coreui_personalization_reference_media($pdo,$uid,$uuid) : null;
    $url = '';
    if ($row !== null) {
      $url = (string)($row['public_url'] ?? '');
      if ($url === '' && function_exists('stu_public_path')) $url = stu_public_path((string)$row['rel_path']);
    }
    $out[$slot] = $row !== null ? ['uuid' => $uuid, 'url' => $url] : null;
  }
  return $out;
}

/**
 * The image references for this turn from the person's reference photos: the portrait when
 * the person asked for an image of themselves, the full-body photo too when the request is
 * about clothing or accessories (or there is no portrait). Tokens like an uploaded image.
 */
function coreui_personalization_image_references(PDO $pdo,int $uid,array $prefs,string $userText): array {
  require_once __DIR__ . '/image_gen.php';
  if (!coreui_image_is_person_self_request($userText)) return [];
  $tokens = [];
  $portrait = (string)($prefs['reference_portrait'] ?? '');
  $body = (string)($prefs['reference_body'] ?? '');
  $wanted = [];
  if ($portrait !== '') $wanted[] = $portrait;
  if ($body !== '' && ($portrait === '' || coreui_image_mentions_outfit($userText))) $wanted[] = $body;
  foreach ($wanted as $uuid) {
    $row = coreui_personalization_reference_media($pdo,$uid,$uuid);
    $token = $row !== null ? coreui_image_media_reference_token($row,$uid) : null;
    if ($token !== null) $tokens[] = $token;
  }
  return array_values(array_unique($tokens));
}

/** A server-authored preference can opt out of a tool, never grant a forbidden tool. */
function coreui_personalization_tool_allowed(string $tool): bool {
  $prefs = $GLOBALS['COREUI_PERSONALIZATION_CONTEXT'] ?? null;
  if (!is_array($prefs)) return true;
  $key = $tool === 'web' ? 'web_search' : ($tool === 'browse' ? 'browse_search' : null);
  return $key === null || ($prefs[$key] ?? true) === true;
}
