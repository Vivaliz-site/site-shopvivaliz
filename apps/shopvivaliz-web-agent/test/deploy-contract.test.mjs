import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const installer=readFileSync(new URL('../deploy/install-local.sh',import.meta.url),'utf8');

test('local installer deploys reproducible locked dependencies, not floating npm install',()=>{
  assert.match(installer,/\$ROOT\/package-lock\.json/);
  assert.match(installer,/npm ci --omit=dev --ignore-scripts --no-audit --no-fund/);
  assert.doesNotMatch(installer,/\bnpm install\b/);
});
test('local installer restarts existing service and rolls back unhealthy release',()=>{
  assert.match(installer,/previous_release=""/);
  assert.match(installer,/systemctl restart shopvivaliz-web-agent\.service/);
  assert.match(installer,/systemctl is-active --quiet shopvivaliz-web-agent\.service/);
  assert.match(installer,/current\.rollback/);
  assert.match(installer,/WEB_AGENT_LOCAL_RELEASE_VERIFIED=true/);
});
test('local installer never exposes admin ports or updates DNS, tunnels, GitHub rules',()=>{
  assert.match(installer,/http:\/\/127\.0\.0\.1:5598\/health/);
  assert.doesNotMatch(installer,/cloudflared|cloudflare api|dns-route|--listen 0\.0\.0\.0/);
  assert.match(installer,/User|useradd --system/);
});
