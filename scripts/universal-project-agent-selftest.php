<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_universal_project.php';

function up_test(bool $ok, string $msg): void {
  if (!$ok) { fwrite(STDERR,"universal project FAIL: ".$msg."\n"); exit(1); }
}
up_test(!coreui_up_enabled(), 'pilot must be disabled without explicit opt-in');
$request = 'Erstelle mir ein kleines Projekt mit HTML, CSS und JavaScript '
  . 'als ZIP zum Download, mit Ordnern fuer Assets und Quelltext.';
up_test(coreui_up_requested($request), 'ZIP creation intent');
up_test(!coreui_up_requested('Bitte erklaere was ein ZIP ist'),
  'plain explanation must not launch tools');

$manifest = [
  'archive'=>'anime-project.zip',
  'files'=>[
    ['path'=>'index.html','purpose'=>'Startseite','depends_on'=>['assets/theme.css','src/app.js']],
    ['path'=>'assets/theme.css','purpose'=>'Layout','depends_on'=>[]],
    ['path'=>'src/app.js','purpose'=>'UI logic','depends_on'=>[]],
  ],
];
$plan = coreui_up_plan_parse(json_encode($manifest,JSON_UNESCAPED_SLASHES),$request);
up_test(is_array($plan) && $plan['archive']==='anime-project.zip'
  && array_column($plan['files'],'path') === ['assets/theme.css','src/app.js','index.html'],
  'model-selected nested project paths and topological order');

$sources = [
  'assets/theme.css'=>"body { color: white; background: #303; }",
  'src/app.js'=>"const greeting = 'hello';\nconsole.log(greeting);",
  'index.html'=>'<!DOCTYPE html><html><head>'
    . '<link rel="stylesheet" href="assets/theme.css"></head>'
    . '<body><script src="src/app.js"></script></body></html>',
];
$calls = [];
$fake = static function(string $prompt,string $phase,string $path,int $attempt)
  use (&$calls,$manifest,$sources): array {
  $calls[] = [$phase,$path,$attempt];
  if ($phase==='plan') return ['text'=>json_encode($manifest),'truncated'=>false];
  if ($phase==='source') return [
    'text'=>$sources[$path]."\n<<<COREUI_END_FILE>>>",
    'truncated'=>false
  ];
  return ['text'=>'','truncated'=>true];
};
$result = coreui_up_build($request,$fake,100000);
up_test(($result['ok']??false)===true && ($result['source_count']??0)===3,
  'complete serial project built');
up_test($calls === [
  ['plan','',1],['source','assets/theme.css',1],
  ['source','src/app.js',1],['source','index.html',1]
], 'one serial plan and one source call per chosen file');
$writer=$result['writer']??[];
up_test(($writer['file_count']??0)===1
  && ($writer['name']??'')==='anime-project.zip'
  && str_contains($writer['code']??'','zipfile.ZipFile')
  && str_contains($writer['code']??'','z.testzip() is None')
  && !str_contains($writer['code']??'',$sources['index.html']),
  'model source is inert encoded data in trusted one-ZIP writer');

$evilPaths=['../secret.txt','/etc/passwd','src/../../evil.js','src\\.env',
  'C:/boot.py','.env','assets/picture.png','file with spaces.js','a//b.js',
  "a\0b.txt",'one/two/three/four/five.js'];
foreach ($evilPaths as $path) up_test(!coreui_up_path_ok($path),'unsafe path '.$path);
up_test(coreui_up_path_ok('src/components/App.tsx')
  && coreui_up_path_ok('docs/README.md'),
  'nested generic text source formats supported');
$bad = $manifest;
$bad['files'][0]['path']='../steal.php';
up_test(coreui_up_plan_parse(json_encode($bad),$request)===null,'reject traversal');
$bad = $manifest;
$bad['files'][2]['depends_on']=['index.html'];
up_test(coreui_up_plan_parse(json_encode($bad),$request)===null,'reject circular references');
$sharedNames = $manifest;
$sharedNames['files'][1]['path']='other/index.html';
up_test(coreui_up_plan_parse(json_encode($sharedNames),$request)!==null,
  'different project folders may contain the same basename in one ZIP');
$bad = $manifest;
$bad['files'][1]['path']='INDEX.HTML';
up_test(coreui_up_plan_parse(json_encode($bad),$request)===null,
  'reject duplicate paths regardless of case');
$bad = $manifest;
$bad['files'][0]['depends_on']=['missing.js'];
up_test(coreui_up_plan_parse(json_encode($bad),$request)===null,
  'reject dependencies not present in project');
