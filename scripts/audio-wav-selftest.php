<?php
declare(strict_types=1);
// CoreAI 0.5.73 (P20): WAV check for Gemma 4 audio input and the P20.0
// media test. Offline: headers are built in memory, no Ollama, no ffmpeg.
// 0.5.74: helpers for audio turns (api/voice_core.php) and the extended test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/audio_wav.php';
require_once $root . '/api/voice_core.php';

function wav_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Audio WAV FAIL: {$why}\n"); exit(1); }
}

/** A RIFF/WAVE file with optional extra chunk and a given data length field. */
function wav_build(int $code, int $channels, int $rate, int $bits, int $samples,
                   bool $extensible = false, string $extra = '', ?int $dataField = null): string {
  $align = $channels * intdiv($bits, 8);
  $fmt = pack('vvVVvv', $extensible ? 0xFFFE : $code, $channels, $rate, $rate * $align, $align, $bits);
  if ($extensible) {
    // cbSize, valid bits, channel mask, sub-format GUID (first two bytes = format code).
    $fmt .= pack('vvV', 22, $bits, 4) . pack('v', $code) . "\x00\x00\x00\x00\x10\x00\x80\x00\x00\xAA\x00\x38\x9B\x71";
  }
  $data = str_repeat("\x00", $samples * $align);
  $body = 'WAVE' . 'fmt ' . pack('V', strlen($fmt)) . $fmt . $extra
    . 'data' . pack('V', $dataField ?? strlen($data)) . $data;
  return 'RIFF' . pack('V', strlen($body)) . $body;
}

$ok = coreui_wav_info(wav_build(1, 1, 16000, 16, 16000 * 3));
wav_test(is_array($ok) && $ok['seconds'] === 3.0 && coreui_wav_gemma_ready($ok)
  && coreui_wav_reject_reason($ok) === '', '16-bit PCM, mono, 16 kHz, 3 s is accepted');

$cases = [
  '24 Bit statt 16 Bit' => wav_build(1, 1, 16000, 24, 1600, true),
  'Gleitkomma statt 16-Bit-PCM' => wav_build(3, 1, 16000, 32, 1600, true),
  '44100 Hz statt 16000 Hz' => wav_build(1, 1, 44100, 16, 4410),
  '2 Kanaele statt Mono' => wav_build(1, 2, 16000, 16, 1600),
  'kein PCM' => wav_build(6, 1, 16000, 8, 1600),
];
foreach ($cases as $reason => $bytes) {
  $info = coreui_wav_info($bytes);
  wav_test(!coreui_wav_gemma_ready($info) && coreui_wav_reject_reason($info) === $reason, 'rejected: ' . $reason);
}
wav_test(coreui_wav_info(str_repeat("\x01\x00", 8000)) === null
  && coreui_wav_reject_reason(null) === 'kein WAV mit RIFF-Header', 'raw PCM without RIFF header is refused');
wav_test(coreui_wav_info('RIFF') === null && coreui_wav_info('') === null, 'truncated input');

// ffmpeg writing to a pipe leaves the data length open (0xFFFFFFFF), and a
// LIST chunk with odd size (padding byte) may sit before the data.
$streamed = coreui_wav_info(wav_build(1, 1, 16000, 16, 16000, false, '', 0xFFFFFFFF));
wav_test(is_array($streamed) && $streamed['seconds'] === 1.0 && coreui_wav_gemma_ready($streamed), 'open data length');
$list = coreui_wav_info(wav_build(1, 1, 16000, 16, 8000, false, 'LIST' . pack('V', 5) . "INFOx\x00"));
wav_test(is_array($list) && $list['seconds'] === 0.5 && coreui_wav_gemma_ready($list), 'odd chunk with padding before data');
wav_test(coreui_wav_ffmpeg_args() === ['-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-f', 'wav'],
  'ffmpeg produces exactly mono, 16 kHz, 16-bit PCM WAV');

// P20.0 media test: queued like a chat turn, parameter-free, read-only.
$spike = (string)file_get_contents($root . '/scripts/media-spike.php');
wav_test(str_contains($spike, 'ember_global_lock_acquire(') && str_contains($spike, 'ember_generation_lock_release($lock)'),
  'every Core call of the media test waits in Mara\'s queue');
wav_test(!preg_match("~'options'\\s*=>~", $spike) && str_contains($spike, "'think'=>false"),
  'no Ollama options are sent (parameter-free Gemma policy)');
