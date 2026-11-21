<?php
declare(strict_types=1);

/*
 * 0.8.11 (CF-003, CF-005): the external OpenAI-compatible adapter.
 *
 * 0.8.15: external Cores. The operator sets the adapter up once in Admin Core (switch, base URL,
 * key) and picks the Cores of the provider that CoreAI offers ("Cores vom Anbieter laden" reads the
 * provider's list). They appear in the Core menu of the chat under EXTERNE CORES, chosen like a
 * local Core and stored per account as "extern:<name>". The Inhaber uses all of them; every other
 * account only the ones he released under Admin Core > Benutzer (the same releases as for Cores
 * outside CoreAI); guests never. The account's choice is checked again at every turn.
 *
 * Only the inference endpoint changes. Prompt, skills, tools, work loop, the serial model queue,
 * cancelling and the time limits stay exactly as they are, so a run can be compared with a local
 * one (to rule a Core in or out as the cause of a fault). An external Core has no Modelfile, so it
 * gets Mara's SYSTEM text from the operator Modelfile (unchanged), like a raw local Core. The
 * request is built from the Ollama /api/chat payload and the answer is turned back into Ollama's
 * shape (message.content, message.thinking, done, done_reason, eval_count, prompt_eval_count), so the
 * existing parsing stays untouched. Ollama-only settings (think, keep_alive, num_ctx, num_predict,
 * top_k and the tuning options) are not sent. Audio, live voice and the browser worker stay local.
 *
 * coreui_provider_wire() gives both call sites one "line": unchanged for Ollama, translated for the
 * external API. The key is read only when the request is built and never shown or logged.
 *
 * The key: the Inhaber pastes it into Admin Core (no file to edit); it is kept sealed with
 * api/secret_box.php (AES-256-GCM, key derived from the server configuration) and never shown
 * again. A key in the server configuration (COREUI_EXTERNAL_API_KEY or STU_EXTERNAL_API_KEY)
 * wins; then Admin Core only shows that it is there.
 */

require_once __DIR__ . '/secret_box.php';

const COREUI_PROVIDER_LABEL_DEFAULT = 'OpenAI-kompatibel';
const COREUI_PROVIDER_KEY_SETTING = 'external_api_key_sealed';
const COREUI_PROVIDER_KEY_PURPOSE = 'external-api-key';
const COREUI_PROVIDER_ERROR_CHARS = 300;
const COREUI_PROVIDER_TEST_TIMEOUT = 10;
// 0.8.15: an account's choice of an external Core is stored as "extern:<name>".
const COREUI_PROVIDER_PREFIX = 'extern:';
// At most this many external Cores are offered; a provider list is read up to COREUI_PROVIDER_LIST_MAX.
const COREUI_PROVIDER_MODELS_MAX = 50;
const COREUI_PROVIDER_LIST_MAX = 1000;

/** A base URL the adapter may call (pure): https, or http only to this machine; '' when not. */
function coreui_provider_base_url(string $value): string {
  $url = rtrim(trim($value), '/');
  if ($url === '' || strlen($url) > 300 || filter_var($url, FILTER_VALIDATE_URL) === false) return '';
  $parts = parse_url($url);
  if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
      || isset($parts['fragment'])) {
    return '';
  }
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
  if ($host === '') return '';
  if ($scheme === 'https') return $url;
  // A key sent without TLS may only stay on this machine (a local proxy such as LiteLLM).
  if ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return $url;
  return '';
}

/**
 * A model name of the provider (pure): letters, digits and ._:/@+- , at most 153 (with "extern:" it
 * fits the 160 characters of an account's Core choice and of a release); '' when not.
 */
function coreui_provider_model_name(string $value): string {
  $model = trim($value);
  return preg_match('~^[A-Za-z0-9._:/@+-]{1,153}$~D', $model) === 1 ? $model : '';
}

/** Whether an account's Core choice names an external Core (pure), valid or not. */
function coreui_provider_is_choice(string $choice): bool {
  return stripos(trim($choice), COREUI_PROVIDER_PREFIX) === 0;
}

