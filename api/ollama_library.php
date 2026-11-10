<?php
declare(strict_types=1);

/**
 * Ollama registry browser for administrators.
 * Read-only HTTPS requests to the official ollama.com search/detail pages.
 * The public registry has no documented JSON search API; parse its HTML
 * defensively and offer an external fallback if the markup changes.
 *
 * Installs only a tag confirmed by a current official detail page.
 * Uses CoreAI's existing serialized /api/pull worker and shared lock, never
 * creates a TCI Core, alters a Modelfile or deletes an existing local model.
 */
require_once __DIR__ . '/core_install.php';

function coreui_library_name_valid(string $name): bool {
  return strlen($name) <= 120
    && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)?$~D', $name) === 1;
}

function coreui_library_tag_valid(string $tag): bool {
  return strlen($tag) <= 50 && preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~D', $tag) === 1
    && preg_match('~(?:^|[._-])cloud(?:$|[._-])~i', $tag) !== 1;
}

function coreui_library_registry_url(string $name): string {
  if (!coreui_library_name_valid($name)) throw new RuntimeException('invalid_model_name');
  // Standard library names and community namespaces have distinct paths.
  return 'https://ollama.com/' . (str_contains($name, '/') ? '' : 'library/') . $name;
}

/** Fixed origin, no HTTP redirects or dynamic hosts (no server-side request forgery). */
function coreui_library_fetch(string $url): string {
  $parts = parse_url($url);
  if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
      || ($parts['host'] ?? '') !== 'ollama.com'
      || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
      || !in_array($parts['path'] ?? '', ['/search'], true)
        && preg_match('~^/(?:library/)?[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)?$~D', (string)($parts['path'] ?? '')) !== 1) {
    throw new RuntimeException('invalid_registry_url');
  }
  if (!function_exists('curl_init')) throw new RuntimeException('registry_unavailable');
  $buffer = '';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'CoreAI-RegistryBrowser/1.0 (+https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface)',
    CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: en'],
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
      if (strlen($buffer) + strlen($chunk) > 3 * 1024 * 1024) return 0;
      $buffer .= $chunk;
      return strlen($chunk);
    },
  ]);
  curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_errno($ch);
  curl_close($ch);
  if ($err !== 0 || $status !== 200 || $buffer === '') throw new RuntimeException('registry_unavailable');
  return $buffer;
}

function coreui_library_dom(string $html): DOMXPath {
  if (!class_exists(DOMDocument::class)) throw new RuntimeException('registry_parser_unavailable');
  $dom = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  try {
    if (!$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
      throw new RuntimeException('registry_invalid_html');
    }
  } finally {
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
  }
  return new DOMXPath($dom);
}

function coreui_library_text(string $value, int $limit = 220): string {
  $flat = preg_replace('~\s+~u', ' ', trim($value));
  return mb_substr(is_string($flat) ? $flat : '', 0, $limit);
}

/** Pure parser for fixture tests. Only official model profile links are kept. */
function coreui_library_search_parse(string $html): array {
  $xpath = coreui_library_dom($html);
  $out = [];
  foreach ($xpath->query('//a[@href]') as $link) {
    $href = (string)$link->getAttribute('href');
    $linkHost = parse_url($href, PHP_URL_HOST);
    if ($linkHost !== null && $linkHost !== 'ollama.com') continue;
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path)) continue;
    if (preg_match('~^/(?:library/)?([A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)?)$~D', $path, $match) !== 1) continue;
    $name = $match[1];
    if (!coreui_library_name_valid($name) || isset($out[$name])
        || in_array(strtolower($name), ['search', 'download', 'blog', 'models', 'pricing', 'login', 'library'], true)) continue;
    // Only accept /library/model or /namespace/model, not arbitrary site links.
    if (!str_starts_with($path, '/library/') && !str_contains($name, '/')) continue;
    $label = coreui_library_text((string)$link->textContent, 260);
    // Cloud-only cards have no local tags or parameter sizes; mixed cards remain.
    if (preg_match('~(?:^|[._/-])cloud(?:$|[._/-])~i', $name) === 1) continue;
    if (preg_match('~\bcloud\b~i', $label) === 1
        && preg_match('~\b(?:[0-9]+(?:\.[0-9]+)?b|[0-9]+\s+tags?)\b|\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b~i', $label) !== 1) continue;
    $out[$name] = [
      'name'=>$name,
      'summary'=>$label === '' ? $name : $label,
      'url'=>coreui_library_registry_url($name),
    ];
    if (count($out) >= 30) break;
  }
  return array_values($out);
}

