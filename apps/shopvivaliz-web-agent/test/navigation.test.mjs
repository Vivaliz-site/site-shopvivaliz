import {test} from 'node:test';
import assert from 'node:assert/strict';
import {TakeoverController} from '../src/controller.mjs';
import {createServer} from '../src/server.mjs';
import {BrowserDriver} from '../src/browser.mjs';

function driverFake(){
  const actions=[];
  return {actions,
    async open(url){actions.push(['open',url]);return {url:'https://example.org/',title:'Home'};},
    async navigate(url){actions.push(['navigate',url]);return {url:'https://example.org/',title:'Navigated'};},
    async back(){actions.push(['back']);return {title:'Previous'};},
    async forward(){actions.push(['forward']);return {title:'Next'};},
    async scroll(delta){actions.push(['scroll',delta]);return {title:'Scrolled'};},
    async fill(selector,value){actions.push(['fill',selector,value]);return {title:'Filled'};},
    async inspect(){return {controls:[]};},
    async click(selector){actions.push(['click',selector]);return {title:'Clicked'};},
    async key(key){actions.push(['key',key]);},
    async screenshot(){return Buffer.from([0xff,0xd8,0xff,0xd9]);},
    async mouse(x,y){actions.push(['mouse',x,y]);},
    async type(value){actions.push(['type',value]);},
    async close(){actions.push(['close']);},
    async snapshot(){return {url:'https://example.org/',title:'Home'};}
  };
}

test('agent can navigate, fill non-secret forms, scroll, use history and keys',async()=>{
  const d=driverFake(),c=new TakeoverController(d);
  const s=await c.open('https://example.org/');
  assert.equal((await c.navigate(s.session_id,'https://example.org/products')).title,'Navigated');
  assert.equal((await c.fill(s.session_id,'#query','printer')).title,'Filled');
  assert.equal((await c.scroll(s.session_id,500)).title,'Scrolled');
  assert.equal((await c.back(s.session_id)).title,'Previous');
  assert.equal((await c.forward(s.session_id)).title,'Next');
  await c.key(s.session_id,'Tab');
  assert.deepEqual(d.actions.slice(1),[
    ['navigate','https://example.org/products'],['fill','#query','printer'],
    ['scroll',500],['back'],['forward'],['key','Tab']
  ]);
  assert.equal(c.status(s.session_id).mode,'agent');
});

test('human takeover blocks every new agent action while allowing human scroll/history',async()=>{
  const d=driverFake(),c=new TakeoverController(d);
  const s=await c.open('https://example.org/');
  const h=await c.handoff(s.session_id);
  for(const callback of [
    ()=>c.navigate(s.session_id,'https://example.org/next'),
    ()=>c.fill(s.session_id,'#x','abc'),
    ()=>c.scroll(s.session_id,500),
    ()=>c.back(s.session_id),
    ()=>c.forward(s.session_id),
    ()=>c.key(s.session_id,'Tab')
  ]) await assert.rejects(callback(),/human_takeover_active/);
  await c.humanAction(s.session_id,h.private.capability,'scroll',{delta_y:-550});
  await c.humanAction(s.session_id,h.private.capability,'back');
  await c.humanAction(s.session_id,h.private.capability,'forward');
  assert.deepEqual(d.actions.slice(-3),[['scroll',-550],['back'],['forward']]);
  await c.release(s.session_id,h.private.capability);
  assert.equal((await c.scroll(s.session_id,250)).mode,'agent');
});

test('MCP advertises navigation tools and rejects wrong argument types',async(t)=>{
  const d=driverFake(),s=createServer({controller:new TakeoverController(d),mcpToken:'a'.repeat(48)});
  await new Promise(resolve=>s.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>s.close(resolve)));
  const base='http://127.0.0.1:'+s.address().port;
  const rpc=async(id,method,params={})=>{
    const response=await fetch(base+'/mcp',{method:'POST',headers:{Authorization:'Bearer '+'a'.repeat(48),'Content-Type':'application/json'},body:JSON.stringify({jsonrpc:'2.0',id,method,params})});
    return (await response.json()).result;
  };
  const names=(await rpc(1,'tools/list')).tools.map(x=>x.name);
  for(const n of ['web_agent_navigate','web_agent_back','web_agent_forward','web_agent_scroll','web_agent_fill','web_agent_key']) assert.ok(names.includes(n),n);
  const opened=await rpc(2,'tools/call',{name:'web_agent_open',arguments:{url:'https://example.org/'}});
  const sid=opened.structuredContent.session_id;
  const bad=await rpc(3,'tools/call',{name:'web_agent_scroll',arguments:{session_id:sid,delta_y:'100'}});
  assert.equal(bad.isError,true);
  const good=await rpc(4,'tools/call',{name:'web_agent_scroll',arguments:{session_id:sid,delta_y:500}});
  assert.equal(good.structuredContent.title,'Scrolled');
  const fill=await rpc(5,'tools/call',{name:'web_agent_fill',arguments:{session_id:sid,selector:'#search',text:'books'}});
  assert.equal(fill.structuredContent.title,'Filled');
});


test('browser fill refuses password, OTP and payment fields while allowing non-secret search',async()=>{
  const driver=new BrowserDriver();
  let node={tagName:'INPUT',isContentEditable:false,getAttribute:()=>null};
  let fills=0;
  driver.page={locator:()=>({first:()=>({
    evaluate:async fn=>fn(node),
    fill:async()=>{fills++;}
  })})};
  driver.snapshot=async()=>({title:'Safe form'});
  const setNode=(tag,attributes={},editable=false)=>{
    node={tagName:tag.toUpperCase(),isContentEditable:editable,
      getAttribute:name=>Object.hasOwn(attributes,name)?attributes[name]:null};
  };
  setNode('input',{type:'password'});
  await assert.rejects(driver.fill('#pwd','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'text',autocomplete:'one-time-code'});
  await assert.rejects(driver.fill('#code','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'tel',name:'mfa_code'});
  await assert.rejects(driver.fill('#mfa','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'text',placeholder:'Verification code'});
  await assert.rejects(driver.fill('#verification','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'text',autocomplete:'new-password'});
  await assert.rejects(driver.fill('#pw-new','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'text','aria-label':'Payment Card Number'});
  await assert.rejects(driver.fill('#payment','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('input',{type:'hidden'});
  await assert.rejects(driver.fill('#hidden','synthetic'),/sensitive_field_requires_human_takeover/);
  setNode('textarea',{name:'api_token'});
  await assert.rejects(driver.fill('#token','synthetic'),/sensitive_field_requires_human_takeover/);
  assert.equal(fills,0);
  setNode('input',{type:'search',placeholder:'Buscar'});
  assert.equal((await driver.fill('#query','printer')).title,'Safe form');
  assert.equal(fills,1);
});