/** The external Core of an account's Core choice "extern:<name>" (pure); '' for a local or invalid one. */
function coreui_provider_choice(string $choice): string {
  if (!coreui_provider_is_choice($choice)) return '';
  return coreui_provider_model_name(substr(trim($choice), strlen(COREUI_PROVIDER_PREFIX)));
}

/**
 * The external Cores CoreAI offers (pure): the setting external_api_models (a JSON list), valid and
 * unique, at most COREUI_PROVIDER_MODELS_MAX. Without a list, the single Core name of 0.8.11
 * (external_api_model) counts, so an adapter set up then keeps working.
 */
function coreui_provider_models_parse($value, string $legacy = ''): array {
  if (is_string($value)) {
    $decoded = trim($value) === '' ? null : json_decode($value, true);
    $value = is_array($decoded) ? $decoded : null;
  }
  $out = [];
  if (is_array($value)) {
    foreach ($value as $name) {
      $name = is_string($name) ? coreui_provider_model_name($name) : '';
      if ($name === '' || in_array($name, $out, true)) continue;
      $out[] = $name;
      if (count($out) >= COREUI_PROVIDER_MODELS_MAX) break;
    }
    return $out;
  }
  $legacy = coreui_provider_model_name($legacy);
  return $legacy !== '' ? [$legacy] : [];
}

/** A key as pasted (pure): printable characters without spaces, 8 to 400 long; '' when not. */
function coreui_provider_key_valid(string $value): string {
  $key = trim($value);
  return preg_match('~^[\x21-\x7e]{8,400}$~D', $key) === 1 ? $key : '';
}

/** The key from the server configuration, '' without one. */
function coreui_provider_server_key(): string {
  return defined('STU_EXTERNAL_API_KEY') ? trim((string)constant('STU_EXTERNAL_API_KEY')) : '';
}

/**
 * The key the Inhaber stored in Admin Core: ['key'=>string, 'unreadable'=>bool]. Read once per
 * request; unreadable when the server configuration changed since (then it has to be pasted again).
 */
function coreui_provider_stored_key(?PDO $pdo = null): array {
  $memo = $GLOBALS['COREUI_PROVIDER_STORED_KEY'] ?? null;
  if (is_array($memo)) return $memo;
  $result = ['key'=>'', 'unreadable'=>false];
  try {
    $pdo = $pdo ?? (function_exists('stu_pdo') ? stu_pdo() : null);
    $sealed = $pdo instanceof PDO && function_exists('stu_app_setting_get')
      ? trim((string)stu_app_setting_get($pdo, COREUI_PROVIDER_KEY_SETTING, '')) : '';
    if ($sealed !== '') {
      $plain = coreui_secret_open($sealed, COREUI_PROVIDER_KEY_PURPOSE);
      $result = is_string($plain) && coreui_provider_key_valid($plain) !== '' ? ['key'=>$plain, 'unreadable'=>false]
        : ['key'=>'', 'unreadable'=>true];
    }
  } catch (Throwable $e) {
    $result = ['key'=>'', 'unreadable'=>false];
  }
  return $GLOBALS['COREUI_PROVIDER_STORED_KEY'] = $result;
}

/** The key in use: the server configuration's, else the stored one; '' without one. */
function coreui_provider_key(?PDO $pdo = null): string {
  $server = coreui_provider_server_key();
  return $server !== '' ? $server : (string)coreui_provider_stored_key($pdo)['key'];
}

/** Where the key comes from: 'server', 'admin' or '' (none), and whether a stored one is unreadable. */
function coreui_provider_key_state(?PDO $pdo = null): array {
  if (coreui_provider_server_key() !== '') return ['source'=>'server', 'unreadable'=>false];
  $stored = coreui_provider_stored_key($pdo);
  return ['source'=>$stored['key'] !== '' ? 'admin' : '', 'unreadable'=>(bool)$stored['unreadable']];
}

