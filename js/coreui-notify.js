/* CoreAI: TCI Interface 0.6.81: Benachrichtigungen in the browser.
   - Push for this browser: permission, the service worker ./sw.js, PushManager.subscribe with
     the server's VAPID key, and telling the server (api/notifications.php). Switching off
     unsubscribes here and removes the browser on the server.
   - The chat keeps this browser's subscription known to the server (sync), e.g. after the
     server's key changed or the browser renewed the subscription.
   - Browser fallback: where Push is not possible (or not switched on here) but notifications
     are allowed and a CoreAI tab is open in the background, a long answer is still announced. */
(function () {
  'use strict';

  var API = './api/notifications.php';
  var FLAG = 'coreai.push.enabled';
  var state = null;
  var loading = null;

  function flag(value) {
    try {
      if (value === undefined) return localStorage.getItem(FLAG) === '1';
      if (value) localStorage.setItem(FLAG, '1'); else localStorage.removeItem(FLAG);
    } catch (error) { return false; }
    return !!value;
  }

  /** What this browser can do (pure apart from the browser objects it reads). */
  function support(win) {
    win = win || window;
    var nav = win.navigator || {};
    var ua = String(nav.userAgent || '');
    var ios = /iPad|iPhone|iPod/.test(ua) || (nav.platform === 'MacIntel' && Number(nav.maxTouchPoints) > 1);
    var standalone = false;
    try { standalone = !!(win.matchMedia && win.matchMedia('(display-mode: standalone)').matches) || nav.standalone === true; } catch (e) {}
    var secure = win.isSecureContext === true;
    var sw = !!nav.serviceWorker;
    var push = 'PushManager' in win;
    var notify = 'Notification' in win;
    var permission = notify ? String(win.Notification.permission || 'default') : 'unsupported';
    var reason = !secure ? 'insecure' : (!notify || !sw) ? 'unsupported' : (ios && !standalone) ? 'ios_home_screen'
      : !push ? 'unsupported' : permission === 'denied' ? 'denied' : '';
    return {secure: secure, serviceWorker: sw, push: push, notification: notify, ios: ios, standalone: standalone,
      permission: permission, ready: reason === '', reason: reason};
  }

  /** The VAPID key from base64url to the bytes PushManager wants (pure). */
  function keyBytes(text) {
    var b64 = String(text || '').replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    var raw = atob(b64);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  /** The short key the server shows for a browser: sha-256 of the endpoint, 16 hex characters. */
  function endpointKey(endpoint) {
    if (!endpoint || !window.crypto || !window.crypto.subtle) return Promise.resolve('');
    return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint)).then(function (hash) {
      return Array.prototype.map.call(new Uint8Array(hash), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('').slice(0, 16);
    });
  }

  function request(method, body) {
    var options = {method: method, credentials: 'include', cache: 'no-store', headers: {Accept: 'application/json'}};
    if (method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CoreUI-CSRF'] = state && state.csrf_token ? state.csrf_token : '';
      options.body = JSON.stringify(body || {});
    }
    return fetch(API, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data || !data.ok) {
          var error = new Error((data && data.error) || 'notifications_failed');
          error.status = response.status;
          throw error;
        }
        state = data;
        return data;
      });
    });
  }

  function load(force) {
    if (state && !force) return Promise.resolve(state);
    if (!loading || force) loading = request('GET').then(function (data) { loading = null; return data; }, function (error) { loading = null; throw error; });
    return loading;
  }
  function post(body) {
    return (state ? Promise.resolve(state) : load()).then(function () { return request('POST', body); });
  }

  function registration() {
    return navigator.serviceWorker.register('./sw.js', {scope: './'}).then(function () {
      return navigator.serviceWorker.ready;
    });
  }

  function currentSubscription() {
    if (!navigator.serviceWorker || !navigator.serviceWorker.getRegistration) return Promise.resolve(null);
    return navigator.serviceWorker.getRegistration('./').then(function (reg) {
      return reg && reg.pushManager ? reg.pushManager.getSubscription() : null;
    }).catch(function () { return null; });
  }

  function sameKey(subscription, publicKey) {
    var own = subscription && subscription.options && subscription.options.applicationServerKey;
    if (!own) return true;
    var a = new Uint8Array(own);
    var b = keyBytes(publicKey);
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  function subscribe(data) {
    return registration().then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (sub && sameKey(sub, data.push.public_key)) return sub;
        return (sub ? sub.unsubscribe() : Promise.resolve()).then(function () {
          return reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(data.push.public_key)});
        });
      });
    }).then(function (sub) {
      var json = sub.toJSON();
      return post({action: 'subscribe', endpoint: json.endpoint, keys: json.keys || {}});
    });
  }

  /** Push on: must run from a tap or click (the browser asks for permission). */
  function enable() {
    var s = support();
    if (!s.ready) return Promise.reject(new Error(s.reason || 'unsupported'));
    return Promise.resolve(window.Notification.requestPermission()).then(function (permission) {
      if (permission !== 'granted') throw new Error(permission === 'denied' ? 'denied' : 'dismissed');
      return load(true);
    }).then(function (data) {
      if (!data.push || !data.push.available || !data.push.public_key) throw new Error('server_unavailable');
      return subscribe(data);
    }).then(function (data) {
      flag(true);
      return data;
    });
  }

  /** Push off for this browser (here and on the server). */
  function disable() {
    return currentSubscription().then(function (sub) {
      var endpoint = sub ? sub.endpoint : '';
      return (sub ? sub.unsubscribe().catch(function () {}) : Promise.resolve()).then(function () {
        flag(false);
        return endpoint ? post({action: 'unsubscribe', endpoint: endpoint}) : load(true);
      });
    });
  }

  /** The chat keeps this browser known to the server; nothing is asked, nothing shown. */
  function sync() {
    var s = support();
    if (!s.ready || s.permission !== 'granted' || !flag()) return Promise.resolve(false);
    return load(true).then(function (data) {
      if (!data.push || !data.push.available) return false;
      return subscribe(data).then(function () { return true; });
    }).catch(function () { return false; });
  }

  /**
   * A long answer has arrived. With Push on this browser, the server announces it; without,
   * and with the tab in the background and notifications allowed, the page does it itself.
   * info: {sessionId, startedAt (ms), text, name}
   */
  function answerDone(info, now) {
    info = info || {};
    var seconds = ((now || Date.now()) - Number(info.startedAt || 0)) / 1000;
    if (!info.startedAt || !document.hidden || !('Notification' in window) || window.Notification.permission !== 'granted') {
      return Promise.resolve(false);
    }
    return Promise.all([load().catch(function () { return null; }), currentSubscription()]).then(function (all) {
      var data = all[0];
      var sub = all[1];
      var limit = data && data.answer_seconds ? Number(data.answer_seconds) : 45;
      var channels = data && data.prefs ? String(data.prefs.answers || '') : 'push';
      if (sub || seconds < limit || channels.indexOf('push') < 0) return false;
      var title = String(info.name || 'Mara') + ' hat geantwortet';
      var body = String(info.text || '').replace(/```[\s\S]*?```/g, ' ').replace(/[*_`#>|]+/g, '').replace(/\s+/g, ' ').trim().slice(0, 160);
      var options = {body: body || 'Deine Antwort ist fertig.', tag: 'coreai-answers-' + String(info.sessionId || ''),
        icon: './images/watermark_logo.png', data: {url: './app.html?session=' + encodeURIComponent(String(info.sessionId || ''))}};
      var viaWorker = navigator.serviceWorker && navigator.serviceWorker.getRegistration
        ? navigator.serviceWorker.getRegistration('./') : Promise.resolve(null);
      return viaWorker.then(function (reg) {
        if (reg && reg.showNotification) return reg.showNotification(title, options).then(function () { return true; });
        var shown = new window.Notification(title, options);
        shown.onclick = function () { window.focus(); shown.close(); };
        return true;
      }).catch(function () { return false; });
    });
  }

  window.CoreAINotify = Object.freeze({
    support: support, keyBytes: keyBytes, endpointKey: endpointKey, load: load, post: post,
    enable: enable, disable: disable, sync: sync, currentSubscription: currentSubscription,
    answerDone: answerDone, enabledHere: function () { return flag(); }
  });

  // The chat page keeps this browser's Push alive (after a server key change or a renewal).
  if (document.getElementById('inputMessage')) setTimeout(sync, 4000);
})();
