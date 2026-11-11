<?php
declare(strict_types=1);
// CoreAI 0.6.17 (P20.1, 0.6.18 to 0.6.20): voice messages and audio files, heard by Mara herself.
//   php scripts/voice-input-selftest.php
// Offline: cutting at pauses (at most 60 s per WAV, Gemma 4's limit), the
// audio budget of the context window, the task for Mara, the transcription
// request for the history (Google's template, neutral SYSTEM text, no
// thinking, no options except the KI-Profil context window) and the wiring of
// upload, chat call, stream, history, endpoint, page and nginx.
// With ffmpeg (as in the php container): a real Opus recording becomes WAVs
// for Mara's own call (frames folder, images field); guests and Cores without
// audio get the honest note; audio-only WebM is audio, not video. Against a
// stand-in Ollama (php -S) with the model queue counted: the transcript after
// the answer (one queued call per part, one retry when Core claims there was
// no recording, silence never sent, cancel, full queue), its cache and the
// lines it gives the history.
// 0.6.35 (P20.2): videos are heard too: the sound track becomes WAVs after the frames in the
// same images field, transcribed first; no track, a silent track, a Core without audio and
// guests get the honest note; the transcript goes under the video and into the history.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$frames = sys_get_temp_dir() . '/coreai-vi-frames-' . bin2hex(random_bytes(4));
define('STU_EMBER_VIDEO_FRAME_DIR', $frames);

function vi_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Voice input FAIL: {$why}\n"); exit(1); }
}

// The model queue, cancel check and the bits of api/chat.php the attachments need, counted here.
function vi_reset(): void {
  $GLOBALS['VI'] = ['acquire' => 0, 'release' => 0, 'depth' => 0, 'max_depth' => 0, 'waits' => [], 'refuse' => false, 'cancel' => false,
    'hears' => ['completion', 'vision', 'audio'], 'url' => $GLOBALS['VI']['url'] ?? '', 'guest' => false, 'language' => 'de', 'ctx' => null];
}
vi_reset();
function ember_global_lock_acquire(int $timeoutSeconds = 90): mixed {
  $v = &$GLOBALS['VI'];
  if ($v['refuse']) return false;
  $v['acquire']++;
  $v['waits'][] = $timeoutSeconds;
  $v['depth']++;
  $v['max_depth'] = max($v['max_depth'], $v['depth']);
  return 'db_lock';
}
function ember_generation_lock_release($slot): void { $GLOBALS['VI']['release']++; $GLOBALS['VI']['depth']--; }
function ember_agent_cancel_requested(bool $force = false): bool { return $GLOBALS['VI']['cancel']; }
function ember_cfg(string $name, $default = null) { return defined($name) ? constant($name) : $default; }
function ember_debug_log(string $type, array $meta = []): void {}
function ember_model(): string { return 'core-test:latest'; }
function ember_url(): string { return $GLOBALS['VI']['url']; }
function ember_keep_alive() { return -1; }
function ember_profile_num_ctx(string $model): ?int { return null; }
function ember_ollama_context_window(string $model): ?int { return $GLOBALS['VI']['ctx']; }
function ember_ollama_show_json(string $model): ?array {
  return $GLOBALS['VI']['hears'] === null ? null : ['capabilities' => $GLOBALS['VI']['hears']];
}
function coreui_guest_is(PDO $pdo, int $uid): bool { return $GLOBALS['VI']['guest']; }
function coreui_ai_runtime_settings(): array { return ['main_language' => $GLOBALS['VI']['language']]; }
// 0.6.19: Mara's own memory reflection and the relationship value (api/chat.php), recorded here.
$GLOBALS['VI_MEMORY'] = [];
function ember_should_reflect_exchange(string $userMsg, string $emberReply): bool { return mb_strlen(trim($userMsg)) >= 14; }
function ember_reflect_maybe_store(PDO $pdo, int $uid, string $characterId, string $userMsg, string $emberAnswer): void {
  $GLOBALS['VI_MEMORY'][] = ['reflect', $uid, $characterId, $userMsg, $emberAnswer];
}
function ember_reputation_maybe_update(PDO $pdo, int $uid, string $characterId, string $userMsg, string $emberAnswer): void {
  $GLOBALS['VI_MEMORY'][] = ['reputation', $uid, $characterId, $userMsg, $emberAnswer];
}
function ember_is_fail_message(string $reply): bool { return str_starts_with($reply, 'FEHLER'); }
// 0.6.22: the work path, recorded here.
$GLOBALS['VI_WORK'] = [];
function coreui_work_record(PDO $pdo, string $kind, string $state, string $title, array $meta = []): void {
  $GLOBALS['VI_WORK'][] = [$kind, $state, $title];
}
require_once $root . '/api/ember_attachments.php';

// --- Offline ---------------------------------------------------------------------------------
vi_test(COREUI_VOICE_INPUT_MAX_SECONDS === 120 && COREUI_VOICE_FILE_MAX_SECONDS === 600 && COREUI_VOICE_PART_MAX_SECONDS === 60,
  'microphone 2 minutes, files up to 10 minutes, at most 60 seconds per WAV');
vi_test(coreui_voice_native_budget(null) === 600 && coreui_voice_native_budget(65536) === 600 && coreui_voice_native_budget(16384) === 262
  && coreui_voice_native_budget(4096) === 65 && coreui_voice_native_budget(1024) === 60,
  'audio takes at most 40 % of the context window (25 tokens per second), at least one WAV');
vi_test(coreui_voice_is_voice_message('Sprachnachricht-2026-11-05_10-00-00.webm') && !coreui_voice_is_voice_message('besprechung.webm'),
  'a recording from the microphone is a voice message');
vi_test(coreui_voice_language_name('de') === 'German' && coreui_voice_language_name('en') === 'English'
  && coreui_voice_language_name('xx') === 'German', 'the account language picks the template language');
// 0.7.14: Settings > Stimme > Sprache: more fixed languages and "Automatisch erkennen".
vi_test(coreui_voice_language_name('fr') === 'French' && coreui_voice_language_name('es') === 'Spanish'
  && coreui_voice_language_name('auto:de') === 'auto:German' && coreui_voice_language_name('auto:en') === 'auto:English'
  && coreui_voice_language_name('auto:xx') === 'auto:German', 'fixed languages and automatic detection with the main language as fallback');
$auto = coreui_voice_transcribe_prompt('auto:German', ['Bergisch Gladbach']);
vi_test(str_starts_with($auto, "Transcribe the following speech segment into text in the language in which it is spoken. Do not translate it. If the language is unclear, assume German.\n\n")
  && str_contains($auto, 'spell it exactly like this: Bergisch Gladbach.') && str_contains($auto, '* Only output the transcription, with no newlines.')
  && str_starts_with(coreui_voice_transcribe_prompt('French'), 'Transcribe the following speech segment in French into French text.'),
  'automatic detection keeps the template instructions and the terms');
vi_test(coreui_voice_core_hears(['capabilities' => ['completion', 'vision', 'audio']]) === true
  && coreui_voice_core_hears(['capabilities' => ['completion', 'vision']]) === false
  && coreui_voice_core_hears(null) === null && coreui_voice_core_hears(['details' => []]) === null,
  'a Core hears when Ollama lists audio; unknown when Ollama does not say');

$log = "[silencedetect @ 0x1] silence_start: 25.0012\n[silencedetect @ 0x1] silence_end: 26.004 | silence_duration: 1.0\n"
  . "size=N/A time=00:00:30\n[silencedetect @ 0x1] silence_start: 51\n[silencedetect @ 0x1] silence_end: 52 | silence_duration: 1\n"
  . "[silencedetect @ 0x1] silence_start: 118.5\n";
vi_test(coreui_voice_parse_silences($log, 120.0) === [[25.0012, 26.004], [51.0, 52.0], [118.5, 120.0]], 'pauses read, a pause at the end included');
$partsOk = static function (array $parts, float $total): bool {
  $pos = 0.0;
  foreach ($parts as [$a, $b]) {
    if (abs($a - $pos) > 0.001 || $b - $a > 60.0 || $b <= $a) return false;
    $pos = $b;
  }
  return abs($pos - $total) < 0.001;
};
vi_test(coreui_voice_parts(50.0, []) === [[0.0, 50.0]], 'a short recording is one WAV');
vi_test(coreui_voice_parts(150.0, []) === [[0.0, 60.0], [60.0, 120.0], [120.0, 150.0]], 'without pauses: hard cuts at 60 s');
$cut = coreui_voice_parts(150.0, [[10.0, 11.0], [29.0, 30.0], [54.0, 55.0], [100.0, 101.2]]);
vi_test($cut === [[0.0, 54.5], [54.5, 100.6], [100.6, 150.0]] && $partsOk($cut, 150.0), 'cut in the middle of the latest pause before 60 s');
vi_test(coreui_voice_parts(120.1, []) === [[0.0, 60.0], [60.0, 120.0]], 'a rest under 0.3 s is dropped');
vi_test(coreui_voice_part_silent([0.0, 5.0], [[0.0, 5.0]]) && !coreui_voice_part_silent([0.0, 5.0], [[1.0, 5.0]]), 'a part that is all pause');
vi_test(coreui_voice_stamp(75.9) === '01:15' && coreui_voice_stamp(600) === '10:00', 'time marks');
// 0.6.18: ffmpeg 5.1 (Debian, php container) and 6.1 report 150 s a few milliseconds apart.
vi_test(coreui_voice_length(149.98) === '02:30' && coreui_voice_length(150.008) === '02:30' && coreui_voice_length(119.996) === '02:00'
  && coreui_voice_length(64.6) === '01:05', 'a length is rounded, never cut down by a few milliseconds');