/** Admin Core: keep a pasted key sealed (Inhaber only, checked by the caller). */
function coreui_provider_key_store(PDO $pdo, string $value): void {
  $key = coreui_provider_key_valid($value);
  if ($key === '') throw new InvalidArgumentException('external_key_invalid');
  stu_app_setting_set($pdo, COREUI_PROVIDER_KEY_SETTING, coreui_secret_seal($key, COREUI_PROVIDER_KEY_PURPOSE));
  unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);
}

/** Admin Core: forget the stored key. */
function coreui_provider_key_delete(PDO $pdo): void {
  stu_app_setting_set($pdo, COREUI_PROVIDER_KEY_SETTING, '');
  unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);
}

/**
 * The adapter as the operator configured it (pure): $settings are the app settings
 * external_api_enabled, _label, _base_url and _models (0.8.11: _model). 'configured' only when it is
 * switched on and everything is there; 'connection_ready' already with base URL and key, so the
 * connection can be checked and the provider's Cores read before it is switched on.
 */
function coreui_provider_capability(array $settings, bool $keyConfigured, string $keySource = '', bool $keyUnreadable = false): array {
  $enabled = (string)($settings['external_api_enabled'] ?? '0') === '1';
  $label = trim((string)($settings['external_api_label'] ?? ''));
  $rawUrl = trim((string)($settings['external_api_base_url'] ?? ''));
  $baseUrl = coreui_provider_base_url($rawUrl);
  $models = coreui_provider_models_parse($settings['external_api_models'] ?? '', (string)($settings['external_api_model'] ?? ''));
  $missing = [];
  if ($rawUrl === '') $missing[] = 'base_url';
  elseif ($baseUrl === '') $missing[] = 'base_url_invalid';
  if (!$keyConfigured) $missing[] = $keyUnreadable ? 'key_unreadable' : 'key';
  if ($models === []) $missing[] = 'models';
  $configured = $enabled && $missing === [];
  return [
    'configured' => $configured,
    'enabled' => $enabled,
    'connection_ready' => $baseUrl !== '' && $keyConfigured,
    'label' => $label !== '' ? mb_substr($label, 0, 160, 'UTF-8') : COREUI_PROVIDER_LABEL_DEFAULT,
    'base_url' => $baseUrl !== '' ? $baseUrl : $rawUrl,
    'models' => $models,
    'key_configured' => $keyConfigured,
    'key_source' => $keyConfigured ? ($keySource !== '' ? $keySource : 'server') : '',
    'missing' => $missing,
    'status' => !$enabled ? 'disabled' : ($configured ? 'ready' : 'incomplete'),
  ];
}

/** Whether CoreAI offers this external Core (pure). */
function coreui_provider_offered(array $capability, string $model): bool {
  return $model !== '' && in_array($model, (array)($capability['models'] ?? []), true);
}

/**
 * The external Core an account chats with this turn (pure), or null: the adapter is configured, it
 * offers the Core, and the account may use it ($allowed: the Inhaber, or an account with the
 * Inhaber's release; never a guest; checked by the caller with coreui_model_access_allowed).
 */
function coreui_provider_account_external(array $capability, string $model, bool $allowed): ?array {
  if (!$allowed || empty($capability['configured']) || !coreui_provider_offered($capability, $model)) return null;
  return ['label'=>(string)$capability['label'], 'base_url'=>(string)$capability['base_url'], 'model'=>$model];
}

/** The external provider of this turn, or null for local Ollama (set by coreui_ai_runtime_apply). */
function coreui_provider_active(): ?array {
  $runtime = function_exists('coreui_ai_runtime_settings') ? coreui_ai_runtime_settings() : [];
  $external = is_array($runtime['external'] ?? null) ? $runtime['external'] : null;
  if ($external === null || ($runtime['provider'] ?? 'local') !== 'external') return null;
  $baseUrl = coreui_provider_base_url((string)($external['base_url'] ?? ''));
  $model = coreui_provider_model_name((string)($external['model'] ?? ''));
  if ($baseUrl === '' || $model === '' || coreui_provider_key() === '') return null;
  return ['label'=>(string)($external['label'] ?? COREUI_PROVIDER_LABEL_DEFAULT), 'base_url'=>$baseUrl, 'model'=>$model];
}

