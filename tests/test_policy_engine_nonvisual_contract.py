from pathlib import Path

policy = Path('agents/policy-engine/index.js').read_text(encoding='utf-8')
pipeline = Path('.github/workflows/master-production-pipeline.yml').read_text(encoding='utf-8')

assert "file === 'includes/integration-health.php'" in policy, (
    'integration-health.php must be classified as non-visual to avoid fake screenshot gates'
)
assert '|| true' not in pipeline, (
    'production pipeline must not contain policy-blocked shell bypass patterns'
)

print('policy engine nonvisual contract: PASS')
