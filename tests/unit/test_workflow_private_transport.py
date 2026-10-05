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
           for event in ('schedule', 'pull_request', 'workflow_run', 'pull_request_target', 'repository_dispatch', 'issue_comment')):
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


def workflow_run_script(step: str) -> str:
    match = re.search(r'(?m)^        run:\s*\|[+-]?\s*\n', step)
    if not match:
        return ''
    lines = []
    for line in step[match.end():].splitlines():
        if line.strip() and not line.startswith('          '):
            break
        value = line[10:]
        if value.strip() and not value.lstrip().startswith('#'):
            lines.append(value)
    return '\n'.join(lines)


def uses_verified_private_bastion(block: str) -> bool:
    """Check the declared hosted tunnel and its unconditional cleanup step."""
    if not re.search(r'(?m)^    runs-on: ubuntu-latest\s*$', block):
        return False
    if not re.search(r'(?m)^    environment: Production\s*$', block):
        return False
    timeout = re.search(r'(?m)^    timeout-minutes: (\d+)\s*$', block)
    if not timeout or not 0 < int(timeout.group(1)) <= 60:
        return False
    starts = list(re.finditer(r'(?m)^      - ', block))
    steps = [block[item.start():starts[i+1].start() if i+1 < len(starts) else len(block)]
             for i, item in enumerate(starts)]
    scripts = '\n'.join(workflow_run_script(step) for step in steps)
    if 'ubuntu@10.0.1.38' in scripts:
        return False
    for marker in ('bastion session create-port-forwarding', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile='):
        if marker not in scripts:
            return False
    for step in steps:
        if not re.search(r'(?m)^        if: (?:always\(\)|\$\{\{\s*always\(\)\s*\}\})\s*$', step):
            continue
        cleanup = workflow_run_script(step)
        original_cidrs = re.search(r"""--client-cidr-list\s+["']?file://\$(?:\{ORIGINAL_CIDRS_FILE\}|ORIGINAL_CIDRS_FILE)""", cleanup)
        if ('bastion session delete' in cleanup
                and 'bastion bastion update' in cleanup and original_cidrs):
            return True
    return False

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
        automatic = ('schedule:', 'push:', 'pull_request:', 'workflow_run:', 'pull_request_target:', 'repository_dispatch:', 'issue_comment:')
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




CONFORMING_BASTION_JOB = """  probe:
    runs-on: ubuntu-latest
    environment: Production
    timeout-minutes: 5
    steps:
      - name: Create private tunnel
        run: |
          oci bastion session create-port-forwarding --target-private-ip 10.0.1.38
          ssh -o StrictHostKeyChecking=yes -o UserKnownHostsFile=known ubuntu@127.0.0.1
      - name: Cleanup
        if: always()
        run: |
          oci bastion session delete --session-id fixture
          oci bastion bastion update --client-cidr-list "file://$ORIGINAL_CIDRS_FILE"
"""


class ReviewedTransportBoundaryTests(unittest.TestCase):
    def test_conforming_bastion_is_accepted(self):
        self.assertTrue(uses_verified_private_bastion(CONFORMING_BASTION_JOB))

    def test_comments_cannot_certify_a_bastion_job(self):
        comments = '\n'.join('    # ' + line for line in CONFORMING_BASTION_JOB.splitlines())
        self.assertFalse(uses_verified_private_bastion(comments))

    def test_cleanup_must_really_run_always(self):
        malformed = CONFORMING_BASTION_JOB.replace(
            '        if: always()', '        # if: always()\n        if: failure()')
        self.assertFalse(uses_verified_private_bastion(malformed))

    def test_cleanup_must_restore_original_cidrs(self):
        malformed = CONFORMING_BASTION_JOB.replace(
            'file://$ORIGINAL_CIDRS_FILE', 'file://$EXPANDED_CIDRS_FILE')
        malformed += '\n    # ORIGINAL_CIDRS_FILE'
        self.assertFalse(uses_verified_private_bastion(malformed))

    def test_both_bootstrap_allowlists_reject_unapproved_events_and_extra_paths(self):
        for name in ('windows-peer-emergency-recovery.yml', 'remote-control-mcp-bootstrap.yml'):
            path = Path('.github/workflows') / name
            text = path.read_text()
            self.assertTrue(reviewed_bootstrap_push(path, text))
            for event in ('pull_request_target', 'repository_dispatch', 'issue_comment'):
                changed = text.replace('  push:', '  ' + event + ':\n  push:')
                self.assertFalse(reviewed_bootstrap_push(path, changed), name + ':' + event)
            self.assertFalse(reviewed_bootstrap_push(path, text.replace(
                '    paths:', '    paths:\n      - "application/**"')))


if __name__ == '__main__':
    unittest.main()
