import importlib.util
import unittest
from pathlib import Path

MODULE = Path(__file__).parents[1] / "ops" / "preview_ttl.py"
spec = importlib.util.spec_from_file_location("preview_ttl", MODULE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class PreviewTtlTests(unittest.TestCase):
    def test_default_ttl_is_48_hours(self):
        self.assertEqual(mod.MIN_AGE, 48 * 3600)

    def test_extract_pr_number_only_from_ephemeral_names(self):
        self.assertEqual(mod.extract_pr_number("solange-pr139"), 139)
        self.assertEqual(mod.extract_pr_number("solange-pr139-review"), 139)
        self.assertEqual(mod.extract_pr_number("solange-chat-20260913-pr122"), 122)
        self.assertIsNone(mod.extract_pr_number("solange-rolla-consultorio"))
        self.assertIsNone(mod.extract_pr_number("solange-client-demo"))

    def test_should_cleanup_requires_closed_pr_and_minimum_age(self):
        self.assertTrue(mod.should_cleanup("solange-pr139", "closed", 7201, 7200))
        self.assertFalse(mod.should_cleanup("solange-pr139", "open", 999999, 7200))
        self.assertFalse(mod.should_cleanup("solange-pr139", "closed", 100, 7200))
        self.assertFalse(mod.should_cleanup("solange-rolla-consultorio", "closed", 999999, 7200))


if __name__ == "__main__":
    unittest.main()
