#!/usr/bin/env node
'use strict';
// Isolated, DOM-free tests for the exact production response timing helpers.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'console-app.js'), 'utf8');
const begin = source.indexOf('  function validDurationMs(value) {');
const end = source.indexOf('  function buildMessageDetails(record) {', begin);
assert(begin >= 0 && end > begin, 'timing helpers must exist before message info');
const helpers = vm.runInNewContext(
  source.slice(begin, end) + '\n({validDurationMs, formatResponseDuration, responseTimings});',
  {}
);
const {validDurationMs, formatResponseDuration, responseTimings} = helpers;
const event = (kind, state, title, meta) => ({kind, state, title, meta});

const oldReply = responseTimings({work_events:[
  event('run','success','Antwort gespeichert',{elapsed_ms:'6000'})
]});
assert.equal(oldReply.total_ms,6000,'existing 0.5.11 messages keep their duration');
assert.equal(oldReply.total_label,'GESAMTDAUER');
assert.equal(oldReply.model_ms,null,'old runs must not invent model time');

const newReply = responseTimings({
  elapsed_ms:999,
  agent_run:{duration_ms:14900},
  work_events:[
    event('run','success','Antwort gespeichert',{elapsed_ms:'15432'}),
    event('model','success','Modellstream empfangen',{duration_ms:'5890'})
  ]
});
assert.equal(newReply.total_ms,15432,'persisted server event overrides transient SSE time');
assert.equal(newReply.model_ms,5890,'measured provider request is separate');
assert.equal(formatResponseDuration(newReply.total_ms),'15,4 s');
assert.equal(formatResponseDuration(newReply.model_ms),'5,9 s');
assert.equal(formatResponseDuration(63456),'1 min 3,5 s');

const multiple = responseTimings({work_events:[
  event('model','success','Modellstream empfangen',{duration_ms:1800}),
  event('model','success','Modellstream empfangen',{duration_ms:2100})
]});
assert.equal(multiple.model_ms,3900,'multiple model calls are summed');

const fallback = responseTimings({agent_run:{duration_ms:5321},work_events:[]});
assert.equal(fallback.total_ms,5321,'completed agent run supplies history fallback');
assert.equal(fallback.total_label,'AGENTENLAUFZEIT');
assert.equal(fallback.model_ms,null);

const invalid = responseTimings({elapsed_ms:0,agent_run:{duration_ms:null},work_events:[
  event('run','error','Antwort gespeichert',{elapsed_ms:'9000'}),
  event('model','error','Modellstream empfangen',{duration_ms:999}),
  event('model','success','Modellstream empfangen',{duration_ms:'NaN'})
]});
assert.equal(invalid.total_ms,null,'invalid/untrusted values must not become durations');
assert.equal(invalid.model_ms,null,'failed/unknown calls are not success timings');
assert.equal(validDurationMs('Infinity'),null);
assert.equal(validDurationMs(-4),null);
console.log('CoreUI persisted response timing UI selftest: OK');
