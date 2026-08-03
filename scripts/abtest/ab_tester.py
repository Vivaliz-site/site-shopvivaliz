#!/usr/bin/env python3
"""Sistema de A/B testing baseado exclusivamente em eventos reais."""

import json
import random
from datetime import datetime, timedelta
from typing import Dict, List, Optional


class ABTester:
    def __init__(self) -> None:
        self.tests: Dict[str, Dict] = {}

    def create_ab_test(self, product_id: str, images: List[Dict]) -> Dict:
        """Cria um teste A/B e inicializa contadores observáveis."""
        test_id = f"test_{product_id}_{int(random.random() * 10000)}"
        test = {
            "test_id": test_id,
            "product_id": product_id,
            "images": images,
            "created_at": datetime.now().isoformat(),
            "status": "running",
            "results": None,
        }

        for image in images:
            image["impressions"] = 0
            image["clicks"] = 0
            image["conversions"] = 0
            image["ctr"] = 0.0
            image["conversion_rate"] = 0.0

        self.tests[test_id] = test
        return test

    def record_impression(self, test_id: str, image_variant: int) -> None:
        if test_id in self.tests and image_variant < len(self.tests[test_id]["images"]):
            self.tests[test_id]["images"][image_variant]["impressions"] += 1

    def record_click(self, test_id: str, image_variant: int) -> None:
        if test_id in self.tests and image_variant < len(self.tests[test_id]["images"]):
            self.tests[test_id]["images"][image_variant]["clicks"] += 1
            self._update_metrics(test_id, image_variant)

    def record_conversion(self, test_id: str, image_variant: int) -> None:
        if test_id in self.tests and image_variant < len(self.tests[test_id]["images"]):
            self.tests[test_id]["images"][image_variant]["conversions"] += 1
            self._update_metrics(test_id, image_variant)

    def _update_metrics(self, test_id: str, variant: int) -> None:
        image = self.tests[test_id]["images"][variant]
        impressions = image["impressions"]
        if impressions > 0:
            image["ctr"] = image["clicks"] / impressions
            image["conversion_rate"] = image["conversions"] / impressions

    def should_finish_test(self, test_id: str) -> bool:
        if test_id not in self.tests:
            return False

        test = self.tests[test_id]
        created = datetime.fromisoformat(test["created_at"])
        if datetime.now() - created < timedelta(days=7):
            return False

        total_impressions = sum(image["impressions"] for image in test["images"])
        if total_impressions < 100:
            return False

        return self._has_statistical_significance(test["images"])

    def _has_statistical_significance(self, images: List[Dict]) -> bool:
        if len(images) < 2:
            return True

        best = max(images, key=lambda item: item["ctr"])
        worst = min(images, key=lambda item: item["ctr"])
        if best["impressions"] > 50 and worst["impressions"] > 50:
            return abs(best["ctr"] - worst["ctr"]) > 0.15
        return False

    def get_winner(self, test_id: str) -> Optional[Dict]:
        if test_id not in self.tests:
            return None

        test = self.tests[test_id]
        winner = max(test["images"], key=lambda item: item["ctr"])
        test["status"] = "finished"
        test["results"] = {
            "winner": winner,
            "metrics": {
                image["variant"]: {
                    "ctr": image["ctr"],
                    "conversions": image["conversions"],
                }
                for image in test["images"]
            },
            "finished_at": datetime.now().isoformat(),
        }
        return winner

    def save_results(self, test_id: str, filepath: str = "logs/ab_tests.jsonl") -> None:
        if test_id not in self.tests:
            return
        with open(filepath, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(self.tests[test_id], ensure_ascii=False) + "\n")
