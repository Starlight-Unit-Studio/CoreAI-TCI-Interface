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
// Legacy media filenames and persisted event identities MUST NOT be renamed.
assert.match(voice, /'Sprachnachricht-' \+ stamp/);
assert.match(phpAudio, /COREUI_VOICE_MESSAGE_PREFIX = 'Sprachnachricht-'/);
assert.match(work, /case 'Modellstream gestartet':/);
console.log('CoreAI TCI language and Stimmsequenz terminology: OK');
