<?php
declare(strict_types=1);
// CoreAI 0.6.37 (P20.5 A): Admin Core > Betrieb > Bildproben on the PHP side. Offline:
// runs listed newest first (only time stamp folders with a readable report), the public
// shape (known keys, numbers, cut text, no file name for a failed image), and an image
// served only when its run lists it as made and it really is a PNG inside that run folder.
// Plus the wiring: Inhaber only, read only, var/ closed in nginx, the card and its script.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/image_spike_store.php';

function img_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Image store FAIL: {$why}\n"); exit(1); }
}

$base = sys_get_temp_dir() . '/coreai-image-store-' . bin2hex(random_bytes(4));
$runs = $base . '/runs';
mkdir($runs, 0700, true);
$png = static fn(string $file) => file_put_contents($file, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
$image = static fn(string $prompt, string $size, bool $ok, string $file = '') => ['prompt' => $prompt, 'size' => $size, 'file' => $file,
  'seed' => 42, 'ok' => $ok, 'error' => $ok ? '' : 'sd-cli Exit 3: kaputt', 'wall_s' => 61.234, 'load_s' => 20.5, 'text_s' => 3.1,
  'sampling_s' => 35.678, 'decode_s' => 2.2, 'peak_rss_mb' => 14123.4, 'secret' => 'nein'];
$report = static fn(string $stamp, array $groups, array $extra = []): array => array_merge([
  'format' => 1, 'stamp' => $stamp, 'created_at' => '2026-11-07T10:15:00+0000', 'host' => "Model name: AMD EPYC 9655\nAVX-512: avx512f",
  'runtime' => ['tag' => 'master-1046-d308544', 'commit' => str_repeat('d', 40), 'license' => 'MIT', 'secret' => 'x'],
  'prompts' => [['id' => 'p1', 'topic' => 'Foto', 'seed' => 42, 'text' => 'A red fox'], ['id' => 'bad', 'topic' => 'x', 'text' => 'y']],
  'groups' => $groups, 'errors' => ['zimage-turbo: kaputt'],
], $extra);
$group = static fn(string $id, array $images, bool $ok = true) => ['id' => $id, 'label' => 'Z-Image-Turbo (6B, Q8_0)',
  'origin' => 'Tongyi-MAI', 'license' => 'Apache-2.0', 'steps' => 8, 'threads' => 20, 'ok' => $ok, 'error' => $ok ? '' : 'kaputt',
  'images' => $images, 'files' => [['role' => 'vae', 'repo' => 'Comfy-Org/Z-Image-Turbo', 'path' => 'split_files/vae/ae.safetensors',
    'revision' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64), 'size' => 335304388, 'license' => 'Apache-2.0', 'secret' => 'x']]];

$old = '20261106-090000';
$new = '20261107-101500';
foreach ([$old, $new, '20261107-999999x', 'junk'] as $dir) mkdir("{$runs}/{$dir}");
file_put_contents("{$runs}/{$old}/report.json", json_encode($report($old, [$group('zimage-turbo', [$image('p1', '512x512', true, 'zimage-turbo-512x512-p1.png')])])));
file_put_contents("{$runs}/{$new}/report.json", json_encode($report($new, [
  $group('zimage-turbo', [$image('p1', '512x512', true, 'zimage-turbo-512x512-p1.png'), $image('p2', '512x512', false, 'zimage-turbo-512x512-p2.png'),
    $image('p1', '1024x1024', true, '../../etc/passwd'), $image('p3', 'riesig', true, 'x.png')]),
  $group('flux2-klein-4b', [], false), ['id' => '../böse', 'images' => []],
])));
file_put_contents("{$runs}/20261107-999999x/report.json", '{}');
$png("{$runs}/{$new}/zimage-turbo-512x512-p1.png");
$png("{$runs}/{$new}/zimage-turbo-512x512-p2.png");
file_put_contents("{$runs}/{$new}/zimage-turbo-1024x1024-p1.png", 'no png at all');

$list = coreui_image_spike_runs($runs);
img_test(array_column($list, 'stamp') === [$new, $old], 'runs newest first, only time stamp folders with a report: ' . json_encode($list));
img_test($list[0]['images'] === 4 && $list[0]['made'] === 3 && $list[0]['errors'] === 1, 'counts per run');
img_test(coreui_image_spike_report($runs, '../runs') === null && coreui_image_spike_report($runs, '20261107-101500/..') === null, 'no paths as run names');

$public = coreui_image_spike_public((array)coreui_image_spike_report($runs, $new));
img_test(count($public['groups']) === 2 && $public['groups'][0]['id'] === 'zimage-turbo', 'groups with valid ids only');
$imgs = $public['groups'][0]['images'];
img_test(count($imgs) === 3 && $imgs[0]['file'] === 'zimage-turbo-512x512-p1.png' && $imgs[0]['wall_s'] === 61.23 && $imgs[0]['sampling_s'] === 35.68,
  'images with sizes, numbers rounded: ' . json_encode($imgs[0]));
