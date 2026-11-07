<?php
declare(strict_types=1);
// CoreAI 0.6.43: which entries of the + menu in the input field this account may use.
//   GET -> {ok, guest, tools: {image, web, browse}: {on, why}, planned: [...]}
// Same rules as Mara's work loop: guests, the switches under Betrieb and the account's own
// rules (Benutzer). Deep Research and connectors are planned (roadmap) and only announced.

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (function_exists('coreui_ai_runtime_apply')) coreui_ai_runtime_apply($pdo, $uid);
$guest = function_exists('coreui_ai_account_is_guest') && coreui_ai_account_is_guest($pdo, $uid);

stu_json(coreui_composer_tools_answer($guest, ember_image_allowed($uid), ember_web_enabled(), ember_browse_enabled()));
