'use strict';
// Contract for the curated Pet library. Only approved sprites ever reach presets.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname,'..');
const read = p=>fs.readFileSync(path.join(root,p),'utf8');
const app=read('js/coreui-pets-settings.js'), settings=read('settings.html');
const api=read('api/pets.php'), store=read('api/pets_store.php'), library=read('api/pet_library_store.php');
new Function(app);
assert(app.includes('state.library = data.library || []') &&
  app.includes('state.own.concat(state.library, state.builtin)'),
  'shared presets appear alongside original and personal pets');
assert(!settings.includes('id="petLibraryForm"') && !settings.includes('id="petLibraryFile"'),
  'duplicate system publication upload form is removed');
assert(settings.includes('https://openpets.sh/') && settings.includes('.openpet.zip,.zip,image/png,image/webp'),
  'the single personal upload accepts OpenPet downloads and existing sprites');
assert(settings.includes('id="petManualFields"') && settings.includes('id="petZipHint"'),
  'ZIP gets automatic name/description without extra input');
assert(app.includes('updateFileMode(file)') &&
  app.includes("form.append('file', file)"),
  'ZIP and raw images use the same upload form');
assert(api.includes("if (stu_permission_level_for_user($pdo, $uid) !== COREUI_ROLE_OWNER)") &&
  api.includes('stu_require_csrf();') &&
  api.includes("is_uploaded_file("),
  'backend checks account owner, CSRF and actual upload for publication');
assert(store.includes('coreui_openpet_library_catalog()') &&
  store.includes('coreui_openpet_library_view()') &&
  store.includes("return coreui_pet_default();"),
  'all accounts may select approved pets and recover Mara after unpublish');
assert(library.includes("if (($metadata['rights_confirmed'] ?? '') !== 'yes')") &&
  library.includes("throw new InvalidArgumentException('unverified_license')") &&
  library.includes("if ($zip->numFiles !== 2)") &&
  library.includes('flock($lock, LOCK_EX)'),
  'license proof, ZIP shape and atomic manifest are required');
assert(library.includes("dirname(__DIR__) . '/var/console_media/pet_library'") &&
  library.includes("'url'=>'./api/pets.php?action=library_sprite&id='") &&
  library.includes("getenv('COREUI_PET_LIBRARY_DIR')"),
  'approved sprites are privately stored and authenticated');
assert(!fs.existsSync(path.join(root,'images/pets/openpets')),
  'unlicensed gallery bundle must not be silently copied to public assets');
console.log('CoreAI OpenPet: unified personal ZIP import, separate secure library backend, presets OK');
