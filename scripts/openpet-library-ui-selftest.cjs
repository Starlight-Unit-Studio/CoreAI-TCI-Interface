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
assert(app.includes('can_manage_library') && app.includes('state.canManageLibrary') &&
  app.includes("state.canManageLibrary = data.can_manage_library === true"),
  'owner-only import controls are gated by authenticated server role');
for(const id of ['petLibraryAdmin','petLibraryForm','petLibraryFile','petLibraryLicense',
  'petLibraryHolder','petLibrarySource','petLibraryEvidence','petLibraryConfirm','petLibrarySubmit']){
  assert(settings.includes('id="'+id+'"'), 'missing import control '+id);
}
assert(app.includes("payload.append('action', 'library_import')") &&
  app.includes("payload.append('rights_confirmed', 'yes')") &&
  app.includes("action: pet.library ? 'library_remove' : 'delete'"),
  'owner-controlled publication/removal have distinct API actions');
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
assert(library.includes("return dirname(__DIR__) . '/var/console_media/pet_library'") &&
  library.includes("'url'=>'./api/pets.php?action=library_sprite&id='"),
  'approved sprites are privately stored and authenticated');
assert(!fs.existsSync(path.join(root,'images/pets/openpets')),
  'unlicensed gallery bundle must not be silently copied to public assets');
console.log('CoreAI OpenPet library UI: rights gate, private asset, owner role and presets OK');