/** Parse selected model variants. No explicit local-size entry means no install button. */
function coreui_library_detail_parse(string $html, string $name): array {
  if (!coreui_library_name_valid($name)) throw new RuntimeException('invalid_model_name');
  $xpath = coreui_library_dom($html);
  $summary = '';
  foreach ($xpath->query('//meta[@name="description"]') as $meta) {
    $summary = coreui_library_text((string)$meta->getAttribute('content'), 480);
    if ($summary !== '') break;
  }
  if ($summary === '') {
    foreach ($xpath->query('//h2') as $heading) {
      $summary = coreui_library_text((string)$heading->textContent, 480);
      if ($summary !== '') break;
    }
  }
  $tags = [];
  foreach ($xpath->query('//a[@href]') as $link) {
    $href = (string)$link->getAttribute('href');
    $linkHost = parse_url($href, PHP_URL_HOST);
    if ($linkHost !== null && $linkHost !== 'ollama.com') continue;
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path)) continue;
    $prefix = '/' . (str_contains($name, '/') ? '' : 'library/') . $name . ':';
    if (!str_starts_with($path, $prefix)) continue;
    $tag = substr($path, strlen($prefix));
    if (!coreui_library_tag_valid($tag)) continue;
    $title = coreui_library_text((string)$link->textContent, 180);
    // Ollama's local variants show an actual download size in MB/GB.
    if (preg_match('~\b([0-9]+(?:\.[0-9]+)?\s*(?:MB|GB|TB))\b~i', $title, $size) !== 1) continue;
    $model = $name . ':' . $tag;
    $tags[$model] = ['name'=>$model, 'size'=>$size[1], 'detail'=>$title];
    if (count($tags) >= 50) break;
  }
  return ['name'=>$name, 'description'=>$summary, 'url'=>coreui_library_registry_url($name), 'tags'=>array_values($tags)];
}

function coreui_library_search(string $query): array {
  if (mb_strlen($query) > 80 || preg_match('~[\x00-\x1f\x7f]~', $query) === 1) {
    throw new RuntimeException('invalid_search');
  }
  return coreui_library_search_parse(coreui_library_fetch('https://ollama.com/search?q=' . rawurlencode(trim($query))));
}

function coreui_library_detail(string $name): array {
  return coreui_library_detail_parse(coreui_library_fetch(coreui_library_registry_url($name)), $name);
}

function coreui_library_install_allowed(string $model, array $detail): bool {
  if (!coreui_model_name_valid($model)
      || preg_match('~(?:^|[._:-])cloud(?:$|[._:-])~i', $model) === 1
      || preg_match('~^(?:ember-core|ember-coreui)(?::|$)~i', $model) === 1) return false;
  foreach ((array)($detail['tags'] ?? []) as $tag) {
    if (is_array($tag) && hash_equals((string)($tag['name'] ?? ''), $model)) return true;
  }
  return false;
}

function coreui_library_begin(string $model): array {
  $state = ['kind'=>'library', 'tier'=>'', 'label'=>$model, 'base'=>$model, 'core'=>$model,
    'phase'=>'pull', 'step'=>'Startet den lokalen Download …',
    'completed'=>0, 'total'=>0, 'percent'=>0, 'layers'=>[], 'started_at'=>time()];
  coreui_core_install_write($state);
  return $state;
}

/** Shares the TCI install lock and progress, but never builds or cleans up a model. */
function coreui_library_run(array $state): array {
  $state = coreui_core_install_pull($state);
  // The tier installer's generic Ollama error is written for Gemma. Here we
  // report a generic registry-model error instead of pretending every model
  // belongs to the Gemma family.
  if (($state['phase'] ?? '') === 'error'
      && str_contains((string)($state['message'] ?? ''), 'Gemma 4')) {
    $state['message'] = 'Ollama konnte das ausgewählte Modell nicht finden. Bitte Modellname und Version prüfen.';
  }
  if (($state['phase'] ?? '') !== 'error') {
    $state['phase'] = 'verify';
    $state['step'] = 'Prüft das lokal installierte Modell …';
    coreui_core_install_write($state);
    try {
      $found = coreui_ollama_model_exists((string)$state['core']);
    } catch (RuntimeException $e) {
      $found = false;
    }
    if (!$found) {
      $state['phase'] = 'error';
      $state['error'] = 'verify_failed';
      $state['message'] = 'Ollama hat den Download gemeldet, das Modell ist aber noch nicht lokal verfügbar.';
    } else {
      $state['phase'] = 'done';
      $state['step'] = 'Das Modell ist lokal installiert.';
      $state['percent'] = 100;
    }
  }
  unset($state['layers'], $state['pulled']);
  coreui_core_install_write($state);
  return $state;
}
