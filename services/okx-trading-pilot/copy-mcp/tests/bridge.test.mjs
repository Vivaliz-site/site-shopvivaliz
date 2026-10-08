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


test('gated Spot/Futures writes are exposed only when runtime metadata and explicit gates allow them',async()=>{
 const oldReadOnly=process.env.OKX_MCP_READ_ONLY;
 const oldWrite=process.env.OKX_SPOT_FUTURES_WRITE_ENABLED;
 process.env.OKX_MCP_READ_ONLY='0';
 process.env.OKX_SPOT_FUTURES_WRITE_ENABLED='1';
 let launchArgs=[];let invokes=[];
 const conn={listTools:async()=>({tools:[
   {name:'account_get_config',inputSchema:{type:'object'},annotations:{readOnlyHint:true}},
   {name:'spot_place_order',inputSchema:{type:'object'},annotations:{readOnlyHint:false}}
 ]}),callTool:async ({name})=>{invokes.push(name);return fakeReply},close:async()=>{},ping:async()=>{}};
 const copy={listTools:()=>[],call:async()=>({}),health:async()=>({enabled:true,write_enabled:false})};
 const srv=createOkxReadBridge({port:0,transportFactory:async({args})=>{launchArgs=args;return conn},
   copyFactory:()=>copy,restClient:{get:async()=>[],post:async()=>[]}});
 await once(srv,'listening');
 const url='http://127.0.0.1:'+srv.address().port;
 try{
   const health=await (await fetch(url+'/health')).json();
   assert.equal(health.read_only,false);
   assert.equal(health.spot_futures_writes_enabled,true);
   assert.equal(launchArgs.includes('--read-only'),false);
   const res=await fetch(url+'/v1/call',{method:'POST',headers:{'content-type':'application/json'},
     body:JSON.stringify({request_id:'write-gate-test',tool:'spot_place_order',arguments:{instId:'BTC-USDT'}})});
   assert.equal(res.status,200);
   assert.deepEqual(invokes,['spot_place_order']);
 }finally{
   await new Promise((resolve,reject)=>srv.close(e=>e?reject(e):resolve()));
   if(oldReadOnly===undefined)delete process.env.OKX_MCP_READ_ONLY;else process.env.OKX_MCP_READ_ONLY=oldReadOnly;
   if(oldWrite===undefined)delete process.env.OKX_SPOT_FUTURES_WRITE_ENABLED;else process.env.OKX_SPOT_FUTURES_WRITE_ENABLED=oldWrite;
 }
});

test('Spot/Futures write gate stays closed unless read-only mode is explicitly disabled too',async()=>{
 const oldReadOnly=process.env.OKX_MCP_READ_ONLY;
 const oldWrite=process.env.OKX_SPOT_FUTURES_WRITE_ENABLED;
 process.env.OKX_MCP_READ_ONLY='1';
 process.env.OKX_SPOT_FUTURES_WRITE_ENABLED='1';
 let launchArgs=[];let invokes=[];
 const conn={listTools:async()=>({tools:[
   {name:'spot_place_order',inputSchema:{type:'object'},annotations:{readOnlyHint:false}}
 ]}),callTool:async ({name})=>{invokes.push(name);return fakeReply},close:async()=>{},ping:async()=>{}};
 const copy={listTools:()=>[],call:async()=>({}),health:async()=>({enabled:true,write_enabled:false})};
 const srv=createOkxReadBridge({port:0,transportFactory:async({args})=>{launchArgs=args;return conn},
   copyFactory:()=>copy,restClient:{get:async()=>[],post:async()=>[]}});
 await once(srv,'listening');
 const url='http://127.0.0.1:'+srv.address().port;
 try{
   const health=await (await fetch(url+'/health')).json();
   assert.equal(health.read_only,true);
   assert.equal(health.spot_futures_writes_enabled,false);
   assert.equal(launchArgs.includes('--read-only'),true);
   const res=await fetch(url+'/v1/call',{method:'POST',headers:{'content-type':'application/json'},
     body:JSON.stringify({request_id:'write-gate-closed',tool:'spot_place_order',arguments:{instId:'BTC-USDT'}})});
   assert.equal(res.status,403);
   assert.deepEqual(invokes,[]);
 }finally{
   await new Promise((resolve,reject)=>srv.close(e=>e?reject(e):resolve()));
   if(oldReadOnly===undefined)delete process.env.OKX_MCP_READ_ONLY;else process.env.OKX_MCP_READ_ONLY=oldReadOnly;
   if(oldWrite===undefined)delete process.env.OKX_SPOT_FUTURES_WRITE_ENABLED;else process.env.OKX_SPOT_FUTURES_WRITE_ENABLED=oldWrite;
 }
});
