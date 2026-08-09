#!/usr/bin/env python3
"""Legacy Google Ads activation entrypoint - intentionally disabled.

The previous version contained a stale hard-coded customer ID, could advertise
an ENABLED status, and reported success without performing a valid Google Ads
mutation. Keeping that behavior would be unsafe.

Use the reviewed fail-closed flow instead:
  1. python scripts/google_ads_auth_preflight.py
  2. python scripts/google_ads_real_readiness.py
  3. python scripts/google_ads_create_search_campaign.py
  4. only after explicit review: add --create-paused

This compatibility entrypoint never enables a campaign and never mutates Ads.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CURRENT_CREATOR = ROOT / "scripts" / "google_ads_create_search_campaign.py"


def main() -> int:
    parser = argparse.ArgumentParser(description="Disabled legacy Google Ads activation entrypoint.")
    parser.add_argument(
        "--dry-run-current",
        action="store_true",
        help="Run the current reviewed campaign creator in dry-run mode only.",
    )
    args = parser.parse_args()

    print("LEGACY_GOOGLE_ADS_ACTIVATION_DISABLED")
    print("reason=stale_customer_id_and_unsafe_enabled_campaign_path")
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
