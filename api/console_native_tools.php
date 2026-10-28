<?php
declare(strict_types=1);
require_once __DIR__ . '/console_plan_store.php';
require_once __DIR__ . '/console_plan_autorun.php';
require_once __DIR__ . '/console_build_intent.php';
require_once __DIR__ . '/console_data_link_bridge.php';

/**
 * Optional, explicitly user-visible model commentary. Only an in-band
 * message.content prefix immediately preceding one native tool is accepted.
 * Private Ollama thinking never enters this parser.
 */
/** An unsafe comment is silently discarded; it never authorizes anything. */
function coreui_native_public_note_safe(string $candidate): ?string {
  $candidate = trim($candidate);
  if (strlen($candidate) >= 12 && strlen($candidate) <= 300
      && preg_match('//u', $candidate) === 1
      && preg_match('~[\x00-\x1f]~u', $candidate) !== 1
      && strpbrk($candidate, '<>[]{}' . chr(96) . '$') === false
      && !preg_match('~(?:https?://|api[ _-]?key|bearer[ :]|password|passwort|'
        . 'system[ _-]?prompt|developer[ _-]?prompt|private[ _-]?reasoning|'
        . 'chain[ _-]?of[ _-]?thought|[A-Za-z0-9_-]{48,})~iu', $candidate)) {
    return $candidate;
  }
  return null;
}

function coreui_native_public_progress_strip(string $reply): array {
  $note = null;
  // Blank lines between the note and its tool block are harmless formatting.
  if (preg_match('~\A[ \t]*\[COREUI_PUBLIC_PROGRESS\]([^\r\n]{1,480})\[/COREUI_PUBLIC_PROGRESS\][ \t]*\r?\n(?:[ \t]*\r?\n)*(?=\[COREUI_)~u',
      $reply, $match) === 1) {
    $reply = substr($reply, strlen($match[0]));
    // The independent, already whitelisted native tool keeps its own checks.
    $note = coreui_native_public_note_safe((string)$match[1]);
  }
  return ['reply'=>$reply,'note'=>$note];
}

/**
 * A model may write the public progress line and then simply answer in
 * prose, without any tool. That is an ordinary reply, not a broken tool
 * call: return the prose and the (filtered) note. Any further COREUI marker
 * outside inline code keeps the strict tool validation.
 *
 * 0.5.76: without thinking Mara also wrote the block over several lines,
 * longer than a note, or left it open; that still is prose and must not
 * start a correction round. Only a lone short note stays strict: it
 * announces a tool step that is missing.
 */
function coreui_native_public_progress_only(string $reply): ?array {
  if (preg_match('~\A[ \t]*\[COREUI_PUBLIC_PROGRESS\]~u', $reply) !== 1) return null;
  $outsideCode = (string)preg_replace('~`[^`\r\n]*`~u', '', $reply);
  if (preg_match('~\[/?COREUI[_/](?!PUBLIC_PROGRESS\])[A-Z_]+\]~i', $outsideCode) === 1) return null;
  $note = null;
  if (preg_match('~\A[ \t]*\[COREUI_PUBLIC_PROGRESS\]([\s\S]*?)\[/COREUI_PUBLIC_PROGRESS\][ \t]*(?:\r?\n|\z)~u',
      $reply, $match) === 1) {
    $inner = trim($match[1]);
    $rest = trim(substr($reply, strlen($match[0])));
    $shortNote = $inner !== '' && !str_contains($inner, "\n") && strlen($inner) <= 480;
    if ($rest === '' && $shortNote) return null;
    if ($shortNote) $note = coreui_native_public_note_safe($inner);
    else $rest = trim($inner . "\n\n" . $rest);
  } else {
    $rest = trim((string)preg_replace('~\A[ \t]*\[COREUI_PUBLIC_PROGRESS\]~u', '', $reply));
  }
  $rest = trim((string)preg_replace('~\[/?COREUI_PUBLIC_PROGRESS\]~i', '', $rest));
  return $rest === '' ? null : ['reply'=>$rest, 'note'=>$note];
}

/**
 * Remove negated technology mentions ("kein React, kein PHP", "ohne Vue")
 * before the unsupported-stack check. A contrast ("sondern React", "mit Vue")
 * is kept, so a real framework project still fails closed.
 */
function coreui_native_strip_negated_stacks(string $text): string {
  return (string)preg_replace_callback(
    '~\b(?:kein(?:e|en|em|er|es)?|ohne|nicht|statt|anstatt|no|not|without)\b([^.,;:!?\r\n]{0,80})~iu',
    static function (array $m): string {
      if (preg_match('~\b(?:sondern|aber|jedoch|mit|but|with|plus)\b~iu', $m[1], $c, PREG_OFFSET_CAPTURE) === 1) {
        return ' ' . substr($m[1], (int)$c[0][1]);
      }
      return ' ';
    }, $text);
}


/**
 * Small, explicit native-tool vocabulary. The MODEL chooses a tool; the user
 * does not need to type predefined filenames, "ZIP" or trigger words.
 * Every call remains scoped to the authenticated, currently running turn.
 */
