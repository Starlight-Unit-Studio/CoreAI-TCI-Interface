<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/skills.php';
function skill_assert(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
$catalog = coreui_skills_catalog();
skill_assert(count($catalog) >= 33, 'Starter catalog incomplete');
skill_assert(coreui_skills_required_ids() === ['werkzeuge'], 'Exactly one mandatory Werkzeuge skill expected');
skill_assert(($catalog['werkzeuge']['required'] ?? false) === true, 'Required catalog metadata');
foreach ($catalog as $id=>$row) {
  $body = coreui_skills_body($id);
  skill_assert(strlen($body) > 120 && strlen($body) <= 2400, 'Invalid body: '.$id);
  skill_assert($row['triggers'] !== [] && $row['description'] !== '', 'Missing metadata');
  foreach ($row['sources'] as $source) skill_assert(str_starts_with($source,'https://'), 'Invalid source');
  if (($row['required'] ?? false) === true) {
    skill_assert(coreui_skills_select('/skill '.$id, [$id=>true]) === [], 'Required skill excluded from optional routing');
  } else {
    skill_assert(coreui_skills_select('/skill '.$id, [$id=>true]) === [$id], 'Explicit selection: '.$id);
  }
  skill_assert(coreui_skills_select('/skill '.$id, [$id=>false]) === [], 'Disabled selection: '.$id);
}
$all = array_fill_keys(array_keys($catalog),true);
skill_assert(coreui_skills_select('Wie geht es dir heute?', $all) === [], 'Casual message activates optional skills');
skill_assert(!in_array('werkzeuge',coreui_skills_select('werkzeug tool agent', $all),true), 'Required skill consumes optional slots');
skill_assert(coreui_skills_select('Das ist typisch und keine Sprache.', ['python'=>true]) === [], 'Substring triggers');
skill_assert(coreui_skills_select('Schreibe ein Python-Skript', ['python'=>true]) === ['python'], 'Natural trigger');
skill_assert(count(coreui_skills_select('projekt html css python php sql git tests zip', $all)) === 4, 'Budget');
skill_assert(coreui_skills_select('projekt html css python php sql git tests zip /skill zip-delivery', $all)[0] === 'zip-delivery', 'Explicit priority');
foreach ([['../../config'=>true], ['python'=>'false'], ['python'=>1], ['werkzeuge'=>false]] as $invalid) {
  try { coreui_skills_validate($invalid); throw new LogicException('Invalid preferences accepted'); }
  catch (InvalidArgumentException $expected) {}
}
skill_assert(coreui_skills_body('../config') === '', 'Path traversal');
unset($GLOBALS['COREUI_SKILLS_BLOCK']);
skill_assert(coreui_skills_augment('Hallo') === 'Hallo', 'Disabled path changed prompt');
$GLOBALS['COREUI_SKILLS_BLOCK'] = 'INSTRUCTIONS';
skill_assert(coreui_skills_augment('QUESTION') === 'INSTRUCTIONSQUESTION', 'Context lost');
unset($GLOBALS['COREUI_SKILLS_BLOCK']);
$root = dirname(__DIR__);
foreach (['chat.php','console_stream.php'] as $file) {
  $code = file_get_contents($root.'/api/'.$file);
  skill_assert(str_contains($code,'$userPrompt = coreui_skills_augment($userPrompt, $augmentSkills);'), 'Required-skill inference hook missing: '.$file);
}
$api = file_get_contents($root.'/api/user_skills.php');
skill_assert(str_contains($api,'stu_require_user_id()') && str_contains($api,'stu_require_csrf()'), 'Endpoint guards');
// Optional persistence tests with actual SQLite storage. Only the MySQL upsert
// is translated in this test adapter; this does not validate MariaDB migration.
if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  class SkillTestPDO extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
      $query = str_replace('ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)',
        'ON CONFLICT(user_id,skill_id) DO UPDATE SET enabled=excluded.enabled', $query);
      return parent::prepare($query,$options);
    }
  }
  $pdo = new SkillTestPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  skill_assert(!coreui_skills_schema_ready($pdo), 'Missing schema');
  $missingPrefs = coreui_skills_load($pdo,1);
  skill_assert($missingPrefs['werkzeuge'] === true && $missingPrefs['python'] === false,
    'Required skill must remain active even without migration');
  try { coreui_skills_save($pdo,1,['python'=>true]); throw new LogicException('False save success'); }
  catch (RuntimeException $expected) { skill_assert($expected->getMessage()==='skills_migration_required','Wrong missing schema error'); }
  $pdo->exec('CREATE TABLE stu_user_skills(user_id INTEGER,skill_id TEXT,enabled INTEGER,PRIMARY KEY(user_id,skill_id))');
  $pdo->exec("INSERT INTO stu_user_skills(user_id,skill_id,enabled) VALUES(1,'werkzeuge',0)");
  skill_assert(coreui_skills_load($pdo,1)['werkzeuge'] === true,
    'Legacy disabled mandatory row must be overridden');
  try {
    coreui_skills_save($pdo,1,['werkzeuge'=>false]);
    throw new LogicException('Required-skill disable should be rejected');
  } catch (InvalidArgumentException $expected) {
    skill_assert($expected->getMessage()==='required_skill_cannot_be_disabled','Unexpected mandatory error');
  }
  $saved = coreui_skills_save($pdo,1,['python'=>true,'zip-delivery'=>true]);
  skill_assert($saved['python'] && $saved['zip-delivery'] && $saved['werkzeuge'], 'Save/read');
  skill_assert(!coreui_skills_load($pdo,2)['python'], 'Account isolation');
  coreui_skills_save($pdo,1,['python'=>false]);
  skill_assert(!coreui_skills_load($pdo,1)['python'] && coreui_skills_load($pdo,1)['zip-delivery'], 'Partial save preservation');
  $pdo->exec("CREATE TRIGGER reject_css BEFORE INSERT ON stu_user_skills WHEN NEW.skill_id='responsive-css' BEGIN SELECT RAISE(ABORT,'test failure'); END");
  try { coreui_skills_save($pdo,1,['python'=>true,'responsive-css'=>true]); throw new LogicException('Write should fail'); }
  catch (PDOException $expected) {}
  skill_assert(!coreui_skills_load($pdo,1)['python'], 'Transaction rollback');
  coreui_skills_prepare($pdo,1,'/skill zip-delivery');
  $prepared = coreui_skills_augment('task');
  skill_assert(str_contains($prepared,'ZIP und Dateiauslieferung')
    && str_contains($prepared,'Werkzeuge: verbindlicher CoreAI-Kern-Skill'),
    'Runtime did not load mandatory and optional skill');
  $focused = coreui_skills_augment('task', false);
  skill_assert(str_contains($focused,'Werkzeuge: verbindlicher CoreAI-Kern-Skill')
    && !str_contains($focused,'ZIP und Dateiauslieferung'),
    'Focused subcall must retain mandatory but omit optional skills');
  coreui_skills_prepare($pdo,2,'/skill zip-delivery');
  $secondAccount = coreui_skills_augment('task');
  skill_assert(str_contains($secondAccount,'Werkzeuge: verbindlicher CoreAI-Kern-Skill')
    && !str_contains($secondAccount,'ZIP und Dateiauslieferung'), 'Optional skill leaked between users');
  echo "Skill persistence (SQLite adapter): OK\n";
} else { echo "Skill persistence: SKIP (pdo_sqlite unavailable)\n"; }
// Public Skills endpoint must be explicitly allowed by BOTH restricted Nginx
// configurations, otherwise even an authenticated GET receives HTTP 404.
foreach (['config/runtime/nginx.conf.template','config/nginx.conf.template'] as $nginxFile) {
  $nginxSource = (string)file_get_contents($root . '/' . $nginxFile);
  skill_assert(
    str_contains($nginxSource, '|user_skills|')
      && str_contains($nginxSource, 'location ~ \.php$'),
    'Skills API denied by Nginx allowlist: ' . $nginxFile
  );
}
echo "CoreAI skills catalog, mandatory Werkzeuge, optional routing and context: OK\n";
