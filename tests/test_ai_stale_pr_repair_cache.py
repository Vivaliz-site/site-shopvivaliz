from pathlib import Path

workflow = Path('.github/workflows/ai-stale-pr-repair.yml').read_text(encoding='utf-8')

required = [
    'uses: actions/cache@v4',
    'path: ~/.ollama/models',
    'key: ollama-model-${{ runner.os }}-${{ env.OLLAMA_MODEL }}',
    'ollama show "$OLLAMA_MODEL"',
    'ollama pull "$OLLAMA_MODEL"',
]
missing = [item for item in required if item not in workflow]
if missing:
    raise SystemExit('ollama cache contract missing: ' + ', '.join(missing))

show_pos = workflow.index('ollama show "$OLLAMA_MODEL"')
pull_pos = workflow.index('ollama pull "$OLLAMA_MODEL"')
if show_pos > pull_pos:
    raise SystemExit('model verification must happen before any pull')

install = workflow.split('- name: Install local Ollama for real conflicts', 1)[1]
resolve = install.split('- name: Resolve safe conflicts with free local model', 1)[0]
if 'if ollama show "$OLLAMA_MODEL"' not in resolve:
    raise SystemExit('cache restore must be verified by ollama show before pull')
if 'cache-hit' in resolve:
    raise SystemExit('cache-hit alone must not decide whether the model is usable')

print('ai stale PR Ollama cache contract: PASS')
