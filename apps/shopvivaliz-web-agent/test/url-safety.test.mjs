import {test} from 'node:test';
import assert from 'node:assert/strict';
import {safePublicUrl,globalIPv4,globalIp,sanitizedLocation} from '../src/url-safety.mjs';
const publicResolver=async()=>[{address:'8.8.8.8'}];
for(const host of ['127.0.0.1','10.0.1.38','169.254.169.254','100.100.100.200','192.168.1.1','172.20.0.2','192.0.2.2','198.51.100.4','203.0.113.1','224.0.0.1'])test('rejects special/private IPv4 '+host,async()=>{
  assert.equal(globalIPv4(host),false);
  await assert.rejects(safePublicUrl('http://'+host+'/',publicResolver));
});
test('accepts only globally routable HTTPS or HTTP URL',async()=>{
  assert.equal(await safePublicUrl('https://example.com/a?query=1#hash',publicResolver),'https://example.com/a?query=1');
  for(const url of ['file:///etc/passwd','http://localhost:5598/','http://x.local/','http://x.internal/','http://x.test/','http://user:pass@example.com/','http://example.com:8080','gopher://example.com/','http://[::1]/'])await assert.rejects(safePublicUrl(url,publicResolver));
  await assert.rejects(safePublicUrl('https://example.com',async()=>[{address:'8.8.8.8'},{address:'10.1.2.3'}]),/private_address_blocked/);
  assert.equal(sanitizedLocation('https://foo.com/path?secret=1#secret'),'https://foo.com/');
});

test('blocks private/special IPv6 but allows public AAAA records',async()=>{
  for(const ip of ['::1','fc00::1','fe80::1','::ffff:127.0.0.1','2001:db8::1','2001:0::1','2002::1']) assert.equal(globalIp(ip),false,ip);
  for(const ip of ['2606:4700:10::6814:1a88','2001:4860:4860::8888'])assert.equal(globalIp(ip),true,ip);
  assert.equal(await safePublicUrl('https://example.org/',async()=>[{address:'104.20.26.136'},{address:'2606:4700:10::6814:1a88'}]),'https://example.org/');
  await assert.rejects(safePublicUrl('https://example.org/',async()=>[{address:'2606:4700:10::6814:1a88'},{address:'fe80::1'}]),/private_address_blocked/);
});
