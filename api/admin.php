<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/profile_store.php';
require_once __DIR__ . '/model_access.php';
require_once __DIR__ . '/account_delete.php';
require_once __DIR__ . '/account_admin.php';

$pdo = stu_pdo();
$actorId = stu_require_user_id();
$actorLevel = stu_require_permission_at_most(1, $pdo);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'overview')));

function coreui_admin_count(PDO $pdo, string $sql, array $params = []): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function coreui_admin_audit(PDO $pdo, int $actorId, string $action, string $type = '', string $targetId = '', array $detail = []): void {
  try {
    $st = $pdo->prepare(
      'INSERT INTO stu_admin_audit (actor_user_id, action_name, target_type, target_id, detail_json) VALUES (?, ?, ?, ?, ?)'
    );
    $json = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $st->execute([$actorId, $action, $type, $targetId, $json]);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_audit_failed', 'action' => $action, 'message' => $e->getMessage()]);
    }
  }
}

function coreui_admin_log_path(): string {
  return dirname(__DIR__) . '/logs/stu_error.log';
}

function coreui_admin_tail(string $file, int $limit): array {
  if (!is_file($file) || !is_readable($file)) return [];
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines)) return [];
  return array_slice($lines, -max(1, min(2000, $limit)));
}

function coreui_admin_setting_rows(PDO $pdo): array {
  $defaults = [
    'registration_enabled' => '0',
    'guest_enabled' => '0',
    'guest_message_limit' => '5',
    'guest_network_guests' => '1',
    'guest_daily_messages' => '200',
    'captcha_enabled' => '1',
    'maintenance_enabled' => '0',
    'maintenance_message' => '',
    'memory_default_enabled' => '1',
    'user_system_prompt_max_chars' => '6000',
    'tool_web_enabled' => defined('STU_EMBER_WEB_ENABLED') && STU_EMBER_WEB_ENABLED ? '1' : '0',
    'tool_browse_enabled' => defined('STU_EMBER_BROWSE_ENABLED') && STU_EMBER_BROWSE_ENABLED ? '1' : '0',
    'tool_python_enabled' => defined('STU_EMBER_PY_ENABLED') && STU_EMBER_PY_ENABLED ? '1' : '0',
    'external_api_enabled' => '0',
    'external_api_label' => 'OpenAI-kompatibel',
    'external_api_base_url' => '',
    'external_api_model' => '',
  ];
  $out = [];
  foreach ($defaults as $key => $default) {
    $out[$key] = (string)stu_app_setting_get($pdo, $key, $default);
  }
  return $out;
}

function coreui_admin_get_overview(PDO $pdo, int $actorLevel): void {
  $stats = [
    'users' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_users WHERE is_guest = 0'),
    'sessions' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_console_sessions'),
    'messages' => coreui_admin_count($pdo, "SELECT COUNT(*) FROM stu_chat_messages WHERE channel = 'console' AND deleted_at IS NULL"),
    'memories' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_memories'),
    'lore_chunks' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_knowledge_chunks'),
    'user_knowledge_chunks' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_user_knowledge_chunks'),
    'browse_active' => coreui_admin_count($pdo, "SELECT COUNT(*) FROM stu_ember_browse_jobs WHERE status IN ('queued','running')"),
  ];
  $logPath = coreui_admin_log_path();
  $stats['log_bytes'] = is_file($logPath) ? (int)filesize($logPath) : 0;
  stu_json([
    'ok' => true,
    'stats' => $stats,
    'settings' => coreui_admin_setting_rows($pdo),
    'external' => coreui_ai_external_capability($pdo),
    // 0.5.78: whether visitors can be told apart (guest network budget, rate limits).
    'client_address' => ['source' => coreui_client_address_current()['source']],
    'permission_level' => $actorLevel,
    'csrf_token' => stu_csrf_token(),
  ]);
}

