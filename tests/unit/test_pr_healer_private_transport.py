from pathlib import Path
import unittest

WORKFLOW = Path('.github/workflows/pr-conflict-auto-healer.yml')


class PrHealerPrivateTransportTest(unittest.TestCase):
    def test_healer_isolated_from_production_deploy_runner(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        self.assertIn('runs-on: ubuntu-latest', text)
        self.assertNotIn('runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]', text)
        self.assertIn('SITE_INSTANCE_NAME: shopvivaliz-free-a1', text)
        self.assertIn('instance-agent command create', text)
        self.assertIn('Compute Instance Run Command', text)
        self.assertIn('GEMINI_ENV_FILE=/home/ubuntu/shopvivaliz-deploy/shared/.env', text)
        self.assertNotIn('VM_HOST: 127.0.0.1', text)
        self.assertNotIn('SHOPVIVALIZ_VM_SSH_KEY', text)
        self.assertNotIn('ORACLE_VM_SSH_KEY', text)


if __name__ == '__main__':
    unittest.main()
