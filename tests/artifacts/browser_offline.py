"""Chromium component tests with explicit fetch and Blob-save test doubles.

No HTTP, database, Docker, native download or Android end-to-end validation.
Run: python3 tests/artifacts/browser_offline.py
Requires Playwright and Chromium. CHROMIUM_PATH may override the executable.
"""
import base64
import io
import os
from pathlib import Path
import zipfile
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
HTML = b'<!doctype html><title>Test</title><script>window.NOT_ALLOWED=1</script>'
buffer = io.BytesIO()
with zipfile.ZipFile(buffer, 'w') as archive:
    archive.writestr('index.html', HTML)
    archive.writestr('info.txt', 'ZIP test')
ZIP = buffer.getvalue()
FILES = [
    {'id': 'a'*32, 'name': 'Gr\u00fc\u00dfe \u661f.html', 'mime_type': 'text/html', 'size_bytes': len(HTML), 'available': True, 'partial': False},
    {'id': 'b'*32, 'name': 'Projekt.zip', 'mime_type': 'application/zip', 'size_bytes': len(ZIP), 'available': True, 'partial': False},
]
ROW = '<div class="msg-row ember" data-message-id="20"><div class="msg-content"><div class="msg-name">EMBER</div><p>Die Dateien stehen bereit.</p><div class="msg-actions">Antwortaktionen</div><div class="msg-time">19:30</div></div></div>'
SETUP = r'''({files,bodies}) => {
  window.testState={files,bodies,lists:[],requests:[],saves:[],mode:'success',failList:false,schemaReady:true,hold:false,waiting:[]};
  window.fetch=async (url,options) => {
    let u=new URL(url), t=testState; t.requests.push({path:u.pathname,credentials:options.credentials});
    if(u.pathname.endsWith('/console_artifacts.php')) {
      let ids=u.searchParams.get('message_ids').split(','); t.lists.push(ids);
      if(t.hold) await new Promise(resolve=>t.waiting.push(resolve));
      if(t.failList) return new Response('{}',{status:500});
      let map={}; for(let id of ids) if(id==='20') map[id]=t.files;
      else if(id==='22') map[id]=[{...t.files[0],partial:true}];
      return new Response(JSON.stringify({ok:true,artifacts:map,schema_ready:t.schemaReady}),{headers:{'Content-Type':'application/json'}});
    }
    if(!u.pathname.endsWith('/console_artifact.php')) throw new Error('unexpected_route');
    let status=Number(t.mode); if(status) return new Response('{}',{status});
    if(t.mode==='json') return new Response('{}',{headers:{'Content-Type':'application/json'}});
    let bytes=Uint8Array.from(atob(t.bodies[u.searchParams.get('id')]),c=>c.charCodeAt(0));
    if(t.mode==='truncated') bytes=bytes.slice(0,1);
    return new Response(bytes,{headers:{'Content-Type':'application/octet-stream'}});
  };
  let objects=new Map(), sequence=0;
  URL.createObjectURL=blob=>{let u='blob:offline-test-'+(++sequence);objects.set(u,blob);return u;};
  URL.revokeObjectURL=u=>objects.delete(u);
  document.addEventListener('click',event=>{
    let a=event.target;
    if(a.tagName==='A' && a.hidden && objects.has(a.getAttribute('href'))) {
      event.preventDefault(); testState.saves.push({name:a.download,blob:objects.get(a.getAttribute('href'))});
    }
  });
}'''