$task = coreui_voice_listen_task('Patrick', '', [['voice' => true, 'seconds' => 120.0, 'paths' => ['a', 'b', 'c'],
  'stamps' => ['00:00', '00:51', '01:43'], 'truncated' => true]]);
vi_test(str_starts_with($task, 'Patrick hat dir gesprochen:') && str_contains($task, "\nEine Stimmsequenz von 120 Sekunden, als 3 Audioteile")
  && str_contains($task, 'als 3 Audioteile nacheinander (Beginn 00:00, 00:51, 01:43)') && str_contains($task, 'nur die ersten 02:00 Minuten')
  && str_contains($task, 'Du hoerst die Aufnahme selbst.') && !str_contains($task, 'schreibt'), 'Mara is told she hears the recording, in how many WAVs');
$task = coreui_voice_listen_task('Patrick', 'Was meinst du?', [['voice' => false, 'name' => 'besprechung.mp3', 'seconds' => 42.0,
  'paths' => ['a'], 'stamps' => ['00:00']]]);
vi_test(str_contains($task, 'Die Audiodatei "besprechung.mp3" von 42 Sekunden, als Audio.') && str_contains($task, 'Dazu schreibt Patrick: "Was meinst du?"')
  && str_contains($task, 'stammen nicht von Patrick'), 'an audio file with text: both reach Mara');

// 0.6.20: no bracketed tag in the task (Mara copied it and opened her answer with "["),
// and a stray "[" in front of an answer to a recording is removed.
vi_test(!str_contains($task, '[') && !str_contains($task, ']'), 'the task has no bracketed tag for Mara to copy');
vi_test(coreui_voice_strip_stray_bracket("[Hey Rick, vielen Dank!\n\nGute Fahrt!") === "Hey Rick, vielen Dank!\n\nGute Fahrt!"
  && coreui_voice_strip_stray_bracket('[Klingt gut, bis morgen.]') === 'Klingt gut, bis morgen.',
  'an answer that opens a bracket and never closes it, or sits in one, loses it');
vi_test(coreui_voice_strip_stray_bracket('[Link](https://example.org) ist hier.') === '[Link](https://example.org) ist hier.'
  && coreui_voice_strip_stray_bracket('[Analyse: kurz] Hallo') === '[Analyse: kurz] Hallo' && coreui_voice_strip_stray_bracket('Hallo [Rick]') === 'Hallo [Rick]'
  && coreui_voice_strip_stray_bracket('[a] und [b]') === '[a] und [b]', 'links and closed notes stay');
// 0.6.22: a tool marker stays whole ("[WEB: Wetter ...]" had become "WEB: Wetter ..." as the answer).
vi_test(coreui_voice_strip_stray_bracket('[WEB: Wetter Bergisch Gladbach heute]') === '[WEB: Wetter Bergisch Gladbach heute]'
  && coreui_voice_clean_reply('[BROWSE: Termine EVK]') === '[BROWSE: Termine EVK]'
  && coreui_voice_clean_reply('[ARCHIV: Termin morgen') === '[ARCHIV: Termin morgen', 'tool markers are not stray brackets');

// 0.6.22: names and terms for voice messages (KI-Profil): one per line, cleaned, at most 40.
vi_test(coreui_voice_terms_normalise("Bergisch Gladbach\n  EVK ,evk; [WEB: x]\n\"Nicki\"\n- Mönchengladbach -\n...\nO’Brien")
  === ['Bergisch Gladbach', 'EVK', 'WEB x', 'Nicki', 'Mönchengladbach', 'O’Brien'],
  'terms: trimmed, split at lines, commas and semicolons, no brackets or quotes, no duplicates');
vi_test(count(coreui_voice_terms_normalise(implode("\n", array_map(static fn(int $i): string => 'Ort ' . $i, range(1, 60))))) === 40
  && mb_strlen(coreui_voice_terms_normalise(str_repeat('ä', 90))[0]) === 60 && coreui_voice_terms_normalise(['A', 'B']) === ['A', 'B']
  && coreui_voice_terms_normalise('') === [], 'at most 40 terms of at most 60 characters');
$plain = coreui_voice_transcribe_prompt('German');
$hinted = coreui_voice_transcribe_prompt('German', ['Bergisch Gladbach', 'EVK']);
vi_test(!str_contains($plain, 'names and terms') && coreui_voice_transcribe_prompt('German', []) === $plain
  && str_starts_with($hinted, $plain . "\n* ") && str_contains($hinted, 'spell it exactly like this: Bergisch Gladbach, EVK.')
  && str_contains($hinted, 'Do not add them when they are not spoken.'),
  'without terms Google\'s template as before; with terms one more instruction');
$payload = coreui_voice_transcribe_payload('core:latest', 'QUJD', 'German', null, null, ['Bergisch Gladbach']);
vi_test($payload['messages'][1]['content'] === coreui_voice_transcribe_prompt('German', ['Bergisch Gladbach']) && !isset($payload['options']),
  'the transcription call carries the terms, still without options');
$clip = [['voice' => true, 'seconds' => 6.0, 'paths' => ['a'], 'stamps' => ['00:00'], 'transcript' => 'Ich wohne in Bergisch Gladbach.']];
$task = coreui_voice_listen_task('Rick', '', $clip, ['Bergisch Gladbach', 'EVK'], ['web', 'archive']);
vi_test(str_contains($task, 'Namen und Begriffe, die Rick oft sagt (so geschrieben): Bergisch Gladbach, EVK.')
  && str_contains($task, '[WEB: suchbegriffe]') && str_contains($task, '[ARCHIV: stichworte]') && !str_contains($task, '[BROWSE:')
  && str_contains($task, 'antworte NUR mit genau einem dieser Marker'), 'Mara gets the terms and the tools she may use');
$task = coreui_voice_listen_task('Rick', '', $clip);
vi_test(!str_contains($task, 'Namen und Begriffe') && !str_contains($task, '[WEB:')
  && !str_contains($task, '[BILD:') && !str_contains($task, '[BILD ICH:'),
  'without terms and tools: neither is mentioned');
// 0.6.40 (P20.5): an image after a voice message (operator, 07.11.2026: "eine Wildkatze im Wald" got a text only).
$task = coreui_voice_listen_task('Rick', '', $clip, [], ['web', 'image']);
vi_test(str_contains($task, 'Moechte Rick ein Selbstportraet von dir')
  && str_contains($task, '[BILD ICH: genaue Bildbeschreibung auf Englisch]')
  && str_contains($task, '[BILD GROSS ICH: ...]')
  && str_contains($task, 'Fuer andere Motive benutze [BILD: Bildbeschreibung auf Englisch]')
  && str_contains($task, '[BILD GROSS: ...]')
  && str_contains($task, 'Nur bei ICH wird dein persoenliches Referenzbild mitgegeben.')
  && str_contains($task, 'Ohne Marker entsteht kein Bild.'),
  'Mara may paint normal images and self-portraits after a voice message');
vi_test(coreui_voice_strip_stray_bracket('[BILD: a wild cat in the forest]') === '[BILD: a wild cat in the forest]'
  && coreui_voice_strip_stray_bracket('[BILD ICH: Mara as Superwoman]') === '[BILD ICH: Mara as Superwoman]'
  && coreui_voice_strip_stray_bracket('[BILD GROSS ICH: Mara as Superwoman]') === '[BILD GROSS ICH: Mara as Superwoman]',
  'normal and self-portrait markers stay whole in spoken replies');


// 0.6.20 (operator's screenshots): a closing tag nothing opened, and the sentence that only
// announces having listened, which repeated itself through the history; normal text stays.
vi_test(coreui_voice_clean_reply("Hey Rick, ich hab mir die Sprachnachricht angehört. 😊\n\nIch hoffe, das klärt die Sache! 🗺️🚀</blockquote>")
  === 'Ich hoffe, das klärt die Sache! 🗺️🚀', 'no announcing sentence, no orphan closing tag');
