"""Tests systemd DynamicUser credentials without actual keys or network."""
import hashlib
import json
from datetime import datetime,timezone,timedelta
from pathlib import Path
import pytest
from okx_pilot.provider_chain import GeminiFreeProvider
from okx_pilot.decision import DecisionParser
from test_ai_engine import valid_payload, snap

def make_creds(folder, key="TEST_FAKE_GEMINI_API_KEY_NOT_REAL"):
    folder.mkdir(parents=True,exist_ok=True)
    (folder/"gemini.env").write_text("GEMINI_API_KEY="+key+"\n")
    (folder/"gemini-free-tier.json").write_text(json.dumps({
        "schema_version":1,"billing_tier":"free","verified_source":"google_ai_studio",
        "verified_at":datetime.now(timezone.utc).isoformat(),
        "project_id":"local-test-fixture",
        "key_sha256":[hashlib.sha256(key.encode()).hexdigest()],
    }))
    (folder/"gemini-free-tier.json").chmod(0o600)

def model_response(m):
    d=valid_payload(m)
    out={k:v for k,v in d.items() if k not in (
        "layers","decision_id","instrument","instrument_type",
        "time_horizon","created_at","expires_at","market_snapshot_ts"
    )}
    out["assessments"]=[x["assessment"] for x in d["layers"]]
    out["evidence"]=[x["evidence"][0] for x in d["layers"]]
    out["supporting_evidence"]=d["supporting_evidence"][0]
    out["contrary_evidence"]=d["contrary_evidence"][0]
    return out

def test_systemd_credential_directory_selected(tmp_path,monkeypatch):
    make_creds(tmp_path)
    monkeypatch.setenv("CREDENTIALS_DIRECTORY",str(tmp_path))
    m=snap()
    calls=[]
    def transport(url,body,*,timeout,headers):
        assert "responseSchema" not in body["generationConfig"]
        assert body["generationConfig"]["responseJsonSchema"]["additionalProperties"] is False
        calls.append((url,body["generationConfig"]["responseMimeType"],headers.get("x-goog-api-key")))
        return {"candidates":[{"content":{"parts":[{"text":json.dumps(model_response(m))}]}}]}
    p=GeminiFreeProvider(transport=transport)
    assert p.env_path == tmp_path/"gemini.env"
    assert p.proof_path == tmp_path/"gemini-free-tier.json"
    result=p.analyze(m,{})
    assert DecisionParser.parse(result,datetime.now(timezone.utc)).instrument==m.instrument
    assert len(result["layers"])==20
    assert calls[0][0].startswith("https://generativelanguage.googleapis.com/")
    assert calls[0][2]=="TEST_FAKE_GEMINI_API_KEY_NOT_REAL"

def test_explicit_paths_not_overridden(tmp_path,monkeypatch):
    monkeypatch.setenv("CREDENTIALS_DIRECTORY",str(tmp_path/"private"))
    p=GeminiFreeProvider(env_path=tmp_path/"a",proof_path=tmp_path/"b")
    assert p.env_path==tmp_path/"a"
    assert p.proof_path==tmp_path/"b"

def test_missing_proof_fails_closed_without_network(tmp_path,monkeypatch):
    monkeypatch.setenv("CREDENTIALS_DIRECTORY",str(tmp_path))
    (tmp_path/"gemini.env").write_text("GEMINI_API_KEY=DO_NOT_USE\n")
    p=GeminiFreeProvider(transport=lambda *a,**k: (_ for _ in ()).throw(RuntimeError("network_called")))
    with pytest.raises(Exception,match="gemini_free_tier_not_verified"):
        p.analyze(snap(),{})

def test_expired_proof_denied(tmp_path,monkeypatch):
    make_creds(tmp_path)
    proof=tmp_path/"gemini-free-tier.json"
    data=json.loads(proof.read_text())
    data["verified_at"]=(datetime.now(timezone.utc)-timedelta(days=31)).isoformat()
    proof.write_text(json.dumps(data));proof.chmod(0o600)
    monkeypatch.setenv("CREDENTIALS_DIRECTORY",str(tmp_path))
    with pytest.raises(Exception,match="gemini_free_tier_not_verified"):
        GeminiFreeProvider().analyze(snap(),{})

def test_service_unit_isolation_untouched():
    root=Path(__file__).parents[1]
    unit=(root/"systemd/shopvivaliz-okx-paper.service").read_text()
    fragment=(root/"systemd/shopvivaliz-okx-paper-gemini-free-credentials.conf").read_text()
    for control in ("DynamicUser=yes","ProtectHome=yes","NoNewPrivileges=yes","MemoryMax=384M"):
        assert control in unit
    assert "LoadCredential=gemini.env:" in fragment
    assert "LoadCredential=gemini-free-tier.json:" in fragment
    assert "ReadWritePaths=/var/lib/shopvivaliz-okx-paper" in unit
    assert "Environment=GEMINI_API_KEY" not in fragment


def test_default_primary_model_is_free_tier_gemini_35_flash_lite():
    # The live-validated default must remain on Google's documented free tier.
    assert GeminiFreeProvider().model == "gemini-3.5-flash-lite"
