<?php
declare(strict_types=1);

/**
 * Public Hugging Face GGUF catalog for CoreAI's existing Ollama library.
 * No additional runtime, shell execution, token or automatic model install.
 * Gated/private Hub repositories require a separate authorization workflow.
 */
require_once __DIR__ . '/ollama_library.php';

function coreui_hf_repo_valid(string $repo): bool {
  return strlen($repo) <= 120
    && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$~D', $repo) === 1;
}

function coreui_hf_file_valid(string $file): bool {
  return strlen($file) <= 110
    && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$~iD', $file) === 1
    && preg_match('~[-_.][0-9]{5}-of-[0-9]{5}\.gguf$~iD', $file) !== 1;
}

function coreui_hf_model_repo(string $model): ?string {
  if (preg_match('~^hf\.co/([A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*):([A-Za-z0-9][A-Za-z0-9._-]*\.gguf)$~iD', $model, $parts) !== 1) return null;
  return coreui_hf_repo_valid($parts[1]) && coreui_hf_file_valid($parts[2]) && coreui_model_name_valid($model)
    ? $parts[1] : null;
}

function coreui_hf_url(string $repo): string {
  if (!coreui_hf_repo_valid($repo)) throw new RuntimeException('invalid_model_name');
  return 'https://huggingface.co/' . $repo;
}

/** Fixed HTTPS Hub API origin; no redirects or user-selected server URLs. */
function coreui_hf_fetch_json(string $url): array {
  $parts = parse_url($url);
  if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
      || ($parts['host'] ?? '') !== 'huggingface.co'
      || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
      || preg_match('~^/api/models(?:/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+(?:/tree/(?:main|[a-f0-9]{40}))?)?$~D',
        (string)($parts['path'] ?? '')) !== 1) {
    throw new RuntimeException('invalid_registry_url');
  }
  if (!function_exists('curl_init')) throw new RuntimeException('hf_unavailable');
  $buffer = '';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'CoreAI-HF-GGUF-Browser/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
      if (strlen($buffer) + strlen($chunk) > 4 * 1024 * 1024) return 0;
      $buffer .= $chunk;
      return strlen($chunk);
    },
  ]);
  curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_errno($ch);
  curl_close($ch);
  if ($status === 401 || $status === 403) throw new RuntimeException('hf_access_required');
  if ($status === 404) throw new RuntimeException('hf_not_found');
  if ($status === 429) throw new RuntimeException('hf_rate_limited');
  if ($error !== 0 || $status !== 200 || $buffer === '') throw new RuntimeException('hf_unavailable');
  $decoded = json_decode($buffer, true);
  if (!is_array($decoded)) throw new RuntimeException('hf_invalid_response');
  return $decoded;
}

function coreui_hf_license(array $meta): string {
  $card = is_array($meta['cardData'] ?? null) ? $meta['cardData'] : [];
  $license = $card['license'] ?? null;
  if (is_string($license) && $license !== '') return coreui_library_text($license, 100);
  foreach ((array)($meta['tags'] ?? []) as $tag) {
    if (is_string($tag) && str_starts_with($tag, 'license:')) return coreui_library_text(substr($tag, 8), 100);
  }
  return 'Nicht angegeben';
}

/** Pure Hub search parser: public and non-gated GGUF repositories only. */
function coreui_hf_search_parse(array $results): array {
  $rows = [];
  foreach ($results as $meta) {
    if (!is_array($meta)) continue;
    $repo = (string)($meta['id'] ?? $meta['modelId'] ?? '');
    if (!coreui_hf_repo_valid($repo) || !empty($meta['private']) || !empty($meta['gated']) || isset($rows[$repo])) continue;
    $tags = array_map('strtolower', array_filter((array)($meta['tags'] ?? []), 'is_string'));
    if (!in_array('gguf', $tags, true) && strtolower((string)($meta['library_name'] ?? '')) !== 'gguf'
        && preg_match('~(?:^|[-_.])gguf(?:$|[-_.])~i', $repo) !== 1) continue;
    $rows[$repo] = ['name'=>$repo, 'summary'=>'GGUF · Lizenz: ' . coreui_hf_license($meta),
      'url'=>coreui_hf_url($repo)];
    if (count($rows) >= 60) break;
  }
  return array_values($rows);
}

