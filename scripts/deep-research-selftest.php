<?php
declare(strict_types=1);
// CoreAI 0.7.38: Deep Research (api/deep_research_store.php, api/deep_research.php, the + menu mark).
//   php scripts/deep-research-selftest.php
// Without a database: the mark, the plan from the Core (JSON, list, fallback), titles, prompts, the
// download, the + menu mark and its answer, and the wiring (reply hook, endpoint, CSRF, nginx, chat
// deletion, worker, migration). With a database (COREUI_SELFTEST_DB=1 or api/config.local.php):
// a plan for an account, guests and accounts without web search, Starten, Bearbeiten with a changed
// plan, Abbrechen and Stopp, a lost worker, sources, activity, the download, another account, and the
// runs going with their chat. Own test accounts, removed afterwards.

$root = dirname(__DIR__);
require_once $root . '/api/deep_research_store.php';
require_once $root . '/api/console_composer_intent.php';

function dr_test(bool $ok, string $why): void {
  if ($ok) return;
  fwrite(STDERR, "Deep research FAIL: $why\n");
  if (isset($GLOBALS['cleanup'])) ($GLOBALS['cleanup'])();
  exit(1);
}
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

// --- Pure -----------------------------------------------------------------------------------------
dr_test(coreui_dr_marker_id(coreui_dr_reply_text(42, 'Analyse example.test.')) === 42, 'the mark carries the run');
dr_test(coreui_dr_reply_text(7, 'Titel.') === '[[deep-research:7]] Ich habe einen Rechercheplan erstellt: Titel.', 'one readable sentence');
dr_test(coreui_dr_marker_id('Text [[deep-research:3]]') === 0 && coreui_dr_marker_id('[[deep-research:x]]') === 0, 'only a leading mark counts');

$plan = coreui_dr_parse_plan("Gern!\n{\"title\": \"**Analyse Starlight Unit Website**\", \"steps\": [\"1. Durchsuche die Website nach Angeboten.\", "
  . "\"Extrahiere Dienstleistungen und Zielgruppen.\", {\"text\": \"Suche nach Referenzen.\"}, \"Suche nach Referenzen.\", \"kurz\", "
  . "\"Fasse alles in einem Bericht zusammen.\"]}", 'https://www.example.test');
dr_test($plan['title'] === 'Analyse Starlight Unit Website' && !$plan['fallback'], 'title from JSON without Markdown: ' . $plan['title']);
dr_test($plan['steps'] === ['Durchsuche die Website nach Angeboten.', 'Extrahiere Dienstleistungen und Zielgruppen.', 'Suche nach Referenzen.',
  'Fasse alles in einem Bericht zusammen.'], 'steps cleaned, short and double ones dropped: ' . json_encode($plan['steps'], JSON_UNESCAPED_UNICODE));
$list = coreui_dr_parse_plan("- Suche nach Förderprogrammen 2026.\n- Vergleiche die Anbieter.\n* Fasse die Ergebnisse zusammen.", 'Balkonkraftwerk');
dr_test(count($list['steps']) === 3 && $list['title'] === 'Recherche: Balkonkraftwerk', 'a list without JSON, title from the question');
$fallback = coreui_dr_parse_plan('{"steps": ["Nur einer."]}', 'Sieh dir https://www.example.test/shop an');
dr_test($fallback['fallback'] && $fallback['title'] === 'Analyse example.test' && str_contains($fallback['steps'][0], 'example.test')
  && count($fallback['steps']) === 4, 'fewer than three steps: the honest fallback plan');
$six = coreui_dr_parse_plan('{"steps": ["Schritt eins ist da.","Schritt zwei ist da.","Schritt drei ist da.","Schritt vier ist da.","Schritt fünf ist da.","Schritt sechs ist da.","Schritt sieben ist da."]}', 'x');
dr_test(count($six['steps']) === 6, 'at most six steps');
dr_test(mb_strlen(coreui_dr_clean_line(str_repeat('a', 300), 220)) === 220, 'a long step is cut');
[$sys, $user] = coreui_dr_plan_prompts('Thema X', ['title' => 'Alt', 'steps' => ['A', 'B']], 'mehr zu Preisen');
dr_test(str_contains($sys, '"steps"') && str_contains($user, 'Bisheriger Plan: Alt') && str_contains($user, "1. A\n2. B")
  && str_contains($user, 'Aenderungswunsch der Person: mehr zu Preisen'), 'a changed plan sees the old one and the change');
