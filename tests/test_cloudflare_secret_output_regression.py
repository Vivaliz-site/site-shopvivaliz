import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/cloudflare-email-routing-bootstrap.yml"


class CloudflareSecretOutputRegressionTests(unittest.TestCase):
    def test_secret_variables_are_never_logged_on_their_reference_line(self):
        for line in WORKFLOW.read_text(encoding="utf-8").splitlines():
            if "CF_TOKEN" in line or "BREVO_API_KEY" in line:
                self.assertNotIn("echo", line)


if __name__ == "__main__":
    unittest.main()
