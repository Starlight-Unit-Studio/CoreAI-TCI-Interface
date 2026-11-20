<?php
declare(strict_types=1);

/*
 * 0.8.11 (CF-003, CF-005): the external OpenAI-compatible adapter.
 *
 * When the operator configured the adapter in Admin Core (switched on, base URL, model; the key
 * only on the server in COREUI_EXTERNAL_API_KEY or STU_EXTERNAL_API_KEY) and an Inhaber or Admin
 * chose "Externe API" as Core-Pfad, every chat model call of that account goes to
 * {base}/chat/completions instead of local Ollama.
 *
 * Only the inference endpoint changes. Prompt, skills, tools, work loop, the serial model queue,
 * cancelling and the time limits stay exactly as they are for the chosen Core, so a run can be
 * compared with a local one (to rule a Core in or out as the cause of a fault). The request is
 * built from the Ollama /api/chat payload and the answer is turned back into Ollama's shape
 * (message.content, message.thinking, done, done_reason, eval_count, prompt_eval_count), so the
 * existing parsing stays untouched. Ollama-only settings (think, keep_alive, num_ctx, num_predict,
 * top_k and the tuning options) are not sent. Audio, live voice and the browser worker stay local.
 *
 * coreui_provider_wire() gives both call sites one "line": unchanged for Ollama, translated for the
 * external API. The key is read only when the request is built and never stored, shown or logged.
 */

const COREUI_PROVIDER_LABEL_DEFAULT = 'OpenAI-kompatibel';
const COREUI_PROVIDER_ERROR_CHARS = 300;
const COREUI_PROVIDER_TEST_TIMEOUT = 10;

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

/** A model name of the provider (pure): letters, digits and ._:/@+- , at most 160; '' when not. */
function coreui_provider_model_name(string $value): string {
  $model = trim($value);
  return preg_match('~^[A-Za-z0-9._:/@+-]{1,160}$~D', $model) === 1 ? $model : '';
}

/** The key from the server configuration, '' without one. Never stored elsewhere. */
function coreui_provider_key(): string {
  return defined('STU_EXTERNAL_API_KEY') ? trim((string)constant('STU_EXTERNAL_API_KEY')) : '';
}

/**
 * The adapter as the operator configured it (pure): $settings are the app settings
 * external_api_enabled, _label, _base_url and _model. 'configured' only when everything is there.
 */
function coreui_provider_capability(array $settings, bool $keyConfigured): array {
  $enabled = (string)($settings['external_api_enabled'] ?? '0') === '1';
  $label = trim((string)($settings['external_api_label'] ?? ''));
  $rawUrl = trim((string)($settings['external_api_base_url'] ?? ''));
  $rawModel = trim((string)($settings['external_api_model'] ?? ''));
  $baseUrl = coreui_provider_base_url($rawUrl);
  $model = coreui_provider_model_name($rawModel);
  $missing = [];
  if ($rawUrl === '') $missing[] = 'base_url';
  elseif ($baseUrl === '') $missing[] = 'base_url_invalid';
  if ($rawModel === '') $missing[] = 'model';
  elseif ($model === '') $missing[] = 'model_invalid';
  if (!$keyConfigured) $missing[] = 'key';
  $configured = $enabled && $missing === [];
  return [
    'configured' => $configured,
    'configuration_complete' => $configured,
    'enabled' => $enabled,
    'label' => $label !== '' ? mb_substr($label, 0, 160, 'UTF-8') : COREUI_PROVIDER_LABEL_DEFAULT,
    'base_url' => $baseUrl !== '' ? $baseUrl : $rawUrl,
    'model' => $model !== '' ? $model : $rawModel,
    'key_configured' => $keyConfigured,
    'missing' => $missing,
    'status' => !$enabled ? 'disabled' : ($configured ? 'ready' : 'incomplete'),
  ];
}

/**
 * Whether this account may use the external API (pure): the adapter is configured, the account
 * chose it, and it is the Inhaber (0) or an Admin (1); never a guest. The API costs money and
 * sends prompts to a third party, so this is not for every account.
 */
function coreui_provider_account_external(array $capability, string $provider, int $level, bool $guest): ?array {
  if ($provider !== 'external' || empty($capability['configured']) || $guest || $level < 0 || $level > 1) return null;
  return ['label'=>(string)$capability['label'], 'base_url'=>(string)$capability['base_url'], 'model'=>(string)$capability['model']];
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
  return [
    'external' => true,
    'url' => $provider['base_url'] . '/chat/completions',
    'headers' => ['Content-Type: application/json', 'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
      'Authorization: Bearer ' . $key],
    'body' => coreui_provider_body($payload, (string)$provider['model']),
    'model' => 'extern:' . $provider['model'],
    'feed' => $translator['feed'],
    'finish' => $translator['finish'],
    'decode' => static fn(string $raw): string => coreui_provider_decode($raw, $key),
  ];
}

/**
 * Admin Core > Verbindung prüfen: GET {base}/models with the key. No model is asked anything (no
 * tokens, not in the model queue). $transport is replaceable in tests: fn(url, headers) =>
 * ['code'=>int, 'body'=>string, 'error'=>string, 'ms'=>int].
 */
function coreui_provider_test(array $capability, string $key, ?callable $transport = null): array {
  if (empty($capability['configured'])) {
    return ['ok'=>false, 'reason'=>'not_configured', 'missing'=>(array)($capability['missing'] ?? [])];
  }
  $transport = $transport ?? static function (string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5,
      CURLOPT_TIMEOUT=>COREUI_PROVIDER_TEST_TIMEOUT, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_NOSIGNAL=>true]);
    $body = curl_exec($ch);
    $result = ['code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body'=>is_string($body) ? $body : '',
      'error'=>curl_errno($ch) !== 0 ? 'curl_' . curl_errno($ch) : '', 'ms'=>(int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
    curl_close($ch);
    return $result;
  };
  $answer = $transport((string)$capability['base_url'] . '/models', ['Accept: application/json', 'Authorization: Bearer ' . $key]);
  $code = (int)($answer['code'] ?? 0);
  $result = ['ok'=>false, 'http_code'=>$code, 'elapsed_ms'=>max(0, (int)($answer['ms'] ?? 0)), 'model'=>(string)$capability['model']];
  if ($code === 0) return $result + ['reason'=>'unreachable'];
  if ($code === 401 || $code === 403) {
    return $result + ['reason'=>'key_refused', 'message'=>coreui_provider_error_message((string)($answer['body'] ?? ''), $key)];
  }
  if ($code === 404 || $code === 405) return ['ok'=>true, 'reason'=>'no_model_list'] + $result;
  if ($code === 429) return $result + ['reason'=>'rate_limited', 'message'=>coreui_provider_error_message((string)($answer['body'] ?? ''), $key)];
  if ($code < 200 || $code >= 300) {
    return $result + ['reason'=>'http_error', 'message'=>coreui_provider_error_message((string)($answer['body'] ?? ''), $key)];
  }
  $json = json_decode((string)($answer['body'] ?? ''), true);
  $ids = [];
  foreach ((array)(is_array($json) ? ($json['data'] ?? $json['models'] ?? []) : []) as $row) {
    if (is_array($row) && is_string($row['id'] ?? $row['name'] ?? null)) $ids[] = (string)($row['id'] ?? $row['name']);
  }
  $listed = in_array((string)$capability['model'], $ids, true);
  return ['ok'=>true, 'reason'=>$ids === [] ? 'no_model_list' : ($listed ? 'model_listed' : 'model_not_listed'),
    'models_count'=>count($ids)] + $result;
}
