<?php
declare(strict_types=1);

/**
 * 0.7.73 (P09 B): Mara reads and changes single project files in a project chat.
 *
 *   [DATEI: pfad]                          read a file (with line numbers)
 *   [DATEI_AENDERN: pfad]                  targeted change: one or more blocks
 *   <<<<<<< SUCHEN                         lines exactly as they are in the file
 *   =======
 *   >>>>>>> ERSETZEN                       (the new lines go between ======= and >>>>>>>)
 *   [/DATEI_AENDERN]
 *   [DATEI_NEU: pfad] ... [/DATEI_NEU]     a new file, or a file replaced as a whole
 *
 * The project comes from the chat on the server, never from the marker. Every
 * change is a new revision (source "mara"); the tab Dateien can restore any
 * earlier state. A change is all or nothing: one block that does not match
 * exactly once changes nothing, and Mara gets told why.
 *
 * 0.7.77 (P10 B): [AUSFUEHREN: befehl] runs a command on the saved files in the sandbox
 * container (api/project_runs_store.php) and gives Mara the real output and exit code, so she
 * can test, change a file and test again.
 *
 * 0.7.81 (P11 B): versions (api/project_vcs_store.php).
 *   [VERSION_SICHERN: Beschreibung]   save the project files as a version (author "mara")
 *   [ARBEITSSTAND: name]              switch to that working state, or start it from the active one
 *                                     (unsaved changes come along, the old one keeps its last version)
 *   [ZUSAMMENFUEHREN: name]           merge that working state into the active one, only without
 *                                     conflicts; conflicts stay for the person in the tab Dateien
 *
 * 0.7.91: Mara knows the environment of P10 and uses the processes of P10 C herself
 * (api/project_procs_store.php). One process per project; the tools act on it.
 *   [PROZESS_STARTEN: befehl]        start a program that keeps running (a port in the command,
 *                                    such as http.server 8000 or --port 5173, makes it a server)
 *   [SERVER_STARTEN: port befehl]    start a development server on that port
 *   [PROZESS_AUSGABE]                state and newest output
 *   [PROZESS_EINGABE: zeile]         a line for the program's stdin
 *   [PROZESS_BEENDEN]                stop it (SIGTERM, then SIGKILL) and read the real exit code
 *   [SERVER_ABRUF: /pfad]            GET a page or an interface of the running server
 */
require_once __DIR__ . '/project_files_store.php';
require_once __DIR__ . '/project_runs_store.php';
require_once __DIR__ . '/project_vcs_store.php';
require_once __DIR__ . '/project_procs_store.php';

const COREUI_PFTOOLS_READ_CHARS = 16000;
const COREUI_PFTOOLS_HINT_FILES = 40;
const COREUI_PFTOOLS_RUN_WAIT = 130;
const COREUI_PFTOOLS_RUN_CHARS = 6000;
const COREUI_PFTOOLS_PROC_CHARS = 4000;
const COREUI_PFTOOLS_PROC_KINDS = ['pproc_start', 'pserver_start', 'pproc_output', 'pproc_input', 'pproc_stop', 'pserver_fetch'];

/** The project of a chat when Mara may use the file tools there, else ''. */
function coreui_pftools_project(PDO $pdo, int $uid, string $sessionId): string {
  if ($uid <= 0 || $sessionId === '' || !coreui_pfiles_schema_ready($pdo)) return '';
  $scope = coreui_project_session_scope($pdo, $uid, $sessionId);
  if (empty($scope['available'])) return '';
  if (($scope['id'] ?? '') !== '') return (string)$scope['id'];
  // A normal chat can own source files too. It is not shown in Projects.
  if (!function_exists('coreui_chat_workspace_id')) return '';
  $id=coreui_chat_workspace_id($pdo,$uid,$sessionId);
  // Chats saved before migration 056 should gain the same tools automatically
  // on their next authenticated work turn, with no manual project creation.
  if ($id==='' && function_exists('coreui_chat_workspace_ready')
      && coreui_chat_workspace_ready($pdo)
      && function_exists('coreui_chat_workspace_ensure')) {
    try {
      $workspace=coreui_chat_workspace_ensure($pdo,$uid,$sessionId);
      $id=(string)($workspace['project_id'] ?? '');
    } catch (Throwable $e) {
      error_log('[CoreAI] Chat workspace unavailable: '.get_class($e));
    }
  }
  return $id;
}

/**
 * Whether this owned project chat already has saved source files.
 * null means the project exists but storage could not be checked; callers
 * must NOT fall back to rebuilding from an old plan in that situation.
 */
function coreui_pftools_has_saved_sources(PDO $pdo, int $uid, string $sessionId): ?bool {
  $projectId = coreui_pftools_project($pdo,$uid,$sessionId);
  if ($projectId === '') return false;
  try {
    $list = coreui_pfiles_list($pdo,$uid,$projectId);
    return (int)($list['count'] ?? 0) > 0;
  } catch (Throwable $e) {
    error_log('[CoreAI] Existing project source inventory unavailable: '.get_class($e));
    return null;
  }
}

/** [DATEI: pfad] (pure): the path, or null. */
function coreui_pftools_extract_read(string $reply): ?string {
  if (preg_match('~\[\s*DATEI\s*:\s*([^\]\r\n]{1,200}?)\s*\]~u', $reply, $m) !== 1) return null;
  return trim($m[1]) !== '' ? trim($m[1]) : null;
}

/** A closed block [NAME: pfad] ... [/NAME] (pure): path . "\n" . body, or null. */
function coreui_pftools_extract_block(string $reply, string $name): ?string {
  $pattern = '~\[\s*' . $name . '\s*:\s*([^\]\r\n]{1,200}?)\s*\][ \t]*\r?\n?(.*?)\r?\n?[ \t]*\[\s*/\s*' . $name . '\s*\]~su';
  if (preg_match($pattern, $reply, $m) !== 1 || trim($m[1]) === '') return null;
  return trim($m[1]) . "\n" . $m[2];
}
function coreui_pftools_extract_edit(string $reply): ?string { return coreui_pftools_extract_block($reply, 'DATEI_AENDERN'); }
function coreui_pftools_extract_write(string $reply): ?string { return coreui_pftools_extract_block($reply, 'DATEI_NEU'); }

/**
 * [AUSFUEHREN: befehl] (pure): the command, or null. At the end of a line the command runs to
 * the last "]" (brackets inside stay: python -c "print([1])"), unless another marker follows on
 * that line; then, and in the middle of a line, it ends at the first "]".
 */
function coreui_pftools_extract_run(string $reply): ?string {
  return coreui_pftools_extract_command($reply, 'AUSF(?:UE|Ü)HREN');
}

/** A marker [NAME: text] whose text may hold brackets, read as for [AUSFUEHREN:] (pure). 0.7.91. */
function coreui_pftools_extract_command(string $reply, string $name, int $max = 400): ?string {
  $start = '~\[\s*' . $name . '\s*:\s*';
  if (preg_match($start . '([^\r\n]{1,' . $max . '})\][ \t]*(?:\r?\n|\z)~u', $reply, $m) === 1
      && preg_match('~\[\s*[A-ZÄÖÜ_]{3,}(?:\s+[A-ZÄÖÜ]+)*\s*:~u', $m[1]) !== 1) {
    $command = trim($m[1]);
  } elseif (preg_match($start . '([^\[\]\r\n]{1,' . $max . '})\]~u', $reply, $m) === 1) {
    $command = trim($m[1]);
  } else {
    return null;
  }
  return $command !== '' ? $command : null;
}

