<?php
declare(strict_types=1);

/*
 * CoreAI 0.5.73 (P20): WAV header check for Gemma 4 audio input.
 *
 * Ollama passes audio through the images array and only understands WAV
 * with a full RIFF header, mono, exactly 16 kHz and 16-bit signed PCM. Other
 * bit depths abort or produce noise, raw PCM is not recognised. Every file
 * is checked here before it is sent; nothing is converted in this file.
 */

/** Parsed header of a RIFF/WAVE file, or null when it is not one. */
function coreui_wav_info(string $bytes): ?array {
  if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') return null;
  $offset = 12;
  $format = null;
  $dataBytes = null;
  $length = strlen($bytes);
  while ($offset + 8 <= $length) {
    $id = substr($bytes, $offset, 4);
    $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
    $body = $offset + 8;
    if ($id === 'fmt ' && $size >= 16 && $body + 16 <= $length) {
      $f = unpack('vcode/vchannels/Vrate/Vbyterate/valign/vbits', substr($bytes, $body, 16));
      $code = (int)$f['code'];
      // WAVE_FORMAT_EXTENSIBLE carries the real format in its sub-format GUID.
      if ($code === 0xFFFE && $size >= 40 && $body + 26 <= $length) {
        $code = unpack('v', substr($bytes, $body + 24, 2))[1];
      }
      $format = ['code'=>$code, 'channels'=>(int)$f['channels'], 'sample_rate'=>(int)$f['rate'],
        'bits'=>(int)$f['bits'], 'block_align'=>(int)$f['align']];
    } elseif ($id === 'data') {
      // Streams written without a known length use 0 or 0xFFFFFFFF here.
      $dataBytes = ($size === 0 || $size === 0xFFFFFFFF || $body + $size > $length) ? $length - $body : $size;
      break;
    }
    $offset = $body + $size + ($size % 2);
  }
  if ($format === null || $dataBytes === null) return null;
  $bytesPerSecond = $format['sample_rate'] * max(1, $format['block_align']);
  return $format + [
    'data_bytes'=>$dataBytes,
    'seconds'=>$bytesPerSecond > 0 ? round($dataBytes / $bytesPerSecond, 3) : 0.0,
  ];
}

/** True when Gemma 4 audio input accepts the header: PCM, mono, 16 kHz, 16 bit. */
function coreui_wav_gemma_ready(?array $info): bool {
  return is_array($info) && $info['code'] === 1 && $info['channels'] === 1
    && $info['sample_rate'] === 16000 && $info['bits'] === 16;
}

/** Short German reason why a file is not accepted (empty when it is). */
function coreui_wav_reject_reason(?array $info): string {
  if (!is_array($info)) return 'kein WAV mit RIFF-Header';
  if ($info['code'] !== 1) return $info['code'] === 3 ? 'Gleitkomma statt 16-Bit-PCM' : 'kein PCM';
  if ($info['bits'] !== 16) return $info['bits'] . ' Bit statt 16 Bit';
  if ($info['sample_rate'] !== 16000) return $info['sample_rate'] . ' Hz statt 16000 Hz';
  if ($info['channels'] !== 1) return $info['channels'] . ' Kanaele statt Mono';
  return '';
}

/** ffmpeg arguments that produce exactly the accepted format. */
function coreui_wav_ffmpeg_args(): array {
  return ['-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-f', 'wav'];
}