vi_test(coreui_voice_clean_reply("[Hey Rick, hab die Sprachnachricht gehört!\n\nAlles gut.") === 'Alles gut.', 'together with a stray bracket');
vi_test(coreui_voice_clean_reply('Ich habe deine Nachricht gehört.') === 'Ich habe deine Nachricht gehört.'
  && coreui_voice_clean_reply('Hab die Aufnahme gehört und finde sie super, weil du lachst.') === 'Hab die Aufnahme gehört und finde sie super, weil du lachst.'
  && coreui_voice_clean_reply('Das ist ein Zitat <blockquote>x</blockquote>') === 'Das ist ein Zitat <blockquote>x</blockquote>',
  'never an empty answer, a sentence that goes on stays, a tag that was opened stays');
$task = coreui_voice_listen_task('Rick', '', [['voice' => true, 'seconds' => 4.2, 'paths' => ['a'], 'stamps' => ['00:00'],
  'transcript' => 'Was gibt es bei dir Neues?']]);
vi_test(str_contains($task, 'Automatische Mitschrift dazu (kann Hoerfehler enthalten, im Zweifel gilt der Audioinhalt): "Was gibt es bei dir Neues?"')
  && str_contains($task, 'Das ist die NEUE Nachricht') && str_contains($task, 'Fruehere Stimmsequenzen im Verlauf sind schon beantwortet')
  && str_contains($task, 'Kuendige nicht an, dass du die Nachricht gehoert'), 'Mara gets the transcript as anchor, only the new message counts');

$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'German');
vi_test($payload['stream'] === false && $payload['think'] === false && !array_key_exists('options', $payload)
  && !array_key_exists('keep_alive', $payload), 'transcript: no thinking, no options');
vi_test($payload['messages'][0] === ['role' => 'system', 'content' => coreui_voice_transcribe_system()]
  && $payload['messages'][1]['content'] === coreui_voice_transcribe_prompt('German') && $payload['messages'][1]['images'] === ['QUJD'],
  'transcript: neutral SYSTEM text, Google\'s template, the audio in images');
$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'English', -1, 8192);
vi_test($payload['options'] === ['num_ctx' => 8192] && $payload['keep_alive'] === -1
  && str_starts_with($payload['messages'][1]['content'], 'Transcribe the following speech segment in English into English text.'),
  'English template; the KI-Profil context window is kept so Core is not reloaded');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$noDash = static fn(string $t): bool => preg_match('/[\x{2013}\x{2014}]/u', $t) !== 1;
vi_test($noDash($read('api/voice_transcribe.php') . $read('js/coreui-voice-input.js') . $read('api/voice_input.php')), 'no long dashes');
$chat = $read('api/chat.php');
vi_test(str_contains($chat, '$listenThink = ($hasAudio || $videoSound !== null) ? false : null;')
  && str_contains($chat, "      \$listenOptions,\n      \$imageUrl,\n      \$listenThink\n    );"), 'Mara hears without thinking (operator decision)');
// 0.6.20: Mara read the header of the skill documents out ("[COREAI KERN-SKILLS: ...]"); none in front of a recording.
vi_test(str_contains($chat, "\$listenOptions = (\$hasAudio || \$videoSound !== null) ? ['__skills' => 'none'] : [];")
  && str_contains($chat, '$imgReply = ember_call_ollama($primaryModel, $visionSys, $visionPrompt, $visionTimeout, $listenOptions, $imageUrl, $listenThink);'),
  'no skill documents in front of a recording, also on the retry');
vi_test(str_contains($chat, "if ((\$hasAudio || \$videoSound !== null) && is_string(\$imgReply) && coreui_voice_reply_kind(\$imgReply) === 'missed') {"),
  'one retry when Core claims there was no recording');
vi_test(str_contains($chat, "\$imgReply,\n      \$imageUrl,\n      \$listenThink,\n      \$listenOptions\n    );"),
  'a continued answer keeps thinking off');
// 0.6.35 (P20.2): the video's task names its sound track; its WAVs are removed after the call; the line under the video.
vi_test(str_contains($chat, "ember_video_sound_task(\$videoSound, (string)(\$vf['sound_note'] ?? 'Ton hast du nicht.'))")
  && !str_contains($chat, '"Ton hast du nicht. Was sagst du dazu')
  && str_contains($chat, "ember_attach_audio_cleanup(\$meta['sound']['dir'] ?? null);"), 'the video task hears the sound track, its WAVs are removed');
vi_test(str_contains($read('api/voice_input.php'), "['pending', 'silent', 'failed', 'none']")
  && !str_contains($read('api/voice_input.php'), "=== 'video') stu_json(['ok' => true, 'state' => 'none']);"),
  'a fresh video is asked again until its state is written (0.6.36)');
vi_test(str_contains($read('api/voice_input.php'), "kind IN (?, ?) LIMIT 1')") && str_contains($read('api/voice_input.php'), "\$st->execute([\$uuid, \$uid, 'audio', 'video']);")
  && str_contains($read('js/coreui-voice-input.js'), "querySelectorAll('audio.msg-audio[data-uuid], video.msg-video[data-uuid]')")
  && str_contains($read('js/console-app.js'), "vid.className = 'msg-image msg-video';")
  && str_contains($read('js/console-app.js'), "if (att.uuid) vid.setAttribute('data-uuid', String(att.uuid));"),
  'the transcript of a video\'s sound track shows under the video');
vi_test(str_contains($chat, "  ?bool \$thinkOverride = null,\n  array \$optionOverrides = []\n): ?string {")
  && str_contains($chat, "      \$optionOverrides,\n      \$imageUrl,\n      \$thinkOverride\n    );"),
  'the continuation passes the think switch on');
vi_test(str_contains($chat, "if (\$hasAudio) \$imgReply = coreui_voice_clean_reply((string)\$imgReply);"), 'answers to recordings are cleaned');
// 0.6.22: a tool marker in the answer to a voice message runs Mara's work loop (text calls, the
// recording is not sent again); a marker for a tool that is off never stands as the answer.
vi_test(str_contains($chat, '$voiceTool = ($voiceTools !== [] && is_string($imgReply)) ? coreui_agent_next_tool($imgReply, $voiceTools) : null;')
  && str_contains($chat, 'coreui_agent_loop_run($imgReply, $voiceTool, $voiceBase, $voiceTools, 0,')
  && str_contains($chat, '$listenOptions, null, $listenThink)') && str_contains($chat, "\$GLOBALS['STU_EMBER_BROWSE_REQUEST'] = \$voiceLoop['browse'];")
  && str_contains($chat, "array_intersect(ember_agent_allowed_tools(\$consoleUid, (string)\$consoleSessionId), ['web', 'browse', 'archive', 'image'])")
  && str_contains($chat, '$prose = trim(ember_strip_stray_tool_markers($imgReply));')
  && strpos($chat, '$voiceLoop = coreui_agent_loop_run(') < strpos($chat, 'if ($hasAudio) $imgReply = coreui_voice_clean_reply('),
  'tools after a voice message: the work loop before the reply is cleaned');
vi_test(str_contains($chat, '$userMsgClean : \'\', $audioClips, $voiceTerms, $voiceTools);'), 'Mara\'s task gets the terms and tools');
// 0.6.23: no "[Bild wurde mitgesendet ...]" for audio: Mara described a picture that was never sent
// ("[Bildbeschreibung: zwei Haende mit Brot ...]", operator's screenshot, 06.11.2026).
if (preg_match('~^function ember_attachment_prompt_note\(.*?^}~ms', $chat, $fn) !== 1) vi_test(false, 'ember_attachment_prompt_note missing');
eval($fn[0]);
$imageLine = "\n[Bild wurde mitgesendet - beschreibe und kommentiere es als Mara.]";
$GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] = [['type' => 'audio'], ['type' => 'audio']];
vi_test(ember_attachment_prompt_note(['a.wav', 'b.wav']) === '', 'voice messages: no picture line');
$GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] = [['type' => 'audio'], ['type' => 'image']];
vi_test(ember_attachment_prompt_note(['a.wav', 'b.png']) === $imageLine, 'a picture with a voice message: the line stays');
$GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] = [['type' => 'video']];
vi_test(ember_attachment_prompt_note(['f1.jpg']) === $imageLine, 'video frames: the line stays');
unset($GLOBALS['STU_EMBER_ATTACHMENT_VISION_META']);
vi_test(ember_attachment_prompt_note('data:image/png;base64,AAA') === $imageLine && ember_attachment_prompt_note(null) === '',
  'a picture without a description: the line; no media: nothing');
vi_test(substr_count($chat, '. ember_attachment_prompt_note($imageUrl);') === 2
  && substr_count($chat, '[Bild wurde mitgesendet - beschreibe und kommentiere es als Mara.]') === 1,
  'both prompt builders ask first');
