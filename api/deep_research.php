<?php
declare(strict_types=1);
// CoreAI 0.7.38: Deep Research runs of the signed-in account (api/deep_research_store.php).
//   GET  ?action=status&id=N[&details=1]  plan, state, counts; with details sources, activity, report
//   GET  ?action=download&id=N            the report as a Markdown file (with the numbered sources)
//   POST {action: start|edit|cancel, id}  with X-CoreUI-CSRF
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deep_research_store.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
if (!coreui_dr_ready($pdo)) stu_json(['ok' => false, 'error' => 'deep_research_migration_required'], 503);

if ($method === 'GET') {
  $action = (string)($_GET['action'] ?? 'status');
  $id = (int)($_GET['id'] ?? 0);
  $row = $id > 0 ? coreui_dr_row($pdo, $uid, $id) : null;
  if ($row === null) stu_json(['ok' => false, 'error' => 'not_found'], 404);
  if ($action === 'download') {
    $public = coreui_dr_public($pdo, $row, true);
    if (!$public['has_report']) stu_json(['ok' => false, 'error' => 'no_report'], 409);
    $name = coreui_dr_file_name((string)$row['title']);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo coreui_dr_download_markdown((string)$row['title'], (string)$public['report'], $public['sources']);
    exit;
  }
  if ($action !== 'status') stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
  stu_json(['ok' => true, 'run' => coreui_dr_public($pdo, $row, !empty($_GET['details'])), 'csrf_token' => stu_csrf_token()]);
}

if ($method !== 'POST') {
  header('Allow: GET, POST');
  stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
stu_require_csrf();
$body = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$action = (string)($body['action'] ?? '');
$id = (int)($body['id'] ?? 0);
if ($id <= 0 || coreui_dr_row($pdo, $uid, $id) === null) stu_json(['ok' => false, 'error' => 'not_found'], 404);
$ok = match ($action) {
  'start' => coreui_dr_start($pdo, $uid, $id),
  'edit' => coreui_dr_edit($pdo, $uid, $id),
  'cancel' => coreui_dr_cancel($pdo, $uid, $id) !== 'missing',
  default => null,
};
if ($ok === null) stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
$row = coreui_dr_row($pdo, $uid, $id);
stu_json(['ok' => $ok, 'error' => $ok ? null : 'wrong_state', 'run' => coreui_dr_public($pdo, $row)], $ok ? 200 : 409);
