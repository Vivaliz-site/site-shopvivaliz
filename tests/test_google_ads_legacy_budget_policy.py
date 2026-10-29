#!/usr/bin/env python3
"""Run the real retired Ads entrypoints against traps, never real credentials.

Removing a policy gate must fail before a Google Ads client, network request,
or mutation can be reached. The SDK is intentionally absent from test deps.
"""
from __future__ import annotations

import contextlib
import http.client
import importlib
import io
import os
from pathlib import Path
import runpy
import socket
import sys
import types
import unittest
from unittest import mock
import urllib.request


ROOT = Path(os.environ.get("ADS_POLICY_TEST_ROOT", Path(__file__).resolve().parents[1]))
POLICY_ID = "SHOPVIVALIZ_GOOGLE_ADS_BUDGET_20261029"
ENTRYPOINTS = (
    "google_ads_antique_decore_status",
    "google_ads_antique_decore_status_v2",
    "google_ads_antique_decore_status_v3",
    "google_ads_antique_decore_automation",
    "google_ads_operator_core_recovery",
    "google_ads_sales_round1_delivery",
)


class ExternalInteraction(RuntimeError):
    pass


class LegacyBudgetPolicyTests(unittest.TestCase):
    def assert_blocked_without_external_access(self, name, args=(), invoke=None):
        calls = []
        output = io.StringIO()

        def trap(operation):
            def blocked(*_args, **_kwargs):
                calls.append(operation)
                raise ExternalInteraction(operation)
            return blocked

        class ClientTrap:
            load_from_dict = staticmethod(trap("client.load_from_dict"))
            get_type = trap("client.get_type")
            get_service = trap("client.get_service")
            search = trap("gaql.search")
            mutate_campaigns = trap("mutate_campaigns")
            mutate_campaign_budgets = trap("mutate_campaign_budgets")
            mutate_conversion_actions = trap("mutate_conversion_actions")

        modules = {key: types.ModuleType(key) for key in (
            "google", "google.ads", "google.ads.googleads", "google.ads.googleads.client"
        )}
        for module in modules.values():
            module.__path__ = []
        modules["google.ads.googleads.client"].GoogleAdsClient = ClientTrap
        environment = {
            "GOOGLE_OAUTH_CLIENT_ID": "offline-fixture",
            "GOOGLE_OAUTH_CLIENT_SECRET": "offline-fixture",
            "GOOGLE_ADS_REFRESH_TOKEN": "offline-fixture",
            "GOOGLE_ADS_DEVELOPER_TOKEN": "offline-fixture",
            "GOOGLE_ADS_CUSTOMER_ID": "1234567890",
            # Historical authorization must not override the new owner policy.
            "ENABLE_IF_APPROVED": "yes",
        }
        loaded_names = list(ENTRYPOINTS) + ["google_ads_legacy_budget_policy"]
        previous = {key: sys.modules.pop(key) for key in loaded_names if key in sys.modules}
        exit_code = None
        unexpected = None
        try:
            with contextlib.ExitStack() as stack:
                stack.enter_context(mock.patch.dict(sys.modules, modules))
                stack.enter_context(mock.patch.dict(os.environ, environment, clear=True))
                stack.enter_context(mock.patch.object(sys, "path", [str(ROOT / "scripts")] + sys.path))
                stack.enter_context(mock.patch.object(sys, "argv", [name + ".py", *args]))
                stack.enter_context(mock.patch.object(socket, "create_connection", trap("socket.create_connection")))
                stack.enter_context(mock.patch.object(socket.socket, "connect", trap("socket.connect")))
                stack.enter_context(mock.patch.object(urllib.request, "urlopen", trap("urllib.urlopen")))
                stack.enter_context(mock.patch.object(http.client.HTTPSConnection, "request", trap("https.request")))
                stack.enter_context(contextlib.redirect_stdout(output))
                stack.enter_context(contextlib.redirect_stderr(output))
                try:
                    if invoke is None:
                        runpy.run_path(str(ROOT / "scripts" / (name + ".py")), run_name="__main__")
                    else:
                        invoke(importlib.import_module(name), ClientTrap())
                except SystemExit as error:
                    exit_code = error.code
                except Exception as error:
                    unexpected = type(error).__name__ + ": " + str(error)
        finally:
            for key in loaded_names:
                sys.modules.pop(key, None)
            sys.modules.update(previous)

        detail = f"calls={calls!r}; unexpected={unexpected!r}; output={output.getvalue()!r}"
        self.assertEqual(exit_code, 2, detail)
        self.assertEqual(calls, [], detail)
        self.assertIn(POLICY_ID, output.getvalue(), detail)
        self.assertIn("NO_CHANGES_MADE", output.getvalue(), detail)

    def test_adapter_rename_flag_cannot_bypass_policy(self):
        self.assert_blocked_without_external_access(
            "google_ads_antique_decore_automation", ["--apply-conversion-name-fix"]
        )

    def test_direct_budget_repair_cannot_raise_five_to_ten(self):
        self.assert_blocked_without_external_access(
            "google_ads_antique_decore_status",
            invoke=lambda module, client: module.ensure_budget(
                client, "1234567890", "customers/1234567890/campaignBudgets/1", 5_000_000
            ),
        )

    def test_direct_status_helper_cannot_enable_a_paused_campaign(self):
        self.assert_blocked_without_external_access(
            "google_ads_antique_decore_status",
            invoke=lambda module, client: module.set_status(
                client, "1234567890", "CampaignService", "CampaignOperation",
                ["customers/1234567890/campaigns/1"], "ENABLED",
            ),
        )

    def test_direct_conversion_rename_cannot_mutate(self):
        self.assert_blocked_without_external_access(
            "google_ads_antique_decore_automation",
            invoke=lambda module, client: module.normalize_primary_purchase_name(
                client, client, "1234567890", apply_fix=True
            ),
        )


def entrypoint_test(name):
    def test(self):
        self.assert_blocked_without_external_access(name)
    return test


for entrypoint in ENTRYPOINTS:
    setattr(LegacyBudgetPolicyTests, "test_entrypoint_" + entrypoint, entrypoint_test(entrypoint))


if __name__ == "__main__":
    unittest.main(verbosity=2)
