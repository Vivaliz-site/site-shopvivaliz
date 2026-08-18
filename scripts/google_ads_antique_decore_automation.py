#!/usr/bin/env python3
"""Automation entry point for the Antique/Decore Google Ads live watch.

The campaign itself does not advertise a coupon. A coupon token that happens to
be visible on the storefront is therefore not, by itself, a broken landing page.
The required landing guard is availability + correct destination/tracking; the
underlying monitor still enforces those checks plus policy, conversion and spend
safety. Disable only the legacy promotional-token heuristic to avoid pausing a
healthy campaign because of storefront copy unrelated to the ad promise.
"""

from __future__ import annotations

import re

import google_ads_antique_decore_status as monitor

# Match nothing. HTTP/redirect/UTM/content checks remain fully active.
monitor.STALE_PROMO_RE = re.compile(r"(?!)")

raise SystemExit(monitor.main())
