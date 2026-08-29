#!/usr/bin/env python3
from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import unittest
import urllib.error
import urllib.parse
from pathlib import Path
from unittest import mock

REPO_ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = REPO_ROOT / "daemon-token-renewer.py"
SPEC = importlib.util.spec_from_file_location("daemon_token_renewer", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
renew = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(renew)


class FakeResponse:
    def __init__(self, payload: dict[str, object], status: int = 200) -> None:
        self.payload = payload
        self.status = status

    def __enter__(self) -> "FakeResponse":
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:
        return False

    def read(self) -> bytes:
        return json.dumps(self.payload).encode("utf-8")


class OlistOAuthCredentialCandidateTests(unittest.TestCase):
    def test_unauthorized_alias_pair_does_not_block_later_valid_pair(self) -> None:
        config = {
            "OLIST_CLIENT_ID": "olist-client",
            "OLIST_CLIENT_SECRET": "olist-secret",
            "OLIST_REFRESH_TOKEN": "olist-refresh",
            "TINY_CLIENT_ID": "tiny-client",
            "TINY_CLIENT_SECRET": "tiny-secret",
            "TINY_REFRESH_TOKEN": "tiny-refresh",
        }
        attempted: list[tuple[str, str]] = []

        def fake_urlopen(request, timeout=30):
            params = urllib.parse.parse_qs(request.data.decode("utf-8"))
            client = params["client_id"][0]
            refresh = params["refresh_token"][0]
            attempted.append((client, refresh))
            if (client, refresh) == ("olist-client", "olist-refresh"):
                raise urllib.error.HTTPError(
                    request.full_url,
                    401,
                    "invalid client",
                    {},
                    io.BytesIO(b'{"error":"invalid_client"}'),
                )
            if (client, refresh) == ("tiny-client", "olist-refresh"):
                raise urllib.error.HTTPError(
                    request.full_url,
                    401,
                    "unauthorized pair",
                    {},
                    io.BytesIO(b'{"error":"unauthorized_client"}'),
                )
            if (client, refresh) == ("tiny-client", "tiny-refresh"):
                return FakeResponse(
                    {
                        "access_token": "fresh-access",
                        "refresh_token": "fresh-refresh",
                        "expires_in": 3600,
                    }
                )
            self.fail(f"unexpected credential pair: {(client, refresh)}")

        output = io.StringIO()
        with mock.patch.object(renew.urllib.request, "urlopen", side_effect=fake_urlopen):
            with contextlib.redirect_stdout(output):
                result = renew.renew_token(config)

        self.assertIsNotNone(result)
        assert result is not None
        self.assertEqual(result["_sv_credential_alias"], "tiny")
        self.assertEqual(result["_sv_refresh_alias"], "tiny")
        self.assertEqual(
            attempted,
            [
                ("olist-client", "olist-refresh"),
                ("tiny-client", "olist-refresh"),
                ("tiny-client", "tiny-refresh"),
            ],
        )
        log = output.getvalue()
        for secret_value in config.values():
            self.assertNotIn(secret_value, log)


if __name__ == "__main__":
    unittest.main()
