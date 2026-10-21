<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$root = dirname(__DIR__);

function frontend_test_read(string $path): string {
  $content = @file_get_contents($path);
  if (!is_string($content) || $content === '') {
    throw new RuntimeException('Datei fehlt oder ist leer: ' . $path);
  }
  return $content;
}

function frontend_test_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

try {
  $settings = frontend_test_read($root . '/js/settings.js');
  $admin = frontend_test_read($root . '/js/admin.js');
  $adminHtml = frontend_test_read($root . '/admin/index.html');
  $appHtml = frontend_test_read($root . '/app.html');
  $console = frontend_test_read($root . '/js/console-app.js');
  $markdown = frontend_test_read($root . '/js/coreui-markdown.js');
  $css = frontend_test_read($root . '/css/console.css');
  $workJs = frontend_test_read($root . '/js/coreui-work-events.js');
  $messageApi = frontend_test_read($root . '/api/console_messages.php');
  $workStore = frontend_test_read($root . '/api/console_work_store.php');
  $streamApi = frontend_test_read($root . '/api/console_stream.php');
  $profileStore = frontend_test_read($root . '/api/profile_store.php');
  $profileMedia = frontend_test_read($root . '/api/profile_media.php');

  frontend_test_assert(!str_contains($settings, 'history.back()'), 'Einstellungen verwenden weiterhin den Browser-Verlauf.');
  frontend_test_assert(str_contains($settings, "location.replace('app.html')"), 'Core Channel besitzt kein deterministisches Ziel.');
  frontend_test_assert(str_contains($adminHtml, 'id="adminBack"'), 'Admin-Zurueckschalter ist nicht eindeutig verdrahtet.');
  frontend_test_assert(
    str_contains($admin, "location.replace('../settings.html')") && str_contains($admin, 'event.preventDefault()'),
    'Admin Core hinterlaesst weiterhin einen problematischen Verlaufseintrag.'
  );

  foreach ([
    "image.className = 'msg-avatar-image'",
    "image.addEventListener('load'",
    "image.addEventListener('error'",
    "element.classList.add('has-image')",
  ] as $needle) {
    frontend_test_assert(str_contains($console, $needle), 'Chat-Avatar-Fallback fehlt: ' . $needle);
  }
  frontend_test_assert(!str_contains($console, 'element.style.backgroundImage'), 'Chat-Avatar wird weiterhin nur als CSS-Hintergrund geladen.');
  frontend_test_assert(
    str_contains($css, '.msg-avatar-image') && str_contains($css, '.msg-avatar.has-image .msg-avatar-image'),
    'CSS fuer das echte Chat-Avatarbild fehlt.'
  );
  frontend_test_assert(
    str_contains($profileStore, 'function coreui_profile_media_record')
      && str_contains($profileMedia, 'coreui_profile_media_record($pdo, $uid, $slot)'),
    'Profil-API und Medienendpunkt verwenden nicht dieselbe Dateipruefung.'
  );
  $markdownScriptPos = strpos($appHtml, 'js/coreui-markdown.js');
  $consoleScriptPos = strpos($appHtml, 'js/console-app.js');
  frontend_test_assert(
    $markdownScriptPos !== false && $consoleScriptPos !== false && $markdownScriptPos < $consoleScriptPos,
    'Markdown-Renderer wird nicht vor dem Chatclient geladen.'
  );
  frontend_test_assert(!str_contains($markdown, '.innerHTML'), 'Markdown-Renderer verwendet innerHTML.');
  frontend_test_assert(
    str_contains($console, 'buildMessageActions(role, text, record || null)')
      && str_contains($css, '.msg-actions')
      && str_contains($css, '.md-codeblock'),
    'Nachrichtenaktionen oder Codeblockdarstellung sind nicht vollstaendig verdrahtet.'
  );
  foreach ([
    "action: 'edit_message'",
    'state.pendingBySession[ses.id]',
    'queuedTurnsBySession',
    'drainQueuedTurn(sessionId)',
    'variantSelectionBySession',
    'msg-variant-nav',
  ] as $needle) {
    frontend_test_assert(
      str_contains($console, $needle),
      'Bearbeitung, Warteschlange oder Antwortvarianten fehlen: ' . $needle
    );
  }
  foreach ([
    'id="composerEditState"',
    'id="composerEditLabel"',
    'id="composerEditCancel"',
  ] as $needle) {
    frontend_test_assert(str_contains($appHtml, $needle), 'Bearbeitungsanzeige fehlt: ' . $needle);
  }
  frontend_test_assert(
    str_contains($css, '.composer-edit-state') && str_contains($css, '.msg-variant-nav'),
    'Darstellung fuer Bearbeitung oder Antwortvarianten fehlt.'
  );

  frontend_test_assert(
    !str_contains($console, 'var thinkRow =')
      && str_contains($console, "querySelector('.work-body')")
      && str_contains($console, 'events: record.work_events'),
    'Live-/History-Arbeitsschritte werden doppelt dargestellt.'
  );
  frontend_test_assert(
    str_contains($messageApi, "coreui_work_map_for_responses(")
      && str_contains($messageApi, "\$row['work_events']")
      && !str_contains($workJs, 'fetch('),
    'Arbeitsereignisse werden nicht ueber die authentifizierte Nachrichten-Historie transportiert.'
  );
  frontend_test_assert(
    str_contains($css, '.msg-row.ember .msg-content')
      && str_contains($css, '.msg-row.ember .msg-bubble')
      && str_contains($css, '.msg-row.ember .work-panel'),
    'Sprechblasenfreier Ember-Chat oder inline Arbeitsverlauf fehlt.'
  );

  frontend_test_assert(
    str_contains($console, 'function rememberLiveWorkEvent(')
      && str_contains($console, 'liveWorkEventsBySession')
      && str_contains($console, 'lastCompletedWorkMessageBySession')
      && str_contains($console, 'work_events: savedWork')
      && str_contains($console, 'renderMessageRecord(saved || m)')
      && str_contains($workJs, 'function merge(serverEvents, liveEvents)')
      && str_contains($workJs, 'Arbeitsweg (')
      && str_contains($workStore, 'LEFT JOIN stu_console_agent_runs r')
      && str_contains($workStore, 'BINARY r.run_key=BINARY w.run_key')
      && str_contains($streamApi, 'console_stream_saved_work_events('),
    'Arbeitsweg wird nach dem Stream oder beim Neuladen nicht sicher erhalten.'
  );

  frontend_test_assert(
    str_contains($console, 'function extractSuggestedReplies(markdown)')
      && str_contains($console, 'function buildSuggestedReplyButtons(markdown, sessionId)')
      && str_contains($console, "button.addEventListener('click'")
      && str_contains($console, 'sendMessage(label);')
      && str_contains($console, 'state.pendingBySession[ses.id]'),
    'Anklickbare Vorschlagsantworten fehlen oder umgehen den Chat-Sendepfad.'
  );
  frontend_test_assert(
    str_contains($css, '.ember-suggested-replies')
      && str_contains($css, '.ember-suggested-reply:focus-visible')
      && str_contains($css, '.ember-suggested-reply { width: 100%; }'),
    'Vorschlagsantworten sind nicht mobil und tastaturzugaenglich.'
  );
  fwrite(STDOUT, "Frontend-Regressions-Selftest OK: Navigation, Avatare, Markdown, Bearbeitung, Warteschlange und Varianten sind fehlersicher verdrahtet.\n");
} catch (Throwable $e) {
  fwrite(STDERR, 'Frontend-Regressions-Selftest FEHLER: ' . $e->getMessage() . "\n");
  exit(2);
}
