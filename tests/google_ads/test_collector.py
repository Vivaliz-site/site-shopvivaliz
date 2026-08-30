import unittest

from scripts.google_ads.client import GoogleAdsError
from scripts.google_ads.collector import WINDOWS, collect_account


class FakeClient:
    customer_id = "1234567890"
    api_version = "v25"

    def __init__(self, fail_label=None, include_score=True):
        self.fail_label = fail_label
        self.include_score = include_score
        self.labels = []

    def query(self, label, gaql):
        self.labels.append(label)
        if label == self.fail_label:
            raise GoogleAdsError(label, "INVALID_ARGUMENT", "fixture secret detail")
        if label == "customer":
            customer = {"id": "1234567890", "descriptiveName": "Shop Vivaliz"}
            if self.include_score:
                customer["optimizationScore"] = 0.82
            return [{"customer": customer}]
        if label == "recommendations":
            return [
                {
                    "recommendation": {
                        "resourceName": "customers/123/recommendations/abc",
                        "type": "KEYWORD",
                        "campaign": "customers/123/campaigns/1",
                    }
                }
            ]
        if label == "conversion_actions":
            return [
                {
                    "conversionAction": {
                        "id": "7",
                        "name": "Purchase",
                        "status": "ENABLED",
                        "category": "PURCHASE",
                    }
                }
            ]
        if label == "campaigns_7d":
            return [
                {
                    "campaign": {"id": "1", "name": "Search"},
                    "metrics": {
                        "clicks": "10",
                        "impressions": "100",
                        "costMicros": "25000000",
                        "conversions": 2,
                        "conversionsValue": 100,
                    },
                }
            ]
        return []


class CollectorTests(unittest.TestCase):
    def test_collects_exactly_all_four_windows_and_datasets(self):
        client = FakeClient()
        result = collect_account(client)
        self.assertEqual(tuple(result["windows"]), tuple(WINDOWS))
        for window in WINDOWS:
            self.assertEqual(
                set(result["windows"][window]),
                {"campaigns", "ad_groups", "keywords", "search_terms", "ads"},
            )
            for dataset in result["windows"][window]:
                self.assertIn(f"{dataset}_{window}", client.labels)

    def test_normalizes_customer_score_and_recommendation(self):
        result = collect_account(FakeClient())
        self.assertEqual(result["customer"]["optimization_score"], 0.82)
        self.assertTrue(result["customer"]["score_available"])
        self.assertEqual(result["recommendations"][0]["type"], "KEYWORD")
        self.assertEqual(
            result["recommendations"][0]["resource_name"],
            "customers/123/recommendations/abc",
        )

    def test_missing_optimization_score_does_not_fail(self):
        result = collect_account(FakeClient(include_score=False))
        self.assertIsNone(result["customer"]["optimization_score"])
        self.assertFalse(result["customer"]["score_available"])
        self.assertFalse(result["partial"])

    def test_normalizes_conversion_action(self):
        result = collect_account(FakeClient())
        self.assertEqual(result["conversion_actions"][0]["category"], "PURCHASE")
        self.assertEqual(result["conversion_actions"][0]["status"], "ENABLED")

    def test_normalizes_cost_micros_and_metrics(self):
        row = collect_account(FakeClient())["windows"]["7d"]["campaigns"][0]
        self.assertEqual(row["cost"], 25.0)
        self.assertEqual(row["clicks"], 10.0)
        self.assertEqual(row["conversion_value"], 100.0)

    def test_failed_dataset_marks_partial_and_sanitizes_error(self):
        result = collect_account(FakeClient(fail_label="search_terms_7d"))
        self.assertTrue(result["partial"])
        self.assertEqual(result["windows"]["7d"]["search_terms"], [])
        error = result["errors"][0]
        self.assertEqual(error["dataset"], "search_terms")
        self.assertEqual(error["window"], "7d")
        self.assertEqual(error["reason"], "INVALID_ARGUMENT")
        self.assertNotIn("fixture secret detail", str(error))


if __name__ == "__main__":
    unittest.main()
