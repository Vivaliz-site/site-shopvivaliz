import copy
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ads_ready", ROOT / "scripts/google_ads_real_readiness.py")
mod = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(mod)
config = json.loads((ROOT / "scripts/google_ads_campaign_live_ready.json").read_text(encoding="utf-8"))

for group in config["ad_groups"]:
    errors = mod.validate_ad_group(group, config["guardrails"])
    forbidden = ("needs_more_keyword_relevant_headlines", "needs_more_purchase_intent_headlines", "needs_more_relevant_descriptions", "final_url_not_specific_to_group")
    assert not [e for e in errors if e.endswith(forbidden)], errors

assert mod.cross_group_keyword_duplicates(config["ad_groups"]) == []
groups = copy.deepcopy(config["ad_groups"])
groups[1]["keywords"][0]["text"] = groups[0]["keywords"][0]["text"]
assert mod.cross_group_keyword_duplicates(groups) == ["vaso antique japi"]
print("google ads generic readiness: PASS")