/** The type of an image from its base64 bytes (pure); JPEG when unknown. */
function coreui_provider_image_mime(string $base64): string {
  $head = (string)base64_decode(substr($base64, 0, 24), false);
  if (str_starts_with($head, "\x89PNG")) return 'image/png';
  if (str_starts_with($head, 'GIF8')) return 'image/gif';
  if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'image/webp';
  return 'image/jpeg';
}

/**
 * The OpenAI chat/completions body for an Ollama /api/chat payload (pure). Images become
 * image_url parts with data URLs. Only options with an OpenAI meaning are kept.
 */
function coreui_provider_body(array $payload, string $model): array {
  $messages = [];
  foreach ((array)($payload['messages'] ?? []) as $message) {
    if (!is_array($message)) continue;
    $role = in_array($message['role'] ?? '', ['system', 'user', 'assistant'], true) ? (string)$message['role'] : 'user';
    $content = (string)($message['content'] ?? '');
    $images = is_array($message['images'] ?? null) ? array_values(array_filter($message['images'], 'is_string')) : [];
    if ($images !== []) {
      $parts = [['type'=>'text', 'text'=>$content]];
      foreach ($images as $image) {
        $parts[] = ['type'=>'image_url', 'image_url'=>['url'=>'data:' . coreui_provider_image_mime($image) . ';base64,' . $image]];
      }
      $messages[] = ['role'=>$role, 'content'=>$parts];
    } else {
      $messages[] = ['role'=>$role, 'content'=>$content];
    }
  }
  $body = ['model'=>$model, 'messages'=>$messages, 'stream'=>!empty($payload['stream'])];
  $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
  if (isset($options['temperature']) && is_numeric($options['temperature'])) $body['temperature'] = max(0.0, min(2.0, (float)$options['temperature']));
  if (isset($options['top_p']) && is_numeric($options['top_p'])) $body['top_p'] = max(0.0, min(1.0, (float)$options['top_p']));
  if (isset($options['seed']) && is_numeric($options['seed'])) $body['seed'] = (int)$options['seed'];
  if (isset($options['stop'])) {
    $stops = array_values(array_filter(array_map('strval', (array)$options['stop']), static fn(string $s): bool => $s !== ''));
    if ($stops !== []) $body['stop'] = array_slice($stops, 0, 4);
  }
  return $body;
}

/** Hide anything that looks like a key in a provider message (pure). */
function coreui_provider_redact(string $text, string $key = ''): string {
  if ($key !== '' && strlen($key) >= 6) $text = str_replace($key, '[Schlüssel ausgeblendet]', $text);
  // Key-like tokens: a known prefix and at least one digit (so words like "api-version" stay).
  $text = (string)preg_replace('~\b(?:sk|pk|rk|gsk|xai|key|api|ak)[-_](?=[A-Za-z0-9_*.\-]*\d)[A-Za-z0-9_*.\-]{6,}~i', '[Schlüssel ausgeblendet]', $text);
  $text = (string)preg_replace('~\bBearer\s+\S+~i', 'Bearer [Schlüssel ausgeblendet]', $text);
  return $text;
}

/** The provider's error message of a body (pure), shortened and without keys; '' when none. */
function coreui_provider_error_message(string $raw, string $key = ''): string {
  $json = json_decode(trim($raw), true);
  $message = '';
  if (is_array($json)) {
    $error = $json['error'] ?? null;
    if (is_string($error)) $message = $error;
    elseif (is_array($error)) $message = (string)($error['message'] ?? $error['code'] ?? $error['type'] ?? '');
    elseif (isset($json['message']) && is_string($json['message'])) $message = $json['message'];
    elseif (isset($json['detail']) && is_string($json['detail'])) $message = $json['detail'];
  }
  $message = trim((string)preg_replace('~[\x00-\x1f]+~u', ' ', $message));
  if ($message === '') return '';
  $message = coreui_provider_redact($message, $key);
  return mb_strlen($message, 'UTF-8') > COREUI_PROVIDER_ERROR_CHARS
    ? mb_substr($message, 0, COREUI_PROVIDER_ERROR_CHARS, 'UTF-8') . '...' : $message;
}

