<?php
declare(strict_types=1);
// Pure request-store fixture: no Docker, deletion, systemd or user DB.
require_once dirname(__DIR__) . '/api/uninstall_store.php';
function check(bool $result, string $what): void {
  if (!$result) { fwrite(STDERR, "CoreAI uninstall store FAIL: $what\n"); exit(1); }
}
$root = sys_get_temp_dir() . '/coreai-uninstall-store-' . bin2hex(random_bytes(6));
$dir = $root . '/var/uninstall-request';
mkdir($dir, 0770, true);
try {
  check(!coreui_uninstall_available($root), 'marker required');
  file_put_contents($dir . '/runner.json', '{"installed":true}');
  check(coreui_uninstall_available($root), 'marker accepted');
  coreui_uninstall_queue($root, 7);
  $req = json_decode((string)file_get_contents($dir . '/request.json'), true);
  check(is_array($req) && $req['action'] === 'uninstall' && $req['mode'] === 'archive'
    && $req['requested_by'] === 7 && preg_match('/^[a-f0-9]{32}$/', $req['nonce']) === 1,
    'exact archive-only request');
  check(coreui_uninstall_pending($root), 'one pending request');
  try { coreui_uninstall_queue($root, 7); check(false, 'duplicate accepted'); }
  catch (RuntimeException $e) { check($e->getMessage() === 'uninstall_pending', 'duplicate refusal'); }
  unlink($dir . '/request.json');
  try { coreui_uninstall_queue($root, 0); check(false, 'invalid owner accepted'); }
  catch (RuntimeException $e) { check($e->getMessage() === 'owner_only', 'invalid owner refusal'); }
  unlink($dir . '/runner.json');
  check(!coreui_uninstall_available($root), 'unavailable after marker loss');
} finally {
  foreach (glob($dir . '/.*') ?: [] as $path) { if (is_file($path)) unlink($path); }
  foreach (glob($dir . '/*') ?: [] as $path) { if (is_file($path)) unlink($path); }
  rmdir($dir); rmdir(dirname($dir)); rmdir($root);
}
echo "CoreAI uninstall store: owner id, archive-only request and atomic queue OK\n";
