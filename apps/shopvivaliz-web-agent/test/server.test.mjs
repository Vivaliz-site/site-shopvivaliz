import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer,RESOURCE_URI} from '../src/server.mjs';
import {TakeoverController} from '../src/controller.mjs';
const TOKEN='x'.repeat(48);
const fake={
  async open(){return {url:'https://example.org/',title:'Test login'}},
  async inspect(){return {controls:[{label:'Login'}]}},
  async click(){return {title:'Clicked'}},
  async screenshot(){return Buffer.from([0xff,0xd8,0xff,0xd9])},
  async mouse(){},async type(){},async key(){},async close(){}
};
const req=(id,method,params={})=>({jsonrpc:'2.0',id,method,params});

test('MCP protocol keeps handoff capability in hidden _meta and human API never exports credentials',async(t)=>{
  const server=createServer({controller:new TakeoverController(fake),mcpToken:TOKEN});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>server.close(resolve)));
  const base='http://127.0.0.1:'+server.address().port;
  const rpc=async(b,headers={})=>{
    const r=await fetch(base+'/mcp',{method:'POST',headers:{'content-type':'application/json',...headers},body:JSON.stringify(b)});
    return {status:r.status,data:await r.json()};
  };
  assert.equal((await rpc(req(1,'tools/list'))).status,401);
  const h={Authorization:'Bearer '+TOKEN};
  assert.equal((await rpc(req(1,'initialize'),h)).data.result.serverInfo.name,'shopvivaliz-web-agent');
  const tools=(await rpc(req(2,'tools/list'),h)).data.result.tools;
  assert.ok(tools.find(x=>x.name==='web_agent_takeover')._meta.ui.resourceUri===RESOURCE_URI);
  const ui=(await rpc(req(3,'resources/read',{uri:RESOURCE_URI}),h)).data.result.contents[0];
  assert.equal(ui.mimeType,'text/html;profile=mcp-app');
  assert.match(ui.text,/Devolver controle ao agente/);
  assert.ok(ui._meta.ui.csp.connectDomains.length===1);
  const opening=(await rpc(req(4,'tools/call',{name:'web_agent_open',arguments:{url:'https://example.org'}}),h)).data.result;
  const sid=opening.structuredContent.session_id;
  const reply=(await rpc(req(5,'tools/call',{name:'web_agent_takeover',arguments:{session_id:sid}}),h)).data.result;
  const capability=reply._meta.handoff.capability;
  assert.ok(!JSON.stringify(reply.structuredContent).includes(capability));
  assert.ok(!JSON.stringify(reply.content).includes(capability));
  const denied=(await rpc(req(6,'tools/call',{name:'web_agent_inspect',arguments:{session_id:sid}}),h)).data.result;
  assert.equal(denied.isError,true);
  const without=await fetch(base+'/human/'+sid+'/frame');assert.equal(without.status,403);
  const frame=await fetch(base+'/human/'+sid+'/frame',{headers:{Authorization:'Bearer '+capability}});
  assert.equal(frame.status,200);assert.equal(frame.headers.get('content-type'),'image/jpeg');
  const sent=await fetch(base+'/human/'+sid+'/action',{method:'POST',headers:{Authorization:'Bearer '+capability,'content-type':'application/json'},body:JSON.stringify({action:'type',text:'sensitive-value-never-in-response'})});
  assert.deepEqual(await sent.json(),{ok:true});
  const released=await fetch(base+'/human/'+sid+'/release',{method:'POST',headers:{Authorization:'Bearer '+capability}});
  assert.equal((await released.json()).mode,'agent');
  const afterward=await rpc(req(7,'tools/call',{name:'web_agent_inspect',arguments:{session_id:sid}}),h);
  assert.equal(afterward.data.result.structuredContent.controls[0].label,'Login');
  const reused=await fetch(base+'/human/'+sid+'/action',{method:'POST',headers:{Authorization:'Bearer '+capability,'content-type':'application/json'},body:JSON.stringify({action:'type',text:'bad'})});
  assert.equal(reused.status,404);
});


test('takeover CORS rejects foreign origins before capabilities and never emits wildcard',async(t)=>{
  const trusted='https://takeover-owner.example';
  const server=createServer({
    controller:new TakeoverController(fake),mcpToken:TOKEN,
    allowedHumanOrigins:[trusted]
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>server.close(resolve)));
  const base='http://127.0.0.1:'+server.address().port;
  const path='/human/00000000-0000-0000-0000-000000000000/frame';
  const preflight=(origin)=>fetch(base+path,{
    method:'OPTIONS',headers:{
      ...(origin===undefined?{}:{Origin:origin}),
      'Access-Control-Request-Method':'GET',
      'Access-Control-Request-Headers':'Authorization'
    }
  });
  const ok=await preflight(trusted);
  assert.equal(ok.status,204);
  assert.equal(ok.headers.get('access-control-allow-origin'),trusted);
  assert.equal(ok.headers.get('vary'),'Origin');
  assert.notEqual(ok.headers.get('access-control-allow-origin'),'*');
  const same=await preflight('http://127.0.0.1:5598');
  assert.equal(same.status,204);
  for(const origin of ['null','https://attacker.example','http://takeover-owner.example']){
    const denied=await preflight(origin);
    assert.equal(denied.status,403,origin);
    assert.equal(denied.headers.get('access-control-allow-origin'),null,origin);
    const get=await fetch(base+path,{headers:{Origin:origin,Authorization:'Bearer dummy'}});
    assert.equal(get.status,403,origin);
    assert.equal(get.headers.get('access-control-allow-origin'),null,origin);
  }
  const noOrigin=await preflight(undefined);
  assert.equal(noOrigin.status,403);
  assert.equal(noOrigin.headers.get('access-control-allow-origin'),null);
  assert.throws(()=>createServer({mcpToken:TOKEN,allowedHumanOrigins:['*']}),/invalid_human_cors_origin/);
  assert.throws(()=>createServer({mcpToken:TOKEN,allowedHumanOrigins:['https://*.example']}),/invalid_human_cors_origin/);
  assert.throws(()=>createServer({mcpToken:TOKEN,allowedHumanOrigins:['http://untrusted.example']}),/invalid_human_cors_origin/);
  assert.throws(()=>createServer({mcpToken:TOKEN,allowedHumanOrigins:['https://trusted.example/path']}),/invalid_human_cors_origin/);
});