/** 0.7.91: the process markers (pure); the input, or null. Markers without text give a fixed word. */
function coreui_pftools_extract_proc_start(string $reply): ?string { return coreui_pftools_extract_command($reply, 'PROZESS_STARTEN'); }
function coreui_pftools_extract_server_start(string $reply): ?string { return coreui_pftools_extract_command($reply, 'SERVER_STARTEN'); }
function coreui_pftools_extract_proc_input(string $reply): ?string {
  return coreui_pftools_extract_command($reply, 'PROZESS_EINGABE', COREUI_PPROCS_INPUT_CHARS);
}
function coreui_pftools_extract_proc_output(string $reply): ?string {
  return preg_match('~\[\s*PROZESS_AUSGABE\s*(?::[^\]\r\n]{0,200})?\]~u', $reply) === 1 ? 'ausgabe' : null;
}
function coreui_pftools_extract_proc_stop(string $reply): ?string {
  return preg_match('~\[\s*PROZESS_BEENDEN\s*(?::[^\]\r\n]{0,200})?\]~u', $reply) === 1 ? 'beenden' : null;
}
function coreui_pftools_extract_server_fetch(string $reply): ?string {
  return coreui_pftools_extract_line($reply, 'SERVER_ABRUF', 1000);
}

/**
 * The port in a server command (pure), or null: python -m http.server 8000, --port 5173,
 * --port=3000, -p 8080, PORT=3000. Ports outside 1024 to 65535 count as none.
 */
function coreui_pftools_guess_port(string $command): ?int {
  $port = null;
  // python -m http.server [port] [-b addr] [-d dir]: the first number that is no option's value.
  if (preg_match('~\bhttp\.server\b([^;&|]*)~', $command, $m) === 1) {
    $tokens = preg_split('~\s+~', trim($m[1])) ?: [];
    for ($i = 0; $i < count($tokens); $i++) {
      if (in_array($tokens[$i], ['-b', '--bind', '-d', '--directory', '--protocol'], true)) { $i++; continue; }
      if (preg_match('~^\d{1,6}$~', $tokens[$i]) === 1) { $port = (int)$tokens[$i]; break; }
    }
  }
  if ($port === null) {
    foreach (['~(?:^|\s)--port(?:=|\s+)(\d{1,6})\b~', '~(?:^|\s)-p\s+(\d{1,6})\b~', '~(?:^|\s)PORT=(\d{1,6})\b~'] as $pattern) {
      if (preg_match($pattern, $command, $m) === 1) { $port = (int)$m[1]; break; }
    }
  }
  return $port !== null && $port >= 1024 && $port <= 65535 ? $port : null;
}

/** [SERVER_STARTEN: port befehl] (pure): ['port'=>?int, 'command'=>string]; a port in front wins. */
function coreui_pftools_server_spec(string $input): array {
  $input = trim($input);
  if (preg_match('~^(?:port\s*)?(\d{1,6})\s*[:,]?\s+(\S.*)$~iu', $input, $m) === 1) {
    return ['port'=>coreui_pprocs_port($m[1]), 'command'=>trim($m[2])];
  }
  return ['port'=>coreui_pftools_guess_port($input), 'command'=>$input];
}

/** [SERVER_ABRUF:] (pure): a path on the server; a local address in front is dropped. */
function coreui_pftools_server_path(string $input): string {
  $path = trim($input);
  if (preg_match('~^(?:https?://)?(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])(?::\d{1,5})?(/\S*)?$~i', $path, $m) === 1) {
    $path = ($m[1] ?? '') !== '' ? $m[1] : '/';
  }
  return coreui_pprocs_path($path);
}

/** A one-line marker [NAME: text] (pure): the text, or null. 0.7.81 for the version tools. */
function coreui_pftools_extract_line(string $reply, string $name, int $max): ?string {
  if (preg_match('~\[\s*' . $name . '\s*:\s*([^\]\r\n]{1,' . $max . '}?)\s*\]~u', $reply, $m) !== 1) return null;
  return trim($m[1]) !== '' ? trim($m[1]) : null;
}
function coreui_pftools_extract_commit(string $reply): ?string { return coreui_pftools_extract_line($reply, 'VERSION_SICHERN', 200); }
function coreui_pftools_extract_branch(string $reply): ?string { return coreui_pftools_extract_line($reply, 'ARBEITSSTAND', 60); }
function coreui_pftools_extract_merge(string $reply): ?string {
  return coreui_pftools_extract_line($reply, 'ZUSAMMENF(?:UE|Ü)HREN', 60);
}

/** The working state of a project called $name, or null. */
function coreui_pftools_branch_named(array $state, string $name): ?array {
  foreach ($state['branches'] as $branch) if ($branch['name'] === $name) return $branch;
  return null;
}

/** 0.7.81: one version tool; what Mara reads next. */
function coreui_pftools_vcs(PDO $pdo, int $uid, string $projectId, string $kind, string $input): string {
  if (!coreui_pvcs_ready($pdo)) throw new RuntimeException('project_vcs_migration_required');
  if ($kind === 'pvcs_commit') {
    try {
      $commit = coreui_pvcs_commit($pdo, $uid, $projectId, $input, 'mara');
    } catch (RuntimeException $e) {
      if ($e->getMessage() !== 'nothing_to_commit') throw $e;
      return 'Es gibt keine ungesicherten Aenderungen; nichts gesichert.';
    }
    $state = coreui_pvcs_state($pdo, $uid, $projectId);
    coreui_pftools_record($pdo, 'success', 'Version gesichert', ['count'=>1]);
    return 'Version gesichert: "' . $commit['message'] . '" auf dem Arbeitsstand ' . $state['active']['name'] . ' (' . $commit['files_count']
      . ' Dateien). Sie bleibt im Tab Dateien unter Versionen wiederherstellbar.';
  }
  $state = coreui_pvcs_state($pdo, $uid, $projectId);
  $before = (string)$state['active']['name'];
  if ($kind === 'pvcs_branch') {
    $name = coreui_pvcs_branch_name($input);
    $target = coreui_pftools_branch_named($state, $name);
    if ($target !== null && $target['active']) return 'Der Arbeitsstand ' . $name . ' ist schon aktiv.';
    if ($target !== null) {
      coreui_pvcs_switch($pdo, $uid, $projectId, (int)$target['id']);
      coreui_pftools_record($pdo, 'success', 'Arbeitsstand gewechselt', ['count'=>1]);
      return 'Gewechselt auf den Arbeitsstand ' . $name . '; die Projektdateien sind jetzt seine. Ungesicherte Aenderungen von ' . $before
        . " bleiben dort.\n" . coreui_pftools_current_file_inventory($pdo,$uid,$projectId)
        . "\nNutze fuer weitere Dateizugriffe die Liste des jetzt aktiven Arbeitsstands.";
    }
    $after = coreui_pvcs_branch_create($pdo, $uid, $projectId, $name, true);
    coreui_pftools_record($pdo, 'success', 'Arbeitsstand angelegt', ['count'=>1]);
    return 'Neuer Arbeitsstand ' . $name . ' angelegt und aktiv' . (!$after['status']['clean'] ? ', die ungesicherten Aenderungen sind mitgekommen' : '')
      . '. ' . $before . " bleibt bei seiner letzten Version.\n"
      . coreui_pftools_current_file_inventory($pdo,$uid,$projectId);
  }
  if ($kind === 'pvcs_merge') {
    $name = coreui_pvcs_branch_name($input);
    $from = coreui_pftools_branch_named($state, $name);
    if ($from === null) throw new RuntimeException('branch_not_found');
    $preview = coreui_pvcs_merge_preview($pdo, $uid, $projectId, (int)$from['id']);
    if ($preview['conflicts'] !== []) {
      coreui_pftools_record($pdo, 'info', 'Zusammenfuehren mit Konflikten', ['count'=>count($preview['conflicts'])]);
      return 'NICHT zusammengefuehrt: ' . count($preview['conflicts']) . ' Konflikt(e) in ' . implode(', ', array_column($preview['conflicts'], 'path'))
        . '. Beide Seiten haben dort dieselben Stellen verschieden geaendert. Die Person kann das im Tab Dateien unter Versionen loesen; nichts wurde geaendert.';
    }
    $merge = coreui_pvcs_merge($pdo, $uid, $projectId, (int)$from['id'],
      ['ours_tree'=>$preview['ours_tree'], 'theirs_head'=>$preview['theirs_head']], 'mara');
    $changes = $preview['changes'];
    coreui_pftools_record($pdo, 'success', 'Arbeitsstaende zusammengefuehrt', ['count'=>1]);
    return 'Zusammengefuehrt: ' . $name . ' in ' . $before . ' (' . count($changes['added']) . ' neu, ' . count($changes['modified']) . ' geaendert, '
      . count($changes['deleted']) . ' geloescht' . ($preview['merged'] !== [] ? ', zeilenweise zusammengefuehrt: ' . implode(', ', $preview['merged']) : '')
      . '). Neue Version "' . $merge['commit']['message'] . '"; sie laesst sich im Tab Dateien zuruecknehmen.';
  }
  return 'Unbekanntes Versions-Werkzeug.';
}