$bad = $manifest;
$bad['files']=array_fill(0,11, $manifest['files'][0]);
up_test(coreui_up_plan_parse(json_encode($bad),$request)===null,'limit project file count');

$cut = coreui_up_chunk("const value = 1;\n",true);
$tail = coreui_up_chunk("console.log(value);\n<<<COREUI_END_FILE>>>",false);
up_test(is_array($cut) && !$cut['complete'] && is_array($tail) && $tail['complete'],
  'explicit end marker protects truncated model output');
up_test(coreui_up_append($cut['bytes'],$tail['bytes'])
  === "const value = 1;\nconsole.log(value);",
  'safe bounded continuation append');
up_test(coreui_up_chunk("[PY]evil[/PY]\n<<<COREUI_END_FILE>>>",true)===null,
  'never execute model-generated tool code');
up_test(coreui_up_source_valid('index.html',$sources['index.html'])
  && coreui_up_source_valid('src/app.js',$sources['src/app.js'])
  && coreui_up_source_valid('config.json','{"valid":true}')
  && !coreui_up_source_valid('config.json','{"missing":}')
  && !coreui_up_source_valid('index.html','<html>cut'),
  'source validation catches incomplete structural formats');
up_test(coreui_up_project_valid($plan,array_change_key_case($sources,CASE_LOWER)),
  'cross-file asset references resolve inside planned tree');
$badSource=$sources;
$badSource['index.html']=str_replace('assets/theme.css','assets/missing.css',$badSource['index.html']);
up_test(!coreui_up_project_valid($plan,array_change_key_case($badSource,CASE_LOWER)),
  'reject HTML references to non-existent local project files');

$events=[]; $noOutput=static function($prompt,$phase,$path,$attempt) use($manifest,&$events) {
  $events[] = [$phase,$attempt];
  if ($phase==='plan') return ['text'=>json_encode($manifest),'truncated'=>false];
  return ['text'=>"body {\n", 'truncated'=>true];
};
$failed=coreui_up_build($request,$noOutput,100000);
up_test(($failed['ok']??true)===false && $failed['reason']==='source_incomplete'
  && count($events) <= 2+3*2,
  'truncation must abort without fabricating ZIP or looping indefinitely');

$more=[];
$truncated=static function($prompt,$phase,$path,$attempt) use($manifest,$sources,&$more) {
  $more[]=[$phase,$path,$attempt];
  if ($phase==='plan') return ['text'=>json_encode($manifest),'truncated'=>false];
  if ($phase==='source' && $path==='src/app.js')
    return ['text'=>"const greeting = 'hello';\n",'truncated'=>true];
  if ($phase==='continue')
    return ['text'=>"console.log(greeting);\n<<<COREUI_END_FILE>>>",'truncated'=>false];
  return ['text'=>$sources[$path]."\n<<<COREUI_END_FILE>>>",'truncated'=>false];
};
$resumed=coreui_up_build($request,$truncated,100000);
up_test(($resumed['ok']??false)===true
  && in_array(['continue','src/app.js',1],$more,true),
  'model output may be continued without restarting all files');

up_test(coreui_up_edit_once("alpha beta gamma","beta","BETA") === "alpha BETA gamma"
  && coreui_up_edit_once("repeat repeat","repeat","X") === null
  && coreui_up_edit_once("code","absent","X") === null,
  'exact unique search-replace edit, never ambiguous fuzzy patch');

$pythonRequest='Schreibe ein kleines Python-Paket mit README als ZIP zum Download.';
$pythonPlan=['archive'=>'python-tool.zip','files'=>[
  ['path'=>'src/tool.py','purpose'=>'CLI tool','depends_on'=>[]],
  ['path'=>'README.md','purpose'=>'Documentation','depends_on'=>['src/tool.py']],
]];
$pythonSource=[
  'src/tool.py'=>"def main():\n    print('Hello')\n\nif __name__ == '__main__':\n    main()\n",
  'README.md'=>"# Small CLI\nRun python src/tool.py\n"
];
$pyFake=static function($prompt,$phase,$path,$attempt) use($pythonPlan,$pythonSource) {
  return ['text'=>$phase==='plan' ? json_encode($pythonPlan)
    : $pythonSource[$path]."\n<<<COREUI_END_FILE>>>",'truncated'=>false];
};
$pyResult=coreui_up_build($pythonRequest,$pyFake,100000);
up_test(($pyResult['ok']??false)===true
  && ($pyResult['plan']['archive']??'')==='python-tool.zip'
  && array_column($pyResult['plan']['files'],'path')===['src/tool.py','README.md'],
  'universal project tool must work beyond HTML, CSS and JavaScript');

echo "CoreUI universal project pilot selftest: OK\n";
