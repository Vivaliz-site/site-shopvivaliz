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
                offenders.append(f'{path}:{"/" .join(found)}')
        self.assertEqual(offenders, [], 'legacy relay workflows still auto-trigger: ' + ', '.join(offenders))

    def test_private_vm_ssh_jobs_use_verified_private_transport(self):
        offenders = []
        for path, text in workflow_texts():
            for job, block in job_blocks(text):
                if not any(f'ubuntu@{target}' in block for target in PRIVATE_TARGETS):
                    continue
                if SITE_RUNNER in block:
                    continue
                backend_bastion = (
                    'runs-on: ubuntu-latest' in block
                    and 'bastion session create-port-forwarding' in block
                    and '--target-private-ip "$BACKEND_PRIVATE_IP"' in block
                    and 'ubuntu@127.0.0.1' in block
                )
                site_bastion = (
                    'runs-on: ubuntu-latest' in block
                    and 'bastion session create-port-forwarding' in block
                    and '--target-private-ip "$SITE_PRIVATE_IP"' in block
                    and 'SITE_INSTANCE_NAME: shopvivaliz-free-a1' in block
                    and 'EXPECTED_SITE_HOST: shopvivaliz-free-a1' in block
                    and 'test "$identity" = "$EXPECTED_SITE_HOST/ubuntu"' in block
                    and 'ubuntu@127.0.0.1' in block
                )
                if not (backend_bastion or site_bastion):
                    offenders.append(f'{path}:{job}')
        self.assertEqual(
            offenders,
            [],
            'private VM SSH jobs lack verified self-hosted or Bastion transport: ' + ', '.join(offenders),
        )

    def test_windows_bastion_recovery_enables_oracle_rsa_compatibility(self):
        path = WORKFLOWS / 'windows-relay-oci-recovery.yml'
        text = path.read_text(encoding='utf-8')
        self.assertIn(
            'HostKeyAlgorithms +ssh-rsa',
            text,
            'OCI Bastion hop must allow the RSA host-key algorithm documented by Oracle',
        )
        self.assertIn(
            'PubkeyAcceptedAlgorithms +ssh-rsa',
            text,
            'OCI Bastion hop must allow RSA public-key authentication on modern OpenSSH',
        )
        self.assertIn(
            'PubkeyAcceptedKeyTypes +ssh-rsa',
            text,
            'OCI Bastion troubleshooting requires the legacy RSA key-type alias for public-key auth failures',
        )
        self.assertIn(
            'Host *',
            text,
            'OCI Bastion troubleshooting requires the RSA compatibility stanza to apply to the Bastion connection',
        )
        self.assertIn(
            '--ssh-public-key-file "$HOME/.ssh/bastion_session_key.pub"',
            text,
            'OCI Bastion session must be created with the public half of its ephemeral session key',
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