if ($method === 'GET') {
  if ($action === 'overview') coreui_admin_get_overview($pdo, $actorLevel);

  if ($action === 'users') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(250, (int)($_GET['limit'] ?? 100)));
    $where = '';
    $params = [];
    if ($q !== '') {
      $where = 'WHERE u.username LIKE ? OR c.name LIKE ? OR p.display_name LIKE ?';
      $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    $st = $pdo->prepare(
      'SELECT u.id, u.username, u.permission_level, u.is_guest, u.banned_until, u.banned_reason, u.created_at, '
      . 'u.chat_seconds_lifetime, p.display_name, p.assistant_name, MAX(c.name) AS character_name, COUNT(DISTINCT s.id) AS session_count, '
      // 0.5.85: active logins for "Alle Geraete abmelden".
      . '(SELECT COUNT(*) FROM stu_auth_sessions a WHERE a.user_id = u.id AND a.revoked_at IS NULL AND a.expires_at > NOW()) AS login_count '
      . 'FROM stu_users u LEFT JOIN stu_characters c ON c.user_id = u.id '
      . 'LEFT JOIN stu_coreui_profiles p ON p.user_id = u.id '
      . 'LEFT JOIN stu_console_sessions s ON s.user_id = u.id ' . $where
      . ' GROUP BY u.id, u.username, u.permission_level, u.is_guest, u.banned_until, u.banned_reason, u.created_at, u.chat_seconds_lifetime, p.display_name, p.assistant_name '
      . 'ORDER BY u.id DESC LIMIT ' . $limit
    );
    $st->execute($params);
    // 0.5.85: own rules per account (tools, limits) and the global tool switches.
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'policies' => coreui_user_policy_all($pdo),
      'tools' => coreui_operator_runtime_load($pdo), 'policy_ready' => coreui_user_policy_ready($pdo)]);
  }

  // 0.5.83: models outside CoreAI and who may use them. Only the Inhaber
  // releases them; installed ones come from Ollama, released ones that are
  // gone from Ollama stay listed so they can be withdrawn.
  if ($action === 'model_access') {
    if ($actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);
    $external = [];
    $ollama = true;
    try {
      foreach (coreui_ollama_models() as $m) {
        $name = (string)($m['name'] ?? '');
        if ($name !== '' && !coreui_model_is_coreai($name)) $external[] = $name;
      }
    } catch (RuntimeException $e) {
      $ollama = false;
    }
    $grants = coreui_model_access_all($pdo);
    foreach ($grants as $names) {
      foreach ($names as $name) {
        if (!in_array($name, $external, true)) $external[] = $name;
      }
    }
    sort($external, SORT_NATURAL | SORT_FLAG_CASE);
    stu_json(['ok' => true, 'models' => $external, 'grants' => (object)$grants, 'ollama' => $ollama,
      'ready' => coreui_model_access_ready($pdo)]);
  }

  if ($action === 'memories') {
    $q = trim((string)($_GET['q'] ?? ''));
    $scope = strtolower(trim((string)($_GET['scope'] ?? '')));
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 150)));
    $where = [];
    $params = [];
    if ($q !== '') { $where[] = 'm.fact LIKE ?'; $params[] = '%' . $q . '%'; }
    if (in_array($scope, ['global', 'user', 'character'], true)) { $where[] = 'm.scope = ?'; $params[] = $scope; }
    $sql = 'SELECT m.id, m.fact, m.relevance, m.scope, m.user_id, m.character_id, m.created_at, m.updated_at, '
      . 'u.username, c.name AS character_name FROM ember_memories m '
      . 'LEFT JOIN stu_users u ON u.id = m.user_id LEFT JOIN stu_characters c ON c.id = m.character_id '
      . ($where ? ('WHERE ' . implode(' AND ', $where) . ' ') : '')
      . 'ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }

  if ($action === 'lore') {
    $q = trim((string)($_GET['q'] ?? ''));
    $source = trim((string)($_GET['source'] ?? ''));
    $sources = $pdo->query(
      'SELECT source, COUNT(*) AS chunks, MAX(created_at) AS latest FROM ember_knowledge_chunks GROUP BY source ORDER BY source'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    if ($q !== '' || $source !== '') {
      $where = [];
      $params = [];
      if ($q !== '') { $where[] = '(title LIKE ? OR chunk_text LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
      if ($source !== '') { $where[] = 'source = ?'; $params[] = $source; }
      $st = $pdo->prepare(
        'SELECT id, source, title, chunk_no, chunk_text, created_at FROM ember_knowledge_chunks '
        . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY source, chunk_no LIMIT 100'
      );
      $st->execute($params);
      $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    stu_json(['ok' => true, 'sources' => $sources, 'items' => $items]);
  }

  if ($action === 'logs') {
    $limit = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
    stu_json(['ok' => true, 'lines' => coreui_admin_tail(coreui_admin_log_path(), $limit)]);
  }

  if ($action === 'audit') {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $st = $pdo->query(
      'SELECT a.id, a.actor_user_id, u.username AS actor, a.action_name, a.target_type, a.target_id, a.detail_json, a.created_at '
      . 'FROM stu_admin_audit a LEFT JOIN stu_users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT ' . $limit
    );
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }

  stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();

if ($action === 'user_create') {
  $email = coreui_account_clean_email((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  $displayName = coreui_account_clean_display_name(
    (string)($body['display_name'] ?? ''),
    coreui_account_default_display_name($email)
  );
  // 0.5.79: new accounts are Admin or Nutzer; Inhaber is never created here.
  $newLevel = (int)($body['permission_level'] ?? COREUI_ROLE_USER) === COREUI_ROLE_ADMIN ? COREUI_ROLE_ADMIN : COREUI_ROLE_USER;
  if (!coreui_account_valid_email($email)) stu_json(['ok' => false, 'error' => 'invalid_username'], 400);
  if (strlen($password) < 12 || strlen($password) > 1024) stu_json(['ok' => false, 'error' => 'invalid_password'], 400);
  if ($actorLevel > 0 && $newLevel <= $actorLevel) stu_json(['ok' => false, 'error' => 'protected_account'], 403);
  try {
    $created = coreui_account_create($pdo, $email, $password, $displayName, $newLevel);
  } catch (RuntimeException $e) {
    if ($e->getMessage() === 'username_taken') stu_json(['ok' => false, 'error' => 'username_taken'], 409);
    if ($e->getMessage() === 'missing_schema_004') stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_user_create_failed', 'actor' => $actorId, 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => 'user_create_failed'], 500);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_user_create_failed', 'actor' => $actorId, 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => 'user_create_failed'], 500);
  }
  coreui_admin_audit($pdo, $actorId, 'user_create', 'user', (string)$created['user_id'], [
    'permission_level' => $newLevel,
    'display_name' => $displayName,
  ]);
  stu_json(['ok' => true, 'user' => [
    'id' => (int)$created['user_id'],
    'username' => $email,
    'display_name' => $displayName,
    'permission_level' => $newLevel,
    'character_id' => (string)$created['character_id'],
  ]]);
}

if ($action === 'user_update') {
  $targetId = (int)($body['user_id'] ?? 0);
  $requestedLevel = (int)($body['permission_level'] ?? COREUI_ROLE_USER);
  if (!in_array($requestedLevel, COREUI_ROLE_LEVELS, true)) stu_json(['ok' => false, 'error' => 'invalid_role'], 400);
  $newLevel = $requestedLevel;
  $reason = trim((string)($body['banned_reason'] ?? ''));
  if (function_exists('mb_substr')) $reason = mb_substr($reason, 0, 255, 'UTF-8');
  else $reason = substr($reason, 0, 255);
  $untilRaw = trim((string)($body['banned_until'] ?? ''));
  $until = null;
  if ($untilRaw !== '') {
    $ts = strtotime($untilRaw);
    if ($ts === false) stu_json(['ok' => false, 'error' => 'invalid_ban_time'], 400);
    $until = date('Y-m-d H:i:s', $ts);
  }
  if ($targetId <= 0) stu_json(['ok' => false, 'error' => 'invalid_user'], 400);

  $st = $pdo->prepare('SELECT id, username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$targetId]);
  $target = $st->fetch(PDO::FETCH_ASSOC);
  if (!$target) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  // A guest stays a guest (Nutzer level); an admin can only ban or unban it.
  if ((int)($target['is_guest'] ?? 0) === 1 && $newLevel !== COREUI_ROLE_USER) {
    stu_json(['ok' => false, 'error' => 'guest_role_fixed'], 400);
  }
  $oldLevel = (int)$target['permission_level'];
  if ($actorLevel > 0 && ($oldLevel <= $actorLevel || $newLevel <= $actorLevel)) {
    stu_json(['ok' => false, 'error' => 'protected_account'], 403);
  }
  if ($targetId === $actorId && ($newLevel !== $oldLevel || $until !== null)) {
    stu_json(['ok' => false, 'error' => 'self_lockout_blocked'], 409);
  }

  $up = $pdo->prepare('UPDATE stu_users SET permission_level = ?, banned_until = ?, banned_reason = ? WHERE id = ?');
  $up->execute([$newLevel, $until, $until ? $reason : '', $targetId]);
  coreui_admin_audit($pdo, $actorId, 'user_update', 'user', (string)$targetId, [
    'old_level' => $oldLevel,
    'new_level' => $newLevel,
    'banned_until' => $until,
  ]);
  stu_json(['ok' => true]);
}

// 0.5.84: delete an account with everything it owns. The Inhaber deletes
// Admins, Nutzer and Gaeste, an Admin only Nutzer and Gaeste; nobody deletes
// himself or an Inhaber. The account's e-mail must be typed to confirm.
if ($action === 'user_delete') {
  $targetId = (int)($body['user_id'] ?? 0);
  $st = $pdo->prepare('SELECT id, username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$targetId]);
  $target = $st->fetch(PDO::FETCH_ASSOC);
  if (!$target) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  $refusal = coreui_account_delete_refusal($actorId, $actorLevel, $target);
  if ($refusal !== '') stu_json(['ok' => false, 'error' => $refusal], $refusal === 'invalid_user' ? 400 : 403);
  if (!coreui_account_delete_confirmed($target, (string)($body['confirm'] ?? ''))) {
    stu_json(['ok' => false, 'error' => 'delete_confirmation_required'], 400);
  }
  try {
    $deleted = coreui_account_delete($pdo, $targetId);
  } catch (RuntimeException $e) {
    $code = $e->getMessage();
    if ($code === 'account_busy') stu_json(['ok' => false, 'error' => 'account_busy'], 409);
    if ($code === 'user_not_found') stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
    if ($code === 'protected_account') stu_json(['ok' => false, 'error' => 'protected_account'], 403);
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'admin_user_delete_failed', 'actor' => $actorId, 'message' => $code]);
    stu_json(['ok' => false, 'error' => 'user_delete_failed'], 500);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'admin_user_delete_failed', 'actor' => $actorId, 'message' => $e->getMessage()]);
    stu_json(['ok' => false, 'error' => 'user_delete_failed'], 500);
  }
  coreui_admin_audit($pdo, $actorId, 'user_delete', 'user', (string)$targetId, [
    'username' => (string)$target['username'],
    'role' => coreui_role_name((int)$target['permission_level'], (int)($target['is_guest'] ?? 0) === 1),
    'sessions' => $deleted['sessions_deleted'],
    'messages' => $deleted['messages_deleted'],
    'knowledge' => $deleted['knowledge_sources_deleted'],
    'memories' => $deleted['memories_deleted'],
    'files' => $deleted['files_deleted'],
  ]);
  stu_json(['ok' => true, 'deleted' => $deleted]);
}

// 0.5.85: settings per account (BEARBEITEN). Same rules as deleting; on the
// own account only name and e-mail (password and devices stay in Konto).
if (in_array($action, ['user_password', 'user_logout_all', 'user_identity'], true)) {
  $targetId = (int)($body['user_id'] ?? 0);
  $target = $targetId > 0 ? coreui_account_admin_target($pdo, $targetId) : null;
  if ($target === null) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  $refusal = coreui_account_manage_refusal($actorId, $actorLevel, $target, $action === 'user_identity');
  if ($refusal !== '') stu_json(['ok' => false, 'error' => $refusal], $refusal === 'invalid_user' ? 400 : 403);
  try {
    if ($action === 'user_password') {
      $ended = coreui_account_set_password($pdo, $targetId, (string)($body['password'] ?? ''));
      coreui_admin_audit($pdo, $actorId, 'user_password_set', 'user', (string)$targetId, ['logins_ended' => $ended]);
      stu_json(['ok' => true, 'logins_ended' => $ended]);
    }
    if ($action === 'user_logout_all') {
      $ended = coreui_account_logout_all($pdo, $targetId);
      coreui_admin_audit($pdo, $actorId, 'user_logout_all', 'user', (string)$targetId, ['logins_ended' => $ended]);
      stu_json(['ok' => true, 'logins_ended' => $ended]);
    }
    $identity = coreui_account_set_identity($pdo, $targetId, (string)($body['display_name'] ?? ''), (string)($body['username'] ?? ''));
    coreui_admin_audit($pdo, $actorId, 'user_identity', 'user', (string)$targetId, [
      'old_username' => (string)$target['username'], 'username' => $identity['username'], 'display_name' => $identity['display_name'],
    ]);
    stu_json(['ok' => true, 'user' => $identity]);
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
  } catch (RuntimeException $e) {
    $code = $e->getMessage();
    if ($code === 'username_taken') stu_json(['ok' => false, 'error' => 'username_taken'], 409);
    if ($code === 'guest_account') stu_json(['ok' => false, 'error' => 'guest_account'], 400);
    if ($code === 'user_not_found') stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'admin_' . $action . '_failed', 'actor' => $actorId, 'message' => $code]);
    stu_json(['ok' => false, 'error' => 'user_settings_failed'], 500);
  }
}

