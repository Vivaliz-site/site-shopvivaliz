import importlib.util
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('agent_cloudflare_preflight', HERE / 'scripts' / 'agent_cloudflare_preflight.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class CloudflareCredentialPreflightTests(unittest.TestCase):
    def test_github_secret_present_never_requests_new_credentials(self):
        result = module.decide_route(github='present', process='absent', local_file='unknown', browser='unknown')
        self.assertEqual(result['next_route'], 'github_actions_existing_secret')
        self.assertFalse(result['request_new_credential'])

    def test_secrets_exist_does_not_claim_current_process_has_access(self):
        result = module.decide_route(github='present', process='absent', local_file='absent', browser='unknown')
        self.assertFalse(result['usable_in_current_process'])
        self.assertEqual(result['credential_availability'], 'provisioned_other_context')

    def test_known_authenticated_browser_is_alternative_without_repeated_login(self):
        result = module.decide_route(github='unknown', process='absent', local_file='unknown', browser='authenticated')
        self.assertEqual(result['next_route'], 'existing_authenticated_browser')
        self.assertFalse(result['request_new_credential'])

    def test_inaccessible_github_metadata_is_not_absence(self):
        result = module.decide_route(github='unknown', process='absent', local_file='absent', browser='unknown')
        self.assertEqual(result['credential_availability'], 'not_verified')
        self.assertEqual(result['next_route'], 'verify_existing_sources')
        self.assertFalse(result['request_new_credential'])

    def test_all_sources_explicitly_absent_marks_distinct_result(self):
        result = module.decide_route(github='absent', process='absent', local_file='absent', browser='absent')
        self.assertEqual(result['credential_availability'], 'verified_absent')
        self.assertEqual(result['next_route'], 'seek_authorized_provisioning')
        self.assertTrue(result['request_new_credential'])

    def test_secret_names_match_exactly_not_substring(self):
        self.assertFalse(module.has_cloudflare_secret({'CLOUDFLARE_API_TOKEN_OLD'}))
        self.assertTrue(module.has_cloudflare_secret({'CLOUDFLARE_API_TOKEN'}))

    def test_dns_and_tunnel_names_are_checked_independently(self):
        names = {"CLOUDFLARE_API_TOKEN", "CLOUDFLARE_MARINA_TUNNEL_TOKEN"}
        self.assertFalse(module.has_cloudflare_secret(names, "CLOUDFLARE_DNS_EDIT_TOKEN"))
        self.assertTrue(module.has_cloudflare_secret(names, "CLOUDFLARE_MARINA_TUNNEL_TOKEN"))

    def test_dns_context_does_not_accept_unrelated_api_token(self):
        import io
        import json
        import os
        import sys
        from unittest.mock import patch
        from contextlib import redirect_stdout
        out = io.StringIO()
        with patch.object(module, "github_secret_state", return_value="unknown"), \
             patch.dict(os.environ, {"CLOUDFLARE_API_TOKEN": "fake-api-value", "CLOUDFLARE_DNS_EDIT_TOKEN": ""}, clear=True), \
             patch.object(sys, "argv", ["agent_cloudflare_preflight.py", "--required-secret", "CLOUDFLARE_DNS_EDIT_TOKEN"]), \
             redirect_stdout(out):
            module.main()
        result = json.loads(out.getvalue())
        self.assertEqual(result["required_secret"], "CLOUDFLARE_DNS_EDIT_TOKEN")
        self.assertEqual(result["source_status"]["process"], "absent")
        self.assertFalse(result["request_new_credential"])

if __name__ == '__main__': unittest.main()
