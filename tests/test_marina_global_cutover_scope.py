#!/usr/bin/env python3
"""Disallow an APTO storefront audit from silently certifying full VM decommission.

A static scope contract, not a substitute for runtime/OCI/evidence validation.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class MarinaScopeTests(unittest.TestCase):
    def test_formal_public_site_audit_keeps_its_apto_but_not_decommission(self):
        text = (ROOT / "docs/quality/AUDIT_STATUS.md").read_text(encoding="utf8")
        self.assertIn("SITE_PUBLIC_AUDIT_STATUS=APTO", text)
        self.assertIn("GLOBAL_MIGRATION_STATUS=NAO_APTO", text)
        self.assertIn("NO_DECOMMISSION", text)
        self.assertIn("AUDIT_VERDICT=APTO", text)
        self.assertIn("Pricing", text)
        self.assertIn("Supabase", text)
        self.assertIn("MCP", text)

    def test_audit_escape_register_preserves_open_global_scope_issue(self):
        entry = (ROOT / "docs/quality/AUDIT_ESCAPE_REGISTER.md").read_text(encoding="utf8")
        self.assertIn("ESC-2026-008", entry)
        self.assertIn("AUDIT_SCOPE_OVERCLAIM_V1", entry)
        self.assertIn("NO_DECOMMISSION", entry)
        self.assertIn("OPEN", entry)

    def test_legacy_nocut_checkpoint_marks_historical_dns_qualification(self):
        text = (ROOT / "MIGRATION-EXECUTE-NOW.md").read_text(encoding="utf8")
        self.assertIn("PUBLIC_ROUTE_OBSERVED=marina-a1-4c24g", text)
        self.assertIn("SITE_PUBLIC_AUDIT_STATUS=APTO", text)
        self.assertIn("GLOBAL_MIGRATION_STATUS=NAO_APTO", text)
        self.assertIn("NO_DECOMMISSION", text)
        self.assertIn("historico", text.lower())

    def test_pricing_logical_restore_is_scoped_and_evidenced(self):
        source = (ROOT / "docs/ops/oci-marina-migration-v5-20261017.md").read_text(encoding="utf8")
        self.assertIn("PRICING_LOGICAL_BACKUP_AND_SQL_RESTORE=PASS", source)
        self.assertIn("RESTORE_VALIDATION=PASS", source)
        self.assertIn("RESTORED_SQL_ROW_PARITY=PASS", source)
        self.assertIn("27/27", source)
        self.assertIn("16/16", source)
        self.assertIn("restore-row-parity-20261020T1020Z.txt", source)
        self.assertIn("GLOBAL_MIGRATION_STATUS=NAO_APTO", source)
        self.assertIn("NO_DECOMMISSION", source)
        self.assertIn("PRICING_WORKER=INACTIVE_DISABLED", source)
        self.assertIn("0.0.0.0:13312", source)
        self.assertIn("[::]:13312", source)
        self.assertIn("mesmo host", source)

    def test_canonical_migration_runbook_has_fresh_scoped_state(self):
        text = (ROOT / "docs/ops/oci-marina-migration-v5-20261017.md").read_text(encoding="utf8")
        self.assertIn("PUBLIC_ROUTE_OBSERVED=marina-a1-4c24g", text)
        self.assertIn("GLOBAL_MIGRATION_STATUS=NAO_APTO", text)
        self.assertIn("SITE_PUBLIC_AUDIT_STATUS=APTO", text)
        self.assertIn("NO_DECOMMISSION", text)


if __name__ == "__main__":
    unittest.main()
