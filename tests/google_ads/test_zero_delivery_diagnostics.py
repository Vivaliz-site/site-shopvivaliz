"""The zero-impression Google Ads signal is read-only and never a bid decision."""
import unittest
from pathlib import Path

from scripts.google_ads_review_campaigns import zero_delivery_classification, inspect_zero_delivery


class DummyEnum:
    def __init__(self, name):
        self.name = name


class DummyCriterion:
    def __init__(self, status):
        self.primary_status = DummyEnum(status)
        self.primary_status_reasons = [DummyEnum("AD_GROUP_CRITERION_RARELY_SERVED")] if status == "NOT_ELIGIBLE" else ([DummyEnum("AD_GROUP_CRITERION_LOW_QUALITY")] if status == "UNKNOWN" else [])


class DummyKeywordRow:
    def __init__(self, status):
        self.ad_group_criterion = DummyCriterion(status)


class DummyCampaign:
    serving_status = DummyEnum("SERVING")
    primary_status = DummyEnum("ELIGIBLE")
    bidding_strategy_type = DummyEnum("TARGET_SPEND")

    class target_spend:
        cpc_bid_ceiling_micros = 800000


class DummyCampaignRow:
    campaign = DummyCampaign()


class FakeGoogleService:
    def __init__(self):
        self.queries = []

    def search(self, customer_id, query):
        self.queries.append(query)
        if "keyword_view" in query:
            return iter([DummyKeywordRow("ELIGIBLE"), DummyKeywordRow("NOT_ELIGIBLE"), DummyKeywordRow("UNKNOWN")])
        return iter([DummyCampaignRow()])


class ZeroDeliveryTests(unittest.TestCase):
    def test_no_false_blocker_for_paused_or_delivering_campaign(self):
        self.assertIsNone(zero_delivery_classification("PAUSED", 0, []))
        self.assertIsNone(zero_delivery_classification("ENABLED", 1, []))

    def test_no_causal_or_commercial_claim_for_enabled_zero_impressions(self):
        signal = zero_delivery_classification("ENABLED", 0, ["ELIGIBLE", "NOT_ELIGIBLE", "UNKNOWN"])
        self.assertEqual(signal["status"], "ZERO_IMPRESSIONS_REVIEW_ONLY")
        self.assertEqual(signal["keyword_eligible"], 1)
        self.assertEqual(signal["keyword_not_eligible"], 1)
        self.assertEqual(signal["keyword_unknown"], 1)
        self.assertFalse(signal["cause_certified"])
        self.assertFalse(signal["commercial_mutation_authorized"])

    def test_official_gaql_select_only_and_bounded(self):
        fake = FakeGoogleService()
        output = inspect_zero_delivery(fake, "1234567890", 23900755807, "ENABLED", 0)
        self.assertEqual(output["cpc_ceiling_brl"], 0.8)
        self.assertEqual(output["keyword_reason_rarely_served"], 1)
        self.assertEqual(output["keyword_reason_low_quality"], 1)
        self.assertEqual(output["bidding_strategy"], "TARGET_SPEND")
        self.assertEqual(output["serving_status"], "SERVING")
        self.assertEqual(len(fake.queries), 2)
        self.assertTrue(all(q.lstrip().upper().startswith("SELECT ") for q in fake.queries))
        self.assertTrue(all("campaign.id = 23900755807" in q for q in fake.queries))
        self.assertIn("LIMIT 100", fake.queries[0])

    def test_no_campaign_mutation_in_readonly_module(self):
        module = Path(__file__).resolve().parents[2] / "scripts/google_ads_review_campaigns.py"
        code = module.read_text()
        self.assertNotIn("mutate_campaign", code)
        self.assertNotIn("mutate_ad_group", code)
        self.assertNotIn("mutate_google_ads", code)


if __name__ == "__main__":
    unittest.main()