/** Start and end of a long output (pure). */
function coreui_pftools_clip(string $text, int $limit = COREUI_PFTOOLS_RUN_CHARS): string {
  if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
  $head = (int)($limit * 0.3);
  return mb_substr($text, 0, $head, 'UTF-8') . "\n[... gekuerzt ...]\n" . mb_substr($text, -($limit - $head), null, 'UTF-8');
}

/** What Mara reads after a run (pure). */
function coreui_pftools_run_result(array $run): string {
  $text = 'Befehl: ' . $run['command'] . "\n" . coreui_pruns_summary($run);
  if (coreui_pruns_active((string)$run['status'])) {
    return $text . "\nDer Lauf ist noch nicht fertig. Das Ergebnis steht danach im Tab Dateien unter Ausfuehren.";
  }
  $out = trim((string)($run['stdout'] ?? ''));
  $err = trim((string)($run['stderr'] ?? ''));
  if ($out !== '') $text .= "\n--- Ausgabe (stdout) ---\n" . coreui_pftools_clip($out);
  if ($err !== '') $text .= "\n--- Fehlerausgabe (stderr) ---\n" . coreui_pftools_clip($err);
  if ($out === '' && $err === '' && $run['status'] === 'done') $text .= "\n(Keine Ausgabe.)";
  return $text . "\n(Lauf im Sandbox-Container mit den gespeicherten Projektdateien; was der Befehl an Dateien aendert, bleibt nicht erhalten.)";
}

/** Split the tool input "path\nbody" (pure). */
function coreui_pftools_split(string $input): array {
  $parts = explode("\n", $input, 2);
  return [trim($parts[0]), $parts[1] ?? ''];
}

/**
 * The SUCHEN/ERSETZEN pairs of a change (pure): [[search, replace], ...].
 * Seven to nine angle brackets and equals signs are accepted, CRLF too.
 */
function coreui_pftools_parse_edits(string $body): array {
  $body = str_replace("\r\n", "\n", $body);
  preg_match_all('~^<{7,9}[ \t]*SUCHEN[ \t]*\n(.*?)^={7,9}[ \t]*\n(.*?)^>{7,9}[ \t]*ERSETZEN[ \t]*$~msu', $body, $all, PREG_SET_ORDER);
  $pairs = [];
  foreach ($all as $m) {
    $search = (string)preg_replace('~\n$~', '', $m[1]);
    $replace = (string)preg_replace('~\n$~', '', $m[2]);
    $pairs[] = [$search, $replace];
  }
  return $pairs;
}

/**
 * Apply the pairs to $content (pure). Every search text has to occur exactly
 * once; otherwise nothing changes. Returns ['ok', 'content', 'error', 'added', 'removed'].
 */
function coreui_pftools_apply_edits(string $content, array $pairs): array {
  if ($pairs === []) return ['ok'=>false, 'content'=>$content, 'error'=>'Kein SUCHEN/ERSETZEN-Block gefunden.', 'added'=>0, 'removed'=>0];
  $crlf = str_contains($content, "\r\n");
  $text = $crlf ? str_replace("\r\n", "\n", $content) : $content;
  $added = 0;
  $removed = 0;
  foreach ($pairs as $i => [$search, $replace]) {
    $n = $i + 1;
    if (trim($search) === '') {
      return ['ok'=>false, 'content'=>$content, 'added'=>0, 'removed'=>0,
        'error'=>'Block ' . $n . ': SUCHEN ist leer. Fuer eine ganze Datei nimm [DATEI_NEU: pfad].'];
    }
    $count = substr_count($text, $search);
    if ($count !== 1) {
      return ['ok'=>false, 'content'=>$content, 'added'=>0, 'removed'=>0,
        'error'=>'Block ' . $n . ': der SUCHEN-Text kommt ' . ($count === 0 ? 'nicht' : $count . ' Mal') . ' in der Datei vor'
          . ($count === 0 ? ' (lies sie mit [DATEI: pfad] und kopiere die Zeilen genau, mit allen Leerzeichen).'
            : ' (nimm mehr umgebende Zeilen, damit er eindeutig ist).')];
    }
    $text = str_replace($search, $replace, $text);
    $removed += substr_count($search, "\n") + 1;
    $added += $replace === '' ? 0 : substr_count($replace, "\n") + 1;
  }
  return ['ok'=>true, 'content'=>$crlf ? str_replace("\n", "\r\n", $text) : $text, 'error'=>'', 'added'=>$added, 'removed'=>$removed];
}

/** Raw saved text for patch recovery, focused on a unique surviving search line. */
function coreui_pftools_recovery_source(string $content, string $patch): string {
  $limit = 8000;
  $content = str_replace("\r\n", "\n", $content);
  if (strlen($content) <= $limit) return $content;

  $candidates = [];
  foreach (coreui_pftools_parse_edits($patch) as [$search]) {
    foreach (explode("\n", $search) as $line) {
      $line = trim((string)preg_replace('~^\s*[0-9]+ \| ~', '', $line));
      if (strlen($line) >= 12) $candidates[$line] = strlen($line);
    }
  }
  arsort($candidates, SORT_NUMERIC);
  $anchor = 0;
  $anchorBytes = 0;
  foreach (array_slice(array_keys($candidates), 0, 24) as $line) {
    $line = (string)$line;
    if (substr_count($content, $line) !== 1) continue;
    $anchor = strpos($content, $line);
    $anchorBytes = strlen($line);
    break;
  }
  $start = max(0, $anchor - intdiv($limit, 3));
  $end = $start + $limit;
  if ($start > 0 && $content[$start - 1] !== "\n") {
    $newline = strpos($content, "\n", $start);
    if ($newline !== false && $newline < $anchor) $start = $newline + 1;
  }
  if ($end < strlen($content)) {
    $newline = strrpos($content, "\n", $end - strlen($content) - 1);
    if ($newline !== false && $newline >= $anchor + $anchorBytes) $end = $newline;
  }
  return mb_strcut($content, $start, $end - $start, 'UTF-8');
}

/** In read markers only, a trailing #Lnumber selects the first line. */
function coreui_pftools_read_target(string $input): array {
  if (preg_match('~^(.+)#L([1-9][0-9]{0,6})$~D', $input, $m) === 1) {
    return [$m[1], (int)$m[2]];
  }
  return [$input, 1];
}

