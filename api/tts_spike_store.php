<?php
declare(strict_types=1);

/*
 * 0.5.94: voice test results (P20.0) for Admin Core > Betrieb > Stimmproben.
 *
 * scripts/tts-spike.sh writes one folder per run under var/tts-spike/runs
 * (named like 20261104-153000) with report.json and the WAV samples. Only the
 * Inhaber reads them. A sample is served only when its run's report lists it
 * and its real path stays inside that run folder.
 */

const COREUI_TTS_SPIKE_STAMP_RE = '/^[0-9]{8}-[0-9]{6}$/';
// 0.6.6: s1..s99 German, e1..e99 English test sentences.
const COREUI_TTS_SPIKE_FILE_RE = '/^[a-z0-9][a-z0-9_-]{0,60}-[0-9]{1,2}c-[se][0-9]{1,2}\.wav$/';
const COREUI_TTS_SPIKE_REPORT_MAX = 2097152;

function coreui_tts_spike_root(): string {
  return dirname(__DIR__) . '/var/tts-spike/runs';
}

/** The report of one run, or null when the run or its report is missing or broken. */
function coreui_tts_spike_report(string $root, string $stamp): ?array {
  if (!preg_match(COREUI_TTS_SPIKE_STAMP_RE, $stamp)) return null;
  $file = $root . '/' . $stamp . '/report.json';
  if (!is_file($file) || filesize($file) > COREUI_TTS_SPIKE_REPORT_MAX) return null;
  $data = json_decode((string)@file_get_contents($file), true);
  if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['voices'] ?? null)) return null;
  return $data;
}

/** Runs with a readable report, newest first. */
function coreui_tts_spike_runs(string $root, int $limit = 12): array {
  if (!is_dir($root)) return [];
  $stamps = [];
  foreach ((array)@scandir($root) as $name) {
    if (is_string($name) && preg_match(COREUI_TTS_SPIKE_STAMP_RE, $name) && is_dir($root . '/' . $name)) $stamps[] = $name;
  }
  rsort($stamps, SORT_STRING);
  $out = [];
  foreach ($stamps as $stamp) {
    $report = coreui_tts_spike_report($root, $stamp);
    if ($report === null) continue;
    $voices = $report['voices'];
    $out[] = [
      'stamp' => $stamp,
      'created_at' => (string)($report['created_at'] ?? ''),
      'voices' => count($voices),
      'spoken' => count(array_filter($voices, static fn($v): bool => is_array($v) && !empty($v['ok']))),
      'errors' => is_array($report['errors'] ?? null) ? count($report['errors']) : 0,
    ];
    if (count($out) >= $limit) break;
  }
  return $out;
}

function coreui_tts_spike_num($value): ?float {
  return is_int($value) || is_float($value) ? round((float)$value, 3) : null;
}

function coreui_tts_spike_text($value, int $max = 400): string {
  $text = is_scalar($value) ? trim((string)$value) : '';
  return mb_substr($text, 0, $max);
}

