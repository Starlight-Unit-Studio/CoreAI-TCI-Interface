<?php
declare(strict_types=1);
// Read-only investigation. Never prints user messages, Python code, prompts,
// passwords, stderr, stdout or generated files. Does not restart services.
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit;
}
require_once dirname(__DIR__) . '/api/db.php';
try {
  $pdo = stu_pdo();
  $rows = $pdo->query("SELECT status,COUNT(*) AS jobs,
    MIN(TIMESTAMPDIFF(SECOND,created_at,NOW())) AS youngest_s,
    MAX(TIMESTAMPDIFF(SECOND,created_at,NOW())) AS oldest_s
    FROM stu_ember_py_jobs GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
  echo "Ember CoreUI Python-Worker: Queue-Diagnose (ohne private Inhalte)\n";
  foreach ($rows as $row) {
    printf("  %-8s %4d Jobs, Alter %d..%d s\n",
      (string)($row['status'] ?? '?'),(int)($row['jobs'] ?? 0),
      (int)($row['youngest_s'] ?? 0),(int)($row['oldest_s'] ?? 0));
  }
  if (!$rows) echo "  Keine Python-Jobs registriert.\n";
  $details = $pdo->query("SELECT id,status,worker_pid,exit_code,duration_ms,
    TIMESTAMPDIFF(SECOND,COALESCE(started_at,created_at),NOW()) AS age_s
    FROM stu_ember_py_jobs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($details as $row) {
    printf("  #%d %-7s Alter %ds Worker-PID %s, Exit %s, %sms\n",
      (int)$row['id'],(string)$row['status'],(int)$row['age_s'],
      $row['worker_pid']===null?'-':(string)(int)$row['worker_pid'],
      $row['exit_code']===null?'-':(string)(int)$row['exit_code'],
      $row['duration_ms']===null?'-':(string)(int)$row['duration_ms']);
  }
  $blocked = $pdo->query("SELECT COUNT(*) FROM stu_ember_py_jobs
    WHERE status='running' AND started_at < NOW() - INTERVAL 3 MINUTE")->fetchColumn();
  if ((int)$blocked > 0) {
    echo "[WARN] Laenger als 3 Minuten laufende Jobs. Dienst und Logs pruefen, nicht blind neu starten.\n";
  }
  echo "Hinweis: Die Queue-Diagnose beweist keine Modellstream-Gesundheit.\n";
} catch (Throwable $e) {
  fwrite(STDERR,'Diagnose konnte Python-Queue nicht auslesen: ' . get_class($e) . "\n");
  exit(2);
}
