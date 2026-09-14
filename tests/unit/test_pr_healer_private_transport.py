from pathlib import Path
import unittest

WORKFLOW = Path('.github/workflows/pr-conflict-auto-healer.yml')


class PrHealerPrivateTransportTest(unittest.TestCase):
    def test_healer_runs_on_local_site_runner_without_public_vm_ip(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        self.assertIn('runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]', text)
        self.assertIn('VM_HOST: 127.0.0.1', text)
        self.assertNotIn('163.176.103.253', text)


if __name__ == '__main__':
    unittest.main()
