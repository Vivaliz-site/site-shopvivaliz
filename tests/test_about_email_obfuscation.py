import pathlib
import unittest


ABOUT_PAGE = pathlib.Path(__file__).parents[1] / "sobre" / "index.php"


class AboutEmailObfuscationContractTest(unittest.TestCase):
    def test_about_mailto_is_excluded_from_cloudflare_obfuscation(self):
        source = ABOUT_PAGE.read_text(encoding="utf-8")
        start = source.find("<!--email_off-->")
        mailto = source.find('href="mailto:')
        end = source.find("<!--/email_off-->")

        self.assertGreaterEqual(start, 0, "about mailto must opt out of Cloudflare email obfuscation")
        self.assertGreaterEqual(mailto, 0, "about page must keep the direct mailto contact link")
        self.assertGreater(end, mailto, "Cloudflare opt-out must wrap the mailto link")
        self.assertLess(start, mailto, "Cloudflare opt-out must start before the mailto link")


if __name__ == "__main__":
    unittest.main()
