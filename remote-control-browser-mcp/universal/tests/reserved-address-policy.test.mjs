import test from 'node:test';
import assert from 'node:assert/strict';
import { forbidIp } from '../live-browser.mjs';

test('rejects IANA documentation and legacy special-use IPv4 ranges', () => {
  const cases = ['192.0.2.5','198.51.100.42','203.0.113.7','192.88.99.1','192.0.0.9','192.0.0.170'];
  for (const ip of cases) assert.equal(forbidIp(ip),true,ip);
});
test('keeps public network ranges and blocks ordinary private addresses', () => {
  for(const ip of ['8.8.8.8','1.1.1.1','93.184.215.14'])assert.equal(forbidIp(ip),false,ip);
  for(const ip of ['127.0.0.1','10.0.0.2','169.254.169.254','192.168.0.1'])assert.equal(forbidIp(ip),true,ip);
});
