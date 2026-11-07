<?php
declare(strict_types=1);

/*
 * 0.6.37 (P20.5 A): image test results for Admin Core > Betrieb > Bildproben.
 *
 * scripts/image-spike.sh writes one folder per run under var/image-spike/runs (named like
 * 20261107-101500) with report.json and the PNG images. Only the Inhaber reads them. An
 * image is served only when its run's report lists it, its real path stays inside that run
 * folder and the file really is a PNG.
 */

const COREUI_IMAGE_SPIKE_STAMP_RE = '/^[0-9]{8}-[0-9]{6}$/';
const COREUI_IMAGE_SPIKE_FILE_RE = '/^[a-z0-9][a-z0-9_-]{0,60}-[0-9]{3,4}x[0-9]{3,4}-p[0-9]{1,2}\.png$/';
const COREUI_IMAGE_SPIKE_REPORT_MAX = 2097152;

function coreui_image_spike_root(): string {
  return dirname(__DIR__) . '/var/image-spike/runs';
}

/** The report of one run, or null when the run or its report is missing or broken. */
function coreui_image_spike_report(string $root, string $stamp): ?array {
  if (!preg_match(COREUI_IMAGE_SPIKE_STAMP_RE, $stamp)) return null;
  $file = $root . '/' . $stamp . '/report.json';
  if (!is_file($file) || filesize($file) > COREUI_IMAGE_SPIKE_REPORT_MAX) return null;
  $data = json_decode((string)@file_get_contents($file), true);
  if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['groups'] ?? null)) return null;
  return $data;
}

/** Runs with a readable report, newest first. */
function coreui_image_spike_runs(string $root, int $limit = 12): array {
  if (!is_dir($root)) return [];
  $stamps = [];
  foreach ((array)@scandir($root) as $name) {
    if (is_string($name) && preg_match(COREUI_IMAGE_SPIKE_STAMP_RE, $name) && is_dir($root . '/' . $name)) $stamps[] = $name;
  }
  rsort($stamps, SORT_STRING);
  $out = [];
  foreach ($stamps as $stamp) {
    $report = coreui_image_spike_report($root, $stamp);
    if ($report === null) continue;
    $images = 0;
    $made = 0;
    foreach ($report['groups'] as $group) {
      foreach ((array)($group['images'] ?? []) as $image) {
        $images++;
        if (is_array($image) && !empty($image['ok'])) $made++;
      }
    }
    $out[] = ['stamp' => $stamp, 'created_at' => (string)($report['created_at'] ?? ''), 'images' => $images, 'made' => $made,
      'errors' => is_array($report['errors'] ?? null) ? count($report['errors']) : 0];
    if (count($out) >= $limit) break;
  }
  return $out;
}

function coreui_image_spike_num($value): ?float {
  return is_int($value) || is_float($value) ? round((float)$value, 2) : null;
}

function coreui_image_spike_text($value, int $max = 400): string {
  $text = is_scalar($value) ? trim((string)$value) : '';
  return mb_substr($text, 0, $max);
}

