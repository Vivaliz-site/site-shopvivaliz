import fs from 'node:fs';

const workflow = fs.readFileSync('.github/workflows/policy-engine.yml', 'utf8');
const proof = JSON.parse(fs.readFileSync('visual-proof.json', 'utf8'));

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(/actions:\s*read/.test(workflow), 'Policy workflow must have actions: read permission');
assert(workflow.includes('Materialize visual proof artifact'), 'Policy workflow must materialize remote visual evidence');
assert(workflow.includes('gh run download'), 'Policy workflow must download the declared GitHub Actions artifact');
assert(workflow.includes('workflow_artifact_digest'), 'Policy workflow must verify the declared artifact digest');
assert(!workflow.includes('.expired // true'), 'jq must not coalesce a valid false expired flag to true');
assert(workflow.includes('if has("expired") then .expired else true end'), 'Policy workflow must preserve expired=false from artifact metadata');
assert(proof.evidence?.workflow_artifact_name, 'visual-proof must declare workflow_artifact_name');
assert(proof.artifacts.every((p) => p.startsWith('.policy-artifacts/')), 'visual artifacts must point to materialized local evidence paths');
console.log('policy-engine-visual-proof-workflow-test: ok');
assert(workflow.includes('Detect visual proof requirement'), 'Policy workflow must determine whether the PR has visual changes before materializing proof');
assert(workflow.includes("steps.visual_scope.outputs.required == 'true'"), 'Visual artifact download must run only when visual proof is required');
assert(workflow.includes("path.startswith('includes/amazon-returns/')"), 'Visual scope preflight must preserve backend-only exemptions');
assert(workflow.includes("re.match(r'^(?:public|includes|templates|views|pages)/'"), 'Visual scope preflight must restrict visual paths');
