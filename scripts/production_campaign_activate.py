#!/usr/bin/env python3
"""Disabled legacy production campaign activation script.

The previous script did not perform a verified Google Ads mutation, but wrote
local files and printed that a campaign was live. That can create dangerous
operational confusion.

Use the reviewed fail-closed Google Ads flow instead:
  1. python scripts/google_ads_auth_preflight.py
  2. python scripts/google_ads_real_readiness.py
  3. python scripts/google_ads_create_search_campaign.py
  4. only after explicit review: add --create-paused

This compatibility entrypoint never marks campaigns live and never mutates Ads.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CURRENT_CREATOR = ROOT / "scripts" / "google_ads_create_search_campaign.py"


def main() -> int:
    parser = argparse.ArgumentParser(description="Disabled legacy production campaign activation script.")
    parser.add_argument(
        "--dry-run-current",
        action="store_true",
        help="Run the current reviewed campaign creator in dry-run mode only.",
    )
    args = parser.parse_args()

    print("LEGACY_PRODUCTION_CAMPAIGN_ACTIVATION_DISABLED")
    print("reason=local_status_files_could_claim_live_without_verified_google_ads_mutation")
    print("canonical_config=scripts/google_ads_campaign_live_ready.json")
    print("canonical_creator=scripts/google_ads_create_search_campaign.py")
    print("real_creation_policy=create_paused_only_after_auth_and_readiness_pass")

    if not args.dry_run_current:
        print("NO_CHANGES_MADE")
        return 2

    result = subprocess.run(
        [sys.executable, str(CURRENT_CREATOR)],
        cwd=str(ROOT),
        check=False,
    )
    return result.returncode


if __name__ == "__main__":
    raise SystemExit(main())
