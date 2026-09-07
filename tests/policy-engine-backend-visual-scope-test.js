const assert = require('assert');
const fs = require('fs');

const engine = fs.readFileSync('agents/policy-engine/index.js', 'utf8');
const backendOnly = [
  'includes/account-schema.php',
  'includes/integration-health.php',
  'includes/order-request-context.php',
  'includes/order-transaction-evidence.php',
  'includes/webhook-job-dispatcher.php',
];
for (const file of backendOnly) {
  assert(engine.includes(`file === '${file}'`), `Policy visual scope must exempt backend-only ${file}`);
}
console.log('policy-engine-backend-visual-scope-test: ok');
