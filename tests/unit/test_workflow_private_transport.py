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

class WorkflowPrivateTransportTests(unittest.TestCase):
    def test_active_workflows_do_not_use_legacy_public_vm_ips(self):
        offenders = []
        for path, text in workflow_texts():
            for ip in LEGACY_PUBLIC_IPS:
                if ip in text:
                    offenders.append(f'{path}:{ip}')
        self.assertEqual(offenders, [], 'legacy public VM IPs remain: ' + ', '.join(offenders))

    def test_legacy_fredwin_relay_workflows_are_manual_only(self):
        offenders = []
        automatic = ('schedule:', 'push:', 'pull_request:', 'workflow_run:')
        relay_markers = ('127.0.0.1:5557', '127.0.0.1:5558', 'select-active-products-browser-relay.sh')
        for path, text in workflow_texts():
            if not any(marker in text for marker in relay_markers):
                continue
            head = text.split('jobs:', 1)[0]
            found = [event[:-1] for event in automatic if event in head]
            if found:
                offenders.append(f'{path}:{"/".join(found)}')
        self.assertEqual(offenders, [], 'legacy relay workflows still auto-trigger: ' + ', '.join(offenders))

    def test_private_vm_ssh_jobs_run_on_site_self_hosted_runner(self):
        offenders = []
        for path, text in workflow_texts():
            for job, block in job_blocks(text):
                if not any(f'ubuntu@{target}' in block for target in PRIVATE_TARGETS):
                    continue
                if SITE_RUNNER not in block:
                    offenders.append(f'{path}:{job}')
        self.assertEqual(offenders, [], 'private VM SSH jobs not pinned to site runner: ' + ', '.join(offenders))

    def test_windows_bastion_recovery_enables_oracle_rsa_compatibility(self):
        path = WORKFLOWS / 'windows-relay-oci-recovery.yml'
        text = path.read_text(encoding='utf-8')
        self.assertIn(
            '-o HostKeyAlgorithms=+ssh-rsa',
            text,
            'OCI Bastion hop must allow the RSA host-key algorithm documented by Oracle',
        )
        self.assertIn(
            '-o PubkeyAcceptedAlgorithms=+ssh-rsa',
            text,
            'OCI Bastion hop must allow RSA public-key authentication on modern OpenSSH',
        )


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
