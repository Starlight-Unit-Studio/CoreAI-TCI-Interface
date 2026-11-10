/* CoreAI: TCI Interface 0.6.86: Settings > Konto, modeled on the ChatGPT account page.
   Allgemein: Name (dialog), Benutzername (dialog "Benutzernamen bearbeiten", @handle unique on this
   server), E-Mail (shown, an Inhaber or Admin changes it in Admin Core), Plan (sub page with the
   account's rules and the four templates Free, Go, Plus and Pro, no prices) and Erstellerprofil
   (planned, see the Workspace package in ROADMAP.md).
   Data: api/profile.php?view=account; saves with action display_name and username. */
(function () {
  'use strict';

  var view = document.getElementById('view-konto');
  if (!view || !document.getElementById('acctMain')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/profile.php';
  var PATTERN = /^[a-z0-9._-]{3,20}$/;
  var state = {account: null, csrf: '', loaded: false, loading: null};

  var ERRORS = {
    invalid_display_name: 'Bitte einen Namen eingeben.',
    username_invalid: 'Verwende 3 bis 20 Kleinbuchstaben, Zahlen, Punkte, Unterstriche oder Bindestriche.',
    username_reserved: 'Dieser Benutzername ist reserviert.',
    username_taken: 'Dieser Benutzername ist schon vergeben.',
    guest_not_allowed: 'Gäste haben keinen Benutzernamen.',
    missing_schema_031: 'Das Update ist noch nicht vollständig eingespielt (Datenbank-Migration 031).',
    missing_schema_004: 'Das Update ist noch nicht vollständig eingespielt (Datenbank-Migration 004).',
    rate_limited: 'Zu viele Änderungen. Bitte später noch einmal.',
    csrf_failed: 'Die Seite war zu lange offen. Bitte neu laden.'
  };
  function errText(err) {
    var code = err && err.message;
    return ERRORS[code] || ('Fehler: ' + (code || 'unbekannt'));
  }
  function status(text, error) {
    var el = $('acctStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }

  function load() {
    if (state.loading) return state.loading;
    state.loading = fetch(API + '?view=account', {credentials: 'include', cache: 'no-store', headers: {'Accept': 'application/json'}})
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP_' + r.status));
          return d;
        });
      })
      .then(function (d) { accept(d); })
      .catch(function (err) { status('Konto nicht erreichbar. ' + errText(err), true); })
      .then(function () { state.loading = null; });
    return state.loading;
  }
  function accept(d) {
    if (d.csrf_token) state.csrf = d.csrf_token;
    if (d.account) state.account = d.account;
    state.loaded = true;
    render();
  }
  function post(body) {
    return fetch(API, {method: 'POST', credentials: 'include', cache: 'no-store',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CoreUI-CSRF': state.csrf},
      body: JSON.stringify(body)}).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (d.csrf_token) state.csrf = d.csrf_token;
        if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP_' + r.status));
        return d;
      });
    });
  }

  // --- Overview -----------------------------------------------------------------------------------
  function render() {
    var a = state.account;
    if (!a) return;
    $('acctName').textContent = a.display_name || 'Ohne Namen';
    var user = $('acctUsername');
    var userEdit = $('acctUsernameEdit');
    if (a.guest) {
      user.textContent = 'Nicht für Gäste';
      userEdit.hidden = true;
    } else if (!a.username_ready) {
      user.textContent = 'Update fehlt (Migration 031)';
      userEdit.hidden = true;
    } else {
      user.textContent = a.username ? '@' + a.username : 'Nicht festgelegt';
      user.classList.toggle('is-empty', !a.username);
      userEdit.hidden = false;
    }
    $('acctRow-email').hidden = !!a.guest;
    $('acctEmail').textContent = a.email || '';
    var plan = a.plan || {};
    $('acctPlan').textContent = plan.label || '-';
    renderPlan();
  }

  // --- Plan sub page ---------------------------------------------------------------------------------
  function renderPlan() {
    var plan = (state.account && state.account.plan) || {};
    $('acctPlanName').textContent = plan.label || '-';
    $('acctPlanNote').textContent = plan.note || '';
    var list = $('acctPlanFeatures');
    list.textContent = '';
    (plan.features || []).forEach(function (text) {
      var li = document.createElement('li');
      li.textContent = text;
      list.appendChild(li);
    });
    var usage = $('acctPlanUsage');
    usage.hidden = plan.daily_messages == null;
    usage.textContent = plan.daily_messages != null
      ? 'Heute gesendet: ' + (plan.used_today || 0) + ' von ' + plan.daily_messages + ' Nachrichten. Das Tageslimit zählt 24 Stunden ab der ersten Nachricht.'
      : '';
    var off = $('acctPlanServerOff');
    off.hidden = !(plan.server_off && plan.server_off.length);
    off.textContent = off.hidden ? '' : 'Auf diesem Server gerade für alle aus: ' + plan.server_off.join(', ') + '.';
    var cards = $('acctPlans');
    cards.textContent = '';
    (plan.plans || []).forEach(function (def) {
      var card = document.createElement('section');
      var mine = plan.key === def.key;
      card.className = 'settings-panel cfg-card acct-plan-card' + (mine ? ' is-current' : '');
      card.setAttribute('data-plan', def.key);
      var head = document.createElement('div');
      head.className = 'acct-plan-head';
      var name = document.createElement('strong');
      name.textContent = def.label;
      head.appendChild(name);
      if (mine) {
        var badge = document.createElement('span');
        badge.className = 'acct-plan-badge';
        badge.textContent = plan.customized ? 'DEIN PLAN (ANGEPASST)' : 'DEIN PLAN';
        head.appendChild(badge);
      }
      card.appendChild(head);
      var tag = document.createElement('p');
      tag.textContent = def.tagline || '';
      card.appendChild(tag);
      var ul = document.createElement('ul');
      ul.className = 'acct-features';
      (def.features || []).forEach(function (text) {
        var li = document.createElement('li');
        li.textContent = text;
        ul.appendChild(li);
      });
      card.appendChild(ul);
      cards.appendChild(card);
    });
  }
  function openPlan() {
    $('acctMain').hidden = true;
    $('acctSub-plan').hidden = false;
    status('');
    renderPlan();
    var sub = $('acctSub-plan');
    if (sub.scrollIntoView) sub.scrollIntoView({block: 'start'});
  }
  function closePlan() {
    $('acctSub-plan').hidden = true;
    $('acctMain').hidden = false;
  }

  // --- Dialogs ------------------------------------------------------------------------------------------
  var openDialog = null;
  var lastFocus = null;
  function showDialog(id, input) {
    lastFocus = document.activeElement;
    openDialog = $(id);
    openDialog.hidden = false;
    window.setTimeout(function () { input.focus(); input.select(); }, 0);
  }
  function hideDialog() {
    if (!openDialog) return;
    openDialog.hidden = true;
    openDialog = null;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function nameValue() { return $('acctNameInput').value.replace(/\s+/g, ' ').trim(); }
  function checkName() {
    var value = nameValue();
    $('acctNameSave').disabled = value === '' || value === ((state.account && state.account.display_name) || '');
  }
  function openName() {
    if (!state.account) return;
    $('acctNameInput').value = state.account.display_name || '';
    $('acctNameError').textContent = '';
    checkName();
    showDialog('acctNameDialog', $('acctNameInput'));
  }
  function saveName() {
    if ($('acctNameSave').disabled) return;
    $('acctNameSave').disabled = true;
    post({action: 'display_name', display_name: nameValue()}).then(function (d) {
      accept(d);
      hideDialog();
      status('Name gespeichert.');
      // Profil and the profile overview show the new name at once (js/settings.js).
      try { document.dispatchEvent(new CustomEvent('coreai:profile-saved', {detail: d.profile || {}})); } catch (e) {}
    }).catch(function (err) {
      $('acctNameError').textContent = errText(err);
      checkName();
    });
  }

  function usernameValue() {
    var value = $('acctUsernameInput').value.trim().toLowerCase();
    return value.charAt(0) === '@' ? value.slice(1) : value;
  }
  function checkUsername() {
    var value = usernameValue();
    var valid = PATTERN.test(value);
    var same = value === ((state.account && state.account.username) || '');
    $('acctUsernameSave').disabled = !valid || same;
    $('acctUsernameError').textContent = value !== '' && !valid ? ERRORS.username_invalid : '';
  }
  function openUsername() {
    if (!state.account || state.account.guest) return;
    $('acctUsernameInput').value = state.account.username || state.account.username_suggestion || '';
    $('acctUsernameError').textContent = '';
    checkUsername();
    showDialog('acctUsernameDialog', $('acctUsernameInput'));
  }
  function saveUsername() {
    if ($('acctUsernameSave').disabled) return;
    $('acctUsernameSave').disabled = true;
    post({action: 'username', username: usernameValue()}).then(function (d) {
      accept(d);
      hideDialog();
      status('Benutzername gespeichert: @' + (state.account.username || ''));
      try { document.dispatchEvent(new CustomEvent('coreai:username', {detail: {username: state.account.username}})); } catch (e) {}
    }).catch(function (err) {
      $('acctUsernameError').textContent = errText(err);
      $('acctUsernameSave').disabled = false;
    });
  }

  $('acctNameEdit').addEventListener('click', openName);
  $('acctUsernameEdit').addEventListener('click', openUsername);
  $('acctPlanOpen').addEventListener('click', openPlan);
  $('acctPlanBack').addEventListener('click', closePlan);
  $('acctNameInput').addEventListener('input', checkName);
  $('acctNameInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') saveName(); });
  $('acctNameSave').addEventListener('click', saveName);
  $('acctUsernameInput').addEventListener('input', function () {
    var input = $('acctUsernameInput');
    var lower = input.value.toLowerCase();
    if (lower !== input.value) input.value = lower;
    checkUsername();
  });
  $('acctUsernameInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') saveUsername(); });
  $('acctUsernameSave').addEventListener('click', saveUsername);
  Array.prototype.forEach.call(view.querySelectorAll('[data-acct-close]'), function (btn) {
    btn.addEventListener('click', hideDialog);
  });
  Array.prototype.forEach.call(view.querySelectorAll('.acct-dialog'), function (dialog) {
    dialog.addEventListener('click', function (e) { if (e.target === dialog) hideDialog(); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openDialog) hideDialog(); });

  // A name saved under Profil shows up here too (js/settings.js).
  document.addEventListener('coreai:profile', function (event) {
    var p = event && event.detail ? event.detail : null;
    if (!p || !state.account || !p.display_name) return;
    state.account.display_name = p.display_name;
    render();
  });
  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'konto') { closePlan(); load(); }
  });
  if (!view.hidden) load();
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (!state.loaded && tabs && typeof tabs.current === 'function' && tabs.current() === 'konto') load();

  window.CoreAIAccountSettings = {load: load, openPlan: openPlan};
})();
