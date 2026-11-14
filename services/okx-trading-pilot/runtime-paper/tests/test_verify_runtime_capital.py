"""Regression for PAPER ledger baseline after simulated capital changes."""
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "verify_runtime.py"
spec = spec_from_file_location("okx_paper_verify_runtime", SCRIPT)
verify_runtime = module_from_spec(spec)
spec.loader.exec_module(verify_runtime)


class CapitalBaselineTests(unittest.TestCase):
    def test_current_and_original_simulated_capital_are_valid(self):
        for capital in ("100", "2500", "2500.00"):
            with self.subTest(capital=capital):
                verify_runtime.validate_state_baseline(
                    {"run_id": "paper-run", "initial_equity": capital},
                    {"run_id": "paper-run", "starting_equity": "2500" if capital == "2500.00" else capital},
                )

    def test_different_simulated_capital_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, "initial_capital_mismatch"):
            verify_runtime.validate_state_baseline(
                {"run_id": "paper-run", "initial_equity": "2500"},
                {"run_id": "paper-run", "starting_equity": "100"},
            )

    def test_run_identifier_mismatch_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, "run_id_mismatch"):
            verify_runtime.validate_state_baseline(
                {"run_id": "wrong-run", "initial_equity": "2500"},
                {"run_id": "paper-run", "starting_equity": "2500"},
            )


if __name__ == "__main__":
    unittest.main()