// 0.6.23: the hearing check on the server uses the very same transcription as CoreAI.
$check = (string)file_get_contents($root . '/scripts/voice-hear-check.php');
vi_test(str_contains($check, 'coreui_voice_transcribe_part(ember_url(), $core, (string)@file_get_contents($path), $language, 600,')
  && str_contains($check, 'coreui_voice_prepare_native($source, $plain, COREUI_VOICE_INPUT_MAX_SECONDS)')
  && str_contains($check, "'loudnorm=I=-18:TP=-1.5:LRA=11'") && str_contains($check, 'register_shutdown_function('),
  'the hearing check: same WAVs and calls as CoreAI, loudness variant, temporary files removed');
// 0.6.30: the live probe (P20.4) measures three ways to the first sentence Eve could speak.
$live = (string)file_get_contents($root . '/scripts/voice-live-check.php');
foreach (['live_first_sentence', 'live_heard_line'] as $fnName) {
  if (preg_match('~^function ' . $fnName . '\(.*?^}~ms', $live, $fn) !== 1) vi_test(false, $fnName . ' missing');
  eval($fn[0]);
}
vi_test(live_first_sentence("Gehört: Wie wird das Wetter?\nIch schaue gleich nach. Morgen", true) === 'Ich schaue gleich nach.'
  && live_first_sentence('Gehört: Wie wird das Wetter?', true) === null
  && live_first_sentence('[Analyse: kurz', false) === null && live_first_sentence('Ja.', false) === null
  && live_first_sentence('Ja. Das klingt gut, oder?', false) === 'Ja. Das klingt gut, oder?',
  'the first sentence Eve could speak, after the "Gehört:" line of way B');
vi_test(live_heard_line("**Gehört:** \"Wie wird das Wetter?\"\nAntwort") === 'Wie wird das Wetter?' && live_heard_line('Antwort ohne Zeile') === '',
  'the "Gehört:" line is the transcript of way B');
vi_test(str_contains($live, "'think' => false") && str_contains($live, 'ember_global_lock_acquire(1800)')
  && str_contains($live, 'ember_generation_lock_release($lock)') && str_contains($live, 'coreui_voice_listen_task($sender')
  && str_contains($live, 'coreui_voice_transcribe_part(ember_url(), $core,') && str_contains($live, "'prompt_eval_count'")
  && str_contains($live, 'register_shutdown_function('),
  'the live probe: no thinking, Mara\'s queue, the chat\'s task and transcript, tokens read anew, no files left');
$attach = (string)file_get_contents($root . '/api/ember_attachments.php');
vi_test(str_contains($attach, "'terms' => ember_attach_voice_terms(\$pdo, \$uid),") && str_contains($attach, 'ember_profile_num_ctx($model), $terms);'),
  'both transcripts get the terms');
vi_test(str_contains($chat, "ember_attach_audio_cleanup(\$meta['dir'] ?? null);") && str_contains($chat, '$visionPrompt = coreui_voice_listen_task($senderName,'),
  'the WAVs are removed after the call; Mara gets the listening task');
vi_test(str_contains($chat, '"SELECT id, user_id, character_id, character_name, message FROM stu_chat_messages')
  && str_contains($chat, 'ember_attach_audio_history_notes($pdo, $consoleUid,'), 'later turns read what was said');
$stream = $read('api/console_stream.php');
$doneAt = strpos($stream, "  sse_send('done', [\n    'work_events'");
$transcriptAt = strpos($stream, 'ember_attach_audio_transcribe_after($pdo, (int)$uid, $attachmentIds);');
vi_test($doneAt !== false && $transcriptAt > $doneAt && $transcriptAt < strpos($stream, 'coreui_compact_with_core($pdo, (int)$uid, $sessionId, false);'),
  'the transcript is written after the answer is out, before the summary');
vi_test(str_contains($chat, "\$GLOBALS['COREUI_VOICE_TURN'] = true;") && str_contains($chat, "if (!\$voiceTurn) ember_reputation_maybe_update(\$pdo, \$uid, \$cid, \$userMsg, \$emberReply);"),
  'a voice turn is reflected later, not now with an empty text');
$voiceAt = strpos($stream, "\$voiceTurn = !empty(\$GLOBALS['COREUI_VOICE_TURN'])");
vi_test($voiceAt !== false && $voiceAt < strpos($stream, 'ember_after_insert_tasks($pdo, $char, $message, $replyFinal);')
  && strpos($stream, 'ember_attach_voice_reflect($pdo, $char, (int)$uid, $attachmentIds, ember_attach_strip_marker($message), $replyFinal);') > $transcriptAt,
  'the stream reflects the voice message right after its transcript');
vi_test(str_contains($read('api/console_upload.php'), "if (coreui_voice_has_video_stream((string)\$file['tmp_name']) === false) {"),
  'audio-only WebM is stored as audio');
$app = $read('js/console-app.js');
// 0.6.69: 0.6.68 introduced a non-persistent welcome draft. The former
// single-line session assertion incorrectly rejected its valid voice route.
// Verify BOTH existing and unsaved channels, including the auto-send handoff.
$voiceStart = strpos($app, '    sendVoice: function (file) {');
$voiceEnd = $voiceStart === false ? false : strpos($app, '    sendImageEdit: function (files, instruction, size) {', $voiceStart);
$voicePath = ($voiceStart !== false && $voiceEnd !== false)
  ? substr($app, $voiceStart, $voiceEnd - $voiceStart) : '';
$handoffStart = strpos($app, '  function sendAfterCompletedUpload() {');
$handoffEnd = $handoffStart === false ? false : strpos($app, '  function processUploadQueue() {', $handoffStart);
$handoff = ($handoffStart !== false && $handoffEnd !== false)
  ? substr($app, $handoffStart, $handoffEnd - $handoffStart) : '';
vi_test(str_contains($app, 'window.CoreUIComposer = {')
  && str_contains($app, "aud.setAttribute('data-uuid', String(att.uuid));")
  && str_contains($voicePath, "pendingSendAfterUpload = {text: inputEl ? String(inputEl.value || '') : '',")
  && str_contains($voicePath, "sessionId: ses ? String(ses.id) : '__draft__'")
  && str_contains($handoff, '(ses && String(ses.id) === pending.sessionId)')
  && str_contains($handoff, "(!ses && pending.sessionId === '__draft__')")
  && str_contains($handoff, 'sendMessage(pending.text);'),
  'voice upload keeps typed text and sends it in existing or first unsaved chat');
$api = $read('api/voice_input.php');
vi_test(!str_contains($api, '$_FILES') && !str_contains($api, "'transcribe'")
  && strpos($api, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, "if (\$action === 'transcript') {")
  && str_contains($api, "WHERE uuid = ? AND user_id = ? AND kind IN (?, ?) LIMIT 1"), 'endpoint: status and the own transcript only, no guests');
$js = $read('js/coreui-voice-input.js');
// 0.7.14: a Stimmsequenz still puts no text into the field; only a dictation (its own button, Settings > Stimme)
// writes the Core's text there, in one place (insert), and a Stimmsequenz never reaches that place.
$insertAt = strpos($js, 'function insert(text)');
vi_test(str_contains($js, 'composer.sendVoice(file)') && str_contains($js, "var name = 'Sprachnachricht-'")
  && substr_count($js, "getElementById('inputMessage')") === 1 && $insertAt !== false
  && strpos($js, "getElementById('inputMessage')") > $insertAt && substr_count($js, 'insert(') === 2
  && str_contains($js, "if (current.mode === 'dictate') {\n      keep({ blob: blob, kind: 'diktat', seconds: seconds }).then(function (id) { dictate(blob, id); });\n      return;\n    }")
  && str_contains($js, 'VOICED_MIN_MS') && str_contains($js, 'if (!state.allowed) return;'),
  'page: sends the recording, no text in the field (only a dictation writes there), silence check, nothing for guests');
vi_test(str_contains($read('js/coreui-core-mode.js'), "new CustomEvent('coreui:core'"), 'a Core change re-checks the microphone');
$page = $read('app.html');
vi_test(strpos($page, 'js/coreui-voice-input.js') !== false && strpos($page, 'js/coreui-voice-input.js') < strpos($page, 'js/console-app.js'),
  'chat page loads the voice input');
vi_test(str_contains($read('css/console.css'), '.composer-inner.has-mic {') && str_contains($read('css/console.css'), '.msg-transcript {'),
  'room for the button, a small transcript line');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  $conf = $read($nginx);
  $routes = preg_match('#location ~ \^/api/\(\?:([a-z_|]+)\)#', $conf, $m) === 1 ? explode('|', $m[1]) : [];
  vi_test(in_array('voice_input', $routes, true) && in_array('console_upload', $routes, true), $nginx . ' lets upload and voice input through');
  vi_test(substr_count($conf, 'camera=(), microphone=(self), geolocation=()') === 3 && !str_contains($conf, 'microphone=()'),
    $nginx . ': the page itself may use the microphone, camera and location stay off');
}
$theme = $read('css/coreui-theme.css');
vi_test(str_contains($theme, 'html[data-coreui-theme="dark"] .composer-voice-state {') && str_contains($theme, 'html[data-coreui-theme="dark"] .msg-transcript {'),
  'night mode: strip and transcript line use the dark palette');
