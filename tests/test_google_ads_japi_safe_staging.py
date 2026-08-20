from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "google_ads_stage_generic_japi_safe.py"
WORKFLOW = ROOT / ".github" / "workflows" / "google-ads-generic-japi-expansion.yml"


class JapiSafeStagingContractTest(unittest.TestCase):
    def test_creative_has_no_coupon_or_discount_claim(self):
        text = SCRIPT.read_text(encoding="utf-8")
        for forbidden in ("VIVALIZ10", "PRIMEIRA10", "10% de desconto", "5% de desconto"):
            self.assertNotIn(forbidden, text)
        self.assertIn('query.pop("cupom", None)', text)

    def test_stages_paused_before_google_review(self):
        text = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("AdGroupAdStatusEnum.PAUSED", text)
        self.assertIn('approval == "APPROVED" and review == "REVIEWED"', text)
        self.assertIn("RESULT=WAITING_GOOGLE_REVIEW", text)
        self.assertIn("RESULT=SAFE_JAPI_EXPANSION_LIVE", text)

    def test_workflow_uses_only_safe_staging_entrypoint(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("google_ads_stage_generic_japi_safe.py", text)
        self.assertNotIn("python3 scripts/google_ads_expand_generic_japi.py", text)


if __name__ == "__main__":
    unittest.main()