// 0.5.85: own rules of an account: tools now, limits next. Not for the own
// account (the global switches under Betrieb apply there).
if ($action === 'user_policy') {
  $targetId = (int)($body['user_id'] ?? 0);
  $target = $targetId > 0 ? coreui_account_admin_target($pdo, $targetId) : null;
  if ($target === null) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  $refusal = coreui_account_manage_refusal($actorId, $actorLevel, $target, false);
  if ($refusal === 'use_account_settings') $refusal = 'own_policy';
  if ($refusal !== '') stu_json(['ok' => false, 'error' => $refusal], $refusal === 'invalid_user' ? 400 : 403);
  $patch = [];
  foreach (['tools', 'daily_messages', 'max_num_ctx'] as $key) {
    if (array_key_exists($key, $body)) $patch[$key] = $body[$key];
  }
  try {
    $policy = coreui_user_policy_save($pdo, $targetId, $patch, $actorId, coreui_ai_num_ctx_choices());
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
  } catch (RuntimeException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage() === 'missing_schema_021' ? 'missing_schema_021' : 'user_settings_failed'], 503);
  }
  coreui_admin_audit($pdo, $actorId, 'user_policy', 'user', (string)$targetId, $policy);
  stu_json(['ok' => true, 'policy' => $policy]);
}

