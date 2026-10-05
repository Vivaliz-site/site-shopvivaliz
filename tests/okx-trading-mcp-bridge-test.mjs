import assert from 'node:assert/strict';
import { once } from 'node:events';
import test from 'node:test';
import { createOkxReadBridge } from '../ops/okx-trading-pilot/mcp-bridge.mjs';

function fakeTransportFactory(record, tools = [
  {name:'market_get_ticker'},
  {name:'account_get_balance'},
  {name:'account_get_positions'},
  {name:'account_get_bills'},
]) {
  return async ({command,args}) => {
    record.command=command; record.args=args;
    return {
      async listTools(){return {tools};},
      async callTool(request){record.call=request; return {content:[{type:'text',text:'{"data":[{"ok":true}]}'}]};},
      async close(){},
    };
  };
}
async function start(options){
  const server=createOkxReadBridge({port:0,...options});
  if(!server.listening) await once(server,'listening');
  const {port}=server.address();
  return {server,base:`http://127.0.0.1:${port}`};
}
async function close(server){server.close(); await once(server,'close');}

test('launches exact pinned live read-only MCP command', async()=>{
  const record={}; const {server,base}=await start({transportFactory:fakeTransportFactory(record)});
  try {
    const r=await fetch(`${base}/health`); assert.equal(r.status,200);
    assert.match(record.command,/node_modules\/\.bin\/okx-trade-mcp$/);
    assert.deepEqual(record.args,['--profile','live','--modules','all','--read-only']);
  } finally {await close(server);}
});

test('permits read calls and rejects order tools with 403', async()=>{
  const record={}; const {server,base}=await start({transportFactory:fakeTransportFactory(record)});
  try {
    let r=await fetch(`${base}/v1/call`,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({request_id:'r1',tool:'market_get_ticker',arguments:{instId:'BTC-USDT'}})});
    assert.equal(r.status,200); assert.equal(record.call.name,'market_get_ticker');
    r=await fetch(`${base}/v1/call`,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({request_id:'r2',tool:'spot_place_order',arguments:{}})});
    assert.equal(r.status,403); const body=await r.json(); assert.equal(body.error,'READ_ONLY');
  } finally {await close(server);}
});

test('health is sanitized', async()=>{
  const record={}; const {server,base}=await start({transportFactory:fakeTransportFactory(record)});
  try {
    const body=await (await fetch(`${base}/health`)).json();
    assert.equal(body.package,'@okx_ai/okx-trade-mcp');
    assert.equal(body.version,'1.4.8');
    assert.equal(body.profile,'live');
    assert.equal(body.read_only,true);
    assert.equal(body.upstream_connected,true);
    const serialized=JSON.stringify(body);
    assert.doesNotMatch(serialized,/api.?key|secret.?key|passphrase|authorization|bearer/i);
  } finally {await close(server);}
});
