<?php
declare(strict_types=1);
// CoreAI 0.5.94: Admin Core > Betrieb > Stimmproben on the PHP side. Offline:
// runs listed newest first (only time stamp folders with a readable report),
// the public shape (known keys, numbers, cut text), and a sample served only
// when its run lists it and it really is a WAV inside that run folder. Plus
// the wiring: Inhaber only, var/ closed in nginx, the card and its script.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/tts_spike_store.php';

function tts_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "TTS store FAIL: {$why}\n"); exit(1); }
}

$base = sys_get_temp_dir() . '/coreai-tts-store-' . bin2hex(random_bytes(4));
$runs = $base . '/runs';
mkdir($runs, 0700, true);
$wav = static function (string $file): void {
  $data = str_repeat("\0\0", 1600);
  file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($data)) . $data);
};
$report = static fn(string $stamp, array $voices, array $extra = []): array => array_merge([
  'format' => 1, 'stamp' => $stamp, 'created_at' => '2026-11-04T12:00:00+00:00', 'sample_cores' => 4,
  'sentences' => [['id' => 's1', 'topic' => 'Begrüßung', 'text' => 'Hallo, ich bin Mara.'], ['id' => 'bad', 'topic' => 'x', 'text' => 'y']],
  'voices' => $voices, 'groups' => [['id' => 'pocket-de', 'label' => 'Pocket TTS Deutsch', 'engine_label' => 'Pocket TTS', 'package' => 'pocket-tts==4.0.1',
    'code_license' => 'MIT', 'code_note' => '', 'files' => [['path' => 'a', 'sha256' => str_repeat('a', 64)]],
    'runs' => [['cores' => 4, 'ok' => true, 'error' => '', 'load_s' => 1.23456, 'warmup_s' => 0.5, 'peak_rss_mb' => 900.4, 'rtf_median' => 0.21,
      'first_audio_median_s' => 0.18], ['cores' => 'zwei', 'ok' => true]]]],
  'errors' => [['group' => 'chatterbox', 'cores' => 4, 'voice' => '', 'error' => 'x']],
], $extra);
$voice = static fn(string $id, array $samples, bool $ok = true): array => [
  'id' => $id, 'group' => 'pocket-de', 'engine' => 'pocket', 'label' => 'Alba', 'model' => 'Pocket TTS Deutsch', 'gender' => 'weiblich',
  'status' => 'frei', 'voice_license' => 'CC BY 4.0', 'weights_license' => 'CC BY 4.0', 'code_license' => 'MIT', 'origin' => 'kyutai/tts-voices',
  'note' => '', 'cores' => 4, 'ok' => $ok, 'error' => $ok ? '' : 'Fehler', 'load_s' => 1.2, 'peak_rss_mb' => 900.0, 'rtf_median' => 0.2,
  'first_audio_median_s' => 0.15, 'samples' => $samples, 'secret' => 'nicht weitergeben',
];
$sample = static fn(string $file): array => ['sentence' => 's1', 'file' => $file, 'synth_s' => 0.4, 'audio_s' => 2.0, 'rtf' => 0.2, 'first_audio_s' => 0.1, 'chunks' => 3];

// Newest run: two voices, one listed sample each, one unlisted WAV next to them.
$new = '20261104-153000';
mkdir("{$runs}/{$new}");
$wav("{$runs}/{$new}/pocket-de-alba-4c-s1.wav");
$wav("{$runs}/{$new}/pocket-de-alba-2c-s1.wav");
file_put_contents("{$runs}/{$new}/pocket-de-anna-4c-s1.wav", 'kein wav, nur Text');
file_put_contents("{$runs}/{$new}/report.json", json_encode($report($new, [
  $voice('pocket-de-alba', [$sample('pocket-de-alba-4c-s1.wav'), $sample('../../escape-4c-s1.wav'), $sample('pocket-de-alba-4c-s1.mp3')]),
  $voice('pocket-de-anna', [$sample('pocket-de-anna-4c-s1.wav'), $sample('pocket-de-anna-4c-s2.wav')]),
  $voice('Bad Id', []),
  $voice('pocket-de-vera', [], false),
])));
// Older run, a run without report, a broken report, a folder with another name.
$old = '20261103-090000';
mkdir("{$runs}/{$old}");
file_put_contents("{$runs}/{$old}/report.json", json_encode($report($old, [$voice('pocket-de-alba', [], false)])));
mkdir("{$runs}/20261102-090000");
mkdir("{$runs}/20261101-090000");
file_put_contents("{$runs}/20261101-090000/report.json", '{kaputt');
mkdir("{$runs}/notes");
file_put_contents("{$runs}/notes/report.json", json_encode($report('notes', [])));
// A WAV outside the runs, reachable only through a link.
$wav("{$base}/outside.wav");
@symlink("{$base}/outside.wav", "{$runs}/{$new}/pocket-de-eve-4c-s1.wav");

// Runs: time stamp folders with a readable report, newest first.
$list = coreui_tts_spike_runs($runs);
tts_test(array_column($list, 'stamp') === [$new, $old], 'runs newest first, broken and foreign folders left out');
tts_test($list[0]['voices'] === 4 && $list[0]['spoken'] === 3 && $list[0]['errors'] === 1, 'counts per run');
tts_test(coreui_tts_spike_runs($base . '/missing') === [], 'no runs folder: empty list');
tts_test(count(coreui_tts_spike_runs($runs, 1)) === 1, 'limit');
tts_test(coreui_tts_spike_report($runs, '../runs/' . $new) === null && coreui_tts_spike_report($runs, 'notes') === null, 'only time stamps');

