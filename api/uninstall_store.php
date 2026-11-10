<?php
declare(strict_types=1);
/** Single-purpose, fixed-path uninstall requests. No shell execution in PHP. */
function coreui_uninstall_paths(string $root): array {
  $dir = rtrim($root, '/') . '/var/uninstall-request';
  return ['dir'=>$dir, 'marker'=>$dir . '/runner.json', 'request'=>$dir . '/request.json', 'lock'=>$dir . '/.queue.lock'];
}
function coreui_uninstall_available(string $root): bool {
  $p = coreui_uninstall_paths($root);
  if (!is_dir($p['dir']) || is_link($p['dir']) || !is_file($p['marker']) || is_link($p['marker'])) return false;
  $raw = @file_get_contents($p['marker']);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) && ($decoded['installed'] ?? false) === true;
}
function coreui_uninstall_pending(string $root): bool {
  $p = coreui_uninstall_paths($root);
  return file_exists($p['request']) || is_link($p['request']);
}
/** Only the authenticated owner API calls this after password + CSRF checks. */
function coreui_uninstall_queue(string $root, int $ownerId): void {
  if ($ownerId <= 0) throw new RuntimeException('owner_only');
  if (!coreui_uninstall_available($root)) throw new RuntimeException('uninstaller_unavailable');
  $p = coreui_uninstall_paths($root);
  if (is_link($p['lock'])) throw new RuntimeException('uninstall_request_unsafe');
  $fp = @fopen($p['lock'], 'c');
  if ($fp === false) throw new RuntimeException('uninstall_request_unavailable');
  try {
    if (!flock($fp, LOCK_EX | LOCK_NB)) throw new RuntimeException('uninstall_request_busy');
    if (coreui_uninstall_pending($root)) throw new RuntimeException('uninstall_pending');
    $request = ['action'=>'uninstall', 'mode'=>'archive', 'requested_by'=>$ownerId,
      'requested_at'=>time(), 'nonce'=>bin2hex(random_bytes(16))];
    $raw = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = @tempnam($p['dir'], '.uninstall-');
    if ($tmp === false) throw new RuntimeException('uninstall_request_unavailable');
    try {
      if (@file_put_contents($tmp, $raw) === false || !@chmod($tmp, 0600)
          || !@rename($tmp, $p['request'])) throw new RuntimeException('uninstall_request_unavailable');
    } finally {
      if (is_file($tmp)) @unlink($tmp);
    }
  } finally {
    @flock($fp, LOCK_UN);
    fclose($fp);
  }
}
