/* Project STΛRLIɢHT: CoreAI: TCI Interface settings controller. */
(function () {
  'use strict';

  var preferences = window.CoreUIPreferences;
  var themeOptions = Array.prototype.slice.call(document.querySelectorAll('input[name="coreuiTheme"]'));
  var fields = {
    compactFeed: document.getElementById('prefCompactFeed'),
    thinkingExpanded: document.getElementById('prefThinkingExpanded'),
    liveFrameMotion: document.getElementById('prefLiveFrameMotion'),
    showLiveBrowser: document.getElementById('prefShowLiveBrowser'),
    showTelemetry: document.getElementById('prefShowTelemetry')
  };
  var appearanceFields = {
    motif: document.getElementById('prefMotif'),
    accent: document.getElementById('prefAccent'),
    fontFamily: document.getElementById('prefFontFamily'),
    uiFontSize: document.getElementById('prefUiFont'),
    contentFontSize: document.getElementById('prefContentFont'),
    codeFontSize: document.getElementById('prefCodeFont'),
    contrast: document.getElementById('prefContrast'),
    motionMode: document.getElementById('prefMotionMode'),
    diffMarkers: document.getElementById('prefDiffMarkers'),
    fullWidth: document.getElementById('prefFullWidth'),
    separateModes: document.getElementById('prefSeparateModes'),
    smartPunctuation: document.getElementById('prefSmartPunctuation'),
    background: document.getElementById('prefBackground'),
    foreground: document.getElementById('prefForeground')
  };
  // 0.6.80: hex text beside each color picker, the Hell/Dunkel edit target, motif transfer.
  var appearanceHex = {
    background: document.getElementById('prefBackgroundHex'),
    foreground: document.getElementById('prefForegroundHex')
  };
  var APPEARANCE_CHECKBOXES = ['fullWidth', 'separateModes', 'smartPunctuation'];
  var APPEARANCE_NUMBERS = ['uiFontSize', 'contentFontSize', 'codeFontSize', 'contrast'];
  var APPEARANCE_MODE_KEYS = ['motif', 'accent', 'fontFamily'];
  var editModeRow = document.getElementById('prefEditModeRow');
  var editModeGroup = document.getElementById('prefEditMode');
  var appearanceEditMode = null;
  var motifExportButton = document.getElementById('motifExport');
  var motifCopyButton = document.getElementById('motifCopy');
  var motifImportButton = document.getElementById('motifImport');
  var motifImportPanel = document.getElementById('motifImportPanel');
  var motifImportText = document.getElementById('motifImportText');
  var motifImportFile = document.getElementById('motifImportFile');
  var motifImportChoose = document.getElementById('motifImportChoose');
  var motifImportCancel = document.getElementById('motifImportCancel');
  var motifImportApply = document.getElementById('motifImportApply');
  var motifImportStatus = document.getElementById('motifImportStatus');
  var contrastOutput = document.getElementById('prefContrastValue');
  var saveState = document.getElementById('settingsSaveState');
  var accountMail = document.getElementById('accountMail');
  var accountDisplayName = document.getElementById('accountDisplayName');
  var accountAvatar = document.getElementById('accountAvatar');
  var accountLevel = document.getElementById('accountLevel');
  var adminLinkGroup = document.getElementById('adminLinkGroup');
  var adminShortcut = document.getElementById('settingsAdminShortcut');
  var healthSummary = document.getElementById('healthSummary');
  var healthVersion = document.getElementById('healthVersion');
  var healthModel = document.getElementById('healthModel');
  var healthChecks = document.getElementById('healthChecks');
  var refreshButton = document.getElementById('refreshHealth');
  var resetButton = document.getElementById('resetPreferences');
  var generalSendKey = document.getElementById('generalSendKey');
  var generalUiLanguage = document.getElementById('generalUiLanguage');
  var generalHigherIntelligence = document.getElementById('generalHigherIntelligence');
  var generalHigherNote = document.getElementById('generalHigherNote');
  var generalReasoningLevels = document.getElementById('generalReasoningLevels');
  var generalReasoningNote = document.getElementById('generalReasoningNote');
  var logoutButton = document.getElementById('settingsLogout');
  var backButton = document.getElementById('settingsBack');
  var aiSaveButton = document.getElementById('saveAiSettings');
  var memorySettingsSaveButton = document.getElementById('saveMemoryAiSettings');
  var aiSystemPrompt = document.getElementById('aiSystemPrompt');
  var aiPromptCounter = document.getElementById('aiPromptCounter');
  var aiMemoryEnabled = document.getElementById('aiMemoryEnabled');
  var aiMemoryLimit = document.getElementById('aiMemoryLimit');
  var aiNumPredict = document.getElementById('aiNumPredict');
  var aiTemperature = document.getElementById('aiTemperature');
  var aiProvider = document.getElementById('aiProvider');
  var aiLanguage = document.getElementById('aiLanguage');
  var externalNote = document.getElementById('externalProviderNote');
  var memoryFact = document.getElementById('memoryFact');
  var memoryRelevance = document.getElementById('memoryRelevance');
  var memoryList = document.getElementById('memoryList');
  var addMemoryButton = document.getElementById('addMemory');
  var reloadMemoriesButton = document.getElementById('reloadMemories');
  var saveProfileButton = document.getElementById('saveProfile');
  var profileDisplayName = document.getElementById('profileDisplayName');
  var assistantDisplayName = document.getElementById('assistantDisplayName');
  var userAvatarPreview = document.getElementById('userAvatarPreview');
  var assistantAvatarPreview = document.getElementById('assistantAvatarPreview');
  var userAvatarFile = document.getElementById('userAvatarFile');
  var assistantAvatarFile = document.getElementById('assistantAvatarFile');
  var removeUserAvatar = document.getElementById('removeUserAvatar');
  var removeAssistantAvatar = document.getElementById('removeAssistantAvatar');
  var editUserAvatar = document.getElementById('editUserAvatar');
  var editAssistantAvatar = document.getElementById('editAssistantAvatar');
  var avatarMedia = {};
  var avatarCropper = window.CoreUIProfileCrop ? window.CoreUIProfileCrop.create() : null;
  var knowledgeTitleInput = document.getElementById('knowledgeTitleInput');
  var knowledgeFile = document.getElementById('knowledgeFile');
  var knowledgeFileName = document.getElementById('knowledgeFileName');
  var knowledgeDrop = document.querySelector('.knowledge-file-drop');
  var uploadKnowledgeButton = document.getElementById('uploadKnowledge');
  var reloadKnowledgeButton = document.getElementById('reloadKnowledge');
  var knowledgeList = document.getElementById('knowledgeList');
  var knowledgeSourceCount = document.getElementById('knowledgeSourceCount');
  var knowledgeChunkCount = document.getElementById('knowledgeChunkCount');
  var knowledgeGlobalCount = document.getElementById('knowledgeGlobalCount');
  var currentPassword = document.getElementById('currentPassword');
  var newPassword = document.getElementById('newPassword');
  var confirmPassword = document.getElementById('confirmPassword');
  var changePasswordButton = document.getElementById('changePassword');
  var loginSessionList = document.getElementById('loginSessionList');
  var revokeOtherSessionsButton = document.getElementById('revokeOtherSessions');
  var exportAccountDataButton = document.getElementById('exportAccountData');
  // 0.5.79: the start view sums up the categories.
  var start = {
    name: document.getElementById('startName'),
    mail: document.getElementById('startMail'),
    role: document.getElementById('startRole'),
    avatar: document.getElementById('startAvatar'),
    core: document.getElementById('startCore'),
    thinking: document.getElementById('startThinking'),
    health: document.getElementById('startHealth'),
    version: document.getElementById('startVersion'),
    knowledge: document.getElementById('startKnowledge'),
    knowledgeChunks: document.getElementById('startKnowledgeChunks'),
    memory: document.getElementById('startMemory')
  };
  var saveTimer = 0;
  var csrfToken = '';
  var promptMax = 6000;
  var selectedKnowledgeFile = null;
  var selectedModelOverride = '';
  var defaultModel = '';
  var lastLoadedAiSettings = null;
  var ctxField = document.getElementById('aiCtxField');
  var contextSlider = ctxField && window.CoreUIContextSlider ? window.CoreUIContextSlider.create({
    root: ctxField,
    range: document.getElementById('aiNumCtx'),
    output: document.getElementById('aiNumCtxValue'),
    useDefault: document.getElementById('aiCtxDefault'),
    stops: document.getElementById('aiCtxStops'),
    estimate: document.getElementById('aiCtxEstimate'),
    warning: document.getElementById('aiCtxWarning'),
    status: document.getElementById('aiCtxStatus'),
    fetchJson: function (url) { return api(url); }
  }) : null;

  function api(url, options) {
    options = options || {};
    options.credentials = 'include';
    options.cache = 'no-store';
    options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method === 'POST') {
      options.headers['X-CoreUI-CSRF'] = csrfToken;
      if (!(options.body instanceof FormData)) options.headers['Content-Type'] = 'application/json';
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data.ok) {
          var err = new Error(data.error || ('HTTP_' + response.status));
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  // 0.6.80: a segment (role=radiogroup with radios) reads and writes like a select.
  function isSegment(el) { return !!(el && el.getAttribute && el.getAttribute('role') === 'radiogroup'); }
  function readField(el) {
    if (!isSegment(el)) return el.value;
    var checked = el.querySelector('input:checked');
    return checked ? checked.value : '';
  }
  function writeField(el, value) {
    if (!isSegment(el)) { el.value = String(value); return; }
    Array.prototype.forEach.call(el.querySelectorAll('input'), function (input) {
      input.checked = input.value === String(value);
    });
  }
  function shownMode(theme) {
    if (preferences && typeof preferences.resolvedTheme === 'function') return preferences.resolvedTheme(theme || 'system');
    return document.documentElement.getAttribute('data-coreui-theme') === 'dark' ? 'dark' : 'light';
  }
  // Which mode the Motiv and color rows edit: the one on screen, or Hell/Dunkel when kept apart.
  function appearanceTarget(value) {
    return value.separateModes && appearanceEditMode ? appearanceEditMode : shownMode(value.theme);
  }
  function parseHex(text) {
    var raw = String(text || '').trim().replace(/^#/, '');
    if (/^[0-9a-fA-F]{3}$/.test(raw)) raw = raw.split('').map(function (c) { return c + c; }).join('');
    return /^[0-9a-fA-F]{6}$/.test(raw) ? '#' + raw.toLowerCase() : null;
  }
  function setColorField(name, hex) {
    if (appearanceFields[name]) appearanceFields[name].value = hex;
    if (appearanceHex[name]) {
      appearanceHex[name].value = hex.toUpperCase();
      appearanceHex[name].removeAttribute('aria-invalid');
    }
  }

  function syncFields(value) {
    value = value || {};
    if (generalSendKey) generalSendKey.value = value.sendKey === 'mod-enter' ? 'mod-enter' : 'enter';
    if (generalUiLanguage) generalUiLanguage.value = ['auto','de','en'].indexOf(value.uiLanguage) >= 0
      ? value.uiLanguage : 'auto';
    Object.keys(fields).forEach(function (key) {
      if (fields[key]) fields[key].checked = value[key] === true;
    });
    var selectedTheme = value.theme || 'system';
    themeOptions.forEach(function (option) { option.checked = option.value === selectedTheme; });
    var target = appearanceTarget(value);
    var suffix = value.separateModes && target === 'dark' ? 'Dark' : '';
    Object.keys(appearanceFields).forEach(function (key) {
      var el = appearanceFields[key];
      if (!el || key === 'background' || key === 'foreground') return;
      if (APPEARANCE_CHECKBOXES.indexOf(key) >= 0) el.checked = value[key] === true;
      else if (APPEARANCE_MODE_KEYS.indexOf(key) >= 0) {
        if (value[key + suffix] !== undefined) writeField(el, value[key + suffix]);
      } else if (value[key] !== undefined) writeField(el, value[key]);
    });
    var dark = target === 'dark';
    setColorField('background', dark ? (value.backgroundDark || '#0c1427') : (value.backgroundLight || '#f0f4fa'));
    setColorField('foreground', dark ? (value.foregroundDark || '#eef4ff') : (value.foregroundLight || '#101626'));
    if (editModeRow) editModeRow.hidden = value.separateModes !== true;
    if (editModeGroup) writeField(editModeGroup, target);
    if (contrastOutput) contrastOutput.textContent = String(value.contrast || 60);
  }

  function collect() {
    var value = {};
    Object.keys(fields).forEach(function (key) {
      value[key] = fields[key] ? fields[key].checked : false;
    });
    var selected = themeOptions.filter(function (option) { return option.checked; })[0];
    value.theme = selected ? selected.value : (preferences ? preferences.load().theme : 'system');
    value.sendKey = generalSendKey ? generalSendKey.value
      : (preferences ? preferences.load().sendKey : 'enter');
    value.uiLanguage = generalUiLanguage ? generalUiLanguage.value
      : (preferences ? preferences.load().uiLanguage : 'auto');
    // Local appearance preferences preserve the inactive theme's palette and look.
    var previous = preferences ? preferences.load() : {};
    ['motif', 'accent', 'fontFamily', 'motifDark', 'accentDark', 'fontFamilyDark'].forEach(function (key) {
      value[key] = previous[key];
    });
    Object.keys(appearanceFields).forEach(function (key) {
      var el = appearanceFields[key];
      if (!el || key === 'background' || key === 'foreground' || APPEARANCE_MODE_KEYS.indexOf(key) >= 0) return;
      value[key] = APPEARANCE_CHECKBOXES.indexOf(key) >= 0 ? el.checked
        : APPEARANCE_NUMBERS.indexOf(key) >= 0 ? Number(readField(el)) : readField(el);
    });
    var mode = appearanceTarget(value);
    var suffix = value.separateModes && mode === 'dark' ? 'Dark' : '';
    APPEARANCE_MODE_KEYS.forEach(function (key) {
      if (appearanceFields[key]) value[key + suffix] = readField(appearanceFields[key]);
    });
    value.backgroundLight = previous.backgroundLight;
    value.backgroundDark = previous.backgroundDark;
    value.foregroundLight = previous.foregroundLight;
    value.foregroundDark = previous.foregroundDark;
    if (appearanceFields.background) value[mode === 'dark' ? 'backgroundDark' : 'backgroundLight'] = appearanceFields.background.value;
    if (appearanceFields.foreground) value[mode === 'dark' ? 'foregroundDark' : 'foregroundLight'] = appearanceFields.foreground.value;
    return value;
  }

  function announceSaved(message, isError) {
    if (!saveState) return;
    saveState.textContent = message || 'GESPEICHERT';
    saveState.classList.toggle('error', isError === true);
    saveState.classList.add('visible');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { saveState.classList.remove('visible'); }, 2600);
  }

  function startText(key, text) {
    if (start[key]) start[key].textContent = text;
  }

  function count(value, one, many) {
    var n = Number(value) || 0;
    return n + ' ' + (n === 1 ? one : many);
  }

  function saveLocal() {
    if (!preferences) return;
    var stored = preferences.save(collect());
    syncFields(stored);
    announceSaved('LOKAL GESPEICHERT');
  }

  function statusLabel(ok) {
    return ok ? 'BEREIT' : 'FEHLER';
  }

  function renderChecks(checks) {
    if (!healthChecks) return;
    healthChecks.innerHTML = '';
    var names = {
      database: 'COREAI: TCI INTERFACE-DATENBANK',
      schema: 'DATENSCHEMA',
      ollama: 'OLLAMA-KERN',
      storage: 'RUNTIME-SPEICHER'
    };
    Object.keys(names).forEach(function (key) {
      var ok = checks && checks[key] === true;
      var item = document.createElement('div');
      item.className = 'health-node ' + (ok ? 'ok' : 'error');
      var light = document.createElement('span');
      light.className = 'health-node-light';
      light.setAttribute('aria-hidden', 'true');
      var name = document.createElement('span');
      name.className = 'health-node-name';
      name.textContent = names[key];
      var result = document.createElement('strong');
      result.textContent = statusLabel(ok);
      item.appendChild(light);
      item.appendChild(name);
      item.appendChild(result);
      healthChecks.appendChild(item);
    });
  }

  function loadHealth() {
    if (refreshButton) refreshButton.disabled = true;
    if (healthSummary) {
      healthSummary.className = 'health-summary checking';
      healthSummary.textContent = 'KERNSTATUS WIRD GEPRÜFT...';
    }
    return fetch('./api/health.php', { credentials: 'include', cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (healthSummary) {
          healthSummary.className = 'health-summary ' + (data.ok ? 'ok' : 'error');
          healthSummary.textContent = data.ok ? 'ALLE KERNMODULE BEREIT' : 'KERNPRÜFUNG MIT ABWEICHUNGEN';
        }
        if (healthVersion) healthVersion.textContent = data.version || 'UNBEKANNT';
        if (healthModel) healthModel.textContent = data.model || 'NICHT GEMELDET';
        renderChecks(data.checks || {});
        startText('health', data.ok ? 'Alles bereit' : 'Mit Abweichungen');
        if (start.health) start.health.setAttribute('data-state', data.ok ? 'ok' : 'error');
        startText('version', 'Version ' + (data.version || 'unbekannt'));
      })
      .catch(function () {
        if (healthSummary) {
          healthSummary.className = 'health-summary error';
          healthSummary.textContent = 'HEALTH-ENDPUNKT NICHT ERREICHBAR';
        }
        renderChecks({});
        startText('health', 'Nicht erreichbar');
        if (start.health) start.health.setAttribute('data-state', 'error');
      })
      .finally(function () { if (refreshButton) refreshButton.disabled = false; });
  }

  function loadAccount() {
    return fetch('./api/auth.php?action=me', { credentials: 'include', cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          location.replace('login.html');
          return;
        }
        csrfToken = data.csrf_token || csrfToken;
        if (accountMail) accountMail.textContent = data.username || 'NICHT GEMELDET';
        if (accountDisplayName) accountDisplayName.textContent = (data.display_name || 'ANGEMELDETER OPERATOR').toUpperCase();
        setAvatarPreview(accountAvatar, data.avatars && data.avatars.user, data.display_name || data.username || 'OP');
        // 0.5.79: CoreAI roles (Inhaber, Admin, Nutzer, Gast) instead of level numbers.
        var lvl = Number(data.permission_level == null ? 4 : data.permission_level);
        var role = data.is_guest ? 'GAST' : (lvl === 0 ? 'INHABER' : (lvl === 1 ? 'ADMIN' : 'NUTZER'));
        if (accountLevel) accountLevel.textContent = role;
        var mayAdmin = !data.is_guest && (lvl === 0 || lvl === 1);
        // Both shortcuts are invisible until this authenticated role check.
        // Server-side authorization on admin/index.html and api/admin.php
        // remains mandatory regardless of DOM state.
        if (adminShortcut) adminShortcut.hidden = !mayAdmin;
        if (adminLinkGroup) {
          adminLinkGroup.dataset.authorized = mayAdmin ? '1' : '0';
          adminLinkGroup.hidden = !mayAdmin;
          var menu = window.CoreAISettingsMenu && window.CoreAISettingsMenu.instance;
          if (menu) menu.refresh();
        }
        // Preserve existing bookmarks from the former embedded Admin Core.
        var oldAdmin = String(location.hash || '').replace(/^#/, '');
        if (mayAdmin && (/^admin-/.test(oldAdmin) ||
          /^(overview|users|runtime|memories|lore|logs|audit)$/.test(oldAdmin))) {
          location.replace('./admin/index.html#' + encodeURIComponent(oldAdmin));
          return;
        }
        startText('role', role);
        startText('mail', data.username || '');
        startText('name', data.display_name || data.username || 'Operator');
        setAvatarPreview(start.avatar, data.avatars && data.avatars.user, data.display_name || data.username || 'OP');
      })
      .catch(function () {
        if (accountMail) accountMail.textContent = 'STATUS NICHT ERREICHBAR';
      });
  }

  function initials(value, fallback) {
    var clean = String(value || '').trim();
    if (!clean) return fallback || 'OP';
    var parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length > 1) return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    return clean.substring(0, 2).toUpperCase();
  }

  function setAvatarPreview(element, url, name) {
    if (!element) return;
    element.textContent = initials(name, element === assistantAvatarPreview ? 'EM' : 'OP');
    element.classList.toggle('has-image', !!url);
    element.style.backgroundImage = url ? ('url("' + String(url).replace(/"/g, '%22') + '")') : '';
  }

  function applyProfile(profile) {
    profile = profile || {};
    if (profileDisplayName) profileDisplayName.value = profile.display_name || '';
    if (assistantDisplayName) assistantDisplayName.value = profile.assistant_name || 'Mara';
    var avatars = profile.avatars || {};
    avatarMedia = profile.media || {};
    if (editUserAvatar) editUserAvatar.hidden = !avatarMedia.user;
    if (editAssistantAvatar) editAssistantAvatar.hidden = !avatarMedia.assistant;
    setAvatarPreview(userAvatarPreview, avatars.user, profile.display_name || 'Operator');
    setAvatarPreview(assistantAvatarPreview, avatars.assistant, profile.assistant_name || 'Mara');
    setAvatarPreview(accountAvatar, avatars.user, profile.display_name || 'Operator');
    setAvatarPreview(start.avatar, avatars.user, profile.display_name || 'Operator');
    if (profile.display_name) startText('name', profile.display_name);
    if (accountDisplayName) accountDisplayName.textContent = (profile.display_name || 'ANGEMELDETER OPERATOR').toUpperCase();
    // 0.6.84: the profile overview head follows a saved name or picture (js/coreui-profile-stats.js).
    try { document.dispatchEvent(new CustomEvent('coreai:profile', {detail: profile})); } catch (e) { /* old browser */ }
  }

  function loadProfile() {
    return api('./api/profile.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      applyProfile(data.profile || {});
    }).catch(function (error) {
      announceSaved(error.message === 'missing_schema_004' ? 'MIGRATION 004 FEHLT' : 'PROFILE NICHT ERREICHBAR', true);
    });
  }

  function saveProfile() {
    if (!saveProfileButton) return;
    saveProfileButton.disabled = true;
    api('./api/profile.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'save',
        display_name: profileDisplayName ? profileDisplayName.value : '',
        assistant_name: assistantDisplayName ? assistantDisplayName.value : 'Mara'
      })
    }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved('PROFILE SERVERSEITIG GESPEICHERT');
    }).catch(function (error) {
      announceSaved('PROFILFEHLER: ' + error.message, true);
    }).finally(function () { saveProfileButton.disabled = false; });
  }

  function uploadAvatar(slot, file, crop) {
    if (!file) return Promise.reject(new Error('no_file_uploaded'));
    var form = new FormData();
    form.append('slot', slot);
    form.append('file', file, file.name || 'avatar');
    form.append('crop', JSON.stringify(crop));
    announceSaved('ORIGINALBILD UND ZUSCHNITT WERDEN GESPEICHERT');
    return api('./api/profile.php', { method: 'POST', body: form }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved(slot === 'assistant' ? 'COREAI-REFERENZBILD GESPEICHERT' : 'BENUTZERBILD GESPEICHERT');
      return data;
    }).catch(function (error) {
      announceSaved('BILDUPLOAD FEHLGESCHLAGEN: ' + error.message, true);
      throw error;
    });
  }

  function recropAvatar(slot, crop, sourceUuid) {
    announceSaved('AUSSCHNITT WIRD NEU ERSTELLT');
    return api('./api/profile.php', {
      method: 'POST',
      body: JSON.stringify({action:'avatar_crop', slot:slot, source_uuid:sourceUuid, crop:crop})
    }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved('ZUSCHNITT ERNEUT GESPEICHERT');
      return data;
    }).catch(function (error) {
      announceSaved('ZUSCHNITT FEHLGESCHLAGEN: ' + error.message, true);
      throw error;
    });
  }

  function openAvatarCrop(slot, file) {
    if (!avatarCropper) {
      announceSaved('ZUSCHNITT-FENSTER NICHT VERFÜGBAR', true);
      return;
    }
    var previous = !file && avatarMedia[slot] ? avatarMedia[slot] : null;
    if (!file && !previous) return;
    if (file && (!['image/png','image/jpeg'].includes(file.type) || file.size > 4 * 1024 * 1024)) {
      announceSaved('NUR PNG/JPG BIS 4 MB ERLAUBT', true);
      return;
    }
    var opened = avatarCropper.open({
      slot: slot,
      file: file || null,
      url: previous ? previous.source_url : '',
      crop: previous ? previous.crop : null,
      onConfirm: function (crop) {
        return file ? uploadAvatar(slot, file, crop) : recropAvatar(slot, crop, previous.uuid);
      }
    });
    if (!opened) announceSaved('ZUSCHNITT-FENSTER KONNTE NICHT GEÖFFNET WERDEN', true);
  }

  function deleteAvatar(slot) {
    if (!window.confirm((slot === 'assistant' ? 'Das CoreAI-Bild' : 'Dein Profilbild') + ' wirklich entfernen?')) return;
    api('./api/profile.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'avatar_delete', slot: slot })
    }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved('PROFILBILD ENTFERNT');
    }).catch(function (error) { announceSaved('ENTFERNEN FEHLGESCHLAGEN: ' + error.message, true); });
  }

  function updatePromptCounter() {
    if (!aiPromptCounter || !aiSystemPrompt) return;
    aiPromptCounter.textContent = String(aiSystemPrompt.value.length) + ' / ' + String(promptMax);
  }

  // Account-wide Core controls are independent of the KI-Profil Save button.
  // Named effort values are offered only when /api/show advertises them.
  function loadGeneralControls() {
    if (!generalHigherIntelligence && !generalReasoningLevels) return Promise.resolve();
    return api('./api/user_settings.php?general=1').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      var ready = data.available === true;
      var general = data.general || {};
      var caps = data.reasoning || {};
      var names = Array.isArray(caps.levels) ? caps.levels : [];
      var canAuto = ready && data.auto_core_allowed === true;
      if (generalHigherIntelligence) {
        generalHigherIntelligence.checked = general.auto_higher_core === true;
        generalHigherIntelligence.disabled = !canAuto;
      }
      if (generalHigherNote) generalHigherNote.textContent =
        !ready ? 'Migration 027 fehlt. Bitte zuerst CoreAI aktualisieren.'
        : !data.auto_core_allowed ? 'Gäste können keinen automatischen Core-Wechsel auslösen.'
        : 'Bei komplexen reinen Textaufgaben wird eine bereits installierte, höhere Mara-Core-Stufe verwendet, falls vorhanden. Deine manuelle Auswahl bleibt erhalten.';
      if (generalReasoningLevels) {
        var previous = general.thinking_level || '';
        generalReasoningLevels.textContent = '';
        function choice(value, label, inactive) {
          var o = document.createElement('option');
          o.value = value;
          o.textContent = label;
          o.disabled = inactive === true;
          generalReasoningLevels.appendChild(o);
        }
        choice('', 'Core-Standard (Denken an/aus)');
        [['low','Gering'],['medium','Mittel'],['high','Hoch'],['max','Max']].forEach(function (pair) {
          if (names.indexOf(pair[0]) !== -1) choice(pair[0], pair[1]);
        });
        if (previous !== '' && names.indexOf(previous) === -1) {
          choice(previous, previous + ' (mit diesem Core nicht verfügbar)', true);
        }
        generalReasoningLevels.value = previous;
        // Even a boolean-only Core can reset a previously selected level
        // to Core-Standard without faking unsupported choices.
        generalReasoningLevels.disabled = !ready;
        if (generalReasoningNote) generalReasoningNote.textContent =
          !ready ? 'Migration 027 fehlt. Bitte zuerst CoreAI aktualisieren.'
          : names.length === 0
          ? 'Der aktuelle Core bietet keine benannten Aufwandsstufen. Der vorhandene Denken-Schalter im Chat bleibt voll funktionsfähig.'
          : 'Verfügbare Aufwandsstufen für ' + String(data.core_label || data.model || 'deinen Core') +
            '. Denken aus im Chat hat immer Vorrang. Es werden keine unbekannten Stufen an Ollama gesendet.';
      }
    }).catch(function (error) {
      if (generalHigherNote) generalHigherNote.textContent = 'Core-Steuerung nicht erreichbar: ' + error.message;
      if (generalReasoningNote) generalReasoningNote.textContent = 'Reasoning-Fähigkeiten nicht erreichbar.';
      if (generalHigherIntelligence) generalHigherIntelligence.disabled = true;
      if (generalReasoningLevels) generalReasoningLevels.disabled = true;
    });
  }

  function saveGeneralControl(setting, value, element) {
    if (!element || element.disabled) return;
    element.disabled = true;
    api('./api/user_settings.php', {
      method: 'POST', body: JSON.stringify({action: 'general', settings: {[setting]: value}})
    }).then(function () {
      announceSaved('ALLGEMEIN SERVERSEITIG GESPEICHERT');
    }).catch(function (error) {
      var label = error.message === 'thinking_level_not_supported'
        ? 'DIESE REASONING-STUFE UNTERSTÜTZT DEIN CORE NICHT'
        : error.message === 'missing_schema_027'
          ? 'MIGRATION 027 FEHLT'
          : 'ALLGEMEIN NICHT GESPEICHERT: ' + error.message;
      announceSaved(label, true);
    }).finally(function () { loadGeneralControls(); });
  }

  function loadAiSettings() {
    return api('./api/user_settings.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      var s = data.settings || {};
      // Snapshot protects unrelated, unsaved KI-Profil fields when a user
      // changes only Memory on its separate page.
      lastLoadedAiSettings = Object.assign({}, s);
      if (memorySettingsSaveButton) memorySettingsSaveButton.disabled = false;
      promptMax = Number(data.limits && data.limits.system_prompt_chars) || 6000;
      if (aiSystemPrompt) {
        aiSystemPrompt.maxLength = promptMax;
        aiSystemPrompt.value = s.system_prompt || '';
      }
      if (aiMemoryEnabled) aiMemoryEnabled.checked = s.memory_enabled === true;
      var personaMemory = document.getElementById('personaMemoryEnabled');
      if (personaMemory) personaMemory.checked = s.memory_enabled === true;
      if (aiMemoryLimit) aiMemoryLimit.value = String(s.memory_limit == null ? 16 : s.memory_limit);
      if (aiNumPredict) aiNumPredict.value = String(s.num_predict == null ? 6500 : s.num_predict);
      if (aiTemperature) aiTemperature.value = String(s.temperature == null ? 1 : s.temperature);
      // 0.6.10: main language for answers and read-aloud; without migration 024 only German.
      if (aiLanguage) {
        aiLanguage.value = s.main_language === 'en' ? 'en' : 'de';
        aiLanguage.disabled = !!(data.limits && data.limits.language_available === false);
      }
      defaultModel = String(data.default_model || defaultModel || '');
      if (contextSlider) {
        contextSlider.apply(s.num_ctx, data.limits && data.limits.num_ctx_choices,
          !(data.limits && data.limits.num_ctx_available === false));
      }
      // 0.5.79: the Core is chosen in the chat menu; the window estimate follows it.
      selectedModelOverride = s.model_override || '';
      startText('core', String(data.core_label || data.default_label || selectedModelOverride || defaultModel || 'Core')
        .replace(/^TCI(?::\s*|\s+)/, ''));
      startText('thinking', 'Denken ' + (s.thinking_enabled === false ? 'aus' : 'an')
        + (selectedModelOverride ? '' : ' · Standard-Core'));
      var externalConfigured = data.external && data.external.configured === true;
      if (aiProvider) {
        var externalOption = aiProvider.querySelector('option[value="external"]');
        if (externalOption) externalOption.disabled = !externalConfigured;
        aiProvider.value = externalConfigured && s.provider === 'external' ? 'external' : 'local';
      }
      if (externalNote) {
        var p = externalNote.querySelector('p');
        if (p && data.external && data.external.enabled && !externalConfigured) {
          p.textContent = 'Der externe Adapter ist im Adminbereich aktiviert, aber noch nicht vollständig mit HTTPS-Endpunkt, Core und serverseitigem Schlüssel konfiguriert. CoreAI: TCI Interface bleibt deshalb sicher auf lokalem Ollama.';
        }
      }
      updatePromptCounter();
      loadContextEstimate();
    }).catch(function (error) {
      lastLoadedAiSettings = null;
      if (memorySettingsSaveButton) memorySettingsSaveButton.disabled = true;
      announceSaved(
        error.message === 'missing_schema_005' ? 'MIGRATION 005 FEHLT' : 'KI-EINSTELLUNGEN NICHT ERREICHBAR',
        true
      );
    });
  }

  /** #113: memory guide values follow the Core chosen in the chat menu. */
  function loadContextEstimate() {
    if (!contextSlider) return Promise.resolve();
    return contextSlider.load(selectedModelOverride || defaultModel);
  }

  function saveAiSettings(event) {
    if (!aiSaveButton && !memorySettingsSaveButton) return;
    var memoryOnly = event && event.currentTarget === memorySettingsSaveButton;
    if (memoryOnly && !lastLoadedAiSettings) {
      announceSaved('KI-EINSTELLUNGEN SIND NOCH NICHT GELADEN', true);
      return;
    }
    if (aiSaveButton) aiSaveButton.disabled = true;
    if (memorySettingsSaveButton) memorySettingsSaveButton.disabled = true;
    var body = {
      system_prompt: aiSystemPrompt ? aiSystemPrompt.value : '',
      memory_enabled: aiMemoryEnabled ? aiMemoryEnabled.checked : true,
      memory_limit: aiMemoryLimit ? Number(aiMemoryLimit.value) : 16,
      num_predict: aiNumPredict ? Number(aiNumPredict.value) : 6500,
      temperature: aiTemperature ? Number(aiTemperature.value) : 1,
      provider: aiProvider ? aiProvider.value : 'local',
      num_ctx: contextSlider ? contextSlider.value() : null,
      main_language: aiLanguage && aiLanguage.value === 'en' ? 'en' : 'de'
    };
    if (memoryOnly) {
      var old = lastLoadedAiSettings;
      // The existing API accepts a complete AI profile. Supply its last
      // server snapshot for every field unrelated to Memory.
      body.system_prompt = old.system_prompt || '';
      body.num_predict = old.num_predict == null ? 6500 : Number(old.num_predict);
      body.temperature = old.temperature == null ? 1 : Number(old.temperature);
      body.provider = old.provider || 'local';
      body.num_ctx = old.num_ctx == null ? null : Number(old.num_ctx);
      body.main_language = old.main_language === 'en' ? 'en' : 'de';
    }
    api('./api/user_settings.php', { method: 'POST', body: JSON.stringify(body) })
      .then(function () {
        announceSaved('KI- UND MEMORY-EINSTELLUNGEN SERVERSEITIG GESPEICHERT');
        return loadAiSettings();
      })
      .catch(function (error) {
        var labels = {
          model_not_available: 'DER CORE IST NICHT MEHR IN OLLAMA INSTALLIERT',
          model_catalog_unavailable: 'OLLAMA-CORE-LISTE IST NICHT ERREICHBAR',
          model_catalog_invalid_response: 'OLLAMA-CORE-LISTE IST UNGÜLTIG',
          invalid_model_name: 'UNGÜLTIGER CORE-NAME',
          invalid_num_ctx: 'UNGÜLTIGES KONTEXTFENSTER',
          num_ctx_not_allowed: 'KONTEXTFENSTER ÜBER DEM LIMIT DIESES KONTOS',
          guest_core_locked: 'ALS GAST GELTEN STANDARD-CORE UND -KONTEXTFENSTER',
          missing_schema_017: 'MIGRATION 017 FEHLT: KONTEXTFENSTER NICHT GESPEICHERT',
          invalid_language: 'UNBEKANNTE SPRACHE',
          missing_schema_024: 'MIGRATION 024 FEHLT: SPRACHE NICHT GESPEICHERT'
        };
        announceSaved(labels[error.message] || ('SPEICHERN FEHLGESCHLAGEN: ' + error.message), true);
      })
      .finally(function () {
        if (aiSaveButton) aiSaveButton.disabled = false;
        if (memorySettingsSaveButton) memorySettingsSaveButton.disabled = false;
      });
  }

  function formatSessionDate(value) {
    if (!value) return 'UNBEKANNT';
    var parsed = new Date(String(value).replace(' ', 'T') + (/Z$|[+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z'));
    return isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('de-DE');
  }

  function renderLoginSessions(items) {
    if (!loginSessionList) return;
    loginSessionList.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'KEINE AKTIVEN ANMELDUNGEN GEMELDET';
      loginSessionList.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var row = document.createElement('article');
      row.className = 'login-session-item' + (item.current ? ' current' : '');
      var copy = document.createElement('div');
      var label = document.createElement('strong');
      label.textContent = item.device_label || 'Unbekanntes Gerät';
      var meta = document.createElement('small');
      meta.textContent = 'ZULETZT AKTIV: ' + formatSessionDate(item.last_seen_at)
        + ' // LÄUFT AB: ' + formatSessionDate(item.expires_at);
      copy.appendChild(label);
      copy.appendChild(meta);
      row.appendChild(copy);
      if (item.current) {
        var current = document.createElement('span');
        current.className = 'login-session-current';
        current.textContent = 'DIESES GERÄT';
        row.appendChild(current);
      } else {
        var revoke = document.createElement('button');
        revoke.className = 'settings-action compact danger';
        revoke.type = 'button';
        revoke.textContent = 'WIDERRUFEN';
        revoke.addEventListener('click', function () { revokeLoginSession(Number(item.id)); });
        row.appendChild(revoke);
      }
      loginSessionList.appendChild(row);
    });
  }

  // 0.6.85: the page Sicherheit und Anmeldung (js/coreui-security-settings.js) renders the rest.
  function shareAccountSecurity(data) {
    try { document.dispatchEvent(new CustomEvent('coreai:security', {detail: data})); } catch (e) { /* old browser */ }
  }
  // 0.6.86: a name saved under Konto (js/coreui-account-settings.js) fills Profil and the overview head.
  document.addEventListener('coreai:profile-saved', function (event) {
    if (event && event.detail && event.detail.display_name) applyProfile(event.detail);
  });
  document.addEventListener('coreai:security-sessions', function (event) {
    var detail = event && event.detail ? event.detail : {};
    if (detail.csrf_token) csrfToken = detail.csrf_token;
    if (detail.sessions) renderLoginSessions(detail.sessions);
  });

  function loadAccountSecurity() {
    return api('./api/account_security.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      renderLoginSessions(data.sessions || []);
      shareAccountSecurity(data);
    }).catch(function (error) {
      renderLoginSessions([]);
      announceSaved(error.message === 'missing_schema_006' ? 'MIGRATION 006 FEHLT' : 'KONTOSICHERHEIT NICHT ERREICHBAR', true);
    });
  }

  function changePassword() {
    var currentValue = currentPassword ? currentPassword.value : '';
    var nextValue = newPassword ? newPassword.value : '';
    var confirmValue = confirmPassword ? confirmPassword.value : '';
    if (nextValue.length < 12) {
      announceSaved('NEUES PASSWORT MUSS MINDESTENS 12 ZEICHEN HABEN', true);
      return;
    }
    if (nextValue !== confirmValue) {
      announceSaved('PASSWORTBESTÄTIGUNG STIMMT NICHT ÜBEREIN', true);
      return;
    }
    if (changePasswordButton) changePasswordButton.disabled = true;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'password_change',
        current_password: currentValue,
        new_password: nextValue,
        confirm_password: confirmValue
      })
    }).then(function (data) {
      if (currentPassword) currentPassword.value = '';
      if (newPassword) newPassword.value = '';
      if (confirmPassword) confirmPassword.value = '';
      csrfToken = data.csrf_token || csrfToken;
      renderLoginSessions(data.sessions || []);
      shareAccountSecurity(data);
      announceSaved('PASSWORT GEÄNDERT, ANDERE GERÄTE ABGEMELDET');
    }).catch(function (error) {
      var labels = {
        invalid_current_password: 'AKTUELLES PASSWORT IST FALSCH',
        invalid_new_password: 'NEUES PASSWORT IST UNGÜLTIG',
        password_confirmation_mismatch: 'PASSWORTBESTÄTIGUNG STIMMT NICHT ÜBEREIN',
        password_unchanged: 'NEUES PASSWORT ENTSPRICHT DEM ALTEN',
        rate_limited: 'ZU VIELE VERSUCHE, BITTE SPÄTER ERNEUT VERSUCHEN'
      };
      announceSaved(labels[error.message] || ('PASSWORTÄNDERUNG FEHLGESCHLAGEN: ' + error.message), true);
    }).finally(function () { if (changePasswordButton) changePasswordButton.disabled = false; });
  }

  function revokeLoginSession(sessionId) {
    if (!sessionId || !window.confirm('Diese Geräte-Anmeldung wirklich widerrufen?')) return;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'session_revoke', session_id: sessionId })
    }).then(function (data) {
      renderLoginSessions(data.sessions || []);
      shareAccountSecurity(data);
      announceSaved('GERÄTE-ANMELDUNG WIDERRUFEN');
    }).catch(function (error) { announceSaved('WIDERRUF FEHLGESCHLAGEN: ' + error.message, true); });
  }

  function revokeOtherLoginSessions() {
    if (!window.confirm('Alle anderen Geräte sofort von CoreAI: TCI Interface abmelden?')) return;
    if (revokeOtherSessionsButton) revokeOtherSessionsButton.disabled = true;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'sessions_revoke_others' })
    }).then(function (data) {
      renderLoginSessions(data.sessions || []);
      shareAccountSecurity(data);
      announceSaved(String(data.revoked_count || 0) + ' ANDERE ANMELDUNGEN WIDERRUFEN');
    }).catch(function (error) { announceSaved('ABMELDEN FEHLGESCHLAGEN: ' + error.message, true); })
      .finally(function () { if (revokeOtherSessionsButton) revokeOtherSessionsButton.disabled = false; });
  }

  function exportAccountData() {
    if (!exportAccountDataButton) return;
    exportAccountDataButton.disabled = true;
    fetch('./api/account_export.php', {
      method: 'POST',
      credentials: 'include',
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CoreUI-CSRF': csrfToken }
    }).then(function (response) {
      if (!response.ok) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          throw new Error(data.error || ('HTTP_' + response.status));
        });
      }
      var disposition = response.headers.get('Content-Disposition') || '';
      var match = disposition.match(/filename="([^"]+)"/i);
      return response.blob().then(function (blob) { return { blob: blob, name: match ? match[1] : 'coreai-tci-interface-account.json' }; });
    }).then(function (download) {
      var url = URL.createObjectURL(download.blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = download.name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      announceSaved('PRIVATER KONTOEXPORT ERSTELLT');
    }).catch(function (error) {
      announceSaved('DATENEXPORT FEHLGESCHLAGEN: ' + error.message, true);
    }).finally(function () { exportAccountDataButton.disabled = false; });
  }

  function memoryScopeLabel(item) {
    if (item.scope === 'global') return 'GLOBAL';
    if (item.scope === 'character') return 'CHARAKTER';
    return 'PERSÖNLICH';
  }

  function renderMemories(items) {
    startText('memory', count((items || []).length, 'Fakt', 'Fakten'));
    if (!memoryList) return;
    memoryList.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'NOCH KEINE SICHTBAREN MEMORY-FAKTEN';
      memoryList.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var row = document.createElement('article');
      row.className = 'memory-item';
      var body = document.createElement('div');
      var fact = document.createElement('p');
      fact.textContent = item.fact || '';
      var meta = document.createElement('small');
      meta.textContent = memoryScopeLabel(item) + ' // REL.' + String(item.relevance || 1);
      body.appendChild(fact);
      body.appendChild(meta);
      row.appendChild(body);
      if (item.scope !== 'global') {
        var del = document.createElement('button');
        del.className = 'memory-delete';
        del.type = 'button';
        del.textContent = '×';
        del.setAttribute('aria-label', 'Memory-Fakt löschen');
        del.addEventListener('click', function () { deleteMemory(Number(item.id)); });
        row.appendChild(del);
      }
      memoryList.appendChild(row);
    });
  }

  function loadMemories() {
    if (reloadMemoriesButton) reloadMemoriesButton.disabled = true;
    return api('./api/memories.php?limit=100')
      .then(function (data) { renderMemories(data.items || []); })
      .catch(function (error) {
        renderMemories([]);
        announceSaved('MEMORY NICHT ERREICHBAR: ' + error.message, true);
      })
      .finally(function () { if (reloadMemoriesButton) reloadMemoriesButton.disabled = false; });
  }

  function addMemory() {
    var fact = memoryFact ? memoryFact.value.trim() : '';
    if (fact.length < 3) {
      announceSaved('MEMORY-FAKT IST ZU KURZ', true);
      return;
    }
    if (addMemoryButton) addMemoryButton.disabled = true;
    api('./api/memories.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'upsert', fact: fact, relevance: Number(memoryRelevance.value) || 5, scope: 'user' })
    }).then(function () {
      if (memoryFact) memoryFact.value = '';
      announceSaved('MEMORY-FAKT GESPEICHERT');
      return loadMemories();
    }).catch(function (error) {
      announceSaved('MEMORY-FEHLER: ' + error.message, true);
    }).finally(function () { if (addMemoryButton) addMemoryButton.disabled = false; });
  }

  function deleteMemory(id) {
    if (!id || !window.confirm('Diesen persönlichen Memory-Fakt wirklich löschen?')) return;
    api('./api/memories.php', { method: 'POST', body: JSON.stringify({ action: 'delete', id: id }) })
      .then(function () {
        announceSaved('MEMORY-FAKT GELÖSCHT');
        return loadMemories();
      })
      .catch(function (error) { announceSaved('LÖSCHEN FEHLGESCHLAGEN: ' + error.message, true); });
  }

  function formatBytes(value) {
    var bytes = Number(value) || 0;
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function renderKnowledge(data) {
    var totals = data.totals || {};
    if (knowledgeSourceCount) knowledgeSourceCount.textContent = String(totals.sources || 0);
    if (knowledgeChunkCount) knowledgeChunkCount.textContent = String(totals.chunks || 0);
    if (knowledgeGlobalCount) knowledgeGlobalCount.textContent = String(totals.global_chunks || 0) + ' CHUNKS';
    startText('knowledge', count(totals.sources, 'Quelle', 'Quellen'));
    startText('knowledgeChunks', count(totals.chunks, 'Abschnitt', 'Abschnitte'));
    if (!knowledgeList) return;
    knowledgeList.innerHTML = '';
    var sources = data.sources || [];
    if (!sources.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'NOCH KEINE PRIVATEN WISSENSQUELLEN';
      knowledgeList.appendChild(empty);
      return;
    }
    sources.forEach(function (source) {
      var row = document.createElement('article');
      row.className = 'knowledge-item';
      var copy = document.createElement('div');
      var title = document.createElement('strong');
      title.textContent = source.title || source.original_name || 'Dokument';
      var meta = document.createElement('small');
      meta.textContent = String(source.chunk_count || 0) + ' CHUNKS // ' + formatBytes(source.file_size)
        + ' // ' + String(source.status || 'ready').toUpperCase();
      copy.appendChild(title);
      copy.appendChild(meta);
      var remove = document.createElement('button');
      remove.className = 'settings-action compact danger';
      remove.type = 'button';
      remove.textContent = 'LÖSCHEN';
      remove.addEventListener('click', function () { deleteKnowledge(source.uuid, source.title || source.original_name); });
      row.appendChild(copy);
      row.appendChild(remove);
      knowledgeList.appendChild(row);
    });
  }

  function loadKnowledge() {
    if (reloadKnowledgeButton) reloadKnowledgeButton.disabled = true;
    return api('./api/knowledge.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      renderKnowledge(data);
    }).catch(function (error) {
      renderKnowledge({ sources: [], totals: {} });
      announceSaved(error.message === 'missing_schema_004' ? 'MIGRATION 004 FEHLT' : 'RAG-LITE NICHT ERREICHBAR: ' + error.message, true);
    }).finally(function () { if (reloadKnowledgeButton) reloadKnowledgeButton.disabled = false; });
  }

  function selectKnowledgeFile(file) {
    selectedKnowledgeFile = file || null;
    if (knowledgeFileName) knowledgeFileName.textContent = file ? file.name : 'DOKUMENT ODER CODEDATEI AUSWÄHLEN';
  }

  function uploadKnowledge() {
    var file = selectedKnowledgeFile || (knowledgeFile && knowledgeFile.files && knowledgeFile.files[0]);
    if (!file) {
      announceSaved('BITTE ZUERST EIN DOKUMENT AUSWÄHLEN', true);
      return;
    }
    if (uploadKnowledgeButton) uploadKnowledgeButton.disabled = true;
    var form = new FormData();
    form.append('file', file, file.name || 'dokument');
    form.append('title', knowledgeTitleInput ? knowledgeTitleInput.value.trim() : '');
    announceSaved('DOKUMENT WIRD GELESEN UND GECHUNKT');
    api('./api/knowledge.php', { method: 'POST', body: form }).then(function (data) {
      renderKnowledge(data);
      selectKnowledgeFile(null);
      if (knowledgeFile) knowledgeFile.value = '';
      if (knowledgeTitleInput) knowledgeTitleInput.value = '';
      announceSaved('RAG-QUELLE MIT ' + String(data.source && data.source.chunk_count || 0) + ' CHUNKS BEREIT');
    }).catch(function (error) {
      var labels = {
        pdf_has_no_text_layer: 'PDF HAT KEINE TEXTEBENE',
        pdf_text_support_unavailable: 'PDF-TEXTWERKZEUG IST NICHT VERFÜGBAR',
        docx_support_unavailable: 'DOCX-UNTERSTÜTZUNG IST NICHT VERFÜGBAR',
        knowledge_file_too_large: 'DOKUMENT IST ZU GROSS',
        knowledge_format_not_allowed: 'DATEIFORMAT NICHT ERLAUBT',
        knowledge_mime_mismatch: 'DATEIINHALT PASST NICHT ZUM DATEIFORMAT',
        knowledge_text_too_short: 'DOKUMENT ENTHÄLT ZU WENIG LESBAREN TEXT',
        knowledge_chunk_limit_exceeded: 'DOKUMENT ERZEUGT ZU VIELE CHUNKS',
        knowledge_busy: 'EIN ANDERER UPLOAD FÜR DIESES KONTO LÄUFT NOCH',
        knowledge_total_limit_reached: 'PERSÖNLICHES WISSENSLIMIT ERREICHT',
        knowledge_source_limit_reached: 'QUELLENLIMIT ERREICHT'
      };
      announceSaved(labels[error.message] || ('RAG-UPLOAD FEHLGESCHLAGEN: ' + error.message), true);
    }).finally(function () { if (uploadKnowledgeButton) uploadKnowledgeButton.disabled = false; });
  }

  function deleteKnowledge(uuid, title) {
    if (!uuid || !window.confirm('Wissensquelle "' + String(title || 'Dokument') + '" inklusive aller Chunks löschen?')) return;
    api('./api/knowledge.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', uuid: uuid })
    }).then(function (data) {
      renderKnowledge(data);
      announceSaved('QUELLE, DATEI UND CHUNKS GELÖSCHT');
    }).catch(function (error) { announceSaved('RAG-LÖSCHEN FEHLGESCHLAGEN: ' + error.message, true); });
  }

  Object.keys(fields).forEach(function (key) {
    if (fields[key]) fields[key].addEventListener('change', saveLocal);
  });
  themeOptions.forEach(function (option) {
    option.addEventListener('change', function () {
      // Switching the selected palette must not copy the old mode's colors.
      appearanceEditMode = null;
      var prior = preferences ? preferences.load() : {};
      if (preferences) {
        var value = Object.assign({}, prior, {theme: option.value});
        preferences.save(value);
        syncFields(preferences.load());
        announceSaved('LOKAL GESPEICHERT');
      }
    });
  });
  Object.keys(appearanceFields).forEach(function (key) {
    if (!appearanceFields[key] || key === 'separateModes') return;
    appearanceFields[key].addEventListener(key === 'contrast' ? 'input' : 'change', saveLocal);
  });
  // 0.6.80: "Hell und Dunkel getrennt": switching on starts Dunkel from the current look,
  // switching off keeps Dunkel's own choice for later and uses the shared look again.
  if (appearanceFields.separateModes) appearanceFields.separateModes.addEventListener('change', function () {
    if (!preferences) return;
    var prior = preferences.load();
    var on = appearanceFields.separateModes.checked;
    var next = Object.assign({}, prior, {separateModes: on});
    if (on && !prior.separateModes) {
      next.motifDark = prior.motif;
      next.accentDark = prior.accent;
      next.fontFamilyDark = prior.fontFamily;
    }
    appearanceEditMode = null;
    syncFields(preferences.save(next));
    announceSaved('LOKAL GESPEICHERT');
  });
  if (editModeGroup) editModeGroup.addEventListener('change', function () {
    var chosen = readField(editModeGroup);
    appearanceEditMode = chosen === 'dark' || chosen === 'light' ? chosen : null;
    if (preferences) syncFields(preferences.load());
  });
  ['background', 'foreground'].forEach(function (name) {
    var picker = appearanceFields[name];
    var hex = appearanceHex[name];
    if (!picker || !hex) return;
    picker.addEventListener('input', function () {
      hex.value = picker.value.toUpperCase();
      hex.removeAttribute('aria-invalid');
    });
    function commitHex() {
      var parsed = parseHex(hex.value);
      if (!parsed) {
        hex.setAttribute('aria-invalid', 'true');
        announceSaved('KEIN GÜLTIGER FARBWERT (#RRGGBB)', true);
        return;
      }
      hex.removeAttribute('aria-invalid');
      hex.value = parsed.toUpperCase();
      if (parsed !== picker.value) {
        picker.value = parsed;
        saveLocal();
      }
    }
    hex.addEventListener('change', commitHex);
    hex.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); commitHex(); }
    });
  });

  // 0.6.80: the motif (design, accent, font, colors) as a file, on the clipboard or imported.
  function motifText() {
    return JSON.stringify(preferences.exportMotif(preferences.load()), null, 2);
  }
  function copyText(text) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (error) { ok = false; }
      area.remove();
      if (ok) resolve(); else reject(new Error('copy_failed'));
    });
  }
  function setImportStatus(text, isError) {
    if (!motifImportStatus) return;
    motifImportStatus.textContent = text || '';
    motifImportStatus.classList.toggle('error', isError === true);
  }
  function toggleImport(open) {
    if (!motifImportPanel) return;
    motifImportPanel.hidden = !open;
    if (motifImportButton) motifImportButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && motifImportText) motifImportText.focus();
    if (!open) {
      if (motifImportText) motifImportText.value = '';
      setImportStatus('');
    }
  }
  function applyImport() {
    if (!preferences || !motifImportText) return;
    var next = preferences.importMotif(motifImportText.value, preferences.load());
    if (!next) {
      setImportStatus('Das ist kein gültiges CoreAI-Motiv. Erwartet wird ein exportiertes oder kopiertes Motiv.', true);
      return;
    }
    appearanceEditMode = null;
    syncFields(preferences.save(next));
    toggleImport(false);
    announceSaved('MOTIV IMPORTIERT');
  }
  if (motifExportButton) motifExportButton.addEventListener('click', function () {
    if (!preferences) return;
    var blob = new Blob([motifText() + '\n'], {type: 'application/json'});
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'coreai-motiv-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(link);
    link.click();
    setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    announceSaved('MOTIV EXPORTIERT');
  });
  if (motifCopyButton) motifCopyButton.addEventListener('click', function () {
    if (!preferences) return;
    copyText(motifText()).then(function () {
      announceSaved('MOTIV KOPIERT');
    }, function () {
      announceSaved('KOPIEREN NICHT MÖGLICH', true);
    });
  });
  if (motifImportButton) motifImportButton.addEventListener('click', function () {
    toggleImport(motifImportPanel ? motifImportPanel.hidden : false);
  });
  if (motifImportCancel) motifImportCancel.addEventListener('click', function () { toggleImport(false); });
  if (motifImportApply) motifImportApply.addEventListener('click', applyImport);
  if (motifImportChoose && motifImportFile) motifImportChoose.addEventListener('click', function () { motifImportFile.click(); });
  if (motifImportFile) motifImportFile.addEventListener('change', function () {
    var file = motifImportFile.files && motifImportFile.files[0];
    motifImportFile.value = '';
    if (!file) return;
    if (file.size > 8000) {
      setImportStatus('Die Datei ist zu groß für ein CoreAI-Motiv.', true);
      return;
    }
    file.text().then(function (text) {
      if (motifImportText) motifImportText.value = text;
      applyImport();
    }, function () {
      setImportStatus('Die Datei konnte nicht gelesen werden.', true);
    });
  });
  if (generalSendKey) generalSendKey.addEventListener('change', saveLocal);
  if (generalUiLanguage) generalUiLanguage.addEventListener('change', saveLocal);
  if (generalHigherIntelligence) generalHigherIntelligence.addEventListener('change', function () {
    saveGeneralControl('auto_higher_core', generalHigherIntelligence.checked, generalHigherIntelligence);
  });
  if (generalReasoningLevels) generalReasoningLevels.addEventListener('change', function () {
    saveGeneralControl('thinking_level', generalReasoningLevels.value, generalReasoningLevels);
  });
  window.addEventListener('coreui:preferences', function (event) {
    if (event && event.detail) syncFields(event.detail);
  });
  if (aiSystemPrompt) aiSystemPrompt.addEventListener('input', updatePromptCounter);
  if (aiSaveButton) aiSaveButton.addEventListener('click', saveAiSettings);
  if (memorySettingsSaveButton) memorySettingsSaveButton.addEventListener('click', saveAiSettings);
  if (saveProfileButton) saveProfileButton.addEventListener('click', saveProfile);
  if (userAvatarFile) userAvatarFile.addEventListener('change', function () {
    var file = userAvatarFile.files && userAvatarFile.files[0];
    userAvatarFile.value = '';
    if (file) openAvatarCrop('user', file);
  });
  if (assistantAvatarFile) assistantAvatarFile.addEventListener('change', function () {
    var file = assistantAvatarFile.files && assistantAvatarFile.files[0];
    assistantAvatarFile.value = '';
    if (file) openAvatarCrop('assistant', file);
  });
  if (editUserAvatar) editUserAvatar.addEventListener('click', function () { openAvatarCrop('user', null); });
  if (editAssistantAvatar) editAssistantAvatar.addEventListener('click', function () { openAvatarCrop('assistant', null); });
  if (removeUserAvatar) removeUserAvatar.addEventListener('click', function () { deleteAvatar('user'); });
  if (removeAssistantAvatar) removeAssistantAvatar.addEventListener('click', function () { deleteAvatar('assistant'); });
  if (addMemoryButton) addMemoryButton.addEventListener('click', addMemory);
  if (reloadMemoriesButton) reloadMemoriesButton.addEventListener('click', loadMemories);
  if (knowledgeFile) knowledgeFile.addEventListener('change', function () { selectKnowledgeFile(knowledgeFile.files && knowledgeFile.files[0]); });
  if (uploadKnowledgeButton) uploadKnowledgeButton.addEventListener('click', uploadKnowledge);
  if (reloadKnowledgeButton) reloadKnowledgeButton.addEventListener('click', loadKnowledge);
  if (changePasswordButton) changePasswordButton.addEventListener('click', changePassword);
  if (revokeOtherSessionsButton) revokeOtherSessionsButton.addEventListener('click', revokeOtherLoginSessions);
  if (exportAccountDataButton) exportAccountDataButton.addEventListener('click', exportAccountData);
  if (knowledgeDrop) {
    ['dragenter', 'dragover'].forEach(function (eventName) {
      knowledgeDrop.addEventListener(eventName, function (event) { event.preventDefault(); knowledgeDrop.classList.add('dragging'); });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      knowledgeDrop.addEventListener(eventName, function (event) { event.preventDefault(); knowledgeDrop.classList.remove('dragging'); });
    });
    knowledgeDrop.addEventListener('drop', function (event) {
      selectKnowledgeFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]);
    });
  }
  if (refreshButton) refreshButton.addEventListener('click', loadHealth);
  if (resetButton) {
    resetButton.addEventListener('click', function () {
      if (!preferences) return;
      // Appearance reset must not silently overwrite the General send shortcut.
      var prior = preferences.load();
      preferences.reset();
      syncFields(preferences.save(Object.assign({}, preferences.defaults, {
        sendKey: prior.sendKey, uiLanguage: prior.uiLanguage
      })));
      announceSaved('ANZEIGE-STANDARD WIEDERHERGESTELLT');
    });
  }
  if (logoutButton) {
    logoutButton.addEventListener('click', function () {
      fetch('./api/auth.php?action=logout', { method: 'POST', credentials: 'include' })
        .finally(function () { location.replace('login.html'); });
    });
  }
  if (backButton) {
    backButton.addEventListener('click', function () {
      location.replace('app.html');
    });
  }

  syncFields(preferences ? preferences.load() : {});
  Promise.resolve(loadAccount()).then(function () {
    return Promise.all([
      loadProfile(),
      loadAiSettings(),
      loadGeneralControls(),
      loadKnowledge(),
      loadMemories(),
      loadHealth(),
      loadAccountSecurity()
    ]);
  });
})();
