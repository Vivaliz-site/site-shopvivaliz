import importlib.util
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts/google_oauth_token_helper.py"
SPEC = importlib.util.spec_from_file_location("google_oauth_token_helper", MODULE_PATH)
helper = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(helper)


class GoogleOAuthYouTubeScopeTest(unittest.TestCase):
    def test_youtube_upload_scope_group_exists(self):
        scopes = helper.build_scopes(["youtube-upload"])
        self.assertIn("https://www.googleapis.com/auth/youtube.upload", scopes)


if __name__ == "__main__":
    unittest.main()
