#!/usr/bin/env python3
from __future__ import annotations

import time

import google_ads_antique_decore_status as legacy
import google_ads_antique_decore_status_v2 as monitor

_MAX_ATTEMPTS = 3
_RETRY_DELAY_SECONDS = 2
_original_validate_landing_pages = legacy.validate_landing_pages
_original_pause_all = monitor.pause_all


def _is_transient_transport_failure(reason: str) -> bool:
    if not reason.startswith("LANDING_PAGE_GUARD_FAILED"):
        return False
    markers = (
        "TimeoutError:",
        "URLError:",
        "ConnectionResetError:",
        "ConnectionRefusedError:",
        "RemoteDisconnected:",
        "IncompleteRead:",
    )
    return any(marker in reason for marker in markers)


def retrying_validate_landing_pages(urls: list[str]):
    last_reason = ""
    last_results = []
    for attempt in range(1, _MAX_ATTEMPTS + 1):
        ok, reason, results = _original_validate_landing_pages(urls)
        if ok:
            if attempt > 1:
                print("LANDING_VERIFY_RECOVERED attempt=" + str(attempt))
            return ok, reason, results

        last_reason = reason
        last_results = results
        if not _is_transient_transport_failure(reason):
            return ok, reason, results

        print("LANDING_VERIFY_TRANSIENT attempt=" + str(attempt) + " reason=" + reason)
        if attempt < _MAX_ATTEMPTS:
            time.sleep(_RETRY_DELAY_SECONDS)

    return (
        False,
        "LANDING_VERIFICATION_INCONCLUSIVE attempts="
        + str(_MAX_ATTEMPTS)
        + " last_reason="
        + last_reason,
        last_results,
    )


def cautious_pause_all(client, customer_id: str, campaign_rn: str, groups, ads, reason: str) -> int:
    if reason.startswith("LANDING_VERIFICATION_INCONCLUSIVE"):
        print("ACTION=NO_MUTATION")
        print("INCONCLUSIVE_REASON=" + reason)
        print("RESULT=INCONCLUSIVE_LANDING_CHECK_NO_MUTATION")
        return 0
    return _original_pause_all(client, customer_id, campaign_rn, groups, ads, reason)


def main() -> int:
    legacy.validate_landing_pages = retrying_validate_landing_pages
    monitor.pause_all = cautious_pause_all
    return monitor.main()


if __name__ == "__main__":
    raise SystemExit(main())
