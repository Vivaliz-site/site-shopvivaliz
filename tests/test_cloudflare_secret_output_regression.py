from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "cloudflare-email-routing-bootstrap.yml"
SECRET_NAMES = ("CF_TOKEN", "BREVO_API_KEY")


class CloudflareSecretOutputRegressionTests(unittest.TestCase):
    # Secret identifiers may be referenced for checks, but never on a logging line.
    def test_secret_identifiers_are_never_referenced_on_logging_lines(self):
        text = WORKFLOW.read_text(encoding="utf-8-sig")
        for line_number, line in enumerate(text.splitlines(), start=1):
            if not any(name in line for name in SECRET_NAMES):
                continue
            self.assertNotIn(
                "echo",
                line.lower(),
                f"secret reference is mixed with logging at line {line_number}: {line.strip()}",
            )


if __name__ == "__main__":
    unittest.main()