// Public shape: known keys, cleaned ids and files, numbers stay numbers.
$public = coreui_tts_spike_public(coreui_tts_spike_report($runs, $new));
tts_test(array_column($public['voices'], 'id') === ['pocket-de-alba', 'pocket-de-anna', 'pocket-de-vera'], 'bad voice ids dropped');
tts_test(array_column($public['voices'][0]['samples'], 'file') === ['pocket-de-alba-4c-s1.wav'], 'only sample names of the right shape');
tts_test(!isset($public['voices'][0]['secret']) && $public['voices'][0]['status'] === 'frei', 'unknown keys never leave the server');
tts_test(array_column($public['sentences'], 'id') === ['s1'], 'sentence ids checked');
tts_test($public['groups'][0]['runs'] === [['cores' => 4, 'ok' => true, 'error' => '', 'load_s' => 1.235, 'warmup_s' => 0.5, 'peak_rss_mb' => 900.4,
  'rtf_median' => 0.21, 'first_audio_median_s' => 0.18]], 'group runs with numbers, bad rows dropped');
tts_test($public['groups'][0]['files'] === 1 && $public['errors'] === 1 && $public['sample_cores'] === 4, 'files and errors only counted');
$long = coreui_tts_spike_public($report($new, [array_merge($voice('pocket-de-alba', []), ['origin' => str_repeat('x', 5000), 'status' => 'egal'])]));
tts_test(mb_strlen($long['voices'][0]['origin']) === 400 && $long['voices'][0]['status'] === 'pruefen', 'long text cut, unknown status means pruefen');

// Samples: listed, in place, a real WAV.
$ok = coreui_tts_spike_sample_path($runs, $new, 'pocket-de-alba-4c-s1.wav');
tts_test($ok === realpath("{$runs}/{$new}/pocket-de-alba-4c-s1.wav"), 'listed sample served');
tts_test(coreui_tts_spike_sample_path($runs, $new, 'pocket-de-alba-2c-s1.wav') === null, 'a WAV the report does not list is refused');
tts_test(coreui_tts_spike_sample_path($runs, $new, 'pocket-de-anna-4c-s1.wav') === null, 'listed but not a WAV');
tts_test(coreui_tts_spike_sample_path($runs, $new, 'pocket-de-anna-4c-s2.wav') === null, 'listed but missing');
tts_test(coreui_tts_spike_sample_path($runs, $new, '../../escape-4c-s1.wav') === null, 'no way out of the run');
tts_test(coreui_tts_spike_sample_path($runs, $old, 'pocket-de-alba-4c-s1.wav') === null, 'the sample belongs to its own run');
tts_test(coreui_tts_spike_sample_path($runs, '../' . $new, 'pocket-de-alba-4c-s1.wav') === null, 'stamp checked');
tts_test(coreui_tts_spike_sample_path($runs, $new, 'report.json') === null, 'the report itself is not a sample');
$linked = $report($new, [$voice('pocket-de-eve', [$sample('pocket-de-eve-4c-s1.wav')])]);
file_put_contents("{$runs}/{$new}/report.json", json_encode($linked));
tts_test(is_link("{$runs}/{$new}/pocket-de-eve-4c-s1.wav") && coreui_tts_spike_sample_path($runs, $new, 'pocket-de-eve-4c-s1.wav') === null,
  'a link leading out of the run is refused');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$admin = $read('api/admin.php');
tts_test(str_contains($admin, "require_once __DIR__ . '/tts_spike_store.php';"), 'admin API loads the store');
foreach (['tts_runs', 'tts_sample'] as $action) {
  $at = strpos($admin, "if (\$action === '{$action}') {");
  tts_test($at !== false && strpos($admin, "if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);", $at) === strpos($admin, "\n", $at) + 5,
    $action . ': Inhaber only, checked first');
}
tts_test(str_contains($admin, "header('Content-Type: audio/wav');") && str_contains($admin, "header('X-Content-Type-Options: nosniff');")
  && str_contains($admin, "stu_json(['ok' => false, 'error' => 'sample_not_found'], 404);"), 'samples as WAV, unknown ones 404');
tts_test(str_contains($admin, "if (\$asked !== '' && \$report === null) stu_json(['ok' => false, 'error' => 'run_not_found'], 404);"), 'unknown run 404');
tts_test(strpos($admin, "if (\$action === 'tts_runs')") < strpos($admin, "if (\$method !== 'POST')"), 'read only (GET)');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  $closed = preg_match('#location ~ \^/\(\?:([a-z|]+)\)\(\?:/\|\$\) \{#', $read($nginx), $m) === 1 ? explode('|', $m[1]) : [];
  tts_test(in_array('var', $closed, true) && in_array('tools', $closed, true), $nginx . ' keeps var/ and tools/ closed');
}
$settings = $read('settings.html');
tts_test(str_contains($settings, '<section class="settings-panel cfg-card wide green tts-card" id="ttsSamplesCard" aria-labelledby="adminTtsTitle" hidden>'), 'card hidden until the Inhaber loads it');
tts_test(strpos($settings, 'id="ttsSamplesCard"') > strpos($settings, 'id="view-admin-betrieb"') && strpos($settings, 'id="ttsSamplesCard"') < strpos($settings, 'id="view-admin-memory"'),
  'card under Admin Core > Betrieb');
tts_test((bool)preg_match('~<script src="\./js/coreui-voice-samples\.js\?v=\d+"></script>~', $settings), 'script loaded');
tts_test(str_contains($settings, '<code>sudo bash /opt/ember-coreui/scripts/tts-spike.sh</code>'), 'how to start the test');

// Clean up.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

echo "CoreAI TTS store: runs, public shape, samples only when listed and inside their run, Inhaber only OK\n";