def main():
    passed = []
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), args=['--no-sandbox'])
        page = browser.new_page(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_content('<html lang="de"><head><base href="https://coreui.test/test/app.html"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="messagesInner">'+ROW+'</div></body></html>')
        page.add_style_tag(content=(ROOT/'css/console.css').read_text())
        page.add_style_tag(content=(ROOT/'css/artifacts.css').read_text())
        page.add_style_tag(content='body{margin:0;padding:16px;box-sizing:border-box}.msg-row,.msg-content,#messagesInner{width:100%;min-width:0;max-width:100%}')
        page.evaluate(SETUP, {'files': FILES, 'bodies': {'a'*32: base64.b64encode(HTML).decode(), 'b'*32: base64.b64encode(ZIP).decode()}})
        page.add_script_tag(content=(ROOT/'js/coreui-artifacts.js').read_text())
        page.locator('.msg-artifact-link').nth(1).wait_for()
        assert page.evaluate('testState.lists') == [['20']]
        passed.append('one batched metadata read for the rendered message')
        for index, body in enumerate((HTML, ZIP)):
            page.locator('.msg-artifact-link').nth(index).click()
            page.wait_for_function('(n)=>testState.saves.length===n', arg=index+1)
            saved = page.evaluate('async n=>({name:testState.saves[n].name,bytes:Array.from(new Uint8Array(await testState.saves[n].blob.arrayBuffer())),type:testState.saves[n].blob.type})', index)
            assert saved['name'] == FILES[index]['name'] and bytes(saved['bytes']) == body
            assert saved['type'] == 'application/octet-stream'
            passed.append('captured Blob-save has correct name, bytes and type: '+FILES[index]['name'])
        assert page.evaluate('window.NOT_ALLOWED') is None
        passed.append('HTML output is never executed in the document')
        for mode in ('401', '404', '409', '410', 'json', 'truncated'):
            page.evaluate('m=>testState.mode=m', mode)
            page.locator('.msg-artifact-link').first.click()
            page.wait_for_function("!document.querySelector('.msg-artifact-link').hasAttribute('aria-busy')")
            assert page.evaluate('testState.saves.length') == 2
            assert page.locator('.msg-artifact-status').first.inner_text() != 'Download gestartet.'
            passed.append('visible error and no false file save: '+mode)
        page.evaluate("testState.mode='success'")
        for _ in range(2):
            page.evaluate('r=>document.getElementById("messagesInner").innerHTML=r', ROW)
            page.locator('.msg-artifact-link').nth(1).wait_for()
            assert page.locator('.msg-artifact-link').count() == 2
        passed.append('history re-render restores cards without duplicates')
        page.evaluate('document.getElementById("messagesInner").insertAdjacentHTML("beforeend",\'<div class="msg-row ember" data-message-id="22"><div class="msg-content"></div></div>\')')
        page.locator('.msg-artifact-warning').wait_for()
        passed.append('new final row gets its partial-result label')
        page.evaluate('files=>document.getElementById("messagesInner").append(CoreUIArtifacts.render(files))', [
            {**FILES[0], 'id': 'c'*32, 'available': False, 'name': '<img src=x onerror=alert(1)>.txt'},
            {**FILES[0], 'id': 'javascript:alert(1)'}, {**FILES[0], 'name': '../outside'}])
        assert page.locator('[aria-disabled="true"]').count() == 1
        assert page.locator('img[onerror],a[href^="javascript:"]').count() == 0
        passed.append('missing files disabled and hostile names/paths/IDs rejected')
        assert page.evaluate('document.documentElement.scrollWidth<=window.innerWidth')
        passed.append('390px mobile layout has no horizontal overflow')
        screenshot = os.environ.get('ARTIFACT_SCREENSHOT')
        if screenshot:
            page.screenshot(path=screenshot, full_page=True)
        for state in ('failList', 'schemaReady'):
            page.evaluate('({r,s})=>{testState[s]=(s==="failList");document.getElementById("messagesInner").innerHTML=r;}', {'r': ROW, 's': state})
            page.locator('.msg-artifact-retry').wait_for()
            page.evaluate('testState.failList=false;testState.schemaReady=true')
            page.locator('.msg-artifact-retry').click()
            page.locator('.msg-artifact-link').nth(1).wait_for()
        passed.append('failed metadata read and missing migration have working retry')
        page.evaluate('r=>{testState.hold=true;document.getElementById("messagesInner").innerHTML=r}', ROW)
        page.wait_for_function('testState.waiting.length===1')
        page.evaluate('document.getElementById("messagesInner").innerHTML=\'<div class="msg-row ember" data-message-id="999"><div class="msg-content">Other session</div></div>\';testState.hold=false;testState.waiting.shift()()')
        page.wait_for_timeout(150)
        assert page.locator('.msg-artifacts').count() == 0
        passed.append('late metadata cannot cross into a replacement session row')
        assert errors == [], errors
        assert all(r['credentials'] == 'same-origin' for r in page.evaluate('testState.requests'))
        passed.append('no JavaScript errors; authenticated fetch options preserved')
        browser.close()
    for result in passed:
        print('PASS:', result)
    print('OFFLINE COMPONENT CHECKS:', len(passed), '(fetch/save are doubles; NOT native-download E2E)')


if __name__ == '__main__':
    main()
