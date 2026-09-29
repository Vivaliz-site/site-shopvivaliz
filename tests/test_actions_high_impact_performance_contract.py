from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def violations(*, enforcer: str, ecommerce: str, token: str, event_gate: str, stale_repair: str) -> list[str]:
    found: list[str] = []
    if 'schedule_target_budget=1' not in enforcer:
        found.append('scheduled_pr_budget_missing')
    if "elif [[ \"$GITHUB_EVENT_NAME\" == 'workflow_dispatch' ]]" not in enforcer:
        found.append('manual_full_sweep_boundary_missing')
    for name, text in (('ecommerce', ecommerce), ('runtime-token', token)):
        if 'uses: ./.github/workflows/production-deploy-event-gate.yml' not in text:
            found.append(f'{name}_event_gate_missing')
        if 'sleep 20' in text or 'production-release-await.yml' in text:
            found.append(f'{name}_deploy_polling_present')
    if 'sleep ' in event_gate:
        found.append('event_gate_polling_present')
    for fragment in (
        'uses: actions/cache@v4',
        'path: ~/.ollama/models',
        'ollama show "$OLLAMA_MODEL"',
        'ollama pull "$OLLAMA_MODEL"',
    ):
        if fragment not in stale_repair:
            found.append('ollama_cache_contract_missing')
            break
    return found


paths = {
    'enforcer': ROOT / '.github/workflows/pr-completion-enforcer.yml',
    'ecommerce': ROOT / '.github/workflows/ecommerce-excellence-audit.yml',
    'token': ROOT / '.github/workflows/runtime-token-security.yml',
    'event_gate': ROOT / '.github/workflows/production-deploy-event-gate.yml',
    'stale_repair': ROOT / '.github/workflows/ai-stale-pr-repair.yml',
}
texts = {name: path.read_text(encoding='utf-8') for name, path in paths.items()}
actual = violations(
    enforcer=texts['enforcer'],
    ecommerce=texts['ecommerce'],
    token=texts['token'],
    event_gate=texts['event_gate'],
    stale_repair=texts['stale_repair'],
)
if actual:
    raise SystemExit('high-impact Actions regressions: ' + ', '.join(actual))

# Negative self-test: prove the detector fails on the exact costly patterns it guards.
bad = violations(
    enforcer='workflow_dispatch only',
    ecommerce='sleep 20 production-release-await.yml',
    token='sleep 20 production-release-await.yml',
    event_gate='sleep 20',
    stale_repair='ollama pull "$OLLAMA_MODEL"',
)
expected_bad = {
    'scheduled_pr_budget_missing',
    'manual_full_sweep_boundary_missing',
    'ecommerce_event_gate_missing',
    'ecommerce_deploy_polling_present',
    'runtime-token_event_gate_missing',
    'runtime-token_deploy_polling_present',
    'event_gate_polling_present',
    'ollama_cache_contract_missing',
}
if set(bad) != expected_bad:
    raise SystemExit(f'guard negative self-test mismatch: {bad}')

print('high-impact Actions performance contract: PASS')
