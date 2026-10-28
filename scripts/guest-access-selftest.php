<?php
declare(strict_types=1);
// CoreAI 0.5.77: guest access. Offline: the admin limit is clamped, every
// turn of a guest (send, Neu generieren, Weiter, edit) is reserved before it
// is stored, the counter survives deleted chats, guests stay in the private
// console, new guests are capped per hour, and the switch shows the button.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';
require_once $root . '/api/guest_access.php';

function guest_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Guest access FAIL: {$why}\n"); exit(1); }
}

// Limit from the admin field.
foreach (['' => 5, '5' => 5, '0' => 1, '1' => 1, '7' => 7, '100' => 100, '500' => 100, 'abc' => 5, '-3' => 5, ' 12 ' => 12, '3.5' => 5]
  as $in => $out) guest_test(coreui_guest_limit_normalise((string)$in) === $out, "limit from '{$in}'");
guest_test(COREUI_GUEST_LIMIT_DEFAULT === 5 && COREUI_GUEST_LIMIT_MAX === 100, 'default 5, at most 100');
guest_test(coreui_guest_turn_allowed(5, 5) && !coreui_guest_turn_allowed(6, 5) && coreui_guest_turn_allowed(1, 1), 'the turn past the limit is refused');

$lib = (string)file_get_contents($root . '/api/guest_access.php');
guest_test(str_contains($lib, "ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1"), 'counter counts up atomically');
guest_test(str_contains($lib, "const COREUI_GUEST_COUNTER = 'coreui_guest_used';") && !str_contains($lib, 'FROM stu_chat_messages'),
  'counter per account in stu_kv, deleting a chat gives nothing back');
guest_test(str_contains($lib, "if (\$channel !== 'console') stu_json(['ok'=>false, 'error'=>'guest_console_only'], 403);"), 'guests stay in the private console');
guest_test(str_contains($lib, "'guest_limit_reached'") && str_contains($lib, 'if (!coreui_guest_is($pdo, $uid)) return null;'), 'normal accounts pass untouched');
guest_test(str_contains($lib, "is_guest = 1 AND guest_key LIKE ? AND created_at > (NOW() - INTERVAL 1 HOUR)")
  && str_contains($lib, ">= COREUI_GUEST_HOURLY_CAP) throw new RuntimeException('guest_capacity');"), 'new guests capped per hour');
guest_test(str_contains($lib, "VALUES (?, NOW(), NULL, NULL, 1, 4)") && str_contains($lib, "coreui_account_provision(\$pdo, \$uid, '', 'Gast');"),
  'guest: no e-mail, no password, normal user level, provisioned like an account');

// Every place a turn starts reserves it first.
$chat = (string)file_get_contents($root . '/api/chat.php');
$send = substr($chat, (int)strpos($chat, "if (\$action === 'send') {"));
guest_test(strpos($send, '$guestQuota = coreui_guest_reserve_turn($pdo, (int)$uid, $channel);') < strpos($send, '$startedAttachmentTx = !$pdo->inTransaction();'),
  'send reserves before the message is stored');
guest_test(str_contains($send, "if (\$guestQuota !== null) \$okPayload['guest'] = \$guestQuota;"), 'send answers with what is left');
$actions = (string)file_get_contents($root . '/api/console_actions.php');
foreach (["=== 'prepare_generation')" => 'coreui_console_generation_prepare(', "=== 'edit_message')" => 'coreui_console_edit_prepare('] as $marker => $call) {
  $part = substr($actions, (int)strpos($actions, $marker));
  guest_test(strpos($part, 'coreui_guest_reserve_turn($pdo, (int)$uid);') < strpos($part, $call), 'counted before ' . $call);
}

// Login: switch in the public answer, guest action like a login.
$auth = (string)file_get_contents($root . '/api/auth.php');
$guest = substr($auth, (int)strpos($auth, "if (\$action === 'guest') {"));
foreach (["stu_rate_limit('guest', 3, 600);", "if (!coreui_guest_enabled(\$pdo)) stu_json(['ok' => false, 'error' => 'guest_disabled'], 403);",
  'stu_enforce_maintenance($pdo, null, 4);', 'session_regenerate_id(true);', 'coreui_auth_session_issue($pdo, $uid);'] as $needle) {
  guest_test(str_contains($guest, $needle), 'guest login: ' . $needle);
}
guest_test(strpos($guest, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") < strpos($guest, 'coreui_guest_create('), 'guest accounts only by POST');
guest_test(str_contains($auth, "'guest_enabled' => \$guestOpen") && str_contains($auth, "'is_guest' => \$guest !== null"), 'login page and chat learn about guests');

// Admin: switch and limit next to the registration switch.
$admin = (string)file_get_contents($root . '/api/admin.php');
guest_test(str_contains($admin, "'registration_enabled', 'guest_enabled', 'guest_message_limit',")
  && str_contains($admin, "if (\$key === 'guest_message_limit') \$value = (string)coreui_guest_limit_normalise(\$value);")
  && str_contains($admin, "'guest_message_limit' => '5',"), 'admin saves the switch and a clamped limit');
$adminHtml = (string)file_get_contents($root . '/settings.html'); // 0.5.79: Admin Core is a tab group there
$adminJs = (string)file_get_contents($root . '/js/admin.js');
guest_test(strpos($adminHtml, 'id="registrationEnabled"') < strpos($adminHtml, 'id="guestEnabled"')
  && str_contains($adminHtml, 'id="guestMessageLimit" type="number" min="1" max="100"'), 'admin fields in Runtime');
guest_test(str_contains($adminJs, "guest_enabled: document.getElementById('guestEnabled').checked ? '1' : '0',")
  && str_contains($adminJs, "guest_message_limit: document.getElementById('guestMessageLimit').value,"), 'admin page sends both');

// Chat: what is left, and a clear note at the end.
$app = (string)file_get_contents($root . '/js/console-app.js');
guest_test(str_contains($app, "'GAST · NOCH ' + Math.max(0, Number(g.left) || 0) + ' VON ' + (Number(g.limit) || 0) + ' NACHRICHTEN'")
  && str_contains($app, "'guest_limit_reached': '\\u26a0 Deine Gast-Nachrichten sind aufgebraucht."), 'chat shows the quota and the end');
guest_test(str_contains((string)file_get_contents($root . '/app.html'), 'id="composerLinkLabel"'), 'quota label in the composer');

echo "CoreAI guest access: limit, counting, console only, hourly cap, login and admin OK\n";
