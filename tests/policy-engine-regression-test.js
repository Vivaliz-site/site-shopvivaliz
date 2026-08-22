const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const cp = require('child_process');

const engineSource = fs.readFileSync(path.join(__dirname, '..', 'agents', 'policy-engine', 'index.js'), 'utf8');

function run(cmd, args, cwd, env = {}) {
  return cp.spawnSync(cmd, args, {
    cwd,
    encoding: 'utf8',
    env: {...process.env, ...env},
  });
}

function write(root, rel, content) {
  const target = path.join(root, rel);
  fs.mkdirSync(path.dirname(target), {recursive: true});
  fs.writeFileSync(target, content);
}

function setupRepo() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'policy-engine-test-'));
  run('git', ['init', '-q'], root);
  run('git', ['config', 'user.email', 'policy-test@example.invalid'], root);
  run('git', ['config', 'user.name', 'Policy Test'], root);
  write(root, 'agents/policy-engine/index.js', engineSource);
  write(root, 'erp-health.json', JSON.stringify({ok: true}));
  return root;
}

function commitAll(root, message) {
  run('git', ['add', '.'], root);
  const result = run('git', ['commit', '-q', '-m', message], root);
  assert.strictEqual(result.status, 0, result.stderr);
  return run('git', ['rev-parse', 'HEAD'], root).stdout.trim();
}

function policy(root, base, head) {
  return run('node', ['agents/policy-engine/index.js'], root, {
    POLICY_BASE_SHA: base,
    POLICY_HEAD_SHA: head,
  });
}

{
  const root = setupRepo();
  write(root, 'includes/product-seo.php', '<?php function seo_title() { return "title"; }\n');
  const base = commitAll(root, 'base');
  write(root, 'includes/product-seo.php', '<?php function seo_title() { return "short title"; }\n');
  const head = commitAll(root, 'seo metadata change');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `SEO metadata-only change must not require screenshot proof:\n${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /prova visual não exigida/);
  fs.rmSync(root, {recursive: true, force: true});
}

console.log('policy-engine-regression: ok');
