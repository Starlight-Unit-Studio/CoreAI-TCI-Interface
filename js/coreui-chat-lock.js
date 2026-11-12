/* CoreAI: account PIN chat lock flow. Uses existing server sessions and theme tokens. */
(function(){
  'use strict';
  var appApi='./api/console_sessions.php';
  var activeTarget=null, mode='', busy=false, csrf='', stage=0, firstPin='';
  var root=document.createElement('div');
  root.className='chat-dialog chat-lock-dialog';
  root.id='chatLockDialog';
  root.hidden=true;
  root.setAttribute('role','dialog');root.setAttribute('aria-modal','true');
  root.setAttribute('aria-labelledby','chatLockTitle');
  root.innerHTML='<div class="chat-dialog-box"><h2 id="chatLockTitle"></h2><p id="chatLockDescription"></p><label class="chat-lock-field" for="chatLockPin">KONTO-PIN<input id="chatLockPin" type="password" inputmode="numeric" maxlength="4" minlength="4" pattern="[0-9]{4}" autocomplete="off" /></label><p id="chatLockError" class="chat-dialog-error" role="alert"></p><div class="chat-dialog-actions stacked"><button id="chatLockConfirm" type="button" class="chat-dialog-primary">Bestätigen</button><button id="chatLockCancel" type="button" class="chat-dialog-secondary">Abbrechen</button></div></div>';
  document.body.appendChild(root);
  var $=function(id){return document.getElementById(id);};
  var messages={
    chat_pin_not_set:'Bitte zuerst eine Konto-PIN einrichten.',
    invalid_pin:'Bitte genau vier Ziffern eingeben.',
    invalid_pin_confirmation:'Die PIN-Bestätigung stimmt nicht überein.',
    chat_pin_incorrect:'Die eingegebene PIN ist falsch.',
    chat_pin_temporarily_blocked:'Nach fünf Fehlversuchen ist die PIN für 15 Minuten gesperrt.',
    chat_lock_migration_required:'Das Update benötigt noch Datenbank-Migration 038.',
    session_busy:'Dieser Chat ist noch beschäftigt und kann momentan nicht gesperrt werden.',
    session_not_found:'Der Chat ist nicht mehr verfügbar.',
    csrf_failed:'Sitzung geändert. Bitte die Seite neu laden.',
    guest_not_allowed:'Gäste können keine Chats sperren.'
  };
  function err(error){return messages[error&&error.message]||'Aktion fehlgeschlagen: '+String(error&&error.message||'unbekannter Fehler');}
  function request(action,payload){
    var method=payload?'POST':'GET';
    var url=method==='GET'?appApi+'?action='+encodeURIComponent(action):appApi;
    var options={method:method,credentials:'include',cache:'no-store',headers:{'Accept':'application/json'}};
    if(payload){options.headers['Content-Type']='application/json';options.headers['X-COREUI-CSRF']=csrf;options.body=JSON.stringify(Object.assign({action:action},payload));}
    return fetch(url,options).then(function(r){return r.json().catch(function(){return {};}).then(function(d){if(!r.ok||!d.ok)throw new Error(d.error||'HTTP_'+r.status);return d;});});
  }
  function status(){return request('chat_lock_status').then(function(d){csrf=d.csrf_token||csrf;if(!d.ready)throw new Error('chat_lock_migration_required');return d;});}
  function close(){if(busy)return;root.hidden=true;activeTarget=null;firstPin='';$('chatLockPin').value='';}
  function show(target,action,step){
    activeTarget=target;mode=action;stage=step||0;firstPin='';$('chatLockError').textContent='';
    var setup=mode==='setup';
    $('chatLockTitle').textContent=setup?'Konto-PIN einrichten':mode==='unlock'?'Chat entsperren?':'Diesen Chat sperren?';
    $('chatLockDescription').textContent=setup?'Lege eine vierstellige Konto-PIN fest. Sie gilt für alle gesperrten Chats und muss bestätigt werden.':mode==='unlock'?'Mit deiner Konto-PIN wird dieser Chat wieder freigegeben.':'Der Chat wird aus den normalen Chatlisten entfernt. Zum Entsperren benötigst du deine Konto-PIN.';
    $('chatLockConfirm').textContent=setup?'PIN weiter':mode==='unlock'?'Entsperren':'Chat sperren';
    root.querySelector('.chat-lock-field').hidden=mode==='lock';
    $('chatLockPin').value='';root.hidden=false;(mode==='lock'?$('chatLockConfirm'):$('chatLockPin')).focus();
  }
  function refresh(){
    var a=window.CoreAIChatApp,p=window.CoreAIProjects;
    if(a&&a.refresh) return Promise.resolve(a.refresh()).then(function(){if(p&&p.refreshLocked)return p.refreshLocked();});
    if(p&&p.refreshLocked)return p.refreshLocked();
    return Promise.resolve();
  }
  function finish(){busy=false;$('chatLockConfirm').disabled=false;root.hidden=true;activeTarget=null;firstPin='';return refresh();}
  function beginLock(chat){
    if(!chat||!chat.id)return;
    status().then(function(d){show(chat,d.has_pin?'lock':'setup');}).catch(function(e){window.alert(err(e));});
  }
  function unlockChat(chat){
    if(!chat||!chat.id)return;
    status().then(function(d){if(!d.has_pin)throw new Error('chat_pin_not_set');show(chat,'unlock');}).catch(function(e){window.alert(err(e));});
  }
  function submit(){
    if(busy||!activeTarget)return;
    var pin=$('chatLockPin').value.trim();
    $('chatLockError').textContent='';
    if(mode!=='lock'&&!/^[0-9]{4}$/.test(pin)){$('chatLockError').textContent=messages.invalid_pin;return;}
    if(mode==='setup'&&stage===0){
      firstPin=pin;stage=1;$('chatLockTitle').textContent='Konto-PIN bestätigen';
      $('chatLockDescription').textContent='Gib dieselbe vierstellige PIN erneut ein.';
      $('chatLockConfirm').textContent='PIN einrichten und Chat sperren';
      $('chatLockPin').value='';$('chatLockPin').focus();return;
    }
    if(mode==='setup'&&! (pin===firstPin)){
      $('chatLockError').textContent=messages.invalid_pin_confirmation;
      $('chatLockPin').value='';return;
    }
    busy=true;$('chatLockConfirm').disabled=true;
    var chatId=activeTarget.id;
    var task=mode==='setup'?request('chat_lock_setup',{pin:firstPin,confirmation:pin}).then(function(){return request('chat_lock',{id:chatId});}):
      mode==='unlock'?request('chat_unlock',{id:chatId,pin:pin}):request('chat_lock',{id:chatId});
    task.then(function(){
      busy=false;$('chatLockConfirm').disabled=false;
      if(mode!=='unlock'){
        var a=window.CoreAIChatApp;if(a&&a.removed)return Promise.resolve(a.removed(chatId)).then(finish);
      }
      return finish();
    }).catch(function(e){busy=false;$('chatLockConfirm').disabled=false;$('chatLockError').textContent=err(e);$('chatLockPin').value='';});
  }
  $('chatLockConfirm').addEventListener('click',submit);
  $('chatLockCancel').addEventListener('click',close);
  $('chatLockPin').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();submit();}if(e.key==='Escape')close();});
  root.addEventListener('click',function(e){if(e.target===root)close();});
  window.CoreAIChatLock={lockChat:beginLock,unlockChat:unlockChat};
})();