function coreui_native_tools_prompt(bool $compact = false): string {
  // Short runtime protocol only. Full Skill docs and the owner Mara persona
  // stay outside this block; no copy of the Modelfile is inserted.
  if ($compact) {
    // Same vocabulary for a small local context window. The parser accepts
    // every tool either way; only the explanation is shorter.
    return "\n[COREUI TOOLS] Du DARFST selbst entscheiden, ob ein Werkzeug notwendig ist. "
      . "Antworte normal ODER nur mit genau EINEM vollstaendigen Block:\n"
      . "[COREUI_PLAN_SAVE]\n# Titel\nMarkdown-Plan\n[/COREUI_PLAN_SAVE]\n"
      . "[COREUI_PLAN_CONTINUE]\nGespeicherten Entwurf fortsetzen\n[/COREUI_PLAN_CONTINUE]\n"
      . "[COREUI_PROJECT_BUILD]\nKurzer Projektauftrag\n[/COREUI_PROJECT_BUILD]\n"
      . "PROJECT_BUILD nur, wenn der Nutzer die Umsetzung verlangt oder freigibt. CoreAI erzeugt "
      . "dann jede Datei einzeln und prueft das ZIP. Keine erfundenen Dateien, Links oder Host-Befehle.\n";
  }
  return "\n[COREUI TOOLS] Du DARFST selbst entscheiden, ob ein Werkzeug notwendig ist. "
    . "Antworte normal ODER mit genau EINEM vollstaendigen Werkzeugblock. Nur eine optionale, oeffentliche Fortschrittszeile darf unmittelbar davor stehen:\n"
    . "[COREUI_PLAN_SAVE]\n# Titel\nVollstaendiger Markdown-Plan\n[/COREUI_PLAN_SAVE]\n"
    . "[COREUI_PLAN_APPEND]\nNeue Markdown-Abschnitte\n[/COREUI_PLAN_APPEND]\n"
    . "[COREUI_PLAN_CONTINUE]\nVorhandenen Markdown-Entwurf fortsetzen\n[/COREUI_PLAN_CONTINUE]\n"
    . "[COREUI_PLAN_AND_BUILD]\n# Titel\nVollstaendiger Markdown-Plan\n[/COREUI_PLAN_AND_BUILD]\n"
    . "[COREUI_PROJECT_BUILD]\nProjektauftrag mit benoetigten Quelldateien\n[/COREUI_PROJECT_BUILD]\n"
    . "SAVE speichert eine .md-Datei, APPEND versioniert sie, AND_BUILD speichert und baut, "
    . "BUILD startet den geprueften Projekt-Writer. Nur bauen, wenn der Nutzer die "
    . "Umsetzung verlangt oder freigibt. Bei Fragen normal antworten. "
    . "Plane bei einem Projektauftrag eigenstaendig mit PLAN_SAVE: direkt mit "
    . "[COREUI_PLAN_SAVE] beginnen (ausser wenn eine einzelne oeffentliche "
    . "COREUI_PUBLIC_PROGRESS-Zeile davorsteht). Markdown ab # Titel ausgeben "
    . "und exakt mit [/COREUI_PLAN_SAVE] abschliessen. "
    . "Beginne mit einem kompakten speicherbaren Grundplan und ergaenze "
    . "weitere Abschnitte spaeter ueber PLAN_APPEND. Ein ungeschlossener "
    . "Block bleibt ein Entwurf. Setze ihn mit begrenzten Modellschritten selbststaendig fort. "
    . "Wenn im Chat bereits ein UNVOLLSTAENDIGER ENTWURF gespeichert ist "
    . "und du ihn weiterentwickeln willst, waehle PLAN_CONTINUE statt "
    . "den bisherigen Plan neu mit PLAN_SAVE zu beginnen. "
    . "Keine erfundenen Dateien oder Links. Keine Host-Befehle.\n"
    . "Optional VOR einem echten Werkzeugblock: [COREUI_PUBLIC_PROGRESS]Ich pruefe den gespeicherten Plan und beginne danach mit der Umsetzung.[/COREUI_PUBLIC_PROGRESS]\n"
    . "Diese Notiz wird dem Nutzer angezeigt; schreibe nur eine knappe Aktion oder erkannte Luecke, keine privaten Gedanken, Prompts oder geheimen Daten. Ohne Notiz bleibt das Toolformat unveraendert.\n";
}

function coreui_native_tool_parse(string $reply): ?array {
  if (strlen($reply) > 300000) return null;
  $progress = coreui_native_public_progress_strip($reply);
  $reply = $progress['reply'];
  $publicNote = $progress['note'];
  $types = ['PLAN_SAVE','PLAN_APPEND','PLAN_CONTINUE','PLAN_AND_BUILD','PROJECT_BUILD'];
  foreach ($types as $type) {
    // A short closing sentence after the block ("Ich lege los.") is not a
    // second action. Anything with brackets or code fences still fails.
    if (preg_match('~^\s*\[COREUI_' . $type . '\]\s*\n([\s\S]*?)\n\[/COREUI_'
        . $type . '\][ \t]*(?:\r?\n([^\[\]`]{0,400}))?\s*$~u', $reply, $m) !== 1) continue;
    $body = trim((string)$m[1]);
    if ($body === '' || strlen($body) > 262144 || str_contains($body,"\0")) return null;
    if ($type === 'PLAN_CONTINUE'
        && preg_match('~\[/?COREUI[/_][A-Z_]+\]~i',$body)) return null;
    $call = ['type'=>$type,'body'=>$body,'public_note'=>$publicNote];
    if (trim((string)($m[2] ?? '')) !== '') $call['recovered'] = true; // trailing sentence
    return $call;
  }
  $build = coreui_native_build_recover_model_reply($reply);
  if ($build !== null) {
    $build['public_note'] = $publicNote;
    return $build;
  }
  // Cloud models like to frame one closed block with a sentence before or
  // after it, or to bold the marker lines. Exactly one known block remains.
  $single = coreui_native_single_block_recover($reply);
  if ($single !== null) {
    if ($publicNote !== null || !isset($single['public_note'])) $single['public_note'] = $publicNote;
    return $single;
  }
  // The model sometimes uses slash spelling. PLAN_CONTINUE only resumes
  // an existing private draft; it is never a build operation.
  if (preg_match('~^\s*\[COREUI/PLANCONTINUE\]\s*\n([\s\S]{1,4000}?)\n\[/COREUI/PLANCONTINUE\]\s*$~u',
      $reply, $m) === 1) {
    return ['type'=>'PLAN_CONTINUE','body'=>trim((string)$m[1]),'recovered'=>true,'public_note'=>$publicNote];
  }
  // A small local model may leave PLAN_CONTINUE open or put an ordinary
  // sentence before it. Recover only a single unmistakable model tool call.
  $continue = coreui_native_plan_continue_recover_model_reply($reply);
  if ($continue !== null) {
    $continue['public_note'] = $publicNote;
    return $continue;
  }
  $plan = coreui_native_plan_recover_model_reply($reply);
  if ($plan === null) $plan = coreui_native_plan_and_build_draft_recover($reply);
  if ($plan === null) $plan = coreui_native_plan_append_draft_recover($reply);
  if ($plan !== null) $plan['public_note'] = $publicNote;
  return $plan;
}


