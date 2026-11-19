/* CoreAI: One real development environment for every saved chat.
 * The existing Project Files module is moved, never cloned. The backend
 * provisions a hidden, private workspace for ordinary chats on demand. */
(function () {
  'use strict';
  var $=function(id){return document.getElementById(id);};
  var overlay=$('chatDevModal'),host=$('chatDevHost'),status=$('chatDevStatus');
  var closeButton=$('chatDevClose'),panel=$('projectFilesPanel'),context=$('chatDevContext');
  if (!overlay || !host || !closeButton || !panel) return;
  var marker=document.createComment('Project Files original position');
  panel.parentNode.insertBefore(marker,panel);
  var savedHidden=true,focusReturn=null,request=0,workspace='',open=false;
  function say(text,error){
    status.textContent=String(text||'');
    status.hidden=!text;
    status.classList.toggle('is-error',!!error);
  }
  function restore(){
    if (marker.parentNode) marker.parentNode.insertBefore(panel,marker.nextSibling);
    panel.hidden=savedHidden;
  }
  function close(){
    if (!open) return;
    request++;open=false;
    restore();
    overlay.hidden=true;
    document.body.classList.remove('chat-dev-modal-open');
    if(focusReturn && document.contains(focusReturn) && typeof focusReturn.focus==='function')
      focusReturn.focus();
    focusReturn=null;
  }
  function show(trigger) {
    if(open)return;
    var app=window.CoreAIChatApp;
    if(!app || typeof app.ensureDevWorkspace!=='function')return;
    var files=window.CoreAIProjectFiles;
    if(!files || typeof files.open!=='function')return;
    focusReturn=trigger || document.activeElement;
    savedHidden=panel.hidden;
    open=true;
    overlay.hidden=false;
    document.body.classList.add('chat-dev-modal-open');
    context.textContent='';
    say('Arbeitsbereich wird geladen …');
    closeButton.focus();
    var ticket=++request;
    app.ensureDevWorkspace().then(function(result) {
      if(!open || ticket!==request)return;
      var id=String(result.project_id||'');
      if(!/^[a-f0-9]{32}$/.test(id))throw new Error('Ungültige Arbeitsbereichskennung.');
      if(workspace && workspace!==id && typeof files.leave==='function' && !files.leave()) {
        throw new Error('Die Änderungen im vorherigen Arbeitsbereich wurden nicht verworfen.');
      }
      workspace=id;
      context.textContent=result.kind==='project'?'Projekt-Dateien':'Dieser Chat';
      host.appendChild(panel);
      panel.hidden=false;
      return files.open(id).then(function(){
        if(open && request===ticket)say('');
      });
    }).catch(function(error){
      if(!open || request!==ticket)return;
      say(error && error.message || 'Arbeitsbereich konnte nicht geöffnet werden.',true);
    });
  }
  closeButton.addEventListener('click',close);
  overlay.addEventListener('click',function(e){if(e.target===overlay)close();});
  overlay.addEventListener('keydown',function(e){
    if(e.key==='Escape'){e.preventDefault();close();return;}
    if(e.key!=='Tab')return;
    var focusables=Array.prototype.slice.call(
      overlay.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),textarea:not([disabled]),select:not([disabled]),[tabindex="0"]')
    ).filter(function(node){return node.getClientRects().length>0;});
    if(!focusables.length)return;
    var first=focusables[0],last=focusables[focusables.length-1];
    if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
  });
  window.CoreAIChatDev={open:show,close:close,isOpen:function(){return open;}};
})();
