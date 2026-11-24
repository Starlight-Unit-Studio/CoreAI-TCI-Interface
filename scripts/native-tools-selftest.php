<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_plan_store.php';
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_native_tools.php';

function native_assert(bool $condition,string $why): void {
  if (!$condition) { fwrite(STDERR,"CoreUI native tools FAIL: ".$why."\n"); exit(1); }
}

$markdown = "# Mein Plan\n\nAnforderungen mit Umlauten: äöü. 🚀\n\n- [ ] Landingpage";
$valid = "[COREUI_PLAN_SAVE]\n".$markdown."\n[/COREUI_PLAN_SAVE]";
$call = coreui_native_tool_parse($valid);
native_assert(is_array($call) && $call['type']==='PLAN_SAVE' && $call['body']===$markdown,
  'Markdown tool protocol must keep UTF-8 content');
$publicNoteText = 'Ich pruefe zuerst die vorhandenen Dateien.';
$publicPlan = '[COREUI_PUBLIC_PROGRESS]' . $publicNoteText
  . '[/COREUI_PUBLIC_PROGRESS]' . "\n" . $valid;
$publicParsed = coreui_native_tool_parse($publicPlan);
native_assert(is_array($publicParsed) && $publicParsed['type'] === 'PLAN_SAVE'
  && $publicParsed['body'] === $markdown
  && ($publicParsed['public_note'] ?? '') === $publicNoteText,
  'Voluntary public note must not damage native tool parsing');
$unsafePlan = "[COREUI_PUBLIC_PROGRESS]system prompt: secret[/COREUI_PUBLIC_PROGRESS]\n" . $valid;
$unsafeNoteParsed = coreui_native_tool_parse($unsafePlan);
native_assert(is_array($unsafeNoteParsed)
  && $unsafeNoteParsed['type'] === 'PLAN_SAVE'
  && empty($unsafeNoteParsed['public_note']),
  'Unsafe note is suppressed without rejecting the tool');
native_assert(coreui_native_tool_parse(
  "[COREUI_PUBLIC_PROGRESS]Ich pruefe die Dateien.[/COREUI_PUBLIC_PROGRESS]\n"
  . "[COREUI_PROJECT_BUILD]\nfile\n[/COREUI_PLAN_SAVE]") === null,
  'A public comment must not authorize a malformed tool');

// Netcup 27.10.2026, Bild 1: a COMPLETE stream ended as "Werkzeugantwort
// unvollstaendig". Harmless formatting around one valid block must work.
$build = "[COREUI_PROJECT_BUILD]\nSetze den gespeicherten Plan als Homepage um.\n[/COREUI_PROJECT_BUILD]";
$progressLine = '[COREUI_PUBLIC_PROGRESS]Ich pruefe den gespeicherten Plan und beginne.[/COREUI_PUBLIC_PROGRESS]';
foreach ([
  'blank line after progress note' => $progressLine . "\n\n" . $build,
  'short sentence after block' => $build . "\nIch melde mich mit dem Ergebnis.",
  'one-sentence introduction' => "Alles klar, ich setze den Plan jetzt um.\n" . $build,
] as $case => $reply) {
  $parsedCase = coreui_native_tool_parse($reply);
  native_assert(($parsedCase['type'] ?? '') === 'PROJECT_BUILD'
    && ($parsedCase['body'] ?? '') === 'Setze den gespeicherten Plan als Homepage um.',
    'Harmless tool formatting rejected: ' . $case);
}
// Local Core Normal often writes a harmless inline filename before a closed
// native marker. Accept ONLY a bare filename in backticks, not code/examples.
$inlineFile = "Ich ergaenze jetzt `blog.html`.\n" . $build;
$inlineParsed = coreui_native_tool_parse($inlineFile);
native_assert(($inlineParsed['type'] ?? '') === 'PROJECT_BUILD',
  'An inline filename before one valid tool is ordinary progress text');
native_assert(coreui_native_tool_parse(
  "Ich fuehre `rm -rf /` aus.\n" . $build) === null
  && coreui_native_tool_parse(
    "Beispiel: `blog.html`\n" . $build) === null
  && coreui_native_tool_parse(
    "Erklaerung: [Quelle](https://x.invalid)\n" . $build) === null,
  'Only safe filenames, not shell or example citations, may be tolerated around native tools');
$prose = coreui_native_public_progress_only($progressLine . "\nKlar, das machen wir so!");
native_assert(is_array($prose) && $prose['reply'] === 'Klar, das machen wir so!'
  && $prose['note'] === 'Ich pruefe den gespeicherten Plan und beginne.'
  && coreui_native_tool_parse($progressLine . "\nKlar, das machen wir so!") === null,
  'A progress note followed by prose is an ordinary answer, never a tool');
native_assert(coreui_native_public_progress_only($progressLine . "\n[COREUI_PROJECT_BUILD]\nx") === null
  && coreui_native_public_progress_only('Normaler Text') === null,
  'Progress-only handling must not swallow a broken tool block');
