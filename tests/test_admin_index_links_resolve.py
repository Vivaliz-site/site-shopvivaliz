import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "admin" / "index.php"


class AdminIndexLinksResolveTests(unittest.TestCase):
    def test_every_internal_admin_link_points_to_an_existing_target(self):
        html = INDEX.read_text(encoding="utf-8")
        links = sorted(set(re.findall(r'href="(/admin/[^"#?]*)', html)))
        self.assertGreaterEqual(len(links), 5, "admin/index.php should expose several admin links")
        missing = []
        for link in links:
            target = ROOT / link.lstrip("/")
            if link.endswith("/"):
                exists = target.is_dir()
            else:
                # sem extensao dependeria de RewriteRule; exigir arquivo explicito
                exists = target.is_file()
            if not exists:
                missing.append(link)
        self.assertEqual([], missing, "dead links in admin/index.php (would 404 in production)")


if __name__ == "__main__":
    unittest.main()
