/* CoreAI 0.6.71: explicit interface translations, never machine translate
   chat content, prompts, memory facts, account data or model output. */
(function(root){
  'use strict';
  var en = {
    'EINSTELLUNGEN':'SETTINGS',
    'KATEGORIEN':'CATEGORIES',
    'Kategorien':'Categories',
    'PERSÖNLICH':'PERSONAL',
    'Allgemein':'General',
    'Darstellung':'Appearance',
    'Anzeige':'Appearance',
    'Benachrichtigungen':'Notifications',
    'Profil':'Profile',
    'Sicherheit und Anmeldung':'Security and sign-in',
    'Konto':'Account',
    'Archivierte Chats':'Archived chats',
    'Stimme':'Voice',
    'Speicher':'Storage',
    'Personalisierung':'Personalization',
    'Pets':'Pets',
    'Tastatur':'Keyboard',
    'Nutzung':'Usage',
    'Datenkontrollen':'Data controls',
    'TEAMS':'TEAMS',
    'Startseite':'Home',
    'Dein erstes Team erstellen':'Create your first team',
    'INTEGRATIONEN':'INTEGRATIONS',
    'Plugins':'Plugins',
    'Passwörter':'Passwords',
    'Cloud-Computer':'Cloud computer',
    'PROGRAMMIERUNG':'PROGRAMMING',
    'Codex Cloud':'Codex Cloud',
    'Code Review':'Code review',
    'COREAI // FUNKTIONEN':'COREAI // FEATURES',
    'KI-Profil':'AI profile',
    'Wissen':'Knowledge',
    'Memory':'Memory',
    'COREAI // SYSTEM':'COREAI // SYSTEM',
    'VERWALTUNG':'MANAGEMENT',
    'Admin Core':'Admin Core',
    'GEPLANT':'PLANNED',
    'SEPARAT':'SEPARATE',
    'EXPERTENEINSTELLUNGEN':'EXPERT SETTINGS',
    'EXPERTENMODUS':'EXPERT MODE',
    'Sicherheit':'Security',
    'Daten exportieren':'Export data',
    'MEMORY-EINSTELLUNGEN SPEICHERN':'SAVE MEMORY SETTINGS',
    'Technische Optionen in jeder Kategorie ein- oder ausblenden':'Show or hide technical controls within each category',
    'Sprache':'Language',
    'Sprache der App-Oberfläche':'Application interface language',
    'Tastenkürzel zum Senden':'Send shortcut',
    'Lege fest, ob Enter die Nachricht sendet oder eine neue Zeile einfügt.':'Choose whether Enter sends a message or inserts a new line.',
    'Höhere Intelligenz':'Higher intelligence',
    'Bei komplexen Fragen automatisch auf einen leistungsfähigeren Core wechseln.':'Use a higher installed Core for complex text requests when available.',
    'Verfügbare Reasoning-Stufen':'Reasoning effort',
    'Wähle künftig aus, welche Aufwandsstufen in der Core-Steuerung angezeigt werden.':'Choose a thinking effort supported by your selected Core.',
    'Im Enter-Modus fügt Shift + Enter einen Zeilenumbruch ein. Im zweiten Modus schreibt Enter eine neue Zeile.':'In Enter mode, Shift + Enter inserts a line break. In the other mode, Enter inserts a line break.',
    'Grundlegende Bedienung deiner CoreAI. Technische Optionen bleiben in ihrer jeweiligen Kategorie.':'CoreAI basics. Technical options stay in their original categories.',
    'CORE':'CORE',
    'DENKEN':'THINKING',
    'SYSTEM':'SYSTEM',
    'WISSEN':'KNOWLEDGE',
    'DEIN PROFIL':'YOUR PROFILE',
    'MEIN COREAI':'MY COREAI',
    'ANZEIGE ZURÜCKSETZEN':'RESET APPEARANCE',
    'Tag- und Nachtmodus':'Light and dark mode',
    'Bewegung und Layout':'Motion and layout',
    'Chat und Live-Browser':'Chat and live browser',
    'Erscheinungsbild':'Appearance',
    'AKTUELL':'CURRENT',
    'IN VORBEREITUNG':'COMING LATER',
    'Diese Unterseite ist vorbereitet':'This settings page is ready for future features',
    'Es werden hier noch keine neuen Kontodaten gespeichert und keine zusätzlichen Dienste aktiviert.':'No new account data is stored here and no additional services are enabled.',
    'EINLOGGEN':'SIGN IN',
    'ALS GAST CHATTEN':'CHAT AS GUEST',
    'PASSWORT':'PASSWORD',
    'PASSWORT WIEDERHOLEN (MIN. 8 ZEICHEN)':'CONFIRM PASSWORD (MIN. 8 CHARACTERS)',
    'ANZEIGENAME (OPTIONAL)':'DISPLAY NAME (OPTIONAL)',
    'NOCH KEIN KONTO? REGISTRIEREN':'NEW HERE? REGISTER',
    'NEUE VERBINDUNG':'NEW CONVERSATION',
    'SITZUNGSMATRIX':'CONVERSATIONS',
    'ARCHIV':'ARCHIVE',
    'EINSTELLUNGEN':'SETTINGS',
    'PROTOKOLLE':'LOGS',
    'ABMELDEN':'SIGN OUT',
    'Was möchtest du heute machen?':'What would you like to do today?',
    'CORE-STUFEN':'CORE TIERS',
    'FORMATIERUNG':'FORMATTING',
    'BEFEHLE UND TASTEN':'COMMANDS AND KEYS',
    'Cores verwalten':'Manage Cores',
    'Jetzt komprimieren':'Compress now',
    'Core installieren':'Install Core',
    'Jetzt aktualisieren':'Update now',
    'Verbindung prüfen':'Check connection',
    'Abbrechen':'Cancel',
    'Installieren':'Install',
    'CORE CHANNEL':'CORE CHANNEL',
    'ZURÜCK':'BACK',
    '← ZURÜCK':'← BACK',
    '← CORE CHANNEL':'← CORE CHANNEL',
    'Kategorien schließen':'Close categories'
  };
  var original = new WeakMap();
  var selectors = [
    '#cfgNav .cfg-nav-label', '#cfgNav .cfg-tab span',
    '#cfgNav .cfg-nav-state', '.cfg-menu-head strong',
    '.cfg-expert-toggle strong', '.cfg-expert-toggle small',
    '.settings-header h1', '.cfg-view-head h2', '.cfg-view-head p',
    '.cfg-general-copy label', '.cfg-general-copy p', '.cfg-general-control small',
    '.cfg-kicker', '.cfg-section-title', '.cfg-planned-state',
    '.cfg-header-expert > span:first-child', '.cfg-admin-shortcut',
    '.cfg-planned-card h3', '.cfg-planned-card p',
    '#loginBtn', '#guestBtn', '#loginModeToggle', '.login-card label',
    '#btnNewSession strong', '#btnNewSession span', '#sessionArchiveToggle',
    '#btnSettings', '#btnProtocols', '#btnLogout', '#welcomeQuestion',
    '#coreLegendTiers', '#coreLegendFormat', '#coreLegendKeys',
    '#coreModeManage', '#compactNow', '#coreInstallTitle',
    '#coreInstallUpdateNow', '#coreInstallToManage', '#coreInstallCloudCheck',
    '#coreInstallCancel', '#coreInstallGo', '#protoBack'
  ].join(',');
  function current() {
    var p = root.CoreUIPreferences && root.CoreUIPreferences.load();
    var choice = p && p.uiLanguage || 'auto';
    var browser = root.navigator && root.navigator.language || 'de';
    return choice === 'auto' ? (/^en(?:-|$)/i.test(browser) ? 'en' : 'de') : choice;
  }
  function apply(){
    if (!root.document || !root.document.querySelectorAll) return;
    var lang = current();
    root.document.documentElement.lang = lang;
    root.document.documentElement.setAttribute('data-coreui-ui-lang',lang);
    Array.prototype.forEach.call(root.document.querySelectorAll(selectors), function(node){
      if (!node || node.children && node.children.length) return;
      if (!original.has(node)) original.set(node, String(node.textContent || ''));
      var de = original.get(node), key = de.trim();
      if (!Object.prototype.hasOwnProperty.call(en,key)) return;
      var replacement = lang === 'en' ? en[key] : key;
      node.textContent = de.replace(key,replacement);
    });
    var placeholders = [['settingsNavSearch','Einstellungen durchsuchen','Search settings'],
      ['inputMessage','Nachricht an CoreAI…','Message CoreAI…']];
    placeholders.forEach(function(entry){
      var input = root.document.getElementById(entry[0]);
      if(input && input.getAttribute('placeholder') !== null)
        input.setAttribute('placeholder',lang === 'en' ? entry[2] : entry[1]);
    });
  }
  root.CoreUIUiLanguage = Object.freeze({apply:apply,current:current});
  if (root.document) {
    if (root.document.readyState === 'loading') {
      root.document.addEventListener('DOMContentLoaded',apply);
    } else apply();
    root.addEventListener('coreui:preferences',apply);
  }
})(typeof window !== 'undefined' ? window : this);