/** What Admin Core shows: known keys only, numbers as numbers, text cut to size. */
function coreui_image_spike_public(array $report): array {
  $prompts = [];
  foreach ((array)($report['prompts'] ?? []) as $p) {
    if (!is_array($p) || !preg_match('/^p[0-9]{1,2}$/', (string)($p['id'] ?? ''))) continue;
    $prompts[] = ['id' => (string)$p['id'], 'topic' => coreui_image_spike_text($p['topic'] ?? '', 40),
      'text' => coreui_image_spike_text($p['text'] ?? '', 600), 'seed' => is_int($p['seed'] ?? null) ? $p['seed'] : null];
  }
  $groups = [];
  foreach ($report['groups'] as $g) {
    if (!is_array($g) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', (string)($g['id'] ?? ''))) continue;
    $images = [];
    foreach ((array)($g['images'] ?? []) as $i) {
      if (!is_array($i) || !preg_match('/^[0-9]{3,4}x[0-9]{3,4}$/', (string)($i['size'] ?? ''))) continue;
      $file = (string)($i['file'] ?? '');
      $images[] = [
        'prompt' => coreui_image_spike_text($i['prompt'] ?? '', 4), 'size' => (string)$i['size'],
        'file' => !empty($i['ok']) && preg_match(COREUI_IMAGE_SPIKE_FILE_RE, $file) ? $file : '',
        'ok' => !empty($i['ok']), 'error' => coreui_image_spike_text($i['error'] ?? '', 300),
        'wall_s' => coreui_image_spike_num($i['wall_s'] ?? null), 'load_s' => coreui_image_spike_num($i['load_s'] ?? null),
        'text_s' => coreui_image_spike_num($i['text_s'] ?? null), 'sampling_s' => coreui_image_spike_num($i['sampling_s'] ?? null),
        'decode_s' => coreui_image_spike_num($i['decode_s'] ?? null), 'peak_rss_mb' => coreui_image_spike_num($i['peak_rss_mb'] ?? null),
      ];
    }
    $files = [];
    foreach ((array)($g['files'] ?? []) as $f) {
      if (!is_array($f)) continue;
      $files[] = ['role' => coreui_image_spike_text($f['role'] ?? '', 20), 'repo' => coreui_image_spike_text($f['repo'] ?? '', 120),
        'path' => coreui_image_spike_text($f['path'] ?? '', 160), 'revision' => coreui_image_spike_text($f['revision'] ?? '', 64),
        'sha256' => coreui_image_spike_text($f['sha256'] ?? '', 64), 'license' => coreui_image_spike_text($f['license'] ?? '', 40),
        'size' => is_int($f['size'] ?? null) ? $f['size'] : null];
    }
    $groups[] = [
      'id' => (string)$g['id'], 'label' => coreui_image_spike_text($g['label'] ?? '', 80), 'origin' => coreui_image_spike_text($g['origin'] ?? '', 200),
      'license' => coreui_image_spike_text($g['license'] ?? '', 40), 'steps' => is_int($g['steps'] ?? null) ? $g['steps'] : null,
      'threads' => is_int($g['threads'] ?? null) ? $g['threads'] : null, 'ok' => !empty($g['ok']),
      'error' => coreui_image_spike_text($g['error'] ?? '', 300), 'images' => $images, 'files' => $files,
    ];
  }
  $runtime = is_array($report['runtime'] ?? null) ? $report['runtime'] : [];
  return [
    'stamp' => coreui_image_spike_text($report['stamp'] ?? '', 15), 'created_at' => coreui_image_spike_text($report['created_at'] ?? '', 40),
    'host' => coreui_image_spike_text($report['host'] ?? '', 2000),
    'runtime' => ['tag' => coreui_image_spike_text($runtime['tag'] ?? '', 60), 'commit' => coreui_image_spike_text($runtime['commit'] ?? '', 40),
      'license' => coreui_image_spike_text($runtime['license'] ?? '', 80)],
    'prompts' => $prompts, 'groups' => $groups, 'errors' => count((array)($report['errors'] ?? [])),
  ];
}

/** Real path of a listed PNG inside its run folder, or null. */
function coreui_image_spike_image_path(string $root, string $stamp, string $file): ?string {
  if (!preg_match(COREUI_IMAGE_SPIKE_STAMP_RE, $stamp) || !preg_match(COREUI_IMAGE_SPIKE_FILE_RE, $file)) return null;
  $report = coreui_image_spike_report($root, $stamp);
  if ($report === null) return null;
  $listed = false;
  foreach ($report['groups'] as $group) {
    foreach ((array)($group['images'] ?? []) as $image) {
      if (is_array($image) && !empty($image['ok']) && ($image['file'] ?? null) === $file) { $listed = true; break 2; }
    }
  }
  if (!$listed) return null;
  $dir = realpath($root . '/' . $stamp);
  $path = realpath($root . '/' . $stamp . '/' . $file);
  if ($dir === false || $path === false || !is_file($path) || dirname($path) !== $dir) return null;
  return (string)@file_get_contents($path, false, null, 0, 8) === "\x89PNG\r\n\x1a\n" ? $path : null;
}
