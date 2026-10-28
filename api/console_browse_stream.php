<?php
declare(strict_types=1);
// ─────────────────────────────────────────────────────────────────────────────
// CoreAI: TCI Interface - private Live-Browser-Agentenansicht
// Eigenstaendiger SSE-Endpunkt fuer asynchrone Browse-Jobs.
//
// Tailt die JPEG-Frames und den kurzen Status EINES Browse-Jobs. Die Frames bleiben in
// der privaten Datenbank und werden nur nach Benutzer-/Job-Pruefung ausgeliefert.
// Reiner Lese-Endpunkt - startet/aendert nichts an der Generierung.
//
// 0.5.77: after_frame/after_step setzen einen abgerissenen Stream fort (der Client
// verbindet sich nach dem 15-Minuten-Deckel oder einem Netzfehler neu, ohne alte Bilder
// doppelt zu bekommen). Jeder Schritt traegt seine Art (step, think, warn), und ein
// status-Ereignis meldet, ob der Auftrag noch auf Mara wartet oder schon laeuft.
// ─────────────────────────────────────────────────────────────────────────────

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ignore_user_abort(true);
@set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function bsse_send(string $event, $data): void {
  echo 'event: ' . $event . "\n";
  echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
  @flush();
}
function bsse_comment(string $c): void { echo ': ' . $c . "\n\n"; @flush(); }

/** Art eines Live-Schritts (rein): unbekannte oder alte Zeilen gelten als step. */
function bsse_step_kind($kind): string {
  $kind = strtolower(trim((string)$kind));
  return in_array($kind, ['step', 'think', 'warn'], true) ? $kind : 'step';
}

/** Fortsetzungspunkt aus der Anfrage (rein): nur nicht negative Ganzzahlen. */
function bsse_resume_id($value): int {
  $value = is_scalar($value) ? trim((string)$value) : '';
  return preg_match('~^[0-9]{1,18}$~', $value) === 1 ? (int)$value : 0;
}

function bsse_emit_steps(PDO $pdo, int $jobId, int &$lastStepId): int {
  static $withKind = true;
  $rows = [];
  try {
    if ($withKind) {
      try {
        $ss = $pdo->prepare(
          "SELECT id, text, kind FROM stu_ember_browse_steps
           WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 100"
        );
        $ss->execute([$jobId, $lastStepId]);
        $rows = $ss->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        // Tabelle aus einer Version ohne Spalte kind (Worker noch nicht neu gestartet).
        $withKind = false;
      }
    }
    if (!$withKind) {
      $ss = $pdo->prepare(
        "SELECT id, text FROM stu_ember_browse_steps
         WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 100"
      );
      $ss->execute([$jobId, $lastStepId]);
      $rows = $ss->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {
    return 0;
  }
  foreach ($rows as $row) {
    $lastStepId = (int)$row['id'];
    bsse_send('step', [
      'id' => $lastStepId,
      'text' => (string)$row['text'],
      'kind' => bsse_step_kind($row['kind'] ?? 'step'),
    ]);
  }
  return count($rows);
}

function bsse_emit_frames(PDO $pdo, int $jobId, int &$lastFrameId): int {
  $count = 0;
  try {
    $sf = $pdo->prepare(
      "SELECT id, step, b64, cx, cy, vw, vh, cursor_click, page_url, frame_label
       FROM stu_ember_browse_frames
       WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 25"
    );
    $sf->execute([$jobId, $lastFrameId]);
    foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $lastFrameId = (int)$row['id'];
      $count++;
      bsse_send('frame', [
        'id'    => $lastFrameId,
        'step'  => (int)($row['step'] ?? 0),
        'b64'   => (string)($row['b64'] ?? ''),
        'cx'    => $row['cx'] !== null ? (float)$row['cx'] : null,
        'cy'    => $row['cy'] !== null ? (float)$row['cy'] : null,
        'vw'    => $row['vw'] !== null ? (int)$row['vw'] : null,
        'vh'    => $row['vh'] !== null ? (int)$row['vh'] : null,
        'click' => ((int)($row['cursor_click'] ?? 0) === 1),
        'url'   => (string)($row['page_url'] ?? ''),
        'label' => (string)($row['frame_label'] ?? ''),
      ]);
    }
  } catch (Throwable $e) {
    // Alte Installation waehrend eines Updates: Worker legt die Tabelle selbst an.
  }
  return $count;
}

try {
  $uid = stu_require_user_id();
  $pdo = stu_pdo();
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'auth']);
  exit;
}

