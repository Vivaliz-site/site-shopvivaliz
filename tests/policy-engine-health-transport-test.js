import fs from 'node:fs';

const workflow = fs.readFileSync('.github/workflows/policy-engine.yml', 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(workflow.includes('node scripts/check-erp.js'), 'Policy Engine must validate production ERP through the canonical HTTPS health checker');
assert(!workflow.includes('Configure verified Oracle SSH'), 'Policy Engine must not require public SSH just to validate a public HTTPS endpoint');
assert(!workflow.includes('ubuntu@163.176.103.253'), 'Policy Engine must not depend on production SSH reachability');
assert(workflow.includes("assert (checks.get('orders') or {}).get('ok') is True"), 'Policy Engine must keep the orders health assertion');
assert(workflow.includes("assert (checks.get('olist') or {}).get('ok') is True"), 'Policy Engine must keep the Olist health assertion');
assert(workflow.includes('GitHub Actions -> public production ERP health'), 'Policy Engine must record the direct production-health route');

console.log('policy-engine-health-transport-test: ok');
