from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def section(path: str, heading: str) -> str:
    text = (ROOT / path).read_text(encoding="utf-8")
    start = text.index(heading)
    tail = text[start + len(heading):]
    end = tail.find("\n## ")
    return text[start:] if end < 0 else text[start:start + len(heading) + end]


class EmailDocsBrevoOnlyTests(unittest.TestCase):
    def test_canonical_email_docs_match_brevo_only_runtime(self) -> None:
        docs = [
            section("docs/email-secrets-aliases.md", "# Email Secrets Aliases"),
            section("docs/secrets-inventory.md", "## Email / SMTP"),
            section("docs/knowledge/secrets-and-integrations-map.md", "## SMTP / Email"),
        ]
        for body in docs:
            self.assertIn("BREVO_API_KEY", body)
            self.assertIn("EMAIL_TO", body)
            lowered = body.lower()
            self.assertTrue("aposentad" in lowered or "retirad" in lowered)
            if "SMTP_HOST" in body:
                marker = max(lowered.find("aposentad"), lowered.find("retirad"))
                self.assertGreaterEqual(marker, 0)
                self.assertLess(marker, body.find("SMTP_HOST"))


if __name__ == "__main__":
    unittest.main()
