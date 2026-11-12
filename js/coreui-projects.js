/* Reference-first project UI. All data operations are server-scoped; this
 * module does not own the chat pipeline or alter the existing composer. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  if (!$('coreuiProjectHome') || !$('projectsNavList')) return;
  var projects = [], sessions = [], lockedChats = [], lockedExpanded = false, current = null, tab = 'chats', sources = [];
  var csrf = '', busy = false;
  var api = './api';
  // Sidebar folding is a local UI preference, never server-side project state.
  var FOLDER_STATE_KEY = 'coreai.project-folder-folded.v1';
  var folderState = Object.create(null);
  try {
    var savedFolderState = JSON.parse(localStorage.getItem(FOLDER_STATE_KEY) || '{}');
    if (savedFolderState && typeof savedFolderState === 'object' && !Array.isArray(savedFolderState)) {
      Object.keys(savedFolderState).forEach(function(id) {
        if (/^[a-f0-9]{32}$/.test(id) && typeof savedFolderState[id] === 'boolean') {
          folderState[id] = savedFolderState[id];
        }
      });
    }
  } catch (e) {}
  function folderIsExpanded(id) {
    return Object.prototype.hasOwnProperty.call(folderState, id) ? folderState[id] : id === current;
  }
  function setFolderExpanded(id, expanded) {
    folderState[id] = !!expanded;
    try { localStorage.setItem(FOLDER_STATE_KEY, JSON.stringify(folderState)); } catch (e) {}
  }
  var ICON_FOLDER_CLOSED = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10H3V7Z"/></svg>';
  var ICON_FOLDER_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11V6a2 2 0 0 1 2-2h5l2 3h6a2 2 0 0 1 2 2v2"/><path d="M5 11h16l-2 9H3l2-9Z"/></svg>';
  var ICON_FOLDER_CHEVRON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>';
  var ERR = {
    project_name_required:'Bitte einen Projektnamen eingeben.',
    project_not_found:'Das Projekt wurde nicht gefunden.',
    project_migration_required:'Datenbank-Migration 037 fehlt.',
    project_instructions_too_long:'Die Projektanweisungen sind zu lang.',
    knowledge_format_not_allowed:'Dieser Dateityp kann noch nicht als Quelle verwendet werden.',
    knowledge_file_too_large:'Die Datei ist zu groß.',
    knowledge_text_too_short:'Das Dokument enthält zu wenig lesbaren Text.',
    csrf_failed:'Die Sitzung wurde geändert. Bitte Seite neu laden.',
    project_source_failed:'Die Projektdatei konnte nicht verarbeitet werden.'
  };
  function chatApp() { return window.CoreAIChatApp || null; }
  function fail(err) { var m=err && err.message || 'Unbekannter Fehler'; return ERR[m] || m; }
  function getJson(url) {
    return fetch(url,{credentials:'include',cache:'no-store'}).then(function(r){
      return r.json().then(function(d){ if (!r.ok || !d || !d.ok) throw new Error((d&&d.error)||('HTTP '+r.status)); return d; });
    });
  }
  function post(body) {
    return fetch(api+'/console_sessions.php',{method:'POST',credentials:'include',
      headers:{'Content-Type':'application/json','X-COREUI-CSRF':csrf},body:JSON.stringify(body)})
      .then(function(r){return r.json().then(function(d){if(!r.ok||!d.ok)throw new Error(d.error||'request_failed');return d;});});
  }
  function selected() {return projects.find(function(p){return p.id===current;})||null;}
  function label(txt,cl) {var d=document.createElement('span');d.className=cl||'';d.textContent=String(txt||'');return d;}
  function el(tag,cl,txt) {var d=document.createElement(tag);if(cl)d.className=cl;if(txt!==undefined)d.textContent=txt;return d;}
  function action(labelText,handler,cl) {
    var b=el('button',cl||'',labelText);b.type='button';b.addEventListener('click',handler);return b;
  }
  function renderSidebar() {
    var list = $('projectsNavList');
    var scrollTop = list.scrollTop;
    list.replaceChildren();
    projects.forEach(function(p) {
      var expanded = folderIsExpanded(p.id);
      var row = el('div', 'project-nav-row project-nav-folder-row' +
        (p.id === current ? ' active' : '') + (expanded ? ' is-expanded' : ''));
      var childrenId = 'project-nav-children-' + p.id;
      var toggle = action('', function() {
        setFolderExpanded(p.id, !folderIsExpanded(p.id));
        renderSidebar();
        var replacement = document.getElementById('project-nav-toggle-' + p.id);
        if (replacement) replacement.focus({preventScroll:true});
      }, 'project-nav-folder-toggle');
      toggle.id = 'project-nav-toggle-' + p.id;
      toggle.setAttribute('aria-expanded', String(expanded));
      toggle.setAttribute('aria-controls', childrenId);
      toggle.setAttribute('aria-label', (expanded ? 'Projektordner zuklappen: ' : 'Projektordner aufklappen: ') + p.name);
      toggle.title = expanded ? 'Ordner zuklappen' : 'Ordner aufklappen';
      var chevron = el('span', 'project-nav-chevron');
      chevron.innerHTML = ICON_FOLDER_CHEVRON;
      toggle.appendChild(chevron);
      var icon = el('span', 'project-nav-folder');
      icon.innerHTML = expanded ? ICON_FOLDER_OPEN : ICON_FOLDER_CLOSED;
      toggle.appendChild(icon);
      row.appendChild(toggle);
      // The name keeps its existing behavior: opening the project workspace.
      var open = action('', function() {openProject(p.id);}, 'project-nav-open');
      open.appendChild(label(p.name, 'project-nav-label'));
      open.setAttribute('aria-label', 'Projekt öffnen: ' + p.name);
      row.appendChild(open);
      var options = action('···', function() {
        openProject(p.id);
        openPopover();
      }, 'project-nav-menu');
      options.setAttribute('aria-label', 'Projektaktionen: ' + p.name);
      row.appendChild(options);
      list.appendChild(row);

      var children = el('div', 'project-nav-children');
      children.id = childrenId;
      children.hidden = !expanded;
      if (expanded) {
        var chats = sessions.filter(function(s) {
          return s.project_id === p.id && !s.archived;
        });
        chats.slice(0, 8).forEach(function(s) {
          var entry = action('◯  ' + (s.title || 'Chat'), function() {
            var app = chatApp(); if (app && app.select) app.select(s.id);
          }, 'project-nav-chat');
          entry.title = s.title || 'Chat';
          children.appendChild(entry);
        });
        if (chats.length > 8) {
          children.appendChild(action('Mehr anzeigen', function() {
            openProject(p.id);
          }, 'project-nav-chat project-nav-more'));
        }
      }
      list.appendChild(children);
    });
    // The protected folder remains conditional: zero locked chats means no row.
    if (lockedChats.length) {
      var lockedRow = el('div', 'project-nav-row project-nav-locked');
      var lockedToggle = action('', function() {
        lockedExpanded = !lockedExpanded;
        renderSidebar();
      }, 'project-nav-open');
      lockedToggle.setAttribute('aria-expanded', String(lockedExpanded));
      lockedToggle.setAttribute('aria-label', 'Gesperrte Chats (' + lockedChats.length + ')');
      var lockIcon = el('span', 'project-nav-folder');
      lockIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>';
      lockedToggle.appendChild(lockIcon);
      lockedToggle.appendChild(label('Gesperrte Chats', 'project-nav-label'));
      lockedToggle.appendChild(label(String(lockedChats.length), 'project-nav-count'));
      lockedRow.appendChild(lockedToggle);
      list.appendChild(lockedRow);
      if (lockedExpanded) {
        var lockedChildren = el('div', 'project-nav-children project-nav-locked-children');
        lockedChats.forEach(function(chat) {
          var entry = action('◈  ' + String(chat.title || 'Chat'), function() {
            if (window.CoreAIChatLock && window.CoreAIChatLock.unlockChat) window.CoreAIChatLock.unlockChat(chat);
          }, 'project-nav-chat project-nav-locked-chat');
          entry.title = 'Mit Konto-PIN entsperren';
          lockedChildren.appendChild(entry);
        });
        list.appendChild(lockedChildren);
      }
    }
    list.scrollTop = scrollTop;
  }
  function refreshLocked() {
    return getJson(api+'/console_sessions.php?action=chat_locked_list').then(function(d){
      lockedChats=Array.isArray(d.sessions)?d.sessions:[];
      if(!lockedChats.length) lockedExpanded=false;
      renderSidebar();
      return lockedChats.length;
    });
  }
  function updateSessions(rows) {
    sessions=Array.isArray(rows)?rows.slice():[];
    renderSidebar();
    if(current && tab==='chats') renderChats();
  }
  function refresh() {
    return getJson(api+'/console_sessions.php?action=projects').then(function(d){
      projects=d.projects||[];csrf=d.csrf_token||csrf;
      var app=chatApp();
      if(app && app.getSessions) sessions=app.getSessions();
      if(current && !selected()) closeHome();
      if(!current) {
        var linkedProject='';
        try {linkedProject=new URLSearchParams(location.search).get('project')||'';}catch(e){}
        if(linkedProject && projects.some(function(p){return p.id===linkedProject;})) {
          openProject(linkedProject);
        }
      }
      renderSidebar();
      if(current) {renderHeader();renderChats();}
      return refreshLocked();
    });
  }
  function renderHeader() {
    var p=selected();if(!p)return;
    $('projectHomeName').textContent=p.name;
    $('projectHomeMode').textContent=p.context_mode==='isolated'?'KONTEXT // ISOLIERT':'KONTEXT // STANDARD';
    $('projectHomeMode').title=p.context_mode==='isolated'?'Nur Projektwissen verwenden':'Normale Erinnerungen können verwendet werden';
    $('projectHomeChatInput').placeholder='Neuer Chat in '+p.name;
    $('projectHomePin').textContent=p.pinned?'Projekt nicht mehr anpinnen':'Projekt anpinnen';
  }
  function renderChats() {
    var list=$('projectHomeChatList');list.replaceChildren();if(!current)return;
    var chats=sessions.filter(function(s){return !s.archived && s.project_id===current;});
    if(!chats.length) {list.appendChild(el('p','project-empty','Noch keine Chats. Beginne oben ein Gespräch mit Mara.'));return;}
    chats.sort(function(a,b){return new Date(b.updated_at||0)-new Date(a.updated_at||0);});
    chats.forEach(function(chat){
      var row=el('article','project-chat-result');
      var btn=action('',function(){var a=chatApp();if(a&&a.select)a.select(chat.id);},'project-chat-result-main');
      btn.appendChild(el('strong','',chat.title||'Chat'));
      btn.appendChild(el('small','',(chat.last_message_preview||(''+(chat.message_count||0)+' Nachrichten')).replace(/\s+/g,' ').slice(0,200)));
      row.appendChild(btn);
      var options=el('div','project-chat-result-options');
      var menu=el('div','project-chat-menu');menu.hidden=true;
      options.appendChild(action('···',function(){
        document.querySelectorAll('.project-chat-menu').forEach(function(m){if(m!==menu)m.hidden=true;});
        menu.hidden=!menu.hidden;
      },'project-chat-result-more'));
      function chatAction(title,operation,danger){
        var item=action(title,function(){
          menu.hidden=true;
          operation();
        },danger?'danger':'');
        menu.appendChild(item);
      }
      chatAction('Chat öffnen',function(){var a=chatApp();if(a&&a.select)a.select(chat.id);});
      chatAction('Chat umbenennen',function(){
        var title=prompt('Chat umbenennen',chat.title||'');
        if(title===null||!title.trim())return;
        post({action:'rename',id:chat.id,title:title.trim()}).then(function(){
          var a=chatApp();return a&&a.refresh?a.refresh():null;
        }).then(refresh).catch(function(e){alert(fail(e));});
      });
      chatAction('Aus Projekt entfernen',function(){
        post({action:'project_assign',id:chat.id,project_id:''}).then(function(){
          var a=chatApp();return a&&a.refresh?a.refresh():null;
        }).then(refresh).catch(function(e){alert(fail(e));});
      });
      chatAction('Chat archivieren',function(){
        post({action:'archive',id:chat.id}).then(function(){
          var a=chatApp();return a&&a.refresh?a.refresh():null;
        }).then(refresh).catch(function(e){alert(fail(e));});
      });
      chatAction('Chat löschen',function(){
        if(!confirm('Chat "'+(chat.title||'Chat')+'" unwiderruflich löschen?'))return;
        post({action:'delete',id:chat.id,archive_first:true,confirmation:'DELETE:'+chat.id}).then(function(){
          var a=chatApp();return a&&a.removed?a.removed(chat.id):null;
        }).then(refresh).catch(function(e){alert(fail(e));});
      },true);
      options.appendChild(menu);row.appendChild(options);list.appendChild(row);
    });
  }
  function sourceHref(id,view) {
    return api+'/console_project_sources.php?project_id='+encodeURIComponent(current)
      +'&download='+encodeURIComponent(id)+(view?'&view=1':'');
  }
  function renderSources() {
    var list=$('projectHomeSourceList');list.replaceChildren();
    var filter=$('projectSourceFilter').value,sort=$('projectSourceSort').value;
    var items=sources.filter(function(s){
      var image=String(s.mime_type||'').indexOf('image/')===0;
      return filter==='all'||(filter==='image'&&image)||(filter==='text'&&!image);
    });
    items.sort(function(a,b){
      if(sort==='name')return String(a.title||'').localeCompare(String(b.title||''),'de');
      var diff=new Date(a.created_at||0)-new Date(b.created_at||0);
      return sort==='oldest'?diff:-diff;
    });
    if(!items.length) {list.appendChild(el('p','project-empty','Noch keine Quellen in diesem Projekt.'));return;}
    items.forEach(function(s){
      var row=el('div','project-source-row'),image=String(s.mime_type||'').startsWith('image/');
      var thumb=el('div','project-source-thumb');
      if(image) {
        var pic=document.createElement('img');pic.alt='';pic.loading='lazy';pic.src=sourceHref(s.uuid,true);thumb.appendChild(pic);
      } else thumb.appendChild(label('▤'));
      row.appendChild(thumb);
      var copy=el('div','project-source-filetext');copy.appendChild(el('strong','',s.title||s.original_name));
      copy.appendChild(el('small','',image?'Bilddatei':((Number(s.chunk_count)||0)+' durchsuchbare Abschnitte')));
      row.appendChild(copy);
      var actions=el('div','project-source-actions');
      var download=el('a','project-source-download','↓');
      download.href=sourceHref(s.uuid,false);download.setAttribute('download',s.original_name||s.title);
      download.title='Herunterladen';download.setAttribute('aria-label','Quelle herunterladen');
      actions.appendChild(download);
      actions.appendChild(action('···',function(){
        if(!confirm('Quelle "'+(s.title||s.original_name)+'" aus diesem Projekt löschen?'))return;
        postSource({action:'delete',project_id:current,uuid:s.uuid}).then(function(){return loadSources();})
          .catch(function(e){sourceStatus(fail(e));});
      }));
      row.appendChild(actions);list.appendChild(row);
    });
  }
  function sourceStatus(msg) {$('projectSourceStatus').textContent=msg||'';}
  function loadSources() {
    if(!current)return Promise.resolve();
    var id=current;
    return getJson(api+'/console_project_sources.php?project_id='+encodeURIComponent(id))
      .then(function(d){if(id!==current)return;sources=d.sources||[];csrf=d.csrf_token||csrf;renderSources();})
      .catch(function(e){sourceStatus(fail(e));});
  }
  function postSource(data) {
    return fetch(api+'/console_project_sources.php',{method:'POST',credentials:'include',
      headers:{'Content-Type':'application/json','X-COREUI-CSRF':csrf},body:JSON.stringify(data)})
      .then(function(r){return r.json().then(function(d){if(!r.ok||!d.ok)throw new Error(d.error||'upload_failed');return d;});});
  }
  async function uploadFiles(files) {
    if(busy || !current)return;
    busy=true;var id=current;var processed=0;
    try {
      for(var i=0;i<files.length;i++){
        sourceStatus('Quelle '+(i+1)+' von '+files.length+' wird verarbeitet: '+files[i].name);
        var fd=new FormData();fd.append('project_id',id);fd.append('file',files[i]);
        var response=await fetch(api+'/console_project_sources.php',{method:'POST',credentials:'include',
          headers:{'X-COREUI-CSRF':csrf},body:fd});
        var data=await response.json();if(!response.ok||!data.ok)throw new Error((data&&data.error)||'upload_failed');
        processed++;
      }
      sourceStatus(processed+' Quelle(n) hinzugefügt.');
      await loadSources();
    } catch(e){sourceStatus(processed+' hochgeladen. Fehler: '+fail(e));}
    finally{busy=false;$('projectSourceFile').value='';}
  }
  function openProject(id) {
    if(!projects.some(function(p){return p.id===id;}))return;
    current=id;tab='chats';sourceStatus('');
    $('coreuiProjectHome').hidden=false;
    document.body.classList.add('project-home-active');
    $('projectHomePopover').hidden=true;
    setTab('chats');renderHeader();renderSidebar();renderChats();
    document.getElementById('messagesArea').scrollTop=0;
    try {history.replaceState(null,'',location.pathname+'?project='+encodeURIComponent(id));}catch(e){}
    var app=chatApp();if(app&&app.closeDrawer)app.closeDrawer();
  }
  function closeHome() {
    if(!current && $('coreuiProjectHome').hidden)return;
    current=null;$('coreuiProjectHome').hidden=true;
    document.body.classList.remove('project-home-active');
    $('projectHomePopover').hidden=true;renderSidebar();
    try {if(new URLSearchParams(location.search).has('project'))history.replaceState(null,'',location.pathname);}catch(e){}
  }
  function setTab(next) {
    tab=next;
    $('projectChatsPanel').hidden=next!=='chats';
    $('projectSourcesPanel').hidden=next!=='sources';
    $('projectTabChats').classList.toggle('active',next==='chats');
    $('projectTabSources').classList.toggle('active',next==='sources');
    $('projectTabChats').setAttribute('aria-selected',next==='chats');
    $('projectTabSources').setAttribute('aria-selected',next==='sources');
    if(next==='sources')loadSources();else renderChats();
  }
  function openPopover() {
    var menu=$('projectHomePopover');menu.hidden=!menu.hidden;
    $('projectHomeMore').setAttribute('aria-expanded',String(!menu.hidden));
  }
  function openModal(which) {
    $('projectModalError').textContent='';
    $('projectModalCreate').hidden=which!=='create';
    $('projectModalSettings').hidden=which!=='settings';
    $('projectModalShare').hidden=which!=='share';
    $('projectModalTitle').textContent=which==='create'?'Neues Projekt':which==='share'?'Projekt teilen':'Projekteinstellungen';
    var p=selected();
    if(which==='create') {$('projectCreateName').value='';$('projectCreateMode').value='open';}
    if(which==='settings' && p) {
      $('projectSettingsName').value=p.name;
      $('projectSettingsInstructions').value=p.instructions_md||'';
      $('projectSettingsMode').value=p.context_mode;
      $('projectSettingsModeHint').textContent=p.context_mode==='isolated'
        ? 'Nur Inhalte dieses Projekts. Der Erinnerungsmodus bleibt aus Sicherheitsgründen nach Erstellung unverändert.'
        : 'Dieses Projekt kann zusätzlich auf die normalen Erinnerungen zugreifen. Der Erinnerungsmodus bleibt unverändert.';
    }
    $('projectModalScrim').hidden=false;
    var focus=which==='create'?$('projectCreateName'):which==='settings'?$('projectSettingsName'):$('projectModalClose');
    if(focus)focus.focus();
  }
  function closeModal() {$('projectModalScrim').hidden=true;}
  function modalError(e) {$('projectModalError').textContent=fail(e);}
  function runAction(task) {
    var p=selected();if(!p)return;
    $('projectHomePopover').hidden=true;
    if(task==='home'){openProject(p.id);return;}
    if(task==='share'){openModal('share');return;}
    if(task==='settings'||task==='rename'){openModal('settings');return;}
    if(task==='pin') {
      post({action:'project_pin',project_id:p.id,pinned:!p.pinned}).then(refresh).catch(function(e){alert(fail(e));});
      return;
    }
    if(task==='delete')deleteProject();
  }
  function deleteProject() {
    var p=selected();if(!p)return;
    if(!confirm('Projekt "'+p.name+'" löschen? Projektquellen und Anweisungen werden gelöscht. Die Chats bleiben erhalten und werden aus dem Projekt gelöst.'))return;
    post({action:'project_delete',project_id:p.id,confirmation:'DELETE:'+p.id}).then(function(){
      closeModal();closeHome();
      var a=chatApp();return a&&a.refresh?a.refresh():null;
    }).then(refresh).catch(modalError);
  }
  function assignChat(chatId) {
    if(!projects.length){openModal('create');return;}
    var choices=projects.map(function(p,i){return (i+1)+'. '+p.name+' ('+(p.context_mode==='isolated'?'isoliert':'Standard')+')';}).join('\n');
    var answer=prompt('Chat zu einem Projekt hinzufügen. Nummer wählen oder 0 zum Entfernen:\n0. Kein Projekt\n'+choices,'1');
    if(answer===null)return;
    var index=Number(answer);if(!Number.isInteger(index)||index<0||index>projects.length){alert('Ungültige Auswahl');return;}
    var p=index>0?projects[index-1]:null;
    if(p&&p.context_mode==='isolated' && !confirm('Dieser Chat kann früheren Kontext enthalten. Trotzdem in ein isoliertes Projekt verschieben?'))return;
    post({action:'project_assign',id:String(chatId),project_id:p?p.id:''}).then(function(){
      var a=chatApp();return a&&a.refresh?a.refresh():null;
    }).then(refresh).catch(function(e){alert(fail(e));});
  }
  $('projectsCreateBtn').addEventListener('click',function(){openModal('create');});
  $('projectHomeDrawerToggle').addEventListener('click',function(){var app=chatApp();if(app&&app.openDrawer)app.openDrawer();});
  $('projectModalClose').addEventListener('click',closeModal);
  $('projectModalScrim').addEventListener('click',function(e){if(e.target===this)closeModal();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeModal();$('projectHomePopover').hidden=true;}});
  $('projectCreateConfirm').addEventListener('click',function(){
    var name=$('projectCreateName').value.trim(),mode=$('projectCreateMode').value;
    if(!name){modalError(new Error('project_name_required'));return;}
    post({action:'project_create',name:name,context_mode:mode}).then(function(d){
      closeModal();return refresh().then(function(){openProject(d.project.id);});
    }).catch(modalError);
  });
  $('projectSettingsSave').addEventListener('click',function(){
    var p=selected();if(!p)return;
    post({action:'project_update',project_id:p.id,name:$('projectSettingsName').value,
      instructions_md:$('projectSettingsInstructions').value}).then(refresh).then(function(){closeModal();})
      .catch(modalError);
  });
  $('projectSettingsDelete').addEventListener('click',deleteProject);
  $('projectHomeShare').addEventListener('click',function(){openModal('share');});
  $('projectHomeMore').addEventListener('click',openPopover);
  $('projectHomePopover').querySelectorAll('[data-project-action]').forEach(function(b){
    b.addEventListener('click',function(){runAction(b.getAttribute('data-project-action'));});
  });
  $('projectTabChats').addEventListener('click',function(){setTab('chats');});
  $('projectTabSources').addEventListener('click',function(){setTab('sources');});
  $('projectHomeAttach').addEventListener('click',function(){setTab('sources');});
  $('projectSourceSort').addEventListener('change',renderSources);
  $('projectSourceFilter').addEventListener('change',renderSources);
  $('projectSourceFile').addEventListener('change',function(){uploadFiles(Array.from(this.files||[]));});
  $('projectHomeChatForm').addEventListener('submit',function(e){
    e.preventDefault();var msg=$('projectHomeChatInput').value.trim();
    if(!msg || !current)return;
    var app=chatApp();if(!app||!app.startProjectChat)return;
    $('projectHomeChatInput').value='';
    app.startProjectChat(current,msg);
  });
  window.CoreAIProjects={refresh:refresh,openProject:openProject,closeHome:closeHome,
    updateSessions:updateSessions,assignChat:assignChat,refreshLocked:refreshLocked};
  refresh().catch(function(e){console.warn('[CoreAI] Projects deferred until sign-in:',e.message);});
})();