img_test($imgs[1]['ok'] === false && $imgs[1]['file'] === '' && $imgs[1]['error'] === 'sd-cli Exit 3: kaputt', 'a failed image has no file, its error shows');
img_test($imgs[2]['file'] === '', 'a file name that is no image name is dropped');
img_test(!str_contains(json_encode($public), 'secret') && !str_contains(json_encode($public), 'nein'), 'only known keys leave the server');
img_test(count($public['prompts']) === 1 && $public['prompts'][0]['seed'] === 42 && $public['runtime']['tag'] === 'master-1046-d308544'
  && str_contains($public['host'], 'EPYC') && $public['groups'][0]['files'][0]['sha256'] === str_repeat('b', 64), 'prompts, runtime, host and files');

img_test(coreui_image_spike_image_path($runs, $new, 'zimage-turbo-512x512-p1.png') === realpath("{$runs}/{$new}/zimage-turbo-512x512-p1.png"), 'a listed PNG is served');
img_test(coreui_image_spike_image_path($runs, $new, 'zimage-turbo-512x512-p2.png') === null, 'a failed image is not served even when the file exists');
img_test(coreui_image_spike_image_path($runs, $new, 'zimage-turbo-1024x1024-p1.png') === null, 'not listed: not served');
img_test(coreui_image_spike_image_path($runs, $new, '../' . $old . '/zimage-turbo-512x512-p1.png') === null
  && coreui_image_spike_image_path($runs, $new, 'report.json') === null && coreui_image_spike_image_path($runs, 'x', 'a.png') === null, 'no traversal, no report');
$fake = $report($new, [$group('zimage-turbo', [$image('p1', '1024x1024', true, 'zimage-turbo-1024x1024-p1.png')])]);
file_put_contents("{$runs}/{$new}/report.json", json_encode($fake));
img_test(coreui_image_spike_image_path($runs, $new, 'zimage-turbo-1024x1024-p1.png') === null, 'a listed file that is no PNG is refused');
unlink("{$runs}/{$new}/zimage-turbo-1024x1024-p1.png");
symlink('/etc/hostname', "{$runs}/{$new}/zimage-turbo-1024x1024-p1.png");
img_test(coreui_image_spike_image_path($runs, $new, 'zimage-turbo-1024x1024-p1.png') === null, 'a link leading out of the run is refused');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$admin = $read('api/admin.php');
img_test(str_contains($admin, "require_once __DIR__ . '/image_spike_store.php';"), 'admin API loads the store');
foreach (['image_runs', 'image_sample'] as $action) {
  $at = strpos($admin, "if (\$action === '{$action}') {");
  img_test($at !== false && strpos($admin, "if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);", $at) === strpos($admin, "\n", $at) + 5,
    $action . ': Inhaber only, checked first');
  img_test(strpos($admin, "if (\$action === '{$action}')") < strpos($admin, "if (\$method !== 'POST')"), $action . ': read only (GET)');
}
img_test(str_contains($admin, "header('Content-Type: image/png');") && str_contains($admin, "stu_json(['ok' => false, 'error' => 'image_not_found'], 404);"),
  'images as PNG, unknown ones 404');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  $closed = preg_match('#location ~ \^/\(\?:([a-z|]+)\)\(\?:/\|\$\) \{#', $read($nginx), $m) === 1 ? explode('|', $m[1]) : [];
  img_test(in_array('var', $closed, true) && in_array('tools', $closed, true), $nginx . ' keeps var/ and tools/ closed');
}
$settings = $read('admin/index.html');
img_test(str_contains($settings, '<section class="settings-panel cfg-card wide green tts-card img-card" id="imageSamplesCard" aria-labelledby="adminImageTitle" hidden>'),
  'card hidden until the Inhaber loads it');
img_test(strpos($settings, 'id="imageSamplesCard"') > strpos($settings, 'id="view-admin-betrieb"') && strpos($settings, 'id="imageSamplesCard"') < strpos($settings, 'id="view-admin-memory"'),
  'card under Admin Core > Betrieb');
img_test((bool)preg_match('~<script src="\.\./js/coreui-image-samples\.js\?v=\d+"></script>~', $settings), 'script loaded');
img_test(str_contains($settings, '<code>sudo bash /opt/ember-coreui/scripts/image-spike.sh</code>'), 'how to start the test');
$js = $read('js/coreui-image-samples.js');
img_test(!preg_match('~innerHTML\s*=(?!\s*\'\';)~', $js) && !str_contains($js, 'insertAdjacentHTML'), 'the card never injects HTML from the report');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

echo "CoreAI image store: runs, public shape, images only when listed as made and real PNGs inside their run, Inhaber only, card wired OK\n";