$jobId = (int)($_GET['job_id'] ?? 0);
$sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
if ($jobId <= 0 || $sessionId === '') {
  bsse_send('error', ['error' => 'invalid_job_scope']);
  exit;
}

try {
  coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'session_not_found']);
  exit;
}

// Eigentuemer-Pruefung: nur Console-Jobs dieses Users.
try {
  $st = $pdo->prepare(
    "SELECT id, status, channel, recipient_uid, trigger_user_id, session_id
     FROM stu_ember_browse_jobs WHERE id = ? LIMIT 1"
  );
  $st->execute([$jobId]);
  $job = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'db']);
  exit;
}
if (!$job
    || ($job['channel'] ?? '') !== 'console'
    || (string)($job['session_id'] ?? '') !== $sessionId
    || ((int)($job['recipient_uid'] ?? 0) !== (int)$uid && (int)($job['trigger_user_id'] ?? 0) !== (int)$uid)) {
  bsse_send('error', ['error' => 'forbidden']);
  exit;
}

// Session-Lock freigeben - die Schleife laeuft minutenlang.
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

bsse_comment('open');

$lastStepId  = bsse_resume_id($_GET['after_step'] ?? '');
$lastFrameId = bsse_resume_id($_GET['after_frame'] ?? '');
$lastStatus  = '';
$startTs    = time();
$MAX_SECS   = 900;   // Sicherheits-Cap passend zu Nginx und PHP-FPM

while (!connection_aborted() && (time() - $startTs) < $MAX_SECS) {
  // Neue Browserbilder zuerst ausliefern. Sie ersetzen das fruehere Schrittprotokoll.
  bsse_emit_frames($pdo, $jobId, $lastFrameId);

  // Neue Schritte ausliefern.
  bsse_emit_steps($pdo, $jobId, $lastStepId);

  // Job-Status pruefen.
  try {
    $sj = $pdo->prepare("SELECT status FROM stu_ember_browse_jobs WHERE id = ? LIMIT 1");
    $sj->execute([$jobId]);
    $status = (string)($sj->fetchColumn() ?: '');
    if ($status === 'done' || $status === 'error') {
      // Alle letzten Frames nachreichen, auch wenn der Worker schneller als der Client war.
      for ($batch = 0; $batch < 20; $batch++) {
        if (bsse_emit_frames($pdo, $jobId, $lastFrameId) < 25) break;
      }
      // Letzte evtl. noch nicht gelesenen Schritte nachreichen.
      bsse_emit_steps($pdo, $jobId, $lastStepId);
      bsse_send($status === 'done' ? 'done' : 'error', ['status' => $status]);
      exit;
    }
    if ($status !== '' && $status !== $lastStatus) {
      // queued = Mara ist noch mit etwas anderem beschaeftigt (Warteschlange).
      $lastStatus = $status;
      bsse_send('status', ['status' => $status]);
    }
  } catch (Throwable $e) {}

  bsse_comment('ping'); // erkennt Client-Abbruch (connection_aborted) + haelt die Leitung warm
  usleep(1000000);      // 1s Poll-Intervall
}

// Cap erreicht / Client weg. Der Client setzt mit diesen Punkten fort.
bsse_send('timeout', ['note' => 'stream_cap', 'after_frame' => $lastFrameId, 'after_step' => $lastStepId]);
exit;