/** A bounded window of source lines with their original line numbers. */
function coreui_pftools_numbered(string $content, int $max = COREUI_PFTOOLS_READ_CHARS, int $start = 1): string {
  $lines = preg_split('~\r?\n~', $content) ?: [''];
  if (count($lines) > 1 && end($lines) === '') array_pop($lines);
  $count = count($lines);
  if ($start > $count) return 'Dieser Ausschnitt beginnt hinter dem Dateiende (Zeile ' . $count . ').';
  $width = strlen((string)$count);
  $out = '';
  for ($i = $start - 1; $i < $count; $i++) {
    $row = str_pad((string)($i + 1), $width, ' ', STR_PAD_LEFT) . ' | ' . $lines[$i] . "\n";
    if (strlen($out) + strlen($row) > $max) {
      if ($out === '') return mb_strcut($row, 0, $max, 'UTF-8')
        . "\n(Zeile " . ($i + 1) . ' ist besonders lang und wurde gekuerzt.)';
      return $out . '... (gekuerzt nach Zeile ' . $i . ' von ' . $count . ')';
    }
    $out .= $row;
  }
  return rtrim($out, "\n");
}

/** The content of [DATEI_NEU] (pure): one wrapping code fence removed, ending with a newline. */
function coreui_pftools_file_body(string $body): string {
  $body = (string)preg_replace('~\A\r?\n~', '', $body);
  if (preg_match('~\A[ \t]*```[A-Za-z0-9_+-]*[ \t]*\r?\n(.*?)\r?\n[ \t]*```[ \t]*\z~s', rtrim($body), $m) === 1) $body = $m[1];
  return $body === '' || str_ends_with($body, "\n") ? $body : $body . "\n";
}

function coreui_pftools_record(PDO $pdo, string $state, string $title, array $meta): void {
  if (function_exists('coreui_work_record')) coreui_work_record($pdo, 'tool', $state, $title, $meta + ['source'=>'project_files']);
}

/** 0.7.91: the process of a project the tools act on: the active one, or with $any the latest. */
function coreui_pftools_proc_current(PDO $pdo, int $uid, string $projectId, bool $any = false): ?array {
  coreui_pprocs_reap($pdo, $uid, $projectId);
  $st = $pdo->prepare('SELECT id FROM stu_console_project_procs WHERE user_id=? AND project_id=?'
    . ($any ? '' : " AND status IN ('queued','running')") . ' ORDER BY id DESC LIMIT 1');
  $st->execute([$uid, $projectId]);
  $id = $st->fetchColumn();
  return $id === false ? null : coreui_pprocs_get($pdo, $uid, $projectId, (int)$id, 0);
}

/** Look at a process again, $ticks times at most, until $done says so. Looking keeps it alive. */
function coreui_pftools_proc_poll(PDO $pdo, int $uid, string $projectId, array $proc, int $ticks, callable $done, ?callable $sleep): array {
  $sleep = $sleep ?? static function (): void { usleep(500000); };
  for ($i = 0; $i < $ticks && !$done($proc); $i++) {
    $sleep();
    $proc = coreui_pprocs_get($pdo, $uid, $projectId, (int)$proc['id'], 0);
  }
  return $proc;
}

/** The newest output of a process, at most COREUI_PFTOOLS_PROC_CHARS characters (pure). */
function coreui_pftools_proc_tail(array $proc): string {
  $out = (string)($proc['output'] ?? '');
  if (mb_strlen($out, 'UTF-8') <= COREUI_PFTOOLS_PROC_CHARS) return $out;
  return "[... aeltere Ausgabe ausgelassen ...]\n" . mb_substr($out, -COREUI_PFTOOLS_PROC_CHARS, null, 'UTF-8');
}

/** What Mara reads about a process (pure). $output is what she should see of its output. */
function coreui_pftools_proc_text(array $proc, string $output, string $heading = 'Ausgabe'): string {
  $text = 'Prozess: ' . $proc['command'] . ($proc['port'] !== null ? ' (Port ' . (int)$proc['port'] . ')' : '') . "\n"
    . coreui_pprocs_summary($proc);
  $output = trim($output, "\r\n");
  $text .= $output !== '' ? "\n--- " . $heading . " ---\n" . $output : "\n(" . ($heading === 'Ausgabe' ? 'Noch keine Ausgabe' : 'Keine neue Ausgabe') . '.)';
  if ($proc['status'] === 'queued' && !$proc['stop_requested']) {
    $text .= "\n(Der Sandbox-Dienst hat ihn noch nicht uebernommen; schau gleich mit [PROZESS_AUSGABE] nach.)";
  } elseif ($proc['status'] === 'running' && !$proc['stop_requested']) {
    $text .= "\n(Er laeuft im Sandbox-Container weiter, hoechstens 30 Minuten, und endet nach 5 Minuten, in denen niemand im Tab Dateien "
      . 'unter Prozesse hinsieht und du ihn nicht nutzt. Gespeicherte Projektdateien kommen nach wenigen Sekunden bei ihm an.'
      . ($proc['port'] !== null ? ' Die Person sieht den Server im Tab Dateien unter Prozesse mit "Vorschau"; eine Adresse wie localhost:'
        . (int)$proc['port'] . ' erreicht sie nicht. Pruefen kannst du ihn mit [SERVER_ABRUF: /pfad].' : '') . ')';
  }
  return $text;
}

/** What Mara reads after a request to the server (pure). */
function coreui_pftools_fetch_text(string $path, array $answer): string {
  $type = (string)$answer['type'];
  $body = (string)$answer['body'];
  $text = 'GET ' . $path . ': HTTP ' . (int)$answer['status'] . ($type !== '' ? ' (' . $type . ')' : '')
    . ((string)$answer['path'] !== $path ? ', nach Weiterleitung ' . $answer['path'] : '') . "\n";
  if ($body === '') return $text . '(Leere Antwort.)';
  if (!coreui_pprocs_is_text($type) || preg_match('//u', $body) !== 1) {
    return $text . '(Binaerdaten, ' . strlen($body) . ' Bytes' . (!empty($answer['truncated']) ? ', gekuerzt' : '') . '.)';
  }
  return $text . "--- Inhalt ---\n" . coreui_pftools_clip($body, COREUI_PFTOOLS_PROC_CHARS)
    . (!empty($answer['truncated']) ? "\n(Die Antwort war groesser und wurde gekuerzt.)" : '');
}

