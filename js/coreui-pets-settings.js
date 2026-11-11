/* CoreAI: TCI Interface 0.7.1: Settings > Pets after the reference screenshots.
   "Pet auswählen": the original CoreAI pets and the account's own pets, animated (js/coreui-pets.js),
   one choice saved at once; "Pet nicht anzeigen"; "Pet hochladen" (name, description, a sprite
   sheet with a live preview); deleting an own pet; "Pet mit Mara erstellen" opens a new chat with
   a draft (app.html?draft=). Data: api/pets.php. */
(function () {
  'use strict';

  var view = document.getElementById('view-pets');
  if (!view || !document.getElementById('petRows')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/pets.php';
  var state = {csrf: '', builtin: [], library: [], own: [], selected: 'mara', ready: false, loaded: false, previewUrl: '', pendingDelete: null, canManageLibrary: false};

  var ERRORS = {
    name_required: 'Bitte gib deinem Pet einen Namen.',
    no_file_uploaded: 'Bitte wähle ein Sprite-Sheet aus.',
    empty_file: 'Die Datei ist leer.',
    file_too_large: 'Das Sprite-Sheet ist größer als 5 MB.',
    invalid_file_type: 'Bitte wähle ein PNG oder WebP.',
    not_transparent: 'Das Sprite-Sheet braucht einen transparenten Hintergrund.',
    invalid_sheet_size: 'Erlaubt sind 2 bis 24 quadratische Frames in einer Zeile oder OpenPet v1/v2 (1536 × 1872/2288).',
    invalid_frame_count: 'Ein Sprite-Sheet braucht 2 bis 24 Einzelbilder.',
    too_many_pets: 'Du hast schon 12 eigene Pets. Lösche erst eines.',
    pet_not_found: 'Dieses Pet gibt es nicht mehr.',
    missing_schema_034: 'Das Update ist noch nicht vollständig eingespielt (Migration 034).',
    not_authenticated: 'Bitte melde dich erneut an.'
  };
  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || (fallback ? fallback + ' (' + (code || 'unbekannt') + ')' : 'Fehler: ' + (code || 'unbekannt'));
  }
  function status(text, error) {
    var el = $('petStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function request(method, body, isForm) {
    var headers = {'Accept': 'application/json'};
    if (method === 'POST') headers['X-CoreUI-CSRF'] = state.csrf;
    if (method === 'POST' && !isForm) headers['Content-Type'] = 'application/json';
    return fetch(API, {method: method, credentials: 'include', cache: 'no-store', headers: headers,
      body: method === 'POST' ? (isForm ? body : JSON.stringify(body)) : undefined}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP_' + res.status));
        return data;
      });
    });
  }
  function petById(id) {
    return state.builtin.concat(state.library, state.own).filter(function (p) { return p.id === id; })[0] || null;
  }

  // --- The list -------------------------------------------------------------------------------------
  function row(pet) {
    var label = document.createElement('label');
    label.className = 'sec-row pet-row' + (pet.own ? ' is-own' : '');
    label.setAttribute('data-pet', pet.id);
    label.htmlFor = 'pet-' + pet.id;
    var sprite = document.createElement('span');
    sprite.className = 'pet-sprite';
    var node = window.CoreAIPets ? window.CoreAIPets.make(pet, 56) : null;
    if (node) sprite.appendChild(node);
    var copy = document.createElement('span');
    copy.className = 'sec-copy';
    var name = document.createElement('strong');
    name.textContent = pet.name;
    if (pet.own || pet.library) {
      var tag = document.createElement('em');
      tag.className = 'pet-own-tag';
      tag.textContent = pet.own ? 'EIGENES PET' : 'FREIGEGEBENES PET';
      name.appendChild(tag);
    }
    var small = document.createElement('small');
    small.textContent = pet.description || '';
    copy.appendChild(name);
    if (pet.description) copy.appendChild(small);
    if (pet.library) {
      var credit = document.createElement('small');
      credit.textContent = '© ' + (pet.attribution || 'Urheber unbekannt') + ' · ' + (pet.license || 'Lizenz ungeklärt');
      copy.appendChild(credit);
    }
    label.appendChild(sprite);
    label.appendChild(copy);
    if (pet.own || (pet.library && state.canManageLibrary)) {
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'pz-icon pet-delete';
      del.setAttribute('aria-label', pet.library ? 'System-Pet „' + pet.name + '“ entfernen' : 'Pet „' + pet.name + '“ löschen');
      del.innerHTML = '<svg aria-hidden="true"><use href="#i-trash" /></svg>';
      del.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); askDelete(pet); });
      label.appendChild(del);
    }
    var radio = document.createElement('input');
    radio.type = 'radio';
    radio.className = 'pet-radio';
    radio.name = 'petChoice';
    radio.id = 'pet-' + pet.id;
    radio.value = pet.id;
    label.appendChild(radio);
    return label;
  }
  function render() {
    var rows = $('petRows');
    rows.textContent = '';
    state.own.concat(state.library, state.builtin).forEach(function (pet) { rows.appendChild(row(pet)); });
    Array.prototype.forEach.call(view.querySelectorAll('input[name="petChoice"]'), function (radio) {
      radio.checked = radio.value === state.selected;
      radio.disabled = !state.loaded;
    });
    $('petLibraryAdmin').hidden = !state.canManageLibrary;
    $('petUploadOpen').disabled = !state.ready;
    $('petUploadOpen').title = state.ready ? '' : 'Das Update ist noch nicht vollständig eingespielt (Migration 034).';
  }
  function apply(data) {
    state.csrf = data.csrf_token || state.csrf;
    state.builtin = data.builtin || [];
    state.library = data.library || [];
    state.own = data.own || [];
    state.canManageLibrary = data.can_manage_library === true;
    state.selected = data.selected || 'none';
    state.ready = data.upload_ready === true;
    state.loaded = true;
    render();
  }
  function load() {
    return request('GET').then(function (data) {
      apply(data);
      status('');
    }).catch(function (err) { status('Pets konnten nicht geladen werden. ' + errText(err), true); });
  }

  // A choice saves at once; a refused one goes back.
  view.addEventListener('change', function (e) {
    var radio = e.target;
    if (!radio || radio.name !== 'petChoice') return;
    var previous = state.selected;
    var choice = radio.value;
    state.selected = choice;
    request('POST', {action: 'select', pet: choice}).then(function (data) {
      state.csrf = data.csrf_token || state.csrf;
      state.library = data.library || state.library;
      state.own = data.own || state.own;
      state.selected = data.selected || 'none';
      var pet = petById(state.selected);
      status(pet ? pet.name + ' begleitet dich jetzt im Chat.' : 'Im Chat wird kein Pet angezeigt.');
      if (window.CoreAIPets) window.CoreAIPets.current = pet;
    }).catch(function (err) {
      state.selected = previous;
      render();
      status(errText(err, 'Das Pet konnte nicht gespeichert werden.'), true);
    });
  });

  // "Pet mit Mara erstellen": a new chat with a draft; nothing is sent yet.
  $('petCreate').addEventListener('click', function () {
    location.href = './app.html?draft=' + encodeURIComponent('Hilf mir, ein eigenes Pet für CoreAI zu entwerfen. '
      + 'Ein Pet kann ein transparenter Streifen mit quadratischen Frames oder ein OpenPet v1/v2 Atlas mit 9 oder 11 Zustandszeilen sein. '
      + 'Ein OpenPet-Atlas hat 8 Spalten mit je 192 × 208 Pixeln. Mein Pet soll so aussehen: ');
  });

  // --- Upload ---------------------------------------------------------------------------------------------
  function clearPreview() {
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = '';
    $('petPreview').hidden = true;
    $('petPreviewSprite').textContent = '';
  }
  function updateSubmit() {
    $('petUploadSubmit').disabled = !$('petName').value.trim() || !($('petFile').files && $('petFile').files[0]);
  }
  function openUpload() {
    if (!state.ready) return;
    $('petUploadForm').reset();
    $('petFileName').textContent = 'Keine ausgewählt';
    $('petUploadError').textContent = '';
    clearPreview();
    updateSubmit();
    $('petUploadDialog').hidden = false;
    $('petName').focus();
  }
  function closeUpload() {
    $('petUploadDialog').hidden = true;
    clearPreview();
  }
  $('petUploadOpen').addEventListener('click', openUpload);
  $('petUploadClose').addEventListener('click', closeUpload);
  $('petUploadCancel').addEventListener('click', closeUpload);
  $('petName').addEventListener('input', updateSubmit);
  // The preview shows the sheet as it will move; the server checks it again.
  $('petFile').addEventListener('change', function () {
    var file = $('petFile').files && $('petFile').files[0];
    $('petUploadError').textContent = '';
    clearPreview();
    $('petFileName').textContent = file ? file.name : 'Keine ausgewählt';
    updateSubmit();
    if (!file) return;
    if (['image/png', 'image/webp'].indexOf(file.type) < 0) { $('petUploadError').textContent = ERRORS.invalid_file_type; return; }
    if (file.size > 5 * 1024 * 1024) { $('petUploadError').textContent = ERRORS.file_too_large; return; }
    state.previewUrl = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      var h = img.naturalHeight, w = img.naturalWidth;
      var version = w === 1536 && (h === 1872 || h === 2288) ? (h === 2288 ? 2 : 1) : 0;
      if (!version && (h < 16 || h > 512 || w % h !== 0)) { $('petUploadError').textContent = ERRORS.invalid_sheet_size; return; }
      var frames = version ? 8 : w / h;
      if (!version && (frames < 2 || frames > 24)) { $('petUploadError').textContent = ERRORS.invalid_frame_count; return; }
      var node = window.CoreAIPets ? window.CoreAIPets.make({id: 'preview', url: state.previewUrl,
        frames: frames, frame_px: version ? 192 : h, sprite_version: version}, 64) : null;
      if (node) $('petPreviewSprite').appendChild(node);
      $('petPreviewInfo').textContent = version ? 'OpenPet v' + version + ': 8 Spalten, ' + (version === 2 ? 11 : 9) + ' Zustandszeilen'
        : frames + ' Bilder mit je ' + h + ' Pixeln';
      $('petPreview').hidden = false;
    };
    img.onerror = function () { $('petUploadError').textContent = ERRORS.invalid_file_type; };
    img.src = state.previewUrl;
  });
  $('petUploadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var file = $('petFile').files && $('petFile').files[0];
    if (!$('petName').value.trim()) { $('petUploadError').textContent = ERRORS.name_required; return; }
    if (!file) { $('petUploadError').textContent = ERRORS.no_file_uploaded; return; }
    var form = new FormData();
    form.append('name', $('petName').value);
    form.append('description', $('petDescription').value);
    form.append('file', file);
    $('petUploadSubmit').disabled = true;
    $('petUploadError').textContent = '';
    request('POST', form, true).then(function (data) {
      apply(data);
      closeUpload();
      status('„' + data.pet.name + '“ ist hochgeladen. Wähle es aus, damit es dich im Chat begleitet.');
    }).catch(function (err) {
      $('petUploadError').textContent = errText(err, 'Das Pet konnte nicht hochgeladen werden.');
      updateSubmit();
    });
  });

  // Only the server-verified owner can publish system-wide presets.
  // Merely downloading a sprite from a community gallery is not a license.
  $('petLibraryForm').addEventListener('submit', function (event) {
    event.preventDefault();
    if (!state.canManageLibrary) return;
    var formEl = $('petLibraryForm');
    var file = $('petLibraryFile').files && $('petLibraryFile').files[0];
    var errorEl = $('petLibraryError');
    errorEl.textContent = '';
    if (!file || file.size > 6 * 1024 * 1024) {
      errorEl.textContent = 'Bitte ein vollständiges .openpet.zip bis 6 MB auswählen.';
      return;
    }
    if (!$('petLibraryConfirm').checked || !formEl.checkValidity()) {
      errorEl.textContent = 'Lizenz, Urheber, Originalquelle und konkreten Rechtebeleg prüfen und bestätigen.';
      return;
    }
    var payload = new FormData();
    payload.append('action', 'library_import');
    payload.append('file', file);
    payload.append('license', $('petLibraryLicense').value);
    payload.append('rights_holder', $('petLibraryHolder').value);
    payload.append('source_url', $('petLibrarySource').value);
    payload.append('evidence_url', $('petLibraryEvidence').value);
    payload.append('rights_confirmed', 'yes');
    var button = $('petLibrarySubmit');
    button.disabled = true;
    request('POST', payload, true).then(function (data) {
      apply(data);
      formEl.reset();
      status('„' + data.pet.name + '“ steht jetzt als freigegebenes Standard-Pet allen Konten zur Verfügung.');
    }).catch(function (err) {
      errorEl.textContent = errText(err, 'Das Pet konnte nicht veröffentlicht werden.');
    }).then(function () { button.disabled = false; });
  });

  // --- Delete ---------------------------------------------------------------------------------------------
  function askDelete(pet) {
    state.pendingDelete = pet;
    $('petDeleteText').textContent = pet.library
      ? '„' + pet.name + '“ wird aus der öffentlichen Standardauswahl entfernt. Die Lizenzdaten werden nicht mehr angezeigt.'
      : '„' + pet.name + '“ wird mit seinem Sprite-Sheet gelöscht.'
      + (state.selected === pet.id ? ' Danach begleitet dich wieder Mara.' : '');
    $('petDeleteError').textContent = '';
    $('petDeleteConfirm').hidden = false;
    $('petDeleteCancel').focus();
  }
  function closeDelete() { $('petDeleteConfirm').hidden = true; state.pendingDelete = null; }
  $('petDeleteCancel').addEventListener('click', closeDelete);
  $('petDeleteOk').addEventListener('click', function () {
    var pet = state.pendingDelete;
    if (!pet) return;
    $('petDeleteOk').disabled = true;
    request('POST', {action: pet.library ? 'library_remove' : 'delete', id: pet.id}).then(function (data) {
      closeDelete();
      apply(data);
      status('„' + pet.name + '“ wurde gelöscht.');
    }).catch(function (err) {
      $('petDeleteError').textContent = errText(err, 'Das Pet konnte nicht gelöscht werden.');
    }).then(function () { $('petDeleteOk').disabled = false; });
  });

  Array.prototype.forEach.call(view.querySelectorAll('#petUploadDialog, #petDeleteConfirm'), function (dialog) {
    dialog.addEventListener('click', function (e) {
      if (e.target !== dialog) return;
      if (dialog.id === 'petUploadDialog') closeUpload(); else closeDelete();
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!$('petDeleteConfirm').hidden) closeDelete();
    else if (!$('petUploadDialog').hidden) closeUpload();
  });

  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'pets') load();
  });
  load();
  window.CoreAIPetsSettings = {load: load};
})();
