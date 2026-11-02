"""Ensure canonical CRLF audit JSON stays byte-exact and clean in Linux sync checkout.

This does not change the certified JSON, global audit hashes, or security policy.
"""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = "docs/quality/GLOBAL_AUDIT_MANIFEST.json"
CONTROL = "docs/quality/AUDIT_CERTIFICATION_MANIFEST_TEMPLATE.json"

def git(*args):
    return subprocess.run(["git", "-C", str(ROOT), *args],
                          check=True, text=True, capture_output=True).stdout

class CanonicalAuditManifestLineEndingTests(unittest.TestCase):
    def test_exception_is_exactly_scoped_to_upstream_byte_parity(self):
        attrs = (ROOT / ".gitattributes").read_text(encoding="utf-8")
        self.assertIn(f"{MANIFEST} -text -eol", attrs)
        result = git("check-attr", "text", "eol", "--", MANIFEST)
        self.assertIn(f"{MANIFEST}: text: unset", result)
        self.assertIn(f"{MANIFEST}: eol: unset", result)
        other = git("check-attr", "text", "eol", "--", CONTROL)
        self.assertIn(f"{CONTROL}: text: set", other)
        self.assertIn(f"{CONTROL}: eol: lf", other)

    def test_canonical_blob_is_identical_to_disk_and_not_dirty(self):
        checkout = (ROOT / MANIFEST).read_bytes()
        target = subprocess.run(["git", "-C", str(ROOT), "show", "HEAD:" + MANIFEST],
                                check=True, capture_output=True).stdout
        self.assertEqual(checkout, target)
        self.assertIn(b"\r\n", target, "This regression targets upstream CRLF source")
        raw_sha = git("hash-object", "--path=" + MANIFEST, str(ROOT / MANIFEST)).strip()
        head_sha = git("rev-parse", "HEAD:" + MANIFEST).strip()
        self.assertEqual(head_sha, raw_sha)
        self.assertEqual("", git("status", "--porcelain=v1", "--", MANIFEST).strip())

if __name__ == "__main__":
    unittest.main()
