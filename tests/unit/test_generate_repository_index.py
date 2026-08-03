import unittest

from scripts.generate_repository_index import (
    classification,
    file_purpose,
    markdown_code,
    safe_path,
)


class GenerateRepositoryIndexTests(unittest.TestCase):
    def test_top_level_storefront_files_are_application_code(self):
        self.assertIn("Pagina publica do catalogo", file_purpose("catalogo.php"))
        self.assertIn("Pagina publica do checkout", file_purpose("checkout.php"))

    def test_documentation_keeps_its_role_when_name_mentions_webhook_or_migration(self):
        self.assertIn("Documentacao ou instrucao", file_purpose("docs/TINY-WEBHOOKS-SETUP.md"))
        self.assertIn("Documentacao ou instrucao", file_purpose("docs/repository-migration.md"))

    def test_legacy_in_test_name_does_not_mark_test_as_removal_candidate(self):
        path = "tests/unit/test_shopee_legacy_credentials_safe.py"
        self.assertEqual("ativo ou ainda nao classificado como legado", classification(path))

    def test_declared_legacy_directory_is_classified(self):
        self.assertIn("legado declarado", classification("checkout-legacy/index.php"))

    def test_operational_data_is_flagged_and_identifier_is_redacted(self):
        path = "storage/orders/SV20260803123456789.json"
        self.assertIn("violacao de politica", classification(path))
        self.assertEqual("storage/orders/<REDACTED_ORDER_ID>.json", safe_path(path))

    def test_markdown_code_span_supports_backticks(self):
        self.assertEqual("``value`with`ticks``", markdown_code("value`with`ticks"))


if __name__ == "__main__":
    unittest.main()
