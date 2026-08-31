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

{
  const root = setupRepo();
  write(root, 'includes/catalog-authoritative-stock-carry.php', '<?php function stock_carry() { return 1; }\n');
  const base = commitAll(root, 'base stock helper');
  write(root, 'includes/catalog-authoritative-stock-carry.php', '<?php function stock_carry() { return 2; }\n');
  const head = commitAll(root, 'backend stock helper change');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Backend catalog stock helper must not require screenshot proof:\n${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /prova visual não exigida/);
  fs.rmSync(root, {recursive: true, force: true});
}


{
  const root = setupRepo();
  const base = commitAll(root, 'base safe workflow');
  write(root, '.github/workflows/safe-diagnostic.yml', `name: Safe diagnostic\non:\n  workflow_dispatch:\njobs:\n  probe:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          status=$(systemctl is-active demo.service 2>/dev/null || true)\n          if [ \"$status\" = active ]; then exit 0; fi\n          exit 1\n`);
  const head = commitAll(root, 'safe diagnostic workflow');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Read-only fallback and explicit successful exit must not be treated as dangerous by themselves:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base regression guard');
  write(root, 'tests/no-direct-push-test.js', `const assert = require('assert');\nassert.doesNotMatch('git status', /git push/);\n`);
  const head = commitAll(root, 'add negative security assertion');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Negative tests that mention forbidden commands must not block the PR:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base dangerous workflow');
  write(root, '.github/workflows/dangerous.yml', `name: Dangerous\non:\n  workflow_dispatch:\njobs:\n  publish:\n    runs-on: ubuntu-latest\n    steps:\n      - run: git push origin HEAD:main\n`);
  const head = commitAll(root, 'add direct push');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Actual direct git push in automation must remain blocked');
  assert.match(result.stdout, /padrão perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

console.log('policy-engine-regression: ok');
