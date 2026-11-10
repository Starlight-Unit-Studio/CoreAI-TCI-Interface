<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_store.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/account_page_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/** 0.6.86: the account row of the person asking (name, e-mail, role, guest). */
function coreui_profile_account_row(PDO $pdo, int $uid): array {
  $st = $pdo->prepare('SELECT username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** 0.6.86: a few changes per session window, like the security page. */
function coreui_profile_rate_limit(string $bucket, int $max, int $window): void {
  stu_start_session();
  $key = 'coreui_profile_rl_' . preg_replace('~[^a-z0-9_-]~i', '_', $bucket);
  $now = time();
  $row = $_SESSION[$key] ?? ['time' => $now, 'count' => 0];
  if (!is_array($row) || ($now - (int)($row['time'] ?? 0)) > $window) $row = ['time' => $now, 'count' => 0];
  $row['count'] = (int)($row['count'] ?? 0) + 1;
  $_SESSION[$key] = $row;
  if ($row['count'] > $max) stu_json(['ok' => false, 'error' => 'rate_limited'], 429);
}

/** 0.6.86: Settings > Konto > Allgemein (name, Benutzername, e-mail, plan). */
function coreui_profile_account_view(PDO $pdo, int $uid): array {
  $row = coreui_profile_account_row($pdo, $uid);
  $guest = (int)($row['is_guest'] ?? 0) === 1;
  $level = (int)($row['permission_level'] ?? 4);
  $email = $guest ? '' : (string)($row['username'] ?? '');
  $profile = coreui_profile_schema_ready($pdo) ? coreui_profile_load($pdo, $uid) : [];
  $handle = $guest ? null : coreui_username_get($pdo, $uid);
  $ready = coreui_username_ready($pdo);
  return [
    'display_name' => (string)($profile['display_name'] ?? ''),
    'email' => $email,
    'guest' => $guest,
    'username' => $handle,
    'username_ready' => $ready,
    'username_suggestion' => (!$guest && $ready && $handle === null) ? coreui_username_suggest($pdo, $uid, $email) : '',
    'plan' => coreui_account_plan_summary($pdo, $uid, $level, $guest, coreui_operator_runtime_load($pdo), coreui_ai_num_ctx_choices()),
    'plan_ready' => coreui_plan_ready($pdo),
  ];
}

if ($method === 'GET') {
  if (!coreui_profile_schema_ready($pdo)) stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
  if ((string)($_GET['view'] ?? '') === 'account') {
    stu_json(['ok' => true, 'account' => coreui_profile_account_view($pdo, $uid), 'csrf_token' => stu_csrf_token()]);
  }
  stu_json([
    'ok' => true,
    'profile' => coreui_profile_load($pdo, $uid),
    'csrf_token' => stu_csrf_token(),
    'limits' => ['name_chars' => 64, 'avatar_mb' => 4, 'avatar_px' => 512, 'assistant_reference_px' => 1024, 'crop' => true],
  ]);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
try {
  if (str_contains($contentType, 'multipart/form-data')) {
    if (empty($_FILES['file'])) stu_json(['ok' => false, 'error' => 'no_file_uploaded'], 400);
    $uploadError = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) stu_json(['ok' => false, 'error' => 'upload_failed', 'code' => $uploadError], 400);
    $slot = strtolower(trim((string)($_POST['slot'] ?? '')));
    $profile = coreui_profile_store_avatar($pdo, $uid, $slot, $_FILES['file'], $_POST['crop'] ?? null);
    stu_json(['ok' => true, 'profile' => $profile]);
  }

  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? 'save')));
  if ($action === 'save') {
    $profile = coreui_profile_save(
      $pdo,
      $uid,
      (string)($body['display_name'] ?? ''),
      (string)($body['assistant_name'] ?? '')
    );
    stu_json(['ok' => true, 'profile' => $profile]);
  }
  // 0.6.86: Settings > Konto: only the own name (the CoreAI's name stays as it is).
  if ($action === 'display_name') {
    $name = trim((string)($body['display_name'] ?? ''));
    if ($name === '' || coreui_profile_name($name, '') === '') stu_json(['ok' => false, 'error' => 'invalid_display_name'], 400);
    $current = coreui_profile_load($pdo, $uid);
    $profile = coreui_profile_save($pdo, $uid, $name, (string)($current['assistant_name'] ?? 'Mara'));
    stu_json(['ok' => true, 'profile' => $profile, 'account' => coreui_profile_account_view($pdo, $uid)]);
  }
  // 0.6.86: Settings > Konto > Benutzername (@handle, unique on this server, no guests).
  if ($action === 'username') {
    $row = coreui_profile_account_row($pdo, $uid);
    if ((int)($row['is_guest'] ?? 1) === 1) stu_json(['ok' => false, 'error' => 'guest_not_allowed'], 403);
    coreui_profile_rate_limit('username', 10, 3600);
    coreui_username_set($pdo, $uid, (string)($body['username'] ?? ''));
    stu_json(['ok' => true, 'account' => coreui_profile_account_view($pdo, $uid)]);
  }
  if ($action === 'avatar_crop') {
    $slot = strtolower(trim((string)($body['slot'] ?? '')));
    $crop = $body['crop'] ?? null;
    $uuid = strtolower(trim((string)($body['source_uuid'] ?? '')));
    $profile = coreui_profile_store_avatar($pdo, $uid, $slot, null, $crop, $uuid);
    stu_json(['ok' => true, 'profile' => $profile]);
  }
  if ($action === 'avatar_delete') {
    $slot = strtolower(trim((string)($body['slot'] ?? '')));
    $deleted = coreui_profile_delete_avatar($pdo, $uid, $slot);
    stu_json(['ok' => true, 'deleted' => $deleted, 'profile' => coreui_profile_load($pdo, $uid)]);
  }
  stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
} catch (InvalidArgumentException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = in_array($e->getMessage(), ['missing_schema_004', 'missing_schema_031'], true) ? 503 : 500;
  stu_json(['ok' => false, 'error' => $e->getMessage()], $code);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type' => 'coreui_profile_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
  }
  stu_json(['ok' => false, 'error' => 'profile_failed'], 500);
}
