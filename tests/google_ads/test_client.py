import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

from scripts.google_ads.client import GoogleAdsClient, GoogleAdsError, load_env


class ClientTests(unittest.TestCase):
    def test_load_env_ignores_comments_and_quotes(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / ".env"
            path.write_text(
                "# comment\nA=1\nB='two'\nC=\"three\"\n",
                encoding="utf-8",
            )
            self.assertEqual(load_env(path), {"A": "1", "B": "two", "C": "three"})

    def test_from_env_requires_expected_keys_without_values_in_error(self):
        env = {"GOOGLE_OAUTH_CLIENT_ID": "secret-client"}
        with self.assertRaises(ValueError) as ctx:
            GoogleAdsClient.from_env(env)
        text = str(ctx.exception)
        self.assertIn("missing Google Ads environment keys", text)
        self.assertNotIn("secret-client", text)

    @patch("scripts.google_ads.client.urllib.request.urlopen")
    def test_query_normalizes_stream_batches(self, urlopen):
        token_response = MagicMock()
        token_response.__enter__.return_value.read.return_value = json.dumps(
            {"access_token": "access-secret"}
        ).encode()
        query_response = MagicMock()
        query_response.__enter__.return_value.read.return_value = json.dumps(
            [
                {"results": [{"campaign": {"id": "1"}}]},
                {"results": [{"campaign": {"id": "2"}}]},
            ]
        ).encode()
        urlopen.side_effect = [token_response, query_response]
        client = GoogleAdsClient.from_env(
            {
                "GOOGLE_OAUTH_CLIENT_ID": "cid",
                "GOOGLE_OAUTH_CLIENT_SECRET": "client-secret",
                "GOOGLE_ADS_REFRESH_TOKEN": "refresh-secret",
                "GOOGLE_ADS_DEVELOPER_TOKEN": "dev-secret",
                "GOOGLE_ADS_CUSTOMER_ID": "123-456-7890",
            }
        )
        rows = client.query("campaigns", "SELECT campaign.id FROM campaign")
        self.assertEqual([row["campaign"]["id"] for row in rows], ["1", "2"])

    def test_client_has_no_mutate_surface(self):
        self.assertFalse(hasattr(GoogleAdsClient, "mutate"))

    def test_google_ads_error_string_is_structured(self):
        error = GoogleAdsError("campaigns", "INVALID_ARGUMENT", "bad query", ("queryError=X",))
        self.assertEqual(
            str(error),
            "campaigns: INVALID_ARGUMENT: bad query (queryError=X)",
        )


if __name__ == "__main__":
    unittest.main()
