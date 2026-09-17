import importlib.util
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products_video", MODULE_PATH)
daemon = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(daemon)


class VideoSourceMergeTest(unittest.TestCase):
    def test_preserves_direct_and_adds_youtube(self):
        product = {"id": 381747419, "sku": "35039", "video_url": "https://shopvivaliz.com.br/uploads/v/381747419.mp4"}
        registry = {"381747419": {"direct_url": product["video_url"], "youtube_url": "https://youtu.be/abcdefghijk"}}
        merged = daemon.merge_video_sources(product, registry)
        self.assertEqual(product["video_url"], merged["video_url"])
        self.assertEqual("https://youtu.be/abcdefghijk", merged["youtube_url"])


if __name__ == "__main__":
    unittest.main()
