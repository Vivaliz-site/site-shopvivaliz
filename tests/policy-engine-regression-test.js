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

{
  const root = setupRepo();
  const base = commitAll(root, 'base suppressed deployment');
  write(root, '.github/workflows/suppressed-deploy.yml', `name: Suppressed deploy\non:\n  workflow_dispatch:\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - run: ./deploy-production.sh || true\n`);
  const head = commitAll(root, 'add suppressed deployment');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Real automation failure suppression with || true must remain blocked');
  assert.match(result.stdout, /padrão perigoso .*\|\| true/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base policy source');
  write(root, 'agents/policy-engine/index.js', engineSource + `\n// detector documentation mentions || true but does not execute shell\n`);
  const head = commitAll(root, 'document detector signature');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Policy source text must not be treated as executable shell suppression:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base executable test guard');
  write(root, 'tests/live-publish-check.sh', `#!/usr/bin/env bash\nset -Eeuo pipefail\ngit push origin HEAD:main\n`);
  const head = commitAll(root, 'add executable test mutation');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Executable tests containing a real direct push must remain blocked');
  assert.match(result.stdout, /padrão perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base whitespace suppression guard');
  write(root, '.github/workflows/suppressed-spacing.yml', `name: Suppressed spacing\non:\n  workflow_dispatch:\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - run: ./deploy-production.sh ||  true\n`);
  const head = commitAll(root, 'add spaced suppression');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Failure suppression must block arbitrary shell whitespace after ||');
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base multiline executable guard');
  write(root, 'tests/live-publish-check.js', `const { execSync } = require('child_process');\nexecSync(\n  \`git push origin HEAD:main\`\n);\n`);
  const head = commitAll(root, 'add multiline executable mutation');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Multiline executable test commands containing a real direct push must remain blocked');
  assert.match(result.stdout, /padrão perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base multiline suppression guard');
  write(root, '.github/workflows/suppressed-multiline.yml', `name: Suppressed multiline\non:\n  workflow_dispatch:\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          ./deploy-production.sh ||\n            true\n`);
  const head = commitAll(root, 'add multiline suppression');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Failure suppression split across shell lines must remain blocked');
  assert.match(result.stdout, /padrão perigoso .*\|\| true/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base multiline readonly fallback');
  write(root, '.github/workflows/safe-multiline-fallback.yml', `name: Safe multiline fallback\non:\n  workflow_dispatch:\njobs:\n  probe:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          status=$(systemctl is-active demo.service ||\n            true)\n          test \"$status\" = active\n`);
  const head = commitAll(root, 'add multiline readonly fallback');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Read-only systemctl fallback may span lines without becoming unsafe:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base executable argument scope');
  write(root, 'tests/harmless-exec-check.js', `const assert = require('assert');\nconst { execSync } = require('child_process');\nexecSync('echo harmless');\nassert.doesNotMatch('git status', /git push/);\n`);
  const head = commitAll(root, 'add harmless executable and negative assertion');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Forbidden text outside executable arguments must not block:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base multiple executable calls');
  const padding = 'x'.repeat(2200);
  write(root, 'tests/second-exec-publish.js', `const { execSync } = require('child_process');\nexecSync('echo harmless');\n// ${padding}\nexecSync('git push origin HEAD:main');\n`);
  const head = commitAll(root, 'add forbidden second executable call');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Every executable invocation must be inspected, not just the first window');
  assert.match(result.stdout, /padrão perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  write(root, 'tests/hunk-boundary.sh', `#!/usr/bin/env bash\nfalse\nrecover\necho one\necho two\necho three\necho four\necho old\n`);
  const base = commitAll(root, 'base separated shell hunks');
  write(root, 'tests/hunk-boundary.sh', `#!/usr/bin/env bash\nfalse ||\nrecover\necho one\necho two\necho three\necho four\ntrue\n`);
  const head = commitAll(root, 'change separate shell hunks');
  const result = policy(root, base, head);
  assert.strictEqual(result.status, 0, `Separated diff hunks must not synthesize a nonexistent || true command:\n${result.stdout}\n${result.stderr}`);
  fs.rmSync(root, {recursive: true, force: true});
}

{
  const root = setupRepo();
  const base = commitAll(root, 'base comment parenthesis lexer guard');
  write(root, 'tests/comment-paren-publish.js', `const { spawnSync } = require('child_process');\nspawnSync('bash', [/* ) */ '-c', 'git push origin HEAD:main']);\n`);
  const head = commitAll(root, 'add executable with comment parenthesis');
  const result = policy(root, base, head);
  assert.notStrictEqual(result.status, 0, 'Comment parentheses must not terminate executable argument scanning');
  assert.match(result.stdout, /padrão perigoso git push/);
  fs.rmSync(root, {recursive: true, force: true});
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

console.log('policy-engine-regression: ok');
