import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class CloudflareSecretOutputRegressionTests(unittest.TestCase):
    def test_cf_token_reference_never_shares_a_logging_line(self):
        workflow = (
            ROOT / ".github/workflows/cloudflare-email-routing-bootstrap.yml"
        ).read_text(encoding="utf-8")
        for line in workflow.splitlines():
            if "CF_TOKEN" in line:
                self.assertNotIn("echo", line)


if __name__ == "__main__":
    unittest.main()
