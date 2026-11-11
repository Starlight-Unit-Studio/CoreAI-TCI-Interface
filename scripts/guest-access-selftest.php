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
guest_test(COREUI_GUEST_IMAGES_DAILY_DEFAULT === 3 && COREUI_GUEST_IMAGES_DAILY_MAX === 100,
  'guests may generate three images per server day by default');
foreach (['3'=>3, '0'=>0, '1'=>1, '100'=>100, '500'=>100,
         'bad'=>3, '-2'=>3, ''=>3] as $input=>$expected) {
  guest_test(coreui_guest_image_limit_normalise((string)$input) === $expected,
    'guest image daily limit normalises: ' . $input);
}
$today = strtotime('2026-11-11 23:58:00');
guest_test(coreui_guest_image_day($today) === date('Y-m-d', $today)
  && date('H:i:s', coreui_guest_image_next_day($today)) === '00:00:00'
  && coreui_guest_image_day(coreui_guest_image_next_day($today)) !== coreui_guest_image_day($today),
  'guest image limits reset at the server calendar-day boundary, not client time');
guest_test(COREUI_GUEST_TOOL_NAMES === ['web', 'browse', 'python', 'image'],
  'all existing CoreAI model tools can be controlled separately for guests');

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
$adminHtml = (string)file_get_contents($root . '/admin/index.html'); // 0.6.71: standalone, role-gated Admin Core
$adminJs = (string)file_get_contents($root . '/js/admin.js');
guest_test(strpos($adminHtml, 'id="registrationEnabled"') < strpos($adminHtml, 'id="guestEnabled"')
  && str_contains($adminHtml, 'id="guestMessageLimit" type="number" min="1" max="100"')
  && str_contains($adminHtml, 'id="adminShell" hidden')
  && !str_contains((string)file_get_contents($root . '/settings.html'), 'id="guestEnabled"'),
  'guest controls exist only in the protected standalone Admin Core');
guest_test(str_contains($adminJs, "guest_enabled: document.getElementById('guestEnabled').checked ? '1' : '0',")
  && str_contains($adminJs, "guest_message_limit: document.getElementById('guestMessageLimit').value,"), 'admin page sends both');

// Existing guest access, message and network protections remain active. The
// additional guest tools and image limits are real backend settings, not UI-only.
guest_test(str_contains($admin, "'guest_tool_web_enabled', 'guest_tool_browse_enabled', 'guest_tool_python_enabled', 'guest_tool_image_enabled', 'guest_image_daily_limit'")
  && str_contains($admin, "if (\$key === 'guest_image_daily_limit') \$value = (string)coreui_guest_image_limit_normalise(\$value);")
  && str_contains($admin, "'guest_image_daily_limit' => (string)COREUI_GUEST_IMAGES_DAILY_DEFAULT,"),
  'admin settings whitelist, validation and default guest image limit are persisted');
foreach (['guestToolWebEnabled', 'guestToolBrowseEnabled', 'guestToolPythonEnabled',
          'guestToolImageEnabled', 'guestImageDailyLimit'] as $id) {
  guest_test(str_contains($adminHtml, 'id="' . $id . '"')
    && str_contains($adminJs, "document.getElementById('" . $id . "')"),
    'protected Admin Core includes guest feature option ' . $id);
}
guest_test(str_contains($adminHtml, 'id="guestImageDailyLimit" type="number" min="0" max="100"')
  && str_contains($adminJs, "guest_image_daily_limit: document.getElementById('guestImageDailyLimit').value,"),
  'daily guest image quota is an editable numeric setting');
$runtime = (string)file_get_contents($root . '/api/ai_settings.php');
guest_test(str_contains($runtime, 'foreach (COREUI_GUEST_TOOL_NAMES as $tool)')
  && str_contains($runtime, "!coreui_guest_tool_enabled(\$pdo, \$tool)"),
  'guest tool restrictions are enforced by the runtime, not just visual toggles');
$imageSource = (string)file_get_contents($root . '/api/image_gen.php');
guest_test(!str_contains($imageSource, "coreui_ai_account_is_guest(\$pdo, \$uid)) return false;")
  && str_contains($imageSource, 'coreui_guest_image_quota($pdo, $uid)')
  && str_contains($imageSource, "return is_array(\$quota) && !empty(\$quota['enabled']) && (int)\$quota['left'] > 0;"),
  'guests can generate only while their live server-side image quota allows it');
guest_test(str_contains($chat, '$reservation = coreui_guest_image_reserve($pdo, $uid);')
  && str_contains($chat, 'if (!$saved) coreui_guest_image_release($pdo, $reservation);')
  && str_contains($chat, '$saved = true;'),
  'each successful image counts individually, failed or cancelled jobs are refunded');
guest_test(str_contains($lib, 'coreui_abuse_count($pdo, $key, COREUI_GUEST_IMAGES_DAY_SECONDS, 1, $now)')
  && str_contains($lib, "if (empty(\$count['ok']))")
  && str_contains($lib, 'coreui_abuse_give_back($pdo, $key)'),
  'server counts guest image jobs atomically and fails closed if quota storage is missing');


// Chat: what is left, and a clear note at the end.
$app = (string)file_get_contents($root . '/js/console-app.js');
guest_test(str_contains($app, "'GAST · NOCH ' + Math.max(0, Number(g.left) || 0) + ' VON ' + (Number(g.limit) || 0) + ' NACHRICHTEN'")
  && str_contains($app, "'guest_limit_reached': '\\u26a0 Deine Gast-Nachrichten sind aufgebraucht."), 'chat shows the quota and the end');
guest_test(str_contains((string)file_get_contents($root . '/app.html'), 'id="composerLinkLabel"'), 'quota label in the composer');

echo "CoreAI guest access: limit, counting, console only, hourly cap, login and admin OK\n";