dr_test(coreui_dr_file_name('Starlight Unit Studios: Märkte & Größe') === 'deep-research-starlight-unit-studios-maerkte-groesse.md', 'file name');
$md = coreui_dr_download_markdown('T', "# T\n\nText [1].", [['n' => 1, 'url' => 'https://a.test/', 'title' => 'Seite [A]']]);
dr_test(str_contains($md, "## Quellen\n\n1. [Seite (A)](https://a.test/)"), 'the download lists the numbered sources');

// --- The + menu mark ------------------------------------------------------------------------------
$intent = coreui_composer_intent_peek('[[deep-research]] https://www.example.test');
dr_test($intent === ['tool' => 'deep', 'size' => 'normal'], 'the Deep Research mark');
dr_test(coreui_composer_intent_peek('[[deep-research-edit:12]] mehr zu Preisen') === ['tool' => 'deep', 'size' => 'normal', 'edit' => 12], 'the mark of a changed plan');
dr_test(coreui_composer_intent_label(['tool' => 'deep']) === 'Deep Research'
  && coreui_composer_intent_label(['tool' => 'deep', 'edit' => 1]) === 'Deep Research · Plan ändern', 'chip labels');
dr_test(coreui_composer_intent_strip('[[deep-research]] Frage') === 'Frage', 'the mark leaves the text');
$tools = coreui_composer_tools_answer(false, true, true, true);
dr_test($tools['tools']['deep']['on'] === true && $tools['planned'] === ['connectors'], 'Deep Research is no longer only announced');
dr_test(coreui_composer_tools_answer(true, true, true, true)['tools']['deep']['on'] === false, 'not for guests');
dr_test(coreui_composer_tools_answer(false, true, false, true)['tools']['deep']['why'] !== '', 'without web search it says why');

// --- Wiring ---------------------------------------------------------------------------------------
$chat = $read('api/chat.php');
$gen = substr($chat, strpos($chat, 'function ember_generate_reply('));
dr_test(strpos($gen, 'coreui_dr_reply(') !== false && strpos($gen, 'coreui_dr_reply(') < strpos($gen, 'ember_attachment_block('),
  'Deep Research answers before anything is prepared for the Core');
dr_test(str_contains($chat, "require_once __DIR__ . '/deep_research_store.php';"), 'chat.php loads the store');
$api = $read('api/deep_research.php');
dr_test(strpos($api, 'stu_require_csrf();') < strpos($api, "'start' => coreui_dr_start(") && str_contains($api, 'stu_require_user_id()')
  && str_contains($api, 'Content-Disposition: attachment'), 'endpoint: account, CSRF for changes, download');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $conf) {
  dr_test(preg_match('~location \~ \^/api/\(\?:[a-z_|]*\|deep_research\|[a-z_|]*\)\\\\\.php\$~', $read($conf)) === 1, "nginx lets the endpoint through: $conf");
}
$sessions = $read('api/console_session_store.php');
dr_test(str_contains($sessions, "status IN ('queued','running','writing')") && str_contains($sessions, 'coreui_dr_delete_session($pdo, $uid, $sessionId)'),
  'a chat with a running research is busy, else its runs go with it');
$worker = $read('tools/ember_browse_worker.py');
dr_test(str_contains($worker, 'def claim_next_research(conn):') && str_contains($worker, 'process_research(conn, cfg, research, log)')
  && is_file($root . '/tools/coreai_deep_research.py'), 'the browse worker runs Deep Research');
$migration = $read('database/migrations/040_deep_research.sql');
foreach (['stu_deep_research_runs', 'stu_deep_research_sources', 'stu_deep_research_events'] as $table) {
  dr_test(str_contains($migration, "CREATE TABLE IF NOT EXISTS $table"), "migration creates $table");
}

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI deep research: mark, plan, fallback, prompts, download, + menu and wiring OK (no database here)\n";
  exit(0);
}

