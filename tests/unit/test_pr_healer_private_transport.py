from pathlib import Path
import unittest

WORKFLOW = Path('.github/workflows/pr-conflict-auto-healer.yml')
PR_ENFORCER = Path('.github/workflows/pr-completion-enforcer.yml')
WINDOWS_RELAY = Path('.github/workflows/windows-relay-oci-recovery.yml')


class PrHealerPrivateTransportTest(unittest.TestCase):
    def test_healer_isolated_from_production_deploy_runner_via_bastion(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        self.assertIn('runs-on: ubuntu-latest', text)
        self.assertIn('environment: Production', text)
        self.assertNotIn('runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]', text)
        self.assertIn('SITE_INSTANCE_NAME: shopvivaliz-free-a1', text)
        self.assertIn('EXPECTED_SITE_HOST: shopvivaliz-free-a1', text)
        self.assertIn('bastion session create-port-forwarding', text)
        self.assertIn('BASTION_ALLOWLIST_UPDATED=true', text)
        self.assertIn('BASTION_ALLOWLIST_RESTORED=PASS', text)
        self.assertIn('test "$identity" = "$EXPECTED_SITE_HOST/ubuntu"', text)
        self.assertIn('GEMINI_ENV_FILE=/home/ubuntu/shopvivaliz-deploy/shared/.env', text)
        self.assertIn('production_deploy_runner_reserved=true', text)
        self.assertNotIn('instance-agent command create', text)
        self.assertNotIn('163.176.103.253', text)

    def test_pr_completion_enforcer_isolated_from_production_deploy_runner(self):
        text = PR_ENFORCER.read_text(encoding='utf-8')
        self.assertIn('runs-on: ubuntu-latest', text)
        self.assertIn('environment: Production', text)
        self.assertNotIn('runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]', text)
        self.assertIn('SITE_INSTANCE_NAME: shopvivaliz-free-a1', text)
        self.assertIn('EXPECTED_SITE_HOST: shopvivaliz-free-a1', text)
        self.assertIn('bastion session create-port-forwarding', text)
        self.assertIn('BASTION_ALLOWLIST_RESTORED=PASS', text)
        self.assertIn('production_deploy_runner_reserved=true', text)
        self.assertNotIn('instance-agent command create', text)
        self.assertNotIn('VM_HOST: 127.0.0.1', text)

    def test_bastion_jobs_serialize_without_losing_workflow_deduplication(self):
        healer = WORKFLOW.read_text(encoding='utf-8')
        enforcer = PR_ENFORCER.read_text(encoding='utf-8')
        relay = WINDOWS_RELAY.read_text(encoding='utf-8')
        group = 'group: shopvivaliz-bastion-access'
        self.assertIn(group, healer)
        self.assertIn(group, enforcer)
        self.assertIn(group, relay)
        self.assertIn("group: pr-conflict-auto-healer-${{ github.event.pull_request.number || 'sweep' }}", healer)
        self.assertIn('group: pr-completion-enforcer', enforcer)
        self.assertIn('group: shopvivaliz-windows-relay-oci-recovery', relay)


if __name__ == '__main__':
    unittest.main()
