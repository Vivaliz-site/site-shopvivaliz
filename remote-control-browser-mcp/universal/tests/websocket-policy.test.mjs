import test from 'node:test';
import assert from 'node:assert/strict';
import { routeAllowedWebSocket } from '../live-browser.mjs';

test('websocket policy rejects loopback and metadata endpoints',async()=>{
  for(const url of ['ws://127.0.0.1:5595/private','ws://169.254.169.254/metadata','ws://[::ffff:127.0.0.1]/']) {
    let closed=false;let connected=false;
    await routeAllowedWebSocket({
      url:()=>url,
      close:async()=>{closed=true},
      connectToServer:()=>{connected=true}
    });
    assert.equal(closed,true,url);
    assert.equal(connected,false,url);
  }
});

test('websocket policy permits public internet destination',async()=>{
  let closed=false,connected=false;
  await routeAllowedWebSocket({
    url:()=> 'wss://example.com/socket',
    close:async()=>{closed=true},
    connectToServer:()=>{connected=true}
  });
  assert.equal(connected,true);
  assert.equal(closed,false);
});