/**
 * A PLAN_CONTINUE request contains no executable payload. Unlike a project
 * build, it can be recovered even when Gemma omits its closing delimiter.
 * It only instructs CoreUI to continue a pre-existing owned Markdown draft.
 * It must not be inferred from the user's words or generic discussion.
 */
function coreui_native_plan_continue_recover_model_reply(string $reply): ?array {
  if (strlen($reply) > 300000 || preg_match('//u', $reply) !== 1
      || str_contains($reply, "\0")) return null;
  $open = '~(?mi)^[ \t]*\[(?:COREUI_PLAN_CONTINUE|COREUI_PLANCONTINUE|COREUI/PLANCONTINUE|COREUI/PLAN_CONTINUE)\][ \t]*\r?$~';
  $matches = [];
  if (preg_match_all($open, $reply, $matches, PREG_OFFSET_CAPTURE) !== 1)
    return null; // Never execute ambiguous multiple marker attempts.
  $opening = (string)$matches[0][0][0];
  $offset = (int)$matches[0][0][1];
  $intro = trim(substr($reply, 0, $offset));
  // Never trigger a tool if its marker was merely illustrated inside a
  // Markdown code fence, while allowing harmless code in the ignored body.
  if (strlen($intro) > 640 || str_contains($intro, str_repeat(chr(96), 3))
      || str_contains($intro, '[')
      || str_contains($intro, ']')
      || preg_match('~\b(?:Beispiel|Syntax|Codeblock|Zitat)\s*:\s*$~iu',$intro))
    return null;

  $body = trim(substr($reply, $offset + strlen($opening)));
  $closing = '~(?:^|\r?\n)[ \t]*\[/COREUI(?:_PLAN_CONTINUE|_PLANCONTINUE|/PLANCONTINUE|/PLAN_CONTINUE)\][ \t]*(?=\r?\n|$)~i';
  $end = [];
  $closingCount = preg_match_all($closing, $body, $end, PREG_OFFSET_CAPTURE);
  if ($closingCount === false || $closingCount > 1) return null;
  $closed = $closingCount === 1;
  if ($closed) {
    $endOffset = (int)$end[0][0][1];
    $endBytes = strlen((string)$end[0][0][0]);
    $trailing = trim(substr($body, $endOffset + $endBytes));
    // Do not lose a tool request just because the model adds a short,
    // non-tool sentence AFTER an otherwise valid closing delimiter.
    if (strlen($trailing) > 640 || str_contains($trailing, '[')
        || str_contains($trailing, ']')) return null;
    $body = trim(substr($body, 0, $endOffset));
  }
  // Gemma may have written an entire Markdown section after this marker.
  // This is NOT executed or appended blindly: recovery uses the last
  // authenticated checkpoint, then generates validated new sections.
  if (strlen($body) > 262144
      || preg_match('~\[(?:/?COREUI[/_])[A-Z_]+\]~i', $body)) return null;
  // No submitted prompt is used as a write/build instruction. This string is
  // descriptive only, the planner loads the owner's saved plan from the DB.
  return [
    'type'=>'PLAN_CONTINUE',
    'body'=>$body !== '' ? $body : 'Gespeicherten Projektplan fortsetzen.',
    'recovered'=>true,
    'incomplete'=>!$closed
  ];
}

/**
 * Models often announce the build in one sentence ("Alles klar, ich lege
 * los.") before an otherwise complete PROJECT_BUILD block. Accept exactly
 * one closed block after a short, bracket-free introduction. The build
 * itself still requires a finished private plan and all writer checks.
 */
function coreui_native_build_recover_model_reply(string $reply): ?array {
  if (strlen($reply) > 20000 || preg_match('//u', $reply) !== 1
      || str_contains($reply, "\0")) return null;
  if (preg_match_all('~\[/?COREUI[_/][A-Z_]+\]~i', $reply) !== 2) return null;
  if (preg_match('~\A(?<intro>[^\[\]`]{1,400}?)\r?\n[ \t]*\[COREUI_PROJECT_BUILD\][ \t]*\r?\n'
      . '(?<body>[\s\S]{1,12000}?)\r?\n[ \t]*\[/COREUI_PROJECT_BUILD\][ \t]*'
      . '(?:\r?\n[^\[\]`]{0,400})?\s*\z~u', $reply, $m) !== 1) return null;
  if (preg_match('~\b(?:Beispiel|Syntax|Format|Codeblock|Zitat)\s*:\s*\z~iu', trim($m['intro']))) return null;
  $body = trim($m['body']);
  return $body === '' ? null : ['type'=>'PROJECT_BUILD','body'=>$body,'recovered'=>true];
}

function coreui_native_plan_continue_marker_attempt(string $reply): bool {
  return preg_match(
    '~(?mi)^[ \t]*\[(?:COREUI_PLAN_CONTINUE|COREUI_PLANCONTINUE|COREUI/PLANCONTINUE|COREUI/PLAN_CONTINUE)\][ \t]*\r?$~',
    substr($reply, 0, 1800)
  ) === 1;
}

