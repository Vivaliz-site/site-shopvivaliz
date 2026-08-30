import importlib.util
from pathlib import Path
import unittest

MODULE_PATH = Path(__file__).parents[1] / ".github" / "scripts" / "ai_conflict_resolver.py"
spec = importlib.util.spec_from_file_location("ai_conflict_resolver", MODULE_PATH)
resolver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(resolver)


class ConflictResolverSafetyTests(unittest.TestCase):
    def test_protected_paths_are_blocked(self):
        blocked = [".env", ".github/workflows/deploy-production.yml", "migrations/001.sql", "certs/private.key", "includes/auth.php", "checkout/payment.php", "infra/production.tf"]
        for path in blocked:
            with self.subTest(path=path):
                self.assertTrue(resolver.is_protected_path(path))

    def test_regular_source_path_is_allowed(self):
        self.assertFalse(resolver.is_protected_path("assets/js/catalog-filter.js"))

    def test_conflict_markers_are_rejected(self):
        self.assertTrue(resolver.has_conflict_markers("<<<<<<< ours\na\n=======\nb\n>>>>>>> theirs\n"))
        self.assertFalse(resolver.has_conflict_markers("a\nb\n"))

    def test_model_json_requires_matching_path(self):
        raw = '{"path":"assets/js/a.js","content":"const x = 1;\\n"}'
        self.assertEqual("const x = 1;\n", resolver.parse_model_content(raw, "assets/js/a.js"))
        with self.assertRaises(ValueError):
            resolver.parse_model_content(raw, "assets/js/b.js")

    def test_candidate_rejects_empty_or_large_deletion(self):
        original = "\n".join(str(i) for i in range(100)) + "\n"
        with self.assertRaises(ValueError): resolver.validate_candidate("assets/js/a.js", original, "")
        with self.assertRaises(ValueError): resolver.validate_candidate("assets/js/a.js", original, "1\n")

    def test_candidate_accepts_small_safe_resolution(self):
        self.assertEqual("a\nb2\nc\n", resolver.validate_candidate("assets/js/a.js", "a\nb\nc\n", "a\nb2\nc\n"))


if __name__ == "__main__":
    unittest.main()