/** 0.7.91: one process tool; what Mara reads next. */
function coreui_pftools_proc(PDO $pdo, int $uid, string $projectId, string $kind, string $input, ?callable $sleep): string {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $active = static fn(array $p): bool => coreui_pprocs_active((string)$p['status']);
  $started = static fn(array $p): bool => $p['status'] !== 'queued';
  if ($kind === 'pproc_start' || $kind === 'pserver_start') {
    $spec = $kind === 'pserver_start' ? coreui_pftools_server_spec($input)
      : ['port'=>coreui_pftools_guess_port($input), 'command'=>$input];
    if ($kind === 'pserver_start' && $spec['port'] === null) {
      return 'Der Server wurde NICHT gestartet: nenne den Port, auf dem er hoert, vor dem Befehl, zum Beispiel '
        . '[SERVER_STARTEN: 3000 npm start] oder [SERVER_STARTEN: 8000 python -m http.server 8000].';
    }
    $proc = coreui_pprocs_start($pdo, $uid, $projectId, $spec['command'], $spec['port']);
    // The worker takes it within a second or two (up to 20 s here), then the first output comes (3 s more).
    $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, $proc, 40, $started, $sleep);
    if ($proc['status'] === 'running') {
      $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, $proc, 6, static fn(array $p): bool => !coreui_pprocs_active((string)$p['status']), $sleep);
    }
    $server = $proc['port'] !== null;
    $verification = '';
    $verifiedHttp = false;
    // A running process is NOT proof of a responsive HTTP server. Verify it
    // against the worker-isolated localhost path before claiming "started".
    if ($kind === 'pserver_start' && $proc['status'] === 'running') {
      try {
        $reply = coreui_pprocs_fetch($pdo,$uid,$projectId,(int)$proc['id'],'GET','/',null,'',$sleep);
        $status = (int)$reply['status'];
        $verifiedHttp = $status >= 200 && $status < 400;
        $verification = "\nHTTP-Pruefung GET /: " . $status
          . ($verifiedHttp ? ' (Server erreichbar).' : ' (Serverprozess laeuft, aber Startseite nicht erfolgreich).');
      } catch (RuntimeException $e) {
        $verification = "\nHTTP-Pruefung GET / fehlgeschlagen (" . $e->getMessage()
          . "). Serverprozess nicht als HTTP-bereit bestaetigt; pruefe Ausgabe und Port.";
      }
    }
    if ($proc['status'] === 'running') {
      coreui_pftools_record($pdo, $kind !== 'pserver_start' || $verifiedHttp ? 'success' : 'info',
        !$server ? 'Prozess gestartet' : ($verifiedHttp ? 'Server verifiziert, Port ' . (int)$proc['port']
          : 'Serverprozess gestartet, HTTP noch nicht verifiziert'),
        ['count'=>1,'process_id'=>(int)$proc['id'],'port'=>$proc['port'],'http_verified'=>$verifiedHttp]);
    } elseif ($proc['status'] === 'queued') {
      coreui_pftools_record($pdo, 'info', 'Prozess wartet auf den Sandbox-Dienst', ['count'=>1]);
    } else {
      coreui_pftools_record($pdo, $proc['status'] === 'error' ? 'error' : 'info', $proc['status'] === 'error' ? 'Prozess nicht gestartet'
        : 'Prozess gleich wieder beendet, Exitcode ' . (int)$proc['exit_code'], ['count'=>1]);
    }
    return coreui_pftools_proc_text($proc, coreui_pftools_proc_tail($proc))
      . "\nProzess-ID: " . (int)$proc['id'] . $verification;
  }
  if ($kind === 'pproc_output') {
    $proc = coreui_pftools_proc_current($pdo, $uid, $projectId, true);
    if ($proc === null) return 'In diesem Projekt wurde noch kein Prozess gestartet. Starte einen mit [PROZESS_STARTEN: befehl] oder [SERVER_STARTEN: port befehl].';
    if ($active($proc)) {
      $end = (int)$proc['output_end'];
      $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, $proc, 4,
        static fn(array $p): bool => !coreui_pprocs_active((string)$p['status']) || (int)$p['output_end'] !== $end, $sleep);
    }
    coreui_pftools_record($pdo, 'info', 'Prozessausgabe gelesen', ['count'=>1]);
    return coreui_pftools_proc_text($proc, coreui_pftools_proc_tail($proc));
  }
  $proc = coreui_pftools_proc_current($pdo, $uid, $projectId);
  if ($proc === null) {
    return $kind === 'pproc_stop' ? 'Gerade laeuft kein Prozess in diesem Projekt; nichts beendet.'
      : 'Gerade laeuft kein Prozess in diesem Projekt. Starte einen mit [PROZESS_STARTEN: befehl] oder [SERVER_STARTEN: port befehl].';
  }
  if ($kind === 'pproc_stop') {
    coreui_pprocs_stop($pdo, $uid, $projectId, (int)$proc['id']);
    $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, coreui_pprocs_get($pdo, $uid, $projectId, (int)$proc['id'], 0), 20,
      static fn(array $p): bool => !coreui_pprocs_active((string)$p['status']), $sleep);
    coreui_pftools_record($pdo, $active($proc) ? 'info' : 'success',
      $active($proc) ? 'Prozess wird beendet' : 'Prozess beendet' . ($proc['exit_code'] !== null ? ', Exitcode ' . (int)$proc['exit_code'] : ''), ['count'=>1]);
    return coreui_pftools_proc_text($proc, coreui_pftools_proc_tail($proc));
  }
  // Input and requests need it running; a queued one gets its moment.
  $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, $proc, 40, $started, $sleep);
  if ($proc['status'] !== 'running') return coreui_pftools_proc_text($proc, coreui_pftools_proc_tail($proc));
  if ($kind === 'pproc_input') {
    $end = (int)$proc['output_end'];
    coreui_pprocs_input($pdo, $uid, $projectId, (int)$proc['id'], $input);
    $proc = coreui_pftools_proc_poll($pdo, $uid, $projectId, coreui_pprocs_get($pdo, $uid, $projectId, (int)$proc['id'], 0), 6,
      static fn(array $p): bool => !coreui_pprocs_active((string)$p['status']) || (int)$p['output_end'] > $end, $sleep);
    $new = coreui_pprocs_get($pdo, $uid, $projectId, (int)$proc['id'], $end);
    coreui_pftools_record($pdo, 'success', 'Eingabe an den Prozess gesendet', ['count'=>1]);
    return 'Eingabe gesendet: "' . $input . "\"\n" . coreui_pftools_proc_text($proc,
      ($new['output_cut'] ? "[... ausgelassen ...]\n" : '') . coreui_pftools_proc_tail($new), 'Neue Ausgabe');
  }
  if ($kind === 'pserver_fetch') {
    $path = coreui_pftools_server_path($input);
    if ($proc['port'] === null) {
      return 'Der laufende Prozess hat keinen Port, also keinen Server zum Abrufen. Ein Server startet mit [SERVER_STARTEN: port befehl].';
    }
    $answer = coreui_pprocs_fetch($pdo, $uid, $projectId, (int)$proc['id'], 'GET', $path, null, '', $sleep);
    coreui_pftools_record($pdo, (int)$answer['status'] < 400 ? 'success' : 'info', 'Server abgerufen, HTTP ' . (int)$answer['status'], ['count'=>1]);
    return coreui_pftools_fetch_text($path, $answer);
  }
  return 'Unbekanntes Prozess-Werkzeug.';
}

/**
 * Current owner-scoped file inventory, shared by workspace reads and branch changes.
 */
function coreui_pftools_current_file_inventory(PDO $pdo,int $uid,string $projectId): string {
  if ($projectId==='') return '';
  try {
    $inventory=coreui_pfiles_list($pdo,$uid,$projectId);
    $files=$inventory['files']??[];
    if (!is_array($files)||$files===[]) return
      'Der Arbeitsbereich hat noch keine gespeicherten Dateien. Nur beauftragte Dateien neu erstellen.';
    $paths=[];
    foreach ($files as $row) {
      $path=(string)($row['path']??'');
      if ($path!=='' && strlen($path)<=200 && !str_contains($path,"\n"))
        $paths[]=$path;
    }
    return "Vorhandene Dateien im gebundenen Arbeitsbereich:\n"
      . implode("\n",$paths);
  } catch(Throwable $e) {
    error_log('[CoreAI] inventory after missing read: '.get_class($e));
    return 'Dateiliste konnte nicht bestaetigt werden; keine Pfade erraten.';
  }
}

