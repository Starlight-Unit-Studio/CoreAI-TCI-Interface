<?php
declare(strict_types=1);
// Offline-only Hugging Face GGUF model metadata checks. No network, token,
// database, or real installation is used in this test.
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/huggingface_library.php';
function check_hf(bool $ok, string $reason): void {
  if (!$ok) { fwrite(STDERR, "HF library FAIL: $reason\n"); exit(1); }
}
$search = [
  ['id'=>'author/Public-GGUF', 'tags'=>['gguf', 'license:apache-2.0'], 'private'=>false, 'gated'=>false],
  ['id'=>'author/Private-GGUF', 'tags'=>['gguf'], 'private'=>true],
  ['id'=>'author/Gated-GGUF', 'tags'=>['gguf'], 'gated'=>'auto'],
  ['id'=>'author/Not-GGUF', 'tags'=>['text-generation']],
  ['id'=>'http://bad/GGUF', 'tags'=>['gguf']],
  ['id'=>'author/Public-GGUF', 'tags'=>['gguf']],
];
$results = coreui_hf_search_parse($search);
check_hf(count($results) === 1 && $results[0]['name'] === 'author/Public-GGUF', 'only verified public GGUF model appears');
check_hf($results[0]['url'] === 'https://huggingface.co/author/Public-GGUF', 'fixed official model origin');
check_hf(!coreui_hf_repo_valid('../escape') && !coreui_hf_repo_valid('https://evil.test/x'), 'repo validation');

$model = ['id'=>'author/Public-GGUF', 'tags'=>['gguf'], 'cardData'=>['license'=>'apache-2.0']];
$tree = [
  ['path'=>'model-Q4_K_M.gguf', 'type'=>'file', 'size'=>4294967296],
  ['path'=>'model-Q8_0.gguf', 'type'=>'file', 'size'=>8589934592],
  ['path'=>'model-00001-of-00002.gguf', 'type'=>'file', 'size'=>1048576],
  ['path'=>'subfolder/model.gguf', 'type'=>'file', 'size'=>1048576],
  ['path'=>'weights.safetensors', 'type'=>'file'],
];
$detail = coreui_hf_detail_parse($model, $tree, 'author/Public-GGUF');
check_hf(count($detail['tags']) === 2 && $detail['license'] === 'apache-2.0', 'quantizations and license retained');
$allowed = 'hf.co/author/Public-GGUF:model-Q4_K_M.gguf';
check_hf(coreui_hf_install_allowed($allowed, $detail), 'exact GGUF tag available to local Ollama pull');
check_hf(!coreui_hf_install_allowed('hf.co/author/Public-GGUF:other.gguf', $detail), 'arbitrary GGUF refused');
check_hf(!coreui_hf_install_allowed('hf.co/author/Other-GGUF:model-Q4_K_M.gguf', $detail), 'wrong repository refused');
check_hf(!coreui_hf_file_valid('model-00001-of-00002.gguf') && !coreui_hf_file_valid('sub/model.gguf'), 'shards and paths refused');
try {
  coreui_hf_detail_parse(['id'=>'author/Public-GGUF','gated'=>true], $tree, 'author/Public-GGUF');
  check_hf(false, 'gated model passed');
} catch (RuntimeException $e) {
  check_hf($e->getMessage() === 'hf_access_required', 'gated rejected before install');
}
try {
  coreui_hf_fetch_json('http://127.0.0.1:9090/api/models');
  check_hf(false, 'untrusted host contacted');
} catch (RuntimeException $e) {
  check_hf($e->getMessage() === 'invalid_registry_url', 'SSRF guarded');
}
echo "CoreAI HF GGUF library: public metadata, safe filenames, explicit license, install allowlist OK\n";
