"""Offline regression tests for paid-project Gemini fail-closed behavior."""
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("gemini_free_proof",ROOT/"scripts/run_background_gemini.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
UTC=datetime.timezone.utc
NOW=datetime.datetime(2026,10,12,4,0,tzinfo=UTC)

def proof(key, **kwargs):
 d={"schema_version":1,"billing_tier":"free","verified_source":"google_ai_studio",
    "project_id":"gen-lang-verified","verified_at":NOW.isoformat(),
    "key_sha256":[hashlib.sha256(key.encode()).hexdigest()]}
 d.update(kwargs)
 return d

class GuardTest(unittest.TestCase):
 def test_no_proof_no_approval(self):
  self.assertEqual(mod.free_tier_approved_credentials([("GEMINI_API_KEY","fixture")],Path("/invalid-probe-file"),now=NOW),[])
 def test_exact_key_only(self):
  with tempfile.TemporaryDirectory() as td:
   f=Path(td)/"proof.json";f.write_text(json.dumps(proof("first")));f.chmod(0o600)
   self.assertEqual(mod.free_tier_approved_credentials([("GEMINI_API_KEY","first"),("GOOGLE_API_KEY","other")],f,now=NOW),[("GEMINI_API_KEY","first")])
 def test_bad_proof_fails_closed(self):
  cases=[{"billing_tier":"paid"},{"verified_source":"unknown"},{"project_id":""},
         {"verified_at":(NOW-datetime.timedelta(days=31)).isoformat()},
         {"verified_at":(NOW+datetime.timedelta(minutes=1)).isoformat()},
         {"verified_at":"invalid"},{"key_sha256":["fake"]},
         {"key_sha256":[hashlib.sha256(b"wrong").hexdigest()]},{"schema_version":2}]
  with tempfile.TemporaryDirectory() as td:
   f=Path(td)/"proof.json"
   for d in cases:
    with self.subTest(data=d):
     f.write_text(json.dumps(proof("first",**d)));f.chmod(0o600)
     self.assertEqual(mod.free_tier_approved_credentials([("GEMINI_API_KEY","first")],f,now=NOW),[])
 def test_public_proof_rejected(self):
  with tempfile.TemporaryDirectory() as td:
   f=Path(td)/"proof.json";f.write_text(json.dumps(proof("first")));f.chmod(0o644)
   self.assertFalse(mod.free_tier_approved_credentials([("GEMINI_API_KEY","first")],f,now=NOW))
 def test_background_without_proof_never_spawns_model(self):
  with tempfile.TemporaryDirectory() as td:
   f=Path(td)/"env";f.write_text("GEMINI_API_KEY=fixture-secret\n")
   prompt=Path(td)/"prompt";prompt.write_text("offline")
   with patch.dict(os.environ,{"SHOPVIVALIZ_RESUME_BACKGROUND":"1"}),patch.object(mod.subprocess,"run") as called:
    result=mod.run(model="gemini-flash-latest",prompt_file=prompt,env_file=f,gemini_bin="/nonexistent/cli",tier_attestation_file=Path(td)/"missing")
   self.assertEqual(result,78)
   called.assert_not_called()
if __name__=="__main__":
 unittest.main()
