import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts/youtube-product-video-upload.py"
SPEC = importlib.util.spec_from_file_location("youtube_product_video_upload", MODULE_PATH)
uploader = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(uploader)


class YouTubeProductVideoUploadTest(unittest.TestCase):
    def test_scope_detection_requires_upload_scope(self):
        self.assertTrue(uploader.has_youtube_upload_scope("openid https://www.googleapis.com/auth/youtube.upload"))
        self.assertFalse(uploader.has_youtube_upload_scope("openid https://www.googleapis.com/auth/webmasters"))

    def test_registry_keeps_direct_and_youtube_urls(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "sources.json"
            uploader.persist_video_source(path, "381747419", "https://shopvivaliz.com.br/uploads/v/381747419.mp4", "AbCdEfGhI12")
            data = json.loads(path.read_text())
            self.assertEqual("https://shopvivaliz.com.br/uploads/v/381747419.mp4", data["381747419"]["direct_url"])
            self.assertEqual("https://youtu.be/AbCdEfGhI12", data["381747419"]["youtube_url"])


if __name__ == "__main__":
    unittest.main()
