import unittest

from scripts.google_ads.metrics import derive_metrics, safe_div, tracking_health, trend


class MetricsTests(unittest.TestCase):
    def test_safe_div_zero_returns_none(self):
        self.assertIsNone(safe_div(10, 0))

    def test_derive_metrics_does_not_invent_cpa_or_roas(self):
        result = derive_metrics(
            {
                "clicks": 0,
                "impressions": 100,
                "cost": 0.0,
                "conversions": 0.0,
                "conversion_value": 0.0,
            }
        )
        self.assertEqual(result["ctr"], 0.0)
        self.assertIsNone(result["cpc"])
        self.assertIsNone(result["cpa"])
        self.assertIsNone(result["roas"])
        self.assertIsNone(result["conversion_rate"])

    def test_derive_metrics_calculates_valid_values(self):
        result = derive_metrics(
            {
                "clicks": "20",
                "impressions": "200",
                "cost": "100",
                "conversions": "4",
                "conversion_value": "500",
            }
        )
        self.assertEqual(result["ctr"], 0.1)
        self.assertEqual(result["cpc"], 5.0)
        self.assertEqual(result["cpa"], 25.0)
        self.assertEqual(result["roas"], 5.0)
        self.assertEqual(result["conversion_rate"], 0.2)

    def test_trend_requires_nonzero_baseline(self):
        self.assertIsNone(trend(10.0, 0.0))
        self.assertEqual(trend(12.0, 10.0), 0.2)

    def test_tracking_unknown_without_purchase_action(self):
        health = tracking_health([], {"7d": []})
        self.assertEqual(health["status"], "unknown")
        self.assertIn("purchase_conversion_action_missing", health["reasons"])

    def test_tracking_unknown_without_recent_purchase_conversions(self):
        actions = [{"category": "PURCHASE", "status": "ENABLED", "include_in_conversions_metric": True}]
        health = tracking_health(actions, {"7d": [{"conversions": 0, "conversion_value": 0}]})
        self.assertEqual(health["status"], "unknown")
        self.assertIn("recent_purchase_conversions_missing", health["reasons"])

    def test_tracking_healthy_with_enabled_action_and_recent_value(self):
        actions = [{"category": "PURCHASE", "status": "ENABLED", "include_in_conversions_metric": True}]
        health = tracking_health(actions, {"7d": [{"conversions": 2, "conversion_value": 150}]})
        self.assertEqual(health["status"], "healthy")
        self.assertEqual(health["reasons"], [])

    def test_non_primary_purchase_action_does_not_open_scaling_gate(self):
        actions = [
            {
                "category": "PURCHASE",
                "status": "ENABLED",
                "primary_for_goal": False,
                "include_in_conversions_metric": True,
            }
        ]
        health = tracking_health(actions, {"7d": [{"conversions": 2, "conversion_value": 150}]})
        self.assertEqual(health["status"], "unknown")
        self.assertIn("purchase_conversion_action_missing", health["reasons"])


if __name__ == "__main__":
    unittest.main()
