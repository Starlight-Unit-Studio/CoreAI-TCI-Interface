#!/usr/bin/env node
'use strict';
// TCI language regression. User-facing copy only; keep protocol and legacy filenames.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const work = read('js/coreui-work-events.js');
const voice = read('js/coreui-voice-input.js');
const settings = read('settings.html');
const answerStyle = read('api/console_answer_style.php');
const phpAudio = read('api/voice_transcribe.php');
const insights = read('js/coreui-insights.js');
const attachPhp = read('api/ember_attachments.php');
const voiceSelftest = read('scripts/voice-input-selftest.php');

assert.match(work, /Ich erstelle zuerst eine Mitschrift deiner Stimmsequenz/);
assert.match(work, /Ich bearbeite deine Anfrage/);
assert.match(work, /Ich höre mir deine Stimmsequenz an/);
assert.doesNotMatch(work, /Core arbeitet jetzt|Ich bereite Core vor|Core schreibt die Aufnahme zuerst|Antwort von Core|Audio-Eingang von Core/);
assert.match(voice, /Stimmsequenz aufnehmen/);
assert.match(voice, /STIMMSEQUENZ WARTET/);
assert.match(settings, /NAMEN UND BEGRIFFE FÜR STIMMSEQUENZEN/);
assert.doesNotMatch(settings, /Core und Mara schreiben sie dann genau so/);
assert.match(answerStyle, /eine einzige zusammenhaengende Terran Cognitive Intelligence/);
assert.match(phpAudio, /Eine Stimmsequenz/);
assert.match(insights, /In dieser Sitzung liegt noch keine Antwort vor/);
// The actual voice attachment note and its end-to-end test must use the same user-facing term.
assert.match(attachPhp, /\$what = \$voice \? 'eine Stimmsequenz' : 'eine Audiodatei'/);
assert.match(voiceSelftest, /str_contains\(\$block, 'Das ist eine Stimmsequenz \(Laenge etwa 02:30, Minuten:Sekunden\)'/);
assert.doesNotMatch(voiceSelftest, /str_contains\(\$block, 'Das ist eine Sprachnachricht/);
// The no-audio-capability branch and its test must agree, while denying the audio path.
assert.match(attachPhp, /if \(!ember_attach_audio_allowed\(\$pdo, \$uid\)\) return \$deaf\('Der gewaehlte Core unterstuetzt keine Audioeingabe\.'\);/);
assert.match(voiceSelftest, /str_contains\(\$unsupportedAudioNote, 'Du kannst sie NICHT anhoeren'\)/);
assert.match(voiceSelftest, /str_contains\(\$unsupportedAudioNote, 'Der gewaehlte Core unterstuetzt keine Audioeingabe\.'\)/);
assert.match(voiceSelftest, /!ember_attach_needs_vision_path\(\$pdo, \$voiceId, 7\)/);
assert.doesNotMatch(voiceSelftest, /keinen Audio-Eingang/);
// Legacy media filenames and persisted event identities MUST NOT be renamed.
assert.match(voice, /'Sprachnachricht-' \+ stamp/);
assert.match(phpAudio, /COREUI_VOICE_MESSAGE_PREFIX = 'Sprachnachricht-'/);
assert.match(work, /case 'Modellstream gestartet':/);
console.log('CoreAI TCI language and Stimmsequenz terminology: OK');