if ($action === 'model_access_save') {
  if ($actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);
  $targetId = (int)($body['user_id'] ?? 0);
  $models = $body['models'] ?? null;
  if ($targetId <= 0 || !is_array($models)) stu_json(['ok' => false, 'error' => 'invalid_request'], 400);
  $target = coreui_model_access_account($pdo, $targetId);
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_users WHERE id = ?');
  $st->execute([$targetId]);
  if ((int)$st->fetchColumn() !== 1) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  // Guests stay on the default Core; the Inhaber has every model anyway.
  if ($target['guest']) stu_json(['ok' => false, 'error' => 'guest_core_locked'], 400);
  if ($target['level'] === 0) stu_json(['ok' => false, 'error' => 'owner_has_all'], 400);
  try {
    $saved = coreui_model_access_save($pdo, $targetId, $models, $actorId);
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
  } catch (RuntimeException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
  }
  coreui_admin_audit($pdo, $actorId, 'model_access_save', 'user', (string)$targetId, ['models' => $saved]);
  stu_json(['ok' => true, 'models' => $saved]);
}

if ($action === 'memory_upsert') {
  $id = max(0, (int)($body['id'] ?? 0));
  $fact = trim((string)($body['fact'] ?? ''));
  $scope = strtolower(trim((string)($body['scope'] ?? 'global')));
  $relevance = max(1, min(10, (int)($body['relevance'] ?? 5)));
  $userId = (int)($body['user_id'] ?? 0);
  $characterId = trim((string)($body['character_id'] ?? ''));
  $length = function_exists('mb_strlen') ? mb_strlen($fact, 'UTF-8') : strlen($fact);
  if ($length < 3 || $length > 2000) stu_json(['ok' => false, 'error' => 'invalid_fact'], 400);
  if (!in_array($scope, ['global', 'user', 'character'], true)) stu_json(['ok' => false, 'error' => 'invalid_scope'], 400);
  if ($scope === 'user' && $userId <= 0) stu_json(['ok' => false, 'error' => 'user_required'], 400);
  if ($scope === 'character' && $characterId === '') stu_json(['ok' => false, 'error' => 'character_required'], 400);
  $normalized = function_exists('mb_strtolower') ? mb_strtolower($fact, 'UTF-8') : strtolower($fact);
  $hash = md5($normalized);
  $memoryUserId = $scope === 'user' ? $userId : null;
  $memoryCharacterId = $scope === 'character' ? $characterId : null;
  if ($id > 0) {
    $st = $pdo->prepare(
      'UPDATE ember_memories SET fact = ?, relevance = ?, scope = ?, user_id = ?, character_id = ?, fact_hash = ?, updated_at = NOW() WHERE id = ?'
    );
    $st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash, $id]);
    if ($st->rowCount() === 0 && coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_memories WHERE id = ?', [$id]) === 0) {
      stu_json(['ok' => false, 'error' => 'memory_not_found'], 404);
    }
  } else {
    $st = $pdo->prepare(
      'INSERT INTO ember_memories (fact, relevance, scope, user_id, character_id, fact_hash, last_used_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash]);
    $id = (int)$pdo->lastInsertId();
  }
  coreui_admin_audit($pdo, $actorId, 'memory_upsert', 'memory', (string)$id, ['scope' => $scope]);
  stu_json(['ok' => true, 'id' => $id]);
}

