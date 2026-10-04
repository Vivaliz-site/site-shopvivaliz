#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts" / "retire-titan-dns.py"

def load():
    spec = importlib.util.spec_from_file_location("retire_titan_dns", PATH)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

class RetireTitanDnsTests(unittest.TestCase):
    def test_select_zone_id_requires_exact_unique_zone(self):
        m = load()
        self.assertEqual(m.select_zone_id({"result":[{"name":m.ZONE,"id":"zone-1"}]}), "zone-1")
        with self.assertRaises(RuntimeError):
            m.select_zone_id({"result":[]})

    def test_only_txt_titan_selector_is_accepted(self):
        m = load()
        payload={"result":[{"name":m.RETIRED_RECORD,"type":"TXT","id":"record-1"}]}
        self.assertEqual(m.validate_retired_records(payload), ["record-1"])
        with self.assertRaises(RuntimeError):
            m.validate_retired_records({"result":[{"name":m.RETIRED_RECORD,"type":"CNAME","id":"x"}]})

    def test_absent_record_is_idempotent(self):
        m = load()
        self.assertEqual(m.validate_retired_records({"result":[]}), [])

if __name__ == "__main__":
    unittest.main()
