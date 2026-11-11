/* CoreAI: TCI Interface 0.7.14: Zuletzt verwendete Aufnahmen (Settings > Stimme).
   The last 20 recordings of the microphone (Stimmsequenzen and dictations) stay in this
   browser only (IndexedDB), never on the server. Each account has its own key from the
   server (no user id in clear), so two accounts on one device never see each other's
   recordings. Used by js/coreui-voice-input.js (saves) and js/coreui-voice-settings.js
   (lists, plays, saves as a file, deletes). Without IndexedDB nothing is kept. */
(function () {
  'use strict';

  var DB = 'coreai-recordings';
  var STORE = 'recordings';
  var KEEP = 20;
  var opening = null;

  function open() {
    if (opening) return opening;
    opening = new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('no_indexeddb')); return; }
      var request;
      try { request = window.indexedDB.open(DB, 1); } catch (e) { reject(e); return; }
      request.onupgradeneeded = function () {
        var store = request.result.createObjectStore(STORE, {keyPath: 'id'});
        store.createIndex('owner', 'owner', {unique: false});
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error || new Error('open_failed')); };
      request.onblocked = function () { reject(new Error('blocked')); };
    });
    opening.catch(function () { opening = null; });
    return opening;
  }

  function done(tx) {
    return new Promise(function (resolve, reject) {
      tx.oncomplete = function () { resolve(); };
      tx.onerror = tx.onabort = function () { reject(tx.error || new Error('tx_failed')); };
    });
  }

  function validOwner(owner) { return typeof owner === 'string' && /^[0-9a-f]{8,64}$/.test(owner); }

  /** The account's recordings, newest first. */
  function list(owner) {
    if (!validOwner(owner)) return Promise.resolve([]);
    return open().then(function (db) {
      return new Promise(function (resolve, reject) {
        var out = [];
        var tx = db.transaction(STORE, 'readonly');
        var request = tx.objectStore(STORE).index('owner').openCursor(window.IDBKeyRange.only(owner));
        request.onsuccess = function () {
          var cursor = request.result;
          if (cursor) { out.push(cursor.value); cursor.continue(); return; }
          out.sort(function (a, b) { return (b.created || 0) - (a.created || 0); });
          resolve(out);
        };
        request.onerror = function () { reject(request.error); };
      });
    });
  }

  function remove(owner, id) {
    if (!validOwner(owner)) return Promise.resolve();
    return open().then(function (db) {
      var tx = db.transaction(STORE, 'readwrite');
      var store = tx.objectStore(STORE);
      var get = store.get(id);
      get.onsuccess = function () { if (get.result && get.result.owner === owner) store.delete(id); };
      return done(tx);
    });
  }

  function clear(owner) {
    return list(owner).then(function (items) {
      if (!items.length) return;
      return open().then(function (db) {
        var tx = db.transaction(STORE, 'readwrite');
        items.forEach(function (item) { tx.objectStore(STORE).delete(item.id); });
        return done(tx);
      });
    });
  }

  /** Keeps a recording: {blob, kind: 'stimmsequenz'|'diktat', seconds, text?, state?}. Then only the newest 20. */
  function add(owner, item) {
    if (!validOwner(owner) || !item || !item.blob || !item.blob.size) return Promise.resolve(null);
    var record = {
      id: Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8),
      owner: owner,
      created: Date.now(),
      kind: item.kind === 'diktat' ? 'diktat' : 'stimmsequenz',
      seconds: Math.max(0, Math.round(Number(item.seconds) || 0)),
      type: String(item.blob.type || 'audio/webm'),
      blob: item.blob,
      text: typeof item.text === 'string' ? item.text.slice(0, 4000) : '',
      state: typeof item.state === 'string' ? item.state : ''
    };
    return open().then(function (db) {
      var tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(record);
      return done(tx);
    }).then(function () {
      return list(owner).then(function (items) {
        var old = items.slice(KEEP);
        if (!old.length) return record;
        return open().then(function (db) {
          var tx = db.transaction(STORE, 'readwrite');
          old.forEach(function (o) { tx.objectStore(STORE).delete(o.id); });
          return done(tx);
        }).then(function () { return record; });
      });
    });
  }

  /** Updates the note of a kept recording (the text of a dictation, "gesendet" or "nicht gesendet"). */
  function update(owner, id, changes) {
    if (!validOwner(owner) || !id) return Promise.resolve();
    return open().then(function (db) {
      var tx = db.transaction(STORE, 'readwrite');
      var store = tx.objectStore(STORE);
      var get = store.get(id);
      get.onsuccess = function () {
        var item = get.result;
        if (!item || item.owner !== owner) return;
        if (typeof changes.text === 'string') item.text = changes.text.slice(0, 4000);
        if (typeof changes.state === 'string') item.state = changes.state;
        store.put(item);
      };
      return done(tx);
    });
  }

  window.CoreAIRecordings = {KEEP: KEEP, add: add, list: list, remove: remove, clear: clear, update: update,
    available: function () { return !!window.indexedDB; }};
})();