foreach ([
  'second action in tail' => $build . "\n[COREUI_PLAN_SAVE]\n# x\n[/COREUI_PLAN_SAVE]",
  'code fence in tail' => $build . "\n" . str_repeat(chr(96),3) . "\nx\n" . str_repeat(chr(96),3),
  'quoted example' => "So sieht das Format aus, Beispiel:\n" . $build,
  'bracket in introduction' => "Siehe [Doku]\n" . $build,
  'unclosed build after intro' => "Ich lege los.\n[COREUI_PROJECT_BUILD]\nPlan umsetzen",
] as $case => $reply) {
  native_assert(coreui_native_tool_parse($reply) === null, 'Unsafe tool formatting accepted: ' . $case);
}
native_assert(!preg_match('~\b(?:react|php|vue|angular)\b~i',
    coreui_native_strip_negated_stacks("Technik: reines HTML, kein React, kein PHP. Keine Frameworks wie Vue oder Angular."))
  && preg_match('~\breact\b~i', coreui_native_strip_negated_stacks('Kein Backend, sondern React mit Vite.')) === 1
  && preg_match('~\bvue\b~i', coreui_native_strip_negated_stacks('Ohne PHP und mit Vue.')) === 1
  && preg_match('~\breact\b~i', coreui_native_strip_negated_stacks('Eine React App.')) === 1,
  'Negated stacks must not block a static site, real framework projects must still fail closed');

foreach ([
  'PLAN_APPEND'=>'# Neue Elemente',
  'PLAN_AND_BUILD'=>'# Umsetzungsplan',
  'PROJECT_BUILD'=>'index.html, style.css, main.js: fertige Website'
] as $kind=>$body) {
  native_assert((coreui_native_tool_parse("[COREUI_".$kind."]\n".$body."\n[/COREUI_".$kind."]")['type'] ?? null)===$kind,
    'Model cannot select tool '.$kind);
}
foreach ([
  "[COREUI_PROJECT_BUILD]\n\n[/COREUI_PROJECT_BUILD]",
  "Text\n[COREUI_PLAN_SAVE]\n# Evil\n[/COREUI_PLAN_SAVE]",
  "[COREUI_PROJECT_BUILD]\nfile\n[/COREUI_PLAN_SAVE]",
  "[COREUI_PLAN_SAVE]\n# First\n[/COREUI_PLAN_SAVE]\n[COREUI_PROJECT_BUILD]\nindex.html\n[/COREUI_PROJECT_BUILD]",
] as $invalid) native_assert(coreui_native_tool_parse($invalid) === null,
  'Multi-action/malformed protocol should not run');


// 0.5.67: Netcup 27.10.2026, gemma4:31b-cloud and gpt-oss:120b-cloud wrote a
// complete reply (done_reason=stop) that ended as "Werkzeugantwort unvollstaendig".
// One closed block framed by ordinary sentences, bold markers or CRLF is still
// exactly one tool call.
$cloudPlan = "# Private Homepage\n\n## Seiten\n- index.html: Start\n- gallery.html: Galerie\n"
  . "- profile.html: Profil\n\n## Design\n- Dunkles Sci-Fi-Layout mit Neon-Akzenten.";