/**
 * Gemma may choose [COREUI/PLANSAVE] with a brief introduction. Only
 * that unambiguous model-selected plan action may be recovered.
 * Missing closure is an incomplete DRAFT, never an approved project.
 */
function coreui_native_plan_recover_model_reply(string $reply): ?array {
  if (strlen($reply) > 300000 || preg_match('//u', $reply) !== 1) return null;
  $matches = [];
  $pattern = '~(?mi)^[ \t]*\[(COREUI_PLAN_SAVE|COREUI/PLANSAVE)\][ \t]*\r?\n~';
  if (preg_match_all($pattern, $reply, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== 1)
    return null;
  $opening = $matches[0][0][0];
  $offset = (int)$matches[0][0][1];
  $intro = trim(substr($reply, 0, $offset));
  if (strlen($intro) > 900 || str_contains($intro, str_repeat(chr(96), 3))
      || preg_match('~\[/?COREUI[/_][A-Z_]+\]~i', $intro)
      || preg_match('~\b(?:Beispiel|Syntax|Format|Codeblock|Code-Block|Zitat|Vorlage|Example)\s*:\s*\z~iu', $intro))
    return null;
  $body = trim(substr($reply, $offset + strlen($opening)));
  $close = '~\r?\n[ \t]*\[/COREUI(?:_PLAN_SAVE|/PLANSAVE)\][ \t]*$~i';
  $closed = preg_match($close, $body) === 1;
  if ($closed) $body = trim((string)preg_replace($close, '', $body, 1));
  if (strlen($body) < 96 || strlen($body) > 262144 || str_contains($body, "\0")
      || preg_match('~\[/?COREUI[/_][A-Z_]+\]~i', $body)) return null;
  if (preg_match('~^(?:#{1,3}[ \t]+\S[^\r\n]*|Projekt[ \t]*:[ \t]*\S[^\r\n]*)~iu', $body) !== 1)
    return null;
  if (preg_match('~(?m)^(?:#{2,4}[ \t]+|[ \t]*[-*][ \t]+\S)~u', $body) !== 1)
    return null;
  return ['type'=>'PLAN_SAVE', 'body'=>$body, 'draft'=>!$closed, 'recovered'=>true];
}

/**
 * A cloud/local model can stop mid PLAN_AND_BUILD. This is not an approved
 * build, but a sufficiently complete Markdown prefix can be saved as an
 * incomplete plan draft and resumed. Never execute or authorize PROJECT_BUILD
 * from an unfinished block; reject mixed markers and fenced examples.
 */
function coreui_native_plan_and_build_draft_recover(string $reply): ?array {
  if (strlen($reply) > 300000 || preg_match('//u',$reply) !== 1
      || str_contains($reply,chr(0))) return null;
  $open = '~(?mi)^[ \\t]*\\[COREUI_PLAN_AND_BUILD\\][ \\t]*\\r?\\n~';
  $count = preg_match_all($open,$reply,$matches,PREG_OFFSET_CAPTURE);
  if ($count !== 1) return null;
  $opening = $matches[0][0][0];
  $offset = (int)$matches[0][0][1];
  $intro = trim(substr($reply,0,$offset));
  if (strlen($intro) > 640 || str_contains($intro,str_repeat(chr(96),3))
      || str_contains($intro,'[') || str_contains($intro,']')) return null;
  $body = trim(substr($reply,$offset+strlen($opening)));
  // A closed or ambiguous tool belongs to the strict parser, never here.
  if (preg_match('~\\[/?COREUI[/_][A-Z_]+\\]~i',$body)
      || strlen($body) < 96 || strlen($body) > 262144) return null;
  if (!preg_match('~^#{1,3}[ \\t]+\\S[^\\r\\n]*~u',$body)
      || !preg_match('~(?m)^(?:#{2,4}[ \\t]+|[ \\t]*[-*][ \\t]+\\S)~u',$body))
    return null;
  return ['type'=>'PLAN_SAVE','body'=>$body,'draft'=>true,
          'recovered'=>true,'source_marker'=>'unfinished_plan_and_build'];
}

/**
 * A cut-off PLAN_APPEND is private Markdown, never executable project code.
 * Preserve it only as an unfinished version based on the existing owner-
 * scoped plan, with no authorization to rebuild until the draft completes.
 */
function coreui_native_plan_append_draft_recover(string $reply): ?array {
  if (strlen($reply) > 300000 || preg_match('//u',$reply) !== 1
      || str_contains($reply,chr(0))) return null;
  $openingPattern = '~(?mi)^[ \\t]*\\[COREUI_PLAN_APPEND\\][ \\t]*\\r?\\n~';
  if (preg_match_all($openingPattern,$reply,$matches,PREG_OFFSET_CAPTURE) !== 1) return null;
  $opening = (string)$matches[0][0][0];
  $offset = (int)$matches[0][0][1];
  $intro = trim(substr($reply,0,$offset));
  if (strlen($intro) > 640 || str_contains($intro,str_repeat(chr(96),3))
      || str_contains($intro,'[') || str_contains($intro,']')) return null;
  $body = trim(substr($reply,$offset+strlen($opening)));
  if (strlen($body) < 96 || strlen($body) > 262144
      || preg_match('~\\[/?COREUI[/_][A-Z_]+\\]~i',$body)) return null;
  // A new heading is evidence of a Markdown addition, unlike arbitrary prose.
  if (preg_match('~^(?:#{2,4}[ \\t]+\\S|[-*][ \\t]+\\S)~u',$body) !== 1)
    return null;
  return ['type'=>'PLAN_APPEND','body'=>$body,'draft'=>true,
          'recovered'=>true,'source_marker'=>'unfinished_plan_append'];
}

/** "**[COREUI_PLAN_SAVE]**" on its own line is the same marker. */
function coreui_native_marker_lines_normalize(string $reply): string {
  $reply = str_replace("\r\n", "\n", $reply);
  return (string)preg_replace(
    '~(?m)^[ \t]*(?:\*\*|__|\*|_)\[(/?COREUI_[A-Z_]+)\](?:\*\*|__|\*|_)[ \t]*$~',
    '[$1]', $reply);
}

/** A plan saved through a framed block must still look like a real plan. */
function coreui_native_recovered_body_ok(string $type, string $body): bool {
  if ($body === '' || strlen($body) > 262144 || str_contains($body, "\0")) return false;
  if ($type === 'PROJECT_BUILD') return true;
  if ($type === 'PLAN_APPEND')
    return strlen($body) >= 24 && preg_match('~^(?:#{1,4}[ \t]+\S|[-*][ \t]+\S)~u', $body) === 1;
  return strlen($body) >= 96
    && preg_match('~^(?:#{1,3}[ \t]+\S|Projekt[ \t]*:[ \t]*\S)~iu', $body) === 1
    && preg_match('~(?m)^(?:#{2,4}[ \t]+|[ \t]*[-*][ \t]+\S)~u', $body) === 1;
}

/**
 * Exactly one CLOSED block of a known tool, with an optional short
 * introduction and an optional closing remark. Brackets or code fences
 * around it, a second marker, or an "Beispiel:" introduction still fail
 * closed: those are illustrations or several actions, not one tool call.
 */
function coreui_native_single_block_recover(string $reply): ?array {
  if (strlen($reply) > 300000 || preg_match('//u', $reply) !== 1
      || str_contains($reply, "\0")) return null;
  $reply = coreui_native_marker_lines_normalize($reply);
  if (preg_match_all('~\[/?COREUI[_/][A-Z_]+\]~i', $reply) !== 2) return null;
  if (preg_match('~\A(?<intro>[\s\S]*?)^[ \t]*\[COREUI_(?<type>PLAN_SAVE|PLAN_APPEND|PLAN_AND_BUILD|PROJECT_BUILD)\][ \t]*\n'
      . '(?<body>[\s\S]*?)\n[ \t]*\[/COREUI_\k<type>\][ \t]*$(?<tail>[\s\S]*)\z~mu', $reply, $m) !== 1)
    return null;
  $intro = trim($m['intro']);
  $tail = trim($m['tail']);
  if (strlen($intro) > 900 || strpbrk($intro, '[]' . chr(96)) !== false
      || preg_match('~\b(?:Beispiel|Syntax|Format|Codeblock|Code-Block|Zitat|Vorlage|Example)\s*:\s*\z~iu', $intro))
    return null;
  if (strlen($tail) > 1200 || strpbrk($tail, '[]') !== false
      || str_contains($tail, str_repeat(chr(96), 3))) return null;
  $type = $m['type'];
  $body = trim($m['body']);
  if (!coreui_native_recovered_body_ok($type, $body)) return null;
  $call = ['type'=>$type,'body'=>$body,'recovered'=>true];
  $note = $intro !== '' ? coreui_native_public_note_safe($intro) : null;
  if ($note !== null) $call['public_note'] = $note;
  return $call;
}

/**
 * Bounded, text-free description of WHY a tool reply was unreadable. It is
 * stored in the work history and steers the single correction request.
 */
function coreui_native_tool_reply_shape(string $reply): string {
  $text = coreui_native_marker_lines_normalize(coreui_native_public_progress_strip($reply)['reply']);
  preg_match_all('~\[(/?)COREUI[_/]([A-Z_]+)\]~i', $text, $found, PREG_SET_ORDER);
  $opens = []; $closes = [];
  foreach ($found as $marker) {
    $name = strtoupper(str_replace('/', '_', $marker[2]));
    if ($name === 'PUBLIC_PROGRESS') continue;
    if ($marker[1] === '/') $closes[] = $name; else $opens[] = $name;
  }
  if ($opens === []) return 'tool_marker_without_block';
  if (str_contains($text, str_repeat(chr(96), 3))) return 'tool_marker_in_code_fence';
  if (count($opens) > 1) return 'multiple_tool_blocks';
  $known = ['PLAN_SAVE','PLAN_APPEND','PLAN_CONTINUE','PLAN_AND_BUILD','PROJECT_BUILD','PLANSAVE','PLANCONTINUE'];
  if (!in_array($opens[0], $known, true)) return 'tool_name_unknown';
  if ($closes === []) return 'tool_block_unclosed';
  if (count($closes) > 1 || $closes[0] !== $opens[0]) return 'tool_markers_mismatched';
  if (preg_match('~\[COREUI_[A-Z_]+\][ \t]*\n([\s\S]*?)\n[ \t]*\[/COREUI_~', $text, $inner) === 1
      && !coreui_native_recovered_body_ok($opens[0], trim($inner[1]))) return 'tool_body_too_short';
  return 'tool_text_around_block';
}

/** Does a reply try to call a native tool at all (also with bold markers)? */
function coreui_native_tool_attempt(string $reply): bool {
  $text = coreui_native_marker_lines_normalize($reply);
  return preg_match('~^\s*\[COREUI_[A-Z_]+\]~u', $text) === 1
    || coreui_native_plan_marker_attempt($text)
    || coreui_native_plan_continue_marker_attempt($text)
    || preg_match('~(?m)^[ \t]*\[COREUI_(?:PLAN_SAVE|PLAN_APPEND|PLAN_CONTINUE|PLAN_AND_BUILD|PROJECT_BUILD)\][ \t]*$~',
      substr($text, 0, 4000)) === 1;
}

/** The single correction request after an unreadable tool reply. */
function coreui_native_tool_repair_prompt(string $shape, string $previous, bool $compact = false): string {
  $why = [
    'multiple_tool_blocks'=>'Du hast mehrere Werkzeugbloecke geschrieben. Erlaubt ist genau EIN Block. '
      . 'Plan speichern UND bauen ist ein einziger [COREUI_PLAN_AND_BUILD]-Block.',
    'tool_marker_in_code_fence'=>'Der Werkzeugblock stand in einem Markdown-Codeblock. '
      . 'Ein echter Werkzeugblock steht ohne Codeblock direkt in der Antwort.',
    'tool_block_unclosed'=>'Der schliessende Marker fehlte.',
    'tool_markers_mismatched'=>'Oeffnender und schliessender Marker passten nicht zusammen.',
    'tool_name_unknown'=>'Der Werkzeugname war unbekannt.',
    'tool_body_too_short'=>'Der Inhalt war zu kurz. Ein Plan beginnt mit # Titel und hat ## Abschnitte oder Listenpunkte.',
    'tool_text_around_block'=>'Vor oder nach dem Block stand Text mit Klammern, Links oder Codebloecken.',
  ][$shape] ?? 'Die Form des Werkzeugblocks war nicht eindeutig.';
  $prompt = "\n\n[COREUI WERKZEUGKORREKTUR]\nDeine letzte Antwort wollte ein CoreAI-Werkzeug "
    . "nutzen, war aber nicht sicher lesbar. Grund: " . $why . "\n"
    . "Triff deine Entscheidung jetzt erneut. Antworte ENTWEDER normal ohne jeden COREUI-Marker "
    . "ODER ausschliesslich mit genau EINEM vollstaendigen Block, erste Zeile der oeffnende, "
    . "letzte Zeile der passende schliessende Marker, zum Beispiel:\n"
    . "[COREUI_PLAN_SAVE]\n# Titel\n## Abschnitt\n- Punkt\n[/COREUI_PLAN_SAVE]\n"
    . "Erlaubte Werkzeuge: PLAN_SAVE, PLAN_APPEND, PLAN_CONTINUE, PLAN_AND_BUILD, PROJECT_BUILD. "
    . "Kein Codeblock und kein zweiter Block.\n";
  $previous = trim($previous);
  if (!$compact && $previous !== '') {
    $excerpt = function_exists('mb_strcut') ? mb_strcut($previous, 0, 8000, 'UTF-8') : substr($previous, 0, 8000);
    $prompt .= "Deine vorige Antwort, nur als Material fuer die Korrektur:\n<<<\n" . $excerpt . "\n>>>\n";
  }
  return $prompt;
}

function coreui_native_plan_marker_attempt(string $reply): bool {
  return preg_match('~(?mi)^[ \t]*\[(?:COREUI_PLAN_SAVE|COREUI_PLAN_APPEND|COREUI_PLAN_AND_BUILD|COREUI/PLANSAVE)\][ \t]*$~',
    substr($reply, 0, 1800)) === 1;
}

/**
 * Extract only explicit, safe source filenames from an approved plan.
 * Do not silently reduce a planned six-page website to three generic files.
 * Inert flat filenames only: no PHP, paths, shells, symlinks or dotfiles.
 */
function coreui_native_plan_source_manifest(string $markdown): ?array {
  $hits = [];
  preg_match_all(
    '~(?<![A-Za-z0-9._/-])([A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg))(?![A-Za-z0-9._/-])~i',
    $markdown, $hits
  );
  $files = [];
  $seen = [];
  foreach ($hits[1] ?? [] as $raw) {
    $name = (string)$raw;
    if (preg_match('~^projektplan-v[0-9]+[.]md$~i', $name)) continue;
    $lower = strtolower($name);
    if (isset($seen[$lower])) continue;
    $seen[$lower] = true;
    $files[] = $name;
    if (count($files) > 9) return null;
  }
  if ($files === []) return [];
  $hasHtml = (bool)array_filter($files,
    static fn(string $name): bool => preg_match('~[.]html?$~i',$name) === 1);
  if (!$hasHtml) return []; // No ungrounded page manifest from CSS-only prose.
  // Keep the user's actual asset filenames, e.g. styles.css / app.js.
  // Only invent the standard CSS/JS names when the plan names none at all.
  $requires = [];
  if (!isset($seen['index.html'])) $requires[] = 'index.html';
  if (!array_filter($files,
      static fn(string $name): bool => preg_match('~[.]css$~i',$name) === 1)) {
    $requires[] = 'style.css';
  }
  if (!array_filter($files,
      static fn(string $name): bool => preg_match('~[.]js$~i',$name) === 1)) {
    $requires[] = 'main.js';
  }
  foreach ($requires as $name) {
    $files[] = $name;
    $seen[$name] = true;
  }
  if (count($files) > 9) return null;
  // Deterministic output order, even if the plan first mentions about.html.
  usort($files, static function(string $a,string $b): int {
    if (strcasecmp($a,'index.html')===0) return -1;
    if (strcasecmp($b,'index.html')===0) return 1;
    return 0;
  });
  return $files;
}

function coreui_native_tool_build_request(PDO $pdo, int $uid, string $sessionId,
                                           string $description): ?string {
  $description = trim($description);
  if ($description === '' || strlen($description) > 12000) return null;
  $plan = coreui_plan_latest($pdo,$uid,$sessionId);
  $source = ($plan && trim((string)$plan['markdown']) !== '')
    ? (string)$plan['markdown'] : '';
  if ($source !== '' && coreui_plan_is_draft_markdown($source)) return null;
  // Never silently omit part of an approved plan; explicit guidance to split.
  // Keep the complete approved plan in each bounded, serial source task.
  // Reject oversized plans rather than silently dropping requirements.
  if (strlen($source) > 12000) return null;
  // Fail closed on unsupported app stacks. Do not quietly relabel React,
  // server code or a mobile APK as a working static three-file ZIP.
  // "kein React, kein PHP" describes a static site and must not block it.
  if (preg_match('~\b(?:react|next[.]?js|vue|angular|svelte|wordpress|vite|php|mysql|postgres|laravel|apk|android\s+native|typescript)\b~iu',
      coreui_native_strip_negated_stacks($description . "\n" . $source))) return null;
  $spec = coreui_console_project_request_spec($description);
  if (!is_array($spec)) return null;
  $files = array_values($spec['requested']);
  // A completed private plan with explicit filenames takes precedence.
  // This list comes from checked plan bytes, never a user-keyword trigger.
  $plannedFiles = $source !== '' ? coreui_native_plan_source_manifest($source) : [];
  if ($plannedFiles === null) return null;
  if ($plannedFiles !== []) $files = $plannedFiles;
  if (count($files) === 0) {
    // No filenames in the plan: preserve the legacy minimal static bundle.
    $files = ['index.html','style.css','main.js'];
  }
  if (count($files) < 2 || count($files) > 9) return null;
  foreach ($files as $filename) {
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)$~iD',$filename))
      return null;
  }
  $header = 'Erstelle die folgenden vollstaendigen Projektdateien und stelle '
    . 'ein ZIP zum Download bereit: ' . implode(', ',$files) . "\n"
    . 'Erstelle projekt.zip. Keine Platzhalter und keine erfundenen Dateilinks.'
    . "\n[COREUI_PLANUNGSKONTEXT]\n"
    . "Ausfuehrungsauftrag vom Modell, als Projektanforderungen behandeln:\n"
    . $description . "\n";
  if ($source !== '') {
    $header .= "Der folgende persistierte Plan ist verbindlicher Projektkontext ("
      . strlen($source) . " Bytes, Version ".(int)$plan['version']."):\n" . $source . "\n";
  }
  return $header . "[COREUI_ENDE_PLANUNGSKONTEXT]\n";
}

