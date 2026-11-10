/* CoreAI 0.6.96: owner-only uninstall card in Admin Core > Operations.
 * The UI queues a fixed archive request; only root's systemd runner may stop
 * the CoreAI stack. No path, command or data-purge option reaches the API.
 */
(function () {
  'use strict';
  var card = document.getElementById('adminUninstallCard');
  var form = document.getElementById('adminUninstallForm');
  var password = document.getElementById('adminUninstallPassword');
  var phrase = document.getElementById('adminUninstallPhrase');
  var button = document.getElementById('adminUninstallButton');
  var status = document.getElementById('adminUninstallStatus');
  if (!card || !form || !password || !phrase || !button || !status) return;
  var apiUrl = new URL('../api/admin.php', document.baseURI);
  var csrf = '';
  var ready = false;
  function message(value, error) {
    status.textContent = value;
    status.classList.toggle('error', !!error);
  }
  function request(action, body) {
    var u = new URL(apiUrl.href);
    u.searchParams.set('action', action);
    var config = {credentials: 'include', cache: 'no-store', headers: {Accept: 'application/json'}};
    if (body) {
      config.method = 'POST';
      config.headers['Content-Type'] = 'application/json';
      config.headers['X-CoreUI-CSRF'] = csrf;
      config.body = JSON.stringify(body);
    }
    return fetch(u.href, config).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (result) {
        if (!response.ok || !result.ok) throw new Error(result.error || ('HTTP_' + response.status));
        return result;
      });
    });
  }
  request('uninstall_status').then(function (result) {
    if (result.owner !== true) return;
    card.hidden = false;
    csrf = result.csrf_token || '';
    ready = !!result.available && !!csrf && !result.queued;
    button.disabled = !ready;
    if (!result.available) message('Der Host-Deinstaller ist noch nicht eingerichtet. Nutze bei Bedarf den SSH-One-Liner.', true);
    else if (result.queued) message('Die Deinstallation wurde bereits angefordert. Der Host-Dienst wird sie bearbeiten.');
    else message('Bereit. Die Daten werden in ein privates Archiv verschoben, nicht gelöscht.');
  }).catch(function () { /* Remain hidden for non-owners or unavailable API. */ });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!ready || !csrf) return;
    if (phrase.value !== 'COREAI ENTFERNEN' || !password.value) {
      message('Bitte das eigene Passwort und COREAI ENTFERNEN exakt eingeben.', true);
      return;
    }
    if (!window.confirm('CoreAI und alle zugehörigen Dienste werden beendet. Daten bleiben im privaten Archiv unter /opt/ erhalten. Wirklich deinstallieren?')) return;
    var secret = password.value;
    password.value = '';
    button.disabled = true;
    message('Anfrage wird übermittelt.');
    request('uninstall_request', {password: secret, confirmation: phrase.value}).then(function () {
      ready = false;
      phrase.value = '';
      message('Deinstallation angefordert. Der Webzugang endet, sobald der Host-Dienst CoreAI stoppt. Daten werden archiviert.');
    }).catch(function (error) {
      button.disabled = false;
      message('Anfrage abgelehnt: ' + error.message, true);
    });
  });
})();
