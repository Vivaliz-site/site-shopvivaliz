import fs from 'node:fs';

const workflow = fs.readFileSync('.github/workflows/policy-engine.yml', 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(workflow.includes('node scripts/check-erp.js'), 'Policy Engine must validate production ERP through the canonical HTTPS health checker');
assert(!workflow.includes('Configure verified Oracle SSH'), 'Policy Engine must not require public SSH just to validate a public HTTPS endpoint');
assert(!workflow.includes('ubuntu@163.176.103.253'), 'Policy Engine must not depend on production SSH reachability');
assert(workflow.includes("for required in ('orders', 'olist')"), 'Policy Engine must validate both orders and Olist health groups');
assert(workflow.includes("assert check.get('ok') is True"), 'Policy Engine must require each production health group to be ok');
assert(workflow.includes("check.get('http_code', 500)"), 'Policy Engine must reject unhealthy upstream HTTP status codes');
assert(workflow.includes('GitHub Actions -> public production ERP health'), 'Policy Engine must record the direct production-health route');

console.log('policy-engine-health-transport-test: ok');