// --- With a database ------------------------------------------------------------------------------
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
dr_test(coreui_dr_ready($pdo), 'migration 040 is applied (stu_deep_research_*)');
$uid = random_int(1500000000, 1900000000);
$other = $uid + 1;
$sid = 'cs_' . bin2hex(random_bytes(8));
$cleanup = static function () use ($pdo, $uid, $other): void {
  $ids = implode(',', [$uid, $other]);
  foreach (["DELETE e FROM stu_deep_research_events e JOIN stu_deep_research_runs r ON r.id = e.run_id WHERE r.user_id IN ($ids)",
    "DELETE s FROM stu_deep_research_sources s JOIN stu_deep_research_runs r ON r.id = s.run_id WHERE r.user_id IN ($ids)",
    "DELETE FROM stu_deep_research_runs WHERE user_id IN ($ids)", "DELETE FROM stu_console_sessions WHERE user_id IN ($ids)",
    "DELETE FROM stu_users WHERE id IN ($ids)"] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
};
$GLOBALS['cleanup'] = $cleanup;
try {
  $ins = $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 3, NOW())');
  $ins->execute([$uid, 'dr-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  $ins->execute([$other, 'dr-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, title) VALUES (?, ?, ?)')->execute([$sid, $uid, 'Recherche']);

  dr_test(str_contains(coreui_dr_reply($pdo, $uid, $sid, 'x', ['tool' => 'deep'], true, true), 'Gästen'), 'guests get a friendly no');
  dr_test(str_contains(coreui_dr_reply($pdo, $uid, $sid, 'x', ['tool' => 'deep'], false, false), 'Websuche'), 'without web search Mara says why');
  dr_test(str_contains(coreui_dr_reply($pdo, $uid, $sid, '  ', ['tool' => 'deep'], false, true), 'was ich gründlich recherchieren soll'), 'an empty question asks');

  // A plan (no Core in this test: the honest fallback plan).
  $reply = coreui_dr_reply($pdo, $uid, $sid, 'https://www.example.test', ['tool' => 'deep'], false, true);
  $id = coreui_dr_marker_id($reply);
  dr_test($id > 0 && str_contains($reply, 'Analyse example.test'), 'Mara answers with the plan mark: ' . $reply);
  $run = coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id));
  dr_test($run['status'] === 'planned' && count($run['steps']) === 4 && $run['states'] === ['todo', 'todo', 'todo', 'todo']
    && $run['autostart_in'] >= 40 && $run['autostart_in'] <= COREUI_DR_AUTOSTART_SECONDS, 'a planned run counts down: ' . json_encode($run));
  dr_test(coreui_dr_row($pdo, $other, $id) === null && !coreui_dr_start($pdo, $other, $id), 'another account neither sees nor starts it');
  dr_test(coreui_dr_start($pdo, $uid, $id) && !coreui_dr_start($pdo, $uid, $id), 'Starten once');
  dr_test(coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id))['status'] === 'queued', 'started runs wait for the worker');
  dr_test(coreui_dr_cancel($pdo, $uid, $id) === 'cancelled', 'Abbrechen before the worker took it');

  // Bearbeiten: the countdown stops; the next message makes a new plan, the old one is replaced.
  $id2 = coreui_dr_marker_id(coreui_dr_reply($pdo, $uid, $sid, 'Balkonkraftwerke 2026', ['tool' => 'deep'], false, true));
  dr_test(coreui_dr_edit($pdo, $uid, $id2) && coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id2))['autostart_in'] === null, 'Bearbeiten stops the countdown');
  $id3 = coreui_dr_marker_id(coreui_dr_reply($pdo, $uid, $sid, 'nur Förderungen', ['tool' => 'deep', 'edit' => $id2], false, true));
  $old = coreui_dr_row($pdo, $uid, $id2);
  $new = coreui_dr_row($pdo, $uid, $id3);
  dr_test($id3 > $id2 && $old['status'] === 'replaced' && $new['status'] === 'planned' && $new['query'] === 'Balkonkraftwerke 2026',
    'a changed plan replaces the old one and keeps the question');
  dr_test(coreui_dr_reply($pdo, $other, 'cs_' . bin2hex(random_bytes(8)), 'fremd', ['tool' => 'deep', 'edit' => $id3], false, true) !== '' && coreui_dr_row($pdo, $uid, $id3)['status'] === 'planned',
    'another account cannot change the plan');

  // Stopp while the worker runs; a worker that went silent.
  $pdo->prepare("UPDATE stu_deep_research_runs SET status='running', started_at=NOW() - INTERVAL 90 SECOND, heartbeat_at=NOW(),
                 progress_json='{\"states\":[\"done\",\"active\",\"todo\",\"todo\"],\"phase\":\"read\",\"percent\":37}', searches=5 WHERE id=?")->execute([$id3]);
  dr_test(coreui_dr_cancel($pdo, $uid, $id3) === 'running', 'Stopp asks the worker');
  $live = coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id3));
  dr_test($live['cancelling'] && $live['states'][1] === 'active' && $live['percent'] === 37 && $live['searches'] === 5 && $live['elapsed'] >= 89,
    'progress as the page sees it: ' . json_encode($live));
  $pdo->prepare('UPDATE stu_deep_research_runs SET heartbeat_at = NOW() - INTERVAL 20 MINUTE WHERE id = ?')->execute([$id3]);
  $lost = coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id3));
  dr_test($lost['status'] === 'error' && $lost['error'] === 'worker_lost', 'a silent worker ends the run honestly');

  // A finished run: sources, activity, report, download.
  $pdo->prepare("UPDATE stu_deep_research_runs SET status='done', report_md=?, citations=1, finished_at=NOW() WHERE id=?")
    ->execute(["# Förderung\n\n## Zusammenfassung\n\nEs gibt Förderung [1].", $id3]);
  $pdo->prepare('INSERT INTO stu_deep_research_sources (run_id, n, url, title, domain, cited) VALUES (?, 1, ?, ?, ?, 1), (?, 2, ?, ?, ?, 0)')
    ->execute([$id3, 'https://a.test/f', 'Förderung A', 'a.test', $id3, 'https://b.test/', 'B', 'b.test']);
  $pdo->prepare("INSERT INTO stu_deep_research_events (run_id, kind, text, domains) VALUES (?, 'search', 'förderung 2026', ?), (?, 'note', 'Ich habe zwei Programme gefunden.', '[]')")
    ->execute([$id3, json_encode(['a.test', 'b.test']), $id3]);
  $done = coreui_dr_public($pdo, coreui_dr_row($pdo, $uid, $id3), true);
  dr_test($done['has_report'] && $done['sources_count'] === 2 && $done['sources'][0]['cited'] && !$done['sources'][1]['cited']
    && $done['events'][0]['domains'] === ['a.test', 'b.test'] && $done['events'][1]['kind'] === 'note' && $done['states'] === ['done', 'done', 'done', 'done'],
    'details: report, sources, activity');
  $file = coreui_dr_download_markdown((string)$new['title'], $done['report'], $done['sources']);
  dr_test(str_starts_with($file, '# Förderung') && str_contains($file, '2. [B](https://b.test/)'), 'the download');

  // The runs go with their chat.
  dr_test(coreui_dr_delete_session($pdo, $other, $sid) === 0, 'another account deletes nothing');
  dr_test(coreui_dr_delete_session($pdo, $uid, $sid) === 3, 'all three runs of the chat go (cancelled, replaced, done)');
  $left = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stu_deep_research_sources WHERE run_id = ?) + (SELECT COUNT(*) FROM stu_deep_research_events WHERE run_id = ?)');
  $left->execute([$id3, $id3]);
  dr_test((int)$left->fetchColumn() === 0, 'with their sources and activity');
} catch (Throwable $e) {
  dr_test(false, get_class($e) . ': ' . $e->getMessage());
}
$cleanup();
echo "CoreAI deep research: mark, plan, fallback, prompts, download, + menu, wiring; plan for an account, guests, Starten, "
  . "Bearbeiten, Abbrechen, Stopp, lost worker, sources, activity, other account, chat deletion OK\n";