/** finish_reason in Ollama's words (pure). */
function coreui_provider_done_reason(?string $finish): string {
  $finish = strtolower(trim((string)$finish));
  return match ($finish) {
    'stop', 'end_turn', 'stop_sequence', 'eos' => 'stop',
    'length', 'max_tokens', 'model_length' => 'length',
    '' => '',
    default => $finish,
  };
}

/** The text of a message or delta field (pure): a string, or text parts. */
function coreui_provider_text($value): string {
  if (is_string($value)) return $value;
  if (!is_array($value)) return '';
  $text = '';
  foreach ($value as $part) {
    if (is_string($part)) $text .= $part;
    elseif (is_array($part) && is_string($part['text'] ?? null)) $text .= $part['text'];
  }
  return $text;
}

/** Reasoning a provider returns next to the answer (pure): reasoning_content or reasoning. */
function coreui_provider_reasoning(array $message): string {
  foreach (['reasoning_content', 'reasoning'] as $field) {
    $text = coreui_provider_text($message[$field] ?? null);
    if ($text !== '') return $text;
  }
  return '';
}

/**
 * A complete chat/completions answer in Ollama's shape (pure). An error body becomes
 * {"error": "..."}; a body that is neither stays as it is, so it fails like a broken Ollama answer.
 */
