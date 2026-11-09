/* CoreAI: TCI Interface 0.6.81: service worker for Push (Settings > Benachrichtigungen).
   Shows the notifications the server sends (encrypted per RFC 8291, only this browser can read
   them). An answer is not shown while a CoreAI page is open and in front, the person is
   already looking. A tap focuses an open CoreAI page or opens the link of the notification.
   No caching, no offline mode: this worker only handles Push. */
'use strict';

var DEFAULT_URL = './app.html';
var ICON = './images/watermark_logo.png';

function safeUrl(value) {
  var text = typeof value === 'string' ? value : '';
  return /^\.\/[A-Za-z0-9_./?#=&%-]*$/.test(text) ? text : DEFAULT_URL;
}

function readPayload(event) {
  if (!event || !event.data) return {};
  try { return event.data.json() || {}; } catch (error) {
    try { return {body: event.data.text()}; } catch (ignored) { return {}; }
  }
}

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
  var data = readPayload(event);
  event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
    var watching = list.some(function (client) { return client.visibilityState === 'visible' && client.focused; });
    if (data.skipIfVisible && watching) return undefined;
    return self.registration.showNotification(String(data.title || 'CoreAI').slice(0, 120), {
      body: String(data.body || '').slice(0, 400),
      icon: ICON,
      badge: ICON,
      tag: String(data.tag || 'coreai'),
      renotify: false,
      data: {url: safeUrl(data.url)}
    });
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = new URL(safeUrl(event.notification.data && event.notification.data.url), self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var client = list[i];
      if (client.url.indexOf(self.registration.scope) === 0 && 'focus' in client) {
        return client.focus().then(function (focused) {
          return focused && 'navigate' in focused && focused.url !== url ? focused.navigate(url) : focused;
        });
      }
    }
    return self.clients.openWindow ? self.clients.openWindow(url) : undefined;
  }));
});
