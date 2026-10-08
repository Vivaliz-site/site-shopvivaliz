import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createOkxReadBridge } from '../mcp-bridge.mjs';
const OKX_UID='822315791411831486';
const fakeReply={content:[{type:'text',text:JSON.stringify({ok:true,data:{data:[{uid:OKX_UID,mainUid:'MAIN'}]}})}]};
async function setup(){
 let invokes=[];
 const conn={listTools:async()=>({tools:[{name:'account_get_config',inputSchema:{type:'object'}},{name:'trade_place_order',inputSchema:{type:'object'}}]}),
 callTool:async ({name})=>{invokes.push(name);return fakeReply},close:async()=>{},ping:async()=>{}};
 const copy={listTools:()=>[{name:'okx.copy.account.verify',inputSchema:{type:'object'}},{name:'okx.copy.stop',inputSchema:{type:'object'}}],
 call:async(t)=>({verified:true,uid:OKX_UID,called:t}),health:async()=>({enabled:true,write_enabled:false})};
 const srv=createOkxReadBridge({port:0,transportFactory:async()=>conn,
 copyFactory:()=>copy,restClient:{get:async()=>[],post:async()=>{throw Error('unreachable')}}});
 await once(srv,'listening');
 return {url:'http://127.0.0.1:'+srv.address().port,
 invokes,close:()=>new Promise((resolve,reject)=>srv.close(e=>e?reject(e):resolve()))};
}
test('old read tools remain callable, writes blocked, and 18-tool extension exposed',async()=>{
 const env=await setup();try{
  const health=await (await fetch(env.url+'/health')).json();
  assert.equal(health.upstream_connected,true);assert.equal(health.copy_tool_count,2);
  const all=await (await fetch(env.url+'/v1/tools')).json();
  assert.equal(all.tools.length,4);
  async function call(tool){
   const res=await fetch(env.url+'/v1/call',{method:'POST',headers:{'content-type':'application/json'},
    body:JSON.stringify({request_id:'r-'+tool,tool,arguments:{}})});
   return {status:res.status,data:await res.json()};
  }
  const ok=await call('account_get_config');assert.equal(ok.status,200);assert.equal(env.invokes.length,1);
  const no=await call('trade_place_order');assert.equal(no.status,403);assert.equal(no.data.error,'READ_ONLY');
  assert.equal(env.invokes.length,1);
  const ext=await call('okx.copy.account.verify');assert.equal(ext.status,200);
  assert.equal(JSON.parse(ext.data.result.content[0].text).uid,OKX_UID);
  assert.equal((await (await fetch(env.url+'/v1/copy/health')).json()).write_enabled,false);
 }finally{await env.close()}
});