function coreui_provider_decode(string $raw, string $key = ''): string {
  $json = json_decode(trim($raw), true);
  if (!is_array($json)) return $raw;
  if (isset($json['error']) || (!isset($json['choices']) && (isset($json['message']) || isset($json['detail'])))) {
    $message = coreui_provider_error_message($raw, $key);
    return (string)json_encode(['error'=>$message !== '' ? $message : 'Fehler der externen API'], JSON_UNESCAPED_UNICODE);
  }
  $choice = is_array($json['choices'][0] ?? null) ? $json['choices'][0] : null;
  if ($choice === null) return (string)json_encode(['error'=>'Die externe API hat keine Antwort geliefert.'], JSON_UNESCAPED_UNICODE);
  $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
  $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
  return (string)json_encode([
    'message' => ['role'=>'assistant', 'content'=>coreui_provider_text($message['content'] ?? null),
      'thinking'=>coreui_provider_reasoning($message)],
    'done' => true,
    'done_reason' => coreui_provider_done_reason(is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : 'stop'),
    'eval_count' => max(0, (int)($usage['completion_tokens'] ?? 0)),
    'prompt_eval_count' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * A translator for a streamed answer (server-sent events): 'feed' takes one raw line and returns
 * the Ollama NDJSON lines for it, 'finish' the closing line at the end. A stream that stops
 * without a finish_reason or [DONE] stays "not done", as with Ollama.
 */
function coreui_provider_stream_translator(string $key = ''): array {
  $state = (object)['reason'=>null, 'done'=>false, 'eval'=>0, 'prompt'=>0];
  $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
  $closing = static function () use ($state, $flags): string {
    $state->done = true;
    return (string)json_encode(['done'=>true, 'done_reason'=>coreui_provider_done_reason($state->reason ?? 'stop'),
      'eval_count'=>$state->eval, 'prompt_eval_count'=>$state->prompt], $flags);
  };
  $feed = static function (string $line) use ($state, $flags, $closing, $key): array {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, ':') || $state->done) return [];
    if (str_starts_with($line, 'data:')) {
      $data = trim(substr($line, 5));
    } elseif (str_starts_with($line, '{')) {
      // Not an event stream: an error body (for example HTTP 401) or a whole JSON answer.
      $data = $line;
    } else {
      return [];
    }
    if ($data === '[DONE]') return [$closing()];
    $json = json_decode($data, true);
    if (!is_array($json)) return [];
    if (isset($json['error'])) {
      $message = coreui_provider_error_message($data, $key);
      return [(string)json_encode(['error'=>$message !== '' ? $message : 'Fehler der externen API'], $flags)];
    }
    $usage = is_array($json['usage'] ?? null) ? $json['usage']
      : (is_array($json['x_groq']['usage'] ?? null) ? $json['x_groq']['usage'] : null);
    if ($usage !== null) {
      $state->eval = max($state->eval, (int)($usage['completion_tokens'] ?? 0));
      $state->prompt = max($state->prompt, (int)($usage['prompt_tokens'] ?? 0));
    }
    $out = [];
    foreach ((array)($json['choices'] ?? []) as $choice) {
      if (!is_array($choice)) continue;
      $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : (is_array($choice['message'] ?? null) ? $choice['message'] : []);
      $content = coreui_provider_text($delta['content'] ?? null);
      $thinking = coreui_provider_reasoning($delta);
      if ($content !== '' || $thinking !== '') {
        $message = ['role'=>'assistant'];
        if ($content !== '') $message['content'] = $content;
        if ($thinking !== '') $message['thinking'] = $thinking;
        $out[] = (string)json_encode(['message'=>$message, 'done'=>false], $flags);
      }
      if (is_string($choice['finish_reason'] ?? null) && $choice['finish_reason'] !== '') $state->reason = $choice['finish_reason'];
    }
    return $out;
  };
  $finish = static function () use ($state, $closing): array {
    // Some providers end with a finish_reason but no [DONE]; that is a complete answer.
    return !$state->done && $state->reason !== null ? [$closing()] : [];
  };
  return ['feed'=>$feed, 'finish'=>$finish];
}

/**
 * The line of one model call: for Ollama exactly as before; for the external API the URL,
 * the headers with the key, the translated body and the translators. 'model' is the name shown in
 * the work view and counted in the usage ("extern:<model>" for the external API).
 */
function coreui_provider_wire(?array $provider, string $ollamaUrl, array $payload, string $ollamaModel): array {
  if ($provider === null) {
    return [
      'external' => false, 'url' => $ollamaUrl, 'headers' => ['Content-Type: application/json'], 'body' => $payload,
      'model' => $ollamaModel,
      'feed' => static fn(string $line): array => [$line],
      'finish' => static fn(): array => [],
      'decode' => static fn(string $raw): string => $raw,
    ];
  }
  $key = coreui_provider_key();
  $stream = !empty($payload['stream']);
  $translator = coreui_provider_stream_translator($key);
  $model = (string)$provider['model'];
  $body = coreui_provider_body($payload, $model);
  if ($model === 'gpt-6-luna' && strcasecmp((string)parse_url($provider['base_url'], PHP_URL_HOST), 'api.openai.com') === 0) {
    // Luna rejects top_p. Use the provider's generation defaults for this model.
    unset($body['top_p'], $body['temperature'], $body['seed'], $body['stop']);
  }
  return [
    'external' => true,
    'url' => $provider['base_url'] . '/chat/completions',
    'headers' => ['Content-Type: application/json', 'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
      'Authorization: Bearer ' . $key],
    'body' => $body,
    'model' => 'extern:' . $model,
    'feed' => $translator['feed'],
    'finish' => $translator['finish'],
    'decode' => static fn(string $raw): string => coreui_provider_decode($raw, $key),
  ];
}

/** The default transport of the connection test and the provider list: one GET, short time limits. */
function coreui_provider_transport(): callable {
  return static function (string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5,
      CURLOPT_TIMEOUT=>COREUI_PROVIDER_TEST_TIMEOUT, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_NOSIGNAL=>true]);
    $body = curl_exec($ch);
    $result = ['code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body'=>is_string($body) ? $body : '',
      'error'=>curl_errno($ch) !== 0 ? 'curl_' . curl_errno($ch) : '', 'ms'=>(int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
    curl_close($ch);
    return $result;
  };
}

/**
 * 0.8.15: the provider's Cores (Admin Core > Cores vom Anbieter laden, and the connection test):
 * GET {base}/models with the key. No model is asked anything (no tokens, not in the model queue).
 * Works before the adapter is switched on; it needs base URL and key. $transport is replaceable in
 * tests: fn(url, headers) => ['code'=>int, 'body'=>string, 'error'=>string, 'ms'=>int].
 * reason: not_ready, unreachable, key_refused, rate_limited, http_error, no_model_list or listed;
 * ids: the valid names, sorted, at most COREUI_PROVIDER_LIST_MAX.
 */
function coreui_provider_list(array $capability, string $key, ?callable $transport = null): array {
  if (empty($capability['connection_ready']) || $key === '') {
    $missing = array_values(array_intersect((array)($capability['missing'] ?? []), ['base_url', 'base_url_invalid', 'key', 'key_unreadable']));
    return ['ok'=>false, 'reason'=>'not_ready', 'missing'=>$missing, 'ids'=>[]];
  }
  $transport = $transport ?? coreui_provider_transport();
  $answer = $transport((string)$capability['base_url'] . '/models', ['Accept: application/json', 'Authorization: Bearer ' . $key]);
  $code = (int)($answer['code'] ?? 0);
  $body = (string)($answer['body'] ?? '');
  $result = ['ok'=>false, 'http_code'=>$code, 'elapsed_ms'=>max(0, (int)($answer['ms'] ?? 0)), 'ids'=>[]];
  if ($code === 0) return $result + ['reason'=>'unreachable'];
  if ($code === 401 || $code === 403) return $result + ['reason'=>'key_refused', 'message'=>coreui_provider_error_message($body, $key)];
  if ($code === 404 || $code === 405) return ['ok'=>true, 'reason'=>'no_model_list'] + $result;
  if ($code === 429) return $result + ['reason'=>'rate_limited', 'message'=>coreui_provider_error_message($body, $key)];
  if ($code < 200 || $code >= 300) return $result + ['reason'=>'http_error', 'message'=>coreui_provider_error_message($body, $key)];
  $json = json_decode($body, true);
  $ids = [];
  foreach ((array)(is_array($json) ? ($json['data'] ?? $json['models'] ?? []) : []) as $row) {
    $raw = is_array($row) ? ($row['id'] ?? $row['name'] ?? null) : $row;
    $name = is_string($raw) ? coreui_provider_model_name($raw) : '';
    if ($name !== '') $ids[$name] = true;
  }
  $ids = array_keys($ids);
  sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
  $ids = array_slice($ids, 0, COREUI_PROVIDER_LIST_MAX);
  return ['ok'=>true, 'reason'=>$ids === [] ? 'no_model_list' : 'listed', 'ids'=>$ids] + $result;
}

/**
 * Admin Core > Verbindung prüfen: reads the provider's list (coreui_provider_list) and says which of
 * the offered Cores it does not list. Works while the adapter is still switched off ('enabled' tells
 * the page). reason: the list's failure, no_model_list, connected (no Core offered yet),
 * models_listed or models_not_listed.
 */
function coreui_provider_test(array $capability, string $key, ?callable $transport = null): array {
  $list = coreui_provider_list($capability, $key, $transport);
  $offered = array_values((array)($capability['models'] ?? []));
  $out = ['ok'=>(bool)$list['ok'], 'reason'=>(string)$list['reason'], 'enabled'=>!empty($capability['enabled']), 'models'=>$offered]
    + array_intersect_key($list, array_flip(['http_code', 'elapsed_ms', 'message', 'missing']));
  if (!$list['ok'] || $list['reason'] === 'no_model_list') return $out;
  $out['models_count'] = count($list['ids']);
  if ($offered === []) return ['reason'=>'connected'] + $out;
  $notListed = array_values(array_filter($offered, static fn(string $m): bool => !in_array($m, $list['ids'], true)));
  return ['reason'=>$notListed === [] ? 'models_listed' : 'models_not_listed', 'models_not_listed'=>$notListed] + $out;
}
