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

/** A single cited WEB row in the existing assistant result format. */
function coreui_web_research_row_text(array $row, int $index): string {
  $head = $index . '. ' . ((string)($row['title'] ?? '') !== ''
    ? (string)$row['title'] : '(ohne Titel)');
  if (!empty($row['date'])) $head .= ' (vom ' . $row['date'] . ')';
  if (!empty($row['url'])) $head .= ' [' . $row['url'] . ']';
  $snippet = (string)($row['content'] ?? '');
  return $head . ($snippet !== '' ? "\n   " . $snippet : '');
}

/**
 * Source URLs must fit inside what the Core actually receives. Keep rows
 * beyond that context budget in the request-local cursor for [WEB: WEITER].
 */
function coreui_web_research_fit_row(array &$row, int $index, int $remaining, int $snipMax): bool {
  $row['content'] = '';
  $head = coreui_web_research_row_text($row, $index);
  $length = function_exists('mb_strlen') ? mb_strlen($head, 'UTF-8') : strlen($head);
  if ($length > $remaining) return false;
  $raw = (string)($row['_raw_content'] ?? '');
  unset($row['_raw_content']);
  if ($raw === '' || $remaining - $length <= 4) return true;
  $usable = min($snipMax, $remaining - $length - 4);
  $rawLength = function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') : strlen($raw);
  $clipped = $rawLength > $usable && $usable > 1;
  $take = $clipped ? $usable - 1 : $usable;
  $row['content'] = function_exists('mb_substr')
    ? mb_substr($raw, 0, $take, 'UTF-8') : substr($raw, 0, $take);
  if ($clipped) $row['content'] .= '…'; // Never suggest a cut-off excerpt was complete.
  return true;
}

function coreui_web_research_next(string $input, array &$state, int $limit, int $timeout,
    ?int $maxChars = null, ?int $snippetChars = null): array {
  $state['backend_available'] = null;
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
  $maxChars ??= ember_web_max_chars();
  $snippetChars ??= ember_web_snippet_chars();
  $usedChars = 0;
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
      // Make the on-screen source count match the *complete* rows in the
      // model input, rather than announcing rows cut off by MAX_CHARS.
      $candidate = $row;
      $candidate['_raw_content'] = (string)($row['content'] ?? '');
      $remaining = $maxChars - $usedChars - ($out !== [] ? 1 : 0);
      if (!coreui_web_research_fit_row($candidate, count($out) + 1,
          $remaining, $snippetChars)) {
        if ($out !== []) {
          array_unshift($cursor['buffer'], $row);
          break; // Save this source for the next requested batch.
        }
        continue; // An excessively long header/URL cannot be cited safely.
      }
      $usedChars += ($out !== [] ? 1 : 0) + (function_exists('mb_strlen')
        ? mb_strlen(coreui_web_research_row_text($candidate, count($out) + 1), 'UTF-8')
        : strlen(coreui_web_research_row_text($candidate, count($out) + 1)));
      $state['seen'][$urlKey] = true;
      $out[] = $candidate;
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
    $state['backend_available'] = is_array($response['results'] ?? null);
    if (!$state['backend_available']) break;
    $raw = $response['results'];
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
        if (is_array($response['results'] ?? null)) $raw = $response['results'];
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