vi_test(str_contains($read('api/voice_store.php'), '|t-[0-9a-f]{64}\.json)$/'), 'transcripts expire with the read-aloud cache (7 days)');

$ffmpeg = coreui_voice_bin('ffmpeg');
if ($ffmpeg === null || coreui_voice_bin('ffprobe') === null || !function_exists('proc_open') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI voice input: WAV parts, budget, task, transcript request and wiring OK (no ffmpeg or SQLite here: audio not run)\n";
  exit(0);
}

// --- With ffmpeg and a stand-in Ollama -------------------------------------------------------
$base = sys_get_temp_dir() . '/coreai-vi-test-' . bin2hex(random_bytes(4));
mkdir($base, 0700);
mkdir($frames, 0700);
file_put_contents($base . '/router.php', <<<'PHP'
<?php
$dir = __DIR__;
$body = (string)file_get_contents('php://input');
$n = count(glob($dir . '/req-*.json') ?: []);
file_put_contents($dir . '/req-' . $n . '.json', $body);
$replies = json_decode((string)@file_get_contents($dir . '/replies.json'), true) ?: [];
$reply = array_shift($replies);
file_put_contents($dir . '/replies.json', json_encode($replies));
header('Content-Type: application/json');
if (is_array($reply)) { http_response_code((int)$reply['status']); echo json_encode(['error' => $reply['error']]); return; }
echo json_encode(['model' => 'core-test:latest', 'message' => ['role' => 'assistant', 'content' => (string)$reply], 'done' => true]);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $base . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$GLOBALS['VI']['url'] = "http://127.0.0.1:{$port}/api/chat";
putenv('COREUI_VOICE_CACHE_DIR=' . $base . '/cache');

$ff = static function (array $args) use ($ffmpeg): void {
  exec(escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -loglevel error -y ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $o, $s);
  vi_test($s === 0, 'test audio made: ' . implode(' ', $o));
};
// 150 s like a browser recording (Opus in WebM): 25 s of sound, 1 s of silence, repeated.
$tone = static fn(int $seconds): string => "aevalsrc='if(lt(mod(t,26),25),0.5*sin(2*PI*440*t),0)':s=48000:d={$seconds}";
$long = $base . '/recording.webm';
$ff(['-f', 'lavfi', '-i', $tone(150), '-c:a', 'libopus', '-b:a', '32k', $long]);
$short = $base . '/short.m4a';
$ff(['-f', 'lavfi', '-i', $tone(10), '-c:a', 'aac', $short]);
$quiet = $base . '/quiet.ogg';
$ff(['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', '6', '-c:a', 'libopus', $quiet]);
$video = $base . '/clip.webm';
$ff(['-f', 'lavfi', '-i', 'testsrc=d=2:s=160x120', '-f', 'lavfi', '-i', 'sine=d=2', '-shortest', '-c:v', 'libvpx', '-c:a', 'libopus', $video]);
$cover = $base . '/song.mp3';
$ff(['-f', 'lavfi', '-i', 'sine=d=2', '-f', 'lavfi', '-i', 'color=c=red:s=64x64:d=1', '-map', '0', '-map', '1', '-c:a', 'libmp3lame',
  '-c:v', 'mjpeg', '-disposition:v', 'attached_pic', '-frames:v', '1', $cover]);
$videoMute = $base . '/mute.webm';
$ff(['-f', 'lavfi', '-i', 'testsrc=d=2:s=160x120', '-c:v', 'libvpx', $videoMute]);
$videoQuiet = $base . '/quiet.webm';
$ff(['-f', 'lavfi', '-i', 'testsrc=d=2:s=160x120', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', '2', '-c:v', 'libvpx',
  '-c:a', 'libopus', $videoQuiet]);
$junk = $base . '/junk.webm';
file_put_contents($junk, str_repeat('kein Audio ', 400));

$replies = static fn(array $list) => file_put_contents($base . '/replies.json', json_encode($list));
$requests = static function () use ($base): array {
  $files = glob($base . '/req-*.json') ?: [];
  natsort($files);
  $out = array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true), array_values($files));
  foreach ($files as $f) unlink($f);
  return $out;
};
$workdirs = static fn(): int => count(glob(rtrim(sys_get_temp_dir(), '/') . '/coreai-voice-*') ?: []);
$options = static fn(array $extra = []): array => $extra + ['url' => $GLOBALS['VI']['url'], 'model' => 'core-test:latest',
  'language' => 'de', 'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'queue_wait' => 300];
$rel = static fn(string $file): string => str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($file, '/');
$before = $workdirs();

try {
  // Upload: a recording without a picture is audio; a real video and cover art are told apart.
  vi_test(coreui_voice_has_video_stream($long) === false && coreui_voice_has_video_stream($video) === true
    && coreui_voice_has_video_stream($cover) === false && coreui_voice_has_video_stream($junk) === null,
    'audio-only WebM is audio, a real video is video, cover art is no video');

  // Conversion: Opus in WebM -> 16 kHz mono 16 bit, cut at 2 minutes and said so.
  $wav = $base . '/check.wav';
  $converted = coreui_voice_to_wav($long, $wav, 120);
  vi_test($converted['ok'] && abs($converted['seconds'] - 120.0) < 0.1 && $converted['truncated'] === true
    && abs((float)$converted['source_seconds'] - 150.0) < 0.5, 'converted, 2 minutes kept, the cut is reported');
  vi_test(coreui_wav_gemma_ready(coreui_voice_wav_file_info($wav)), 'the WAV is exactly what Gemma accepts');
  $pauses = coreui_voice_silences($wav, 120.0);
  vi_test(count($pauses) === 4 && abs($pauses[0][0] - 25.0) < 0.2 && abs($pauses[3][1] - 104.0) < 0.2, 'the four pauses found');
  vi_test(!coreui_voice_to_wav($junk, $base . '/junk.wav', 120)['ok'], 'something that is no audio is refused');

  // WAVs for Mara: at most 60 s each, cut in the pauses, nothing but silence leaves nothing.
  $target = $base . '/native';
  mkdir($target, 0700);
  $native = coreui_voice_prepare_native($long, $target, 120);
  vi_test($native['ok'] && count($native['paths']) === 3 && $native['stamps'] === ['00:00', '00:51', '01:43'] && $native['truncated'] === true,
    'three WAVs from two minutes, starts at the pauses');
  foreach ($native['paths'] as $path) {
    $info = coreui_voice_wav_file_info($path);
    vi_test(dirname($path) === $target && coreui_wav_gemma_ready($info) && $info['seconds'] <= 60.0, 'each WAV valid and at most 60 s');
  }
  $quietDir = $base . '/quiet';
  mkdir($quietDir, 0700);
  $none = coreui_voice_prepare_native($quiet, $quietDir, 120);
  vi_test(!$none['ok'] && $none['error'] === 'no_speech' && glob($quietDir . '/*') === [], 'pure silence: nothing for Mara');
  vi_test($workdirs() === $before, 'no temporary files left');

  // The chat: a voice message becomes WAVs in the images field of Mara's own call.
  $pdo = new PDO('sqlite::memory:');
  $pdo->exec('CREATE TABLE stu_console_media (uuid TEXT, user_id INTEGER, kind TEXT, rel_path TEXT, orig_name TEXT, mime_type TEXT,'
    . ' file_size INTEGER, public_url TEXT, created_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_message_attachments (message_id INTEGER, media_uuid TEXT, user_id INTEGER, position INTEGER)');
  $addMedia = static function (string $uuid, string $file, string $name) use ($pdo, $rel): void {
    $pdo->prepare('INSERT INTO stu_console_media VALUES (?, 7, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$uuid, 'audio', $rel($file), $name, 'audio/webm', filesize($file), '', gmdate('Y-m-d H:i:s')]);
  };
  $voiceId = str_repeat('a1', 16);
  $fileId = str_repeat('b2', 16);
  $quietId = str_repeat('c3', 16);
  $addMedia($voiceId, $long, 'Sprachnachricht-2026-11-05_10-00-00.webm');
  $addMedia($fileId, $long, 'besprechung.webm');
  $addMedia($quietId, $quiet, 'Sprachnachricht-2026-11-05_10-05-00.webm');
  vi_test(ember_attach_needs_vision_path($pdo, $voiceId, 7), 'a voice message takes the media call, not the text stream');

  vi_reset();
  $message = '[file:' . $voiceId . ']';
  unset($GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'], $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META']);
  // 0.6.20: the transcript first (one queued call per part, one retry), as an anchor in Mara's call.
  $replies(['Es ist kein Audio zur Analyse vorhanden.', 'Was gibt es', '(keine Sprache)', 'bei dir Neues?']);
  $block = ember_attachment_block($pdo, $message, 7);
  $images = $GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'] ?? [];
  $meta = $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] ?? [];
  // 0.6.18: one check per statement, each showing what Mara was told when it fails.
  $told = trim((string)preg_replace('~\s+~', ' ', $block));
  vi_test($message === '', 'the marker leaves the message');
  vi_test(str_contains($block, 'Das ist eine Stimmsequenz (Laenge etwa 02:30, Minuten:Sekunden)'),
    'Mara is told it is a voice message of about 02:30: ' . $told . ' (ffprobe: ' . var_export(coreui_voice_probe_seconds($long), true) . ' s)');
  vi_test(str_contains($block, 'in 3 Teilen nacheinander'), 'Mara is told it comes in 3 parts: ' . $told);
  vi_test(str_contains($block, 'nur die ersten 02:00 Minuten'), 'Mara is told that 2 minutes are kept: ' . $told);
  vi_test(count($images) === 3 && count($meta) === 1 && $meta[0]['type'] === 'audio' && $meta[0]['voice'] === true && $meta[0]['paths'] === $images,
    'the three WAVs travel in the images field like video frames');
  $clipDir = (string)$meta[0]['dir'];
  vi_test(str_starts_with($clipDir, $frames . '/audio_') && dirname($images[0]) === $clipDir, 'in the frame folder that api/chat.php sends from');
  $sent = $requests();
  vi_test(count($sent) === 4 && $GLOBALS['VI']['acquire'] === 4 && $GLOBALS['VI']['release'] === 4 && $GLOBALS['VI']['max_depth'] === 1,
    'before the answer: one queued transcript call per WAV and one retry');
  foreach ($sent as $i => $call) {
    vi_test($call['think'] === false && $call['messages'][1]['content'] === coreui_voice_transcribe_prompt('German')
      && base64_decode((string)$call['messages'][1]['images'][0], true) === file_get_contents($images[min(2, max(0, $i - 1))]),
      'the transcript hears exactly the WAVs Mara gets');
  }
  vi_test($meta[0]['transcript'] === 'Was gibt es bei dir Neues?', 'the transcript goes with the WAVs into Mara\'s call');
  // 0.6.22: one step with its end; an open step per part was told as "Der Abschluss steht noch aus".
  vi_test($GLOBALS['VI_WORK'] === [['tool', 'running', 'Aufnahme wird mitgeschrieben'], ['tool', 'success', 'Aufnahme mitgeschrieben']],
    'the work path: one transcript step, then its end: ' . json_encode($GLOBALS['VI_WORK']));
  $kept = coreui_voice_transcript_load(7, $voiceId);
  vi_test(($kept['state'] ?? '') === 'done' && $kept['text'] === 'Was gibt es bei dir Neues?' && count($kept['parts']) === 2
    && abs($kept['parts'][1][0] - 103.5) < 0.3, 'kept at once for the history and the line under the message');
  $GLOBALS['VI']['acquire'] = 0;
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  $again = ember_attachment_single_block($pdo, $voiceId, 7, 4000);
  vi_test($requests() === [] && ($GLOBALS['STU_EMBER_AUDIO_CLIPS']['transcript'] ?? '') === 'Was gibt es bei dir Neues?', 'a regenerated answer takes the kept transcript');
  ember_attach_audio_cleanup($GLOBALS['STU_EMBER_AUDIO_CLIPS']['dir'] ?? null);

  // 0.6.22: the account's names and terms reach the transcript (migration 025: column vocabulary).
  vi_test(!coreui_voice_terms_ready($pdo) && ember_attach_voice_terms($pdo, 7) === [], 'no column yet: no terms, no error');
  $pdo->exec('CREATE TABLE stu_user_voice_settings (user_id INTEGER PRIMARY KEY, auto_read INTEGER NOT NULL DEFAULT 0, vocabulary TEXT NULL)');
  $pdo->exec("INSERT INTO stu_user_voice_settings (user_id, auto_read, vocabulary) VALUES (7, 0, 'Bergisch Gladbach\nEVK')");
  vi_test(coreui_voice_terms_ready($pdo) && ember_attach_voice_terms($pdo, 7) === ['Bergisch Gladbach', 'EVK'] && ember_attach_voice_terms($pdo, 8) === [],
    'the terms of account 7, none for another account');
  $termsId = str_repeat('d4', 16);
  $addMedia($termsId, $short, 'Sprachnachricht-2026-11-06_09-00-00.webm');
  vi_reset();
  $GLOBALS['VI_WORK'] = [];
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  $replies(['Ich wohne in Bergisch Gladbach.']);
  ember_attachment_single_block($pdo, $termsId, 7, 4000);
  $sent = $requests();
  vi_test(count($sent) >= 1 && $sent[0]['messages'][1]['content'] === coreui_voice_transcribe_prompt('German', ['Bergisch Gladbach', 'EVK']),
    'the transcript before the answer is asked with the terms');
  ember_attach_audio_cleanup($GLOBALS['STU_EMBER_AUDIO_CLIPS']['dir'] ?? null);
  @unlink((string)coreui_voice_transcript_path(7, $termsId));
  $replies(array_fill(0, 6, 'Ich wohne in Bergisch Gladbach.'));
  vi_test(ember_attach_audio_transcribe_after($pdo, 7, [$termsId]) === 1, 'the transcript after the answer is written');
  $sent = $requests();
  vi_test($sent !== [] && $sent[0]['messages'][1]['content'] === coreui_voice_transcribe_prompt('German', ['Bergisch Gladbach', 'EVK']),
    'the transcript after the answer is asked with the terms too');
  $pdo->exec('DROP TABLE stu_user_voice_settings');
  $pdo->exec('DELETE FROM stu_console_media WHERE uuid = ' . $pdo->quote($termsId));
  @unlink((string)coreui_voice_transcript_path(7, $termsId));
  vi_reset();
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  ember_attach_audio_cleanup($GLOBALS['STU_EMBER_AUDIO_CLIPS']['dir'] ?? null);
  ember_attach_audio_cleanup($base);
  vi_test(is_dir($base), 'cleanup never touches a folder outside the frame folder');
  ember_attach_audio_cleanup($clipDir);
  vi_test(!is_dir($clipDir), 'the WAVs are removed after the call');

  // An uploaded file: the whole file, or what the context window allows (said openly).
  vi_reset();
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  $replies(['Eins.', 'Zwei.', 'Drei.']);
  $note = ember_attachment_single_block($pdo, $fileId, 7, 4000);
  $clips = $GLOBALS['STU_EMBER_AUDIO_CLIPS'] ?? [];
  vi_test(count($requests()) === 3 && $clips['transcript'] === 'Eins. Zwei. Drei.', 'an audio file is transcribed first, too');
  vi_test(str_contains($note, 'Das ist eine Audiodatei') && count($clips['paths'] ?? []) === 3 && empty($clips['truncated']) && $clips['voice'] === false,
    'an audio file of 2:30 is heard completely: ' . trim((string)preg_replace('~\s+~', ' ', $note)));
  ember_attach_audio_cleanup($clips['dir'] ?? null);
  vi_reset();
  $GLOBALS['VI']['ctx'] = 4096;
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  foreach (glob($base . '/cache/7/t-*.json') ?: [] as $file) unlink($file);
  $replies(['Eins.', 'Zwei.']);
  $note = ember_attachment_single_block($pdo, $fileId, 7, 4000);
  vi_test(count($requests()) === 2 && coreui_voice_transcript_load(7, $fileId) === null,
    'a file cut to fit the window is not kept: the job after the answer writes all ten minutes');
  $clips = $GLOBALS['STU_EMBER_AUDIO_CLIPS'] ?? [];
  vi_test(count($clips['paths'] ?? []) === 2 && !empty($clips['truncated']) && str_contains($note, 'nur die ersten 01:05 Minuten'),
    'a small context window: the first 65 s, said openly: ' . trim((string)preg_replace('~\s+~', ' ', $note)));
  ember_attach_audio_cleanup($clips['dir'] ?? null);

  // 0.6.35 (P20.2): a video with sound: frames, then its sound track as WAV, transcribed first.
  vi_test(coreui_voice_has_audio_stream($video) === true && coreui_voice_has_audio_stream($videoMute) === false
    && coreui_voice_has_audio_stream($junk) === null, 'a sound track is found, a mute video has none');
  $addVideo = static function (string $uuid, string $file, string $name) use ($pdo, $rel): void {
    $pdo->prepare('INSERT INTO stu_console_media VALUES (?, 7, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$uuid, 'video', $rel($file), $name, 'video/webm', filesize($file), '', gmdate('Y-m-d H:i:s')]);
  };
  $videoId = str_repeat('e5', 16);
  $muteId = str_repeat('f6', 16);
  $quietVideoId = str_repeat('a7', 16);
  $addVideo($videoId, $video, 'urlaub.webm');
  $addVideo($muteId, $videoMute, 'stumm.webm');
  $addVideo($quietVideoId, $videoQuiet, 'leise.webm');
  vi_reset();
  $GLOBALS['VI_WORK'] = [];
  unset($GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'], $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META']);
  $replies(['Hallo aus dem Urlaub.']);
  $message = '[file:' . $videoId . ']';
  $block = ember_attachment_block($pdo, $message, 7);
  $images = $GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'] ?? [];
  $meta = $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] ?? [];
  $told = trim((string)preg_replace('~\s+~', ' ', $block));
  vi_test(count($meta) === 1 && $meta[0]['type'] === 'video' && is_array($meta[0]['sound']) && count($meta[0]['sound']['paths']) === 1,
    'one video, its sound track as one WAV: ' . json_encode(array_map(static fn($m) => [$m['type'] ?? '', isset($m['sound'])], $meta)));
  $frameCount = count($meta[0]['paths']);
  vi_test($frameCount >= 1 && count($images) === $frameCount + 1 && array_slice($images, 0, $frameCount) === $meta[0]['paths']
    && end($images) === $meta[0]['sound']['paths'][0] && str_ends_with((string)end($images), '.wav'),
    'the frames first, then the sound track, in the same images field');
  vi_test(coreui_wav_gemma_ready(coreui_voice_wav_file_info($meta[0]['sound']['paths'][0])), 'the sound track is a WAV Gemma accepts');
  vi_test(str_contains($block, 'Du hoerst auch die Tonspur: Sie liegt dir nach den Einzelbildern als Audio bei.') && !str_contains($block, 'Ton hast du nicht'),
    'Mara is told she hears the sound track: ' . $told);
  $sent = $requests();
  vi_test(count($sent) === 1 && $sent[0]['think'] === false && base64_decode((string)$sent[0]['messages'][1]['images'][0], true)
    === file_get_contents($meta[0]['sound']['paths'][0]), 'transcribed first, exactly the WAV Mara gets');
  vi_test($meta[0]['sound']['transcript'] === 'Hallo aus dem Urlaub.' && (coreui_voice_transcript_load(7, $videoId)['text'] ?? '') === 'Hallo aus dem Urlaub.',
    'the transcript goes with the sound and is kept for the line under the video');
  vi_test($GLOBALS['VI_WORK'] === [['tool', 'running', 'Tonspur wird mitgeschrieben'], ['tool', 'success', 'Tonspur mitgeschrieben']],
    'the work path names the sound track: ' . json_encode($GLOBALS['VI_WORK']));
  $task = ember_video_sound_task($meta[0]['sound'], (string)$meta[0]['sound_note']);
  vi_test(str_contains($task, 'Du hoerst auch die Tonspur des Videos') && str_contains($task, 'laut Mitschrift: "Hallo aus dem Urlaub."')
    && str_contains($task, 'im Zweifel gilt der Ton') && str_contains($task, 'erfinde keinen Ton') && !str_contains($task, 'Teilen'),
    'Mara\'s task: hear the track, the transcript as an anchor, nothing invented: ' . $task);
  vi_test(str_contains(ember_video_sound_task(['paths' => ['a', 'b'], 'stamps' => ['00:00', '00:58'], 'transcript' => '', 'truncated' => true,
    'seconds' => 120.0], ''), 'in 2 Teilen ab 00:00, 00:58') && str_contains(ember_video_sound_task(['paths' => ['a'], 'transcript' => '',
    'truncated' => true, 'seconds' => 120.0], ''), 'nur die ersten 02:00 Minuten des Tons')
    && str_contains(ember_video_sound_task(['paths' => ['a'], 'transcript' => ''], ''), 'nur Musik oder Geraeusch'),
    'parts with their starts, a cut track and a track without words are said');
  ember_attach_frames_cleanup($meta[0]['dir'] ?? null);
  ember_attach_audio_cleanup($meta[0]['sound']['dir'] ?? null);

  // No track, a silent track, a Core without audio, a guest: the frames as before and the honest note.
  $videoNote = static function (string $uuid) use ($pdo): array {
    unset($GLOBALS['STU_EMBER_VIDEO_FRAMES']);
    $block = ember_attachment_single_block($pdo, $uuid, 7, 4000);
    $fr = $GLOBALS['STU_EMBER_VIDEO_FRAMES'] ?? [];
    ember_attach_frames_cleanup($fr['dir'] ?? null);
    return [$block, array_key_exists('sound', $fr) ? $fr['sound'] : 'missing', (string)($fr['sound_note'] ?? '')];
  };
  vi_reset();
  [$b, $sound, $note] = $videoNote($muteId);
  vi_test($sound === null && $note === 'Das Video hat keine Tonspur.' && str_contains($b, 'Das Video hat keine Tonspur.') && $requests() === [],
    'a mute video: no track, nothing transcribed: ' . $note);
  // 0.6.36: every video ends with a state, so the page stops asking (no line under a mute video).
  vi_test((coreui_voice_transcript_load(7, $muteId)['state'] ?? '') === 'none', 'a mute video ends as "none"');
  vi_test(ember_video_sound_task(null, $note) === 'Das Video hat keine Tonspur.', 'the task names it');
  vi_reset();
  [$b, $sound, $note] = $videoNote($quietVideoId);
  vi_test($sound === null && str_contains($note, 'Die Tonspur ist still') && $requests() === [], 'a silent track: said so, nothing sent: ' . $note);
  vi_test((coreui_voice_transcript_load(7, $quietVideoId)['state'] ?? '') === 'silent', 'a silent track ends as "silent" ("Keine gesprochenen Worte.")');
  vi_reset();
  $GLOBALS['VI']['hears'] = ['completion', 'vision'];
  [$b, $sound, $note] = $videoNote($videoId);
  vi_test($sound === null && str_contains($note, 'Der gewaehlte Core unterstuetzt keine Audioeingabe') && $requests() === [],
    'a Core without audio: the frames, and the honest note about the sound: ' . $note);
  vi_test((coreui_voice_transcript_load(7, $videoId)['state'] ?? '') === 'done', 'a transcript written before stays, also with another Core');
  vi_reset();
  $GLOBALS['VI']['guest'] = true;
  [$b, $sound, $note] = $videoNote($videoId);
  vi_test($sound === null && $note === 'Ton hast du nicht.' && str_contains($b, 'Einzelbilder'), 'guests: frames only, as before');
  // (The frames' cleanup only works under /ember_frames/, a safety net; this test folder is removed at the end.)
  // 0.6.36: the transcription fails: the video ends as "failed", not as an endless "follows".
  $failedVideoId = str_repeat('b8', 16);
  $addVideo($failedVideoId, $video, 'kaputt.webm');
  vi_reset();
  $replies([['status' => 500, 'error' => 'boom']]);
  [$b, $sound, $note] = $videoNote($failedVideoId);
  $requests();
  vi_test(is_array($sound) && (coreui_voice_transcript_load(7, $failedVideoId)['state'] ?? '') === 'failed',
    'a failed transcript: Mara still hears the track, the video ends as "failed"');
  ember_attach_audio_cleanup($sound['dir'] ?? null);
  vi_test(glob($frames . '/audio_*') === [], 'no WAVs of a sound track left behind: ' . json_encode(array_map('basename', glob($frames . '/audio_*') ?: [])));
  // The history: what was said in a video; a video without written sound keeps no line.
  foreach ([[20, $videoId], [21, $muteId]] as [$msg, $uuid]) {
    $pdo->prepare('INSERT INTO stu_console_message_attachments VALUES (?, ?, 7, 0)')->execute([$msg, $uuid]);
  }
  $notes = ember_attach_audio_history_notes($pdo, 7, [20, 21]);
  vi_test(($notes[20] ?? '') === 'Ton des Videos "urlaub.webm": "Hallo aus dem Urlaub."' && !isset($notes[21]),
    'the history knows what was said in the video: ' . json_encode($notes, JSON_UNESCAPED_UNICODE));

  // Guests and Cores without audio: the honest note, nothing prepared, nothing sent.
  vi_reset();
  $GLOBALS['VI']['guest'] = true;
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  vi_test(str_contains(ember_attachment_single_block($pdo, $voiceId, 7, 4000), 'Du kannst sie NICHT anhoeren') && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS'])
    && !ember_attach_needs_vision_path($pdo, $voiceId, 7), 'guests: honest note, no audio');
  vi_reset();
  $GLOBALS['VI']['hears'] = ['completion', 'vision'];
  $unsupportedAudioNote = ember_attachment_single_block($pdo, $voiceId, 7, 4000);
  vi_test(str_contains($unsupportedAudioNote, 'Du kannst sie NICHT anhoeren')
    && str_contains($unsupportedAudioNote, 'Der gewaehlte Core unterstuetzt keine Audioeingabe.')
    && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS'])
    && !ember_attach_needs_vision_path($pdo, $voiceId, 7),
    'a Core without audio: honest note, no audio (note: ' . trim((string)preg_replace('~\s+~', ' ', $unsupportedAudioNote)) . ')');
  vi_reset();
  vi_test(str_contains(ember_attachment_single_block($pdo, $quietId, 7, 4000), 'nur Stille') && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS']),
    'a silent recording is named as such');
  vi_test(glob($frames . '/audio_*') === [], 'no WAVs left behind');

  // After the answer: the transcript for the history, through the queue, with one retry
  // (here for recordings whose transcript before the answer failed).
  foreach (glob($base . '/cache/7/t-*.json') ?: [] as $file) unlink($file);
  vi_reset();
  $replies(['Bitte stellen Sie die Aufnahme zur Verfügung.', 'Erster Teil.', '(keine Sprache)', 'Dritter Teil.']);
  vi_test(ember_attach_audio_transcribe_after($pdo, 7, [$voiceId, 'nichts', $quietId]) === 2, 'two transcripts written (voice and silence)');
  $sent = $requests();
  vi_test(count($sent) === 4 && $GLOBALS['VI']['acquire'] === 4 && $GLOBALS['VI']['release'] === 4 && $GLOBALS['VI']['max_depth'] === 1
    && $GLOBALS['VI']['waits'] === [600, 600, 600, 600], 'one queued call per part and one retry; silence is never sent');
  foreach ($sent as $call) {
    vi_test($call['think'] === false && !isset($call['options']) && $call['messages'][1]['content'] === coreui_voice_transcribe_prompt('German')
      && $call['messages'][0]['content'] === coreui_voice_transcribe_system(), 'transcript: template, neutral SYSTEM text, no thinking');
    $info = coreui_wav_info((string)base64_decode((string)$call['messages'][1]['images'][0], true));
    vi_test(coreui_wav_gemma_ready($info) && $info['seconds'] <= 60.0, 'each transcript part a valid WAV of at most 60 s');
  }
  $done = coreui_voice_transcript_load(7, $voiceId);
  vi_test(is_array($done) && $done['state'] === 'done' && $done['text'] === 'Erster Teil. Dritter Teil.', 'transcript kept for the account');
  vi_test((coreui_voice_transcript_load(7, $quietId)['state'] ?? '') === 'silent', 'a silent recording is marked as such');
  vi_test(ember_attach_audio_transcribe_after($pdo, 7, [$voiceId]) === 0 && $requests() === [], 'written once, then from the cache');
  vi_test(coreui_voice_transcript_load(8, $voiceId) === null, 'another account does not see it');
  coreui_voice_transcript_save(7, $fileId, ['state' => 'pending', 'started' => time() - 2 * COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS]);
  vi_test(coreui_voice_transcript_load(7, $fileId) === null, 'a transcript that never finished is no longer pending');

  // Later turns: what was said, per message.
  foreach ([[10, $voiceId], [11, $fileId], [12, $quietId]] as [$msg, $uuid]) {
    $pdo->prepare('INSERT INTO stu_console_message_attachments VALUES (?, ?, 7, 0)')->execute([$msg, $uuid]);
  }
  $notes = ember_attach_audio_history_notes($pdo, 7, [10, 11, 12, 13]);
  vi_test(($notes[10] ?? '') === 'Stimmsequenz: "Erster Teil. Dritter Teil."' && ($notes[11] ?? '') === '(Audiodatei "besprechung.webm")'
    && ($notes[12] ?? '') === '(Stimmsequenz ohne gesprochene Worte)' && !isset($notes[13]), 'the history knows what was said');
  vi_test(ember_attach_audio_history_notes($pdo, 8, [10, 11]) === [], 'never from another account');

  // 0.6.19: after the transcript Mara decides herself what goes into her long-term memory,
  // with the same reflection as for text; the relationship value follows the spoken words.
  $char = ['id' => 'mara-user-7', 'user_id' => 7];
  $GLOBALS['VI_MEMORY'] = [];
  vi_test(ember_attach_voice_reflect($pdo, $char, 7, [$voiceId], 'Hör mal:', 'Klingt gut, mache ich.')
    && $GLOBALS['VI_MEMORY'] === [
      ['reflect', 7, 'mara-user-7', 'Hör mal: Erster Teil. Dritter Teil.', 'Klingt gut, mache ich.'],
      ['reputation', 7, 'mara-user-7', 'Hör mal: Erster Teil. Dritter Teil.', 'Klingt gut, mache ich.'],
    ], 'a voice message reaches the memory reflection with what was said (and the typed text)');
  $GLOBALS['VI_MEMORY'] = [];
  vi_test(!ember_attach_voice_reflect($pdo, $char, 7, [$fileId], '', 'Antwort.') && !ember_attach_voice_reflect($pdo, $char, 7, [$voiceId, $fileId], '', 'Antwort.')
    && !ember_attach_voice_reflect($pdo, $char, 7, [$voiceId, str_repeat('d4', 16)], '', 'Antwort.') && $GLOBALS['VI_MEMORY'] === [],
    'an uploaded audio file or a mix with other attachments is not the sender\'s words (like an image)');
  vi_test(!ember_attach_voice_reflect($pdo, $char, 7, [$quietId], '', 'Antwort.') && !ember_attach_voice_reflect($pdo, $char, 7, [$voiceId], '', 'FEHLER: nichts')
    && !ember_attach_voice_reflect($pdo, $char, 8, [$voiceId], '', 'Antwort.') && $GLOBALS['VI_MEMORY'] === [],
    'nothing said, a failed answer or another account: nothing reflected');
  coreui_voice_transcript_save(7, $quietId, ['state' => 'done', 'text' => 'Ja.']);
  vi_test(ember_attach_voice_reflect($pdo, $char, 7, [$quietId], '', 'Gut.') && $GLOBALS['VI_MEMORY'] === [['reputation', 7, 'mara-user-7', 'Ja.', 'Gut.']],
    'a short "Ja." is no memory, as with text; the relationship value still counts it');
  coreui_voice_transcript_save(7, $quietId, ['state' => 'silent']);

  // The transcript itself: errors, cancel, full queue, outside the queue.
  vi_reset();
  $replies(['Es ist kein Audio zur Analyse vorhanden.', 'Bitte stellen Sie die Aufnahme zur Verfügung.']);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'missed' && count($requests()) === 2, 'one retry only, then "not understood"');
  vi_reset();
  $replies([['status' => 500, 'error' => 'this model does not support audio input']]);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'core_without_audio' && $GLOBALS['VI']['release'] === 1, 'a Core without audio is named');
  $requests();
  vi_reset();
  $replies(['Hello there.']);
  $result = coreui_voice_transcribe_file($short, $options(['language' => 'en', 'num_ctx' => 8192, 'keep_alive' => -1]));
  $sent = $requests();
  vi_test($result['ok'] && $result['text'] === 'Hello there.' && str_contains($sent[0]['messages'][1]['content'], 'in English into English text')
    && $sent[0]['options'] === ['num_ctx' => 8192], 'an English account gets the English template, the KI-Profil window stays');
  vi_reset();
  $GLOBALS['VI']['refuse'] = true;
  vi_test(coreui_voice_transcribe_file($short, $options())['error'] === 'queue_timeout' && $requests() === [], 'full queue: nothing sent');
  vi_reset();
  $GLOBALS['VI']['cancel'] = true;
  vi_test(coreui_voice_transcribe_file($short, $options())['error'] === 'cancelled' && $requests() === [], 'a cancelled run sends nothing');
  $probe = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($root . '/api/voice_transcribe.php', true) . ';'
    . ' echo coreui_voice_transcribe_part("http://127.0.0.1:1/api/chat", "m", "x", "German", 5)["error"];');
  vi_test(trim((string)shell_exec($probe)) === 'queue_unavailable', 'no Core call outside the model queue');
  vi_test($workdirs() === $before, 'no temporary files left');
} finally {
  proc_terminate($server);
  proc_close($server);
  exec('rm -rf ' . escapeshellarg($base) . ' ' . escapeshellarg($frames));
}
echo "CoreAI voice input: Mara hears voice messages and audio files herself (WAVs of at most 60 s in her own call, no thinking),"
  . " guests and Cores without audio get the honest note, audio-only WebM is audio, the transcript after the answer"
  . " (queue, retry, silence, cache), the history and Mara's own memory reflection of what was said;"
  . " videos with their sound track after the frames, transcribed first, honest without track, silent, without audio Core or as guest OK\n";
