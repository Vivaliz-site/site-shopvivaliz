from pathlib import Path
import re
import unittest

WORKFLOWS = Path('.github/workflows')
LEGACY_PUBLIC_IPS = ('163.176.103.253', '144.22.157.209')
PRIVATE_TARGETS = ('127.0.0.1', '10.0.1.38')
SITE_RUNNER = 'shopvivaliz-a1-deploy'


def workflow_texts():
    for path in sorted(WORKFLOWS.glob('*.y*ml')):
        yield path, path.read_text(encoding='utf-8')


def job_blocks(text: str):
    match = re.search(r'(?m)^jobs:\s*$', text)
    if not match:
        return []
    body = text[match.end():]
    starts = list(re.finditer(r'(?m)^  ([A-Za-z0-9_.-]+):\s*$', body))
    blocks = []
    for idx, item in enumerate(starts):
        end = starts[idx + 1].start() if idx + 1 < len(starts) else len(body)
        blocks.append((item.group(1), body[item.start():end]))
    return blocks

def reviewed_bootstrap_push(path: Path, text: str) -> bool:
    """Allow only the documented source-triggered recovery entrypoints."""
    if path.name not in {'remote-control-mcp-bootstrap.yml', 'windows-peer-emergency-recovery.yml'}:
        return False
    head = text.split('jobs:', 1)[0]
    if any(re.search(r'(?m)^  ' + event + r':', head)
           for event in ('schedule', 'pull_request', 'workflow_run')):
        return False
    if 'branches: [main]' not in head or '    paths:' not in head:
        return False
    path_lines = re.findall(r'(?m)^      - (.+)$', head.split('    paths:', 1)[1])
    paths = {value.strip().strip("\"'") for value in path_lines}
    own_path = '.github/workflows/' + path.name
    allowed = {own_path}
    if path.name == 'remote-control-mcp-bootstrap.yml':
        allowed.update({
            'remote-control-mcp/**',
            'scripts/setup-remote-control-access.sh',
            'scripts/setup-remote-control-windows.ps1',
            'scripts/windows-openssh-recovery.ps1',
            'scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1',
            'scripts/desktopkocepsv-remote-bootstrap.ps1',
            'scripts/desktopkocepsv-remote-control-ssh-bridge.ps1',
            'deploy/systemd/shopvivaliz-remote-control-mcp.service',
            'tests/remote-control-mcp-test.py',
        })
    return own_path in paths and paths <= allowed


def uses_verified_private_bastion(block: str) -> bool:
    """A hosted runner reaches a loopback tunnel, not a directly routed VM."""
    markers = (
        'runs-on: ubuntu-latest', 'environment: Production',
        'timeout-minutes:', 'bastion session create-port-forwarding',
        'bastion session delete', 'StrictHostKeyChecking=yes',
        'UserKnownHostsFile=', 'ORIGINAL_CIDRS_FILE',
        '--client-cidr-list', 'if: always()',
    )
    return ('ubuntu@10.0.1.38' not in block
            and all(marker in block for marker in markers))


class PrivateTransportClassifierTests(unittest.TestCase):
    def test_bastion_exception_does_not_accept_plain_hosted_ssh(self):
        self.assertFalse(uses_verified_private_bastion('runs-on: ubuntu-latest\nssh ubuntu@127.0.0.1'))
        self.assertFalse(uses_verified_private_bastion('bastion session create-port-forwarding'))

    def test_bootstrap_exception_is_exact_and_rejects_periodic_or_broad_triggers(self):
        path = Path('.github/workflows/windows-peer-emergency-recovery.yml')
        text = path.read_text()
        self.assertTrue(reviewed_bootstrap_push(path, text))
        self.assertFalse(reviewed_bootstrap_push(Path('unreviewed.yml'), text))
        self.assertFalse(reviewed_bootstrap_push(path, text.replace('  push:', '  schedule:\n  push:')))
        self.assertFalse(reviewed_bootstrap_push(path, text.replace(str(path), '**')))
        self.assertFalse(reviewed_bootstrap_push(path, text.replace('    paths:', '    paths:\n      - **')))


class WorkflowPrivateTransportTests(unittest.TestCase):
    def test_active_workflows_do_not_use_legacy_public_vm_ips(self):
        offenders = []
        for path, text in workflow_texts():
            for ip in LEGACY_PUBLIC_IPS:
                if ip in text:
                    offenders.append(f'{path}:{ip}')
        self.assertEqual(offenders, [], 'legacy public VM IPs remain: ' + ', '.join(offenders))

    def test_legacy_relays_are_manual_or_reviewed_source_bootstrap_only(self):
        offenders = []
        automatic = ('schedule:', 'push:', 'pull_request:', 'workflow_run:')
        relay_markers = ('127.0.0.1:5557', '127.0.0.1:5558', 'select-active-products-browser-relay.sh')
        for path, text in workflow_texts():
            if not any(marker in text for marker in relay_markers):
                continue
            head = text.split('jobs:', 1)[0]
            found = [event[:-1] for event in automatic if event in head]
            if found and not reviewed_bootstrap_push(path, text):
                offenders.append(f'{path}:{"/".join(found)}')
        self.assertEqual(offenders, [], 'legacy relay workflows still auto-trigger: ' + ', '.join(offenders))

    def test_private_vm_ssh_jobs_use_site_runner_or_verified_bastion_tunnel(self):
        offenders = []
        for path, text in workflow_texts():
            for job, block in job_blocks(text):
                if not any(f'ubuntu@{target}' in block for target in PRIVATE_TARGETS):
                    continue
                if SITE_RUNNER not in block and not uses_verified_private_bastion(block):
                    offenders.append(f'{path}:{job}')
        self.assertEqual(offenders, [], 'private VM SSH jobs not pinned to site runner: ' + ', '.join(offenders))


OPERATIONAL_PATHS = [
    Path('AGENTS.md'), Path('AGENTS-VM-ACCESS.md'), Path('CLAUDE.md'),
    Path('config'), Path('scripts'), Path('ops'), Path('docs/knowledge'),
    Path('docs/AGENT-VM-PROMPTS.md'), Path('docs/VM-SSH-ACCESS.md'),
    Path('docs/FRED-WIN-PRIVATE-RELAY.md'), Path('docs/agent-actions-observability.md'),
    Path('.github/workflows'),
]


def operational_files():
    for root in OPERATIONAL_PATHS:
        if root.is_file():
            yield root
        elif root.is_dir():
            for path in root.rglob('*'):
                if path.is_file() and path.suffix.lower() in {'.md', '.json', '.py', '.sh', '.ps1', '.yml', '.yaml', '.js', '.php'}:
                    yield path


class OperationalSourceTests(unittest.TestCase):
    def test_operational_sources_do_not_use_legacy_public_vm_ips(self):
        offenders = []
        for path in operational_files():
            if path == Path('scripts/check_pr_completion_policy.py'):
                continue  # policy guard intentionally contains retired IP signatures
            text = path.read_text(encoding='utf-8', errors='ignore')
            for ip in LEGACY_PUBLIC_IPS:
                if ip in text:
                    offenders.append(f'{path}:{ip}')
        self.assertEqual(offenders, [], 'legacy public VM IPs remain in operational sources: ' + ', '.join(offenders))


if __name__ == '__main__':
    unittest.main()
