import importlib.util
import pathlib
import sys
import unittest

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "ecommerce-excellence-audit.py"
SPEC = importlib.util.spec_from_file_location("ecommerce_excellence_sitewide_audit", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class SitewideAuditContractTest(unittest.TestCase):
    def test_sitemap_inventory_is_same_origin_normalized_and_stable(self):
        sitemap = b'''<?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <url><loc>https://shopvivaliz.com.br/</loc></url>
          <url><loc>https://shopvivaliz.com.br/blog#top</loc></url>
          <url><loc>https://shopvivaliz.com.br/blog</loc></url>
          <url><loc>https://external.example/page</loc></url>
          <url><loc>https://shopvivaliz.com.br/catalogo/?q=rodizio</loc></url>
        </urlset>'''
        self.assertEqual(
            [
                "https://shopvivaliz.com.br/",
                "https://shopvivaliz.com.br/blog",
                "https://shopvivaliz.com.br/catalogo/?q=rodizio",
            ],
            MODULE.sitemap_inventory("https://shopvivaliz.com.br", sitemap),
        )

    def test_sitemap_page_locations_ignore_image_locations(self):
        sitemap = b'''<?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
          <url>
            <loc>https://shopvivaliz.com.br/blog/guia</loc>
            <image:image><image:loc>https://cdn.example.com/guia.jpg</image:loc></image:image>
          </url>
          <url>
            <loc>https://shopvivaliz.com.br/produto/item</loc>
            <image:image><image:loc>https://shopvivaliz.com.br/public/item.jpg</image:loc></image:image>
          </url>
        </urlset>'''
        self.assertEqual(
            [
                "https://shopvivaliz.com.br/blog/guia",
                "https://shopvivaliz.com.br/produto/item",
            ],
            MODULE.sitemap_page_locations(sitemap),
        )
        self.assertEqual(
            [
                "https://shopvivaliz.com.br/blog/guia",
                "https://shopvivaliz.com.br/produto/item",
            ],
            MODULE.sitemap_inventory("https://shopvivaliz.com.br", sitemap),
        )

    def test_cross_page_findings_detect_duplicate_metadata(self):
        pages = [
            {
                "url": "https://shopvivaliz.com.br/a",
                "path": "/a",
                "status": 200,
                "canonical": "https://shopvivaliz.com.br/a",
                "title": "Mesmo titulo",
                "description": "Mesma descricao util para as duas paginas.",
                "is_indexable": True,
            },
            {
                "url": "https://shopvivaliz.com.br/b",
                "path": "/b",
                "status": 200,
                "canonical": "https://shopvivaliz.com.br/b",
                "title": "Mesmo titulo",
                "description": "Mesma descricao util para as duas paginas.",
                "is_indexable": True,
            },
        ]
        codes = [item.code for item in MODULE.cross_page_findings(pages)]
        self.assertIn("duplicate_title", codes)
        self.assertIn("duplicate_meta_description", codes)

    def test_page_inspection_detects_canonical_encoding_and_thin_blog_content(self):
        body = '''<!doctype html><html><head>
        <title>Guia de teste</title>
        <meta name="description" content="Descricao suficientemente clara para o teste de auditoria.">
        <meta property="og:title" content="Guia de teste">
        <meta property="og:description" content="Descricao suficientemente clara para o teste de auditoria.">
        <meta property="og:image" content="/imagem.jpg">
        <link rel="canonical" href="/blog/outro-guia">
        </head><body><h1>Guia</h1><main>InformaÃ§Ã£o curta.</main></body></html>'''.encode("utf-8")
        page, findings = MODULE.inspect_page_document(
            "https://shopvivaliz.com.br",
            "https://shopvivaliz.com.br/blog/guia-de-teste",
            200,
            {},
            body,
            "https://shopvivaliz.com.br/blog/guia-de-teste",
        )
        codes = {item.code for item in findings}
        self.assertEqual("https://shopvivaliz.com.br/blog/outro-guia", page["canonical"])
        self.assertIn("canonical_mismatch", codes)
        self.assertIn("visible_encoding_issue", codes)
        self.assertIn("thin_editorial_content", codes)

    def test_page_inspection_does_not_flag_valid_portuguese_tilde_as_mojibake(self):
        body = """<!doctype html><html><head>
        <title>Colecao de teste</title>
        <meta name="description" content="Descricao suficientemente clara para o teste.">
        <meta property="og:title" content="Colecao de teste">
        <meta property="og:description" content="Descricao suficientemente clara para o teste.">
        <meta property="og:image" content="/imagem.jpg">
        <link rel="canonical" href="/colecao">
        </head><body><h1>COLEÇÃO</h1><main>COLEÇÃO exclusiva para organização.</main></body></html>""".encode("utf-8")
        _, findings = MODULE.inspect_page_document(
            "https://shopvivaliz.com.br",
            "https://shopvivaliz.com.br/colecao",
            200,
            {},
            body,
            "https://shopvivaliz.com.br/colecao",
        )
        codes = {item.code for item in findings}
        self.assertNotIn("visible_encoding_issue", codes)

    def test_internal_links_are_same_origin_and_deduplicated(self):
        body = b'''<html><body>
        <a href="/catalogo/">Catalogo</a>
        <a href="/catalogo/#top">Catalogo duplicado</a>
        <a href="mailto:oi@example.com">Email</a>
        <a href="https://external.example/page">Externo</a>
        </body></html>'''
        self.assertEqual(
            ["https://shopvivaliz.com.br/catalogo/"],
            MODULE.extract_internal_links(
                "https://shopvivaliz.com.br",
                "https://shopvivaliz.com.br/blog/guia",
                body,
            ),
        )

    def test_internal_links_ignore_cloudflare_email_protection_endpoint(self):
        body = b"""<html><body>
        <a href="/cdn-cgi/l/email-protection">email protected</a>
        <a href="/contato">Contato</a>
        </body></html>"""
        self.assertEqual(
            ["https://shopvivaliz.com.br/contato"],
            MODULE.extract_internal_links(
                "https://shopvivaliz.com.br",
                "https://shopvivaliz.com.br/sobre/",
                body,
            ),
        )

    def test_internal_links_percent_encode_unicode_before_fetch(self):
        body = '''<html><body>
        <a href="/catalogo/?q=acessório">Busca com acento</a>
        <a href="/blog/guia-de-organização">Guia com acento</a>
        </body></html>'''.encode("utf-8")
        self.assertEqual(
            [
                "https://shopvivaliz.com.br/catalogo/?q=acess%C3%B3rio",
                "https://shopvivaliz.com.br/blog/guia-de-organiza%C3%A7%C3%A3o",
            ],
            MODULE.extract_internal_links(
                "https://shopvivaliz.com.br",
                "https://shopvivaliz.com.br/blog/guia",
                body,
            ),
        )


if __name__ == "__main__":
    unittest.main()