wav_test(!preg_match('~/api/(?:create|delete|pull|copy|push)\b~', $spike), 'the media test never changes models');
wav_test(str_contains($spike, 'coreui_wav_ffmpeg_args()') && str_contains($spike, 'coreui_wav_reject_reason('),
  'test audio uses the same format and check as the later product');
$shell = $root . '/scripts/media-spike.sh';
exec('bash -n ' . escapeshellarg($shell) . ' 2>&1', $lines, $status);
wav_test($status === 0, 'media-spike.sh has valid bash syntax: ' . implode(' ', $lines));
$sh = (string)file_get_contents($shell);
wav_test(str_contains($sh, 'exec -T php php scripts/media-spike.php') && str_contains($sh, "IFS='|' read -r wall rss rc"),
  'starter runs the PHP part in the php container and keeps empty timing fields');

// 0.5.74: audio turns. The 5 s miss from the server run is recognised,
// real transcripts (also from the same run) and silence are not.
wav_test(coreui_voice_attachment_hint(4.6) === '[Angehaengt: Sprachaufnahme, 5 Sekunden]'
  && coreui_voice_attachment_hint(0.4) === '[Angehaengt: Sprachaufnahme, 1 Sekunde]', 'attachment hint names the length');
foreach ([
  'Bitte stellen Sie die Aufnahme zur Verfügung. Ich kann die Transkription erst erstellen, wenn ich den Audioinhalt habe.',
  'Es wurde keine Audiodatei angehängt.',
  'Ich kann die Aufnahme leider nicht abspielen.',
  'Ich habe keinen Zugriff auf Audio.',
  'Please provide the audio recording.',
  "I can't listen to audio files.",
] as $missed) wav_test(coreui_voice_missed_audio($missed), 'missed audio recognised: ' . $missed);
foreach ([
  'Hey, sei fair, es ist nicht sauber genug. Haben Sie eigentlich Handtücher dabei? Ja. Kopfkissenbezüge? Natürlich.',
  'Wussten Sie, dass die meisten ins Hotel gehen, damit sie das klauen können? Das ist mein Fussel-Reservat.',
  '(keine Sprache)',
  'Okay, okay, schon gut.',
  '',
] as $heard) wav_test(!coreui_voice_missed_audio($heard), 'a transcript is not a miss: ' . $heard);
wav_test(!coreui_voice_missed_audio(str_repeat('Bitte stellen Sie die Aufnahme zur Verfügung. ', 20)),
  'long answers are transcripts, not a refusal');
$persona = "<|think|>\nDu bist Mara, die TCI der Ravager Thorn.\nDu sprichst Deutsch.";
wav_test(coreui_voice_modelfile_system(['system'=>$persona]) === $persona, 'SYSTEM from the /api/show field');
wav_test(coreui_voice_modelfile_system(['modelfile'=>"FROM gemma4:e4b\nSYSTEM \"\"\"{$persona}\"\"\"\nPARAMETER x 1\n"]) === $persona,
  'SYSTEM from a Modelfile block');
wav_test(coreui_voice_modelfile_system(['modelfile'=>"FROM gemma4:e4b\n"]) === '', 'no SYSTEM');
wav_test(coreui_voice_system_without_think($persona) === "Du bist Mara, die TCI der Ravager Thorn.\nDu sprichst Deutsch.",
  'only the thinking switch is removed, the persona stays word for word');

// 0.5.74 media test: CoreAI's keep_alive, streamed answers with the time to
// the first word, the thinking comparison only reads the Modelfile.
wav_test(str_contains($spike, '$keepAlive = ember_keep_alive();') && !str_contains($spike, "'15m'"),
  'the media test keeps CoreAI\'s keep_alive instead of its own');
wav_test(str_contains($spike, "'stream'=>true") && str_contains($spike, "'first_answer_s'=>") && str_contains($spike, "'first_sentence_s'=>"),
  'time to the first answer word and sentence is measured');
wav_test(str_contains($spike, 'coreui_voice_system_without_think($system)') && str_contains($spike, 'coreui_voice_missed_audio($r[\'text\'])')
  && str_contains($spike, 'coreui_voice_attachment_hint($sec)'), 'thinking comparison and short clips use the shared helpers');
wav_test(str_contains($sh, '--short "$SHORT" --repeat "$REPEAT"'), 'starter passes the short clip options');

echo "CoreAI audio WAV check, audio turn helpers and P20.0 media test contract OK\n";
