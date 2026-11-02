"""Regression for canonical post-migration audit policy identities and blob parity."""
from __future__ import annotations

import ast
import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "docs/quality/GLOBAL_AUDIT_MANIFEST.json"
SCRIPTS = (
    "scripts/certify-audit-manifest.py",
    "scripts/validate-global-audit-policy.py",
)
RETIRED = (
    "fredmourao-ai/mei-mg-email",
    "fredmourao-ai/solange-rolla-consultorio",
)
CURRENT = (
    "Vivaliz-site/mei-mg-email",
    "Vivaliz-site/solange-rolla-consultorio",
)

def git_blob_sha(payload: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(payload)).encode("ascii") + b"\x00" + payload,
                        usedforsecurity=False).hexdigest()

def expected_repositories(script: Path) -> list[str]:
    tree = ast.parse(script.read_text(), filename=str(script))
    for node in tree.body:
        if isinstance(node, ast.Assign) and any(
            isinstance(t, ast.Name) and t.id in {"EXPECTED_REPOSITORIES", "REQUIRED_REPOSITORIES"}
            for t in node.targets
        ):
            return ast.literal_eval(node.value)
    raise AssertionError(f"repository allowlist constant missing in {script.name}")

class PostMigrationGlobalAuditParityTests(unittest.TestCase):
    def test_manifest_current_repositories_only(self):
        m = json.loads(MANIFEST.read_text())
        repos = m["required_repositories"]
        self.assertEqual("Vivaliz-site/-shopvivaliz-pipeline", m["canonical_repository"])
        self.assertEqual("GLOBAL_AUDIT_MANIFEST_V1", m["schema"])
        self.assertTrue(all(name in repos for name in CURRENT))
        self.assertTrue(all(name not in repos for name in RETIRED))
        self.assertEqual(len(repos), len(set(repos)))

    def test_scripts_use_same_repositories_as_canonical_manifest(self):
        m = json.loads(MANIFEST.read_text())
        for rel in SCRIPTS:
            with self.subTest(script=rel):
                expected = expected_repositories(ROOT / rel)
                self.assertEqual(m["required_repositories"], expected)
                text = (ROOT / rel).read_text()
                for old in RETIRED:
                    self.assertNotIn(old, text)

    def test_critical_script_blobs_match_canonical_manifest(self):
        m = json.loads(MANIFEST.read_text())
        for rel in SCRIPTS:
            with self.subTest(script=rel):
                actual = git_blob_sha((ROOT / rel).read_bytes())
                self.assertEqual(m["required_blobs"][rel], actual)

if __name__ == "__main__":
    unittest.main()
