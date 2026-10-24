<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_universal_source_protocol.php';
function usp_check(bool $condition, string $message): void {
  if (!$condition) { fwrite(STDERR, "Universal source protocol FAIL: ".$message."\n"); exit(1); }
}
$css = ":root { --background: #223344; }\nbody { color: var(--background); }";
$raw = coreui_usp_parse($css, 'style.css');
usp_check(is_array($raw) && $raw['bytes'] === $css, 'raw CSS must preserve source bytes');
usp_check(coreui_usp_reply_complete(['done_reason'=>'stop'], $raw),
  'normal Ollama stop completes valid raw source even without magic marker');
usp_check(!coreui_usp_reply_complete(['done_reason'=>'length'], $raw),
  'length-stopped output must never be treated as complete');
usp_check(coreui_usp_reply_truncated(['done_reason'=>'length']),
  'length stops require generic continuation regardless of extension');
usp_check(!coreui_usp_reply_complete(['done'=>false,'done_reason'=>'stop'], $raw),
  'incomplete Ollama response must not count as stop');
$fenced = coreui_usp_parse("### style.css\n" . chr(96).chr(96).chr(96)
  . "css\n".$css."\n".chr(96).chr(96).chr(96), 'style.css');
usp_check(is_array($fenced) && $fenced['bytes'] === $css && $fenced['fence_closed'],
  'a single source fence can be stripped without changing content');
$open = coreui_usp_parse(str_repeat(chr(96),3) . "css\n".$css, 'style.css');
usp_check(is_array($open) && $open['bytes'] === $css && !$open['fence_closed'],
  'an open outer fence is parsed as inert bytes');
usp_check(coreui_usp_reply_complete(['done_reason'=>'stop'], $open),
  'normal Ollama stop plus source validation can recover unclosed outer fence');
$marked = coreui_usp_parse($css . "\n<<<COREUI_END_FILE>>>", 'style.css');
usp_check(is_array($marked) && $marked['bytes'] === $css && $marked['marked'],
  'legacy optional completion marker recognized');
usp_check(coreui_usp_reply_complete([], $marked),
  'explicit marker can complete an otherwise unknown response');
usp_check(!coreui_usp_reply_complete(['done_reason'=>'length'], $marked),
  'premature marker cannot override a known length stop');
$js = "const x = 1;\nconsole.log(x);\n";
usp_check(coreui_usp_parse($js, 'src/app.js')['bytes'] === $js,
  'same protocol must preserve JavaScript');
usp_check(coreui_usp_parse("Here is the code:\n" . $css, 'style.css') !== null,
  'raw text parser defers content validation to the existing source validator');
usp_check(coreui_usp_parse("[PY]print(1)[/PY]", 'style.css') === null,
  'model-generated executable Python marker rejected');
usp_check(coreui_usp_parse($css . "\n<<<COREUI_END_FILE>>>\nother text", 'style.css') === null,
  'trailing text after explicit completion marker rejected');
usp_check(!coreui_usp_reply_complete([], $raw),
  'unknown finish without explicit end is never silently accepted');
echo "CoreUI universal source protocol selftest: OK\n";