/** Run one file tool of the work loop; the result is what Mara reads next. $sleep is replaceable in tests. */
function coreui_pftools_run(PDO $pdo, int $uid, string $sessionId, array $tool, ?callable $sleep = null): string {
  $projectId = coreui_pftools_project($pdo, $uid, $sessionId);
  if ($projectId === '') return 'Der Datei-Arbeitsbereich dieses Chats ist noch nicht verfügbar. Migration 056 und Berechtigungen prüfen.';
  $kind = (string)($tool['tool'] ?? '');
  try {
    if (in_array($kind, COREUI_PFTOOLS_PROC_KINDS, true)) {
      return coreui_pftools_proc($pdo, $uid, $projectId, $kind, trim((string)$tool['input']), $sleep);
    }
    if ($kind === 'prun') {
      $run = coreui_pruns_enqueue($pdo, $uid, $projectId, (string)$tool['input'], $sessionId);
      $run = coreui_pruns_wait($pdo, $uid, $projectId, (int)$run['id'], COREUI_PFTOOLS_RUN_WAIT);
      $ok = $run['status'] === 'done' && (int)$run['exit_code'] === 0;
      coreui_pftools_record($pdo, $ok ? 'success' : ($run['status'] === 'done' ? 'info' : 'error'),
        $run['status'] === 'done' ? 'Projekt ausgefuehrt, Exitcode ' . (int)$run['exit_code'] : 'Projektlauf ohne Ergebnis', ['count'=>1]);
      return coreui_pftools_run_result($run);
    }
    if (in_array($kind, ['pvcs_commit', 'pvcs_branch', 'pvcs_merge'], true)) {
      return coreui_pftools_vcs($pdo, $uid, $projectId, $kind, trim((string)$tool['input']));
    }
    if ($kind === 'pfile_read') {
      [$readPath, $startLine] = coreui_pftools_read_target((string)$tool['input']);
      $path = coreui_pfiles_path($readPath);
      $file = coreui_pfiles_get($pdo, $uid, $projectId, $path);
      coreui_pftools_record($pdo, 'info', 'Projektdatei gelesen', ['count'=>1]);
      $header = 'Datei ' . $file['path'] . ' (Stand ' . $file['revision'] . ', ' . $file['size_bytes'] . " Bytes):\n";
      if (($tool['recovery_read'] ?? false) === true) {
        return $header
          . "Gespeicherter Quelltext ohne Zeilennummern (bei langen Dateien ein Ausschnitt):\n"
          . coreui_pftools_recovery_source($file['content'], (string)($tool['recovery_patch'] ?? ''));
      }
      $excerpt = coreui_pftools_numbered($file['content'], COREUI_PFTOOLS_READ_CHARS, $startLine);
      $next = '';
      if (preg_match('~\.\.\. \(gekuerzt nach Zeile ([0-9]+) von [0-9]+\)\z~', $excerpt, $cut) === 1) {
        $next = "\nNaechster Quelltextabschnitt: [DATEI: " . $path . '#L' . ((int)$cut[1] + 1) . ']';
      }
      return $header . $excerpt . "\n(Die Zeilennummern und \" | \" gehoeren nicht zur Datei.)" . $next;
    }
    [$rawPath, $body] = coreui_pftools_split((string)$tool['input']);
    $path = coreui_pfiles_path($rawPath);
    if ($kind === 'pfile_edit') {
      $file = coreui_pfiles_get($pdo, $uid, $projectId, $path);
      $applied = coreui_pftools_apply_edits($file['content'], coreui_pftools_parse_edits($body));
      if (!$applied['ok']) {
        coreui_pftools_record($pdo, 'error', 'Projektdatei nicht geaendert', ['count'=>0]);
        return 'Die Datei ' . $path . ' wurde NICHT geaendert: ' . $applied['error'];
      }
      $result = coreui_pfiles_write($pdo, $uid, $projectId, $path, $applied['content'], 'mara',
        ['base_sha256'=>$file['sha256'], 'session_id'=>$sessionId]);
      if (!$result['changed']) return 'Die Datei ' . $path . ' hatte diesen Inhalt schon; nichts geaendert.';
      coreui_pftools_record($pdo, 'success', 'Projektdatei geaendert', ['count'=>1]);
      return 'Datei ' . $path . ' geaendert (jetzt Stand ' . $result['file']['revision'] . '): ' . $applied['removed']
        . ' Zeilen ersetzt durch ' . $applied['added'] . '. Der vorige Stand bleibt im Tab Dateien wiederherstellbar.';
    }
    if ($kind === 'pfile_write') {
      $content = coreui_pftools_file_body($body);
      $existing = coreui_pfiles_row($pdo, $uid, $projectId, $path);
      $result = coreui_pfiles_write($pdo, $uid, $projectId, $path, $content, 'mara', ['session_id'=>$sessionId]);
      if (!$result['changed']) return 'Die Datei ' . $path . ' hatte diesen Inhalt schon; nichts geaendert.';
      coreui_pftools_record($pdo, 'success', $existing === null ? 'Projektdatei angelegt' : 'Projektdatei ersetzt', ['count'=>1]);
      return 'Datei ' . $path . ($existing === null ? ' angelegt' : ' ganz ersetzt') . ' (Stand ' . $result['file']['revision'] . ', '
        . $result['file']['size_bytes'] . ' Bytes).' . ($existing === null ? '' : ' Der vorige Stand bleibt im Tab Dateien wiederherstellbar.');
    }
  } catch (PDOException $e) {
    error_log('[CoreAI] project file tool failed: ' . get_class($e));
    return 'Die Projektdatei konnte gerade nicht gelesen oder gespeichert werden (Datenbankfehler). Nichts geaendert.';
  } catch (InvalidArgumentException|RuntimeException $e) {
    coreui_pftools_record($pdo, 'error', in_array($kind, COREUI_PFTOOLS_PROC_KINDS, true) ? 'Prozess-Werkzeug ohne Ergebnis'
      : 'Projektdatei nicht verfuegbar', ['count'=>0]);
    $why = [
      'invalid_path'=>'der Pfad ist ungueltig (relativ, ohne "..", Buchstaben, Ziffern und ._-()+)',
      'unsupported_type'=>'nur Textdateien wie .html, .css, .js, .json, .md, .txt oder .py',
      'file_not_found'=>'diese Datei gibt es im Projekt nicht',
      'file_too_large'=>'die Datei waere groesser als 512 KB',
      'not_text'=>'der Inhalt ist kein reiner Text',
      'file_changed'=>'die Datei wurde gerade anders geaendert; lies sie neu',
      'project_full'=>'das Projekt ist voll (200 Dateien oder 10 MB)',
      'storage_full'=>'der Speicher des Kontos ist voll',
      'invalid_command'=>'der Befehl muss eine Zeile mit hoechstens 400 Zeichen sein',
      'run_busy'=>'in diesem Projekt laeuft schon ein Befehl',
      'run_limit'=>'fuer diese Stunde sind es genug Laeufe',
      'no_files'=>'das Projekt hat noch keine Dateien',
      'project_runs_migration_required'=>'das Ausfuehren ist auf diesem Server noch nicht eingerichtet (Migration 047)',
      'project_vcs_migration_required'=>'Versionen sind auf diesem Server noch nicht eingerichtet (Migration 049)',
      'project_merge_migration_required'=>'das Zusammenfuehren ist auf diesem Server noch nicht eingerichtet (Migration 050)',
      'invalid_message'=>'die Beschreibung einer Version ist eine Zeile mit hoechstens 200 Zeichen',
      'invalid_branch_name'=>'der Name eines Arbeitsstands besteht aus Buchstaben, Ziffern und ._-/ (hoechstens 60 Zeichen, ohne Leerzeichen)',
      'branch_not_found'=>'diesen Arbeitsstand gibt es im Projekt nicht',
      'branch_limit'=>'das Projekt hat schon 10 Arbeitsstaende',
      'branch_exists'=>'ein Arbeitsstand mit diesem Namen (oder diesem Namen als Ordner) gibt es schon',
      'history_full'=>'das Projekt hat schon 200 Versionen',
      'already_merged'=>'der Arbeitsstand ist schon ganz enthalten; es gibt nichts zusammenzufuehren',
      'merge_same'=>'ein Arbeitsstand kann nicht mit sich selbst zusammengefuehrt werden',
      // 0.7.91: processes.
      'project_procs_migration_required'=>'Prozesse sind auf diesem Server noch nicht eingerichtet (Migration 048)',
      'proc_busy'=>'in diesem Projekt laeuft schon ein Prozess; lies ihn mit [PROZESS_AUSGABE] oder beende ihn mit [PROZESS_BEENDEN]',
      'proc_active_limit'=>'in diesem Konto laufen schon zwei Prozesse in anderen Projekten',
      'proc_limit'=>'fuer diese Stunde sind es genug Prozessstarts',
      'proc_not_running'=>'der Prozess laeuft nicht mehr',
      'proc_not_found'=>'den Prozess gibt es nicht mehr',
      'invalid_port'=>'der Port liegt zwischen 1024 und 65535',
      'invalid_input'=>'die Eingabe ist eine Zeile mit hoechstens 2000 Zeichen',
      'input_busy'=>'es warten schon zu viele Eingaben auf das Programm',
      'invalid_server_path'=>'der Pfad beginnt mit / (zum Beispiel / oder /api/daten), ohne Leerzeichen',
      'server_unreachable'=>'der Server antwortet nicht: er ist noch nicht bereit, hoert auf einem anderen Port oder nicht auf 127.0.0.1 '
        . 'im Container; lies die Ausgabe mit [PROZESS_AUSGABE]',
      'fetch_timeout'=>'der Server hat nicht rechtzeitig geantwortet',
      'fetch_failed'=>'die Anfrage an den Server ist fehlgeschlagen',
      'fetch_busy'=>'es warten schon zu viele Anfragen an den Server',
    ][$e->getMessage()] ?? $e->getMessage();
    if ($kind==='pfile_read' && $e->getMessage()==='file_not_found') {
      return 'Das ging nicht: ' . $why . ".\n"
        . coreui_pftools_current_file_inventory($pdo,$uid,$projectId)
        . "\nEinen vorhandenen Pfad waehlen oder eine beauftragte Datei erstellen. "
        . "Fehlgeschlagenen Leseaufruf nicht wiederholen.";
    }
    return 'Das ging nicht: ' . $why . '.';
  }
  return 'Unbekanntes Datei-Werkzeug.';
}