function coreui_hf_search(string $query): array {
  $query = trim($query);
  if (mb_strlen($query) > 80 || preg_match('~[\x00-\x1f\x7f]~', $query) === 1) throw new RuntimeException('invalid_search');
  // Recent releases and revisions on the Hub. Empty query gets shuffled
  // suggestions; a specific query preserves the Hub search order.
  $url = 'https://huggingface.co/api/models?library=gguf&limit=80&sort=lastModified&direction=-1&full=true'
    . ($query === '' ? '' : '&search=' . rawurlencode($query));
  $rows = coreui_hf_search_parse(coreui_hf_fetch_json($url));
  if ($query === '') {
    shuffle($rows);
    $rows = array_slice($rows, 0, 12);
  }
  return $rows;
}

/** Only single, root-level GGUF files can be selected as Ollama filename tags. */
function coreui_hf_detail_parse(array $meta, array $tree, string $repo): array {
  if (!coreui_hf_repo_valid($repo) || (string)($meta['id'] ?? '') !== $repo) {
    throw new RuntimeException('invalid_model_name');
  }
  if (!empty($meta['gated']) || !empty($meta['private'])) throw new RuntimeException('hf_access_required');
  $files = [];
  foreach ($tree as $entry) {
    if (!is_array($entry)) continue;
    $file = (string)($entry['path'] ?? $entry['rfilename'] ?? '');
    if (!coreui_hf_file_valid($file) || (isset($entry['type']) && $entry['type'] !== 'file')) continue;
    $model = 'hf.co/' . $repo . ':' . $file;
    if (!coreui_model_name_valid($model) || coreui_hf_model_repo($model) !== $repo) continue;
    $size = max(0, (int)($entry['size'] ?? ($entry['lfs']['size'] ?? 0)));
    $files[$model] = ['name'=>$model, 'file'=>$file,
      'size'=>$size ? number_format($size / 1073741824, 1, ',', '.') . ' GB' : 'Größe unbekannt',
      'size_bytes'=>$size, 'detail'=>$file];
    if (count($files) >= 80) break;
  }
  uasort($files, static fn(array $a, array $b): int =>
    ($a['size_bytes'] ?: PHP_INT_MAX) <=> ($b['size_bytes'] ?: PHP_INT_MAX)
      ?: strnatcasecmp($a['file'], $b['file']));
  return ['name'=>$repo, 'description'=>'Öffentliches GGUF-Repository bei Hugging Face.',
    'url'=>coreui_hf_url($repo), 'license'=>coreui_hf_license($meta),
    'tags'=>array_values($files)];
}

function coreui_hf_detail(string $repo): array {
  if (!coreui_hf_repo_valid($repo)) throw new RuntimeException('invalid_model_name');
  $meta = coreui_hf_fetch_json('https://huggingface.co/api/models/' . $repo);
  if (!empty($meta['gated']) || !empty($meta['private'])) throw new RuntimeException('hf_access_required');
  $sha = (string)($meta['sha'] ?? '');
  $revision = preg_match('~^[a-f0-9]{40}$~D', $sha) === 1 ? $sha : 'main';
  try {
    $tree = coreui_hf_fetch_json('https://huggingface.co/api/models/' . $repo . '/tree/' . $revision . '?recursive=true');
  } catch (RuntimeException $e) {
    if (!is_array($meta['siblings'] ?? null) || $meta['siblings'] === []) throw $e;
    $tree = $meta['siblings'];
  }
  return coreui_hf_detail_parse($meta, $tree, $repo);
}

function coreui_hf_install_allowed(string $model, array $detail): bool {
  $repo = coreui_hf_model_repo($model);
  if ($repo === null || (string)($detail['name'] ?? '') !== $repo) return false;
  foreach ((array)($detail['tags'] ?? []) as $tag) {
    if (is_array($tag) && hash_equals((string)($tag['name'] ?? ''), $model)) return true;
  }
  return false;
}
