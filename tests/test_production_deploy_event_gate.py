from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts' / 'production-deploy-event-gate.sh'
GATE = ROOT / '.github' / 'workflows' / 'production-deploy-event-gate.yml'
ECOM = ROOT / '.github' / 'workflows' / 'ecommerce-excellence-audit.yml'
TOKEN = ROOT / '.github' / 'workflows' / 'runtime-token-security.yml'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


require(SCRIPT.is_file(), 'production deploy event gate script missing')
require(GATE.is_file(), 'production deploy event gate reusable workflow missing')

gate = GATE.read_text(encoding='utf-8')
ecom = ECOM.read_text(encoding='utf-8')
token = TOKEN.read_text(encoding='utf-8')

for fragment in ('workflow_call:', 'source_run_id:', 'expected_sha:', 'source_conclusion:', 'should_run:', 'production_sha:'):
    require(fragment in gate, f'event gate workflow missing: {fragment}')
require('scripts/production-deploy-event-gate.sh' in gate, 'reusable workflow must execute canonical gate script')
require('sleep ' not in gate, 'event gate workflow must not poll/sleep')

for name, text in (('ecommerce', ecom), ('runtime-token', token)):
    require('workflow_run:' in text, f'{name} missing workflow_run trigger')
    require('Master Production Pipeline 24/7' in text, f'{name} not tied to production pipeline')
    require('uses: ./.github/workflows/production-deploy-event-gate.yml' in text, f'{name} missing reusable event gate')

require('await-production-evidence:' not in ecom, 'ecommerce inline deployment polling still present')
require('sleep 20' not in ecom, 'ecommerce deployment polling sleep still present')
require('uses: ./.github/workflows/production-release-await.yml' not in ecom, 'ecommerce still uses release polling workflow')
require('uses: ./.github/workflows/production-release-await.yml' not in token, 'runtime token audit still uses release polling workflow')
require("if: github.event_name != 'workflow_run'" in ecom, 'ecommerce static audit must skip post-deploy event runs')
require('needs: production-audit-gate' in ecom, 'ecommerce live audit must depend on production event gate')
require('needs: production-audit-gate' in token, 'runtime token audit must depend on production event gate')


def run_gate(*, event_name: str, deploy: str = 'success', evidence_sha: str = 'a' * 40, expected_sha: str = 'a' * 40, source_conclusion: str = 'success'):
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        bindir = root / 'bin'
        bindir.mkdir()
        gh = bindir / 'gh'
        gh.write_text(
            '#!/usr/bin/env bash\n'
            'set -euo pipefail\n'
            'args="$*"\n'
            'if [[ "$args" == *"actions/runs/123/jobs?per_page=100"* ]]; then\n'
            f"  printf '%s\\n' '{{\"jobs\":[{{\"name\":\"deploy\",\"conclusion\":\"{deploy}\"}}]}}'\n"
            'elif [[ "$args" == *"contents/deployment/latest.json?ref=deployment-evidence"* ]]; then\n'
            f"  printf '%s\\n' '{{\"sha\":\"{evidence_sha}\",\"status\":\"PRODUCTION_UPDATED\",\"jobs\":{{\"validate\":\"success\",\"deploy\":\"success\",\"smoke_test\":\"success\"}}}}'\n"
            'else\n'
            '  echo "unexpected gh args: $args" >&2\n'
            '  exit 9\n'
            'fi\n',
            encoding='utf-8',
        )
        gh.chmod(0o755)
        output = root / 'out'
        env = os.environ.copy()
        env.update({
            'PATH': f"{bindir}:{env.get('PATH', '')}",
            'EVENT_NAME': event_name,
            'SOURCE_RUN_ID': '123' if event_name == 'workflow_run' else '',
            'EXPECTED_SHA': expected_sha if event_name == 'workflow_run' else '',
            'SOURCE_CONCLUSION': source_conclusion if event_name == 'workflow_run' else '',
            'GITHUB_REPOSITORY': 'Vivaliz-site/site-shopvivaliz',
            'GITHUB_OUTPUT': str(output),
        })
        result = subprocess.run(['bash', str(SCRIPT)], env=env, text=True, capture_output=True)
        values: dict[str, str] = {}
        if output.exists():
            for line in output.read_text(encoding='utf-8').splitlines():
                if '=' in line:
                    key, value = line.split('=', 1)
                    values[key] = value
        return result, values


ok, values = run_gate(event_name='workflow_run')
require(ok.returncode == 0, f'matching deploy must pass: {ok.stderr}{ok.stdout}')
require(values.get('should_run') == 'true', f'matching deploy should_run mismatch: {values}')
require(values.get('production_sha') == 'a' * 40, f'production sha mismatch: {values}')

skipped, values = run_gate(event_name='workflow_run', deploy='skipped')
require(skipped.returncode == 0, f'skipped deploy should be clean: {skipped.stderr}{skipped.stdout}')
require(values.get('should_run') == 'false', f'skipped deploy must not audit: {values}')

failed_source, values = run_gate(event_name='workflow_run', source_conclusion='failure')
require(failed_source.returncode == 0, 'failed source pipeline should skip live audit cleanly')
require(values.get('should_run') == 'false', 'failed source pipeline must not run live audit')

mismatch, _ = run_gate(event_name='workflow_run', evidence_sha='b' * 40)
require(mismatch.returncode != 0, 'mismatched immutable deployment evidence must fail closed')

manual, values = run_gate(event_name='workflow_dispatch')
require(manual.returncode == 0, f'manual production audit gate must pass on current evidence: {manual.stderr}{manual.stdout}')
require(values.get('should_run') == 'true', 'manual audit should run')
require(values.get('production_sha') == 'a' * 40, 'manual audit must expose current production sha')

print('production deploy event gate contract: PASS')