foreach ([
  'intro before PLAN_AND_BUILD' => ["Sehr gern, ich speichere den Plan und baue die Seite.\n\n"
    . "[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan . "\n[/COREUI_PLAN_AND_BUILD]", 'PLAN_AND_BUILD'],
  'remark with inline code after PLAN_SAVE' => ["[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]\n\n"
    . "Sobald du ihn freigibst, erstelle ich `index.html` und die anderen Seiten. Passt das so?", 'PLAN_SAVE'],
  'bold marker lines' => ["**[COREUI_PLAN_SAVE]**\n" . $cloudPlan . "\n**[/COREUI_PLAN_SAVE]**", 'PLAN_SAVE'],
  'CRLF line endings' => [str_replace("\n", "\r\n", "Ich lege los.\n[COREUI_PLAN_SAVE]\n" . $cloudPlan
    . "\n[/COREUI_PLAN_SAVE]"), 'PLAN_SAVE'],
  'intro before PLAN_APPEND' => ["Ich ergaenze den Plan.\n[COREUI_PLAN_APPEND]\n## Blog\n- blog.html mit Artikeln\n"
    . "[/COREUI_PLAN_APPEND]", 'PLAN_APPEND'],
] as $case => [$cloudReply, $cloudType]) {
  $cloudCall = coreui_native_tool_parse($cloudReply);
  native_assert(($cloudCall['type'] ?? '') === $cloudType
    && str_replace("\r", '', (string)($cloudCall['body'] ?? '')) !== ''
    && ($cloudType !== 'PLAN_SAVE' && $cloudType !== 'PLAN_AND_BUILD'
      || str_replace("\r", '', (string)$cloudCall['body']) === $cloudPlan),
    'Cloud tool framing rejected: ' . $case);
}
$introCall = coreui_native_tool_parse("Sehr gern, ich speichere den Plan und baue die Seite.\n\n"
  . "[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan . "\n[/COREUI_PLAN_AND_BUILD]");
native_assert(($introCall['public_note'] ?? '') === 'Sehr gern, ich speichere den Plan und baue die Seite.',
  'A safe introduction becomes the visible progress note');
foreach ([
  'two actions (save, then build)' => "[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]\n"
    . "[COREUI_PROJECT_BUILD]\nPlan umsetzen\n[/COREUI_PROJECT_BUILD]",
  'fenced block' => "Hier:\n" . str_repeat(chr(96),3) . "\n[COREUI_PLAN_SAVE]\n" . $cloudPlan
    . "\n[/COREUI_PLAN_SAVE]\n" . str_repeat(chr(96),3),
  'example introduction' => "Beispiel:\n[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]",
  'markdown link after block' => "[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]\nMehr: [Doku](https://x.y)",
  'tiny plan after intro' => "Text\n[COREUI_PLAN_AND_BUILD]\n# Evil\n[/COREUI_PLAN_AND_BUILD]",
] as $case => $unsafeCloud) {
  native_assert(coreui_native_tool_parse($unsafeCloud) === null, 'Ambiguous cloud tool accepted: ' . $case);
  native_assert(coreui_native_tool_attempt($unsafeCloud), 'Ambiguous tool reply not detected: ' . $case);
}
native_assert(coreui_native_tool_reply_shape("[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]\n"
    . "[COREUI_PROJECT_BUILD]\nx\n[/COREUI_PROJECT_BUILD]") === 'multiple_tool_blocks'
  && coreui_native_tool_reply_shape(str_repeat(chr(96),3) . "\n[COREUI_PLAN_SAVE]\n# x\n[/COREUI_PLAN_SAVE]\n"
    . str_repeat(chr(96),3)) === 'tool_marker_in_code_fence'
  && coreui_native_tool_reply_shape("[COREUI_PROJECT_BUILD]\nx\n[/COREUI_PLAN_SAVE]") === 'tool_markers_mismatched'
  && coreui_native_tool_reply_shape("[COREUI_PROJECT_BUILD]\nPlan umsetzen") === 'tool_block_unclosed'
  && coreui_native_tool_reply_shape("[COREUI_DEPLOY]\nx\n[/COREUI_DEPLOY]") === 'tool_name_unknown'
  && coreui_native_tool_reply_shape("Text\n[COREUI_PLAN_AND_BUILD]\n# Evil\n[/COREUI_PLAN_AND_BUILD]") === 'tool_body_too_short'
  && coreui_native_tool_reply_shape("[COREUI_PLAN_SAVE]\n" . $cloudPlan . "\n[/COREUI_PLAN_SAVE]\nMehr: [Doku](https://x.y)")
    === 'tool_text_around_block',
  'Unreadable tool replies get a bounded, text-free reason');
$repair = coreui_native_tool_repair_prompt('multiple_tool_blocks', 'VORHER-ANTWORT');
$repairSmall = coreui_native_tool_repair_prompt('multiple_tool_blocks', 'VORHER-ANTWORT', true);
native_assert(str_contains($repair, '[COREUI_PLAN_AND_BUILD]') && str_contains($repair, 'VORHER-ANTWORT')
  && str_contains($repair, 'genau EINEM') && !str_contains($repairSmall, 'VORHER-ANTWORT'),
  'The correction names the reason and omits the old reply for a small context window');
// Existing project regression (19 Nov 2026): a usable PROJECT_BUILD block
// followed by a Markdown link is not silently executed, but its ONE safe
// correction must retain the intended build tool instead of PLAN_SAVE.
$framedBuild = "[COREUI_PROJECT_BUILD]\nErgaenze vorhandene Homepage-Dateien und pruefe die Ausgabe.\n[/COREUI_PROJECT_BUILD]"
  . "\nErgebnis: [Projektdateien](https://example.invalid)";
native_assert(coreui_native_tool_parse($framedBuild) === null
  && coreui_native_tool_reply_shape($framedBuild) === 'tool_text_around_block',
  'Markdown around tool is rejected before any project write');
$buildCorrection = coreui_native_tool_repair_prompt('tool_text_around_block',$framedBuild);
native_assert(str_contains($buildCorrection,
    "[COREUI_PROJECT_BUILD]\nGespeicherten, vom Benutzer beauftragten Projektstand")
  && !str_contains($buildCorrection,"[COREUI_PLAN_SAVE]\n# Titel")
  && str_contains($buildCorrection,'Kein neuer Plan'),
  'A malformed existing-project build must be corrected as PROJECT_BUILD');
native_assert(coreui_native_tool_repair_example($framedBuild) !== null
  && coreui_native_tool_parse(coreui_native_tool_repair_example($framedBuild))['type']==='PROJECT_BUILD',
  'The targeted repair uses real newlines and a parsable block');
$badMulti = "[COREUI_PLAN_SAVE]\n# P\n[/COREUI_PLAN_SAVE]\n"
  . "[COREUI_PROJECT_BUILD]\nUmsetzen\n[/COREUI_PROJECT_BUILD]";
native_assert(coreui_native_tool_repair_example($badMulti) === null
  && coreui_native_tool_parse($badMulti) === null,
  'Mixed plan/build attempts cannot be promoted to an executable tool');
$fencedBuild = "```text\n[COREUI_PROJECT_BUILD]\nUmsetzen\n[/COREUI_PROJECT_BUILD]\n```";
native_assert(coreui_native_tool_repair_example($fencedBuild)===null
  && coreui_native_tool_parse($fencedBuild)===null,
  'A quoted or fenced example is not a model-selected action');
$appendWrapped="[COREUI_PLAN_APPEND]\n## Weitere Funktionen\n- Datei ergänzen.\n"
  . "[/COREUI_PLAN_APPEND]\nWeitere Details: [Vorschau](https://example.invalid)";
$appendCorrection=coreui_native_tool_repair_prompt('tool_text_around_block',$appendWrapped);
native_assert(str_contains($appendCorrection,"[COREUI_PLAN_APPEND]\n## Naechste Schritte")
  && !str_contains($appendCorrection,"[COREUI_PLAN_SAVE]\n# Titel"),
  'An already versioned plan must be appended rather than restarted');
$streamRepair = (string)file_get_contents(dirname(__DIR__).'/api/console_stream.php');
$syncRepair = (string)file_get_contents(dirname(__DIR__).'/api/chat.php');
native_assert(str_contains($streamRepair,'false,null,false,false);')
  && !str_contains($streamRepair,'false,180);')
  && str_contains($syncRepair,"ember_timeout_for_model(\$primaryModel),['__skills'=>'none'],null,false,false);"),
  'Tool repair must use configured CPU timeout without redundant skills/thinking');

// A rejected continuation must never be repaired as a brand-new plan.
$malformedContinue = "[COREUI_PLAN_CONTINUE]\nIch moechte den gespeicherten Plan erweitern.";
$continueFix = coreui_native_tool_repair_prompt('invalid_plan_continue_marker', $malformedContinue);
native_assert(str_contains($continueFix, "[COREUI_PLAN_CONTINUE]\nGespeicherten Entwurf fortsetzen.")
  && !str_contains($continueFix, "[COREUI_PLAN_SAVE]\n# Titel"),
  'a malformed PLAN_CONTINUE must target the existing private checkpoint');
native_assert(coreui_native_tool_reply_shape(
  "[COREUI_PLAN_CONTINUE]\nWeiter schreiben.\n[/COREUI_PLAN_CONTINUE]") !== 'tool_body_too_short',
  'short continuation instructions are not rejected as short Markdown plans');
native_assert(!coreui_native_tool_attempt('Ganz normale Antwort ohne Werkzeug.')
  && coreui_native_tool_attempt("Ich speichere.\n**[COREUI_PLAN_SAVE]**\n# x"),
  'Tool attempts are detected without flagging ordinary prose');

// Screenshot regression: Gemma writes [COREUI/PLANSAVE] after normal prose
// and often omits the closing marker. Never mistake that for a finished plan.
$planBody = "# Projekt: Anime-Welt Tagebuch\n\n## Ziel\nSechs miteinander "
  . "verknuepfte Seiten, klare Navigation und konsistentes Design.\n\n"
  . "## Unterseiten\n- index.html\n- about.html\n- gallery.html\n- blog.html\n"
  . "- contact.html\n- links.html\n\n## Abnahme\nAlle Links funktionieren.";
$malformed = "Ich werde den Projektplan nun erstellen und speichern.\n\n"
  . "[COREUI/PLANSAVE]\n" . $planBody;
$recovered = coreui_native_tool_parse($malformed);
native_assert(is_array($recovered) && $recovered['type']==='PLAN_SAVE'
  && !empty($recovered['draft']) && $recovered['body']===$planBody,
  'Malformed model PLAN_SAVE from the screenshot must be preserved as draft');
$closed = coreui_native_tool_parse("Ich speichere ihn jetzt.\n\n"
  . "[COREUI/PLANSAVE]\n" . $planBody . "\n[/COREUI/PLANSAVE]");
native_assert(is_array($closed) && empty($closed['draft']) && $closed['body']===$planBody,
  'Completed slash-spelled PLAN_SAVE must be normalized without a draft flag');
foreach ([
  "Beispiel:\n" . str_repeat('A', 1000) . "\n[COREUI/PLANSAVE]\n".$planBody,
  "[COREUI/PLANSAVE]\n# Kurz\nSatz.",
  "[COREUI/PLANSAVE]\n".$planBody."\n[COREUI_PROJECT_BUILD]\nx\n[/COREUI_PROJECT_BUILD]",
  "Text mit eingebettetem Code-Block:\n".str_repeat(chr(96),3)."\n"
    ."[COREUI/PLANSAVE]\n".$planBody,
] as $unsafePlan) {
  native_assert(coreui_native_tool_parse($unsafePlan)===null,
    'Quoted, incomplete or mixed model tool output must never be saved');
}


// 0.5.66: interrupted PLAN_AND_BUILD must not disappear or execute.
$unfinishedBuild = "[COREUI_PLAN_AND_BUILD]\n" . $planBody;
$draftBuild = coreui_native_tool_parse($unfinishedBuild);
native_assert(is_array($draftBuild)
  && ($draftBuild['type'] ?? '') === 'PLAN_SAVE'
  && !empty($draftBuild['draft']) && !empty($draftBuild['recovered'])
  && ($draftBuild['body'] ?? '') === $planBody,
  'unfinished cloud plan-and-build must recover as an inert Markdown draft');
native_assert(coreui_native_tool_parse($unfinishedBuild
  . "\n[COREUI_PROJECT_BUILD]\nindex.html") === null,
  'mixed model build fragments must never be recovered or executed');
native_assert(coreui_native_tool_parse("[COREUI_PLAN_AND_BUILD]\n# Kurz")
  === null, 'a heading without a real plan is not a project draft');

// 0.5.66: incomplete PLAN_APPEND must not overwrite the approved plan as
// final or execute a build; it is an authenticated, versioned Markdown draft.
$appendBody = "## Weitere Unterseiten\n"
  . "- Die Galerie bekommt eine eigene, bedienbare Unterseite.\n"
  . "- Blog und Kontakt bleiben verlinkt; vorhandene Seiten werden erhalten.\n"
  . "### Abnahme\n- Navigation und responsive Layouts pruefen.";
$appendParsed = coreui_native_tool_parse("[COREUI_PLAN_APPEND]\n" . $appendBody);
native_assert(is_array($appendParsed)
  && ($appendParsed['type'] ?? '') === 'PLAN_APPEND'
  && !empty($appendParsed['draft']) && !empty($appendParsed['recovered'])
  && ($appendParsed['body'] ?? '') === $appendBody,
  'unfinished plan extension must be preserved only as a draft');
native_assert(coreui_native_plan_marker_attempt("[COREUI_PLAN_APPEND]\n" . $appendBody),
  'plan append must bypass generic prose continuation');
native_assert(coreui_native_tool_parse("[COREUI_PLAN_APPEND]\n## Weiter\n"
    . str_repeat("abc",40) . "\n[COREUI_PROJECT_BUILD]") === null,
  'mixed plan-append/build markers are unsafe');
native_assert(coreui_native_tool_parse("[COREUI_PLAN_APPEND]\nNur Text") === null,
  'plain prose is not an approved Markdown plan extension');

$appendDraft = coreui_native_tool_parse("[COREUI_PLAN_APPEND]\n"
  . "## Neue Unterseiten\n" . str_repeat("- Navigationslink und Inhaltslayout.\n",8));
native_assert(is_array($appendDraft) && ($appendDraft['type'] ?? '') === 'PLAN_APPEND'
  && !empty($appendDraft['draft']),
  'cut-off cloud plan additions must be persisted as an incomplete draft');
native_assert(coreui_native_tool_parse("[COREUI_PLAN_APPEND]\\n"
  . "## Links\\n" . str_repeat("- Test.\\n",28)
  . "[COREUI_PROJECT_BUILD]") === null,
  'mixed tool markers cannot authorize an appended plan');

// Screenshot regression: models frequently omit the closing delimiter for
// PLAN_CONTINUE or add an introductory sentence before the selected tool.
// This operation reads an existing owner-bound plan; no model text executes.
foreach ([
  "[COREUI_PLAN_CONTINUE]",
  "[COREUI_PLAN_CONTINUE]\nBitte den gespeicherten Entwurf fortsetzen.",
  "Ich arbeite den vorhandenen Projektplan weiter aus.\n"
    . "[COREUI_PLAN_CONTINUE]\n## Weitere Unterseiten\n"
    . str_repeat("- Geforderte Navigation und Bedienbarkeit.\n",160),
  "[COREUI/PLANCONTINUE]\nProjektplan ergaenzen.",
  "[COREUI_PLAN_CONTINUE]\n## Neue Seite\n"
    . str_repeat(chr(96),3) . "html\n<main>Preview</main>\n"
    . str_repeat(chr(96),3),
  "[COREUI_PLAN_CONTINUE]\nProjektplan weiterfuehren.\n"
    . "[/COREUI_PLAN_CONTINUE]\nIch bearbeite die gespeicherte Markdown-Version.",
  "Ich setze die Planung fort.\n[COREUI/PLAN_CONTINUE]\n"
    . "Bestehenden Entwurf vervollstaendigen.\n[/COREUI/PLAN_CONTINUE]",
] as $resumeAttempt) {
  $parsedResume = coreui_native_tool_parse($resumeAttempt);
  native_assert(is_array($parsedResume)
      && ($parsedResume['type'] ?? '') === 'PLAN_CONTINUE'
      && !empty($parsedResume['recovered']),
    'Model-selected PLAN_CONTINUE must recover an open or prefaced marker');
}
foreach ([
  "Beispiel:\n[COREUI_PLAN_CONTINUE]\nEin Muster.",
  "Gespeicherter Marker:\n" . str_repeat(chr(96),3) . "\n"
    . "[COREUI_PLAN_CONTINUE]\n" . str_repeat(chr(96),3),
  "[COREUI_PLAN_CONTINUE]\n[COREUI_PROJECT_BUILD]\nUnzulaessig",
  "[COREUI_PLAN_CONTINUE]\n[COREUI_PLAN_CONTINUE]\nMehrere Aufrufe",
  "[COREUI_PLAN_CONTINUE]\nWeiter.\n[/COREUI_PLAN_CONTINUE]"
    . "\n[/COREUI_PLAN_CONTINUE]",
  "[COREUI_PLAN_CONTINUE]\n"
    . "[COREUI_PLAN_APPEND]\n## Fremder Inhalt\n[/COREUI_PLAN_APPEND]\n"
    . "[/COREUI_PLAN_CONTINUE]",
] as $unsafeContinue) {
  native_assert(coreui_native_tool_parse($unsafeContinue) === null,
    'Mixed/quoted/ambiguous PLAN_CONTINUE must never trigger a tool');
}
native_assert(coreui_native_plan_continue_marker_attempt(
  "Vorwort\n[COREUI_PLAN_CONTINUE]\nUngeschlossen"),
  'Partially generated model resume marker must be visible to routing');

$toolPrompt = coreui_native_tools_prompt();
$buildExample = [];
native_assert(preg_match('~(\[COREUI_PROJECT_BUILD\]\n[^\r\n]+\n\[/COREUI_PROJECT_BUILD\])~',
  $toolPrompt, $buildExample) === 1
  && (coreui_native_tool_parse($buildExample[1])['type'] ?? null) === 'PROJECT_BUILD',
  'Runtime PROJECT_BUILD example must contain real newlines and parse');
native_assert(str_contains(coreui_native_tools_prompt(), '[COREUI_PLAN_SAVE]')
  && str_contains(coreui_native_tools_prompt(), '[COREUI_PROJECT_BUILD]')
  && str_contains(coreui_native_tools_prompt(), 'Du DARFST selbst entscheiden'),
  'Native tools must be model-driven from the runtime prompt');

// Ensure SQL owner isolation and server-owned downloads in all channels.
$root = dirname(__DIR__);
$store = (string)file_get_contents($root.'/api/console_plan_store.php');
foreach (['user_id=? AND session_id=?', 'source_turn_id=?', 'response_message_id=?',
  'hash(\'sha256\',$markdown)', 'function coreui_plan_artifact_map('] as $needle) {
  native_assert(str_contains($store,$needle),'Plan persistence guard missing: '.$needle);
}
$toolSource = (string)file_get_contents($root.'/api/console_native_tools.php');
native_assert(str_contains($toolSource, 'react|next[.]?js|vue|angular'),
  'Unsupported framework build must be rejected without fake artifact success');
$download = (string)file_get_contents($root.'/api/console_plan.php');
native_assert(str_contains($download,'stu_require_user_id()')
  && str_contains($download,'hash_equals(')
  && str_contains($download,'Content-Type: application/octet-stream'),
  'Plan download must enforce authentication and integrity');
foreach (['config/nginx.conf.template','config/runtime/nginx.conf.template'] as $path) {
  native_assert(str_contains((string)file_get_contents($root.'/'.$path),'|console_plan|'),
    'Nginx must expose private plan downloads: '.$path);
}
$script = (string)file_get_contents($root.'/js/coreui-artifacts.js');
native_assert(str_contains($script,"file.kind === 'plan' ? 'console_plan.php'"),
  'Plan download card not integrated');
$chat = (string)file_get_contents($root.'/api/chat.php');
$stream = (string)file_get_contents($root.'/api/console_stream.php');
native_assert(str_contains($chat,'coreui_native_tool_apply(')
  && str_contains($stream,'coreui_native_tool_apply(')
  && str_contains($chat,'coreui_plan_bind_response(')
  && !str_contains($chat, 'coreui_console_web_project_resolve(')
  && !str_contains($stream, 'coreui_console_web_project_resolve('),
  'Old keyword router must be replaced by real model-chosen tool dispatch');

if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  class NativePlanTestPDO extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
      return parent::prepare(str_replace('NOW()', 'CURRENT_TIMESTAMP', $query), $options);
    }
  }
  $pdo = new NativePlanTestPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec("CREATE TABLE stu_chat_messages (
    id INTEGER PRIMARY KEY,user_id INTEGER,session_id TEXT,channel TEXT,deleted_at TEXT,
    reply_to_id INTEGER,message TEXT)");
  $pdo->exec("CREATE TABLE stu_console_project_plans (
    id TEXT PRIMARY KEY,user_id INTEGER,session_id TEXT,source_turn_id INTEGER,
    response_message_id INTEGER,version INTEGER,title TEXT,markdown TEXT,byte_size INTEGER,
    sha256 TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
  $sid='cs_1234567890abcdef';
  $pdo->exec("INSERT INTO stu_chat_messages (id,user_id,session_id,channel) VALUES (10,1,'".$sid."','console'),(11,1,'".$sid."','console'),(12,2,'cs_other_12345678','console')");
  // These are authenticated operator messages, not fabricated plan text.
  // Explicit homepage creation on turn 11 must survive following plan versions.
  $pdo->exec("UPDATE stu_chat_messages SET message='Nur einen Projektplan erstellen'
    WHERE id=10");
  $pdo->exec("UPDATE stu_chat_messages SET message='kannst du eine homepage erstellen die modern und professionell aussieht'
    WHERE id=11");
  $v1=coreui_plan_save($pdo,1,$sid,10,$markdown);
  native_assert($v1['version']===1 && $v1['markdown']===$markdown,'First version saved unchanged');
  $v2=coreui_plan_save($pdo,1,$sid,11,"## Nachtrag\nNeue Funktion",true);
  native_assert($v2['version']===2 && str_contains($v2['markdown'],$markdown)
    && str_contains($v2['markdown'],'## Nachtrag'),'Append creates new complete revision');
  native_assert(coreui_plan_latest($pdo,1,$sid)['id']===$v2['id'],'Latest version lost');
  native_assert(coreui_plan_latest($pdo,2,$sid)===null,'Other account read private plan');
  $before=$pdo->query("SELECT COUNT(*) FROM stu_console_project_plans")->fetchColumn();
  try {
    coreui_plan_save($pdo,2,$sid,11,'# Evil');
    native_assert(false,'Cross-account trigger permitted');
  } catch (RuntimeException $expected) {}
  native_assert((int)$before===(int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_plans")->fetchColumn(),
    'Cross-account write modified plan data');
  $pdo->exec("INSERT INTO stu_chat_messages (id,user_id,session_id,channel,reply_to_id)
    VALUES (20,1,'".$sid."','console',10),(21,1,'".$sid."','console',11)");
  coreui_plan_bind_response($pdo,1,$sid,10,20);
  coreui_plan_bind_response($pdo,1,$sid,11,21);
  $map=coreui_plan_artifact_map($pdo,1,[20,21]);
  native_assert(count($map[20] ?? [])===1 && count($map[21] ?? [])===1
    && $map[21][0]['name']==='projektplan-v2.md',
    'Plan cards must bind to real assistant response and retain older versions');
  native_assert(coreui_plan_artifact_map($pdo,2,[20,21])===[], 'Other account received download cards');
  $hint=coreui_plan_prompt_block($pdo,1,$sid);
  native_assert(str_contains($hint,'v2') && str_contains($hint,'Nachtrag'),
    'Current persisted plan not visible to Mara');
  $draftResult = coreui_native_tool_apply($pdo,1,$sid,11,$malformed,
    static fn(string $task): string => 'BUILD MUST NOT RUN');
  native_assert(is_string($draftResult)
    && str_contains($draftResult,'UNVOLLSTAENDIGEN Markdown-Entwurf'),
    'Recoverable screenshot plan must be saved as explicitly incomplete');
  $draftRow = coreui_plan_latest($pdo,1,$sid);
  native_assert(is_array($draftRow) && coreui_plan_is_draft_markdown($draftRow['markdown'])
    && str_contains($draftRow['markdown'],$planBody),
    'Entire model-produced plan content must survive in the private draft');
  native_assert(coreui_native_tool_build_request($pdo,1,$sid,'Homepage') === null,
    'Unfinished plan draft must never be passed to the project builder');
  native_assert(str_contains(coreui_plan_prompt_block($pdo,1,$sid),'UNVOLLSTAENDIGER ENTWURF'),
    'Next model turn must know the saved draft is not approved');
  native_assert($draftRow['title']==='Projekt: Anime-Welt Tagebuch',
    'Recovered draft must retain its meaningful Markdown title');
  $properPlan = "[COREUI_PLAN_SAVE]\n" . $planBody . "\n[/COREUI_PLAN_SAVE]";
  $truncatedReply = coreui_native_tool_apply($pdo,1,$sid,11,$properPlan,
    static fn(string $task): string => 'BUILD MUST NOT RUN',true);
  $newDraft = coreui_plan_latest($pdo,1,$sid);
  native_assert(is_string($truncatedReply)
    && coreui_plan_is_draft_markdown((string)$newDraft['markdown']),
    'A length-stopped model plan must still be saved only as an incomplete draft');
  $noBuild = coreui_native_tool_apply($pdo,1,$sid,11,
    "[COREUI_PROJECT_BUILD]\nBuild webpage\n[/COREUI_PROJECT_BUILD]",
    static fn(string $task): string => 'BUILD MUST NOT RUN',true);
  native_assert(is_string($noBuild) && str_contains($noBuild,'vorzeitig abgebrochen'),
    'Truncated PROJECT_BUILD calls must never execute');

  // A real build request must not stop at its Markdown plan. The existing
  // model-controlled intent selector authorizes the continuation for the
  // very same owner, chat, message and freshly persisted plan version.
  $autoBuildCalls = 0;
  $decisionCalls = 0;
  $completed = coreui_native_tool_apply($pdo,1,$sid,11,$properPlan,
    static function(string $task) use (&$autoBuildCalls): string {
      $autoBuildCalls++;
      native_assert(str_contains($task,'[COREUI_PLANUNGSKONTEXT]')
        && str_contains($task,'index.html'),
        'Plan build lost the owner-approved source list');
      return 'VERIFIED PROJECT CREATED';
    },false,
    static function(string $prompt) use (&$decisionCalls): array {
      $decisionCalls++;
      native_assert(str_contains($prompt,'START_BUILD')
        && str_contains($prompt,'Erstelle die Homepage'),
        'Build-intent selector was not asked about the original user request');
      return ['text'=>'START_BUILD','ok'=>true];
    },'Erstelle die Homepage jetzt vollstaendig mit allen Dateien');
  native_assert($completed==='VERIFIED PROJECT CREATED'
    && $autoBuildCalls===1 && $decisionCalls===1,
    'Explicit build request must flow directly from saved plan into the writer');

  // Full original request: a polite homepage-build question must run from
  // draft -> in-turn format correction -> verified plan -> actual file writer.
  // The operator must NOT need to write Continue after the Markdown plan.
  $homepageBuildCalls=0;
  $homepageDecisionCalls=0;
  $homepageModelCalls=0;
  $finalSection="## Endkontrolle\n- Navigation, mobile Breiten und lokale Links pruefen.\n"
    . "- Seiten vollstaendig ausliefern und fehlende Platzhalter vermeiden.\n"
    . "[COREUI_PLAN_COMPLETE]";
  $homepageBuilt=coreui_native_tool_apply($pdo,1,$sid,11,$properPlan,
    static function (string $task) use (&$homepageBuildCalls): string {
      $homepageBuildCalls++;
      native_assert(str_contains($task,'index.html')
        && str_contains($task,'[COREUI_PLANUNGSKONTEXT]'),
        'The complete approved plan must reach the actual project writer');
      return 'VERIFIED HOMEPAGE CREATED';
    },true,
    static function (string $prompt) use (&$homepageModelCalls,&$homepageDecisionCalls,$finalSection): array {
      if (str_contains($prompt,'WERKZEUG-ENTSCHEIDER')) {
        $homepageDecisionCalls++;
        native_assert(str_contains($prompt,'kannst du eine homepage erstellen'),
          'Build selector must see the original polite user request');
        return ['text'=>'START_BUILD','ok'=>true];
      }
      $homepageModelCalls++;
      return ['text'=>$homepageModelCalls===1
          ? 'Ich setze die Planung fort.' : $finalSection,'ok'=>true];
    },'kannst du eine homepage erstellen die modern und professionell aussieht');
  native_assert($homepageBuilt==='VERIFIED HOMEPAGE CREATED'
    && $homepageBuildCalls===1 && $homepageDecisionCalls===1
    && $homepageModelCalls===2,
    'A user homepage request must go from a recoverable draft to the actual build without Continue');

  // Regression observed by the operator: a fully saved plan followed by
  // "Continue" must not report "already completed" and wait for another turn.
  // It must use the original account-owned homepage request exactly once.
  $finishedVersion=(int)coreui_plan_latest($pdo,1,$sid)['version'];
  $continueBuilds=0;
  $continueDecisions=0;
  $resumeComplete=coreui_native_tool_apply($pdo,1,$sid,11,
    "[COREUI_PLAN_CONTINUE]\nBitte den gespeicherten Auftrag fortsetzen.\n[/COREUI_PLAN_CONTINUE]",
    static function(string $task) use (&$continueBuilds): string {
      $continueBuilds++;
      native_assert(str_contains($task,'[COREUI_PLANUNGSKONTEXT]')
        && str_contains($task,'index.html'),
        'The saved complete plan must reach the validated file worker');
      return 'VERIFIED HOMEPAGE CONTINUED';
    },false,static function(string $prompt) use (&$continueDecisions): array {
      $continueDecisions++;
      native_assert(str_contains($prompt,'URSPRUENGLICHER AUFTRAG')
        && str_contains($prompt,'kannst du eine homepage erstellen')
        && str_contains($prompt,'Continue'),
        'The real initial request must survive a later Continue');
      return ['text'=>'START_BUILD','ok'=>true];
    },'Continue');
  native_assert($resumeComplete==='VERIFIED HOMEPAGE CONTINUED'
    && $continueBuilds===1 && $continueDecisions===1
    && (int)coreui_plan_latest($pdo,1,$sid)['version']===$finishedVersion,
    'Completed PLAN_CONTINUE must invoke one approved build without a duplicate plan');

  // A plan-only request remains inert even if a valid plan is saved.
  $planOnlyBuildCalls = 0;
  $planSaved = coreui_native_tool_apply($pdo,1,$sid,11,$properPlan,
    static function(string $task) use (&$planOnlyBuildCalls): string {
      $planOnlyBuildCalls++;
      return 'MUST NOT BUILD';
    },false,
    static fn(string $prompt): array => ['text'=>'CHAT','ok'=>true],
    'Schreibe nur einen Projektplan, keinen Code');
  native_assert(is_string($planSaved)
    && str_contains($planSaved,'abgeschlossene Markdown-Version')
    && $planOnlyBuildCalls===0,
    'A plan-only task must never be silently escalated into a project build');

  // An invalid or incomplete classifier response fails closed without losing
  // the completed plan and without running the project writer.
  $invalidBuildCalls=0;
  $invalidDecision = coreui_native_tool_apply($pdo,1,$sid,11,$properPlan,
    static function(string $task) use (&$invalidBuildCalls): string {
      $invalidBuildCalls++;
      return 'MUST NOT BUILD';
    },false,
    static fn(string $prompt): array => ['text'=>'Maybe later','ok'=>true],
    'Erstelle die Homepage jetzt vollstaendig');
  native_assert(is_string($invalidDecision)
    && str_contains($invalidDecision,'abgeschlossene Markdown-Version')
    && $invalidBuildCalls===0,
    'Unknown model intent must not authorize a build');
  echo "Plan persistence and authorized plan-to-build (SQLite fixture): OK\n";
} else {
  echo "Plan persistence SQLite fixture: SKIP (pdo_sqlite unavailable)\n";
}
// 0.8.30: a Core that writes the same single tool block again in its correction has chosen that tool
// twice; the text around it is dropped. The first reply with text around a block still never runs.
$aroundFirst = "Sehr gern! Hier ist mein Plan fuer deine Homepage:\n\n[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan
  . "\n[/COREUI_PLAN_AND_BUILD]\n\nMehr Infos: [CoreAI](https://example.test/coreai) und `index.html`.";
$aroundFix = "Gut, ich setze das so um. Die Seiten sind [index], [blog] und [gaestebuch].\n\n**[COREUI_PLAN_AND_BUILD]**\n"
  . $cloudPlan . "\n**[/COREUI_PLAN_AND_BUILD]**\n\nDanach kannst du die Vorschau oeffnen: [Vorschau](https://example.test/p).";
$confirmed = coreui_native_confirmed_block($aroundFix, $aroundFirst);
native_assert(coreui_native_tool_parse($aroundFirst) === null && coreui_native_tool_parse($aroundFix) === null
  && coreui_native_tool_reply_shape($aroundFix) === 'tool_text_around_block'
  && $confirmed === "[COREUI_PLAN_AND_BUILD]\n" . trim($cloudPlan) . "\n[/COREUI_PLAN_AND_BUILD]"
  && (coreui_native_tool_parse((string)$confirmed)['type'] ?? '') === 'PLAN_AND_BUILD'
  && !str_contains((string)$confirmed, 'Vorschau') && !str_contains((string)$confirmed, 'Sehr gern'),
  'the same tool block in the correction runs without the text around it');
foreach ([
  'another tool than the first reply' => [str_replace('PLAN_AND_BUILD', 'PLAN_SAVE', $aroundFix), $aroundFirst],
  'two blocks' => [$aroundFix . "\n[COREUI_PROJECT_BUILD]\nUmsetzen\n[/COREUI_PROJECT_BUILD]", $aroundFirst],
  'fenced block' => ["Hier:\n" . str_repeat(chr(96), 3) . "\n[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan
    . "\n[/COREUI_PLAN_AND_BUILD]\n" . str_repeat(chr(96), 3), $aroundFirst],
  'example introduction' => ["Beispiel:\n[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan . "\n[/COREUI_PLAN_AND_BUILD]\nMehr: [x](y)", $aroundFirst],
  'tiny plan' => ["Text [x]\n[COREUI_PLAN_AND_BUILD]\n# Evil\n[/COREUI_PLAN_AND_BUILD]", $aroundFirst],
  'unclosed block' => ["Text [x]\n[COREUI_PLAN_AND_BUILD]\n" . $cloudPlan, $aroundFirst],
  'first reply without a tool' => [$aroundFix, 'Ich erklaere dir gern, wie eine Homepage aufgebaut ist.'],
] as $case => [$fix, $first]) {
  native_assert(coreui_native_confirmed_block($fix, $first) === null, 'not confirmed: ' . $case);
}
$streamWire = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
$syncWire = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
native_assert(str_contains($streamWire, "\$fixTool = !\$fixComplete ? null : (coreui_native_tool_parse(\$fixText) !== null ? \$fixText\n        : coreui_native_confirmed_block(\$fixText, \$reply));")
  && str_contains($streamWire, '$corrected = $applyNativeTool($fixTool, false);')
  && str_contains($syncWire, "\$fixTool = !\$fixComplete ? null : (coreui_native_tool_parse(\$fixText) !== null ? \$fixText\n        : coreui_native_confirmed_block(\$fixText, \$out));")
  && str_contains($syncWire, '$corrected = $applyNative($fixTool, false);'),
  'both correction paths run a block confirmed by the correction');

echo "Native tools: model call parsing, durable Markdown versions, guarded downloads, chat routing OK\n";
