<?php
declare(strict_types=1);
// CoreAI 0.5.71: images, videos and PDF pages are answered by the same Mara
// as text turns. Live report 28.10.2026: after an image or video "someone
// else" answered and took change requests as insulting criticism; the work
// view also showed a false "transfer incomplete" error for every attachment.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

function vision_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Vision persona FAIL: {$why}\n"); exit(1); }
}

$chat = (string)file_get_contents($root . '/api/chat.php');
$start = strpos($chat, '// Vision-Handling: Gemma 4 ist nativ multimodal');
$end = strpos($chat, '// Fallback: Bild mit vollem Kontext nochmal versuchen', (int)$start);
vision_test($start !== false && $end !== false, 'vision branch found');
$vision = substr($chat, (int)$start, (int)$end - (int)$start);

// Same persona: no own system prompt, so the operator's Modelfile SYSTEM stays.
vision_test(str_contains($vision, '$visionSys = $sys;'), 'vision uses the regular (empty) system prompt');
vision_test(!preg_match('~Gamer-Note|Online-Chat der 2000er|Du bist Mara Thorne~u', $vision),
  'no second, hard-coded Mara persona');
vision_test(str_contains($vision, '$visionPrompt = $u . "\n\nANHANG-AUFGABE: " . $visionPrompt;'),
  'history, memory, KI-Profil and attachment block reach the vision call');
vision_test(substr_count($vision, '$visionRules') >= 4, 'every vision variant carries the shared rules');
vision_test(str_contains($vision, 'stammen nicht von') && str_contains($vision, 'uebernimm ihren Ton nicht'),
  'text inside an attachment is not the sender, its tone is not adopted');
vision_test(str_contains($vision, '$visionTimeout = ember_timeout_for_model($primaryModel);'),
  'configured timeout also for a single image');

// The regular sync prompt keeps the Modelfile SYSTEM: empty for Mara's own
// Core; since 0.5.76 other Cores get the same persona text. Images use it too.
vision_test(str_contains($chat, "\$sysBase = ember_persona_system_for(ember_model());\n  \$sys = \$sysBase;"),
  'sync system prompt stays empty for Mara\'s own Core (runtime check in core-tiers-selftest)');

// No memory reflection from an image description (console only).
vision_test(str_contains($vision, "if (\$channel === 'console') \$GLOBALS['COREUI_VISION_TURN'] = true;"),
  'a console vision turn is marked');
vision_test(str_contains($chat, '$visionTurn = !empty($GLOBALS[\'COREUI_VISION_TURN\']);')
  && str_contains($chat, 'if (!$visionTurn && ember_should_reflect_exchange($userMsg, $emberReply)) {'),
  'reflection skips vision turns, runtime facts and reputation stay');

// The SSE endpoint names the vision path instead of a false error.
$stream = (string)file_get_contents($root . '/api/console_stream.php');
// 0.6.17: an audio turn takes the same path and names it "Aufnahme wird angehoert".
$visionAt = strpos($stream, '    } elseif ($isVisionTurn) {' . "\n" . '      // 0.5.71: images, videos and PDF pages never stream on purpose;');
$unsafeAt = $visionAt === false ? false : strpos($stream, '} elseif ($streamUnsafe) {', $visionAt);
vision_test($visionAt !== false && $unsafeAt !== false
  && str_contains(substr($stream, $visionAt, $unsafeAt - $visionAt),
    "coreui_work_record(\$pdo, 'model', 'running', \$listening ? 'Aufnahme wird angehoert' : 'Anhang wird angesehen');"),
  'vision turns record "Anhang wird angesehen" (audio: "Aufnahme wird angehoert") before the incomplete-stream error');
$js = (string)file_get_contents($root . '/js/coreui-work-events.js');
vision_test(str_contains($js, "case 'Anhang wird angesehen':") && str_contains($js, "'Anhang wird angesehen':'Ich sehe mir den Anhang an'")
  && str_contains($js, "case 'Aufnahme wird angehoert':") && str_contains($js, "'Aufnahme wird angehoert':'Ich höre die Stimmsequenz an'"),
  'the work view explains the vision and the listening step');

echo "CoreAI vision: same Mara, full context, no false error, no image facts in memory OK\n";
