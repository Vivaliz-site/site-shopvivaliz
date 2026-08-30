import unittest

from scripts.google_ads.policy import classify_recommendation


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


class PolicyTests(unittest.TestCase):
    def test_unknown_type_fails_closed(self):
        result = classify_recommendation(
            {"type": "FUTURE_UNKNOWN", "resource_name": "x"},
            {"tracking_health": "healthy"},
            CONFIG,
        )
        self.assertEqual(result["classification"], "REVIEW")
        self.assertIn("unknown_recommendation_type", result["reason_codes"])

    def test_budget_scaling_blocked_when_tracking_unknown(self):
        result = classify_recommendation(
            {"type": "CAMPAIGN_BUDGET", "resource_name": "x", "proposed_increase_pct": 0.05},
            {"tracking_health": "unknown", "recent_conversions": 50},
            CONFIG,
        )
        self.assertNotEqual(result["classification"], "APPLY")
        self.assertIn("tracking_not_healthy", result["blocked_by"])

    def test_budget_over_ten_percent_never_apply(self):
        result = classify_recommendation(
            {"type": "CAMPAIGN_BUDGET", "resource_name": "x", "proposed_increase_pct": 0.20},
            {"tracking_health": "healthy", "recent_conversions": 100},
            CONFIG,
        )
        self.assertNotEqual(result["classification"], "APPLY")
        self.assertIn("budget_change_exceeds_cap", result["blocked_by"])

    def test_budget_economics_missing_stays_review(self):
        result = classify_recommendation(
            {"type": "CAMPAIGN_BUDGET", "resource_name": "x", "proposed_increase_pct": 0.05},
            {"tracking_health": "healthy", "recent_conversions": 100},
            CONFIG,
        )
        self.assertEqual(result["classification"], "REVIEW")
        self.assertIn("business_thresholds_missing", result["blocked_by"])

    def test_broad_match_defaults_review_without_strong_evidence(self):
        result = classify_recommendation(
            {"type": "USE_BROAD_MATCH_KEYWORD", "resource_name": "x"},
            {
                "tracking_health": "healthy",
                "recent_conversions": 2,
                "negative_keyword_protection": False,
            },
            CONFIG,
        )
        self.assertEqual(result["classification"], "REVIEW")

    def test_performance_max_is_never_apply_in_phase_one(self):
        result = classify_recommendation(
            {"type": "PERFORMANCE_MAX_OPT_IN", "resource_name": "x"},
            {"tracking_health": "healthy", "recent_conversions": 100},
            CONFIG,
        )
        self.assertEqual(result["classification"], "REVIEW")
        self.assertIn("phase_one_excluded", result["blocked_by"])

    def test_performance_max_migration_is_explicitly_phase_one_excluded(self):
        result = classify_recommendation(
            {"type": "MIGRATE_DYNAMIC_SEARCH_ADS_CAMPAIGN_TO_PERFORMANCE_MAX", "resource_name": "x"},
            {"tracking_health": "healthy", "recent_conversions": 100},
            CONFIG,
        )
        self.assertEqual(result["classification"], "REVIEW")
        self.assertIn("phase_one_excluded", result["blocked_by"])

    def test_verified_rsa_asset_completeness_can_be_apply(self):
        result = classify_recommendation(
            {"type": "RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH", "resource_name": "x"},
            {"content_quality_verified": True, "reversible": True},
            CONFIG,
        )
        self.assertEqual(result["classification"], "APPLY")

    def test_bidding_migration_never_classifies_apply(self):
        configured = dict(CONFIG, target_cpa_brl=50.0)
        result = classify_recommendation(
            {"type": "TARGET_CPA_OPT_IN", "resource_name": "x"},
            {"tracking_health": "healthy", "recent_conversions": 100},
            configured,
        )
        self.assertEqual(result["classification"], "TEST")

    def test_set_target_roas_is_treated_as_bidding_experiment(self):
        configured = dict(CONFIG, minimum_roas=3.0)
        result = classify_recommendation(
            {"type": "SET_TARGET_ROAS", "resource_name": "x"},
            {"tracking_health": "healthy", "recent_conversions": 100},
            configured,
        )
        self.assertEqual(result["classification"], "TEST")


if __name__ == "__main__":
    unittest.main()
