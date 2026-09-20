from pathlib import Path
import unittest

WORKFLOW = Path('.github/workflows/pr-conflict-auto-healer.yml')
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

    def test_bastion_policy_mutations_are_serialized_repository_wide(self):
        healer = WORKFLOW.read_text(encoding='utf-8')
        relay = WINDOWS_RELAY.read_text(encoding='utf-8')
        group = 'group: shopvivaliz-bastion-access'
        self.assertIn(group, healer)
        self.assertIn(group, relay)
        self.assertIn('cancel-in-progress: false', healer)
        self.assertIn('cancel-in-progress: false', relay)


if __name__ == '__main__':
    unittest.main()
