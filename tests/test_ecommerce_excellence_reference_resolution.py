import importlib.util
import pathlib
import sys
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "ecommerce-excellence-audit.py"
SPEC = importlib.util.spec_from_file_location("ecommerce_excellence_reference_audit", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class ReferenceResolutionScannerTest(unittest.TestCase):
    def _scan(self, files: dict[str, str]):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            paths = []
            for rel, content in files.items():
                path = root / rel
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
                paths.append(path)
            previous_root = MODULE.ROOT
            previous_tracked = MODULE.tracked_files
            try:
                MODULE.ROOT = root
                MODULE.tracked_files = lambda: paths
                return MODULE.validate_static(paths, scope="changed")
            finally:
                MODULE.ROOT = previous_root
                MODULE.tracked_files = previous_tracked

    @staticmethod
    def _missing(report):
        return [item for item in report["findings"] if item["code"] == "missing_local_asset"]

    def test_clean_php_route_is_resolved(self):
        report = self._scan({
            "page.php": '<a href="/carrinho">Carrinho</a>',
            "carrinho.php": "<?php echo 'ok';",
        })
        self.assertEqual([], self._missing(report))

    def test_htaccess_rewrite_route_is_resolved(self):
        report = self._scan({
            "page.php": '<a href="/blog/feed.xml">Feed</a>',
            "blog/feed.php": "<?php echo 'rss';",
            ".htaccess": "RewriteRule ^blog/feed\\.xml$ blog/feed.php [L]\n",
        })
        self.assertEqual([], self._missing(report))

    def test_web_absolute_reference_resolves_in_web_root(self):
        report = self._scan({
            "web/index.html": '<a href="/status.json">Status</a>',
            "web/status.json": '{"ok":true}',
        })
        self.assertEqual([], self._missing(report))

    def test_templates_tests_and_vendored_examples_do_not_create_asset_warnings(self):
        report = self._scan({
            "page.php": '<a href="/blog/<?= $slug ?>">Article</a>',
            "tests/example.php": '<a href="/produto/disponivel">Fixture</a>',
            "includes/PHPMailer/PHPMailer.php": '<img src="/images/a.png">',
        })
        self.assertEqual([], self._missing(report))

    def test_real_missing_reference_stays_visible(self):
        report = self._scan({
            "page.php": '<a href="/checkout/success">Success</a>',
        })
        missing = self._missing(report)
        self.assertEqual(1, len(missing))
        self.assertIn("/checkout/success", missing[0]["message"])

    def test_empty_python_package_marker_is_not_runtime_warning(self):
        report = self._scan({
            "scripts/ia/seo/__init__.py": "",
            "scripts/runtime.py": "",
        })
        empty = [item for item in report["findings"] if item["code"] == "empty_runtime_file"]
        self.assertEqual(1, len(empty))
        self.assertTrue(empty[0]["path"].endswith("scripts/runtime.py"))


    def test_catch_all_rewrite_does_not_hide_missing_reference(self):
        report = self._scan({
            "page.php": '<a href="/checkout/success">Success</a>',
            ".htaccess": "RewriteRule ^(.*)$ https://shopvivaliz.com.br/$1 [R=301,L]\n",
        })
        missing = self._missing(report)
        self.assertEqual(1, len(missing))


if __name__ == "__main__":
    unittest.main()