/**
 * Execute one model-selected tool safely. No shell, SQL, URL or arbitrary
 * PHP command can be passed by the model. The build callback reuses the
 * already secured per-turn Python source/ZIP writer once and ONLY once.
 */
function coreui_native_tool_apply(
  PDO $pdo,int $uid,string $sessionId,int $turnId,string $reply,callable $build,
  bool $modelWasTruncated = false, ?callable $planGenerate = null,
  string $planGoal = ''
): ?string {
  if (!empty($GLOBALS['COREUI_NATIVE_TOOL_REENTRY'])) return null;
  $tool = coreui_native_tool_parse($reply);
  if ($tool === null) return null;
  if ($uid <= 0 || $turnId <= 0 || !preg_match('~^[A-Za-z0-9_-]{8,40}$~D',$sessionId))
    return 'Der Werkzeugauftrag besitzt keinen gueltigen privaten Chatkontext.';
  $kind = $tool['type'];
  if ($kind === 'PLAN_CONTINUE' && !empty($tool['recovered'])
      && function_exists('coreui_work_record')) {
    coreui_work_record($pdo,'tool','info',
      'Fortsetzungswerkzeug trotz unvollstaendigem Marker erkannt',
      ['reason'=>'recovered_plan_continue',
       'marker_incomplete'=>!empty($tool['incomplete']),
       'discarded_tool_body_bytes'=>strlen((string)$tool['body'])]);
  }
  // PLAN_CONTINUE has no executable body: it only resumes the already
  // authenticated, saved draft. A truncated marker is safe to recover.
  if ($modelWasTruncated && !in_array($kind,['PLAN_SAVE','PLAN_APPEND','PLAN_CONTINUE'],true)) {
    return 'Der Werkzeugauftrag wurde vorzeitig abgebrochen. '
      . 'Keine Aktion ausgefuehrt, kein Download vorgetaeuscht.';
  }
  if (!empty($tool['public_note']) && function_exists('coreui_work_record')) {
    coreui_work_record($pdo,'commentary','info','Mara meldet ihren naechsten Schritt',
      ['public_note'=>(string)$tool['public_note'],'source'=>'model_public_text']);
  }
  $draft = in_array($kind,['PLAN_SAVE','PLAN_APPEND'],true)
    && (!empty($tool['draft']) || $modelWasTruncated);
  if (in_array($kind,['PLAN_SAVE','PLAN_APPEND','PLAN_CONTINUE','PLAN_AND_BUILD'],true)) {
    try {
      if ($kind === 'PLAN_CONTINUE') {
        $saved = coreui_plan_latest($pdo,$uid,$sessionId);
        if (!is_array($saved)) return 'Hier ist noch kein Projektplan gespeichert.';
        if (!coreui_plan_is_draft_markdown((string)$saved['markdown']))
          return 'Der letzte Projektplan ist bereits abgeschlossen.';
        if ($planGenerate === null)
          return 'Der Entwurf ist erhalten, aber die Fortsetzung ist momentan nicht verfuegbar.';
        $saved = coreui_plan_save($pdo,$uid,$sessionId,$turnId,
          (string)$saved['markdown'],false);
      } elseif ($kind === 'PLAN_APPEND' && $draft) {
        // Incomplete additions must never make an approved previous plan
        // silently appear complete. Preserve old bytes in a versioned draft.
        $previous = coreui_plan_latest($pdo,$uid,$sessionId);
        if (!is_array($previous)) return 'Es gibt noch keinen Projektplan zum Erweitern.';
        $existingText = coreui_plan_draft_text((string)$previous['markdown']);
        $extended = coreui_plan_merge_chunk($existingText,(string)$tool['body']);
        if (!is_string($extended)) return 'Die Planerweiterung war eine Wiederholung oder nicht zusammenfuehrbar.';
        $saved = coreui_plan_save($pdo,$uid,$sessionId,$turnId,
          COREUI_PLAN_DRAFT_MARKER . "\n\n" . $extended,false);
      } else {
        $planText = $draft ? COREUI_PLAN_DRAFT_MARKER . "\n\n" . $tool['body'] : $tool['body'];
        $saved = coreui_plan_save($pdo,$uid,$sessionId,$turnId,$planText,
          $kind==='PLAN_APPEND');
      }
    } catch (Throwable $e) {
      error_log('[CoreUI] Native plan save failed: '.get_class($e));
      return 'Ich konnte den Markdown-Projektplan nicht dauerhaft speichern. '
        . 'Die Datenbankmigration und die maximale Dateigroesse muessen geprueft werden.';
    }
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo,'artifact','success','Markdown-Projektplan gespeichert',
        ['file_name'=>'projektplan-v'.(int)$saved['version'].'.md',
          'source'=>'native_plan_tool']);
    }
    if ($kind !== 'PLAN_AND_BUILD') {
      $autoSegments = 0;
      if ($planGenerate !== null && coreui_plan_is_draft_markdown((string)$saved['markdown'])) {
        $progress = coreui_plan_autorun($pdo,$uid,$sessionId,$turnId,$saved,
          $planGenerate,$planGoal);
        $saved = $progress['saved'];
        $autoSegments = (int)$progress['segments'];
      }
      if (coreui_plan_is_draft_markdown((string)$saved['markdown'])) {
        return 'Ich habe den Projektplan als UNVOLLSTAENDIGEN Markdown-Entwurf v'
          . (int)$saved['version'] . ' gesichert. '
          . ($autoSegments > 0
            ? 'Ich habe selbststaendig ' . $autoSegments . ' weitere Teilabschnitte geschrieben. '
            : 'Ein weiterer Planabschnitt von Core konnte noch nicht bestaetigt werden. ')
          . 'Der Markdown-Download ist vorhanden; die Planung ist noch nicht abgeschlossen. '
          . 'Es wurde kein Projektbau gestartet.';
      }
      return 'Ich habe den Projektplan als abgeschlossene Markdown-Version v'
        . (int)$saved['version'] . ' gespeichert. '
        . ($autoSegments > 0
          ? $autoSegments . ' weitere Teilabschnitte habe ich eigenstaendig erstellt. '
          : '')
        . 'Die Datei ist als geschuetzter Download verfuegbar.';
    }
    $description = 'Setze den gespeicherten Plan als vollstaendiges statisches Webprojekt um.';
  } else {
    $description = $tool['body'];
  }
  if (function_exists('ember_py_enabled') && !ember_py_enabled()) {
    return 'Der Datei-Writer ist derzeit deaktiviert. Es wurde kein Projekt erstellt.';
  }
  $request = coreui_native_tool_build_request($pdo,$uid,$sessionId,$description);
  if ($request === null) {
    $lastPlan = coreui_plan_latest($pdo,$uid,$sessionId);
    if ($lastPlan && coreui_plan_is_draft_markdown((string)$lastPlan['markdown'])) {
      return 'Der gespeicherte Projektplan ist noch ein unvollstaendiger Entwurf. '
        . 'Ich starte damit keine Dateierstellung und gebe keinen fertigen ZIP-Download vor. '
        . 'Zuerst muss die Planung abgeschlossen und als neue fertige Version gespeichert werden.';
    }
    if ($lastPlan && strlen((string)$lastPlan['markdown']) > 12000) {
      return 'Der fertig gespeicherte Projektplan umfasst '
        . strlen((string)$lastPlan['markdown']) . ' Bytes und ueberschreitet '
        . 'das derzeitige Limit von 12000 Bytes pro Projektauftrag. '
        . 'Ich schneide ihn nicht ab und gebe kein unvollstaendiges ZIP aus. '
        . 'Die Umsetzung muss in kleinere Projektabschnitte aufgeteilt werden.';
    }
    if ($lastPlan && coreui_native_plan_source_manifest((string)$lastPlan['markdown']) === null) {
      return 'Der Projektplan enthaelt mehr als neun unterschiedliche Quelldateien. '
        . 'Der Dateigenerator nimmt hoechstens neun sichere Dateien pro ZIP an. '
        . 'Die Planung muss in mehrere verifizierbare Dateipakete aufgeteilt werden.';
    }
    return 'Die Projektquellen konnten nicht sicher zusammengestellt werden. '
      . 'Entweder ist die Dateiliste ungueltig oder der gespeicherte Plan '
      . 'ist zu gross fuer einen einzelnen Core-Durchlauf. Die Markdown-Datei bleibt erhalten.';
  }
  if (function_exists('coreui_work_record')) {
    coreui_work_record($pdo,'tool','info','Mara hat selbst den Projekt-Writer aufgerufen',
      ['reason'=>'model_requested_project_build']);
  }
  // This authority comes from the verified native tool, not from a user
  // keyword or model-written Python. Retain it only during this callback.
  // The file worker MUST generate inert source files one by one; it may not
  // execute the legacy model-written [PY] project constructor.
  $previousSourceMode = $GLOBALS['COREUI_NATIVE_SOURCE_PROJECT'] ?? null;
  $GLOBALS['COREUI_NATIVE_TOOL_REENTRY'] = true;
  $GLOBALS['COREUI_NATIVE_SOURCE_PROJECT'] = true;
  try {
    $response = $build($request);
    return is_string($response) && trim($response) !== ''
      ? $response
      : 'Der Projekt-Writer hat keinen bestaetigten Download geliefert.';
  } catch (Throwable $e) {
    error_log('[CoreUI] Native project build failed: '.get_class($e));
    return 'Der Projektauftrag konnte nicht abgeschlossen werden. Es wird kein Download vorgetaeuscht.';
  } finally {
    unset($GLOBALS['COREUI_NATIVE_TOOL_REENTRY']);
    if ($previousSourceMode === null) unset($GLOBALS['COREUI_NATIVE_SOURCE_PROJECT']);
    else $GLOBALS['COREUI_NATIVE_SOURCE_PROJECT'] = $previousSourceMode;
  }
}
