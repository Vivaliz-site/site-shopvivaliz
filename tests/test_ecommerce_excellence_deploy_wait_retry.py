from pathlib import Path
import os
import re
import subprocess
import tempfile

WORKFLOW = Path('.github/workflows/ecommerce-excellence-audit.yml')
text = WORKFLOW.read_text(encoding='utf-8')

match = re.search(
    r"      - name: Wait for exact SHA production evidence\n(?P<body>.*?)(?=\n      - name: )",
    text,
    re.S,
)
if not match:
    raise SystemExit('wait-for-production-evidence step not found')

body = match.group('body')
required = [
    'set -euo pipefail',
    'for _ in $(seq 1 36); do',
    'if gh api \\',
    'if python3 - "$EXPECTED_SHA" /tmp/deployment-latest.json <<\'PY\'; then',
    "if python3 - /tmp/deployment-latest.json <<'PY'; then",
    "if [ \"$evidence_ready\" = '1' ]; then",
    'sleep 20',
    'test "$ready" = \'1\'',
]
missing = [item for item in required if item not in body]
if missing:
    raise SystemExit('retry contract missing: ' + ', '.join(missing))

run_match = re.search(r"        run: \|\n(?P<script>.*)$", body, re.S)
if not run_match:
    raise SystemExit('wait step run script not found')
script_lines = run_match.group('script').splitlines()
script = '\n'.join(line[10:] if line.startswith('          ') else line for line in script_lines) + '\n'

expected = 'a' * 40
with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    bindir = root / 'bin'
    bindir.mkdir()
    counter = root / 'gh-count'
    gh = bindir / 'gh'
    gh.write_text(
        '#!/usr/bin/env bash\n'
        'set -euo pipefail\n'
        'n=0\n'
        'test ! -f "$FAKE_GH_COUNTER" || n=$(cat "$FAKE_GH_COUNTER")\n'
        'n=$((n+1))\n'
        'printf "%s" "$n" > "$FAKE_GH_COUNTER"\n'
        'if [ "$n" -eq 1 ]; then sha="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"; else sha="$EXPECTED_SHA"; fi\n'
        'printf \'{"sha":"%s","status":"PRODUCTION_UPDATED","jobs":{"validate":"success","deploy":"success","smoke_test":"success"}}\\n\' "$sha"\n',
        encoding='utf-8',
    )
    gh.chmod(0o755)
    sleep = bindir / 'sleep'
    sleep.write_text('#!/usr/bin/env bash\nexit 0\n', encoding='utf-8')
    sleep.chmod(0o755)
    shell = root / 'wait.sh'
    shell.write_text(script, encoding='utf-8')
    env = os.environ.copy()
    env.update({
        'PATH': f'{bindir}:{env.get("PATH", "")}',
        'EXPECTED_SHA': expected,
        'GITHUB_REPOSITORY': 'Vivaliz-site/site-shopvivaliz',
        'FAKE_GH_COUNTER': str(counter),
    })
    result = subprocess.run(['bash', str(shell)], env=env, text=True, capture_output=True)
    if result.returncode != 0:
        raise SystemExit(f'retry behavior failed rc={result.returncode}: {result.stderr}{result.stdout}')
    attempts = int(counter.read_text(encoding='utf-8'))
    if attempts != 2:
        raise SystemExit(f'expected exactly 2 evidence attempts, got {attempts}')

print('ecommerce excellence deployment wait retry contract: PASS')
