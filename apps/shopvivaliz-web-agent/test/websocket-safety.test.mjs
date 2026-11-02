import {test} from 'node:test';
import assert from 'node:assert/strict';
import {guardWebSocketRoute} from '../src/browser.mjs';
import {safePublicUrl} from '../src/url-safety.mjs';

const dnsLookup=async hostname=>[{address:hostname==='safe.example.com'?'104.20.26.136':'10.0.0.1'}];
function routeFor(url){
  const track={connected:0,closed:[]};
  return {track,url:()=>url,
    connectToServer:async()=>{track.connected++;return 'connected'},
    close:async options=>{track.closed.push(options);return 'closed'}};
}
const guarded=(route)=>guardWebSocketRoute(route,input=>safePublicUrl(input,dnsLookup));

test('allows only public WSS destinations and converts ws schemes for URL guard',async()=>{
  for(const url of ['wss://safe.example.com/socket','ws://safe.example.com/socket']){
    const route=routeFor(url);
    assert.equal(await guarded(route),'connected');
    assert.equal(route.track.connected,1);
    assert.deepEqual(route.track.closed,[]);
  }
});
test('blocks WebSockets to loopback, private metadata IP, IPv6 loopback and internal DNS',async()=>{
  const destinations=[
    'ws://127.0.0.1/socket',
    'wss://169.254.169.254/latest/meta-data',
    'ws://10.0.1.38/mcp',
    'wss://[::1]/secret',
    'ws://internal.example.com/socket',
    'wss://safe.example.com:8443/socket',
    'wss://someone:password@safe.example.com/socket',
    'file:///etc/passwd'
  ];
  for(const url of destinations){
    const route=routeFor(url);
    assert.equal(await guarded(route),'closed',url);
    assert.equal(route.track.connected,0,url);
    assert.equal(route.track.closed[0].code,1008,url);
  }
});
test('fails closed when DNS validation fails or upstream connect throws',async()=>{
  let route=routeFor('wss://safe.example.com/socket');
  assert.equal(await guardWebSocketRoute(route,async()=>{throw Error('dns_unavailable')}),'closed');
  assert.equal(route.track.connected,0);
  route=routeFor('wss://safe.example.com/socket');
  route.connectToServer=async()=>{throw Error('connect_failed')};
  assert.equal(await guarded(route),'closed');
  assert.equal(route.track.closed[0].code,1008);
});
