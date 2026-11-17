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
 */
require_once __DIR__ . '/project_files_store.php';
require_once __DIR__ . '/project_runs_store.php';

const COREUI_PFTOOLS_READ_CHARS = 16000;
const COREUI_PFTOOLS_HINT_FILES = 40;
const COREUI_PFTOOLS_RUN_WAIT = 130;
const COREUI_PFTOOLS_RUN_CHARS = 6000;

/** The project of a chat when Mara may use the file tools there, else ''. */
function coreui_pftools_project(PDO $pdo, int $uid, string $sessionId): string {
  if ($uid <= 0 || $sessionId === '' || !coreui_pfiles_schema_ready($pdo)) return '';
  $scope = coreui_project_session_scope($pdo, $uid, $sessionId);
  return !empty($scope['available']) ? (string)($scope['id'] ?? '') : '';
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
  $start = '~\[\s*AUSF(?:UE|Ü)HREN\s*:\s*';
  if (preg_match($start . '([^\r\n]{1,400})\][ \t]*(?:\r?\n|\z)~u', $reply, $m) === 1
      && preg_match('~\[\s*[A-ZÄÖÜ_]{3,}(?:\s+[A-ZÄÖÜ]+)*\s*:~u', $m[1]) !== 1) {
    $command = trim($m[1]);
  } elseif (preg_match($start . '([^\[\]\r\n]{1,400})\]~u', $reply, $m) === 1) {
    $command = trim($m[1]);
  } else {
    return null;
  }
  return $command !== '' ? $command : null;
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

/** A file with line numbers for Mara (pure), shortened to $max characters. */
function coreui_pftools_numbered(string $content, int $max = COREUI_PFTOOLS_READ_CHARS): string {
  $lines = preg_split('~\r?\n~', $content) ?: [''];
  // The newline at the end of the last line is no extra line.
  if (count($lines) > 1 && end($lines) === '') array_pop($lines);
  $width = strlen((string)count($lines));
  $out = '';
  foreach ($lines as $i => $line) {
    $row = str_pad((string)($i + 1), $width, ' ', STR_PAD_LEFT) . ' | ' . $line . "\n";
    if (strlen($out) + strlen($row) > $max) {
      return $out . '... (gekuerzt nach Zeile ' . $i . ' von ' . count($lines) . ')';
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

/** Run one file tool of the work loop; the result is what Mara reads next. */
function coreui_pftools_run(PDO $pdo, int $uid, string $sessionId, array $tool): string {
  $projectId = coreui_pftools_project($pdo, $uid, $sessionId);
  if ($projectId === '') return 'Projektdateien gibt es nur in einem Chat, der zu einem Projekt gehoert.';
  $kind = (string)($tool['tool'] ?? '');
  try {
    if ($kind === 'prun') {
      $run = coreui_pruns_enqueue($pdo, $uid, $projectId, (string)$tool['input'], $sessionId);
      $run = coreui_pruns_wait($pdo, $uid, $projectId, (int)$run['id'], COREUI_PFTOOLS_RUN_WAIT);
      $ok = $run['status'] === 'done' && (int)$run['exit_code'] === 0;
      coreui_pftools_record($pdo, $ok ? 'success' : ($run['status'] === 'done' ? 'info' : 'error'),
        $run['status'] === 'done' ? 'Projekt ausgefuehrt, Exitcode ' . (int)$run['exit_code'] : 'Projektlauf ohne Ergebnis', ['count'=>1]);
      return coreui_pftools_run_result($run);
    }
    if ($kind === 'pfile_read') {
      $path = coreui_pfiles_path((string)$tool['input']);
      $file = coreui_pfiles_get($pdo, $uid, $projectId, $path);
      coreui_pftools_record($pdo, 'info', 'Projektdatei gelesen', ['count'=>1]);
      return 'Datei ' . $file['path'] . ' (Stand ' . $file['revision'] . ', ' . $file['size_bytes'] . " Bytes):\n"
        . coreui_pftools_numbered($file['content']) . "\n(Die Zeilennummern und \" | \" gehoeren nicht zur Datei.)";
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
    coreui_pftools_record($pdo, 'error', 'Projektdatei nicht verfuegbar', ['count'=>0]);
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
    ][$e->getMessage()] ?? $e->getMessage();
    return 'Das ging nicht: ' . $why . '.';
  }
  return 'Unbekanntes Datei-Werkzeug.';
}

/**
 * The part of Mara's prompt in a project chat: which files the project has and
 * how she reads and changes them. '' outside a project or without files support.
 */
function coreui_pftools_hint(PDO $pdo, int $uid, string $sessionId, bool $compact, bool $canRun = false): string {
  $projectId = coreui_pftools_project($pdo, $uid, $sessionId);
  if ($projectId === '') return '';
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
  if ($compact) {
    return "PROJEKTDATEIEN: " . $files . " Lesen: [DATEI: pfad]. Gezielt aendern: [DATEI_AENDERN: pfad] mit Bloecken "
      . "<<<<<<< SUCHEN / alte Zeilen genau / ======= / neue Zeilen / >>>>>>> ERSETZEN, dann [/DATEI_AENDERN]. "
      . "Neu oder ganz ersetzen: [DATEI_NEU: pfad] Inhalt [/DATEI_NEU]."
      . ($canRun ? " Ausfuehren und testen: [AUSFUEHREN: befehl] (Sandbox mit Python 3.12)." : '');
  }
  return "PROJEKTDATEIEN: " . $files . "\n"
    . "Du kannst die Dateien dieses Projekts selbst lesen und gezielt aendern, ein Werkzeug pro Schritt:\n"
    . "- [DATEI: pfad] liest eine Datei mit Zeilennummern. Lies eine Datei, bevor du sie aenderst.\n"
    . "- Gezielt aendern:\n[DATEI_AENDERN: pfad]\n<<<<<<< SUCHEN\nalte Zeilen, Zeichen fuer Zeichen wie in der Datei (ohne Zeilennummern)\n"
    . "=======\nneue Zeilen\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]\n"
    . "  Mehrere SUCHEN/ERSETZEN-Bloecke sind erlaubt; jeder SUCHEN-Text muss genau einmal vorkommen.\n"
    . "- [DATEI_NEU: pfad] ganzer Inhalt [/DATEI_NEU] legt eine Datei an oder ersetzt sie ganz.\n"
    . ($canRun ? "- [AUSFUEHREN: befehl] fuehrt einen Befehl mit den gespeicherten Dateien im Sandbox-Container aus (Python 3.12 und "
      . "Node.js 24 mit npm), zum Beispiel [AUSFUEHREN: python -m unittest -v] oder [AUSFUEHREN: npm test]; du bekommst die echte "
      . "Ausgabe und den Exitcode. "
      . "Nach einer Aenderung fuehrst du die Tests erneut aus. Was der Befehl an Dateien aendert, bleibt nicht erhalten.\n" : '')
    . "Aendere nur, was die Person moechte. Fuer kleine Aenderungen an vorhandenen Dateien nimm DATEI_AENDERN statt eines neuen Baus. "
    . "Jeder vorige Stand bleibt im Tab Dateien wiederherstellbar. Sag danach kurz, was du geaendert hast.";
}
