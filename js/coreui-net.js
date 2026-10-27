/* CoreAI 0.5.70 (#112): resilient JSON requests for the console.
 * Every request can be aborted and times out; failures carry a kind
 * (offline, timeout, aborted, http, json, network) so the UI can say what
 * happened. Measurements stay in memory and never hold message text. */
(function () {
  'use strict';

  var MAX_METRICS = 60;
  var metrics = [];
  var longFrames = {count: 0, max_ms: 0};

  function now() {
    return typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now();
  }

  function isOnline() {
    return typeof navigator === 'undefined' || navigator.onLine !== false;
  }

  /** Only numbers and short codes: no prompts, answers or tokens. */
  function record(entry) {
    var clean = {at: Date.now()};
    Object.keys(entry || {}).forEach(function (key) {
      var value = entry[key];
      if (typeof value === 'number' && isFinite(value)) clean[key] = Math.round(value);
      else if (typeof value === 'boolean') clean[key] = value;
      else if (typeof value === 'string' && /^[a-z0-9_:-]{1,40}$/i.test(value)) clean[key] = value;
    });
    metrics.push(clean);
    if (metrics.length > MAX_METRICS) metrics.shift();
    return clean;
  }

  function netError(kind, status, code) {
    var error = new Error(code || kind);
    error.kind = kind;
    error.status = status || 0;
    return error;
  }

  /**
   * fetch plus JSON with timeout and abort. Resolves with the parsed body
   * (also for HTTP errors that carry JSON, the caller checks data.ok).
   */
  function fetchJson(url, opts) {
    opts = opts || {};
    var timeoutMs = Math.max(1000, Number(opts.timeoutMs) || 20000);
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var outer = opts.signal || null;
    if (outer && outer.aborted) return Promise.reject(netError('aborted'));
    var timedOut = false;
    var started = now();
    function abortInner() { if (controller) controller.abort(); }
    if (outer && outer.addEventListener) outer.addEventListener('abort', abortInner);
    var timer = setTimeout(function () { timedOut = true; abortInner(); }, timeoutMs);
    function cleanup() {
      clearTimeout(timer);
      if (outer && outer.removeEventListener) outer.removeEventListener('abort', abortInner);
    }
    var init = {credentials: 'include', cache: 'no-store'};
    if (opts.init) Object.keys(opts.init).forEach(function (key) { init[key] = opts.init[key]; });
    if (controller) init.signal = controller.signal;

    function failed(error) {
      cleanup();
      if (error && error.kind) throw error;
      if (outer && outer.aborted) throw netError('aborted');
      if (timedOut) throw netError('timeout');
      if (!isOnline()) throw netError('offline');
      throw netError('network');
    }

    var request;
    try {
      request = fetch(url, init);
    } catch (error) {
      request = Promise.reject(error);
    }
    return Promise.resolve(request).then(function (response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { data = null; }
        if (!data || typeof data !== 'object') {
          throw netError(response.ok ? 'json' : 'http', response.status);
        }
        cleanup();
        if (opts.metric) record({kind: opts.metric, ms: now() - started, status: response.status});
        return data;
      });
    }).then(null, function (error) {
      try { failed(error); } catch (classified) {
        if (opts.metric && classified.kind !== 'aborted') {
          record({kind: opts.metric + '_error', error: classified.kind, status: classified.status,
            ms: now() - started});
        }
        throw classified;
      }
    });
  }

  /** Exponential backoff with jitter, capped; attempt starts at 0. */
  function backoff(attempt, baseMs, capMs, random) {
    var n = Math.max(0, Math.min(16, Number(attempt) || 0));
    var raw = Math.min(Number(capMs) || 30000, (Number(baseMs) || 1000) * Math.pow(2, n));
    var jitter = 0.8 + 0.4 * (typeof random === 'function' ? random() : Math.random());
    return Math.round(Math.min(Number(capMs) || 30000, raw * jitter));
  }

  /** Short German explanation for a failed request. */
  function describe(error) {
    var kind = error && error.kind;
    if (kind === 'offline') return 'Keine Netzverbindung. Sobald sie zurück ist, lade ich automatisch neu.';
    if (kind === 'timeout') return 'Der Server hat nicht rechtzeitig geantwortet (Zeitüberschreitung).';
    if (kind === 'http') return 'Der Server meldet einen Fehler (HTTP ' + (Number(error.status) || 0) + ').';
    if (kind === 'json') return 'Die Serverantwort war unvollständig oder ungültig.';
    if (kind === 'network') return 'Die Verbindung zum Server ist gerade gestört.';
    var code = error && error.message ? String(error.message).replace(/[^a-z0-9_]/gi, '').slice(0, 40) : '';
    return 'Der Server konnte den Verlauf nicht liefern' + (code ? ' (' + code + ')' : '') + '.';
  }

  // Long frames (over 50 ms) only as a count and the longest duration.
  try {
    if (typeof PerformanceObserver === 'function'
        && (PerformanceObserver.supportedEntryTypes || []).indexOf('longtask') >= 0) {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          longFrames.count++;
          longFrames.max_ms = Math.max(longFrames.max_ms, Math.round(entry.duration));
        });
      }).observe({entryTypes: ['longtask']});
    }
  } catch (e) { /* optional */ }

  window.CoreUINet = {
    fetchJson: fetchJson,
    backoff: backoff,
    describe: describe,
    isOnline: isOnline,
    record: record,
    metrics: function () {
      return {entries: metrics.slice(), long_frames: {count: longFrames.count, max_ms: longFrames.max_ms}};
    }
  };
})();
