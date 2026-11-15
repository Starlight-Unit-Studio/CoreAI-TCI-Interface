<?php
declare(strict_types=1);

/**
 * Per-answer, request-local SearXNG source cursor. The agent can request
 * [WEB: WEITER] to take the next unseen batch from the same query, or supply
 * different terms to start a fresh query. No account/global state is stored.
 *
 * A SearXNG page can contain more items than the operator's per-step limit.
 * Buffer the *whole* cleaned page before fetching pageno=2, so no results
 * are silently skipped when WEB_LIMIT=4 and SearXNG returns 10 per page.
 * At most one new populated search page is fetched per invocation.
 */
function coreui_web_research_resolve_query(string $input, array $state): string {
  $input = trim((string)preg_replace('~\\s+~u', ' ', $input));
  if (preg_match('~^WEITER$~iu', $input) === 1) {
    return trim((string)($state['last_query'] ?? ''));
  }
  return $input;
}

function coreui_web_research_next(string $input, array &$state, int $limit, int $timeout): array {
  $query = coreui_web_research_resolve_query($input, $state);
  if ($query === '') return [];
  $state['last_query'] = $query;
  $state['queries'] ??= [];
  $state['seen'] ??= [];
  $key = hash('sha256', function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query));
  if (!isset($state['queries'][$key])) {
    $state['queries'][$key] = [
      'variants'=>ember_web_query_variants($query), 'variant'=>0,
      'page'=>1, 'buffer'=>[], 'exhausted'=>false,
    ];
  }
  $cursor =& $state['queries'][$key];
  $limit = max(1, $limit);
  $out = [];
  $requested = false;
  while (count($out) < $limit) {
    if ($cursor['buffer'] !== []) {
      $row = array_shift($cursor['buffer']);
      if (!is_array($row)) continue;
      $url = trim((string)($row['url'] ?? ''));
      // Only attributable, linkable results may enter a research source ledger.
      if ($url === '') continue;
      $urlKey = ember_web_url_key($url);
      if (isset($state['seen'][$urlKey])) continue;
      $state['seen'][$urlKey] = true;
      $out[] = $row;
      continue;
    }
    // A single tool step never crawls many pages. If its buffered page is
    // exhausted, another model decision is required before the next request.
    if ($requested || $cursor['exhausted'] || $out !== []) break;
    $requested = true;
    $variant = (string)($cursor['variants'][$cursor['variant']] ?? $query);
    $page = max(1, (int)$cursor['page']);
    $base = ember_web_url();
    $url = $base . (str_contains($base, '?') ? '&' : '?')
      . 'q=' . rawurlencode($variant)
      . '&format=json&language=de&safesearch=1&pageno=' . $page;
    $response = ember_http_get_json($url, $timeout);
    $raw = is_array($response) && isset($response['results']) && is_array($response['results'])
      ? $response['results'] : [];
    if ($raw === []) {
      if ($page === 1 && $cursor['variant'] + 1 < count($cursor['variants'])) {
        // Preserve the existing question -> keywords fallback only on page one.
        $cursor['variant']++;
        $cursor['page'] = 1;
        $variant = (string)$cursor['variants'][$cursor['variant']];
        $base = ember_web_url();
        $url = $base . (str_contains($base, '?') ? '&' : '?')
          . 'q=' . rawurlencode($variant)
          . '&format=json&language=de&safesearch=1&pageno=1';
        $response = ember_http_get_json($url, $timeout);
        $raw = is_array($response) && isset($response['results']) && is_array($response['results'])
          ? $response['results'] : [];
      }
      if ($raw === []) {
        $cursor['exhausted'] = true;
        break;
      }
    }
    $cursor['page']++;
    $cursor['buffer'] = ember_web_clean_rows($raw, count($raw));
    if ($cursor['buffer'] === []) $cursor['exhausted'] = true;
  }
  return $out;
}