/**
 * 0.7.91: the environment of the project in Mara's prompt (P10): the sandbox, the preview the
 * person sees, the processes and the process running now. $canRun: commands (Python tool and
 * migration 047); $canProcs: processes as well (migration 048).
 */
function coreui_pftools_env_hint(PDO $pdo, int $uid, string $projectId, bool $compact, bool $canRun, bool $canProcs): string {
  $now = '';
  if ($canProcs) {
    try {
      coreui_pprocs_reap($pdo, $uid, $projectId);
      $st = $pdo->prepare("SELECT command, port, status FROM stu_console_project_procs WHERE user_id=? AND project_id=?
        AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
      $st->execute([$uid, $projectId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $now = is_array($row) ? 'Gerade laeuft: ' . $row['command'] . ($row['port'] !== null ? ' (Port ' . (int)$row['port'] . ')' : '') . '.'
        : 'Gerade laeuft kein Prozess.';
    } catch (Throwable $e) {
      $now = '';
    }
  }
  if ($compact) {
    if (!$canRun && !$canProcs) return ' Ausfuehren und Prozesse sind in diesem Chat nicht verfuegbar.';
    return ' Umgebung: Sandbox mit Python 3.12 und Node.js 24 (npm, npx) und Internet; die Person sieht Seiten im Tab Dateien unter Vorschau.'
      . ($canProcs ? ' Prozesse und Server: [PROZESS_STARTEN: befehl], [SERVER_STARTEN: port befehl], [PROZESS_AUSGABE], '
        . '[PROZESS_EINGABE: zeile], [PROZESS_BEENDEN], [SERVER_ABRUF: /pfad]. ' . $now : '');
  }
  $preview = 'Die Person sieht HTML-Seiten des Projekts im Tab Dateien unter "Vorschau"' . ($canProcs ? ', einen laufenden Server mit Port '
    . 'dort unter "Prozesse" mit "Vorschau" (eine Adresse wie localhost:8000 erreicht sie nicht)' : '') . '. Du selbst siehst die Vorschau nicht.';
  if (!$canRun && !$canProcs) {
    return "UMGEBUNG: " . $preview . " Befehle, Tests und Prozesse sind in diesem Chat nicht verfuegbar (das Python-Werkzeug ist aus "
      . "oder auf dem Server nicht eingerichtet); sag das, statt eine Ausfuehrung zu behaupten.\n";
  }
  $env = "UMGEBUNG: Zum Projekt gehoert ein Sandbox-Container mit Python 3.12 und Node.js 24 (npm, npx) und Internetzugang. "
    . "Arbeitsordner sind die gespeicherten Projektdateien, das System ist schreibgeschuetzt, du arbeitest dort als einfacher Nutzer. "
    . "Was ein Befehl an Dateien aendert, bleibt nicht erhalten; Projektdateien aenderst du mit den Werkzeugen oben. " . $preview . "\n";
  if (!$canProcs) return $env;
  return $env
    . "PROZESSE (einer je Projekt; er laeuft weiter, hoechstens 30 Minuten, und endet nach 5 Minuten, in denen niemand hinsieht):\n"
    . "- [PROZESS_STARTEN: befehl] startet ein Programm, das weiterlaeuft, zum Beispiel ein Spiel, das Eingaben erwartet.\n"
    . "- [SERVER_STARTEN: port befehl] startet einen Entwicklungsserver, der auf diesem Port hoert, zum Beispiel "
    . "[SERVER_STARTEN: 8000 python -m http.server 8000] oder [SERVER_STARTEN: 3000 npm start].\n"
    . "- [PROZESS_AUSGABE] zeigt Zustand und neueste Ausgabe, [PROZESS_EINGABE: zeile] schickt eine Zeile an das Programm, "
    . "[PROZESS_BEENDEN] beendet es mit echtem Exitcode.\n"
    . "- [SERVER_ABRUF: /pfad] ruft eine Seite oder Schnittstelle des laufenden Servers ab (Status und Inhalt), zum Beispiel "
    . "[SERVER_ABRUF: /] oder [SERVER_ABRUF: /api/daten].\n"
    . "Fuer Tests und einmalige Befehle nimm [AUSFUEHREN: befehl], fuer Server und Programme, die weiterlaufen, die Prozesse. "
    . "Nach dem Start eines Servers pruefst du ihn mit [SERVER_ABRUF: /], bevor du sagst, dass er laeuft. " . $now . "\n";
}

/**
 * 0.7.91: outside a project, one line that the environment exists in project chats, so Mara does not
 * deny it or claim it here. '' in the compact prompt, in other channels or without the Python tool.
 */
function coreui_pftools_outside_hint(PDO $pdo, string $channel, int $uid, bool $compact): string {
  if ($channel !== 'console' || $uid <= 0) return '';
  // A regular CoreAI chat is a valid work chat. Never tell Mara that its
  // Python/browser/native tools require a project. The versioned pfile
  // backend still has a project-storage restriction until chat workspaces
  // are unified; that is an implementation gap, not a user requirement.
  $notice = "CHAT-WERKZEUGE: Auch dieser normale CoreAI-Chat kann "
    . "dateibasierte Auftraege, Python, Web-Werkzeuge und echte Downloads "
    . "bearbeiten, soweit diese Werkzeuge aktiviert sind. Ein Projekt ist "
    . "dafuer keine Voraussetzung. Historische Projektplaene sind "
    . "kein Ersatz fuer den aktuellen Nutzerauftrag. ";
  if ($compact) return $notice;
  return $notice
    . "Achtung: Der spezielle versionierte Projektdatei-Speicher "
    . "benoetigt im bisherigen Backend noch einen Projektbezug. "
    . "Das ist eine zu behebende Speichergrenze, KEINE allgemeine "
    . "Einschraenkung der Werkzeuge im Hauptchat.";
}

/**
 * The part of Mara's prompt in a project chat: which files the project has and
 * how she reads and changes them. '' outside a project or without files support.
 */
function coreui_pftools_hint(PDO $pdo, int $uid, string $sessionId, bool $compact, bool $canRun = false): string {
  $projectId = coreui_pftools_project($pdo, $uid, $sessionId);
  if ($projectId === '') return '';
  // 0.7.91: processes need what running needs ($canRun: the Python tool) and migration 048.
  $canProcs = $canRun && coreui_pprocs_ready($pdo);
  try {
    $list = coreui_pfiles_list($pdo, $uid, $projectId);
  } catch (Throwable $e) {
    return '';
  }
  $names = array_map(static fn(array $f): string => $f['path'] . ' (' . max(1, (int)ceil($f['size_bytes'] / 1024)) . ' KB)',
    array_slice($list['files'], 0, $compact ? 15 : COREUI_PFTOOLS_HINT_FILES));
  $more = $list['count'] - count($names);
  $files = $names === [] ? 'Das Projekt hat noch keine Dateien.'
    : 'Dateien des Projekts: ' . implode(', ', $names) . ($more > 0 ? ' und ' . $more . ' weitere.' : '.');
  // 0.7.81 (P11 B): versions and working states; read only here (the first version comes with the first use).
  $vcs = '';
  if (coreui_pvcs_ready($pdo)) {
    $st = $pdo->prepare('SELECT b.name FROM stu_console_project_vcs v JOIN stu_console_project_branches b ON b.id=v.active_branch_id
      WHERE v.project_id=?');
    $st->execute([$projectId]);
    $active = (string)($st->fetchColumn() ?: COREUI_PVCS_MAIN);
    $canMerge = coreui_pvcs_merge_ready($pdo);
    $vcs = $compact
      ? " Arbeitsstand: " . $active . ". Version sichern: [VERSION_SICHERN: Beschreibung]. Eigener Arbeitsstand: [ARBEITSSTAND: name]."
        . ($canMerge ? " Zusammenfuehren: [ZUSAMMENFUEHREN: name]." : '')
      : "VERSIONEN: Aktiver Arbeitsstand ist " . $active . ".\n"
        . "- [VERSION_SICHERN: kurze Beschreibung] sichert den Stand aller Dateien als Version (wiederherstellbar).\n"
        . "- [ARBEITSSTAND: name] wechselt auf diesen Arbeitsstand oder legt ihn neu an (Name wie mara/neue-farbe, ohne Leerzeichen). Ein vorhandener Arbeitsstand kann andere Dateien enthalten; pruefe dessen Dateiliste. "
        . "ungesicherte Aenderungen kommen beim Anlegen mit, der bisherige Arbeitsstand bleibt bei seiner letzten Version.\n"
        . ($canMerge ? "- [ZUSAMMENFUEHREN: name] fuehrt diesen Arbeitsstand in den aktiven zusammen, nur ohne Konflikte; Konflikte loest die Person.\n" : '')
        . "Fuer eine groessere Aenderung legst du zuerst einen eigenen Arbeitsstand an, damit der bisherige unveraendert bleibt; "
        . "ist sie fertig und getestet, sicherst du eine Version. Zusammenfuehren nur, wenn die Person es moechte.\n";
  }
  if ($compact) {
    return "PROJEKTDATEIEN: " . $files . " Bei Folgewuenschen aktuelle Dateien erweitern, nicht alten Projektplan neu bauen. " . "Lesen: [DATEI: pfad]. Gezielt aendern: [DATEI_AENDERN: pfad] mit Bloecken "
      . "<<<<<<< SUCHEN / alte Zeilen genau / ======= / neue Zeilen / >>>>>>> ERSETZEN, dann [/DATEI_AENDERN]. "
      . "Neu oder ganz ersetzen: [DATEI_NEU: pfad] Inhalt [/DATEI_NEU]."
      . ($canRun ? " Ausfuehren und testen: [AUSFUEHREN: befehl]." : '')
      . coreui_pftools_env_hint($pdo, $uid, $projectId, true, $canRun, $canProcs) . $vcs;
  }
  return "PROJEKTDATEIEN: " . $files . "\n"
    . "Ein neuer Seitenwunsch (z.B. blog.html) ergaenzt die bestehenden Projektdateien. Der alte, abgeschlossene Projektplan ist nur historischer Kontext: NICHT die Homepage neu aus diesem Plan erstellen. Bestehende Dateien und die aktuelle Nutzernachricht sind massgebend.\n"
    . "Du kannst die Dateien dieses Projekts selbst lesen und gezielt aendern, ein Werkzeug pro Schritt:\n"
    . "- [DATEI: pfad] liest eine Datei mit Zeilennummern. [DATEI: pfad#L250] liest ab Zeile 250. Folge bei einem gekuerzten Auszug dem angegebenen naechsten Abschnitt.\n"
    . "- Gezielt aendern:\n[DATEI_AENDERN: pfad]\n<<<<<<< SUCHEN\nalte Zeilen, Zeichen fuer Zeichen wie in der Datei (ohne Zeilennummern)\n"
    . "=======\nneue Zeilen\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]\n"
    . "  Mehrere SUCHEN/ERSETZEN-Bloecke sind erlaubt; jeder SUCHEN-Text muss genau einmal vorkommen.\n"
    . "- [DATEI_NEU: pfad] ganzer Inhalt [/DATEI_NEU] legt eine Datei an oder ersetzt sie ganz.\n"
    // 0.8.17: a whole large page in one block ran into the output limit and lost its end marker.
    . "  Eine grosse Datei legst du in Teilen an: ein kurzes, vollstaendiges Grundgeruest mit DATEI_NEU, "
    . "danach je Abschnitt ein eigener DATEI_AENDERN-Block. Eine vorhandene Seite erweiterst du mit DATEI_AENDERN.\n"
    . ($canRun ? "- [AUSFUEHREN: befehl] fuehrt einen Befehl mit den gespeicherten Dateien im Sandbox-Container aus (Python 3.12 und "
      . "Node.js 24 mit npm), zum Beispiel [AUSFUEHREN: python -m unittest -v] oder [AUSFUEHREN: npm test]; du bekommst die echte "
      . "Ausgabe und den Exitcode. "
      . "Nach einer Aenderung fuehrst du die Tests erneut aus. Was der Befehl an Dateien aendert, bleibt nicht erhalten.\n"
      // 0.8.17: a static page has no test suite; a run was declared blocked for want of a test.
      . "  Eine statische Webseite ohne Testsuite pruefst du selbst, zum Beispiel mit [AUSFUEHREN: node --check script.js] "
      . "oder einem kurzen Python-Befehl, der die HTML-Dateien einliest; eine fehlende Pruefung ist keine Blockade.\n" : '')
    . coreui_pftools_env_hint($pdo, $uid, $projectId, false, $canRun, $canProcs)
    . $vcs
    . "Aendere nur, was die Person moechte. Fuer kleine Aenderungen an vorhandenen Dateien nimm DATEI_AENDERN statt eines neuen Baus. "
    . "Jeder vorige Stand bleibt im Tab Dateien wiederherstellbar. Sag danach kurz, was du geaendert hast; "
    // 0.8.17: free text after the last tool is no decision in a running project task.
    . "in einem laufenden Projektauftrag steht das im COREUI_TASK_DONE-Block.";
}