/** What Admin Core shows: known keys only, numbers as numbers, text cut to size. */
function coreui_tts_spike_public(array $report): array {
  $sentences = [];
  foreach ((array)($report['sentences'] ?? []) as $s) {
    if (!is_array($s) || !preg_match('/^[se][0-9]{1,2}$/', (string)($s['id'] ?? ''))) continue;
    $sentences[] = ['id' => (string)$s['id'], 'topic' => coreui_tts_spike_text($s['topic'] ?? '', 80), 'text' => coreui_tts_spike_text($s['text'] ?? '', 600),
      'language' => ($s['language'] ?? '') === 'en' ? 'en' : 'de',
      // 0.5.98: what the voice really got after the German text preparation.
      'spoken' => coreui_tts_spike_text($s['spoken'] ?? ($s['text'] ?? ''), 900)];
  }
  $voices = [];
  foreach ($report['voices'] as $v) {
    if (!is_array($v) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', (string)($v['id'] ?? ''))) continue;
    $samples = [];
    foreach ((array)($v['samples'] ?? []) as $s) {
      if (!is_array($s) || !preg_match(COREUI_TTS_SPIKE_FILE_RE, (string)($s['file'] ?? ''))) continue;
      $samples[] = [
        'sentence' => coreui_tts_spike_text($s['sentence'] ?? '', 4), 'file' => (string)$s['file'],
        'synth_s' => coreui_tts_spike_num($s['synth_s'] ?? null), 'audio_s' => coreui_tts_spike_num($s['audio_s'] ?? null),
        'rtf' => coreui_tts_spike_num($s['rtf'] ?? null), 'first_audio_s' => coreui_tts_spike_num($s['first_audio_s'] ?? null),
      ];
    }
    $voices[] = [
      'id' => (string)$v['id'], 'group' => coreui_tts_spike_text($v['group'] ?? '', 64), 'engine' => coreui_tts_spike_text($v['engine'] ?? '', 32),
      'language' => ($v['language'] ?? '') === 'en' ? 'en' : 'de',
      'label' => coreui_tts_spike_text($v['label'] ?? '', 80), 'model' => coreui_tts_spike_text($v['model'] ?? '', 120),
      'gender' => coreui_tts_spike_text($v['gender'] ?? '', 20), 'status' => ($v['status'] ?? '') === 'frei' ? 'frei' : 'pruefen',
      'voice_license' => coreui_tts_spike_text($v['voice_license'] ?? ''), 'weights_license' => coreui_tts_spike_text($v['weights_license'] ?? ''),
      'code_license' => coreui_tts_spike_text($v['code_license'] ?? '', 80), 'origin' => coreui_tts_spike_text($v['origin'] ?? ''),
      'note' => coreui_tts_spike_text($v['note'] ?? ''), 'ok' => !empty($v['ok']), 'error' => coreui_tts_spike_text($v['error'] ?? ''),
      'cores' => is_int($v['cores'] ?? null) ? $v['cores'] : null, 'load_s' => coreui_tts_spike_num($v['load_s'] ?? null),
      'peak_rss_mb' => coreui_tts_spike_num($v['peak_rss_mb'] ?? null), 'rtf_median' => coreui_tts_spike_num($v['rtf_median'] ?? null),
      'first_audio_median_s' => coreui_tts_spike_num($v['first_audio_median_s'] ?? null), 'samples' => $samples,
    ];
  }
  $groups = [];
  foreach ((array)($report['groups'] ?? []) as $g) {
    if (!is_array($g)) continue;
    $runs = [];
    foreach ((array)($g['runs'] ?? []) as $r) {
      if (!is_array($r) || !is_int($r['cores'] ?? null)) continue;
      $runs[] = [
        'cores' => $r['cores'], 'ok' => !empty($r['ok']), 'error' => coreui_tts_spike_text($r['error'] ?? ''),
        'load_s' => coreui_tts_spike_num($r['load_s'] ?? null), 'warmup_s' => coreui_tts_spike_num($r['warmup_s'] ?? null),
        'peak_rss_mb' => coreui_tts_spike_num($r['peak_rss_mb'] ?? null), 'rtf_median' => coreui_tts_spike_num($r['rtf_median'] ?? null),
        'first_audio_median_s' => coreui_tts_spike_num($r['first_audio_median_s'] ?? null),
      ];
    }
    $groups[] = [
      'id' => coreui_tts_spike_text($g['id'] ?? '', 64), 'label' => coreui_tts_spike_text($g['label'] ?? '', 120),
      'engine_label' => coreui_tts_spike_text($g['engine_label'] ?? '', 80), 'package' => coreui_tts_spike_text($g['package'] ?? '', 80),
      'code_license' => coreui_tts_spike_text($g['code_license'] ?? '', 80), 'code_note' => coreui_tts_spike_text($g['code_note'] ?? ''),
      'files' => count((array)($g['files'] ?? [])), 'runs' => $runs,
    ];
  }
  return [
    'stamp' => coreui_tts_spike_text($report['stamp'] ?? '', 15), 'created_at' => coreui_tts_spike_text($report['created_at'] ?? '', 40),
    'sample_cores' => is_int($report['sample_cores'] ?? null) ? $report['sample_cores'] : null,
    'sentences' => $sentences, 'voices' => $voices, 'groups' => $groups,
    'errors' => count((array)($report['errors'] ?? [])),
  ];
}

/** Real path of a listed sample inside its run folder, or null. */
function coreui_tts_spike_sample_path(string $root, string $stamp, string $file): ?string {
  if (!preg_match(COREUI_TTS_SPIKE_STAMP_RE, $stamp) || !preg_match(COREUI_TTS_SPIKE_FILE_RE, $file)) return null;
  $report = coreui_tts_spike_report($root, $stamp);
  if ($report === null) return null;
  $listed = false;
  foreach ($report['voices'] as $voice) {
    foreach ((array)($voice['samples'] ?? []) as $sample) {
      if (is_array($sample) && ($sample['file'] ?? null) === $file) { $listed = true; break 2; }
    }
  }
  if (!$listed) return null;
  $dir = realpath($root . '/' . $stamp);
  $path = realpath($root . '/' . $stamp . '/' . $file);
  if ($dir === false || $path === false || !is_file($path)) return null;
  if (dirname($path) !== $dir) return null;
  $head = (string)@file_get_contents($path, false, null, 0, 12);
  if (strlen($head) !== 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') return null;
  return $path;
}
