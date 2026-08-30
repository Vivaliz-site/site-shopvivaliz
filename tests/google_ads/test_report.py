import json
import unittest

from scripts.google_ads.report import build_report, sanitize


CONFIG = {
    "min_recent_conversions_for_scaling": 15,
    "max_budget_increase_pct": 0.10,
    "min_search_term_clicks_for_negative_candidate": 20,
    "min_search_term_spend_brl_for_negative_candidate": 30.0,
    "protected_terms": ["vivaliz"],
    "target_cpa_brl": None,
    "minimum_roas": None,
    "gross_margin_pct": None,
}


def fixture_collected():
    windows = {
        window: {"campaigns": [], "ad_groups": [], "keywords": [], "search_terms": [], "ads": []}
        for window in ("1d", "3d", "7d", "30d")
    }
    windows["7d"]["campaigns"] = [
        {
            "campaign": {"id": "1", "name": "Search"},
            "impressions": 100,
            "clicks": 10,
            "cost": 50,
            "conversions": 0,
            "conversion_value": 0,
        }
    ]
    windows["30d"]["search_terms"] = [
        {
            "search_term_view": {"search_term": "irrelevant fixture"},
            "clicks": 25,
            "cost": 40,
            "conversions": 0,
            "conversion_value": 0,
        }
    ]
    return {
        "api_version": "v25",
        "generated_at": "2026-08-30T00:00:00Z",
        "customer": {
            "id": "1234567890",
            "descriptive_name": "Shop Vivaliz",
            "optimization_score": 0.82,
            "score_available": True,
        },
        "recommendations": [
            {
                "resource_name": "customers/123/recommendations/a",
                "type": "CAMPAIGN_BUDGET",
                "proposed_increase_pct": 0.05,
                "impact": {"potential_metrics": {"conversions": 10}},
            }
        ],
        "conversion_actions": [
            {
                "id": "7",
                "name": "Purchase",
                "status": "ENABLED",
                "category": "PURCHASE",
                "include_in_conversions_metric": True,
            }
        ],
        "windows": windows,
        "errors": [],
        "partial": False,
    }


class ReportTests(unittest.TestCase):
    def test_report_has_stable_readonly_schema(self):
        report = build_report(fixture_collected(), CONFIG)
        self.assertEqual(
            set(report),
            {
                "schema_version",
                "mode",
                "api_version",
                "generated_at",
                "customer",
                "optimization",
                "tracking_health",
                "windows",
                "findings",
                "decisions",
                "guardrails",
                "errors",
                "partial",
            },
        )
        self.assertEqual(report["mode"], "readonly")
        self.assertEqual(report["schema_version"], 1)

    def test_recursive_sanitization_removes_secret_keys_and_values(self):
        payload = {
            "refresh_token": "refresh-secret",
            "nested": {
                "client_secret": "client-secret",
                "safe": "access-secret and dev-secret",
                "developer_token": "dev-secret",
            },
        }
        cleaned = sanitize(
            payload,
            secret_values=("refresh-secret", "access-secret", "client-secret", "dev-secret"),
        )
        blob = json.dumps(cleaned).lower()
        for forbidden in (
            "refresh_token",
            "client_secret",
            "developer_token",
            "refresh-secret",
            "access-secret",
            "client-secret",
            "dev-secret",
        ):
            self.assertNotIn(forbidden, blob)

    def test_zero_conversions_keep_cpa_null_and_real_zero_roas(self):
        report = build_report(fixture_collected(), CONFIG)
        metrics = report["windows"]["7d"]["campaigns"][0]["derived_metrics"]
        self.assertIsNone(metrics["cpa"])
        self.assertEqual(metrics["roas"], 0.0)

    def test_tracking_uncertainty_blocks_real_budget_recommendation(self):
        report = build_report(fixture_collected(), CONFIG)
        self.assertEqual(report["tracking_health"]["status"], "unknown")
        self.assertEqual(report["decisions"][0]["classification"], "REVIEW")
        self.assertIn("tracking_not_healthy", report["decisions"][0]["blocked_by"])

    def test_search_term_waste_is_review_only_without_catalog_reconciliation(self):
        report = build_report(fixture_collected(), CONFIG)
        finding = next(item for item in report["findings"] if item["type"] == "search_term_waste_candidate")
        self.assertEqual(finding["classification"], "REVIEW")
        self.assertIn("catalog_intent_not_reconciled", finding["blocked_by"])

    def test_customer_identifier_is_masked(self):
        report = build_report(fixture_collected(), CONFIG)
        self.assertEqual(report["customer"]["id"], "******7890")


if __name__ == "__main__":
    unittest.main()
