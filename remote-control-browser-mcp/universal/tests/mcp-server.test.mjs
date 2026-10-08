import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root=dirname(dirname(fileURLToPath(import.meta.url)));
const token='safe-test-token';
const port=5598;
const url='http://127.0.0.1:'+port+'/mcp';

async function call(name,args={}){
  const rsp=await fetch(url,{method:'POST',headers:{'Authorization':'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name,arguments:args}})});
  assert.equal(rsp.status,200);
  const json=await rsp.json();
  assert.equal(json.result?.isError,false,JSON.stringify(json).slice(0,500));
  return JSON.parse(json.result.content[0].text);
}

test('MCP keeps live tabs across calls and accepts tab_id for inspection',{timeout:90000}, async () => {
  const data=mkdtempSync(resolve(tmpdir(),'sv-universal-http-test-'));
  const server=spawn(process.execPath,[resolve(root,'mcp-server.mjs')],{
    cwd:root,env:{PATH:process.env.PATH,HOME:process.env.HOME,LANG:'C.UTF-8',
      SHOPVIVALIZ_REMOTE_MCP_TOKEN:token,SHOPVIVALIZ_BROWSER_UNIVERSAL_PORT:String(port),
      SHOPVIVALIZ_BROWSER_UNIVERSAL_DATA_DIR:data,
      SHOPVIVALIZ_BROWSER_UNIVERSAL_PROFILE_DIR:resolve(data,'profile')},
    stdio:['ignore','ignore','pipe'],
  });
  let log='';
  server.stderr.on('data',b=>{log+=b.toString().slice(0,500)});
  try {
    let healthy=false;
    for(let i=0;i<60;i++){
      try{const rsp=await fetch('http://127.0.0.1:'+port+'/health');if(rsp.ok){healthy=true;break;}}catch{}
      await new Promise(r=>setTimeout(r,100));
    }
    assert.equal(healthy,true,'server health startup: '+log);
    const denied=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
    assert.equal(denied.status,401);
    const created=await call('browser_universal_tabs_open',{url:'https://example.com/'});
    assert.ok(created.active);
    const selected=await call('browser_universal_tabs_switch',{tab_id:created.active});
    assert.equal(selected.reloaded,false,'tab switch must reuse existing live page');
    const inspected=await call('browser_universal_inspect',{tab_id:created.active});
    assert.equal(inspected.title,'Example Domain');
    const childPid=Number(execFileSync('pgrep',['-P',String(server.pid)]).toString().trim().split('\n')[0]);
    assert.ok(childPid>0,'worker child process exists');
    process.kill(childPid,'SIGTERM');
    await new Promise(r=>setTimeout(r,800));
    const recovered=await call('browser_universal_tabs_switch',{tab_id:created.active});
    assert.equal(recovered.reloaded,true,'worker restart reloads saved URL');
    const closed=await call('browser_universal_tabs_close',{tab_id:created.active});
    assert.equal(closed.tabsCount,0);
  } finally {
    server.kill('SIGTERM');
    await new Promise(r=>{if(server.exitCode!==null||server.signalCode!==null)return r();server.once('exit',r);setTimeout(r,2000)});
    rmSync(data,{recursive:true,force:true});
  }
});
