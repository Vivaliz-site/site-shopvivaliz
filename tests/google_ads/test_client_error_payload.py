import io
import json
import unittest
import urllib.error

from scripts.google_ads.client import _api_error


class ClientErrorPayloadTests(unittest.TestCase):
    def test_api_error_parses_search_stream_array_payload(self):
        payload = [{"error": {
            "code": 400,
            "message": "Request contains an invalid argument.",
            "status": "INVALID_ARGUMENT",
            "details": [{"errors": [{
                "errorCode": {"queryError": "UNRECOGNIZED_FIELD"},
                "message": "bad field",
            }]}],
        }}]
        http_error = urllib.error.HTTPError(
            "https://googleads.googleapis.com/v25/test", 400, "Bad Request", {},
            io.BytesIO(json.dumps(payload).encode()),
        )
        error = _api_error(http_error, "recommendations")
        self.assertEqual(error.status, "INVALID_ARGUMENT")
        self.assertEqual(error.message, "Request contains an invalid argument.")
        self.assertEqual(error.reasons, ("queryError=UNRECOGNIZED_FIELD",))


if __name__ == "__main__":
    unittest.main()