if ($action === 'memory_delete') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) stu_json(['ok' => false, 'error' => 'invalid_id'], 400);
  $st = $pdo->prepare('DELETE FROM ember_memories WHERE id = ?');
  $st->execute([$id]);
  coreui_admin_audit($pdo, $actorId, 'memory_delete', 'memory', (string)$id);
  stu_json(['ok' => true, 'deleted' => $st->rowCount() > 0]);
}

if ($action === 'settings_save') {
  $whitelist = [
    'registration_enabled', 'guest_enabled', 'guest_message_limit', 'guest_network_guests', 'guest_daily_messages',
    'captcha_enabled', 'maintenance_enabled', 'maintenance_message',
    'memory_default_enabled', 'user_system_prompt_max_chars',
    'tool_web_enabled', 'tool_browse_enabled', 'tool_python_enabled',
    'external_api_enabled', 'external_api_label', 'external_api_base_url', 'external_api_model',
  ];
  $incoming = $body['settings'] ?? [];
  if (!is_array($incoming)) stu_json(['ok' => false, 'error' => 'invalid_settings'], 400);
  $saved = [];
  foreach ($whitelist as $key) {
    if (!array_key_exists($key, $incoming)) continue;
    $value = trim((string)$incoming[$key]);
    if (str_ends_with($key, '_enabled')) $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    if ($key === 'user_system_prompt_max_chars') $value = (string)max(500, min(12000, (int)$value));
    if ($key === 'guest_message_limit') $value = (string)coreui_guest_limit_normalise($value);
    if ($key === 'guest_network_guests') $value = (string)coreui_guest_network_normalise($value);
    if ($key === 'guest_daily_messages') $value = (string)coreui_guest_daily_normalise($value);
    if ($key === 'maintenance_message') {
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 520, 'UTF-8');
      else $value = substr($value, 0, 520);
    }
    if ($key === 'external_api_base_url' && $value !== '') {
      $valid = filter_var($value, FILTER_VALIDATE_URL);
      if (!$valid || strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https') {
        stu_json(['ok' => false, 'error' => 'external_url_requires_https'], 400);
      }
      $value = rtrim($value, '/');
    }
    if (in_array($key, ['external_api_label', 'external_api_model'], true)) {
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 160, 'UTF-8');
      else $value = substr($value, 0, 160);
    }
    stu_app_setting_set($pdo, $key, $value);
    $saved[$key] = $value;
  }
  coreui_admin_audit($pdo, $actorId, 'settings_save', 'app_settings', '', ['keys' => array_keys($saved)]);
  stu_json(['ok' => true, 'settings' => coreui_admin_setting_rows($pdo)]);
}

if ($action === 'logs_clear') {
  $file = coreui_admin_log_path();
  $removed = count(coreui_admin_tail($file, 2000));
  if (is_file($file) && file_put_contents($file, '', LOCK_EX) === false) {
    stu_json(['ok' => false, 'error' => 'log_clear_failed'], 500);
  }
  coreui_admin_audit($pdo, $actorId, 'logs_clear', 'log', 'stu_error.log', ['visible_lines' => $removed]);
  stu_json(['ok' => true, 'removed' => $removed]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
