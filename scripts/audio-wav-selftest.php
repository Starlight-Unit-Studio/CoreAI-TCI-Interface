<?php
declare(strict_types=1);
// CoreAI 0.5.73 (P20): WAV check for Gemma 4 audio input and the P20.0
// media test. Offline: headers are built in memory, no Ollama, no ffmpeg.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/audio_wav.php';

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

echo "CoreAI audio WAV check and P20.0 media test contract OK\n";
