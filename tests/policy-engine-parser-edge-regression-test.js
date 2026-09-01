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
  const base = commitAll(root, 'base keyword regex guard');
  write(root, 'tests/keyword-regex-publish.js', `const { execSync } = require('child_process');\nexecSync((function () { return /[)]/; })() && 'git push origin HEAD:main');\n`);
  const head = commitAll(root, 'add keyword-led regex executable mutation');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Regex literals after return must not terminate executable argument scanning');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base python triple quote guard');
  write(root, 'tests/triple-quote-publish.py', `import subprocess\nsubprocess.run(\"\"\"echo \")\" ; git push origin HEAD:main\"\"\", shell=True, check=True)\n`);
  const head = commitAll(root, 'add triple quoted shell mutation');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Python triple-quoted strings must stay lexically intact while scanning executable arguments');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base inert executable comment');
  write(root, 'tests/comment-only-forbidden-text.js', `const { execSync } = require('child_process');\nexecSync(\n  // Verify this helper never uses git push.\n  'echo harmless'\n);\n`);
  const head = commitAll(root, 'add inert comment inside executable call');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Comments inside executable calls must not be treated as executed commands:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base regex context after block comment');
  write(root, 'tests/comment-regex-publish.js', `const { execSync } = require('child_process');\nexecSync((/* grouping */ /[)]/.test(')')) && 'git push origin HEAD:main');\n`);
  const head = commitAll(root, 'add regex after block comment');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Block comments must not hide the lexical token that permits a regex literal');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base property named return division');
  write(root, 'tests/property-division-publish.js', `const { execSync } = require('child_process');\nexecSync((obj.return / divisor) && 'git push origin HEAD:main');\n`);
  const head = commitAll(root, 'add property division before direct push');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Property names that match regex-leading keywords must still allow division parsing');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base identifier named of division');
  write(root, 'tests/of-identifier-division-publish.js', `const { execSync } = require('child_process');\nconst of = 10;\nexecSync((of / divisor) && 'git push origin HEAD:main');\n`);
  const head = commitAll(root, 'add of identifier division before direct push');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'An identifier named of must not turn division into a regex literal');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base for-of regex context');
  write(root, 'tests/for-of-regex-publish.js', `const { execSync } = require('child_process');\nexecSync((function () { for (const x of /[)]/) {} return 'git push origin HEAD:main'; })());\n`);
  const head = commitAll(root, 'add for-of regex before direct push');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'A contextual for-of keyword must still permit a regex literal');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base classic for of identifier division');
  write(root, 'tests/classic-for-of-identifier-publish.js', `const { execSync } = require('child_process');\nexecSync((function () { for (x + of / divisor; keepGoing; step()) {} return 'git push origin HEAD:main'; })());\n`);
  const head = commitAll(root, 'add classic for of identifier division');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'An of identifier inside a classic for expression must remain division');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base classic for property named of division');
  write(root, 'tests/classic-for-property-of-publish.js', `const { execSync } = require('child_process');\nexecSync((function () { for (foo.of / divisor; keepGoing; step()) {} return 'git push origin HEAD:main'; })());\n`);
  const head = commitAll(root, 'add classic for property of division');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'A property named of inside a classic for expression must remain division');
  assert.match(result.stdout, /perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

console.log('policy-engine-parser-edge-regression: ok');
